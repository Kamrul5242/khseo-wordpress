<?php
/**
 * Checks that need more than one page: duplicates, broken internal links,
 * canonical targets and sitemap/indexability conflicts.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

/**
 * Only URLs fetched in THIS audit count as tested. Anything else is NOT TESTED,
 * never assumed fine or broken.
 */
final class CrossPageAnalyzer {

	public const SOURCE = 'pages fetched in this audit';

	/**
	 * Analyse page summaries.
	 *
	 * @param array<int, array<string, mixed>> $pages Summaries from PageAnalyzer.
	 * @return array<int, RuleResult>
	 */
	public static function analyze( array $pages ): array {
		$by_url = array();
		foreach ( $pages as $page ) {
			$by_url[ (string) $page['url'] ]     = $page;
			$by_url[ (string) $page['final'] ] ??= $page;
		}
		$indexable = array_filter( $pages, static fn ( array $p ): bool => 200 === (int) $p['status'] && ! $p['noindex'] && (bool) $p['html'] );
		$out       = array_merge(
			self::duplicates( $indexable, 'title', 'SEO-TITLE-002', 'title' ),
			self::duplicates( $indexable, 'desc', 'SEO-META-002', 'meta description' )
		);

		foreach ( $pages as $page ) {
			$url = (string) $page['url'];

			// Canonical target status (only if the target was fetched here).
			if ( null !== $page['canonical'] && $page['canonical'] !== $page['final'] ) {
				$target = $by_url[ $page['canonical'] ] ?? null;
				if ( null === $target ) {
					$out[] = RuleResult::notTested( 'SEO-CANON-006', $url, 'Canonical target ' . $page['canonical'] . ' was not in this audit.' );
				} elseif ( (int) $target['status'] >= 300 || $target['final'] !== $page['canonical'] ) {
					$out[] = RuleResult::fail( 'SEO-CANON-006', $url, 'Canonical target returned ' . $target['status'] . ( $target['final'] !== $page['canonical'] ? ' and redirects to ' . $target['final'] : '' ) . '.', self::SOURCE );
				} else {
					$out[] = RuleResult::pass( 'SEO-CANON-006', $url, 'Canonical target returned 200.', self::SOURCE );
				}
			}

			// Internal links whose targets were fetched in this audit.
			$checked = 0;
			$broken  = array();
			foreach ( (array) $page['links'] as $link ) {
				if ( isset( $by_url[ $link ] ) ) {
					++$checked;
					if ( (int) $by_url[ $link ]['status'] >= 400 ) {
						$broken[] = $link . ' (' . $by_url[ $link ]['status'] . ')';
					}
				}
			}
			$unchecked = count( (array) $page['links'] ) - $checked;
			if ( 0 === $checked ) {
				$out[] = RuleResult::notTested( 'SEO-LINK-002', $url, 'None of the ' . count( (array) $page['links'] ) . ' internal link target(s) were in this audit.' );
			} elseif ( array() !== $broken ) {
				$out[] = RuleResult::fail( 'SEO-LINK-002', $url, 'Broken: ' . implode( ', ', array_slice( $broken, 0, 5 ) ) . '. ' . $unchecked . ' other link target(s) not checked.', self::SOURCE );
			} else {
				$out[] = RuleResult::pass( 'SEO-LINK-002', $url, $checked . ' internal link target(s) checked, none broken; ' . $unchecked . ' not checked.', self::SOURCE );
			}

			// Sitemap entries must be final, indexable URLs.
			if ( $page['from_sitemap'] ) {
				$problems = array();
				if ( (int) $page['status'] >= 300 ) {
					$problems[] = 'status ' . $page['status'];
				}
				if ( $page['final'] !== $url ) {
					$problems[] = 'redirects to ' . $page['final'];
				}
				if ( $page['noindex'] ) {
					$problems[] = 'noindex';
				}
				$out[] = array() !== $problems
					? RuleResult::fail( 'SEO-SITEMAP-003', $url, 'Listed in the sitemap but: ' . implode( ', ', $problems ) . '.', self::SOURCE )
					: RuleResult::pass( 'SEO-SITEMAP-003', $url, 'Listed in the sitemap and indexable by page-level signals.', self::SOURCE );
			}
		}
		return $out;
	}

	/**
	 * Duplicate values among indexable pages.
	 *
	 * @param array<int, array<string, mixed>> $pages Indexable pages.
	 * @param string                           $field Summary field.
	 * @param string                           $rule  Rule id.
	 * @param string                           $label Human label.
	 * @return array<int, RuleResult>
	 */
	private static function duplicates( array $pages, string $field, string $rule, string $label ): array {
		$groups = array();
		foreach ( $pages as $page ) {
			$value = mb_strtolower( trim( (string) $page[ $field ] ) );
			if ( '' !== $value ) {
				$groups[ $value ][] = (string) $page['url'];
			}
		}
		$out = array();
		foreach ( $groups as $urls ) {
			foreach ( $urls as $url ) {
				$others = array_values( array_diff( $urls, array( $url ) ) );
				$out[]  = array() !== $others
					? RuleResult::fail( $rule, $url, 'Same ' . $label . ' as ' . count( $others ) . ' other URL(s): ' . implode( ', ', array_slice( $others, 0, 3 ) ), self::SOURCE )
					: RuleResult::pass( $rule, $url, 'Unique ' . $label . ' among ' . count( $pages ) . ' indexable page(s) analysed.', self::SOURCE );
			}
		}
		return $out;
	}
}
