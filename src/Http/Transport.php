<?php
/**
 * Low-level HTTP transport used by SafeFetcher.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Http;

/**
 * Sends ONE request (no redirect following) to an already-validated IP.
 * Implementations must connect to $pinned_ip only, never re-resolve the host.
 */
interface Transport {

	/**
	 * Perform a GET request.
	 *
	 * @param string      $url       Absolute URL (Host header / SNI come from it).
	 * @param string      $pinned_ip Validated IP to connect to.
	 * @param int         $port      Port.
	 * @param FetchPolicy $policy    Limits.
	 * @return TransportResponse
	 */
	public function get( string $url, string $pinned_ip, int $port, FetchPolicy $policy ): TransportResponse;
}
