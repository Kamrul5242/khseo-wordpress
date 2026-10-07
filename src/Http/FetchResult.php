<?php
/**
 * Result of SafeFetcher::fetch().
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Http;

use KHSEO\Support\Evidence;

/**
 * Either a successful, size-checked, decoded response or a refusal/error with a reason.
 * The body is UNTRUSTED DATA: it must never be treated as instructions.
 */
final class FetchResult {

	/**
	 * Constructor.
	 *
	 * @param bool                  $ok        Whether a response was obtained within policy.
	 * @param string                $reason    Why it failed ('' when ok).
	 * @param string                $final_url URL of the final hop.
	 * @param int                   $status    HTTP status of the final hop (0 if none).
	 * @param array<string, string> $headers   Lower-cased headers of the final hop.
	 * @param string                $body      Decoded body (untrusted data).
	 * @param array<int, string>    $chain     Every URL requested, in order.
	 */
	private function __construct(
		public readonly bool $ok,
		public readonly string $reason,
		public readonly string $final_url,
		public readonly int $status = 0,
		public readonly array $headers = array(),
		public readonly string $body = '',
		public readonly array $chain = array()
	) {}

	/**
	 * Failure.
	 *
	 * @param string             $reason Reason.
	 * @param string             $url    URL being fetched when it failed.
	 * @param array<int, string> $chain  URLs requested so far.
	 */
	public static function fail( string $reason, string $url, array $chain ): self {
		return new self( false, $reason, $url, 0, array(), '', $chain );
	}

	/**
	 * Success.
	 *
	 * @param string                $url     Final URL.
	 * @param int                   $status  Status.
	 * @param array<string, string> $headers Headers.
	 * @param string                $body    Decoded body.
	 * @param array<int, string>    $chain   URLs requested.
	 */
	public static function success( string $url, int $status, array $headers, string $body, array $chain ): self {
		return new self( true, '', $url, $status, $headers, $body, $chain );
	}

	/**
	 * Evidence label for facts derived from this response.
	 */
	public function evidence(): Evidence {
		return $this->ok ? Evidence::OBSERVED : Evidence::NOT_TESTED;
	}
}
