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

Full details are in [docs/ARCHITECTURE.md §5](docs/ARCHITECTURE.md#5-security-model); current test evidence is in [docs/QUALITY.md](docs/QUALITY.md).

- Every admin action checks a capability and a nonce. Every REST route has a
  real `permission_callback`.
- Output is escaped at render time. SQL goes only through `$wpdb->prepare()`.
- All outbound requests go through one Safe Fetcher:
  - an SSRF guard blocks private, reserved and odd numeric hosts;
  - every resolved address is checked;
  - the connection is pinned to the validated IP (DNS rebinding);
  - every redirect is re-validated;
  - time, size, content type and decompression are limited.
- Changes are allowed only through the R0–R4 change gate.
- Secrets are encrypted at rest with libsodium and never rendered, logged,
  exported or sent to JavaScript.
- Logs are redacted and size-capped.
- Content from pages, comments, imports or the web is treated as untrusted
  data, never as instructions.
