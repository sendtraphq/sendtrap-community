# Sendtrap Community

Sendtrap Community is a **self-hosted email sandbox**: point your
application's outgoing mail at it and every message is captured, parsed, and
browsable — nothing is ever delivered to a real mailbox. It runs as a single
workspace on your own machine or network, needs **no external account and no
internet access**, and is built on the MIT-licensed
[`sendtrap/core`](https://github.com/sendtraphq/sendtrap-core) package
(`^0.1`).

## What you get

- **An SMTP server that catches instead of sends** — `php artisan
  mail:smtp-server` accepts SMTP (including STARTTLS) on port `1025` by
  default and files every message into an inbox.
- **Projects and inboxes** — organize captured mail per app/environment;
  each inbox has its own SMTP credentials and API token.
- **A message browser** — HTML/text/raw views, MIME structure, attachments,
  envelope and BCC capture, merge-tag detection.
- **Message checks** — deliverability lint checks and HTML client
  compatibility scored against the caniemail dataset, fully offline from a
  checked-in snapshot.
- **A bearer-token REST API per inbox** — list/filter messages, fetch
  detail/raw/HTML, download attachments, and drive your test suites with
  `/expect`: one deterministic request that waits for mail, matches, asserts
  and — via named extractors, also available on
  `POST /messages/{id}/extract` — hands back verification codes, magic
  links, addresses and attachment metadata with no email parsing in the
  test. Documented by an OpenAPI 3.1 contract shipped with the app:
  browse it interactively at `/docs/api/reference` on your instance, or grab
  `/docs/api/openapi.yaml` (Postman collection alongside) to import into
  Postman, Bruno or Insomnia.
- **Public share links, webhooks and auto-forwarding** for individual
  messages.
- **A simple role model** — every user is an **owner** (manage users,
  settings, everything), **member** (manage projects/inboxes and mail), or
  **viewer** (read-only, no SMTP/API credentials visible).

## Quick start (Docker)

The fastest way to run Community is the official container image: one durable
container carrying the web UI/API (`:8080`), the SMTP ingestion daemon
(`:1025`), a queue worker and the scheduler — SQLite and all state on a single
volume, nothing else to install.

```bash
ADMIN_PASSWORD="$(openssl rand -base64 15)" && echo "admin password: ${ADMIN_PASSWORD}"
docker run -d --name sendtrap \
  -p 80:8080 -p 1025:1025 \
  -e APP_URL=http://localhost \
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
  -e APP_URL=http://localhost `
  -e SENDTRAP_ADMIN_NAME="Admin" `
  -e SENDTRAP_ADMIN_EMAIL="admin@example.com" `
  -e SENDTRAP_ADMIN_PASSWORD=$ADMIN_PASSWORD `
  -v sendtrap-data:/data `
  ghcr.io/sendtraphq/sendtrap-community:latest
```

The `SENDTRAP_ADMIN_*` values are read only on the first boot of a fresh
volume — to re-create the admin with a new password, wipe the state first:
`docker rm -f sendtrap && docker volume rm sendtrap-data`, then run again.

Open `APP_URL`, log in as the admin user, and see a first message straight
away — no application wiring needed:

```bash
docker exec sendtrap php artisan sendtrap:send-test
```

That seeds a rich example message (HTML + text, attachment, inline image,
BCC, merge tags) into the starter inbox. When you're ready for real mail,
point your application's mailer at `smtp://localhost:1025` with the inbox
credentials shown in the UI. The
repo also ships a reference `docker-compose.yml` (hardened: read-only rootfs,
dropped capabilities) and an **ephemeral CI profile** (`SENDTRAP_MODE=ci` —
zero-config, deterministic credentials, boots seeded in seconds for test
jobs). See [docker/README.md](docker/README.md) for the full runbook: build,
run, backup/restore, upgrade, external MySQL/Postgres/Redis/S3 backends, and
ready-to-copy CI job examples.

## Running from source

### Requirements

- PHP 8.3+ with the `sqlite3` and `openssl` extensions (sqlite is the default
  database; openssl backs the SMTP server's STARTTLS)
- Composer
- Node.js 20+ and npm (to build the front-end assets)

### Quick start

```bash
git clone https://github.com/sendtraphq/sendtrap-community.git
cd sendtrap-community

composer install
cp .env.example .env
php artisan key:generate

# Runs migrations, creates the single workspace and the first owner user
# (prompts for name/email/password; flags available for non-interactive use)
php artisan sendtrap:install

npm install
npm run build

php artisan serve            # web UI on http://localhost:8000
php artisan mail:smtp-server # SMTP ingestion on port 1025
```

Then seed a first message — no application wiring needed:

```bash
php artisan sendtrap:send-test            # inject straight into the pipeline
php artisan sendtrap:send-test --via-smtp # or prove the full SMTP wire
```

When you're ready for real mail, configure your application to send through
`smtp://localhost:1025` using the inbox credentials shown in the UI, and
watch messages appear.

For production-style deployments set `APP_ENV=production` and
`APP_DEBUG=false` in `.env`, serve `public/` behind a real web server, and run
the SMTP server under a process supervisor. Run the scheduler too
(`php artisan schedule:work`, or a `schedule:run` cron entry) — it drives daily
message pruning. The default `QUEUE_CONNECTION=sync` parses captured mail inline
in the SMTP daemon, so no separate worker is required; if you expect high ingest
volume, switch to a real queue (`database`/`redis`) and add a
`php artisan queue:work` process so ingestion doesn't block the SMTP loop.

## Configuration

Everything is driven by `.env` (see `.env.example`, which documents each
block):

- `SENDTRAP_SMTP_BIND` / `SENDTRAP_SMTP_PORT` — where the ingestion SMTP
  server listens (defaults `0.0.0.0:1025`).
- `SENDTRAP_*` instance limits — optional per-instance caps (projects,
  inboxes, users, message retention, sizes…). Unset means unlimited.
- Optional S3-compatible storage for raw messages/attachments, and an
  optional external spam-check service — both off by default; Community is
  offline-first.

## Updating

```bash
git pull
composer install
php artisan migrate
npm install && npm run build
```

`sendtrap/core` is pinned with a caret constraint on the current minor
(see `composer.json`) and updates through `composer update sendtrap/core`.
Breaking changes may land in `0.x` minor releases (semver 0.x semantics) —
read the release notes before a minor bump.

## Developing core and Community together

Community consumes `sendtrap/core` as a released tag. If you are working on
core itself and want your local checkout picked up instantly, use a
**gitignored** Composer override instead of editing `composer.json`:

1. Copy `composer.json` to `composer.local.json` and add a path repository
   entry pointing at your core checkout, first in the list:

   ```json
   "repositories": [
       { "type": "path", "url": "../sendtrap-core", "options": { "symlink": true } }
   ]
   ```

2. In `composer.local.json`, require the core branch you are working on,
   aliased so it satisfies the `^0.1` constraint:

   ```json
   "require": { "sendtrap/core": "dev-main as 0.1.x-dev" }
   ```

3. Resolve with the override active:

   ```bash
   cp composer.lock composer.local.lock
   COMPOSER=composer.local.json composer update sendtrap/core
   ```

`composer.local.json` / `composer.local.lock` are gitignored — never commit
them (they contain machine-local paths). Drop the `COMPOSER=` prefix (plain
`composer install`) to return to the released tag.

## Contributing, security, support

- [CONTRIBUTING.md](CONTRIBUTING.md) — test commands, DCO sign-off, how PRs
  land pre-1.0.
- [SECURITY.md](SECURITY.md) — private vulnerability disclosure. Never open
  a public issue for a security problem.
- [SUPPORT.md](SUPPORT.md) — supported versions, where to file what.
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) — community standards.

## License

Sendtrap Community is open-source software licensed under the
[MIT license](LICENSE). The "Sendtrap" name and logo are trademarks reserved
by the project; the code license does not grant trademark rights — see
[TRADEMARK.md](TRADEMARK.md). Third-party data attribution (the bundled
caniemail dataset) is in [NOTICE](NOTICE).
