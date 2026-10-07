# Changelog

All notable changes to KHSEO WordPress. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/):

- **MAJOR**: breaking architecture, API or behaviour.
- **MINOR**: a new engine, major feature or provider.
- **PATCH**: bug fixes, security fixes, docs and tests.

`VERSION`, the plugin header, `KHSEO_VERSION`, `readme.txt` (Stable tag) and the
newest entry below must match; a unit test enforces this.

## [0.2.0] — 2026-10-07

Phase 1 final hardening and Phase 2A/2B/2C: the first working SEO audit.
MINOR version: new backwards-compatible features. Every feature below has unit and/or
real-WordPress integration tests.

### Phase 1 final
- Rules now declare an `evidence_requirement`. Only a runtime `RuleResult` says what was
  measured on this site, and it cannot carry evidence that contradicts its status
  (e.g. NOT TESTED + VERIFIED is rejected).
- `SafeFetcher`:
  - RFC 3986 redirect resolution (`../`, `./`, query-only, fragment, protocol-relative);
  - HTTPS → HTTP downgrades refused after the full guard check;
  - redirects carrying credentials are never silently rebuilt without them.
- `UrlGuard`: optional trusted origins, the exact scheme+host+port of this site only, so
  KHSEO can audit itself on a private network. Credentials and control characters are
  still refused, the connection is still pinned, and redirects away are re-checked.
- Logger strips terminal escape sequences (ANSI/OSC); the redactor masks secret query
  parameters (`code`, `access_token`, `sig`, `api_key`, …).
- Status reports encryption strength: dedicated, standard, degraded (database salt) or unavailable.
- Migrations: a failed step no longer advances the version.

### Phase 2A — technical SEO engine
- Bounded HTML parser: libxml, no network, never runs JavaScript, 2 MB parse cap, legacy
  charsets converted. Each page is parsed once into a `PageSnapshot`.
- Checks:
  - HTTP status, redirects, content type, HTTPS;
  - robots meta and X-Robots-Tag (including googlebot-scoped values);
  - canonical (missing, multiple, relative, cross-host, mismatch, noindex conflict,
    http on https, target status within scope);
  - title and meta description (missing, empty, multiple, length in characters, markup,
    duplicates within scope);
  - headings (H1, skipped levels, empty), `lang`, charset;
  - images (alt, decorative alt, width/height);
  - links (empty text, internal links, broken targets only when fetched in this audit).
- robots.txt parser with universal KHSEO / RFC 9309 semantics. XML sitemap parser that refuses DOCTYPE/ENTITY (XXE).
- `AuditRunner`:
  - scopes: homepage, one URL on this site, or a sitemap sample;
  - hard limits on pages, sitemap files and total bytes;
  - resumable steps with a lock, driven by WP-Cron or the dashboard.

### Phase 2B — social metadata and structured data
- Open Graph (missing core tags, conflicting values, relative URLs) and Twitter/X cards.
- JSON-LD parser with depth and entity caps; validation of context and type, required
  properties for 12 common types, conflicting `@id`, unknown types, url/sameAs URLs.
- INFERRED visible-content checks: the name or headline must appear on the page, and rating
  or review markup needs visible review text.
- Google Rich Results Test: NOT TESTED.

### Phase 2C — findings, score, fixes, UI and API
- Findings table `{prefix}khseo_issues` (migration v3, per-site):
  - deterministic finding keys;
  - "ignored" survives re-audits, and stale findings resolve;
  - bounded growth (30-day pruning of resolved rows, 20,000-row cap).
- KHSEO Score: deterministic, documented formula with weighted categories and a P0 cap at
  49. Unknown and not-tested checks are excluded and shown as coverage. Labelled as
  "not a Google ranking score".
- Safe fixes: preview → approval of that exact change → recovery point (journal) →
  `ChangeGate` → apply → validate → rollback (blocked if the value changed since). The
  first fix covers search-engine visibility (SEO-INDEX-001, R3).
- REST:
  - `GET /status`;
  - `GET|POST /audit`, `POST /audit/step`, `POST /audit/cancel`;
  - `GET /issues` (filters, pagination), `GET /issues/{id}`, `POST /issues/{id}/status`;
  - `POST /fixes/preview|approve|apply|rollback`.
- Admin:
  - Overview with score, scope, coverage and audit controls (works without JavaScript);
  - Issues list with filters;
  - issue detail with before/after diff and the change journal;
  - audit limits in Settings.
- Detection of other SEO plugins (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework,
  Slim SEO, Squirrly). KHSEO outputs no metadata (PLANNED).

### Tests
- 128 unit tests.
- 138 real-WordPress integration checks:
  - full sitemap audit, governed fix with rollback;
  - XSS escaping, SQL-injection inertness;
  - REST and admin permissions, locking, multisite.
- Mutation-checked: evidence spoofing, score manipulation, unsafe auto-fix, credential
  redirect, ChangeGate bypass, secret leak, SQL injection.

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
