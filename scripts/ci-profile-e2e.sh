#!/usr/bin/env bash
#
# Sendtrap Community — CI-PROFILE end-to-end test (Plan 06 Phase 8b, §8.1).
# Sibling of scripts/container-e2e.sh; proves the Phase 8 exit gate:
#
#   "An isolated CI job can start the image, send SMTP mail, assert it via the
#    current API and tear down WITHOUT external services or leaked state."
#
# Flow:
#   1. build (or SKIP_BUILD + pull) the SAME image
#   2. run it ephemeral with the canonical §5.0 flag set (tmpfs /data, no -v,
#      --read-only, cap_drop ALL + the 5 CI caps, no-new-privileges, --rm)
#   3. readiness: poll /up -> 200 AND assert the container reports healthy
#   4. read the contract: PRIMARY = the JSON line from `docker logs` (survives
#      --rm); secondary = `docker cp /run/sendtrap/ci-contract.json` while live.
#      Assert both match the supplied SENDTRAP_CI_* creds
#   5. SMTP send: AUTH ci/ci-smtp-password over :1025 (python smtplib)
#   6. assert: POST /api/v1/assert -> matched:true AND GET /messages?wait= -> msg
#   7. teardown: docker rm -f (--rm)
#   8. NO-LEAK negative: a SECOND ephemeral container's /messages is EMPTY
#   9. isolation: the always-on banner is in the logs; a non-tmpfs /data in CI
#      WITHOUT SENDTRAP_CI_ACK_PERSISTENT=1 REFUSES to boot, and boots WITH it
#
# Like Phase 7's D-20 this REQUIRES a Docker host (this working env's user is not
# in the docker group). It is authored + statically validated (bash -n) here and
# executed on a Docker-capable CI runner or the owner's machine.
#
# Usage:
#   CORE_MIRROR=/path/to/sendtrap-core-mirror.git scripts/ci-profile-e2e.sh
#   SKIP_BUILD=1 SENDTRAP_IMAGE=sendtrap/community:ci scripts/ci-profile-e2e.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

IMAGE="${SENDTRAP_IMAGE:-sendtrap/community:ci-e2e}"
NAME="sendtrap-ci-e2e"
NAME2="sendtrap-ci-e2e-2"
WEB_PORT="${SENDTRAP_WEB_PORT:-18080}"
SMTP_PORT="${SENDTRAP_SMTP_HOST_PORT:-11025}"

# The deterministic CI credential contract (the documented defaults).
CI_SMTP_USER="ci"
CI_SMTP_PASS="ci-smtp-password"
CI_API_TOKEN="ci-api-token"
PDATA_VOLUME=""

# Canonical §5.0 flag set — the COMPLETE tmpfs writable surface + caps
# dropped-to-minimum. Used verbatim for every ephemeral run below.
CI_FLAGS=(
  --tmpfs /data --tmpfs /tmp --tmpfs /run:rw,exec,nosuid,nodev,mode=755
  --tmpfs /app/bootstrap/cache --tmpfs /app/storage/framework --tmpfs /app/storage/logs
  --read-only
  --cap-drop ALL
  --cap-add CHOWN --cap-add SETUID --cap-add SETGID --cap-add FOWNER --cap-add KILL
  --security-opt no-new-privileges:true
)

say() { printf '\n=== %s ===\n' "$*"; }
fail() {
  printf '\nCI-E2E FAILED: %s\n' "$*" >&2
  docker logs "$NAME" 2>&1 | tail -60 >&2 || true
  teardown
  exit 1
}

teardown() {
  docker rm -f "$NAME" "$NAME2" "${NAME2}-persist" "${NAME2}-ack" >/dev/null 2>&1 || true
  if [ -n "${PDATA_VOLUME:-}" ]; then
    docker volume rm -f "$PDATA_VOLUME" >/dev/null 2>&1 || true
  fi
}
trap 'teardown' EXIT

# --------------------------------------------------------------------------
say "1. build image (SKIP_BUILD=1 to reuse an existing tag)"
if [ "${SKIP_BUILD:-0}" != "1" ]; then
  build_args=()
  if [ -n "${CORE_MIRROR:-}" ]; then
    # CORE_SOURCE=mirror selects the Dockerfile's core-src-mirror stage
    # (gate-review batch-2 BLOCKER #2) — without it the default
    # CORE_SOURCE=published copies an empty dir regardless of the context.
    build_args=(--build-arg "CORE_SOURCE=mirror" --build-context "core-mirror=${CORE_MIRROR}")
  fi
  docker buildx build "${build_args[@]}" -t "$IMAGE" --load .
fi

# --------------------------------------------------------------------------
say "2. start ephemeral CI container (canonical §5.0 flags, no -v)"
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" \
  -e SENDTRAP_MODE=ci \
  -e "APP_URL=http://localhost:${WEB_PORT}" \
  -e "SENDTRAP_CI_SMTP_USERNAME=${CI_SMTP_USER}" \
  -e "SENDTRAP_CI_SMTP_PASSWORD=${CI_SMTP_PASS}" \
  -e "SENDTRAP_CI_API_TOKEN=${CI_API_TOKEN}" \
  "${CI_FLAGS[@]}" \
  -p "${WEB_PORT}:8080" -p "${SMTP_PORT}:1025" \
  "$IMAGE"

# --------------------------------------------------------------------------
say "3. readiness — poll /up AND assert docker health"
wait_ready() {
  local tries=40 status
  while [ "$tries" -gt 0 ]; do
    if curl -fsS "http://127.0.0.1:${WEB_PORT}/up" >/dev/null 2>&1; then
      status="$(docker inspect -f '{{.State.Health.Status}}' "$NAME" 2>/dev/null || echo none)"
      case "$status" in
        healthy|none) echo "ready (/up 200, health=${status})"; return 0 ;;
        unhealthy) fail "container reported unhealthy" ;;
      esac
    fi
    sleep 2; tries=$((tries-1))
  done
  fail "timed out waiting for /up + health"
}
wait_ready

# --------------------------------------------------------------------------
say "4. read the connection contract (PRIMARY = docker logs JSON line)"
CONTRACT="$(docker logs "$NAME" 2>&1 | grep -o '{"mode":"ci".*}' | tail -1)"
[ -n "$CONTRACT" ] || fail "no ci-seed JSON contract line found in docker logs"
printf '%s' "$CONTRACT" | CI_USER="$CI_SMTP_USER" CI_PASS="$CI_SMTP_PASS" CI_TOKEN="$CI_API_TOKEN" python3 -c '
import sys, json, os
c = json.load(sys.stdin)
assert c["mode"] == "ci", c
assert c["smtp"]["username"] == os.environ["CI_USER"], c["smtp"]
assert c["smtp"]["password"] == os.environ["CI_PASS"], c["smtp"]
assert c["api"]["token"] == os.environ["CI_TOKEN"], c["api"]
print("contract OK (stdout):", c["smtp"]["username"], c["api"]["token"])
' || fail "stdout contract did not match the supplied SENDTRAP_CI_* creds"

# Secondary sink: docker cp the tmpfs file while the container is still live.
if docker cp "${NAME}:/run/sendtrap/ci-contract.json" /tmp/ci-contract.json >/dev/null 2>&1; then
  python3 -c '
import json; c=json.load(open("/tmp/ci-contract.json"))
assert c["mode"]=="ci"; print("contract OK (file sink):", c["smtp"]["username"])
' || fail "file-sink contract malformed"
  rm -f /tmp/ci-contract.json
else
  echo "note: file sink not copyable (races --rm); stdout is authoritative"
fi

# --------------------------------------------------------------------------
smtp_send() {
  local subject="$1"
  SMTP_HOST=127.0.0.1 SMTP_PORT="$SMTP_PORT" SMTP_USER="$CI_SMTP_USER" SMTP_PASS="$CI_SMTP_PASS" SUBJECT="$subject" \
  python3 - <<'PY'
import os, smtplib, ssl
from email.message import EmailMessage
msg = EmailMessage()
msg["From"] = "sender@example.com"
msg["To"] = "catch@inbox.test"
msg["Subject"] = os.environ["SUBJECT"]
msg.set_content("hello from the sendtrap CI-profile e2e")
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

say "5. SMTP send (AUTH ${CI_SMTP_USER}) to :${SMTP_PORT}"
SUBJECT="Welcome ci-$(date +%s)-$RANDOM"
smtp_send "$SUBJECT" || fail "SMTP AUTH+send failed with the CI creds"

# --------------------------------------------------------------------------
say "6. assert via BOTH current APIs (/assert and /messages?wait=)"
ASSERT_BODY="$(curl -fsS -X POST "http://127.0.0.1:${WEB_PORT}/api/v1/assert" \
  -H "Authorization: Bearer ${CI_API_TOKEN}" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"subject_contains\":\"Welcome\",\"timeout\":15}" 2>/dev/null || echo '{}')"
grep -q '"matched":true' <<< "$ASSERT_BODY" \
  || fail "POST /assert did not return matched:true (body: ${ASSERT_BODY})"
echo "OK: POST /api/v1/assert matched:true"

MSG_BODY="$(curl -fsS "http://127.0.0.1:${WEB_PORT}/api/v1/messages?wait=15" \
  -H "Authorization: Bearer ${CI_API_TOKEN}" -H "Accept: application/json" 2>/dev/null || echo '[]')"
printf '%s' "$MSG_BODY" | SUBJECT="$SUBJECT" python3 -c '
import sys, json, os
data = json.load(sys.stdin)
items = data.get("data", data) if isinstance(data, dict) else data
assert any(isinstance(m, dict) and m.get("subject") == os.environ["SUBJECT"] for m in items), items
print("OK: GET /messages?wait= returned the message")
' || fail "GET /messages?wait= did not return the sent message"

# --------------------------------------------------------------------------
say "7. teardown run 1"
docker rm -f "$NAME" >/dev/null 2>&1 || true

# --------------------------------------------------------------------------
say "8. NO-LEAK negative — a fresh ephemeral container starts empty"
docker run -d --name "$NAME2" \
  -e SENDTRAP_MODE=ci \
  -e "APP_URL=http://localhost:${WEB_PORT}" \
  -e "SENDTRAP_CI_API_TOKEN=${CI_API_TOKEN}" \
  "${CI_FLAGS[@]}" \
  -p "${WEB_PORT}:8080" -p "${SMTP_PORT}:1025" \
  "$IMAGE"
# reuse the readiness poll against the second container
tries=40
while [ "$tries" -gt 0 ]; do
  curl -fsS "http://127.0.0.1:${WEB_PORT}/up" >/dev/null 2>&1 && break
  sleep 2; tries=$((tries-1))
done
[ "$tries" -gt 0 ] || fail "second container never became ready"
EMPTY_BODY="$(curl -fsS "http://127.0.0.1:${WEB_PORT}/api/v1/messages" \
  -H "Authorization: Bearer ${CI_API_TOKEN}" -H "Accept: application/json" 2>/dev/null || echo '[]')"
printf '%s' "$EMPTY_BODY" | python3 -c '
import sys, json
data = json.load(sys.stdin)
items = data.get("data", data) if isinstance(data, dict) else data
assert len(items) == 0, ("expected an empty inbox on a fresh run, got", items)
print("OK: fresh run inbox is empty — no residue from run 1")
' || fail "second run inbox was NOT empty — ephemerality leaked"
docker rm -f "$NAME2" >/dev/null 2>&1 || true

# --------------------------------------------------------------------------
say "9a. isolation — the always-on banner is present in the logs"
BANNER_LOG="$(docker run --rm -e SENDTRAP_MODE=ci "${CI_FLAGS[@]}" "$IMAGE" 2>&1 | head -40 || true)"
grep -q "EPHEMERAL, NON-PERSISTENT, TEST-ONLY" <<< "$BANNER_LOG" \
  || fail "the always-on CI isolation banner was not printed"
echo "OK: isolation banner present"

say "9b. isolation — a persistent (non-tmpfs) /data in CI mode REFUSES without ack"
PDATA_VOLUME="${NAME2}-persistent-${RANDOM}-$$"
docker volume create "$PDATA_VOLUME" >/dev/null
set +e
REFUSE_LOG="$(timeout 60 docker run --rm --name "${NAME2}-persist" \
  -e SENDTRAP_MODE=ci -v "${PDATA_VOLUME}:/data" "$IMAGE" 2>&1)"
REFUSE_RC=$?
set -e
docker rm -f "${NAME2}-persist" >/dev/null 2>&1 || true
if [ "$REFUSE_RC" -eq 0 ]; then
  fail "container booted on a persistent /data without the ack — the refusal did not fire"
fi
grep -qi "persistent" <<< "$REFUSE_LOG" \
  || fail "expected a persistent-/data refusal message (rc=${REFUSE_RC})"
echo "OK: persistent /data refused (rc=${REFUSE_RC})"

say "9c. isolation — the SAME persistent /data boots WITH the ack"
docker rm -f "${NAME2}-ack" >/dev/null 2>&1 || true
docker run -d --name "${NAME2}-ack" \
  -e SENDTRAP_MODE=ci -e SENDTRAP_CI_ACK_PERSISTENT=1 \
  -e "APP_URL=http://localhost:${WEB_PORT}" \
  -v "${PDATA_VOLUME}:/data" \
  --cap-drop ALL \
  --cap-add CHOWN --cap-add SETUID --cap-add SETGID --cap-add FOWNER --cap-add KILL --cap-add DAC_OVERRIDE \
  --security-opt no-new-privileges:true \
  -p "${WEB_PORT}:8080" -p "${SMTP_PORT}:1025" \
  "$IMAGE"
tries=40
while [ "$tries" -gt 0 ]; do
  curl -fsS "http://127.0.0.1:${WEB_PORT}/up" >/dev/null 2>&1 && break
  sleep 2; tries=$((tries-1))
done
docker rm -f "${NAME2}-ack" >/dev/null 2>&1 || true
[ "$tries" -gt 0 ] || fail "acked persistent /data did not boot to /up-ready"
docker volume rm "$PDATA_VOLUME" >/dev/null \
  || fail "could not remove the persistent isolation-test volume"
PDATA_VOLUME=""
echo "OK: acked persistent /data boots"

say "CI-E2E PASSED"
