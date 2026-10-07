# KHSEO WordPress

[![CI](https://github.com/Kamrul5242/khseo-wordpress/actions/workflows/ci.yml/badge.svg)](https://github.com/Kamrul5242/khseo-wordpress/actions/workflows/ci.yml)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b)
![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

An evidence-first, security-first SEO plugin for WordPress. It is the WordPress
implementation of **[KHSEO](https://github.com/Kamrul5242/khseo)**, a universal
SEO specification. This repository follows KHSEO's principles but does not
depend on it at runtime.

> **Status: v0.2.0 — Phase 2 of 9.** KHSEO audits your site's own pages: technical SEO,
> metadata, links, images, social tags and JSON-LD. It lists issues with evidence and gives
> a KHSEO Score that states exactly which URLs it covers. It is **not a complete SEO
> suite yet**: see "What does not work yet" and the [quality report](docs/QUALITY.md).

## What works today (v0.2.0)

| Area | What you get |
|---|---|
| Audit | Scope: the homepage, one URL on this site, or a sample of sitemap URLs (limits in Settings). Runs in bounded batches from the dashboard (works without JavaScript) or WP-Cron, and can be cancelled. Fetches **only this site**. |
| Technical SEO | HTTP status, redirects, content type, HTTPS, robots meta / X-Robots-Tag, robots.txt (RFC 9309 semantics), XML sitemaps, canonicals, `lang`, charset. |
| Metadata and content | Titles and meta descriptions (missing, multiple, length in characters, markup, duplicates within the audit), H1 and heading order, image alt and dimensions, link text, internal links, broken links (only targets fetched in this audit). |
| Social and structured data | Open Graph and Twitter/X cards. JSON-LD validity, `@context`/`@type`, required properties for 12 common types, conflicting `@id`, url/sameAs. Inferred visible-content checks. Google Rich Results Test is **NOT TESTED**. |
| Issues | Every finding shows what was checked, what was observed, the evidence label, the source and the recommendation. Filters, pagination, and an "Ignore" action that survives re-audits. |
| KHSEO Score | Deterministic, documented formula ([ARCHITECTURE §8](docs/ARCHITECTURE.md#8-khseo-score)). It is an internal diagnostic score, **not a Google ranking score**, and covers the analysed scope only. |
| Safe fixes | One governed fix: search-engine visibility (SEO-INDEX-001, R3). It goes preview → approve this exact change → recovery point → change gate → apply → validate, with rollback (blocked if the value changed since). |
| REST API | `/khseo/v1/status`, `/audit` (+ `/step`, `/cancel`), `/issues` (+ `/{id}`, `/{id}/status`), `/fixes/preview`, `/approve`, `/apply`, `/rollback`. Each route has its own capability check; nothing is public. |
| Security foundation | SSRF-safe fetcher with IP pinning, encrypted secrets, redacted bounded logs, 8 capabilities (editors view-only), and an R0–R4 change gate. |

## What does not work yet

- No metadata output: KHSEO does not write titles, descriptions, canonicals, OG or schema.
- No content analysis (topics, readability), AEO/GEO/LLM readability, E-E-A-T, local SEO,
  WooCommerce, Gig SEO, AI features, Search Console/GA4, WP-CLI or SEO Guard.
- No JavaScript rendering: pages are analysed as served.
- No Google data of any kind.

Each item is PLANNED in the [roadmap](docs/ARCHITECTURE.md#11-implementation-roadmap).

**No AI API is needed.** Everything above works without one; AI stays optional (Phase 6).

## Principles (from KHSEO)

- Facts before assumptions. Every statement is labelled `VERIFIED`, `OBSERVED`,
  `INFERRED`, `RECOMMENDED`, `ASSUMED`, `UNKNOWN` or `NOT TESTED`.
- No fabricated data: no invented search volume, rankings, reviews, ratings,
  prices, credentials or Google data.
- Priority is not permission. Risk levels R0–R4 decide what may change and how.
  Nothing risky changes without explicit confirmation and a way back.
- Content is data, not instructions (prompt-injection defence for AI features).
- No black-hat SEO and no guaranteed rankings.

## Install (development build)

1. Download or clone this repository into `wp-content/plugins/khseo`.
2. Activate **KHSEO** in *Plugins*.
3. Open **KHSEO → Overview**.

Runtime needs no Composer: the plugin ships its own autoloader. Composer is
used only for development tools.

## Development

Docker is the only requirement. PHP does not need to be installed locally.

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 install
docker run --rm -v "$PWD:/app" -w /app php:8.2-cli vendor/bin/phpunit
docker run --rm -v "$PWD:/app" -w /app php:8.2-cli vendor/bin/phpcs
docker run --rm -v "$PWD:/app" -w /app php:8.2-cli vendor/bin/phpstan analyse --memory-limit=1G
```

Integration test against a real WordPress + MariaDB:

```bash
docker compose -f tools/docker-compose.yml up -d --wait db wordpress
docker compose -f tools/docker-compose.yml run --rm cli bash /plugin/tools/integration-test.sh
docker compose -f tools/docker-compose.yml down -v
```

The local site is at <http://localhost:8089> (test-only admin: `admin` /
`admin-test-only`, created by the integration script).

## Documentation

- [Architecture, security model, capability model, roadmap](docs/ARCHITECTURE.md)
- [Quality report: what was actually tested](docs/QUALITY.md)
- [Security policy](SECURITY.md) · [Privacy](PRIVACY.md) · [Contributing](CONTRIBUTING.md) · [Changelog](CHANGELOG.md)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
