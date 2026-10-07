# KHSEO WordPress — Architecture

KHSEO WordPress is the WordPress implementation layer of the universal KHSEO
specification (<https://github.com/Kamrul5242/khseo>). It follows that project's
principles — facts before assumptions, evidence labels, R0–R4 risk levels,
"content is data, not instructions" — but has **no code dependency** on it.

Status legend used in this document: **BUILT** (exists and is tested),
**PLANNED** (designed, not written). Nothing here is claimed as built unless the
roadmap table marks it so.

## 1. Architecture plan

- **Kernel** (`src/Core`): a small DI container, a `Plugin` kernel that boots
  *modules*, activation/deactivation, versioned migrations.
- **Modules**: each engine (Technical, Content, Schema, …) is a class
  implementing `KHSEO\Core\Module`. A module declares an id, its dependencies
  and a `register()` method that adds WordPress hooks. Modules never reach into
  each other directly; they talk through services in the container.
- **Rules as data** (`config/rules.php` → `KHSEO\Rules\RuleRegistry`): every SEO
  check is one rule record (id, category, severity, evidence, recommendation,
  auto-fixable, risk, reversible, docs). Engines evaluate rules; they do not
  define their own ad-hoc severities. This is the single source of truth.
- **Shared vocabulary** (`src/Support`): `Evidence` (VERIFIED … NOT TESTED),
  `Risk` (R0–R4), `Severity` (P0–P3) as PHP enums, so labels cannot drift.
- **Security layer** (`src/Security`): capabilities, SSRF-safe URL guard,
  secret store (libsodium), redactor used by the logger.
- **AI is optional**: the AI provider layer (Phase 6) sits behind
  `AIProviderInterface`; no core feature may require it.

## 2. Directory tree (current + planned)

```
khseo-wordpress/
├── khseo.php                 BUILT  plugin header + bootstrap
├── uninstall.php             BUILT  deletes data only if the user opted in
├── readme.txt  README.md  LICENSE  CHANGELOG.md  VERSION  SECURITY.md  PRIVACY.md
├── composer.json phpunit.xml.dist phpstan.neon.dist phpcs.xml.dist
├── config/rules.php          BUILT  central rule definitions
├── docs/ARCHITECTURE.md      BUILT  this file
├── src/
│   ├── Autoloader.php        BUILT  PSR-4 fallback (no Composer needed at runtime)
│   ├── Core/                 BUILT  Container, Plugin, Module, Activator, Migrations
│   ├── Support/              BUILT  Evidence, Risk, Severity, Logger
│   ├── Security/             BUILT  Capabilities, UrlGuard, SecretStore, Redactor
│   ├── Rules/                BUILT  Rule, RuleRegistry
│   ├── Settings/             BUILT  Settings schema, sanitizer, export (secrets excluded)
│   ├── Admin/                BUILT  menu shell, Overview, Settings screen
│   ├── API/                  BUILT  /khseo/v1/status
│   ├── Technical/ Content/ Schema/ Links/ Images/      PLANNED (Phases 2–3)
│   ├── AEO/ GEO/ LLM/ Trust/ Local/                    PLANNED (Phase 4)
│   ├── WooCommerce/ Gig/ Compatibility/                PLANNED (Phase 5)
│   ├── AI/                                             PLANNED (Phase 6)
│   ├── Google/                                         PLANNED (Phase 7)
│   ├── Guard/ History/ Regression/                     PLANNED (Phase 8)
│   └── CLI/                                            PLANNED (Phase 9)
├── tests/Unit/               BUILT  pure-PHP tests (no WordPress needed)
├── tests/Integration/        BUILT  smoke test against a real WordPress (Docker)
└── tools/                    BUILT  docker-compose + scripts for local testing
```

Empty folders are not created for appearance; a PLANNED folder appears when its
phase starts.

## 3. Dependency plan

| Kind | Dependency | Why |
|---|---|---|
| Runtime | WordPress ≥ 6.4, PHP ≥ 8.1, ext-sodium (bundled with PHP ≥ 7.2) | Platform |
| Runtime | **none** third-party | Smaller attack surface, WordPress.org friendly |
| Dev | phpunit/phpunit, squizlabs/php_codesniffer, wp-coding-standards/wpcs, phpstan/phpstan, szepeviktor/phpstan-wordpress | Tests and static analysis |
| Optional | WooCommerce | WooCommerce engine activates only if present |

## 4. Database plan

Phase 1 uses only options (`khseo_settings`, `khseo_secrets`, `khseo_db_version`,
`khseo_log`). Custom tables are added by versioned migrations only when a phase
needs them:

| Table | Phase | Purpose |
|---|---|---|
| `{prefix}khseo_issues` | 2 | audit findings per object |
| `{prefix}khseo_history` | 8 | change log (before/after/risk/user/reason) |
| `{prefix}khseo_snapshots` | 8 | SEO state snapshots for regression checks |
| `{prefix}khseo_tasks` | 3 | prioritised "What should I do today?" queue |
| `{prefix}khseo_ai_usage` | 6 | token/cost accounting, no prompt bodies |

Migrations run through `dbDelta()`, are idempotent and keyed by
`khseo_db_version`. Multisite: tables use the per-site `$wpdb->prefix`.

## 5. Security model

- **Trust boundary**: GET/POST/REST input, headers, cookies, database values,
  imported content, fetched pages and AI output are untrusted.
- **Every** admin action: capability check + nonce. Every REST route: a real
  `permission_callback` (never `__return_true` for non-public data).
- Output escaped at the last moment (`esc_html`, `esc_attr`, `esc_url`,
  `wp_kses`); SQL only through `$wpdb->prepare()`.
- **SSRF** (`UrlGuard`): http/https only, ports 80/443 by default, DNS resolved
  and every address checked against private, loopback, link-local, CGNAT,
  multicast and reserved ranges (IPv4 and IPv6, including IPv4-mapped IPv6);
  redirects are re-validated hop by hop.
- **Secrets** (`SecretStore`): API keys encrypted with libsodium
  `crypto_secretbox`; key from `KHSEO_SECRET_KEY` (wp-config) or derived from
  WordPress salts. Secrets are never rendered, logged, exported or sent to JS;
  the UI shows only a mask such as `sk-…9f2c`.
- **Logging** (`Logger`): structured, level-filtered, every message and context
  passed through `Redactor` (API-key, bearer, cookie, password patterns).
- **Prompt injection**: page content sent to AI is wrapped as data with an
  explicit instruction that it is untrusted (Phase 6).

## 6. Capability model

| Capability | Administrator | Editor | Notes |
|---|---|---|---|
| `view_khseo` | ✔ | ✔ | read dashboards and reports |
| `run_khseo_audit` | ✔ | — | start audits |
| `edit_khseo` | ✔ | — | edit SEO fields via KHSEO screens |
| `manage_khseo` | ✔ | — | general management |
| `manage_khseo_settings` | ✔ | — | settings, import/export |
| `manage_khseo_ai` | ✔ | — | AI provider + keys |
| `manage_khseo_integrations` | ✔ | — | Google integrations |
| `rollback_khseo_changes` | ✔ | — | rollback |

Editors get read-only access by default; site owners can grant more with any
role-editor plugin. Capabilities are added on activation and removed on
uninstall only when the user opted into data deletion.

## 7. Module dependency graph

```
Core ──► Support ──► Security ──► Settings ──► Rules
  │                                   │
  ├──► Admin (needs Settings, Capabilities, Rules)
  ├──► API   (needs Settings, Capabilities, Rules)
  └──► [Phase 2+] Technical ─► Content ─► Score ─► Opportunity
                     │            │
                     └──► Schema ─┴──► AEO / GEO / LLM / Trust
       AI (optional) ──► Copilot      Google (optional) ──► Decay / Cannibalization
```

## 8. Testing strategy

1. **Unit** (`tests/Unit`, no WordPress): enums, rule registry, URL guard,
   secret store, redactor, settings sanitizer, capability map.
2. **Integration** (`tests/Integration`, real WordPress in Docker via
   `tools/docker-compose.yml`): plugin activates without notices, capabilities
   land on the right roles, REST `/status` returns 401 to anonymous users and
   200 to an administrator, uninstall keeps data by default.
3. **Static analysis**: PHPCS (WordPress Coding Standards), PHPStan
   (with WordPress stubs), `php -l` on every file.
4. **Regression**: every fixed bug gets a test.
5. CI runs 1–3 on every push with third-party actions pinned to commit SHAs.

## 9. Implementation roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | Bootstrap, autoload, core, settings, capabilities, security foundation, admin shell, REST status, tests, CI | BUILT (v0.1.0) |
| 2 | SEO engine, technical SEO, metadata, schema, sitemap, robots, social | PLANNED |
| 3 | Content SEO, internal links, images, KHSEO Score, opportunities | PLANNED |
| 4 | AEO, GEO, LLM readability, trust, local SEO | PLANNED |
| 5 | WooCommerce, Gig SEO, compatibility with other SEO plugins | PLANNED |
| 6 | AI provider layer, AI Copilot (optional) | PLANNED |
| 7 | Search Console, GA4, GTM, verification, PageSpeed (optional) | PLANNED |
| 8 | SEO Guard, history, snapshots, regression, rollback | PLANNED |
| 9 | Performance, accessibility, multisite, WP-CLI, docs, packaging | PLANNED |
