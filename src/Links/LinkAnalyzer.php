<?php
/**
 * Link checks for one page, plus the internal targets used by cross-page checks.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Links;

use KHSEO\Audit\PageSnapshot;
use KHSEO\Audit\RuleResult as R;
use KHSEO\Audit\Url;

/**
 * Broken links are NOT decided here: a link is only "broken" when its target was
 * actually fetched in the same audit (see CrossPageAnalyzer). One page never
 * supports a site-wide broken-link claim.
 */
final class LinkAnalyzer {

	public const SOURCE      = 'fetched HTML';
	public const MAX_TARGETS = 200;

	/**
	 * Analyse one page.
	 *
	 * @param PageSnapshot $p Page.
	 * @return array{results: array<int, R>, internal: array<int, string>, stats: array<string, int>}
	 */
	public static function analyze( PageSnapshot $p ): array {
		$u = $p->url;
		if ( ! $p->is_html ) {
			return array(
				'results'  => array( R::notTested( 'SEO-LINK-001', $u, 'The response is not HTML.' ), R::notTested( 'SEO-LINK-003', $u, 'The response is not HTML.' ) ),
				'internal' => array(),
				'stats'    => array(),
			);
		}
		$self     = Url::normalize( $p->final_url ) ?? $p->final_url;
		$internal = array();
		$stats    = array(
			'total'     => 0,
			'internal'  => 0,
			'external'  => 0,
			'fragment'  => 0,
			'empty'     => 0,
			'nofollow'  => 0,
			'sponsored' => 0,
			'ugc'       => 0,
		);
		foreach ( $p->links as $link ) {
			$href = $link['href'];
			if ( '' === $href || 1 === preg_match( '#^(mailto|tel|javascript|data|sms):#i', $href ) ) {
				continue;
			}
			++$stats['total'];
			if ( '' === $link['text'] ) {
				++$stats['empty'];
			}
			foreach ( array( 'nofollow', 'sponsored', 'ugc' ) as $rel ) {
				$stats[ $rel ] += in_array( $rel, $link['rel'], true ) ? 1 : 0;
			}
			if ( str_starts_with( $href, '#' ) ) {
				++$stats['fragment'];
				continue;
			}
			$target = Url::absolute( $p->final_url, $href );
			if ( null === $target ) {
				continue;
			}
			if ( Url::sameHost( $target, $self ) ) {
				++$stats['internal'];
				if ( $target !== $self && count( $internal ) < self::MAX_TARGETS ) {
					$internal[ $target ] = true;
				}
			} else {
				++$stats['external'];
			}
		}
		$summary = sprintf( '%d links: %d internal, %d external, %d fragment-only, %d nofollow, %d sponsored, %d ugc.', $stats['total'], $stats['internal'], $stats['external'], $stats['fragment'], $stats['nofollow'], $stats['sponsored'], $stats['ugc'] );
		return array(
			'results'  => array(
				$stats['empty'] > 0
					? R::fail( 'SEO-LINK-001', $u, $stats['empty'] . ' link(s) without accessible text. ' . $summary, self::SOURCE )
					: R::pass( 'SEO-LINK-001', $u, 'Every link has text. ' . $summary, self::SOURCE ),
				array() === $internal
					? R::fail( 'SEO-LINK-003', $u, 'No links to other pages on this site. ' . $summary, self::SOURCE )
					: R::pass( 'SEO-LINK-003', $u, count( $internal ) . ' distinct internal link target(s).', self::SOURCE ),
			),
			'internal' => array_keys( $internal ),
			'stats'    => $stats,
		);
	}
}
