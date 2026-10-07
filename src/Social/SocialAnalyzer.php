<?php
/**
 * Open Graph and Twitter/X card checks.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Social;

use KHSEO\Audit\PageSnapshot;
use KHSEO\Audit\RuleResult as R;
use KHSEO\Technical\TechnicalAnalyzer;

/**
 * Checks the tags only. How Facebook, LinkedIn or X actually render a share is
 * NOT TESTED: that would need their own preview tools.
 */
final class SocialAnalyzer {

	public const SOURCE     = 'meta tags in fetched HTML';
	public const CORE_OG    = array( 'og:title', 'og:type', 'og:image', 'og:url' );
	public const CARD_TYPES = array( 'summary', 'summary_large_image', 'app', 'player' );
	private const MULTI_OK  = array( 'og:image', 'og:image:url', 'og:image:secure_url', 'og:image:width', 'og:image:height', 'og:image:alt', 'og:image:type', 'og:locale:alternate', 'og:video', 'og:audio', 'article:tag', 'article:author' );

	/**
	 * Analyse one page.
	 *
	 * @param PageSnapshot $p Page.
	 * @return array<int, R>
	 */
	public static function analyze( PageSnapshot $p ): array {
		$u = $p->url;
		if ( ! $p->is_html ) {
			return array_map( static fn ( string $r ): R => R::notTested( $r, $u, 'The response is not HTML.' ), array( 'SEO-OG-001', 'SEO-OG-002', 'SEO-OG-003', 'SEO-TW-001' ) );
		}
		$og      = $p->open_graph;
		$missing = array_values( array_filter( self::CORE_OG, static fn ( string $k ): bool => '' === trim( (string) ( $og[ $k ][0] ?? '' ) ) ) );
		$out     = array(
			array() !== $missing
				? R::fail( 'SEO-OG-001', $u, 'Missing: ' . implode( ', ', $missing ) . '.', self::SOURCE )
				: R::pass( 'SEO-OG-001', $u, 'og:title, og:type, og:image and og:url present.', self::SOURCE ),
		);

		$conflicts = array();
		foreach ( $og as $property => $values ) {
			if ( ! in_array( $property, self::MULTI_OK, true ) && count( array_unique( $values ) ) > 1 ) {
				$conflicts[] = $property . ' (' . count( $values ) . ' values)';
			}
		}
		$out[] = array() !== $conflicts
			? R::fail( 'SEO-OG-002', $u, 'Conflicting: ' . implode( ', ', array_slice( $conflicts, 0, 5 ) ), self::SOURCE )
			: R::pass( 'SEO-OG-002', $u, 'No conflicting Open Graph values.', self::SOURCE );

		$bad = array();
		foreach ( array( 'og:url', 'og:image' ) as $key ) {
			foreach ( $og[ $key ] ?? array() as $value ) {
				if ( '' !== $value && 1 !== preg_match( '#^https?://[^\s/]+#i', $value ) ) {
					$bad[] = $key . ' = "' . TechnicalAnalyzer::short( $value ) . '"';
				}
			}
		}
		$out[] = array() !== $bad
			? R::fail( 'SEO-OG-003', $u, 'Not absolute: ' . implode( '; ', array_slice( $bad, 0, 3 ) ), self::SOURCE )
			: R::pass( 'SEO-OG-003', $u, 'og:url and og:image are absolute (or absent).', self::SOURCE );

		$card  = strtolower( trim( (string) ( $p->twitter['twitter:card'][0] ?? '' ) ) );
		$out[] = in_array( $card, self::CARD_TYPES, true )
			? R::pass( 'SEO-TW-001', $u, 'twitter:card = ' . $card . '.', self::SOURCE )
			: R::fail( 'SEO-TW-001', $u, '' === $card ? 'No twitter:card.' : 'Unrecognised twitter:card "' . TechnicalAnalyzer::short( $card ) . '".', self::SOURCE );
		return $out;
	}
}
