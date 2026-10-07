# KHSEO WordPress

[![CI](https://github.com/Kamrul5242/khseo-wordpress/actions/workflows/ci.yml/badge.svg)](https://github.com/Kamrul5242/khseo-wordpress/actions/workflows/ci.yml)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b)
![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

An evidence-first, security-first SEO plugin for WordPress. It is the WordPress
implementation of **[KHSEO](https://github.com/Kamrul5242/khseo)**, a universal
SEO specification. This repository follows KHSEO's principles but does not
depend on it at runtime.

> **Status: v0.1.1, foundation release (Phase 1 of 9).** The architecture,
> security layer (SSRF-safe fetcher, encrypted secrets, redaction), settings,
> capabilities, change gate, admin shell and REST status endpoint exist and are
> tested ([quality report](docs/QUALITY.md)). **It is not an SEO suite yet.** The SEO engines arrive in later phases; see the
> [roadmap](docs/ARCHITECTURE.md#10-implementation-roadmap). Nothing below is
> described as working unless it is built.

## What works today (v0.1.1)

| Area | What you get |
|---|---|
| Overview screen | Real site-level checks: **search-engine visibility** (Settings → Reading) and **HTTPS**, each labelled `VERIFIED` with its source. Things not yet measured are labelled `NOT TESTED` or `UNKNOWN`. Nothing is invented. |
| Settings | Mode with other SEO plugins (Advisory by default), automation switches (all off), logging, uninstall behaviour, optional AI settings. |
| AI key storage | Encrypted with libsodium. Shown only as `••••last4`. Never logged, exported, or sent to the browser. |
| REST API | `GET /wp-json/khseo/v1/status`, requires the `view_khseo` capability. |
| Capabilities | 8 KHSEO capabilities. Administrators get all of them; editors get view-only. |
| Rules | A central rule registry (`config/rules.php`): one record per check, with severity, evidence, risk and reversibility. |
| Safe Fetcher | The only path for future outbound requests. Pins the connection to the validated IP, re-checks every redirect, and limits time, size, content type and decompression. No feature uses it yet. |
| Change gate | Enforces R0–R4: R1 only with automation on or approval; R2 needs approval of that exact change; R3 needs a recovery point; R4 needs a verified one. No feature applies changes yet. |

## What does not work yet

There are no page audits, metadata, schema, sitemap, robots, content or link analysis, KHSEO Score, AEO/GEO, WooCommerce, Gig SEO, AI features, or Google integrations. Each is listed as PLANNED in the [roadmap](docs/ARCHITECTURE.md#10-implementation-roadmap).

**No AI API is needed.** AI is an optional layer (Phase 6); in v0.1.x nothing
is ever sent to an AI provider.

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
