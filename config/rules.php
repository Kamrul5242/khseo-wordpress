<?php
/**
 * KHSEO rule definitions — the single source of truth for SEO checks.
 *
 * Each row is validated by KHSEO\Rules\Rule:
 * - `evidence_requirement` is the strongest evidence a check of the rule can produce.
 *   It never says the current site was verified; only a runtime RuleResult does that.
 * - `severity` (P0–P3) is urgency. `risk` (R0–R4) is what changing it would take.
 *   Priority is not permission.
 *
 * @package KHSEO
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && ! defined( 'KHSEO_TESTING' ) ) {
	exit;
}

$khseo_g = 'https://developers.google.com/search/docs/';

// Compact row builder: id, category, severity, evidence requirement, risk, condition, recommendation, docs.
$khseo_rule = static fn ( string $id, string $cat, string $sev, string $ev, string $risk, string $cond, string $rec, string $docs = '' ): array => array(
	'id'                   => $id,
	'category'             => $cat,
	'severity'             => $sev,
	'evidence_requirement' => $ev,
	'risk'                 => $risk,
	'condition'            => $cond,
	'recommendation'       => $rec,
	'auto_fixable'         => false,
	'reversible'           => true,
	'docs'                 => $docs,
);

return array(
	// --- Crawlability ------------------------------------------------------------
	$khseo_rule( 'SEO-ROBOTS-001', 'crawlability', 'P0', 'VERIFIED', 'R3', 'robots.txt returned a server error (5xx) or could not be fetched; crawlers may treat the whole site as disallowed.', 'Make /robots.txt return 200 (or 404 if you have no rules).', $khseo_g . 'crawling-indexing/robots/robots_txt' ),
	$khseo_rule( 'SEO-ROBOTS-002', 'crawlability', 'P0', 'VERIFIED', 'R3', 'robots.txt disallows this URL for Googlebot.', 'If this page should appear in search, remove or narrow the Disallow rule that matches it.', $khseo_g . 'crawling-indexing/robots/robots_txt' ),
	$khseo_rule( 'SEO-ROBOTS-003', 'crawlability', 'P3', 'VERIFIED', 'R2', 'robots.txt contains lines that are not valid directives.', 'Fix or remove the malformed lines; crawlers ignore them.', $khseo_g . 'crawling-indexing/robots/robots_txt' ),
	$khseo_rule( 'SEO-ROBOTS-004', 'crawlability', 'P3', 'VERIFIED', 'R2', 'robots.txt does not declare a Sitemap.', 'Add a "Sitemap: https://…/sitemap.xml" line so crawlers can find it.', $khseo_g . 'crawling-indexing/sitemaps/build-sitemap' ),
	$khseo_rule( 'SEO-SITEMAP-001', 'crawlability', 'P1', 'VERIFIED', 'R2', 'No XML sitemap was found (robots.txt declarations, /wp-sitemap.xml and /sitemap.xml were checked).', 'Enable an XML sitemap (WordPress core provides /wp-sitemap.xml).', $khseo_g . 'crawling-indexing/sitemaps/overview' ),
	$khseo_rule( 'SEO-SITEMAP-002', 'crawlability', 'P1', 'VERIFIED', 'R2', 'A sitemap returned an error status, a non-XML type, or malformed XML.', 'Serve the sitemap with status 200 and well-formed XML.', $khseo_g . 'crawling-indexing/sitemaps/build-sitemap' ),
	$khseo_rule( 'SEO-SITEMAP-003', 'crawlability', 'P2', 'VERIFIED', 'R2', 'The sitemap lists a URL that is not indexable (error status, redirect or noindex).', 'List only final, indexable URLs in the sitemap.', $khseo_g . 'crawling-indexing/sitemaps/build-sitemap' ),
	$khseo_rule( 'SEO-SITEMAP-004', 'crawlability', 'P2', 'VERIFIED', 'R2', 'The sitemap contains invalid URLs or URLs on another host.', 'Use absolute URLs on this site only.', $khseo_g . 'crawling-indexing/sitemaps/build-sitemap' ),

	// --- Indexability ------------------------------------------------------------
	$khseo_rule( 'SEO-INDEX-001', 'indexability', 'P0', 'VERIFIED', 'R3', 'The whole site discourages search engines (Settings → Reading).', 'If the site is live, untick "Discourage search engines from indexing this site".', 'https://developer.wordpress.org/apis/options/#reading' ),
	$khseo_rule( 'SEO-INDEX-002', 'indexability', 'P1', 'VERIFIED', 'R2', 'The page has a noindex directive (meta robots or X-Robots-Tag).', 'Remove noindex if this page should appear in search results; keep it if exclusion is intended.', $khseo_g . 'crawling-indexing/block-indexing' ),
	$khseo_rule( 'SEO-INDEX-003', 'indexability', 'P2', 'VERIFIED', 'R2', 'The page has a page-level nofollow directive.', 'Remove page-level nofollow unless you intend crawlers to ignore every link on the page.', $khseo_g . 'crawling-indexing/robots-meta-tag' ),
	$khseo_rule( 'SEO-INDEX-004', 'indexability', 'P3', 'VERIFIED', 'R2', 'The page restricts snippets or archiving (nosnippet, noarchive, max-snippet:0, unavailable_after).', 'Confirm these restrictions are intended; they limit how results are shown.', $khseo_g . 'crawling-indexing/robots-meta-tag' ),
	$khseo_rule( 'SEO-CANON-001', 'indexability', 'P1', 'OBSERVED', 'R3', 'The canonical URL points to a different page than the one analysed.', 'Confirm the canonical target is the preferred version; otherwise make the page self-canonical.', $khseo_g . 'crawling-indexing/consolidate-duplicate-urls' ),
	$khseo_rule( 'SEO-CANON-002', 'indexability', 'P2', 'VERIFIED', 'R2', 'The page has no canonical link.', 'Add a self-referencing canonical link to indexable pages.', $khseo_g . 'crawling-indexing/consolidate-duplicate-urls' ),
	$khseo_rule( 'SEO-CANON-003', 'indexability', 'P1', 'VERIFIED', 'R3', 'The page declares more than one canonical URL.', 'Keep exactly one canonical link; search engines may ignore conflicting ones.', $khseo_g . 'crawling-indexing/consolidate-duplicate-urls' ),
	$khseo_rule( 'SEO-CANON-004', 'indexability', 'P1', 'VERIFIED', 'R3', 'The canonical URL is empty, relative or not a valid http(s) URL.', 'Use an absolute https URL as the canonical.', $khseo_g . 'crawling-indexing/consolidate-duplicate-urls' ),
	$khseo_rule( 'SEO-CANON-005', 'indexability', 'P1', 'OBSERVED', 'R3', 'The canonical URL points to another host.', 'Confirm the cross-domain canonical is intended; it hands indexing to the other site.', $khseo_g . 'crawling-indexing/consolidate-duplicate-urls' ),
	$khseo_rule( 'SEO-CANON-006', 'indexability', 'P1', 'VERIFIED', 'R3', 'The canonical target redirects or returns an error status (only checked when the target was in the audit scope).', 'Point the canonical at a final URL that returns 200.', $khseo_g . 'crawling-indexing/consolidate-duplicate-urls' ),
	$khseo_rule( 'SEO-CANON-007', 'indexability', 'P1', 'VERIFIED', 'R3', 'The page is noindex but also canonicalises to another URL (conflicting signals).', 'Use either noindex or a canonical to another URL, not both.', $khseo_g . 'crawling-indexing/consolidate-duplicate-urls' ),
	$khseo_rule( 'SEO-CANON-008', 'indexability', 'P2', 'VERIFIED', 'R3', 'The canonical uses http:// although the page is served over https://.', 'Use the https URL as the canonical.', $khseo_g . 'crawling-indexing/consolidate-duplicate-urls' ),

	// --- Technical ---------------------------------------------------------------
	$khseo_rule( 'SEO-HTTP-001', 'technical', 'P0', 'VERIFIED', 'R2', 'The page returned an error status (4xx or 5xx).', 'Restore the page or redirect it to the right URL; remove links to it otherwise.', $khseo_g . 'crawling-indexing/http-network-errors' ),
	$khseo_rule( 'SEO-HTTP-002', 'technical', 'P2', 'VERIFIED', 'R2', 'The URL redirects before reaching the final page.', 'Link to the final URL directly; avoid redirect chains.', $khseo_g . 'crawling-indexing/301-redirects' ),
	$khseo_rule( 'SEO-HTTP-003', 'technical', 'P1', 'VERIFIED', 'R2', 'The page is not served as HTML.', 'Serve pages with Content-Type text/html.', $khseo_g . 'crawling-indexing/http-network-errors' ),
	$khseo_rule( 'SEO-HTTPS-001', 'technical', 'P1', 'VERIFIED', 'R4', 'The site URL does not use HTTPS.', 'Serve the site over HTTPS and update the WordPress and Site Address.', 'https://developer.wordpress.org/advanced-administration/security/https/' ),
	$khseo_rule( 'SEO-HTTPS-002', 'technical', 'P1', 'VERIFIED', 'R4', 'The page was finally served over plain http://.', 'Redirect http to https and serve every page over HTTPS.', $khseo_g . 'appearance/page-experience' ),
	$khseo_rule( 'SEO-LANG-001', 'technical', 'P2', 'VERIFIED', 'R2', 'The <html> element has no lang attribute or an invalid one.', 'Declare the page language, e.g. <html lang="en">.', 'https://developer.mozilla.org/docs/Web/HTML/Global_attributes/lang' ),
	$khseo_rule( 'SEO-LANG-002', 'technical', 'P3', 'OBSERVED', 'R2', 'Language indicators disagree (html lang, Content-Language, og:locale).', 'Make the declared languages consistent.', 'https://developer.mozilla.org/docs/Web/HTML/Global_attributes/lang' ),
	$khseo_rule( 'SEO-CHARSET-001', 'technical', 'P3', 'VERIFIED', 'R2', 'No character encoding is declared (HTTP header or <meta charset>).', 'Declare UTF-8 with <meta charset="utf-8">.', 'https://developer.mozilla.org/docs/Web/HTML/Element/meta#charset' ),

	// --- Metadata ----------------------------------------------------------------
	$khseo_rule( 'SEO-TITLE-001', 'metadata', 'P1', 'VERIFIED', 'R1', 'The page has no <title> element or it is empty.', 'Add a descriptive, unique title that states the page topic.', $khseo_g . 'appearance/title-link' ),
	$khseo_rule( 'SEO-TITLE-002', 'metadata', 'P2', 'VERIFIED', 'R2', 'The title is identical on another analysed URL.', 'Make each indexable page title unique to its content.', $khseo_g . 'appearance/title-link' ),
	$khseo_rule( 'SEO-TITLE-003', 'metadata', 'P2', 'VERIFIED', 'R2', 'The page has more than one <title> element.', 'Keep exactly one <title>; themes and plugins sometimes both add one.', $khseo_g . 'appearance/title-link' ),
	$khseo_rule( 'SEO-TITLE-004', 'metadata', 'P3', 'VERIFIED', 'R1', 'The title is longer than 60 characters (character count, not pixel width).', 'Put the most important words first; long titles are often shortened in results.', $khseo_g . 'appearance/title-link' ),
	$khseo_rule( 'SEO-TITLE-005', 'metadata', 'P3', 'VERIFIED', 'R1', 'The title is shorter than 10 characters.', 'Use a title that describes the page, not just a single word.', $khseo_g . 'appearance/title-link' ),
	$khseo_rule( 'SEO-META-001', 'metadata', 'P2', 'VERIFIED', 'R1', 'The page has no meta description or it is empty.', 'Write a meta description that summarises the page for searchers.', $khseo_g . 'appearance/snippet' ),
	$khseo_rule( 'SEO-META-002', 'metadata', 'P3', 'VERIFIED', 'R2', 'The meta description is identical on another analysed URL.', 'Write a description specific to each page.', $khseo_g . 'appearance/snippet' ),
	$khseo_rule( 'SEO-META-003', 'metadata', 'P2', 'VERIFIED', 'R2', 'The page has more than one meta description.', 'Keep exactly one meta description.', $khseo_g . 'appearance/snippet' ),
	$khseo_rule( 'SEO-META-004', 'metadata', 'P3', 'VERIFIED', 'R1', 'The meta description is shorter than 50 characters.', 'Expand it to summarise the page in a sentence or two.', $khseo_g . 'appearance/snippet' ),
	$khseo_rule( 'SEO-META-005', 'metadata', 'P3', 'VERIFIED', 'R1', 'The meta description is longer than 160 characters.', 'Shorten it; long descriptions are usually cut off.', $khseo_g . 'appearance/snippet' ),
	$khseo_rule( 'SEO-META-006', 'metadata', 'P3', 'VERIFIED', 'R1', 'The meta description contains HTML markup.', 'Use plain text in the meta description.', $khseo_g . 'appearance/snippet' ),

	// --- Content (headings) ------------------------------------------------------
	$khseo_rule( 'SEO-H1-001', 'content', 'P2', 'VERIFIED', 'R2', 'The page has no <h1> heading.', 'Give the page one main heading that states its topic.', 'https://developer.mozilla.org/docs/Web/HTML/Element/Heading_Elements' ),
	$khseo_rule( 'SEO-H1-002', 'content', 'P3', 'VERIFIED', 'R2', 'The page has more than one <h1> heading.', 'Multiple H1s are valid HTML; consider one clear main heading for readability.', 'https://developer.mozilla.org/docs/Web/HTML/Element/Heading_Elements' ),
	$khseo_rule( 'SEO-HEAD-001', 'content', 'P3', 'VERIFIED', 'R2', 'Heading levels skip a level (e.g. h2 followed by h4).', 'Use heading levels in order for accessible structure.', 'https://developer.mozilla.org/docs/Web/HTML/Element/Heading_Elements' ),
	$khseo_rule( 'SEO-HEAD-002', 'content', 'P3', 'VERIFIED', 'R2', 'The page contains empty headings.', 'Remove empty headings or give them text.', 'https://developer.mozilla.org/docs/Web/HTML/Element/Heading_Elements' ),

	// --- Links -------------------------------------------------------------------
	$khseo_rule( 'SEO-LINK-001', 'links', 'P2', 'VERIFIED', 'R2', 'Links have no accessible text (no text, aria-label or image alt).', 'Give every link descriptive text.', $khseo_g . 'crawling-indexing/links-crawlable' ),
	$khseo_rule( 'SEO-LINK-002', 'links', 'P1', 'VERIFIED', 'R2', 'The page links to an internal URL that returned an error status in this audit.', 'Fix or remove links to broken pages.', $khseo_g . 'crawling-indexing/links-crawlable' ),
	$khseo_rule( 'SEO-LINK-003', 'links', 'P3', 'VERIFIED', 'R2', 'The page has no links to other pages on the site.', 'Add relevant internal links so visitors and crawlers can continue.', $khseo_g . 'crawling-indexing/links-crawlable' ),

	// --- Images ------------------------------------------------------------------
	$khseo_rule( 'SEO-IMG-001', 'images', 'P2', 'VERIFIED', 'R2', 'A content image has no alt attribute.', 'Describe the image in its alt text, or use alt="" if it is decorative. KHSEO never writes alt text without your approval.', $khseo_g . 'appearance/google-images' ),
	$khseo_rule( 'SEO-IMG-002', 'images', 'P3', 'VERIFIED', 'R2', 'Images have no width/height attributes (layout can shift while loading).', 'Add width and height attributes to images.', 'https://web.dev/articles/optimize-cls' ),

	// --- Social metadata ---------------------------------------------------------
	$khseo_rule( 'SEO-OG-001', 'social', 'P3', 'VERIFIED', 'R1', 'Core Open Graph tags are missing (og:title, og:type, og:image, og:url).', 'Add the missing Open Graph tags so shared links have a title and image.', 'https://ogp.me/' ),
	$khseo_rule( 'SEO-OG-002', 'social', 'P3', 'VERIFIED', 'R2', 'An Open Graph property is declared more than once with different values.', 'Declare each property once (arrays such as og:image are allowed but should be intentional).', 'https://ogp.me/' ),
	$khseo_rule( 'SEO-OG-003', 'social', 'P3', 'VERIFIED', 'R1', 'og:url or og:image is not an absolute http(s) URL.', 'Use absolute URLs for og:url and og:image.', 'https://ogp.me/' ),
	$khseo_rule( 'SEO-TW-001', 'social', 'P3', 'VERIFIED', 'R1', 'twitter:card is missing or not a recognised card type.', 'Add <meta name="twitter:card" content="summary_large_image"> (or summary).', 'https://developer.x.com/en/docs/x-for-websites/cards/overview/markup' ),

	// --- Structured data ---------------------------------------------------------
	$khseo_rule( 'SEO-SCHEMA-001', 'structured_data', 'P1', 'VERIFIED', 'R2', 'A JSON-LD block is not valid JSON.', 'Fix the JSON syntax; invalid blocks are ignored by search engines.', $khseo_g . 'appearance/structured-data/intro-structured-data' ),
	$khseo_rule( 'SEO-SCHEMA-002', 'structured_data', 'P2', 'VERIFIED', 'R2', 'A JSON-LD entity has no @type, or the block has no schema.org @context.', 'Add "@context": "https://schema.org" and an @type.', $khseo_g . 'appearance/structured-data/intro-structured-data' ),
	$khseo_rule( 'SEO-SCHEMA-003', 'structured_data', 'P2', 'VERIFIED', 'R2', 'A recognised entity is missing properties KHSEO checks as required.', 'Add the missing properties using facts that are visible on the page; never invent them.', $khseo_g . 'appearance/structured-data/search-gallery' ),
	$khseo_rule( 'SEO-SCHEMA-004', 'structured_data', 'P3', 'VERIFIED', 'R2', 'The same entity (@id) is declared with conflicting values.', 'Declare each entity once, or keep its properties consistent.', 'https://schema.org/docs/datamodel.html' ),
	$khseo_rule( 'SEO-SCHEMA-005', 'structured_data', 'P3', 'OBSERVED', 'R2', 'An @type is not in the set of types KHSEO checks (it may still be valid schema.org).', 'No action needed if the type is correct; KHSEO only validates common types.', 'https://schema.org/docs/full.html' ),
	$khseo_rule( 'SEO-SCHEMA-006', 'structured_data', 'P2', 'INFERRED', 'R2', 'A structured-data name or headline does not appear in the page text.', 'Structured data must describe content visible on the page.', $khseo_g . 'appearance/structured-data/sd-policies' ),
	$khseo_rule( 'SEO-SCHEMA-007', 'structured_data', 'P1', 'INFERRED', 'R2', 'Rating or review markup exists but no matching rating/review text is visible on the page.', 'Only mark up reviews and ratings that visitors can see; never add fake ones.', $khseo_g . 'appearance/structured-data/sd-policies' ),
	$khseo_rule( 'SEO-SCHEMA-008', 'structured_data', 'P3', 'VERIFIED', 'R2', 'The page has no structured data.', 'Consider Organization/WebSite on the home page and Article/Product where they truthfully apply.', $khseo_g . 'appearance/structured-data/intro-structured-data' ),
	$khseo_rule( 'SEO-SCHEMA-009', 'structured_data', 'P3', 'VERIFIED', 'R2', 'An @id, url or sameAs value is not an absolute http(s) URL.', 'Use absolute URLs for entity identifiers and sameAs profiles.', 'https://schema.org/sameAs' ),
);
