# Changelog

All notable changes to KHSEO WordPress. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/):

- **MAJOR**: breaking architecture, API or behaviour.
- **MINOR**: a new engine, major feature or provider.
- **PATCH**: bug fixes, security fixes, docs and tests.

`VERSION`, the plugin header, `KHSEO_VERSION`, `readme.txt` (Stable tag) and the
newest entry below must match; a unit test enforces this.

## [0.1.1] — 2026-10-07

Phase 1 hardening, from a full audit of 0.1.0. Each fix has a regression test.

### Security
- **Safe Fetcher** (`src/Http`): the only component allowed to make outbound requests.
  - Pins the connection to the validated IP with `CURLOPT_RESOLVE` (DNS-rebinding defence) and refuses the request if pinning cannot be applied.
  - Re-validates every redirect hop and caps redirects.
  - Enforces connection and total timeouts, a raw-size cap, a decoded-size cap (gzip-bomb guard) and a content-type allow-list.
  - A unit test fails the build if code outside `src/Http` calls `wp_remote_*`, curl, `fsockopen` or remote `file_get_contents`.
- `UrlGuard` refuses decimal, hex, octal and shortened IPv4 hosts (`2130706433`, `0x7f.1`, `127.1`). curl reads these as loopback. It also treats a trailing dot (`localhost.`) as the same name and allows only ASCII host names.
- `SecretStore`:
  - never builds a key from WordPress's placeholder salts ("put your unique phrase here"), which would make the key public;
  - prefers `KHSEO_SECRET_KEY`, then the real wp-config salts, then WordPress's database salt;
  - no longer causes a fatal error when no key material exists: key saving is disabled and the UI says why;
  - rejects non-string, oversized or malformed ciphertext without throwing.
- `Logger` stores single-line, tag-free messages capped at 500 characters and context capped at 2 KB. Previously a 1 MB entry with a forged second line was stored as-is.

### Fixed
- Multisite:
  - network activation and uninstall covered only the first 100 sites (`get_sites()` default);
  - sites created after network activation got no KHSEO capabilities. Migration v2 now grants the defaults on each site's first boot.
- REST `/status` said AI was "configured" when only a key was saved. It now reports `state` (`not_configured` / `key_saved`) and `features: PLANNED`; the `configured` field is kept for compatibility. It also reports which encryption key source is in use.

### Added
- `Governance\ChangeGate`: the single R0–R4 decision point that future engines must use before applying any change.
- Tests:
  - 88 unit tests (was 53);
  - 76 real-WordPress integration checks (was 30), now including:
    - real-HTTP login, nonce and capability attacks on admin-post;
    - Safe Fetcher refusals inside WordPress;
    - a live DNS-pinning proof;
    - multisite activation, per-site isolation and uninstall.
- `docs/QUALITY.md`: the current verified quality status.

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
