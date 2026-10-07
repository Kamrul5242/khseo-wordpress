<?php
/**
 * KHSEO rule definitions — the single source of truth for SEO checks.
 *
 * Each row is validated by KHSEO\Rules\Rule. Engines (Phase 2+) evaluate these
 * rules; Phase 1 only registers and validates them.
 *
 * @package KHSEO
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && ! defined( 'KHSEO_TESTING' ) ) {
	exit;
}

return array(
	array(
		'id'             => 'SEO-TITLE-001',
		'category'       => 'metadata',
		'severity'       => 'P1',
		'condition'      => 'The page has no <title> element or it is empty.',
		'evidence'       => 'VERIFIED',
		'recommendation' => 'Add a descriptive, unique title that states the page topic.',
		'auto_fixable'   => true,
		'risk'           => 'R1',
		'reversible'     => true,
		'docs'           => 'https://developers.google.com/search/docs/appearance/title-link',
	),
	array(
		'id'             => 'SEO-TITLE-002',
		'category'       => 'metadata',
		'severity'       => 'P2',
		'condition'      => 'The title is duplicated on another indexable URL.',
		'evidence'       => 'VERIFIED',
		'recommendation' => 'Make each indexable page title unique to its content.',
		'auto_fixable'   => false,
		'risk'           => 'R2',
		'reversible'     => true,
		'docs'           => 'https://developers.google.com/search/docs/appearance/title-link',
	),
	array(
		'id'             => 'SEO-META-001',
		'category'       => 'metadata',
		'severity'       => 'P2',
		'condition'      => 'The page has no meta description.',
		'evidence'       => 'VERIFIED',
		'recommendation' => 'Write a meta description that summarises the page for searchers.',
		'auto_fixable'   => false,
		'risk'           => 'R1',
		'reversible'     => true,
		'docs'           => 'https://developers.google.com/search/docs/appearance/snippet',
	),
	array(
		'id'             => 'SEO-INDEX-001',
		'category'       => 'indexability',
		'severity'       => 'P0',
		'condition'      => 'The whole site discourages search engines (Settings → Reading).',
		'evidence'       => 'VERIFIED',
		'recommendation' => 'If the site is live, untick "Discourage search engines from indexing this site".',
		'auto_fixable'   => false,
		'risk'           => 'R3',
		'reversible'     => true,
		'docs'           => 'https://developer.wordpress.org/apis/options/#reading',
	),
	array(
		'id'             => 'SEO-INDEX-002',
		'category'       => 'indexability',
		'severity'       => 'P0',
		'condition'      => 'An important page has a noindex directive.',
		'evidence'       => 'VERIFIED',
		'recommendation' => 'Remove noindex if the page should appear in search results.',
		'auto_fixable'   => false,
		'risk'           => 'R2',
		'reversible'     => true,
		'docs'           => 'https://developers.google.com/search/docs/crawling-indexing/block-indexing',
	),
	array(
		'id'             => 'SEO-CANON-001',
		'category'       => 'indexability',
		'severity'       => 'P1',
		'condition'      => 'The canonical URL points to a different page than expected.',
		'evidence'       => 'OBSERVED',
		'recommendation' => 'Review whether the canonical target is the preferred URL.',
		'auto_fixable'   => false,
		'risk'           => 'R2',
		'reversible'     => true,
		'docs'           => 'https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls',
	),
	array(
		'id'             => 'SEO-HTTPS-001',
		'category'       => 'technical',
		'severity'       => 'P1',
		'condition'      => 'The site URL does not use HTTPS.',
		'evidence'       => 'VERIFIED',
		'recommendation' => 'Serve the site over HTTPS and update the WordPress and Site Address.',
		'auto_fixable'   => false,
		'risk'           => 'R4',
		'reversible'     => true,
		'docs'           => 'https://developer.wordpress.org/advanced-administration/security/https/',
	),
	array(
		'id'             => 'SEO-IMG-001',
		'category'       => 'images',
		'severity'       => 'P2',
		'condition'      => 'A content image has no alt attribute.',
		'evidence'       => 'VERIFIED',
		'recommendation' => 'Describe the image in its alt text, or use alt="" if it is decorative.',
		'auto_fixable'   => false,
		'risk'           => 'R1',
		'reversible'     => true,
		'docs'           => 'https://developers.google.com/search/docs/appearance/google-images',
	),
);
