#!/usr/bin/env bash
#
# Sendtrap Community — generic docker-run CI example (Plan 06 Phase 8b, §6.3).
#
# The ONLY example that hand-writes container flags, so it carries the full
# canonical §5.0 set VERBATIM: the COMPLETE tmpfs writable surface (omit any
# under --read-only and boot hits EROFS at config:cache) AND caps dropped-to-
# minimum (drop ALL + add back CHOWN/SETUID/SETGID/FOWNER/KILL — dropping ALL
# and adding none never boots; DAC_OVERRIDE is cross-boot-only and omitted).
#
# Flow: start ephemeral (no -v, tmpfs /data, --rm) -> wait for /up AND the
# container's own reported health (sendtrap-healthcheck: /up 200 AND an SMTP
# 220 banner on :1025 — /up alone only proves nginx+php-fpm are serving, not
# that the separate smtp longrun is accepting yet) -> SMTP send to
# localhost:1025 (AUTH ci / ci-smtp-password) -> assert via POST /api/v1/assert
# (Bearer ci-api-token) -> teardown (--rm + tmpfs /data => zero residue).
#
# Requires: docker + python3 (the reference SMTP client). Runs anywhere with a
# Docker host — any runner, or locally.
set -euo pipefail

IMAGE="${SENDTRAP_IMAGE:-ghcr.io/sendtraphq/sendtrap-community:latest}"
NAME="sendtrap-ci"
SUBJECT="Welcome to the CI run"

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

# 1. Start ephemeral — canonical §5.0 flag set VERBATIM.
docker run -d --name "$NAME" --rm \
  -e SENDTRAP_MODE=ci -e APP_URL=http://localhost:8080 \
  -e SENDTRAP_CI_SMTP_USERNAME=ci -e SENDTRAP_CI_SMTP_PASSWORD=ci-smtp-password \
  -e SENDTRAP_CI_API_TOKEN=ci-api-token \
  --tmpfs /data --tmpfs /tmp --tmpfs /run:rw,exec,nosuid,nodev,mode=755 \
  --tmpfs /app/bootstrap/cache --tmpfs /app/storage/framework --tmpfs /app/storage/logs \
  --read-only \
  --cap-drop ALL \
  --cap-add CHOWN --cap-add SETUID --cap-add SETGID --cap-add FOWNER --cap-add KILL \
  --security-opt no-new-privileges:true \
  -p 8080:8080 -p 1025:1025 \
  "$IMAGE"

# 2. Wait ready — poll /up AND the container's own reported health. /up
#    implies migrate+install+ci-seed done (structural, §4.1) for the WEB leg,
#    but the SMTP longrun is a separate process behind the same oneshot; a
#    strict reading leaves a race where :1025 isn't accepting yet. The image's
#    baked-in HEALTHCHECK (`sendtrap-healthcheck`) asserts BOTH /up 200 AND an
#    SMTP 220 banner, so gating on docker's reported health closes that race
#    (mirrors scripts/ci-profile-e2e.sh's wait_ready).
ready=""
for _ in $(seq 1 40); do
  if curl -fsS http://localhost:8080/up >/dev/null 2>&1; then
    status="$(docker inspect -f '{{.State.Health.Status}}' "$NAME" 2>/dev/null || echo none)"
    case "$status" in
      healthy|none) ready=1; break ;;
      unhealthy) echo "sendtrap reported unhealthy" >&2; docker logs "$NAME" >&2 || true; exit 1 ;;
    esac
  fi
  sleep 2
done
[ -n "$ready" ] || { echo "timed out waiting for /up + health" >&2; docker logs "$NAME" >&2 || true; exit 1; }

# 3. App under test sends SMTP to localhost:1025 (AUTH ci / ci-smtp-password).
#    Reference client (STARTTLS optional, plaintext AUTH; unverified TLS ctx) —
#    replace with your own mailer if you have one.
SMTP_HOST=127.0.0.1 SMTP_PORT=1025 SMTP_USER=ci SMTP_PASS=ci-smtp-password SUBJECT="$SUBJECT" \
python3 - <<'PY'
import os, smtplib, ssl
from email.message import EmailMessage
msg = EmailMessage()
msg["From"] = "sender@example.com"
msg["To"] = "catch@inbox.test"
msg["Subject"] = os.environ["SUBJECT"]
msg.set_content("hello from the sendtrap CI example")
ctx = ssl._create_unverified_context()
with smtplib.SMTP(os.environ["SMTP_HOST"], int(os.environ["SMTP_PORT"]), timeout=20) as s:
    s.ehlo()
    if s.has_extn("starttls"):
        s.starttls(context=ctx); s.ehlo()
    s.login(os.environ["SMTP_USER"], os.environ["SMTP_PASS"])
    s.send_message(msg)
print("sent:", os.environ["SUBJECT"])
PY

# 4. Assert via the current API (POST /assert busy-waits up to timeout).
curl -fsS -X POST http://localhost:8080/api/v1/assert \
  -H "Authorization: Bearer ci-api-token" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"subject_contains":"Welcome","timeout":15}' | grep '"matched":true' >/dev/null

echo "OK: email asserted via /api/v1/assert"

# 5. Teardown — trap runs `docker rm -f` (--rm + tmpfs /data => zero residue).
