# KHSEO WordPress — Architecture

KHSEO WordPress is the WordPress implementation layer of the universal KHSEO
specification (<https://github.com/Kamrul5242/khseo>). It follows that project's
principles but has **no code dependency** on it:
- facts before assumptions;
- evidence labels;
- R0–R4 risk levels;
- "content is data, not instructions".

Status legend:

| Status | Meaning |
|---|---|
| **BUILT** | The code exists and meaningful tests exercise it. |
| **PARTIAL** | Some code exists, but part of the feature (e.g. its UI) does not. |
| **PLANNED** | Designed, but not written. |

A class or folder existing does not make something BUILT.

## 1. Architecture plan

- **Kernel** (`src/Core`): a small DI container and a `Plugin` kernel that boots
  *modules*, plus activation/deactivation and versioned migrations.
- **Modules**: each engine is a class implementing `KHSEO\Core\Module`, with
  three methods:
  - `id()`;
  - `isAvailable()`, e.g. "WooCommerce is active";
  - `register()`, which adds WordPress hooks and must be cheap.

  Modules never call each other directly; they share services through the
  container. Dependency ordering is the order in the `khseo_modules` filter,
  which is also the extension point for future engines.
- **Rules as data** (`config/rules.php` → `RuleRegistry`): every SEO check is
  one validated record with id, category, severity, condition, evidence,
  recommendation, auto_fixable, risk, reversible and docs. Engines evaluate
  rules; they never define their own severities. Validation guarantees that
  auto-fixable rules are reversible and at most R1.
- **Shared vocabulary** (`src/Support`): `Evidence` (VERIFIED … NOT TESTED),
  `Risk` (R0–R4) and `Severity` (P0–P3) are PHP enums, so labels cannot drift.
  P0–P3 says *how urgent* an issue is; R0–R4 says *what it takes to change it*.
  Priority is not permission.
- **Security layer** (`src/Security`): capabilities, the SSRF `UrlGuard`, the
  libsodium `SecretStore`, and the `Redactor` used by the logger.
- **Outbound HTTP** (`src/Http`): `SafeFetcher` is the **only** component
  allowed to fetch external URLs. A unit test enforces this.
- **Governance** (`src/Governance`): `ChangeGate` is the single R0–R4 decision
  point for applying any change.
- **AI is optional**: the AI provider layer (Phase 6) will sit behind a
  provider interface. No core feature may require it.

## 2. Directory tree and status

```
khseo-wordpress/
├── khseo.php                 BUILT    plugin header + bootstrap (PHP 8.1 guard)
├── uninstall.php             BUILT    deletes data only if the user opted in (multisite-aware)
├── config/rules.php          BUILT    central rule definitions (8 rules)
├── src/
│   ├── Autoloader.php        BUILT    PSR-4, rejects path traversal
│   ├── Core/                 BUILT    Container, Plugin, Module, Activator, Migrations (v2)
│   ├── Support/              BUILT    Evidence, Risk, Severity, Logger (redacted, bounded)
│   ├── Security/             BUILT    Capabilities, UrlGuard, SecretStore, Redactor
│   ├── Http/                 BUILT    SafeFetcher, FetchPolicy, WpHttpTransport (IP pinning)
│   ├── Governance/           BUILT    ChangeGate (no engine applies changes yet)
│   ├── Rules/                BUILT    Rule, RuleRegistry
│   ├── Settings/             BUILT    schema, sanitizer on every write path
│   │                         PARTIAL  import/export: library + tests, no UI or REST yet
│   ├── Audit/                BUILT    SiteChecks (search visibility, HTTPS), StatusReport
│   ├── Admin/                BUILT    menu, Overview, Settings, encrypted AI-key form
│   ├── API/                  BUILT    GET /khseo/v1/status
│   ├── Technical/ Content/ Schema/ Links/ Images/      PLANNED (Phases 2–3)
│   ├── AEO/ GEO/ LLM/ Trust/ Local/                    PLANNED (Phase 4)
│   ├── WooCommerce/ Gig/ Compatibility/                PLANNED (Phase 5)
│   ├── AI/                                             PLANNED (Phase 6)
│   ├── Google/                                         PLANNED (Phase 7)
│   ├── Guard/ History/ Regression/                     PLANNED (Phase 8)
│   └── CLI/                                            PLANNED (Phase 9)
├── tests/Unit/               BUILT    pure-PHP tests (no WordPress needed)
├── tools/integration-test.sh BUILT    real WordPress + MariaDB in Docker, incl. multisite
└── docs/                     BUILT    ARCHITECTURE.md, QUALITY.md
```

Folders marked PLANNED do not exist yet. They appear when their phase starts.

## 3. Dependency plan

| Kind | Dependency | Why |
|---|---|---|
| Runtime | WordPress ≥ 6.4, PHP ≥ 8.1, ext-sodium (or WordPress's bundled sodium_compat), ext-curl for fetching | Platform |
| Runtime | **No** third-party packages | Small attack surface; ready for WordPress.org |
| Dev | PHPUnit 10, PHPCS + WPCS 3, PHPCompatibilityWP, PHPStan 2 + phpstan-wordpress | Tests and static analysis |
| Optional | WooCommerce | The WooCommerce engine (Phase 5) loads only if it is active |

## 4. Database plan

Phase 1 uses only options:

| Option | Autoload | Purpose |
|---|---|---|
| `khseo_settings` | yes | Settings (sanitized schema) |
| `khseo_secrets` | no | Encrypted secrets |
| `khseo_log` | no | Bounded log |
| `khseo_db_version` | yes | Migration version (read on every request) |

Migrations (`Core/Migrations`) are versioned, idempotent and run once per site:

| Version | Change |
|---|---|
| v1 | Log option |
| v2 | Default capabilities, so sites created after network activation get them |

Custom tables are added only when a phase needs them, using `dbDelta()` and the
per-site `$wpdb->prefix`:

| Table | Phase | Purpose |
|---|---|---|
| `{prefix}khseo_issues` | 2 | audit findings per object |
| `{prefix}khseo_tasks` | 3 | prioritised "What should I do today?" queue |
| `{prefix}khseo_ai_usage` | 6 | token and cost accounting (no prompt bodies) |
| `{prefix}khseo_history` | 8 | change journal: before/after, risk, user, reason |
| `{prefix}khseo_snapshots` | 8 | SEO state snapshots for regression checks and rollback |

Deactivation never deletes data. Uninstall deletes a site's data only if that
site opted in.

## 5. Security model

- **Trust boundary**: all of these are untrusted:
  - GET/POST/REST input, headers and cookies;
  - database values;
  - imported JSON;
  - fetched pages;
  - AI output.

  Database reads are re-validated: `Settings::normalize()`, `SecretStore::decrypt(mixed)` and `Logger::prune()` all tolerate corrupt values.
- **Every admin action** checks a capability and a nonce. Integration tests
  cover four cases over real HTTP:
  - anonymous request;
  - editor;
  - admin with no nonce;
  - admin with a forged nonce.
- **Every REST route** has a real `permission_callback`; nothing private uses `__return_true`.
- **Output** is escaped at render time.
- **SQL** goes only through `$wpdb->prepare()`. Phase 1 runs no custom SQL.
- **SSRF**: every external request goes `SafeFetcher` → `UrlGuard` → `WpHttpTransport`:
  1. `UrlGuard` allows only http/https on ports 80/443, with no credentials, ASCII hosts only, and no
     local names (`localhost`, `localhost.`, `*.local`, `*.internal`).
  2. It refuses numeric IPv4 shorthands (`2130706433`, `0x7f.1`, `127.1`), because curl reads them as
     addresses.
  3. It resolves DNS and checks **every** A/AAAA record against private, loopback, link-local, CGNAT,
     documentation, benchmarking, multicast and reserved ranges, including IPv4-mapped and NAT64 IPv6.
  4. `WpHttpTransport` pins the TCP connection to the validated IP (`CURLOPT_RESOLVE`), so DNS cannot
     be rebound between the check and the connection. If curl is not the transport in use, the
     request is **refused**.
  5. Redirects are never followed automatically; `SafeFetcher` re-validates each hop.
  6. `FetchPolicy` sets connect timeout 5 s, total 10 s, 2 MB raw, 5 MB decoded (gzip-bomb guard),
     3 redirects, and a content-type allow-list. Engines may tighten these limits, never disable them.
- **Secrets** (`SecretStore`): encrypted with libsodium `crypto_secretbox`. The key comes from, in
  order:
  1. `KHSEO_SECRET_KEY` in wp-config;
  2. the real wp-config salts (WordPress's placeholder salts are rejected);
  3. WordPress's database salt (weaker, and reported as the key source).

  With no safe key material, saving keys is disabled; nothing crashes. Secrets are never rendered,
  logged, exported, sent to JavaScript or included in exception messages. The UI shows only
  `••••last4`.
- **Logging**: entries are redacted, flattened to one line (no forged entries), stripped of tags,
  capped at 500 characters with 2 KB of context, limited to 200 entries and pruned by retention.
  Security events are always kept.

## 6. Capability model

| Capability | Administrator | Editor | Notes |
|---|---|---|---|
| `view_khseo` | ✔ | ✔ | read dashboards and reports |
| `run_khseo_audit` | ✔ | — | start audits (analysis only) |
| `edit_khseo` | ✔ | — | edit SEO fields via KHSEO screens |
| `manage_khseo` | ✔ | — | general management |
| `manage_khseo_settings` | ✔ | — | settings, import/export |
| `manage_khseo_ai` | ✔ | — | AI provider + keys |
| `manage_khseo_integrations` | ✔ | — | Google integrations |
| `rollback_khseo_changes` | ✔ | — | rollback |

- Editors get read-only access by default. Analysing (`run_khseo_audit`) and
  changing (`edit_khseo`, future apply-fix) stay separate capabilities.
- Defaults are granted on activation and by migration v2, which only ever adds.
- Capabilities are removed only on an opted-in uninstall.

## 7. Planned designs for later phases (not built)

**Safe-fix pipeline (Phases 2 and 8).** Engines never mutate content or options
directly. Each change goes through these steps:
1. Audit
2. Issue (rule id + evidence)
3. Recommendation
4. Risk from the rule
5. Proposed diff (before/after)
6. Approval of *this* change-set id
7. Recovery point (R3: available; R4: verified)
8. `ChangeGate::decide()`
9. Apply
10. Validate
11. Journal (`khseo_history`)
12. Rollback

Rollback only reverts KHSEO-owned changes, and is **blocked** if the current
value no longer matches what KHSEO wrote.

**Prompt-injection boundaries (Phase 6).** Prompts keep five sections separate:
- SYSTEM RULES
- USER INTENT
- SITE DATA
- EXTERNAL CONTENT
- MODEL OUTPUT

Fetched pages (`FetchResult` bodies) and post content go only into the
data/content sections, wrapped and labelled as untrusted. Nothing in them can
change rules or trigger actions. Secrets, salts, paths and cookies are never
included. Every AI output is validated before it is shown as a suggestion
(`RECOMMENDED`, never `VERIFIED`).

**REST growth.** `/khseo/v1/status` exists today. These routes are added only
when their engine exists, each with its own capability:
- `/audit` and `/issues` (`run_khseo_audit` / `view_khseo`);
- `/settings` (`manage_khseo_settings`, through the same sanitizer);
- `/schema`, `/content`, `/integrations`.

**Coexistence with other SEO plugins (Phase 5).** The default compatibility mode
is *Advisory*: KHSEO reports and suggests but outputs no metadata. The
compatibility engine will:
- detect Yoast, Rank Math, AIOSEO, SEOPress and The SEO Framework;
- flag duplicate titles, descriptions, canonicals, robots tags, schema and sitemaps;
- never overwrite another plugin's metadata automatically.

## 8. Module dependency graph

```
Core ──► Support ──► Security ──► Settings ──► Rules
  │          │           │
  │          └──► Http (SafeFetcher)     Governance (ChangeGate)
  ├──► Admin (Settings, Capabilities, Rules, SecretStore)
  ├──► API   (Settings, Capabilities, Rules)
  └──► [Phase 2+] Technical ─► Content ─► Score ─► Opportunity
                     │            │   (all fetching via Http, all changes via Governance)
                     └──► Schema ─┴──► AEO / GEO / LLM / Trust
       AI (optional) ──► Copilot      Google (optional) ──► Decay / Cannibalization
```

## 9. Testing strategy

1. **Unit** (`tests/Unit`, no WordPress). Covers:
   - enums, rules, settings and import edge cases;
   - `UrlGuard` (all ranges, numeric hosts);
   - `SafeFetcher` (redirect re-validation, loops, size, content type, gzip bomb);
   - `SecretStore` (key sources, tampering) and the redactor;
   - logger injection, `ChangeGate`, container and autoloader;
   - version sync, hidden characters, and a ban on direct HTTP calls outside `src/Http`.
2. **Integration** (`tools/integration-test.sh`, real WordPress + MariaDB). Covers:
   - activation, capabilities and REST permissions;
   - real site checks;
   - secrets, sanitizer on WP-CLI writes, logger bounds;
   - SafeFetcher refusing internal hosts, plus a live **DNS-pinning proof**;
   - real-HTTP login, nonce and capability attacks;
   - admin rendering, uninstall;
   - **multisite** (network activation, later-created sites, per-site isolation, per-site uninstall).
3. **Static analysis**: `php -l`, PHPCS (WordPress Coding Standards), PHPStan
   level 8, `composer audit`.
4. **Regression**: every fixed bug has a test. Mutation checks confirmed the new
   tests fail when the fix is removed.
5. **CI** runs all of this on PHP 8.1–8.4, with actions pinned to commit SHAs.

Current results: [QUALITY.md](QUALITY.md).

## 10. Implementation roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | Bootstrap, autoload, core, settings, capabilities, security (SSRF, secrets, redaction), safe fetcher, change gate, admin shell, REST status, tests, CI | BUILT (v0.1.1) |
| 2 | SEO engine, technical SEO, metadata, canonical, robots, sitemap, social, schema | PLANNED |
| 3 | Content SEO, internal links, images, KHSEO Score, opportunities | PLANNED |
| 4 | AEO, GEO, LLM readability, trust, local SEO | PLANNED |
| 5 | WooCommerce, Gig SEO, compatibility with other SEO plugins | PLANNED |
| 6 | AI provider layer, AI Copilot (optional) | PLANNED |
| 7 | Search Console, GA4, GTM, verification, PageSpeed (optional) | PLANNED |
| 8 | SEO Guard, history, snapshots, regression, rollback | PLANNED |
| 9 | Performance, accessibility, multisite UI, WP-CLI, docs, packaging | PLANNED |
