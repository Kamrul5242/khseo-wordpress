=== KHSEO ===
Contributors: kamrul5242
Tags: seo, schema, technical seo, aeo, security
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Evidence-first, security-first SEO for WordPress. Works fully without an AI API.

== Description ==

KHSEO reports what it actually measured and labels everything else as UNKNOWN or NOT TESTED. It never invents rankings, search volume, reviews or Google data.

This is a foundation release (0.1.0). It includes:

* Site-level checks: search-engine visibility and HTTPS, with evidence and source.
* Settings with safe defaults: no automation, no AI, and no data deletion unless you choose them.
* Encrypted storage for an optional AI API key. The key is never shown, logged or exported.
* A REST status endpoint protected by a dedicated capability.

SEO engines (metadata, schema, content, internal links, AEO/GEO, WooCommerce and more) arrive in later releases.

= External services =

Version 0.1.0 makes no external requests. Future optional integrations (AI providers, Google Search Console) will be off by default, will be documented here, and will run only after you enable them.

== Frequently Asked Questions ==

= Do I need an AI API key? =

No. KHSEO is designed to be fully useful without AI.

= Will it conflict with Yoast, Rank Math or other SEO plugins? =

KHSEO starts in Advisory mode: it reports and suggests but does not output metadata.

= What happens when I delete the plugin? =

Your data is kept unless you tick "Delete all KHSEO data when the plugin is deleted" in KHSEO → Settings.

== Changelog ==

= 0.1.0 =
* Foundation release: architecture, security layer, settings, capabilities, admin shell, REST status, tests.
