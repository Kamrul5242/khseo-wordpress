<?php
/**
 * Limits applied to every outbound fetch.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Http;

/**
 * Immutable limits. Engines may tighten them; nothing may disable them.
 */
final class FetchPolicy {

	/**
	 * Constructor.
	 *
	 * @param int                $connect_timeout  Seconds to establish the connection.
	 * @param int                $timeout          Seconds for the whole request.
	 * @param int                $max_bytes        Maximum raw (on-the-wire) body bytes.
	 * @param int                $max_decoded      Maximum body bytes after decompression (bomb guard).
	 * @param int                $max_redirects    Redirect hops followed (each one re-validated).
	 * @param array<int, string> $allowed_types    Allowed Content-Type media types.
	 * @param string             $user_agent       User-Agent sent.
	 * @param bool               $allow_downgrade  Follow HTTPS -> HTTP redirects (off: refused).
	 */
	public function __construct(
		public readonly int $connect_timeout = 5,
		public readonly int $timeout = 10,
		public readonly int $max_bytes = 2_000_000,
		public readonly int $max_decoded = 5_000_000,
		public readonly int $max_redirects = 3,
		public readonly array $allowed_types = array( 'text/html', 'application/xhtml+xml', 'text/plain', 'application/xml', 'text/xml', 'application/json', 'application/ld+json' ),
		public readonly string $user_agent = 'KHSEO/1 (+https://github.com/Kamrul5242/khseo-wordpress)',
		public readonly bool $allow_downgrade = false
	) {}

	/**
	 * Whether a Content-Type header value is allowed (parameters such as charset ignored).
	 *
	 * @param string $content_type Raw header value.
	 */
	public function allowsType( string $content_type ): bool {
		$media = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
		return in_array( $media, $this->allowed_types, true );
	}
}
