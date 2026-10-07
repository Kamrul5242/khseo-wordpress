# Quality status

What was actually verified for the current version. Every number below comes from
a real run. Anything not run is listed under **Not tested**. CI re-runs everything
on every push; see the [CI workflow](../.github/workflows/ci.yml) and the
**Actions** tab for the latest result.

| Item | Value |
|---|---|
| Version | 0.1.1 (Phase 1 of 9: foundation) |
| Verified on | 2026-10-07, local Docker run before release |
| WordPress | 7.1.2 (Docker image `wordpress:php8.2-apache`) with MariaDB 11 |

## Results

| Check | Command | Result |
|---|---|---|
| Syntax | `php -l` on every PHP file | 45 files, 0 failures (PHP 8.1.34 and 8.2.34) |
| Unit tests | `vendor/bin/phpunit` | **OK, 88 tests** on PHP 8.1.34 and 8.2.34 (CI also runs 8.3 and 8.4) |
| Coding standards | `vendor/bin/phpcs` (WordPress Coding Standards 3, PHPCompatibilityWP) | 0 errors, 0 warnings |
| Static analysis | `vendor/bin/phpstan analyse` (level 8, PHPStan 2 + WordPress stubs) | No errors |
| Dependencies | `composer audit` | No security advisories |
| Integration | `tools/integration-test.sh` on a real WordPress | **76 PASS, 0 FAIL, 0 SKIP** |

## What the integration test covers

- Activation, migration version, safe defaults, and capabilities per role (editors are view-only).
- REST `/status`:
  - anonymous gets 401, editor gets 200, admin gets 200;
  - honest AI, Search Console and encryption states.
- Real site checks reacting to real settings (search visibility, HTTPS).
- Secrets:
  - stored encrypted with real salts;
  - tampered ciphertext returns NULL instead of crashing;
  - never in REST output or HTML.
- The settings sanitizer runs on WP-CLI writes, not only in wp-admin; a corrupt option value is normalized.
- Logger: entries are capped, single-line and redacted.
- Safe Fetcher refuses these targets inside WordPress:
  - the internal Docker hosts (`wordpress`, `db`);
  - `127.0.0.1`, `localhost:8089`, `169.254.169.254`, `2130706433` and `[::1]`.
- **DNS-pinning proof**: a host name that does not exist in DNS is mapped by the guard to a real public IP, and the request connects. The same URL fails without pinning (control).
- **Real HTTP** with curl and cookie login, against admin-post:
  - anonymous: blocked;
  - editor: 403;
  - admin without a nonce: 403;
  - admin with a forged nonce: 403;
  - admin with a valid nonce: 302, and the key is saved encrypted and never echoed;
  - the editor gets 403 on the Settings screen and 200 on Overview.
- No KHSEO notices in `debug.log`.
- Deactivation keeps data. Default uninstall keeps data. Opted-in uninstall removes settings, secrets and capabilities.
- **Multisite**:
  - network activation;
  - a site created *after* activation gets capabilities;
  - per-site settings stay isolated;
  - opted-in uninstall removes only that site's rows, checked in the database tables directly.

## Mutation checks (do the tests fail when the code is wrong?)

Each fix below was removed or broken on purpose. The named test failed, then
passed again once the fix was restored.

| Planted defect | Caught by |
|---|---|
| CGNAT range unblocked in `UrlGuard` | unit `test_blocked_ip_ranges` |
| REST `/status` made public | integration "anonymous -> 401" |
| Editors given `manage_khseo_settings` | integration capability checks |
| Logger sanitising removed (forged newline stored) | unit `test_log_message_is_one_line…` |
| Old `UrlGuard` without numeric-host rule | unit `test_non_canonical_and_odd_hosts…` |
| Migration v2 removed (multisite capabilities) | integration "site created AFTER activation gets caps" |
| `DB_VERSION` out of sync with migration steps | unit `test_db_version_matches_the_highest_migration_step` |
| Direct `wp_remote_get()` outside `src/Http` | unit `test_only_the_safe_fetcher_makes_outbound_requests` |
| Stray hook-output file in the repo | unit `test_no_hidden_characters_or_stray_tool_output_in_source` |

## Manual browser check

On a fresh site (WordPress 7.1.2), logged in as an administrator:
- **Settings:** saving shows the "Settings saved." notice.
- **AI key:** a fake test key was saved through the real form and shown only as a mask.
- **Overview:** shows version 0.1.1 and says the AI features are PLANNED, not "configured".

## Not tested

- **Multisite networks with more than 100 sites.** The fix (`get_sites()` with `'number' => 0`) follows the WordPress API documentation; the test network had 3 sites.
- **PHP 8.3 and 8.4 locally.** These run only in CI.
- **A host without the curl extension.** By design the fetcher refuses all requests there; no test environment lacks curl.
- **Real AI providers, Google APIs and WooCommerce.** These features are not built yet (PLANNED).
