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
			// After the full guard check: refuse an HTTPS -> HTTP downgrade on a redirect hop.
			$previous = end( $chain );
			if ( false !== $previous && ! $this->policy->allow_downgrade && str_starts_with( strtolower( $previous ), 'https:' ) && str_starts_with( strtolower( $url ), 'http:' ) ) {
				return FetchResult::fail( 'Refused redirect from HTTPS to plain HTTP.', $url, $chain );
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
	 * Resolve a Location header against the current URL (RFC 3986 section 5.2).
	 *
	 * Handles absolute, protocol-relative, absolute-path, relative-path ("../", "./"),
	 * query-only and fragment-only references. Fragments are dropped (never sent).
	 * The result is NOT trusted: SafeFetcher passes it back through the full UrlGuard.
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
		$location = explode( '#', $location, 2 )[0];
		$b        = parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, runs without WordPress.
		if ( false === $b || ! isset( $b['scheme'], $b['host'] ) ) {
			return null;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) ) {
			$r = parse_url( $location ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class.
			if ( false === $r || ! isset( $r['scheme'] ) ) {
				return null;
			}
			// Never rebuild a URL that carries credentials: return it as-is so the guard refuses it.
			if ( ! isset( $r['host'] ) || isset( $r['user'] ) || isset( $r['pass'] ) ) {
				return $location; // e.g. javascript:, data: — the guard rejects the scheme.
			}
			return self::build( $r, self::removeDotSegments( $r['path'] ?? '/' ) );
		}
		if ( str_starts_with( $location, '//' ) ) {
			return self::resolve( $base, $b['scheme'] . ':' . $location );
		}
		$base_path = $b['path'] ?? '/';
		if ( '' === $location ) {
			$path  = $base_path;
			$query = $b['query'] ?? null;
		} elseif ( str_starts_with( $location, '?' ) ) {
			$path  = $base_path;
			$query = substr( $location, 1 );
		} else {
			[ $rel_path, $query ] = array_pad( explode( '?', $location, 2 ), 2, null );
			$rel_path             = (string) $rel_path;
			$path                 = str_starts_with( $rel_path, '/' )
				? $rel_path
				: substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 ) . $rel_path;
		}
		$parts          = $b;
		$parts['query'] = $query;
		return self::build( $parts, self::removeDotSegments( '' === $path ? '/' : $path ) );
	}

	/**
	 * RFC 3986 5.2.4 remove_dot_segments. Percent-encoded dots are NOT decoded (they are not dot segments).
	 *
	 * @param string $path Path.
	 */
	public static function removeDotSegments( string $path ): string {
		$out = array();
		foreach ( explode( '/', $path ) as $i => $segment ) {
			if ( '..' === $segment ) {
				if ( count( $out ) > 1 ) {
					array_pop( $out );
				}
			} elseif ( '.' !== $segment || 0 === $i ) {
				$out[] = $segment;
			}
		}
		$last = substr( $path, -3 );
		if ( '/..' === $last || '/.' === substr( $path, -2 ) ) {
			$out[] = '';
		}
		$result = implode( '/', $out );
		return str_starts_with( $result, '/' ) ? $result : '/' . $result;
	}

	/**
	 * Rebuild an absolute URL from parse_url() parts with a given path.
	 *
	 * @param array<string, mixed> $p    Parts (scheme, host, optional port and query).
	 * @param string               $path Normalised path.
	 */
	private static function build( array $p, string $path ): string {
		$host = (string) $p['host'];
		$host = str_contains( $host, ':' ) && ! str_starts_with( $host, '[' ) ? '[' . $host . ']' : $host;
		$url  = strtolower( (string) $p['scheme'] ) . '://' . $host . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' ) . $path;
		return isset( $p['query'] ) ? $url . '?' . $p['query'] : $url;
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
