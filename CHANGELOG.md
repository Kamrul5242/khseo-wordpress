# Changelog

All notable changes to KHSEO WordPress. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/):

- **MAJOR**: breaking architecture, API or behaviour.
- **MINOR**: a new engine, major feature or provider.
- **PATCH**: bug fixes, security fixes, docs and tests.

`VERSION`, the plugin header, `KHSEO_VERSION`, `readme.txt` (Stable tag) and the
newest entry below must match; a unit test enforces this.

## [0.1.0] — 2026-10-07

Phase 1: foundation.

### Added
- Plugin bootstrap with a PHP 8.1 guard, a PSR-4 autoloader (no Composer needed at runtime) and a module kernel with a small DI container.
- Central rule registry (`config/rules.php`) with validated rule records. Auto-fixes must be reversible and at most R1.
- Shared enums: `Evidence` (VERIFIED … NOT TESTED), `Risk` (R0–R4), `Severity` (P0–P3).
- Security layer:
  - an SSRF URL guard covering private, loopback, link-local, CGNAT, reserved and IPv4-mapped/NAT64 IPv6 addresses, credentials in URLs and non-web ports;
  - libsodium secret store;
  - a redactor for API keys, OAuth tokens, bearer tokens, cookies and passwords;
  - 8 capabilities, with editors limited to view-only.
- Settings with a strict sanitizer that runs on every write path (wp-admin, REST, WP-CLI). Export never includes secrets.
- Admin shell:
  - Overview with real site checks (search-engine visibility, HTTPS) and honest UNKNOWN / NOT TESTED states;
  - Settings screen;
  - encrypted AI-key form that shows only a mask.
- `GET /khseo/v1/status`, protected by `view_khseo`.
- Structured, redacted, size-capped logger.
- Opt-in uninstall cleanup that is multisite-aware. Data is kept by default.
- Tests:
  - 53 unit tests;
  - 30 integration checks against a real WordPress in Docker;
  - PHPCS (WordPress Coding Standards) and PHPStan level 8.
- CI with actions pinned to commit SHAs.
