#!/usr/bin/env bash
#
# Sendtrap Community — container end-to-end persistence test (Plan 06 Phase 7
# §11, slice 7b-6). Proves the exit gate:
#
#   "A clean machine can start Community with one documented command, complete
#    installation, receive mail and retain it across restart/upgrade."
#
# Flow: build -> up -> healthy -> seed inbox -> SMTP send -> assert via REST
#       API -> restart (assert persists + re-AUTH proves APP_KEY survived) ->
#       3x-restart idempotency -> recreate/upgrade on same volume (assert
#       persists + re-AUTH) -> APP_KEY mismatch guard (must refuse to boot) ->
#       down -v negative check (mail gone) -> teardown.
#
# Every restart/upgrade check re-runs a fresh SMTP AUTH + send with the SAME
# inbox smtp_username/smtp_password captured before the restart (Phase 7b
# review HIGH-1). AUTH only succeeds if smtp_password still decrypts, which
# only holds if APP_KEY survived the restart/upgrade — the plaintext
# api_token/message-count assertions alone never exercise that path, so a
# regression that silently rotated APP_KEY on every boot would otherwise pass
# green. If SMTP AUTH fails post-restart/upgrade, the test FAILS.
#
# Requirements: docker + docker compose + python3 (SMTP client). For a LOCAL
# build it also needs the sendtrap/core mirror; pass its path as CORE_MIRROR
# (see docker/README.md / Dockerfile BUILD SOURCE).
#
# Usage:
#   CORE_MIRROR=/path/to/sendtrap-core-mirror.git scripts/container-e2e.sh
#   SKIP_BUILD=1 SENDTRAP_IMAGE=sendtrap/community:e2e scripts/container-e2e.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

IMAGE="${SENDTRAP_IMAGE:-sendtrap/community:e2e}"
PROJECT="sendtrap-e2e"
WEB_PORT="${SENDTRAP_WEB_PORT:-18080}"
SMTP_PORT="${SENDTRAP_SMTP_HOST_PORT:-11025}"
ADMIN_EMAIL="admin@example.com"
ADMIN_PASS="e2e-admin-password"

export SENDTRAP_IMAGE="$IMAGE"
export SENDTRAP_WEB_PORT="$WEB_PORT"
export SENDTRAP_SMTP_HOST_PORT="$SMTP_PORT"
export APP_URL="http://localhost:${WEB_PORT}"
export SENDTRAP_ADMIN_NAME="E2E Admin"
export SENDTRAP_ADMIN_EMAIL="$ADMIN_EMAIL"
export SENDTRAP_ADMIN_PASSWORD="$ADMIN_PASS"
export SENDTRAP_WORKSPACE_NAME="E2E"

dc() { docker compose -p "$PROJECT" "$@"; }
say() { printf '\n=== %s ===\n' "$*"; }
fail() { printf '\nE2E FAILED: %s\n' "$*" >&2; dc logs --no-color 2>&1 | tail -80 >&2 || true; teardown; exit 1; }

teardown() {
  say "teardown"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
}
trap 'teardown' EXIT

# build_image <tag> — one real `docker buildx build` of this Dockerfile,
# tagged <tag>. Used for the initial image (step 1) AND for the "second
# image" the upgrade step (7) needs (build/tag a second image, then
# `up -d` it on the SAME volume). CORE_MIRROR (when set) is passed the
# same way both times — CORE_SOURCE=mirror selects the Dockerfile's
# core-src-mirror stage; without it the default CORE_SOURCE=published
# makes the context irrelevant.
build_image() {
  local tag="$1"
  local build_args=()
  if [ -n "${CORE_MIRROR:-}" ]; then
    build_args=(--build-arg "CORE_SOURCE=mirror" --build-context "core-mirror=${CORE_MIRROR}")
  fi
  docker buildx build "${build_args[@]}" -t "$tag" --load .
}

# --------------------------------------------------------------------------
say "1. build image"
if [ "${SKIP_BUILD:-0}" != "1" ]; then
  build_image "$IMAGE"
fi

# Assert the runtime layer carries no build toolchain (§11 step 1).
say "1b. assert no build toolchain in runtime"
if docker run --rm --entrypoint sh "$IMAGE" -c 'command -v composer || command -v npm || command -v node' 2>/dev/null; then
  fail "runtime image unexpectedly contains composer/node/npm"
fi
docker run --rm --entrypoint php "$IMAGE" -m | grep -i '^intl$' >/dev/null || fail "intl extension missing"
echo "OK: no composer/node/npm; intl present"

# --------------------------------------------------------------------------
say "2. clean-machine start on a fresh volume"
dc down -v >/dev/null 2>&1 || true
dc up -d

wait_healthy() {
  local tries=60
  while [ "$tries" -gt 0 ]; do
    local cid status
    cid="$(dc ps -q sendtrap)"
    [ -n "$cid" ] || { sleep 2; tries=$((tries-1)); continue; }
    status="$(docker inspect -f '{{.State.Health.Status}}' "$cid" 2>/dev/null || echo starting)"
    case "$status" in
      healthy) echo "container healthy"; return 0 ;;
      unhealthy) fail "container reported unhealthy" ;;
    esac
    sleep 3; tries=$((tries-1))
  done
  fail "timed out waiting for healthy"
}
wait_healthy

# --------------------------------------------------------------------------
say "3. seed an inbox (workspace already installed by bootstrap)"
SMTP_USER="e2e"
SMTP_PASS="e2e-smtp-password"
API_TOKEN="e2e-api-token"
SEED_OUTPUT=""
if ! SEED_OUTPUT="$(dc exec -T --user sendtrap \
    -e SENDTRAP_CI_PROJECT="E2E Project" \
    -e SENDTRAP_CI_INBOX="E2E Inbox" \
    -e SENDTRAP_CI_SMTP_USERNAME="$SMTP_USER" \
    -e SENDTRAP_CI_SMTP_PASSWORD="$SMTP_PASS" \
    -e SENDTRAP_CI_API_TOKEN="$API_TOKEN" \
    -e SENDTRAP_CI_CONTRACT_PATH=/tmp/e2e-ci-contract.json \
    sendtrap php /app/artisan sendtrap:ci-seed --no-ansi 2>&1)"; then
  fail "could not seed the E2E inbox: ${SEED_OUTPUT}"
fi
CREDS_JSON="$(printf '%s' "$SEED_OUTPUT" | grep -o '{.*}' | tail -1 || true)"
[ -n "$CREDS_JSON" ] || fail "seed command emitted no JSON contract (output: ${SEED_OUTPUT})"
printf '%s' "$CREDS_JSON" | SMTP_USER="$SMTP_USER" SMTP_PASS="$SMTP_PASS" API_TOKEN="$API_TOKEN" python3 -c '
import json, os, sys
c = json.load(sys.stdin)
assert c["smtp"]["username"] == os.environ["SMTP_USER"], c
assert c["smtp"]["password"] == os.environ["SMTP_PASS"], c
assert c["api"]["token"] == os.environ["API_TOKEN"], c
' || fail "seed command JSON contract did not match supplied credentials"
echo "seeded inbox smtp_user=${SMTP_USER}"

# --------------------------------------------------------------------------
# smtp_send <subject> — AUTH + send one message over SMTP using the SAME
# inbox smtp_username/smtp_password captured in step 3. Used both for the
# initial send and for every post-restart/post-upgrade re-AUTH check: a
# failure here means smtp_password no longer decrypts, i.e. APP_KEY rotated.
smtp_send() {
  local subject="$1"
  SMTP_HOST=127.0.0.1 SMTP_PORT="$SMTP_PORT" SMTP_USER="$SMTP_USER" SMTP_PASS="$SMTP_PASS" SUBJECT="$subject" \
  python3 - <<'PY'
import os, smtplib, ssl, sys
from email.message import EmailMessage
msg = EmailMessage()
msg["From"] = "sender@example.com"
msg["To"] = "catch@inbox.test"
msg["Subject"] = os.environ["SUBJECT"]
msg.set_content("hello from the sendtrap container e2e")
ctx = ssl._create_unverified_context()
with smtplib.SMTP(os.environ["SMTP_HOST"], int(os.environ["SMTP_PORT"]), timeout=20) as s:
    s.ehlo()
    if s.has_extn("starttls"):
        s.starttls(context=ctx); s.ehlo()
    s.login(os.environ["SMTP_USER"], os.environ["SMTP_PASS"])
    s.send_message(msg)
print("sent", os.environ["SUBJECT"])
PY
}

# api_message_count <subject> — queries the REST API with the inbox bearer
# token and echoes the count of messages whose subject matches <subject>.
api_message_count() {
  local subject="$1"
  local body
  body="$(curl -fsS -H "Authorization: Bearer ${API_TOKEN}" \
      "http://127.0.0.1:${WEB_PORT}/api/v1/messages" 2>/dev/null || echo '[]')"
  printf '%s' "$body" | SUBJECT="$subject" python3 -c '
import sys, json, os
try:
    data = json.load(sys.stdin)
except Exception:
    print(0); sys.exit()
items = data.get("data", data) if isinstance(data, dict) else data
n = sum(1 for m in items if isinstance(m, dict) and m.get("subject") == os.environ["SUBJECT"])
print(n)'
}

# wait_for_message <subject> [attempts] — SMTP acceptance precedes the queued
# message-processing job. Poll for the exact subject so an older inbox message
# cannot make the assertion return before the newly accepted message is stored.
wait_for_message() {
  local subject="$1"
  local attempts="${2:-30}"
  local count

  while [ "$attempts" -gt 0 ]; do
    count="$(api_message_count "$subject")"
    if [ "${count:-0}" -ge 1 ]; then
      return 0
    fi
    attempts=$((attempts - 1))
    [ "$attempts" -gt 0 ] && sleep 1
  done

  return 1
}

say "4. receive mail via SMTP :$SMTP_PORT"
SUBJECT="e2e-$(date +%s)-$RANDOM"
smtp_send "$SUBJECT" || fail "SMTP send failed"

# --------------------------------------------------------------------------
say "5. assert message arrived via REST API"
wait_for_message "$SUBJECT" || fail "message not visible via API after send"
echo "OK: message present via API"

# --------------------------------------------------------------------------
# gate-review batch-2 #5: the runtime image now carries the sqlite3 CLI (not
# just the PHP sqlite3 extension) so docker/README.md's documented
# `.backup`-based snapshot command actually works. scripts/sqlite-backup-
# restore-test.sh already proves the command's SEMANTICS (Docker-free, runs
# in ordinary CI); this step proves the CLI is genuinely PRESENT in the real
# built image and that `.backup` succeeds against the live, in-use database
# with real seeded data.
say "5b. sqlite3 CLI is present in the image; docker/README.md's .backup command works"
dc exec -T sendtrap sh -c 'command -v sqlite3' >/dev/null 2>&1 \
  || fail "sqlite3 CLI not found in the runtime image (docker/README.md Backup section requires it)"
dc exec -T sendtrap sh -c "sqlite3 \$DB_DATABASE \".backup /tmp/e2e-backup.sqlite\"" \
  || fail "sqlite3 .backup command failed inside the container"
BACKUP_MSG_COUNT="$(dc exec -T sendtrap sh -c 'sqlite3 /tmp/e2e-backup.sqlite "SELECT COUNT(*) FROM messages;"' | tr -d '\r')"
[ "${BACKUP_MSG_COUNT:-0}" -ge 1 ] || fail "the .backup snapshot has no messages -- expected the message sent in step 4"
dc exec -T sendtrap rm -f /tmp/e2e-backup.sqlite || true
echo "OK: sqlite3 CLI present; .backup produced a snapshot containing the live message"

# --------------------------------------------------------------------------
say "6. restart — assert message persists + APP_KEY survived (smtp still auths)"
dc restart
wait_healthy
wait_for_message "$SUBJECT" || fail "original message gone after restart"
echo "OK: original message survived restart"

RESTART_SUBJECT="e2e-restart-$(date +%s)-$RANDOM"
smtp_send "$RESTART_SUBJECT" || fail "post-restart SMTP AUTH+send failed -- APP_KEY likely rotated (smtp_password no longer decrypts)"
wait_for_message "$RESTART_SUBJECT" || fail "post-restart SMTP-sent message not visible via API"
echo "OK: SMTP AUTH decrypted smtp_password post-restart (APP_KEY intact) and the new message arrived via API"

# --------------------------------------------------------------------------
say "6b. 3x-restart idempotency (install/migrate safe to re-run)"
for i in 1 2 3; do
  echo "--- restart iteration ${i}/3 ---"
  dc restart
  wait_healthy
done
wait_for_message "$SUBJECT" || fail "original message gone after 3x restart"
IDEMPOTENT_SUBJECT="e2e-idempotent-$(date +%s)-$RANDOM"
smtp_send "$IDEMPOTENT_SUBJECT" || fail "SMTP AUTH+send failed after 3x restart -- bootstrap (migrate/install/APP_KEY resolution) is not idempotent"
wait_for_message "$IDEMPOTENT_SUBJECT" || fail "message sent after 3x restart not visible via API"
echo "OK: bootstrap (migrate/install/APP_KEY resolution) is safe across 3 consecutive restarts"

# --------------------------------------------------------------------------
# 7. Real upgrade: build/tag a SECOND image, `up -d` it on the SAME volume,
# then assert migrations ran idempotently and the message from step 3 is
# still present. A
# `--force-recreate` of the SAME image (the previous version of this script)
# never exercises the migrate/boot path a real image upgrade runs through —
# a rebuild, even of identical source, is sufficient to do that because it
# produces a genuinely different image the container is recreated FROM,
# forcing the full boot oneshot (package:discover, config/route/view:cache,
# migrate --force, sendtrap:install --force) to run again against the
# EXISTING /data volume, exactly as a real version upgrade would.
say "7. simulate upgrade — build/tag a SECOND image, deploy it onto the SAME volume"
UPGRADE_IMAGE="${IMAGE}-e2e-v2"
build_image "$UPGRADE_IMAGE"

# Same compose project (-p "$PROJECT") + same named volume (sendtrap-data,
# scoped to that project) — only the image tag changes, so `up -d` recreates
# the container FROM the new image against the volume the original container
# already populated. No teardown/down between builds: that IS the upgrade.
export SENDTRAP_IMAGE="$UPGRADE_IMAGE"
dc up -d
wait_healthy

# "migrations ran idempotently" — the boot oneshot's `migrate --force` runs
# unconditionally on every boot (already-applied migrations are skipped by
# Laravel itself); a healthy container after this recreate IS that proof,
# the same idempotency contract the 3x-restart check (6b) already exercises.
# Belt-and-braces: also grep the new container's boot log for the oneshot's
# own migrate step actually having run (not skipped by some future regression
# that no-ops the oneshot entirely).
dc logs --no-color sendtrap 2>&1 | grep '\[bootstrap\].*sendtrap:install' >/dev/null \
  || fail "post-upgrade boot log shows no sendtrap:install run -- the boot oneshot did not execute on the new image"
echo "OK: post-upgrade boot oneshot ran (migrate + install) against the existing volume"

wait_for_message "$SUBJECT" || fail "original message gone after upgrade to the second image"
echo "OK: original message survived the upgrade (second image, same volume)"

UPGRADE_SUBJECT="e2e-upgrade-$(date +%s)-$RANDOM"
smtp_send "$UPGRADE_SUBJECT" || fail "post-upgrade SMTP AUTH+send failed -- APP_KEY likely rotated across upgrade (smtp_password no longer decrypts)"
wait_for_message "$UPGRADE_SUBJECT" || fail "post-upgrade SMTP-sent message not visible via API"
echo "OK: SMTP AUTH decrypted smtp_password post-upgrade (APP_KEY intact) and a NEWLY-sent post-upgrade message arrived via API"

# --------------------------------------------------------------------------
say "7b. APP_KEY mismatch guard — a wrong key against a persisted app.key must refuse to boot"
DATA_VOLUME="$(docker volume ls -q \
  --filter "label=com.docker.compose.project=${PROJECT}" \
  --filter "label=com.docker.compose.volume=sendtrap-data")"
[ -n "$DATA_VOLUME" ] || fail "could not resolve the sendtrap-data volume name for the mismatch-guard check"

BADKEY_LOG="$(mktemp)"
WRONG_KEY="deliberately-wrong-key-$(date +%s)"
set +e
timeout 60 docker run --rm --name sendtrap-e2e-badkey \
  -e "APP_KEY=${WRONG_KEY}" \
  -e "APP_URL=${APP_URL}" \
  -v "${DATA_VOLUME}:/data" \
  "$IMAGE" >"$BADKEY_LOG" 2>&1
BADKEY_RC=$?
set -e
docker rm -f sendtrap-e2e-badkey >/dev/null 2>&1 || true

if [ "$BADKEY_RC" -eq 0 ]; then
  cat "$BADKEY_LOG" >&2
  rm -f "$BADKEY_LOG"
  fail "container booted successfully with a mismatched APP_KEY -- the mismatch guard did not fire"
fi
if [ "$BADKEY_RC" -eq 124 ]; then
  cat "$BADKEY_LOG" >&2
  rm -f "$BADKEY_LOG"
  fail "container did not exit within 60s after a mismatched APP_KEY -- expected a fast, loud bootstrap failure"
fi
if ! grep -qi "refusing to start" "$BADKEY_LOG"; then
  cat "$BADKEY_LOG" >&2
  rm -f "$BADKEY_LOG"
  fail "container exited (rc=${BADKEY_RC}) for an unexpected reason -- expected the APP_KEY mismatch guard message"
fi
rm -f "$BADKEY_LOG"
echo "OK: a mismatched APP_KEY against a persisted app.key makes the container refuse to start (rc=${BADKEY_RC})"

# --------------------------------------------------------------------------
say "8. negative check — down -v wipes the volume, mail must be gone"
dc down -v
dc up -d
wait_healthy
# Fresh volume => the seeded inbox/token no longer exists => 401/empty.
if curl -fsS -H "Authorization: Bearer ${API_TOKEN}" \
     "http://127.0.0.1:${WEB_PORT}/api/v1/messages" >/dev/null 2>&1; then
  fail "old token still worked after down -v (persistence not volume-bound)"
fi
echo "OK: persistence is volume-bound (fresh volume => mail + token gone)"

say "E2E PASSED"
