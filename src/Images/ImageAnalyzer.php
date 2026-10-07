<?php
/**
 * Image alt and dimension checks.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Images;

use KHSEO\Audit\PageSnapshot;
use KHSEO\Audit\RuleResult as R;

/**
 * An empty alt (alt="") marks a valid decorative image and is NOT counted as missing.
 * KHSEO never writes alt text without the user's approval.
 */
final class ImageAnalyzer {

	public const SOURCE = 'fetched HTML';

	/**
	 * Analyse one page.
	 *
	 * @param PageSnapshot $p Page.
	 * @return array<int, R>
	 */
	public static function analyze( PageSnapshot $p ): array {
		$u = $p->url;
		if ( ! $p->is_html ) {
			return array( R::notTested( 'SEO-IMG-001', $u, 'The response is not HTML.' ), R::notTested( 'SEO-IMG-002', $u, 'The response is not HTML.' ) );
		}
		$total = count( $p->images );
		if ( 0 === $total ) {
			return array( R::pass( 'SEO-IMG-001', $u, 'No images on the page.', self::SOURCE ), R::pass( 'SEO-IMG-002', $u, 'No images on the page.', self::SOURCE ) );
		}
		$missing    = array_filter( $p->images, static fn ( array $i ): bool => null === $i['alt'] );
		$decorative = count( array_filter( $p->images, static fn ( array $i ): bool => '' === $i['alt'] ) );
		$unsized    = count( array_filter( $p->images, static fn ( array $i ): bool => ! $i['sized'] ) );
		$lazy       = count( array_filter( $p->images, static fn ( array $i ): bool => 'lazy' === $i['loading'] ) );
		$facts      = sprintf( '%d image(s); %d decorative (alt=""); %d lazy-loaded.', $total, $decorative, $lazy );
		$examples   = implode( ', ', array_slice( array_map( static fn ( array $i ): string => mb_substr( $i['src'], 0, 80 ), $missing ), 0, 3 ) );
		return array(
			array() !== $missing
				? R::fail( 'SEO-IMG-001', $u, count( $missing ) . ' image(s) without an alt attribute (e.g. ' . $examples . '). ' . $facts, self::SOURCE )
				: R::pass( 'SEO-IMG-001', $u, 'Every image has an alt attribute. ' . $facts, self::SOURCE ),
			$unsized > 0
				? R::fail( 'SEO-IMG-002', $u, $unsized . ' of ' . $total . ' image(s) without width and height attributes.', self::SOURCE )
				: R::pass( 'SEO-IMG-002', $u, 'Every image has width and height.', self::SOURCE ),
		);
	}
}
