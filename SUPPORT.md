# Support

## Supported versions

Sendtrap Community follows semantic versioning on a `0.x` line. Only the
**latest published `0.x` minor** is supported: bug fixes and security fixes
land there and are released as patch versions. Breaking changes may land in
minor releases until `1.0` (always with release notes). The policy tightens
at `1.0` (latest minor plus the immediately-previous minor for security
backports, window to be finalized at that milestone).

Security fixes that originate in the `sendtrap/core` package ship as a new
core tag plus a Community patch release that pins it — see
[SECURITY.md](SECURITY.md).

## Where to go

- **Bugs and feature requests** — open a GitHub issue with a minimal
  reproduction: PHP/Node versions, how you run the app, and for ingestion
  issues the raw SMTP conversation or message if you can share it.
- **Security vulnerabilities** — never a public issue; follow
  [SECURITY.md](SECURITY.md).
- **Setup and self-hosting questions** — check the
  [README](README.md) quick start and configuration sections first, then
  open a GitHub issue/discussion.

## Deployment status

Sendtrap Community ships two ways: as a clone-and-build Laravel application
(see the README quick start), and as a durable, self-hosted container image
(`docker compose up -d` — see [docker/README.md](docker/README.md)) that also
carries an ephemeral, API-first CI profile (`SENDTRAP_MODE=ci`) for running in
test pipelines.

## Scope boundary

This repository supports the **self-hosted Community** application only.
Support for any hosted/commercial Sendtrap offering is out of scope here
and is not handled through this issue tracker.

## No warranty

This software is provided "as is" under the [MIT license](LICENSE), without
warranty of any kind. Best-effort support only; there is no SLA on issue
responses.
