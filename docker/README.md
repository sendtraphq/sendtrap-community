# Sendtrap Community — Docker runbook

A single durable container: web UI + REST API (nginx + php-fpm on `:8080`), the
SMTP ingestion daemon (`:1025`), a queue worker, and the scheduler — supervised
by s6-overlay v3. SQLite + local disk by default; all state on one volume.

> Self-hosting a mail sandbox for a solo dev or small team. For "grown-up"
> backends (MySQL/Postgres, Redis, S3) see [External backends](#external-backends)
> — same image, no rebuild.

## One-command start

```bash
cp docker/.env.example .env      # edit APP_URL + SENDTRAP_ADMIN_* first
docker compose up -d
```

That boots one `sendtrap` service on SQLite/local/DB-queue with a single named
volume (`sendtrap-data` → `/data`). On a fresh volume the boot oneshot resolves
`APP_KEY`, runs migrations, and creates the workspace + first owner. Open
`APP_URL`; send test mail to host port `1025`.

`docker run` equivalent:

```bash
ADMIN_PASSWORD="$(openssl rand -base64 15)" && echo "admin password: ${ADMIN_PASSWORD}"
docker run -d --name sendtrap \
  -p 80:8080 -p 1025:1025 \
  -e APP_URL=https://mail.example.com \
  -e SENDTRAP_ADMIN_NAME="Admin" \
  -e SENDTRAP_ADMIN_EMAIL="admin@example.com" \
  -e SENDTRAP_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
  -v sendtrap-data:/data \
  ghcr.io/sendtraphq/sendtrap-community:latest
```

On Windows (PowerShell):

```powershell
$bytes = [byte[]]::new(15)
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$ADMIN_PASSWORD = [Convert]::ToBase64String($bytes)
Write-Host "admin password: $ADMIN_PASSWORD"

docker run -d --name sendtrap `
  -p 80:8080 -p 1025:1025 `
  -e APP_URL=https://mail.example.com `
  -e SENDTRAP_ADMIN_NAME="Admin" `
  -e SENDTRAP_ADMIN_EMAIL="admin@example.com" `
  -e SENDTRAP_ADMIN_PASSWORD=$ADMIN_PASSWORD `
  -v sendtrap-data:/data `
  ghcr.io/sendtraphq/sendtrap-community:latest
```

The required first-run env is `APP_URL` + the three `SENDTRAP_ADMIN_*` values;
on a fresh volume the container **refuses to start** without them rather than
create a password-less owner. See `docker/.env.example` for the full surface.

## Building the image

**Prerequisite — this is a hard cross-dependency on the `sendtrap/core`
publication, not optional.** The release image build runs a plain
`composer install` with no local overrides, which only resolves if
`composer.json`'s `repositories`/`require` entries already point at the
**published** `sendtrap/core` (Packagist package or public GitHub tag), not
a local pre-publication VCS mirror. Do not attempt a release/CI build before
that repointing lands — the publication checklist repoints it as part of
making `sendtrap/core` public; the container build is a downstream consumer
of that step, not a place to work around it.

**Release build.** The published image builds from the published Community repo
consuming the published `sendtrap/core` (Packagist / a public GitHub tag). Once
`composer.json`'s `repositories` points at that public core, a plain build works:

```bash
docker buildx build -t ghcr.io/sendtraphq/sendtrap-community:VERSION .
```

**Local / dev build (before the public tag exists).** Supply the local Core
mirror as a named build context. The Dockerfile copies it to a neutral internal
path and refreshes only `sendtrap/core` in the build layer; the host path is
never written into project Composer metadata:

```bash
MIRROR=/path/to/sendtrap-core-mirror.git
docker buildx build \
  --build-arg CORE_SOURCE=mirror \
  --build-context core-mirror="$MIRROR" \
  -t sendtrap/community:local .
```

The **runtime image never contacts the mirror or GitHub** — it is fully
self-contained. OCI source/url/version labels are set from build args
(`SOURCE_REPO_URL`, `PRODUCT_URL`, `IMAGE_VERSION`) at release build so GHCR links
the image to its source at `sendtraphq/sendtrap-community`.

## Persistence — what lives on `/data`

```
/data
├── database.sqlite   # DB: messages, inboxes, users, workspace + sessions/queue/cache
├── storage/app/      # raw .eml + attachments (private/), STARTTLS cert (sendtrap-tls/)
└── app.key           # persisted APP_KEY (chmod 600) when not env-supplied
```

Three things must persist to retain mail across restart/upgrade: the **database**,
the **message bodies** (`storage/app`), and the **`APP_KEY`**. All three live on
the one `/data` volume.

### APP_KEY is a backup artifact — the critical trap

`APP_KEY` encrypts each inbox's `smtp_password` and signs sessions. **Lose or
change it and those become unusable** — the inbox SMTP passwords must be
regenerated and everyone is logged out. What is *not* affected: message
metadata/bodies, `api_token`, and `webhook_secret` are plaintext and survive a
key change intact.

- Leave `APP_KEY` unset to auto-generate + persist it to `/data/app.key` on
  first boot (steady state: it is loaded from there every boot).
- Or set `APP_KEY` in your env/secret store and keep it forever. If a supplied
  `APP_KEY` disagrees with the key that already encrypted `/data`, the container
  **fails loudly and refuses to start** rather than silently corrupt data.
- **Every backup must include the key** (the `/data` volume already contains
  `app.key`; if you set `APP_KEY` via env, back that up separately).

## Backup

- **Volume snapshot (simplest):** back up the whole `/data` volume — DB, message
  bodies, TLS cert, and `app.key` in one shot.
- **Consistent DB snapshot (SQLite):**
  `docker compose exec sendtrap sqlite3 /data/database.sqlite ".backup /data/backup.sqlite"`
  then copy `backup.sqlite` + a `tar` of `/data/storage` + `app.key`. The image
  carries the `sqlite3` **CLI** (not just the PHP `sqlite3` extension) for
  exactly this command. `scripts/sqlite-backup-restore-test.sh` is an
  automated, Docker-free test (runs in ordinary CI) that proves `.backup` +
  restore round-trip a SQLite database correctly; `scripts/container-e2e.sh`
  exercises the same commands inside the real image (D-20).
- **External DB:** your own `mysqldump`/`pg_dump` + a copy of `/data/storage`
  (or the S3 bucket) **+ `APP_KEY`**.

## Restore

Recreate the `/data` volume from the snapshot (or restore the DB dump +
`storage/` + `app.key`), then `docker compose up -d`. Because the restored
`APP_KEY` matches the ciphertext, inbox credentials and sessions come back
intact. If the key was *not* captured, bodies/metadata restore fine but each
inbox's `smtp_password` must be regenerated.

## Upgrade

```bash
docker compose pull
docker compose up -d          # recreates on the SAME /data volume
```

On boot the oneshot re-runs migrations (append-only; already-applied ones skip)
and the installer (a no-op once the owner exists); `APP_KEY` loads from
`/data/app.key`. Old mail is untouched. This is the "retain across upgrade" path
and is exercised by `scripts/container-e2e.sh`.

## Rollback

Immutable image tags make rollback = pull the previous tag + `up -d` on the same
volume, **provided the newer version ran no destructive migration**. Core
migrations are append-only, so rolling back within a minor line is safe; an old
image simply ignores a column a newer one added. A DB restore from backup is the
escape hatch for any non-backward-compatible migration.

## External backends

Set env and enable the matching Compose profile — the image already carries
`pdo_mysql`, `pdo_pgsql`, and `redis`, so **no rebuild**:

```bash
# MySQL + Redis, using the bundled profiles:
docker compose --profile mysql --profile redis up -d
```

| Concern | Default | Override |
|---|---|---|
| Database | SQLite `/data/database.sqlite` | `DB_CONNECTION=mysql\|pgsql` + `DB_*` |
| Queue / Cache / Session | `database` (SQLite) | `QUEUE_CONNECTION`/`CACHE_STORE`/`SESSION_DRIVER=redis` + `REDIS_*` |
| Message storage | `local` disk → `/data` | `FILESYSTEM_DISK=s3` + `AWS_*` |

With external DB + S3 + Redis the container is stateless **except `APP_KEY`** —
the encrypted `smtp_password` now lives in the external DB, still keyed to it.

## Security / exposure

- **Least-privilege + hardened:** request handlers and application services run
  as uid 1000. Only s6 and the minimal nginx/PHP-FPM process-manager masters
  remain container-root; they do not handle requests and run with all
  capabilities dropped except the documented minimum. The reference Compose
  uses `read_only: true`, `cap_drop: [ALL]` + a minimal `cap_add` (see
  [Capabilities](#capabilities) below), `no-new-privileges`, with tmpfs scratch
  for `/tmp`, `/run`, `/app/bootstrap/cache`, `/app/storage/framework`, and
  `/app/storage/logs`. No process binds a privileged port (web `8080`, SMTP
  `1025`), so **no `NET_BIND_SERVICE` is needed** — both ports are >1024 and
  bindable by an unprivileged uid.
- **TLS / forwarded headers:** front the web port with a TLS-terminating reverse
  proxy (Caddy/Traefik/nginx/Cloudflare). The image trusts `X-Forwarded-*` by
  default (`SENDTRAP_TRUSTED_PROXIES`, `SENDTRAP_FORCE_HTTPS`); restrict trusted
  proxies to your proxy's CIDR on a hostile network.
- **SMTP port 1025:** delivery requires per-inbox SMTP AUTH, but **do not publish
  1025 to the public internet** without the workspace IP allowlist
  (`SENDTRAP_INSTANCE_ALLOWED_IPS`) and/or `SENDTRAP_REQUIRE_TLS=true`. A sandbox
  is not an open relay, but should not be needlessly exposed.
- **Secrets:** deliver `APP_KEY` and `SENDTRAP_ADMIN_PASSWORD` via Docker/Compose
  secrets or an env-file, never baked into an image.

### Capabilities

The container's PID 1 (s6-overlay) and bootstrap start as **container root** so
bootstrap can prepare the `/data` volume and runtime tmpfs directories. The
nginx and PHP-FPM masters also remain container-root so they can open inherited
container log descriptors and manage worker lifecycles; their request workers
explicitly run as uid/gid 1000. SMTP, queue and scheduler are stepped directly
to uid/gid 1000 by `s6-setuidgid`. `cap_drop: [ALL]` removes everything first,
then the reference Compose adds back exactly what initialization, worker
step-down and signal forwarding require:

| Capability | Why it's needed |
|---|---|
| `CHOWN` | the bootstrap oneshot `chown -R sendtrap:sendtrap` on `/data` and the tmpfs scratch dirs |
| `SETUID` | nginx/PHP-FPM and `s6-setuidgid` drop request/application workers to uid 1000 |
| `SETGID` | nginx/PHP-FPM and `s6-setuidgid` drop request/application workers to gid 1000 |
| `DAC_OVERRIDE` | the bootstrap oneshot (still root at that point) reads/writes files a **previous** boot already handed to uid 1000 — e.g. `cat`-ing the chmod-600 `/data/app.key` on every boot after the first |
| `FOWNER` | the bootstrap oneshot `chmod`s a directory a prior boot's `chown` already gave to uid 1000 (e.g. `sendtrap-tls` gets `chmod 700` re-applied every boot) |
| `KILL` | the s6 supervisor (root) forwards `SIGTERM`/`SIGKILL` into child processes it does not own (uid 1000) on restart/shutdown |

Nothing else is added. In particular **`NET_BIND_SERVICE` is not needed** —
nginx binds `8080` and the SMTP daemon binds `1025`, both above the
privileged-port threshold (1024), so no capability is required to bind them
even as an unprivileged user.

## Rootless notes

The image's request handlers and application services run as uid 1000 and need
no privileged ports. It also runs under a rootless Docker/Podman daemon: the
container-root process-manager identities map to the daemon's unprivileged host
user, while `/data` only needs to be writable within that user namespace.
Read-only rootfs + the tmpfs mounts in the reference Compose are compatible
with rootless operation.

## Health

`docker ps` health reflects **both** ingestion legs: the `sendtrap-healthcheck`
script asserts `/up` returns 200 **and** the SMTP daemon answers a `220` banner
(pure `php`, no `curl`). Readiness is structural — the boot oneshot gates every
long-running service, so the web/SMTP ports do not accept traffic until
migrations complete.

## Live updates (Reverb)

The default image is Reverb-free and fully functional (the UI degrades to "no
live updates"; the REST API's `?wait=` long-poll covers test needs). Live
updates ship as a **published image variant**, not a runtime toggle, because the
browser bundle compiles `VITE_REVERB_*` at asset-build time.

## CI profile (ephemeral, `SENDTRAP_MODE=ci`)

The **same image** has a second mode for isolated CI jobs, selected by
`SENDTRAP_MODE=ci`. It is **ephemeral and test-only**: it stores **no data**
(the DB, message bodies, and `app.key` all live in a tmpfs `/data`), ships
**well-known default credentials**, and boots to `/up`-ready in a few seconds
with a project + inbox already seeded — so a job needs **zero discovery**. This
is *not* the durable profile; it uses **no named volume** and keeps nothing
across runs.

> **Isolation warning.** CI mode uses WELL-KNOWN DEFAULT credentials
> (`ci` / `ci-smtp-password` / `ci-api-token`). **Never publish `:1025` /
> `:8080` to a public interface** — bind them only on an isolated CI network.
> The container prints this warning as a loud boot banner on every start, and
> **refuses to boot on a persistent (non-tmpfs) `/data`** unless you set
> `SENDTRAP_CI_ACK_PERSISTENT=1` (the accidental-cross-run-leak guard).

### One-command CI job

```bash
docker compose -f docker/docker-compose.ci.yml up -d
```

Or the equivalent one-liner (the canonical hardened flag set — the **complete**
tmpfs writable surface plus `cap_drop ALL` with the five ephemeral caps added
back; omitting a tmpfs path hits EROFS, dropping all caps with none added never
boots):

```bash
docker run --rm \
  -e SENDTRAP_MODE=ci -e APP_URL=http://localhost:8080 \
  --tmpfs /data --tmpfs /tmp --tmpfs /run:rw,exec,nosuid,nodev,mode=755 \
  --tmpfs /app/bootstrap/cache --tmpfs /app/storage/framework --tmpfs /app/storage/logs \
  --read-only \
  --cap-drop ALL \
  --cap-add CHOWN --cap-add SETUID --cap-add SETGID --cap-add FOWNER --cap-add KILL \
  --security-opt no-new-privileges:true \
  -p 8080:8080 -p 1025:1025 \
  ghcr.io/sendtraphq/sendtrap-community:latest
```

Ready-to-copy job files live in `docker/ci-examples/` — GitHub Actions
(`github-actions.yml`), GitLab CI (`gitlab-ci.yml`), and a generic
`docker-run.sh`. Copy `docker/.env.ci.example` to `.env` to override any
default (everything there is optional). **No `-v` in CI** — a named volume or
bind at `/data` defeats ephemerality and the entrypoint refuses it.

### The credential + connection contract

Credentials are **input** (supplied `SENDTRAP_CI_*` env with the fixed defaults
below), so a job knows every value with zero parsing. `sendtrap:ci-seed` also
**emits** the contract as one JSON line to **stdout** (the primary sink — read
via `docker logs`; survives `--rm`) and, best-effort, to
`/run/sendtrap/ci-contract.json` (a secondary tmpfs sink a live container can
`docker cp`). There is **no** network endpoint serving it (no token leak).

| What the job needs | Value (default) | Env override |
|---|---|---|
| SMTP host | the runner-assigned service host/alias (e.g. `sendtrap`, or `localhost` + mapped port) | — |
| SMTP port | `1025` | — |
| SMTP username | `ci` | `SENDTRAP_CI_SMTP_USERNAME` |
| SMTP password | `ci-smtp-password` | `SENDTRAP_CI_SMTP_PASSWORD` |
| API base URL | `http://<host>:8080/api/v1` | (from `APP_URL`) |
| API token (Bearer) | `ci-api-token` | `SENDTRAP_CI_API_TOKEN` |
| Project / inbox name | `CI` / `ci` | `SENDTRAP_CI_PROJECT` / `SENDTRAP_CI_INBOX` |

SMTP keeps STARTTLS advertised with `require_tls=false`, so a client may
authenticate over **plaintext** (no cert trust needed). Set `SENDTRAP_TLS=false`
as an opt-in fast path to skip TLS entirely. Assert with the **current** APIs:
`POST /api/v1/assert` (`{subject_contains,timeout}` → `{matched:bool}`) or
`GET /api/v1/messages?wait=N`, both `Authorization: Bearer <api_token>`.

### Readiness + ephemerality

- **Readiness = `GET /up` → 200, for the web leg.** It is *structurally*
  sufficient for the seed: nginx and php-fpm gate on the boot oneshot, and the
  CI seed runs **inside** it, so the web port does not accept until migrate +
  install + `ci-seed` have completed — a green `/up` implies the seeded inbox
  already exists. But the SMTP daemon is a **separate** supervised longrun
  behind that same oneshot, not a further-downstream step gated by `/up`
  itself — a strict reading leaves a race where `/up` is already 200 but
  `:1025` isn't accepting yet. Gate on the image's own `HEALTHCHECK` instead
  when a job (like this one) also drives SMTP: it asserts **both** `/up` 200
  **and** an SMTP `220` banner (`--health-cmd sendtrap-healthcheck` /
  `depends_on: service_healthy` / poll `docker inspect -f
  '{{.State.Health.Status}}'` for a hand-written `docker run`, as
  `docker/ci-examples/docker-run.sh` does).
- **No leaked state.** No named volume, `/data` on tmpfs, `--rm` drops the
  overlay on exit, and `app.key` is never persisted durably — two consecutive
  runs are independent (the second boots an empty inbox). Outbound forwarding is
  off three ways (`SENDTRAP_FORWARDS_PER_MONTH=0` + `MAIL_MAILER=log` + no
  `auto_forward_to` on the seeded inbox), all forced by the entrypoint.

The `scripts/ci-profile-e2e.sh` script proves this exit gate end-to-end on a
Docker host.

## End-to-end test

`scripts/container-e2e.sh` builds the image, starts it on a fresh volume, sends
mail over SMTP, asserts it via the REST API, proves the `sqlite3` CLI's
`.backup` command works against the live database, then proves persistence
across `restart` and a **real upgrade** — it builds and tags a SECOND image
from the same source and deploys it onto the SAME `/data` volume (not a
recreate of the same image), asserting the boot oneshot re-ran (migrate +
install) and that both the pre-upgrade message and a newly-sent post-upgrade
message are visible — and finally that `down -v` wipes it (persistence is
volume-bound). Pass `CORE_MIRROR=...` for a local build.

Beyond message-count retention, the restart and upgrade steps each also
re-run a fresh SMTP AUTH + send with the **same** inbox `smtp_username`/
`smtp_password` captured before the restart. AUTH only succeeds if
`smtp_password` still decrypts, which only happens if `APP_KEY` survived —
this is the one dependency the plaintext `api_token`/API-only assertions
never exercise, and it is the exact thing that would go silently wrong if a
regression rotated `APP_KEY` on every boot. The script also asserts: 3
consecutive restarts stay idempotent (install/migrate safe to re-run, SMTP
AUTH still works after all three), and that supplying a **mismatched**
`APP_KEY` against an already-persisted `/data/app.key` makes the container
refuse to start (loud failure), rather than silently starting with
undecryptable data.
