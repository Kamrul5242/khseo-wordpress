# Quality status

What was actually verified for the current version. Every number below comes from
a real run. Anything not run is listed under **Not tested**. CI re-runs everything
on every push; see the [CI workflow](../.github/workflows/ci.yml) and the
**Actions** tab.

| Item | Value |
|---|---|
| Version | 0.2.0 (Phase 2 of 9: technical SEO audit, findings, score, governed fixes) |
| Verified on | 2026-10-07, local Docker run before release |
| WordPress | 7.1.2 (`wordpress:php8.2-apache`) with MariaDB 11 |

## Results

| Check | Command | Result |
|---|---|---|
| Syntax | `php -l` on every PHP file | 73 files, 0 failures (PHP 8.1.34 and 8.2.34) |
| Unit tests | `vendor/bin/phpunit` | **OK, 128 tests** on PHP 8.1.34 and 8.2.34 (CI also runs 8.3 and 8.4) |
| Coding standards | `vendor/bin/phpcs` (WPCS 3, PHPCompatibilityWP) | 0 errors, 0 warnings |
| Static analysis | `vendor/bin/phpstan analyse` (level 8) | No errors |
| Dependencies | `composer audit` | No security advisories |
| Integration | `tools/integration-test.sh` on a real WordPress | **139 PASS, 0 FAIL, 0 SKIP** |

## What the integration test covers (Phase 2 additions)

- **Real audit:** sitemap audit of fixture pages, run through REST over real HTTP (cookie +
  `wp_rest` nonce) inside the WordPress container. It finds:
  - `noindex` (SEO-INDEX-002);
  - invalid JSON-LD (SEO-SCHEMA-001);
  - a description with markup (SEO-META-006).

  robots.txt and the WordPress sitemap are read. Findings are de-duplicated (unique keys).
- **Score and coverage:**
  - numeric 0–100 with the "not a Google ranking score" disclaimer;
  - capped at 49 while the site discourages indexing (P0);
  - exact scope labels ("Homepage only (1 URL)", "N URL(s): N of M sitemap URL(s), including the homepage");
  - JS rendering and Google index status reported as NOT TESTED.
- **API edge:**
  - an unknown scope is rejected (400);
  - audits of other hosts and of `169.254.169.254` are refused (409);
  - `per_page` > 100 and severity `P9` are rejected (400);
  - an unknown issue id returns 404;
  - a SQL-injection string in the URL filter returns 0 rows;
  - pagination headers are present.
- **Permissions:**
  - anonymous → 401;
  - a cookie without the `wp_rest` nonce cannot start an audit;
  - an editor can read issues, but cannot start audits, preview, approve or apply fixes (403);
  - admin-post without a nonce → 403.
- **Governed fix:**
  - preview shows R3 and the before/after values;
  - apply without approval → 409, and nothing changes;
  - a forged change id → 409;
  - approve → apply → validated (`blog_public` = 1);
  - the approval is single-use;
  - rollback restores 0;
  - rollback after a later manual change → **409 BLOCKED**, and nothing is modified.
- **Re-audit:** a fixed finding becomes `resolved`; an ignored finding stays `ignored`.
- **XSS:** a `<script>` payload in a page's meta description is shown escaped on the issue screen and never output raw.
- **Locking:** a held lock means a step does nothing. Cancel stops a running audit.
- **Phase 1 checks still pass:**
  - SSRF refusals inside WordPress, plus the live DNS-pinning proof;
  - secrets never in REST or HTML;
  - real-HTTP nonce and capability attacks;
  - multisite (network activation, later-created sites, per-site settings and uninstall);
  - opted-in uninstall, which also drops the findings table.

## Mutation checks (do the tests fail when the code is wrong?)

Each protection was removed or broken on purpose. The named test failed, then passed again
once the fix was restored, and the restore was checked by file checksum.

| Planted defect | Caught by |
|---|---|
| RuleResult accepts NOT TESTED + VERIFIED (evidence spoofing) | unit `test_runtime_results_cannot_spoof_evidence` |
| Score counts negative passes (score manipulation) | unit `test_score_is_deterministic_documented_and_capped` |
| Auto-fix allowed above R1 (unsafe auto-fix) | unit `test_auto_fix_must_be_reversible_and_low_risk` |
| Redirect resolver drops credentials (silent target rewrite) | unit `test_redirect_with_credentials_is_never_silently_cleaned` |
| `FixService` ignores the ChangeGate decision | integration "apply WITHOUT approval refused" (got 200, value changed) |
| Encrypted secrets added to REST `/status` (secret leak) | integration "REST output never contains ciphertext", "no secrets in REST status" |
| URL filter interpolated into SQL (SQL injection) | integration "SQL-injection string in the URL filter is inert" (got 53 rows) |
| Earlier releases | CGNAT unblocked, public REST, editor privilege, logger sanitising, numeric hosts, migration v2, `DB_VERSION` drift, direct `wp_remote_get`, stray files: all still covered |

## Manual browser check

On a fresh WordPress 7.1.2, logged in as an administrator:
- **Before any audit**, the Overview shows NOT TESTED and no score.
- **Sitemap audit:** run from the Overview form, it completed with the dashboard script and returned **KHSEO Score 87/100**:
  - scope: "7 URL(s): 7 of 7 sitemap URL(s), including the homepage";
  - 48 rules evaluated, 13 not tested;
  - category table: Structured Data 0/100, because default WordPress has no JSON-LD.
- **Re-running the same audit** gave the same score.
- **Issues:** the P1 filter showed 8 HTTPS findings (the test site uses http).
- **Issue detail:** shows the evidence, source, priority/risk and recommendation, and states that no automatic fix is available.

## Not tested

- **Sites behind a CDN or WAF that blocks server-side requests.** Such pages are reported as "could not fetch" (UNKNOWN), never as passing.
- **Multisite networks with more than 100 sites.** The code fetches all sites; the test network had 3.
- **PHP 8.3 and 8.4 locally.** These run only in CI.
- **A host without the curl extension.** By design the fetcher refuses all requests there.
- **WP-Cron driving a full audit.** Integration drives audits through REST steps. Cron uses the same `step()` method and is only registered, not exercised end to end, because loopback cron is unreliable in Docker.
- **Google Rich Results Test, JavaScript rendering, Google index status, Search Console, AI providers and WooCommerce.** Not built (PLANNED).
