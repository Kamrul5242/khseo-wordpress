<?php
/**
 * Raw response from a Transport.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Http;

/**
 * One HTTP response, or a transport error.
 */
final class TransportResponse {

	/**
	 * Constructor.
	 *
	 * @param int                   $status    HTTP status (0 on transport error).
	 * @param array<string, string> $headers   Lower-cased header names.
	 * @param string                $body      Raw body (possibly compressed).
	 * @param bool                  $truncated True if the body hit the byte limit.
	 * @param string                $error     Transport error message ('' if none).
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers = array(),
		public readonly string $body = '',
		public readonly bool $truncated = false,
		public readonly string $error = ''
	) {}

	/**
	 * Transport failure.
	 *
	 * @param string $error Message (must not contain secrets).
	 */
	public static function failure( string $error ): self {
		return new self( 0, array(), '', false, $error );
	}

	/**
	 * Header value or ''.
	 *
	 * @param string $name Header name (any case).
	 */
	public function header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}
}
