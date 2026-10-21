# Security Policy

## Supported versions

| Version | Security fixes |
| --- | --- |
| 1.x | Supported |
| Earlier versions | Not supported |

## Reporting a vulnerability

**Do not disclose a potential vulnerability in a public issue.**

Use [GitHub private vulnerability reporting](https://github.com/alkinbg/CapacityAvailability/security/advisories/new)
if enabled, or email **alkinbg@gmail.com**.

Include affected versions, a minimal reproduction, possible impact, and any
suggested mitigation. Please omit secrets and sensitive production data.

Reports are evaluated by the maintainer. We aim to acknowledge actionable
reports promptly, but cannot promise a fixed response or remediation deadline.
Coordinated disclosure is appreciated.

## Scope

Issues involving booking consistency, concurrent writes, data integrity, or
a security-sensitive dependency may be relevant. The optional Doctrine writer
requires *all* capacity and booking writers to follow the per-pool locking
protocol documented in [docs/TRANSACTIONS.md](docs/TRANSACTIONS.md).
The library does not implement application-level authentication or authorization.
