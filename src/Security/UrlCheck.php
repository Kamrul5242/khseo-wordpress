<?php
/**
 * Result of a UrlGuard check.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Security;

/**
 * Immutable allow/deny result. On allow, $ips are the validated addresses to pin.
 */
final class UrlCheck {

	/**
	 * Constructor.
	 *
	 * @param bool               $allowed Whether the URL may be fetched.
	 * @param string             $reason  Human-readable reason when denied.
	 * @param string             $scheme  Scheme.
	 * @param string             $host    Lower-cased host.
	 * @param int                $port    Port.
	 * @param array<int, string> $ips     Validated IPs.
	 */
	private function __construct(
		public readonly bool $allowed,
		public readonly string $reason,
		public readonly string $scheme = '',
		public readonly string $host = '',
		public readonly int $port = 0,
		public readonly array $ips = array()
	) {}

	/**
	 * Denied result.
	 *
	 * @param string $reason Why.
	 */
	public static function deny( string $reason ): self {
		return new self( false, $reason );
	}

	/**
	 * Allowed result.
	 *
	 * @param string             $scheme Scheme.
	 * @param string             $host   Host.
	 * @param int                $port   Port.
	 * @param array<int, string> $ips    Validated IPs.
	 */
	public static function allow( string $scheme, string $host, int $port, array $ips ): self {
		return new self( true, '', $scheme, $host, $port, $ips );
	}
}
