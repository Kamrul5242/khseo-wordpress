<?php
/**
 * The ONLY component allowed to fetch external URLs.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Http;

use KHSEO\Security\UrlGuard;

/**
 * Validate → pin to validated IP → request (no auto-redirect) → re-validate every
 * redirect hop → enforce size, content type and decompression limits.
 *
 * Engines must use this service instead of wp_remote_get(), curl or
 * file_get_contents() for anything external.
 */
final class SafeFetcher {

	/**
	 * Constructor.
	 *
	 * @param UrlGuard    $guard     SSRF guard.
	 * @param Transport   $transport Transport that honours the pinned IP.
	 * @param FetchPolicy $policy    Limits.
	 */
	public function __construct(
		private UrlGuard $guard,
		private Transport $transport,
		private FetchPolicy $policy = new FetchPolicy()
	) {}

	/**
	 * Fetch a URL under the policy. Never throws.
	 *
	 * @param string $url Absolute http(s) URL.
	 */
	public function fetch( string $url ): FetchResult {
		$chain = array();
		for ( $hop = 0; $hop <= $this->policy->max_redirects; $hop++ ) {
			$check = $this->guard->check( $url );
			if ( ! $check->allowed ) {
				return FetchResult::fail( 'Blocked: ' . $check->reason, $url, $chain );
			}
			$chain[]  = $url;
			$response = $this->transport->get( $url, $check->ips[0], $check->port, $this->policy );
			if ( '' !== $response->error ) {
				return FetchResult::fail( 'Request failed: ' . $response->error, $url, $chain );
			}

			if ( $response->status >= 300 && $response->status < 400 && '' !== $response->header( 'location' ) ) {
				$next = self::resolve( $url, $response->header( 'location' ) );
				if ( null === $next ) {
					return FetchResult::fail( 'Redirect target is not a valid URL.', $url, $chain );
				}
				$url = $next;
				continue;
			}

			if ( $response->truncated || strlen( $response->body ) > $this->policy->max_bytes ) {
				return FetchResult::fail( 'Response exceeded ' . $this->policy->max_bytes . ' bytes.', $url, $chain );
			}
			if ( '' !== $response->body && ! $this->policy->allowsType( $response->header( 'content-type' ) ) ) {
				return FetchResult::fail( 'Content type not allowed: ' . substr( $response->header( 'content-type' ), 0, 80 ), $url, $chain );
			}
			$body = self::decode( $response->body, $response->header( 'content-encoding' ), $this->policy->max_decoded );
			if ( null === $body ) {
				return FetchResult::fail( 'Compressed body is invalid or expands beyond ' . $this->policy->max_decoded . ' bytes.', $url, $chain );
			}
			return FetchResult::success( $url, $response->status, $response->headers, $body, $chain );
		}
		return FetchResult::fail( 'Too many redirects (limit ' . $this->policy->max_redirects . ').', $url, $chain );
	}

	/**
	 * Resolve a Location header against the current URL.
	 *
	 * @param string $base     Current absolute URL.
	 * @param string $location Location header value.
	 * @return string|null Absolute URL, or null if unusable.
	 */
	public static function resolve( string $base, string $location ): ?string {
		$location = trim( $location );
		if ( '' === $location || strlen( $location ) > 2048 || preg_match( '/[\x00-\x1F\x7F]/', $location ) ) {
			return null;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) ) {
			return $location; // Absolute (any scheme; the guard rejects non-http(s)).
		}
		$b = parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, runs without WordPress.
		if ( false === $b || ! isset( $b['scheme'], $b['host'] ) ) {
			return null;
		}
		$host = str_contains( $b['host'], ':' ) && ! str_starts_with( $b['host'], '[' ) ? '[' . $b['host'] . ']' : $b['host'];
		$auth = $b['scheme'] . '://' . $host . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( str_starts_with( $location, '//' ) ) {
			return $b['scheme'] . ':' . $location;
		}
		if ( str_starts_with( $location, '/' ) ) {
			return $auth . $location;
		}
		$path = $b['path'] ?? '/';
		$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
		return $auth . ( '' === $dir ? '/' : $dir ) . $location;
	}

	/**
	 * Decode a Content-Encoding with a hard output cap (gzip/deflate bomb guard).
	 *
	 * @param string $body     Raw body.
	 * @param string $encoding Content-Encoding header.
	 * @param int    $max      Maximum decoded bytes.
	 * @return string|null Decoded body, or null if invalid/too large/unsupported.
	 */
	public static function decode( string $body, string $encoding, int $max ): ?string {
		$encoding = strtolower( trim( $encoding ) );
		if ( '' === $encoding || 'identity' === $encoding ) {
			return strlen( $body ) <= $max ? $body : null;
		}
		$modes = array(
			'gzip'    => ZLIB_ENCODING_GZIP,
			'x-gzip'  => ZLIB_ENCODING_GZIP,
			'deflate' => ZLIB_ENCODING_DEFLATE,
		);
		if ( ! isset( $modes[ $encoding ] ) || '' === $body ) {
			return null;
		}
		$ctx = inflate_init( $modes[ $encoding ] );
		if ( false === $ctx ) {
			return null;
		}
		$out = '';
		foreach ( str_split( $body, 1024 ) as $chunk ) {
			$part = @inflate_add( $ctx, $chunk, ZLIB_SYNC_FLUSH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt input is reported as null.
			if ( false === $part ) {
				return null;
			}
			$out .= $part;
			if ( strlen( $out ) > $max ) {
				return null;
			}
		}
		return $out;
	}
}
