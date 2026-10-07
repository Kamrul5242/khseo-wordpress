<?php
/**
 * SSRF guard tests.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Tests\Unit;

use KHSEO\Security\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlGuardTest extends TestCase {

	/**
	 * Guard with a fake resolver so tests never touch the network.
	 *
	 * @param array<string, array<int, string>> $dns Host to IPs.
	 */
	private function guard( array $dns = array() ): UrlGuard {
		return new UrlGuard( static fn ( string $host ): array => $dns[ $host ] ?? array() );
	}

	public function test_public_https_url_is_allowed_and_ips_returned(): void {
		$check = $this->guard( array( 'example.com' => array( '93.184.215.14' ) ) )->check( 'https://example.com/page' );
		$this->assertTrue( $check->allowed, $check->reason );
		$this->assertSame( array( '93.184.215.14' ), $check->ips );
		$this->assertSame( 443, $check->port );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function blockedIps(): array {
		return array(
			'loopback v4'        => array( '127.0.0.1' ),
			'private 10/8'       => array( '10.1.2.3' ),
			'private 172.16/12'  => array( '172.31.255.255' ),
			'private 192.168/16' => array( '192.168.0.10' ),
			'link-local/metadata'=> array( '169.254.169.254' ),
			'CGNAT'              => array( '100.64.0.1' ),
			'this-network'       => array( '0.0.0.0' ),
			'multicast'          => array( '224.0.0.1' ),
			'broadcast'          => array( '255.255.255.255' ),
			'loopback v6'        => array( '::1' ),
			'unspecified v6'     => array( '::' ),
			'ULA v6'             => array( 'fd00::1' ),
			'link-local v6'      => array( 'fe80::1' ),
			'v4-mapped loopback' => array( '::ffff:127.0.0.1' ),
			'v4-mapped metadata' => array( '::ffff:169.254.169.254' ),
			'NAT64 private'      => array( '64:ff9b::10.0.0.1' ),
			'6to4'               => array( '2002:7f00:1::' ),
			'garbage'            => array( 'not-an-ip' ),
		);
	}

	#[DataProvider( 'blockedIps' )]
	public function test_blocked_ip_ranges( string $ip ): void {
		$this->assertTrue( UrlGuard::isBlockedIp( $ip ), $ip . ' must be blocked' );
	}

	public function test_public_ips_are_not_blocked(): void {
		foreach ( array( '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '::ffff:8.8.8.8', '172.32.0.1', '100.128.0.1' ) as $ip ) {
			$this->assertFalse( UrlGuard::isBlockedIp( $ip ), $ip . ' must be allowed' );
		}
	}

	public function test_dns_rebinding_style_host_with_any_private_record_is_denied(): void {
		$check = $this->guard( array( 'evil.test' => array( '93.184.215.14', '127.0.0.1' ) ) )->check( 'http://evil.test/' );
		$this->assertFalse( $check->allowed );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function deniedUrls(): array {
		return array(
			'file scheme'      => array( 'file:///etc/passwd' ),
			'gopher scheme'    => array( 'gopher://example.com/' ),
			'relative'         => array( '/wp-admin/' ),
			'credentials'      => array( 'https://user:pass@example.com/' ),
			'odd port'         => array( 'http://example.com:6379/' ),
			'localhost name'   => array( 'http://localhost/' ),
			'sub.localhost'    => array( 'http://a.localhost/' ),
			'IP literal'       => array( 'http://127.0.0.1/' ),
			'IPv6 literal'     => array( 'http://[::1]/' ),
			'unresolvable'     => array( 'https://no-such-host.test/' ),
			'control chars'    => array( "https://example.com/\r\nHost: x" ),
		);
	}

	#[DataProvider( 'deniedUrls' )]
	public function test_denied_urls( string $url ): void {
		$check = $this->guard( array( 'example.com' => array( '93.184.215.14' ) ) )->check( $url );
		$this->assertFalse( $check->allowed, $url . ' must be denied' );
		$this->assertNotSame( '', $check->reason );
	}
}
