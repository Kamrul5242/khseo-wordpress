=== KHSEO ===
Contributors: kamrul5242
Tags: seo, schema, technical seo, aeo, security
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Evidence-first, security-first SEO for WordPress. Works fully without an AI API.

== Description ==

KHSEO reports what it actually measured and labels everything else as UNKNOWN or NOT TESTED. It never invents rankings, search volume, reviews or Google data.

This is a foundation release (0.1.x, Phase 1 of 9). It includes:

* Site-level checks: search-engine visibility and HTTPS, with evidence and source.
* Settings with safe defaults: no automation, no AI, and no data deletion unless you choose them.
* Encrypted storage for an optional AI API key. The key is never shown, logged or exported.
* A Safe Fetcher and a change gate (R0–R4 risk rules) that later SEO engines must use.
* A REST status endpoint protected by a dedicated capability.

SEO engines (metadata, schema, content, internal links, AEO/GEO, WooCommerce and more) arrive in later releases.

= External services =

No KHSEO feature makes external requests in this version. A Safe Fetcher (SSRF-protected) exists for future features but nothing calls it yet. Future optional integrations (AI providers, Google Search Console) will be off by default, will be documented here, and will run only after you enable them.

== Frequently Asked Questions ==

= Do I need an AI API key? =

No. KHSEO is designed to be fully useful without AI.

= Will it conflict with Yoast, Rank Math or other SEO plugins? =

KHSEO starts in Advisory mode: it reports and suggests but does not output metadata.

= What happens when I delete the plugin? =

Your data is kept unless you tick "Delete all KHSEO data when the plugin is deleted" in KHSEO → Settings.

== Changelog ==

= 0.1.1 =
* Security: one Safe Fetcher for all future outbound requests. It pins the connection to the validated IP (DNS-rebinding defence), re-checks every redirect, and limits time, size, content type and decompression.
* Security: odd numeric hosts such as 2130706433 or 127.1 are now refused, along with trailing-dot local names.
* Security: placeholder WordPress salts are never used as an encryption key. A missing key no longer causes a fatal error.
* Security: log entries are single-line, tag-free and size-capped.
* Fix: on multisite, every site is now covered (more than 100 sites) and sites created after network activation get KHSEO capabilities.
* Fix: the status no longer says AI is "configured" when only a key is saved. AI features are reported as PLANNED.

= 0.1.0 =
* Foundation release: architecture, security layer, settings, capabilities, admin shell, REST status, tests.
