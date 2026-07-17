# syntax=docker/dockerfile:1
#
# Sendtrap Community — durable self-hosted container (Plan 06 Phase 7).
#
# Three stages (§1.1): a Composer vendor stage and a Node asset stage feed a
# slim php:8.3-fpm-alpine runtime that carries NO build toolchain (no Composer,
# no Node/npm). Process supervision is s6-overlay v3 (§2); nginx + php-fpm serve
# the UI/API on :8080, the ReactPHP SMTP daemon ingests on :1025.
#
# ---------------------------------------------------------------------------
# BUILD SOURCE (§1.1 PREREQUISITE / MEDIUM-4 — gate-review batch-2 BLOCKER #2)
# — read before building.
#
#   * RELEASE build (default, and the ONLY mode container-release.yml uses):
#     CORE_SOURCE=published (the default below). The committed composer.json
#     `repositories` is repointed (Phase 6) at the PUBLISHED sendtrap/core
#     (Packagist / public GitHub tag), so a plain `composer install` resolves
#     it with no extra flags. NO --build-context is needed or accepted: the
#     `core-src-mirror` stage two lines down is not in this build's
#     dependency graph (BuildKit only resolves stages the requested target
#     actually depends on), so the `core-mirror` named context is NEVER
#     requested and a release build cannot fail trying to resolve it as an
#     image (this was the BLOCKER — `COPY --from=core-mirror` unconditionally
#     required that context before this fix).
#
#   * LOCAL / dev build (before the public tag exists): pass
#     CORE_SOURCE=mirror and supply the mirror as a named build context. The
#     vendor stage installs it at a neutral internal path and refreshes only
#     sendtrap/core in its build-local Composer files, e.g.:
#
#       docker buildx build \
#         --build-arg CORE_SOURCE=mirror \
#         --build-context core-mirror=<path-to>/sendtrap-core-mirror.git \
#         -t sendtrap/community:local .
#
#     (docker-compose.yml's commented-out `build:` block and
#     scripts/container-e2e.sh / scripts/ci-profile-e2e.sh's CORE_MIRROR
#     branch both set CORE_SOURCE=mirror alongside the context — see there.)
#
# The runtime image itself never contacts the mirror or GitHub — it is fully
# self-contained.
# ---------------------------------------------------------------------------

# Pin at release for reproducible supply-chain scans (§9).
ARG PHP_VERSION=8.3
ARG S6_OVERLAY_VERSION=3.2.0.2

# ARG-selected core source (BLOCKER #2 fix). published = release-safe default;
# mirror = dev/local only (see BUILD SOURCE above). Declared before any FROM
# so it is usable in the FROM lines immediately below.
ARG CORE_SOURCE=published

########################################################################
# Stage 0 — core source selection (build-mode aware; see BUILD SOURCE above)
########################################################################
# core-src-published is an EMPTY (scratch) stage: in the release build the
# COPY from it further down is a harmless no-op onto an unused directory,
# because the published build uses the committed public Composer metadata —
# `composer install` resolves sendtrap/core from Packagist/GitHub instead.
FROM scratch AS core-src-published
# core-src-mirror is FROM the "core-mirror" named build context. BuildKit
# only resolves a stage that is actually in the dependency graph of the
# requested build target, so this stage — and therefore the "core-mirror"
# context — is NEVER touched unless CORE_SOURCE=mirror selects it below.
FROM core-mirror AS core-src-mirror
# The ARG-selected stage the vendor stage below actually copies from.
FROM core-src-${CORE_SOURCE} AS core-src

########################################################################
# Stage 1 — vendor: Composer dependencies + optimized autoloader (no dev)
########################################################################
FROM php:${PHP_VERSION}-cli-alpine AS vendor

# Re-declare the global selector for RUN instructions in this stage. The
# mirror always lives at this neutral container-internal path; host paths are
# build-context inputs only and never enter composer.json/composer.lock.
ARG CORE_SOURCE
ARG CORE_MIRROR_PATH=/opt/sendtrap-core-mirror.git

RUN apk add --no-cache git unzip
# intl is required even here so any extension-touching resolve step succeeds
# (§1.2 — intl in BOTH build stages).
# Pinned by tag + digest (gate-review batch-2 #6 — was `.../latest/download/`,
# an unpinned, unverified moving target). ADD --checksum is BuildKit's
# documented digest-pinning mechanism for a remote HTTP(S) source: the build
# fails closed if GitHub ever serves different bytes for this tag.
ADD --chmod=0755 --checksum=sha256:7c133ae4b9490d912287188c62ea570729cfa74f0ea357e4be672ce696b4aa29 \
    https://github.com/mlocati/docker-php-extension-installer/releases/download/2.11.12/install-php-extensions \
    /usr/local/bin/
RUN install-php-extensions intl pdo_sqlite
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dev/local core source at a neutral internal path (empty in a release build —
# see BUILD SOURCE / stage 0 comments).
COPY --from=core-src . ${CORE_MIRROR_PATH}

# Full source (respecting .dockerignore) so --optimize-autoloader builds a
# complete App\ classmap. --no-scripts defers package:discover to boot (§1.1).
COPY . /app
RUN git config --global --add safe.directory '*' \
 && if [ "$CORE_SOURCE" = "mirror" ]; then \
      php -r '$f="composer.json"; $j=json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR); $j["repositories"]=[["type"=>"vcs", "url"=>$argv[1]]]; file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$CORE_MIRROR_PATH"; \
      composer update sendtrap/core \
        --no-dev --prefer-dist --no-scripts --no-progress \
        --no-interaction --optimize-autoloader; \
    else \
      composer install \
        --no-dev --prefer-dist --no-scripts --no-progress \
        --no-interaction --optimize-autoloader; \
    fi \
 && rm -rf /root/.composer

########################################################################
# Stage 2 — assets: Vite build (Node lives ONLY here)
########################################################################
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
# The Vite build resolves @sendtrap/core and tightenco/ziggy out of vendor/,
# so the installed packages must be present for `npm run build`.
COPY --from=vendor /app/vendor /app/vendor
COPY . /app
RUN npm run build

########################################################################
# Stage 3 — runtime: php-fpm + nginx + s6-overlay (no build toolchain)
########################################################################
FROM php:${PHP_VERSION}-fpm-alpine AS runtime
ARG S6_OVERLAY_VERSION
ARG TARGETARCH

# --- PHP extensions (§1.2). openssl/mbstring/dom/xml/ctype/fileinfo/tokenizer
#     /filter are bundled or added; intl/bcmath/sqlite/opcache/mysql/pgsql/redis
#     installed explicitly. intl is HARD-required (Number::fileSize in the API).
# Pinned by tag + digest — see the vendor-stage ADD above (gate-review
# batch-2 #6) for why; same version, same asset, same checksum both times.
ADD --chmod=0755 --checksum=sha256:7c133ae4b9490d912287188c62ea570729cfa74f0ea357e4be672ce696b4aa29 \
    https://github.com/mlocati/docker-php-extension-installer/releases/download/2.11.12/install-php-extensions \
    /usr/local/bin/
RUN install-php-extensions \
      pdo_sqlite sqlite3 intl bcmath opcache \
      mbstring dom xml ctype fileinfo tokenizer filter \
      pdo_mysql pdo_pgsql redis sockets pcntl

# --- nginx + s6-overlay v3 (real PID1: reaps zombies, forwards signals) ---
# sqlite3 (the CLI, NOT the `sqlite3` PHP extension above) is installed here
# for the `.backup`-based consistent-snapshot workflow docker/README.md
# documents (gate-review batch-2 #5 — the image previously carried only the
# PHP extension, so `docker compose exec sendtrap sqlite3 ...` 404'd on
# /usr/bin/sqlite3). Rolled into the same apk layer as nginx/tzdata/xz.
RUN set -eux; \
    # Pull in Alpine security patches published after the php base image was
    # cut (the Trivy release gate fails on any fixable HIGH/CRITICAL in the
    # base packages, e.g. c-ares 1.34.6-r0 -> 1.34.8-r0 on alpine 3.24.1).
    apk upgrade --no-cache; \
    apk add --no-cache nginx sqlite tzdata xz; \
    case "${TARGETARCH:-amd64}" in \
      amd64) S6_ARCH=x86_64 ;; \
      arm64) S6_ARCH=aarch64 ;; \
      *) echo "unsupported TARGETARCH=${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    wget -qO /tmp/s6-noarch.tar.xz "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz"; \
    wget -qO /tmp/s6-arch.tar.xz   "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-${S6_ARCH}.tar.xz"; \
    tar -C / -Jxpf /tmp/s6-noarch.tar.xz; \
    tar -C / -Jxpf /tmp/s6-arch.tar.xz; \
    rm -f /tmp/s6-*.tar.xz; \
    apk del xz

# --- unprivileged app user (uid/gid 1000, §1.5) ---
RUN addgroup -g 1000 sendtrap \
 && adduser -D -u 1000 -G sendtrap -h /app -s /sbin/nologin sendtrap

WORKDIR /app

# Application code + built artifacts. vendor/ (optimized autoloader) and
# public/build come from the earlier stages; the rest is the app source.
COPY --chown=sendtrap:sendtrap . /app
COPY --from=vendor  --chown=sendtrap:sendtrap /app/vendor       /app/vendor
COPY --from=assets  --chown=sendtrap:sendtrap /app/public/build /app/public/build

# storage/app is redirected onto the /data volume so message bodies + the
# STARTTLS self-signed cert persist (§3.2). Baked at build (not a runtime
# symlink) so it is safe under a read-only rootfs. /data is created at runtime.
RUN rm -rf /app/storage/app \
 && ln -s /data/storage/app /app/storage/app \
 && rm -f /app/.env /app/.env.* \
 && rm -rf /app/bootstrap/cache/* /app/storage/framework/cache/* \
           /app/storage/framework/views/* /app/storage/logs/*

# s6 service tree, entrypoint, nginx/php-fpm/php config, healthcheck.
COPY docker/rootfs/ /
RUN chmod +x /usr/local/bin/sendtrap-bootstrap /usr/local/bin/sendtrap-healthcheck \
 && mkdir -p /tmp/nginx

# Remove the default php-fpm pool so only our zz-sendtrap.conf pool is active.
RUN rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf.default /usr/local/etc/php-fpm.d/zz-docker.conf

# --- production-safe defaults; every value stays env-overridable because the
#     config cache is built at BOOT, not here (§1.1/§4). ---
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost \
    LOG_CHANNEL=stderr \
    LOG_STACK=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    SESSION_DRIVER=database \
    QUEUE_CONNECTION=database \
    CACHE_STORE=database \
    BROADCAST_CONNECTION=log \
    FILESYSTEM_DISK=local \
    SENDTRAP_SMTP_PORT=1025 \
    S6_READ_ONLY_ROOT=1 \
    S6_BEHAVIOUR_IF_STAGE2_FAILS=2 \
    S6_CMD_WAIT_FOR_SERVICES_MAXTIME=0 \
    S6_KEEP_ENV=1

# OCI image labels so GHCR links the image to its source/docs (§9).
# Provisional org (owner-TBD) — parameterized so a release build sets the real
# repo/version without editing this file. The runtime never contacts GitHub.
# PRODUCT_URL defaults to the source repo; a release build may repoint it at
# the public product/docs site via --build-arg.
ARG SOURCE_REPO_URL=https://github.com/sendtraphq/sendtrap-community
ARG PRODUCT_URL=https://github.com/sendtraphq/sendtrap-community
ARG IMAGE_VERSION=0.0.0-dev
LABEL org.opencontainers.image.title="Sendtrap Community" \
      org.opencontainers.image.description="Self-hosted, single-workspace email sandbox (SMTP catcher + REST API)." \
      org.opencontainers.image.source="${SOURCE_REPO_URL}" \
      org.opencontainers.image.url="${PRODUCT_URL}" \
      org.opencontainers.image.documentation="${PRODUCT_URL}" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${IMAGE_VERSION}"

EXPOSE 8080 1025

# Liveness for BOTH ingestion legs: /up returns 200 AND the SMTP daemon
# answers 220 (§5). Uses php only — php:8.3-fpm-alpine ships no curl.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=5 \
  CMD ["sendtrap-healthcheck"]

# s6-overlay is PID1; it runs bootstrap then the supervised longruns. No USER
# directive: s6 prepares /run; nginx/FPM keep minimal container-root masters
# but drop request workers to uid 1000, and every app longrun uses
# s6-setuidgid (§1.5).
ENTRYPOINT ["/init"]
