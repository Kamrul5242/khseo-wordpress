<?php
/**
 * URL helpers: normalisation for de-duplication and same-origin tests.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use KHSEO\Http\SafeFetcher;
use KHSEO\Security\UrlGuard;

/**
 * Pure functions; no WordPress needed.
 */
final class Url {

	/**
	 * Normalise for comparison: lower-case scheme/host, default port removed,
	 * dot segments resolved, fragment dropped, empty path = "/". Query kept as-is.
	 *
	 * @param string $url URL.
	 * @return string|null Normalised URL, or null if not an absolute http(s) URL.
	 */
	public static function normalize( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return null;
		}
		$p = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class.
		if ( false === $p || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $p['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}
		$host    = rtrim( strtolower( $p['host'] ), '.' );
		$port    = isset( $p['port'] ) ? (int) $p['port'] : null;
		$default = 'https' === $scheme ? 443 : 80;
		$out     = $scheme . '://' . $host . ( null !== $port && $port !== $default ? ':' . $port : '' );
		$out    .= SafeFetcher::removeDotSegments( '' === ( $p['path'] ?? '' ) ? '/' : (string) $p['path'] );
		return isset( $p['query'] ) && '' !== $p['query'] ? $out . '?' . $p['query'] : $out;
	}

	/**
	 * Resolve a (possibly relative) reference found in a page against the page URL.
	 *
	 * @param string $base Page URL.
	 * @param string $ref  href/src value.
	 * @return string|null Absolute normalised http(s) URL, or null.
	 */
	public static function absolute( string $base, string $ref ): ?string {
		$resolved = SafeFetcher::resolve( $base, $ref );
		return null === $resolved ? null : self::normalize( $resolved );
	}

	/**
	 * Whether two URLs share scheme, host and port.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 */
	public static function sameOrigin( string $a, string $b ): bool {
		$oa = UrlGuard::origin( $a );
		return '' !== $oa && UrlGuard::origin( $b ) === $oa;
	}

	/**
	 * Whether two URLs share the host (any scheme/port).
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 */
	public static function sameHost( string $a, string $b ): bool {
		$ha = strtolower( (string) parse_url( $a, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class.
		return '' !== $ha && strtolower( (string) parse_url( $b, PHP_URL_HOST ) ) === $ha; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class.
	}
}
