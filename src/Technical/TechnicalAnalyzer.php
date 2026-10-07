<?php
/**
 * HTTP, HTTPS, content type, redirects, language and charset checks.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Technical;

use KHSEO\Audit\PageSnapshot;
use KHSEO\Audit\RuleResult as R;
use KHSEO\Support\Evidence;

/**
 * Page-level technical checks from the HTTP response and HTML head.
 */
final class TechnicalAnalyzer {

	public const SOURCE_HTTP = 'HTTP response';
	public const SOURCE_HTML = 'fetched HTML';

	/**
	 * Analyse one page.
	 *
	 * @param PageSnapshot $p Page.
	 * @return array<int, R>
	 */
	public static function analyze( PageSnapshot $p ): array {
		$u   = $p->url;
		$out = array();

		$out[] = $p->status >= 400
			? R::fail( 'SEO-HTTP-001', $u, 'Returned HTTP ' . $p->status . '.', self::SOURCE_HTTP )
			: R::pass( 'SEO-HTTP-001', $u, 'Returned HTTP ' . $p->status . '.', self::SOURCE_HTTP );

		$hops  = count( $p->chain ) - 1;
		$out[] = $hops > 0
			? R::fail( 'SEO-HTTP-002', $u, sprintf( 'Redirected %d time(s): %s', $hops, implode( ' → ', array_slice( $p->chain, 0, 5 ) ) ), self::SOURCE_HTTP )
			: R::pass( 'SEO-HTTP-002', $u, 'No redirect.', self::SOURCE_HTTP );

		$out[] = $p->is_html
			? R::pass( 'SEO-HTTP-003', $u, 'Content-Type: ' . self::short( $p->content_type ), self::SOURCE_HTTP )
			: R::fail( 'SEO-HTTP-003', $u, 'Content-Type: ' . ( '' === $p->content_type ? '(none)' : self::short( $p->content_type ) ), self::SOURCE_HTTP );

		$out[] = str_starts_with( strtolower( $p->final_url ), 'https://' )
			? R::pass( 'SEO-HTTPS-002', $u, 'Final URL uses HTTPS.', self::SOURCE_HTTP )
			: R::fail( 'SEO-HTTPS-002', $u, 'Final URL uses plain HTTP: ' . $p->final_url, self::SOURCE_HTTP );

		if ( ! $p->is_html ) {
			foreach ( array( 'SEO-LANG-001', 'SEO-LANG-002', 'SEO-CHARSET-001' ) as $rule ) {
				$out[] = R::notTested( $rule, $u, 'The response is not HTML.' );
			}
			return $out;
		}

		if ( null === $p->html_lang || '' === $p->html_lang ) {
			$out[] = R::fail( 'SEO-LANG-001', $u, '<html> has no lang attribute.', self::SOURCE_HTML );
		} elseif ( ! self::validLang( $p->html_lang ) ) {
			$out[] = R::fail( 'SEO-LANG-001', $u, 'Invalid lang value: "' . self::short( $p->html_lang ) . '".', self::SOURCE_HTML );
		} else {
			$out[] = R::pass( 'SEO-LANG-001', $u, 'lang="' . $p->html_lang . '".', self::SOURCE_HTML );
		}

		$indicators = array_filter(
			array(
				'html lang'        => self::primary( (string) $p->html_lang ),
				'Content-Language' => self::primary( explode( ',', $p->content_language )[0] ),
				'og:locale'        => self::primary( str_replace( '_', '-', $p->open_graph['og:locale'][0] ?? '' ) ),
			)
		);
		$out[]      = count( array_unique( $indicators ) ) > 1
			? R::fail( 'SEO-LANG-002', $u, 'Languages differ: ' . self::pairs( $indicators ), self::SOURCE_HTML, Evidence::OBSERVED )
			: R::pass( 'SEO-LANG-002', $u, count( $indicators ) > 1 ? 'Language indicators agree: ' . self::pairs( $indicators ) : 'Fewer than two language indicators; nothing to compare.', self::SOURCE_HTML, Evidence::OBSERVED );

		$out[] = 'utf-8-assumed' === $p->charset
			? R::fail( 'SEO-CHARSET-001', $u, 'No charset in the Content-Type header or a <meta charset>.', self::SOURCE_HTML )
			: R::pass( 'SEO-CHARSET-001', $u, 'Charset: ' . $p->charset, self::SOURCE_HTML );

		return $out;
	}

	/**
	 * BCP 47-shaped language tag (structural check, not a registry lookup).
	 *
	 * @param string $tag Tag.
	 */
	public static function validLang( string $tag ): bool {
		return 1 === preg_match( '/^(?:[a-z]{2,3}|[a-z]{5,8})(?:-[a-z0-9]{1,8})*$/i', $tag ) || 1 === preg_match( '/^x-[a-z0-9]{1,8}$/i', $tag );
	}

	/**
	 * Primary language subtag, lower-cased.
	 *
	 * @param string $tag Tag.
	 */
	private static function primary( string $tag ): string {
		return strtolower( trim( explode( '-', trim( $tag ) )[0] ) );
	}

	/**
	 * "a: x, b: y".
	 *
	 * @param array<string, string> $pairs Pairs.
	 */
	private static function pairs( array $pairs ): string {
		return implode( ', ', array_map( static fn ( $k, $v ) => $k . ' = ' . $v, array_keys( $pairs ), $pairs ) );
	}

	/**
	 * Shorten untrusted values for observations.
	 *
	 * @param string $value Value.
	 */
	public static function short( string $value ): string {
		return mb_strlen( $value ) > 120 ? mb_substr( $value, 0, 120 ) . '…' : $value;
	}
}
