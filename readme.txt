=== KHSEO ===
Contributors: kamrul5242
Tags: seo, schema, technical seo, aeo, security
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Evidence-first, security-first SEO for WordPress. Works fully without an AI API.

== Description ==

KHSEO reports what it actually measured and labels everything else as UNKNOWN or NOT TESTED. It never invents rankings, search volume, reviews or Google data.

Version 0.2 (Phase 2 of 9) audits your site's own pages:

* Technical SEO: status codes, redirects, HTTPS, robots meta / X-Robots-Tag, robots.txt, XML sitemaps, canonicals, language, charset.
* Metadata: titles, meta descriptions, headings; images (alt, dimensions); links.
* Social: Open Graph and Twitter/X cards. Structured data: JSON-LD validation for common schema.org types.
* An issues list with evidence for every finding, and a KHSEO Score (an internal diagnostic score, not a Google ranking score) that states exactly which URLs it covers.
* One governed fix (search-engine visibility) with approval, a recovery point and rollback.
* Site-level checks: search-engine visibility and HTTPS, with evidence and source.
* Settings with safe defaults: no automation, no AI, and no data deletion unless you choose them.
* Encrypted storage for an optional AI API key. The key is never shown, logged or exported.
* A Safe Fetcher and a change gate (R0–R4 risk rules) that later SEO engines must use.
* A REST status endpoint protected by a dedicated capability.

Not built yet (planned): KHSEO metadata output, content analysis, AEO/GEO, WooCommerce, Gig SEO, AI features, Google integrations.

= External services =

Audits fetch pages of THIS site only (its own address, through an SSRF-protected fetcher). Nothing is sent to any third party. Future optional integrations (AI providers, Google Search Console) will be off by default, will be documented here, and will run only after you enable them.

== Frequently Asked Questions ==

= Do I need an AI API key? =

No. KHSEO is designed to be fully useful without AI.

= Will it conflict with Yoast, Rank Math or other SEO plugins? =

KHSEO starts in Advisory mode: it reports and suggests but does not output metadata.

= What happens when I delete the plugin? =

Your data is kept unless you tick "Delete all KHSEO data when the plugin is deleted" in KHSEO → Settings.

== Changelog ==

= 0.2.0 =
* Phase 2: technical SEO audit, robots.txt and sitemap checks, metadata, images, links, Open Graph, Twitter cards, JSON-LD validation, issues list, KHSEO Score, governed fix with rollback, REST API.

= 0.1.1 =
* Security: one Safe Fetcher for all future outbound requests. It pins the connection to the validated IP (DNS-rebinding defence), re-checks every redirect, and limits time, size, content type and decompression.
* Security: odd numeric hosts such as 2130706433 or 127.1 are now refused, along with trailing-dot local names.
* Security: placeholder WordPress salts are never used as an encryption key. A missing key no longer causes a fatal error.
* Security: log entries are single-line, tag-free and size-capped.
* Fix: on multisite, every site is now covered (more than 100 sites) and sites created after network activation get KHSEO capabilities.
* Fix: the status no longer says AI is "configured" when only a key is saved. AI features are reported as PLANNED.

= 0.1.0 =
* Foundation release: architecture, security layer, settings, capabilities, admin shell, REST status, tests.
