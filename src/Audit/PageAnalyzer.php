<?php
/**
 * Runs every page-level analyzer over one snapshot.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use KHSEO\Images\ImageAnalyzer;
use KHSEO\Links\LinkAnalyzer;
use KHSEO\Metadata\MetadataAnalyzer;
use KHSEO\Schema\SchemaAnalyzer;
use KHSEO\Social\SocialAnalyzer;
use KHSEO\Technical\IndexabilityAnalyzer;
use KHSEO\Technical\TechnicalAnalyzer;

/**
 * Pure: snapshot in, results + a compact page summary out. The summary is what
 * cross-page checks use (titles, canonicals, link targets), so pages are parsed once.
 */
final class PageAnalyzer {

	/**
	 * Analyse a page.
	 *
	 * @param PageSnapshot $p            Page.
	 * @param AuditContext $ctx          Context.
	 * @param bool         $from_sitemap Whether the URL came from a sitemap.
	 * @return array{results: array<int, RuleResult>, summary: array<string, mixed>}
	 */
	public static function analyze( PageSnapshot $p, AuditContext $ctx, bool $from_sitemap = false ): array {
		$links   = LinkAnalyzer::analyze( $p );
		$results = array_merge(
			TechnicalAnalyzer::analyze( $p ),
			IndexabilityAnalyzer::analyze( $p, $ctx ),
			MetadataAnalyzer::analyze( $p ),
			$links['results'],
			ImageAnalyzer::analyze( $p ),
			SocialAnalyzer::analyze( $p ),
			SchemaAnalyzer::analyze( $p )
		);
		$canon   = array_values( array_unique( $p->canonicals ) );
		$summary = array(
			'url'          => $p->url,
			'final'        => Url::normalize( $p->final_url ) ?? $p->final_url,
			'status'       => $p->status,
			'html'         => $p->is_html,
			'noindex'      => $p->isNoindex(),
			'title'        => mb_substr( (string) ( array_values( array_filter( $p->titles ) )[0] ?? '' ), 0, 300 ),
			'desc'         => mb_substr( (string) ( array_values( array_filter( $p->descriptions ) )[0] ?? '' ), 0, 500 ),
			'canonical'    => 1 === count( $canon ) && 1 === preg_match( '#^https?://#i', $canon[0] ) ? Url::normalize( $canon[0] ) : null,
			'links'        => $links['internal'],
			'from_sitemap' => $from_sitemap,
		);
		return array(
			'results' => $results,
			'summary' => $summary,
		);
	}
}
