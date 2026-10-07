# Security Policy

## Reporting a vulnerability

Please **do not** open a public issue. Report privately through GitHub:
<https://github.com/Kamrul5242/khseo-wordpress/security/advisories/new>

Include the affected version, steps to reproduce and the impact. You will get
an acknowledgement, and a fix is released as a PATCH version with credit
unless you prefer otherwise.

## Supported versions

Only the latest release receives security fixes while the project is pre-1.0.

## Security design (summary)

Full details are in [docs/ARCHITECTURE.md §5](docs/ARCHITECTURE.md#5-security-model).

- Every admin action checks a capability and a nonce. Every REST route has a
  real `permission_callback`.
- Output is escaped at render time. SQL goes only through `$wpdb->prepare()`.
- Outbound URLs pass an SSRF guard. Private and reserved ranges are blocked,
  every resolved address is checked, and redirects are re-validated.
- Secrets are encrypted at rest with libsodium and never rendered, logged,
  exported or sent to JavaScript.
- Logs are redacted and size-capped.
- Content from pages, comments, imports or the web is treated as untrusted
  data, never as instructions.
