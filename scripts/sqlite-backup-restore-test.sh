#!/usr/bin/env bash
#
# Sendtrap Community — sqlite3 CLI backup/restore smoke test (gate-review
# batch-2 #5). docker/README.md's "Backup" section documents:
#
#   docker compose exec sendtrap sqlite3 /data/database.sqlite \
#     ".backup /data/backup.sqlite"
#
# which requires the /usr/bin/sqlite3 CLI in the runtime image (the image
# previously carried only the PHP `sqlite3` EXTENSION, not the CLI binary —
# see the Dockerfile runtime-stage `apk add ... sqlite ...` line this test's
# companion fix adds). This script proves the DOCUMENTED COMMAND ITSELF is
# correct — that a `.backup` snapshot is a faithful, independently-openable
# copy of the source database, and that a "restore" (making the snapshot the
# live file again) round-trips the data — without needing Docker or the
# Sendtrap application: it runs directly against the sqlite3 CLI on any host
# that has one (the same binary the container now ships), so it is exercised
# in ordinary CI (no Docker host required) as well as under the Docker-backed
# container e2e (D-20).
#
# Usage: scripts/sqlite-backup-restore-test.sh
# Requires: sqlite3 (the CLI — `command -v sqlite3`), a POSIX shell.
set -euo pipefail

say() { printf '\n=== %s ===\n' "$*"; }
fail() { printf '\nSQLITE BACKUP/RESTORE TEST FAILED: %s\n' "$*" >&2; exit 1; }

command -v sqlite3 >/dev/null 2>&1 || fail "sqlite3 CLI not found on PATH (this is exactly what gate-review #5 fixes in the runtime image)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

DB="$WORK/database.sqlite"
BACKUP="$WORK/backup.sqlite"
RESTORED="$WORK/restored.sqlite"

# --------------------------------------------------------------------------
say "1. seed a representative source database"
sqlite3 "$DB" <<'SQL'
CREATE TABLE messages (id INTEGER PRIMARY KEY, subject TEXT NOT NULL);
INSERT INTO messages (subject) VALUES ('pre-backup message one');
INSERT INTO messages (subject) VALUES ('pre-backup message two');
SQL
[ -f "$DB" ] || fail "seed database was not created"
SEED_COUNT="$(sqlite3 "$DB" 'SELECT COUNT(*) FROM messages;')"
[ "$SEED_COUNT" = "2" ] || fail "seed produced ${SEED_COUNT} rows, expected 2"
echo "OK: seeded ${SEED_COUNT} rows"

# --------------------------------------------------------------------------
# The EXACT command docker/README.md documents (Backup section / this file's
# header), just against a plain path instead of a running container's
# `docker compose exec`.
say "2. .backup (the documented consistent-snapshot command)"
sqlite3 "$DB" ".backup '${BACKUP}'"
[ -f "$BACKUP" ] || fail ".backup did not produce ${BACKUP}"
echo "OK: .backup produced ${BACKUP}"

# --------------------------------------------------------------------------
say "3. assert the backup is a faithful, independently-openable snapshot"
BACKUP_COUNT="$(sqlite3 "$BACKUP" 'SELECT COUNT(*) FROM messages;')"
[ "$BACKUP_COUNT" = "$SEED_COUNT" ] || fail "backup has ${BACKUP_COUNT} rows, expected ${SEED_COUNT}"
BACKUP_SUBJECTS="$(sqlite3 "$BACKUP" 'SELECT subject FROM messages ORDER BY id;')"
SEED_SUBJECTS="$(sqlite3 "$DB" 'SELECT subject FROM messages ORDER BY id;')"
[ "$BACKUP_SUBJECTS" = "$SEED_SUBJECTS" ] || fail "backup subjects differ from source"
echo "OK: backup row count and content match the source exactly"

# --------------------------------------------------------------------------
say "4. mutate the source AFTER the backup (proves the backup is a snapshot, not a live link)"
sqlite3 "$DB" "INSERT INTO messages (subject) VALUES ('post-backup message three');"
[ "$(sqlite3 "$DB" 'SELECT COUNT(*) FROM messages;')" = "3" ] || fail "post-backup insert into source failed"
[ "$(sqlite3 "$BACKUP" 'SELECT COUNT(*) FROM messages;')" = "$SEED_COUNT" ] \
  || fail "backup row count changed after mutating the source -- .backup is not an independent snapshot"
echo "OK: source mutation after backup did not affect the snapshot (independent copy confirmed)"

# --------------------------------------------------------------------------
# docker/README.md's "Restore" section: recreate /data from the snapshot,
# i.e. the backup file becomes the live database again.
say "5. restore — the backup becomes the live database"
cp "$BACKUP" "$RESTORED"
RESTORED_COUNT="$(sqlite3 "$RESTORED" 'SELECT COUNT(*) FROM messages;')"
[ "$RESTORED_COUNT" = "$SEED_COUNT" ] || fail "restored database has ${RESTORED_COUNT} rows, expected ${SEED_COUNT}"
RESTORED_SUBJECTS="$(sqlite3 "$RESTORED" 'SELECT subject FROM messages ORDER BY id;')"
[ "$RESTORED_SUBJECTS" = "$SEED_SUBJECTS" ] || fail "restored subjects differ from the pre-backup source"
# The post-backup row must NOT be present -- restoring from an
# earlier snapshot is expected to lose anything written after it.
if sqlite3 "$RESTORED" "SELECT 1 FROM messages WHERE subject = 'post-backup message three';" | grep -q 1; then
  fail "restored database unexpectedly contains a message written AFTER the backup was taken"
fi
echo "OK: restore recovers exactly the pre-backup data, and only the pre-backup data"

# --------------------------------------------------------------------------
# Integrity check both files independently -- a sqlite3-CLI-native
# correctness check that catches a truncated/corrupt .backup output.
say "6. integrity check both the source and the backup"
[ "$(sqlite3 "$DB" 'PRAGMA integrity_check;')" = "ok" ] || fail "source database failed PRAGMA integrity_check"
[ "$(sqlite3 "$BACKUP" 'PRAGMA integrity_check;')" = "ok" ] || fail "backup database failed PRAGMA integrity_check"
echo "OK: PRAGMA integrity_check passes on both the source and the backup"

say "SQLITE BACKUP/RESTORE TEST PASSED"
