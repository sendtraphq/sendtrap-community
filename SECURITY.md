# Security Policy

## Reporting a vulnerability

**Please do not open public issues or pull requests for security
vulnerabilities.**

Report vulnerabilities privately by email to **security@sendtrap.dev**. Include a
description of the issue, a proof of concept if you have one, and the
affected version(s).

You will receive an acknowledgement within **3 business days**. We aim to
ship a fix (or a documented mitigation) within **90 days** of a confirmed
report, coordinating the disclosure date with you.

## Scope note: core vs Community

Much of Sendtrap Community's surface (SMTP ingestion, MIME parsing, the
inbox API, message checks) lives in the
[`sendtrap/core`](https://github.com/sendtraphq/sendtrap-core) package.
Vulnerabilities there are tracked upstream in that repository and fixed in a
new core release; Community then ships the fixed core tag as a patch
release. Report to the same address either way — we will route it.

## Supported versions

During the `0.x` series, only the **latest published `0.x` minor** receives
security fixes, released as a new patch version. There is no long-term
support branch before `1.0`.

| Version | Supported |
| --- | --- |
| latest 0.x minor | yes |
| older 0.x releases | no — upgrade to the latest minor |

## Coordinated disclosure

Security fixes ship publicly with synchronized releases of the Sendtrap
distributions affected. Advisories credit reporters unless they prefer
otherwise.
