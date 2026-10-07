<?php
/**
 * Robots directives, robots.txt access and canonical analysis.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Technical;

use KHSEO\Audit\AuditContext;
use KHSEO\Audit\PageSnapshot;
use KHSEO\Audit\RuleResult as R;
use KHSEO\Audit\Url;
use KHSEO\Support\Evidence;

/**
 * Says what page-level signals indicate. It never claims a page IS indexed by
 * Google: that needs Search Console data, which this engine does not have.
 */
final class IndexabilityAnalyzer {

	public const SOURCE = 'robots meta / X-Robots-Tag';

	/**
	 * Analyse one page.
	 *
	 * @param PageSnapshot $p   Page.
	 * @param AuditContext $ctx Context.
	 * @return array<int, R>
	 */
	public static function analyze( PageSnapshot $p, AuditContext $ctx ): array {
		$u          = $p->url;
		$out        = array();
		$directives = $p->robotsDirectives();
		$shown      = array() === $directives ? 'none' : implode( ', ', $directives );

		$out[] = $p->isNoindex()
			? R::fail( 'SEO-INDEX-002', $u, 'noindex found (directives: ' . $shown . ').', self::SOURCE )
			: R::pass( 'SEO-INDEX-002', $u, 'No noindex directive (directives: ' . $shown . ').', self::SOURCE );

		$out[] = array_intersect( array( 'nofollow', 'none' ), $directives )
			? R::fail( 'SEO-INDEX-003', $u, 'Page-level nofollow found.', self::SOURCE )
			: R::pass( 'SEO-INDEX-003', $u, 'No page-level nofollow.', self::SOURCE );

		$limits = array_values(
			array_filter(
				$directives,
				static fn ( string $d ): bool => in_array( $d, array( 'nosnippet', 'noarchive' ), true ) || str_starts_with( $d, 'unavailable_after' ) || 1 === preg_match( '/^max-snippet:\s*0$/', $d )
			)
		);
		$out[]  = array() !== $limits
			? R::fail( 'SEO-INDEX-004', $u, 'Restrictions: ' . implode( ', ', $limits ), self::SOURCE )
			: R::pass( 'SEO-INDEX-004', $u, 'No snippet/archive restrictions.', self::SOURCE );

		$out[] = self::robotsTxt( $p, $ctx );

		if ( $p->is_html ) {
			array_push( $out, ...self::canonical( $p ) );
		} else {
			$out[] = R::notTested( 'SEO-CANON-002', $u, 'The response is not HTML.' );
		}
		return $out;
	}

	/**
	 * Whether robots.txt lets Googlebot fetch the URL.
	 *
	 * @param PageSnapshot $p   Page.
	 * @param AuditContext $ctx Context.
	 */
	private static function robotsTxt( PageSnapshot $p, AuditContext $ctx ): R {
		return match ( $ctx->robots_state ) {
			AuditContext::ROBOTS_OK          => null !== $ctx->robots && ! $ctx->robots->isAllowed( 'googlebot', $p->url )
				? R::fail( 'SEO-ROBOTS-002', $p->url, 'Disallowed for Googlebot by robots.txt.', 'robots.txt' )
				: R::pass( 'SEO-ROBOTS-002', $p->url, 'Allowed for Googlebot by robots.txt.', 'robots.txt' ),
			AuditContext::ROBOTS_ABSENT      => R::pass( 'SEO-ROBOTS-002', $p->url, 'No robots.txt (4xx): crawling is not restricted.', 'robots.txt' ),
			AuditContext::ROBOTS_UNAVAILABLE => R::unknown( 'SEO-ROBOTS-002', $p->url, 'robots.txt could not be read; crawlers may treat the site as disallowed (see SEO-ROBOTS-001).', 'robots.txt' ),
			default                          => R::notTested( 'SEO-ROBOTS-002', $p->url, 'robots.txt was not fetched in this audit.' ),
		};
	}

	/**
	 * Canonical checks.
	 *
	 * @param PageSnapshot $p Page.
	 * @return array<int, R>
	 */
	private static function canonical( PageSnapshot $p ): array {
		$u    = $p->url;
		$src  = 'rel=canonical in fetched HTML';
		$list = array_values( array_unique( $p->canonicals ) );
		if ( array() === $list ) {
			return array( R::fail( 'SEO-CANON-002', $u, 'No <link rel="canonical"> found.', $src ) );
		}
		$out   = array( R::pass( 'SEO-CANON-002', $u, 'Canonical present.', $src ) );
		$out[] = count( $list ) > 1
			? R::fail( 'SEO-CANON-003', $u, count( $list ) . ' different canonical URLs: ' . TechnicalAnalyzer::short( implode( ' | ', $list ) ), $src )
			: R::pass( 'SEO-CANON-003', $u, 'One canonical URL.', $src );

		$raw      = $list[0];
		$absolute = 1 === preg_match( '#^https?://#i', $raw ) ? Url::normalize( $raw ) : null;
		if ( null === $absolute ) {
			$out[] = R::fail( 'SEO-CANON-004', $u, 'Canonical is "' . TechnicalAnalyzer::short( $raw ) . '" (empty, relative or invalid).', $src );
			return $out;
		}
		$out[] = R::pass( 'SEO-CANON-004', $u, 'Canonical is an absolute URL.', $src );

		$final = Url::normalize( $p->final_url ) ?? $p->final_url;
		if ( ! Url::sameHost( $absolute, $final ) ) {
			$out[] = R::fail( 'SEO-CANON-005', $u, 'Canonical points to another host: ' . $absolute, $src, Evidence::OBSERVED );
		} else {
			$out[] = R::pass( 'SEO-CANON-005', $u, 'Canonical is on the same host.', $src, Evidence::OBSERVED );
		}
		$self  = $absolute === $final;
		$out[] = $self
			? R::pass( 'SEO-CANON-001', $u, 'Self-referencing canonical.', $src, Evidence::OBSERVED )
			: R::fail( 'SEO-CANON-001', $u, 'Canonical points to ' . $absolute . ' (page is ' . $final . ').', $src, Evidence::OBSERVED );
		$out[] = $p->isNoindex() && ! $self
			? R::fail( 'SEO-CANON-007', $u, 'noindex and a canonical to another URL on the same page.', $src )
			: R::pass( 'SEO-CANON-007', $u, 'No noindex/canonical conflict.', $src );
		$out[] = str_starts_with( $final, 'https://' ) && str_starts_with( $absolute, 'http://' )
			? R::fail( 'SEO-CANON-008', $u, 'HTTPS page with an http:// canonical.', $src )
			: R::pass( 'SEO-CANON-008', $u, 'Canonical scheme matches the page.', $src );
		return $out;
	}
}
