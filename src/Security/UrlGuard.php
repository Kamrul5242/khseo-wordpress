<?php
/**
 * SSRF guard for every outbound URL KHSEO fetches.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Security;

/**
 * Validates a URL and every address its host resolves to.
 *
 * The resolved IPs are returned so the caller can pin the connection to them
 * (CURLOPT_RESOLVE) and so redirects can be re-validated hop by hop.
 */
final class UrlGuard {

	/**
	 * Blocked IPv4 ranges: private, loopback, link-local, CGNAT, documentation,
	 * benchmarking, multicast, reserved and broadcast.
	 */
	private const BLOCKED_V4 = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
	);

	/**
	 * Blocked IPv6 ranges. IPv4-mapped and NAT64 addresses are additionally
	 * unwrapped and checked against the IPv4 list.
	 */
	private const BLOCKED_V6 = array(
		'::/128',
		'::1/128',
		'100::/64',
		'2001::/32',
		'2001:db8::/32',
		'2002::/16',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	);

	/**
	 * DNS resolver: host => list of IP strings. Injectable for tests.
	 *
	 * @var callable(string): array<int, string>
	 */
	private $resolver;

	/**
	 * Allowed destination ports.
	 *
	 * @var array<int, int>
	 */
	private array $ports;

	/**
	 * Trusted origins ("scheme://host:port"), normally only this site's own home/site URL.
	 *
	 * @var array<int, string>
	 */
	private array $trusted_origins;

	/**
	 * Constructor.
	 *
	 * @param callable|null      $resolver Resolver returning IPs for a host; defaults to system DNS.
	 * @param array<int, int>    $ports           Allowed ports.
	 * @param array<int, string> $trusted_origins Exact origins exempt from the private-address rules
	 *                                            (this site, which may live on a private network).
	 *                                            Scheme, credential and control-character checks still apply,
	 *                                            the connection is still pinned, and every redirect is re-checked.
	 */
	public function __construct( ?callable $resolver = null, array $ports = array( 80, 443 ), array $trusted_origins = array() ) {
		$this->resolver        = $resolver ?? array( self::class, 'systemResolve' );
		$this->ports           = $ports;
		$this->trusted_origins = array_values( array_filter( array_map( array( self::class, 'origin' ), $trusted_origins ) ) );
	}

	/**
	 * Normalised origin "scheme://host:port" of a URL, or '' if it has none.
	 *
	 * @param string $url URL.
	 */
	public static function origin( string $url ): string {
		$p = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class.
		if ( false === $p || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $p['scheme'] );
		$port   = isset( $p['port'] ) ? (int) $p['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . rtrim( strtolower( trim( $p['host'], '[]' ) ), '.' ) . ':' . $port;
	}

	/**
	 * Check a URL.
	 *
	 * @param string $url Absolute URL.
	 */
	public function check( string $url ): UrlCheck {
		if ( strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return UrlCheck::deny( 'URL is too long or contains whitespace/control characters.' );
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, runs without WordPress; PHP >= 8.1 parse_url is consistent.
		if ( false === $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return UrlCheck::deny( 'URL is not absolute.' );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return UrlCheck::deny( 'Only http and https URLs are allowed.' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return UrlCheck::deny( 'URLs with embedded credentials are not allowed.' );
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( in_array( self::origin( $url ), $this->trusted_origins, true ) ) {
			return $this->checkTrusted( $scheme, $parts['host'], $port );
		}
		if ( ! in_array( $port, $this->ports, true ) ) {
			return UrlCheck::deny( 'Port ' . $port . ' is not allowed.' );
		}

		$is_ip_literal = str_starts_with( $parts['host'], '[' );
		// A trailing dot ("localhost.") is the same name; strip it before any comparison.
		$host = rtrim( strtolower( trim( $parts['host'], '[]' ) ), '.' );
		if ( '' === $host ) {
			return UrlCheck::deny( 'URL has an empty host.' );
		}
		if ( ! $is_ip_literal && ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return UrlCheck::deny( 'Host name contains characters that are not allowed (use punycode for international names).' );
		}
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return UrlCheck::deny( 'Local host names are not allowed.' );
		}
		$is_ip = false !== filter_var( $host, FILTER_VALIDATE_IP );
		// Decimal, hex, octal or shortened IPv4 ("2130706433", "0x7f.1", "127.1") are read as
		// addresses by curl and browsers but are not canonical IPs: refuse them outright.
		if ( ! $is_ip && preg_match( '/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/', $host ) ) {
			return UrlCheck::deny( 'Numeric host is not a standard IP address.' );
		}

		$ips = $is_ip ? array( $host ) : ( $this->resolver )( $host );
		if ( array() === $ips ) {
			return UrlCheck::deny( 'Host did not resolve.' );
		}
		foreach ( $ips as $ip ) {
			if ( self::isBlockedIp( $ip ) ) {
				return UrlCheck::deny( 'Host resolves to a private or reserved address.' );
			}
		}
		return UrlCheck::allow( $scheme, $host, $port, array_values( $ips ) );
	}

	/**
	 * Resolve a trusted origin (hosts file included, e.g. "localhost" or a Docker service name).
	 *
	 * @param string $scheme Scheme.
	 * @param string $raw    Host as parsed.
	 * @param int    $port   Port.
	 */
	private function checkTrusted( string $scheme, string $raw, int $port ): UrlCheck {
		$host = rtrim( strtolower( trim( $raw, '[]' ) ), '.' );
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return UrlCheck::allow( $scheme, $host, $port, array( $host ) );
		}
		$ips = ( $this->resolver )( $host );
		if ( array() === $ips && function_exists( 'gethostbynamel' ) ) {
			$resolved = gethostbynamel( $host );
			$ips      = false === $resolved ? array() : $resolved;
		}
		return array() === $ips ? UrlCheck::deny( 'This site\'s own host did not resolve.' ) : UrlCheck::allow( $scheme, $host, $port, array_values( $ips ) );
	}

	/**
	 * Whether an IP is in a blocked range (or is not a valid IP at all).
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 */
	public static function isBlockedIp( string $ip ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input is handled below.
		if ( false === $packed ) {
			return true;
		}
		if ( 4 === strlen( $packed ) ) {
			return self::inAny( $packed, self::BLOCKED_V4 );
		}
		// IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d): judge the embedded IPv4.
		$mapped_prefix = str_repeat( "\0", 10 ) . "\xff\xff";
		$nat64_prefix  = "\x00\x64\xff\x9b" . str_repeat( "\0", 8 );
		$head          = substr( $packed, 0, 12 );
		if ( $head === $mapped_prefix || $head === $nat64_prefix ) {
			return self::inAny( substr( $packed, 12 ), self::BLOCKED_V4 );
		}
		return self::inAny( $packed, self::BLOCKED_V6 );
	}

	/**
	 * Whether a packed address falls in any CIDR of the list.
	 *
	 * @param string             $packed Packed address.
	 * @param array<int, string> $cidrs  CIDR list of the same family.
	 */
	private static function inAny( string $packed, array $cidrs ): bool {
		foreach ( $cidrs as $cidr ) {
			[ $net, $bits ] = explode( '/', $cidr );
			$net_packed     = inet_pton( $net );
			if ( false === $net_packed || strlen( $net_packed ) !== strlen( $packed ) ) {
				continue;
			}
			if ( self::prefixMatches( $packed, $net_packed, (int) $bits ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compare the first $bits bits of two packed addresses.
	 *
	 * @param string $a    Packed address.
	 * @param string $b    Packed network.
	 * @param int    $bits Prefix length.
	 */
	private static function prefixMatches( string $a, string $b, int $bits ): bool {
		$bytes = intdiv( $bits, 8 );
		if ( 0 !== strncmp( $a, $b, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}

	/**
	 * Resolve A and AAAA records with the system resolver.
	 *
	 * @param string $host Host name.
	 * @return array<int, string>
	 */
	public static function systemResolve( string $host ): array {
		$ips     = array();
		$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure returns false.
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( isset( $record['ip'] ) ) {
					$ips[] = (string) $record['ip'];
				} elseif ( isset( $record['ipv6'] ) ) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}
}
