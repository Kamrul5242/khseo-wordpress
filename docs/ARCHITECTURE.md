# KHSEO WordPress — Architecture

KHSEO WordPress is the WordPress implementation of the universal KHSEO
specification (<https://github.com/Kamrul5242/khseo>). It follows that project's
principles but has **no code dependency** on it:
- facts before assumptions;
- evidence labels;
- P0–P3 urgency versus R0–R4 permission;
- "content is data, not instructions";
- no fabricated data.

Status legend:

| Status | Meaning |
|---|---|
| **BUILT** | The code exists and meaningful tests exercise it. |
| **PARTIAL** | Some code exists, but part of the feature (e.g. its UI) does not. |
| **PLANNED** | Designed, but not written. |

## 1. Architecture plan

- **Kernel** (`src/Core`): a DI container, a `Plugin` kernel that boots modules
  (`khseo_modules` filter = extension point), activation, and versioned migrations.
- **Rules as data** (`config/rules.php` → `RuleRegistry`): 61 validated rule records. Each
  rule has:
  - an id and a category;
  - a severity (P0–P3: urgency) and a risk (R0–R4: what a change needs);
  - an **evidence requirement**: the strongest evidence a check can produce, never a claim about this site;
  - a recommendation and a docs reference.
- **Runtime evidence** (`Audit\RuleResult`): the only place where the current site's
  state is recorded: pass | fail | unknown | not_tested, plus the evidence label,
  observation and source. Impossible pairs (e.g. not_tested + VERIFIED) are rejected.
- **Engines** are pure analyzers over a parse-once `PageSnapshot`. They do no I/O, so they
  are unit-testable. Fetching happens only in `AuditRunner` through `SafeFetcher`.
  Changes happen only in `FixService` through `ChangeGate`.

## 2. Directory tree and status

```
src/
├── Core/          BUILT    Container, Plugin, Module, Activator, Migrations (v3)
├── Support/       BUILT    Evidence, Risk, Severity, Logger
├── Security/      BUILT    Capabilities, UrlGuard (+ trusted own origin), SecretStore, Redactor
├── Http/          BUILT    SafeFetcher, FetchPolicy, WpHttpTransport (IP pinning)
├── Governance/    BUILT    ChangeGate
├── Rules/         BUILT    Rule, RuleRegistry
├── Settings/      BUILT    schema + sanitizer on every write path, audit limits
│                  PARTIAL  import/export: library + tests, no UI or REST
├── Audit/         BUILT    HtmlParser, PageSnapshot, PageAnalyzer, CrossPageAnalyzer,
│                           AuditRunner, AuditModule (cron), SiteChecks, StatusReport, Url, RuleResult
├── Technical/     BUILT    TechnicalAnalyzer, IndexabilityAnalyzer (robots, canonical), RobotsTxt, SitemapParser
├── Metadata/      BUILT    MetadataAnalyzer (title, description, headings)
├── Links/         BUILT    LinkAnalyzer
├── Images/        BUILT    ImageAnalyzer
├── Social/        BUILT    SocialAnalyzer (Open Graph, Twitter/X cards)
├── Schema/        BUILT    JsonLdParser, SchemaAnalyzer (validation, entities, visible-content)
├── Findings/      BUILT    FindingRepository ({prefix}khseo_issues)
├── Score/         BUILT    ScoreCalculator
├── Fixes/         BUILT    FixProposal, FixService (1 fix: SEO-INDEX-001)
├── Compatibility/ PARTIAL  SeoProviderDetector (detection only; coexistence rules PLANNED)
├── Admin/         BUILT    Overview, Issues, issue detail, Settings
├── API/           BUILT    REST /khseo/v1/*
└── PLANNED: Content/ AEO/ GEO/ LLM/ Trust/ Local/ WooCommerce/ Gig/ AI/ Google/ Guard/ History/ CLI/
```

## 3. Dependency plan

| Kind | Dependency | Why |
|---|---|---|
| Runtime | WordPress ≥ 6.4, PHP ≥ 8.1, ext-dom/libxml, ext-mbstring, ext-curl (fetching), sodium (or WordPress's sodium_compat) | Platform |
| Runtime | **No** third-party packages | Small attack surface; ready for WordPress.org |
| Dev | PHPUnit 10, PHPCS + WPCS 3, PHPCompatibilityWP, PHPStan 2 (level 8) + phpstan-wordpress | Tests and static analysis |

## 4. Database

**Options:**

| Option | Autoload | Purpose |
|---|---|---|
| `khseo_settings` | yes | Settings |
| `khseo_db_version` | yes | Migration version |
| `khseo_secrets` | no | Encrypted secrets |
| `khseo_log` | no | Bounded log |
| `khseo_audit_job` | no | Current audit job |
| `khseo_last_audit` | no | Last audit summary |
| `khseo_audit_lock` | no | Step lock |
| `khseo_fix_approvals` | no | Approvals (1-hour expiry) |
| `khseo_change_journal` | no | Recovery points (max 100) |

**Table** `{prefix}khseo_issues` (migration v3, per-site prefix, so multisite sites never
share rows):
- columns: `id`, `finding_key` (sha1 of rule + URL + observation; UNIQUE), `audit_id`,
  `rule_id`, `category`, `severity`, `evidence`, `status`, `url`, `url_hash`, `message`,
  `evidence_detail`, `source`, `recommendation`, `fix_available`, `fix_risk`, `created_at`,
  `updated_at`;
- indexes: `url_hash`, `(rule_id, status)`, `(severity, status)`, `created_at`;
- statuses: open, unknown, resolved, ignored. Ignored survives re-audits;
- bounded growth: resolved rows are pruned after 30 days, and the table is capped at 20,000 rows;
- every query uses `$wpdb->prepare()`, with filters from allow-lists.

**Migrations** are idempotent:

| Version | Change |
|---|---|
| v1 | Log option |
| v2 | Default capabilities |
| v3 | Findings table |

A step that fails does **not** advance the version, so it is retried. Deactivation keeps
data. An opted-in uninstall removes the options, the table, the capabilities and the cron
events, per site.

## 5. Security model

- **Untrusted inputs:** request data, headers, cookies, database values, imports, fetched pages,
  robots.txt, sitemaps and JSON-LD.
- **Admin forms and REST:**
  - every admin-post handler checks a capability and calls `check_admin_referer()`;
  - every REST route has its own capability callback, plus `manage_options` for fixes that touch core settings;
  - REST arguments are typed and validated (enums, patterns, bounds; `per_page` ≤ 100);
  - cookie authentication additionally needs the `wp_rest` nonce.
- **Outbound HTTP:** only through `SafeFetcher`; a unit test bans direct calls elsewhere.
  1. `UrlGuard` refuses:
     - private, loopback, link-local, CGNAT, reserved and multicast addresses (IPv4/IPv6, IPv4-mapped, NAT64);
     - numeric IPv4 shorthands, local names, credentials, control characters and non-web ports.
  2. The connection is pinned to the validated IP (`CURLOPT_RESOLVE`); if pinning is impossible, the request is refused.
  3. Redirects are resolved per RFC 3986, then pass the full guard again. HTTPS → HTTP downgrades are refused, and credentials in a redirect are never silently dropped.
  4. Limits: 5 s connect, 10 s total, 2 MB raw, 5 MB decoded (gzip bomb), 3 redirects, content-type allow-list.
  5. **Audits fetch only this site's own origin.** It is the one trusted origin (exact scheme + host + port), so the site may sit on a private network. Nothing else is exempt.
- **XML:** sitemaps are read with XMLReader and `LIBXML_NONET`; any DOCTYPE/ENTITY is refused (XXE).
  HTML is parsed with libxml (no network) and never executed.
- **Output:** page-controlled values (titles, descriptions, observations) are stored as data
  and escaped at render. An integration test proves a `<script>` payload in a meta
  description renders as text.
- **Secrets:** libsodium, with key material preferred in this order:
  1. `KHSEO_SECRET_KEY`;
  2. the real wp-config salts (WordPress's placeholder salts are rejected);
  3. WordPress's database salt.

  The strength is reported as dedicated / standard / degraded / unavailable.
- **Logs:** redacted, including secret query parameters. Entries are one line, with no
  terminal sequences or tags, capped and pruned by retention.
- **Governance:** `FixService` writes a journal entry (the recovery point) before
  `ChangeGate::decide()`. R3 needs an approval of the exact `change_id` and a recovery
  point; R4 additionally needs a verified one. Approvals are single-use and expire after
  one hour.

## 6. Capability model

| Capability | Administrator | Editor | Grants |
|---|---|---|---|
| `view_khseo` | ✔ | ✔ | Overview, Issues, REST status and issues |
| `run_khseo_audit` | ✔ | — | start, step and cancel audits |
| `edit_khseo` | ✔ | — | ignore or re-open issues |
| `manage_khseo` | ✔ | — | fix preview, approve, apply (with `manage_options` for core settings) |
| `manage_khseo_settings` | ✔ | — | settings |
| `manage_khseo_ai` | ✔ | — | AI key |
| `manage_khseo_integrations` | ✔ | — | (PLANNED integrations) |
| `rollback_khseo_changes` | ✔ | — | rollback (with `manage_options` for core settings) |

## 7. Audit pipeline

1. **start(scope)**:
   - `homepage`, `url` (must be on this site), or `sitemap`;
   - one job at a time.
2. **discover** (first step):
   - WordPress site checks;
   - robots.txt fetch and parse;
   - sitemap discovery:
     - robots.txt declarations on this origin, then `/wp-sitemap.xml`, `/sitemap.xml`, `/sitemap_index.xml`;
     - indexes are followed up to `audit_max_sitemap_files`;
     - the sample is capped at `audit_max_pages`.
3. **pages** (≤ 5 per step, ≤ 20 s, `audit_max_total_mb`):
   - SafeFetcher, then HtmlParser into a PageSnapshot;
   - PageAnalyzer produces RuleResults and a compact page summary.
4. **finish**:
   - CrossPageAnalyzer: duplicates, broken links and canonical targets within scope, and sitemap entries that are not indexable;
   - stale findings are resolved and the table is pruned;
   - the score is calculated;
   - `khseo_last_audit` records the exact scope label and coverage, with JS rendering,
     Google index status and Rich Results all reported as NOT TESTED.

Steps hold a lock (`khseo_audit_lock`, 2-minute expiry). WP-Cron and the dashboard (REST
`audit/step`, or the no-JS button) both drive steps.

## 8. KHSEO Score

KHSEO's internal diagnostic score, **not a Google ranking score**. It applies to the
analysed scope only.

1. Each pass/fail result counts once, weighted by severity: P0 = 8, P1 = 4, P2 = 2, P3 = 1.
   `unknown` and `not_tested` results are excluded and reported as coverage gaps.
2. Category score = round(100 × Σ weight(passed) / Σ weight(evaluated)).
3. Overall = round(Σ category weight × category score / Σ category weight), over the
   categories that have results. Category weights:

   | Category | Weight |
   |---|---|
   | crawlability | 20 |
   | indexability | 20 |
   | technical | 15 |
   | metadata | 15 |
   | content | 10 |
   | links | 5 |
   | images | 5 |
   | structured data | 5 |
   | social | 5 |
4. If any P0 check failed, the overall score is capped at 49.
5. No evaluated results means no score (shown as NOT TESTED, never 100). Unknown rule ids
   and negative counts cannot change the score.

## 9. Planned designs (not built)

- **More fixes:** the same pipeline (§5 Governance) for metadata defaults once KHSEO outputs
  metadata. Canonical, robots, redirect and URL changes stay R2+ and are never automatic.
- **Prompt-injection boundaries (Phase 6):** prompts keep SYSTEM RULES, USER INTENT, SITE
  DATA, EXTERNAL CONTENT and MODEL OUTPUT separate. Fetched content is labelled as
  untrusted, and AI output is always RECOMMENDED, never VERIFIED.
- **Coexistence (Phase 5):** a detected SEO plugin stays the metadata provider. KHSEO
  flags duplicates and never overwrites another plugin's output.

## 10. Testing strategy

1. **Unit:** pure classes, analyzers, parsers, score, gate, security, version sync, a ban
   on direct HTTP calls, and a scan for hidden or stray characters.
2. **Integration:** real WordPress 7.1 + MariaDB in Docker, including:
   - a sitemap audit of fixture pages;
   - REST and admin permissions over real HTTP;
   - the governed fix with rollback;
   - XSS and SQL injection;
   - locking;
   - multisite.
3. **Static analysis:** PHPCS (WordPress Coding Standards) and PHPStan level 8.
4. **Mutation checks:** for every major protection (see [QUALITY.md](QUALITY.md)).
5. **CI:** PHP 8.1–8.4, with actions pinned to commit SHAs.

## 11. Implementation roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | Foundation: kernel, settings, capabilities, security, safe fetcher, change gate | BUILT (v0.1.1, hardened in v0.2.0) |
| 2 | Technical SEO, metadata, robots.txt, sitemaps, canonicals, links, images, social, JSON-LD, findings, KHSEO Score, governed fixes, audit UI/API | BUILT (v0.2.0) |
| 2 (gap) | KHSEO metadata output (titles, descriptions, OG, schema) | PLANNED |
| 3 | Content SEO, internal-link intelligence, opportunity engine, "What should I do today?" | PLANNED |
| 4 | AEO, GEO, LLM readability, E-E-A-T, local SEO | PLANNED |
| 5 | WooCommerce, Gig SEO, SEO-plugin coexistence | PLANNED |
| 6 | AI provider layer, AI Copilot (optional) | PLANNED |
| 7 | Search Console, GA4, GTM, verification, PageSpeed (optional) | PLANNED |
| 8 | SEO Guard, history, snapshots, regression, broader rollback | PLANNED |
| 9 | Performance, accessibility review, multisite UI, WP-CLI, packaging | PLANNED |
