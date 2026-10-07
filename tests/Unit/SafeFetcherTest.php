<?php
/**
 * Safe fetcher: redirect re-validation, pinning, limits, decompression bombs.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Tests\Unit;

use KHSEO\Http\FetchPolicy;
use KHSEO\Http\SafeFetcher;
use KHSEO\Http\Transport;
use KHSEO\Http\TransportResponse;
use KHSEO\Security\UrlGuard;
use KHSEO\Support\Evidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scripted transport: returns queued responses and records every call.
 */
final class FakeTransport implements Transport {

	/**
	 * Calls made: [url, pinned_ip, port].
	 *
	 * @var array<int, array{0: string, 1: string, 2: int}>
	 */
	public array $calls = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, TransportResponse> $queue Responses in order.
	 */
	public function __construct( private array $queue ) {}

	public function get( string $url, string $pinned_ip, int $port, FetchPolicy $policy ): TransportResponse {
		$this->calls[] = array( $url, $pinned_ip, $port );
		return array_shift( $this->queue ) ?? TransportResponse::failure( 'no scripted response' );
	}
}

final class SafeFetcherTest extends TestCase {

	private const DNS = array(
		'example.com'   => array( '93.184.215.14' ),
		'other.example' => array( '93.184.215.15' ),
		'rebind.test'   => array( '127.0.0.1' ),
		'mixed.test'    => array( '93.184.215.14', '10.0.0.5' ),
	);

	private function guard(): UrlGuard {
		return new UrlGuard( static fn ( string $h ): array => self::DNS[ $h ] ?? array() );
	}

	private function html( string $body = '<html></html>', array $extra = array() ): TransportResponse {
		return new TransportResponse( 200, array( 'content-type' => 'text/html; charset=utf-8' ) + $extra, $body );
	}

	private function redirect( string $to, int $status = 302 ): TransportResponse {
		return new TransportResponse( $status, array( 'location' => $to ) );
	}

	public function test_successful_fetch_is_pinned_to_the_validated_ip(): void {
		$t      = new FakeTransport( array( $this->html( '<h1>ok</h1>' ) ) );
		$result = ( new SafeFetcher( $this->guard(), $t ) )->fetch( 'https://example.com/a' );
		$this->assertTrue( $result->ok, $result->reason );
		$this->assertSame( '<h1>ok</h1>', $result->body );
		$this->assertSame( array( array( 'https://example.com/a', '93.184.215.14', 443 ) ), $t->calls );
		$this->assertSame( Evidence::OBSERVED, $result->evidence() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function badRedirects(): array {
		return array(
			'to loopback IP'       => array( 'http://127.0.0.1/admin' ),
			'to localhost'         => array( 'http://localhost/' ),
			'to metadata service'  => array( 'http://169.254.169.254/latest/meta-data/' ),
			'to private host'      => array( 'http://rebind.test/' ),
			'to mixed DNS host'    => array( 'http://mixed.test/' ),
			'to IPv6 loopback'     => array( 'http://[::1]/' ),
			'to decimal IP'        => array( 'http://2130706433/' ),
			'to file scheme'       => array( 'file:///etc/passwd' ),
			'to odd port'          => array( 'http://example.com:8080/' ),
			'to credentials URL'   => array( 'http://user:pw@example.com/' ),
		);
	}

	#[DataProvider( 'badRedirects' )]
	public function test_every_redirect_hop_is_revalidated( string $target ): void {
		$t      = new FakeTransport( array( $this->redirect( $target ), $this->html() ) );
		$result = ( new SafeFetcher( $this->guard(), $t ) )->fetch( 'https://example.com/' );
		$this->assertFalse( $result->ok, 'redirect to ' . $target . ' must be refused' );
		$this->assertStringStartsWith( 'Blocked:', $result->reason );
		$this->assertCount( 1, $t->calls, 'the blocked hop must never be requested' );
	}

	public function test_relative_and_cross_host_redirects_are_followed_and_repinned(): void {
		$t      = new FakeTransport( array( $this->redirect( '/next' ), $this->redirect( 'https://other.example/final', 301 ), $this->html() ) );
		$result = ( new SafeFetcher( $this->guard(), $t ) )->fetch( 'https://example.com/start' );
		$this->assertTrue( $result->ok, $result->reason );
		$this->assertSame( 'https://other.example/final', $result->final_url );
		$this->assertSame( '93.184.215.15', $t->calls[2][1], 'new host gets its own validated IP' );
		$this->assertSame( array( 'https://example.com/start', 'https://example.com/next', 'https://other.example/final' ), $result->chain );
	}

	public function test_redirect_loop_is_bounded(): void {
		$t      = new FakeTransport( array_fill( 0, 10, $this->redirect( '/loop' ) ) );
		$result = ( new SafeFetcher( $this->guard(), $t, new FetchPolicy( max_redirects: 2 ) ) )->fetch( 'https://example.com/loop' );
		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( 'Too many redirects', $result->reason );
		$this->assertCount( 3, $t->calls );
	}

	public function test_oversized_and_truncated_bodies_are_refused(): void {
		$policy = new FetchPolicy( max_bytes: 100 );
		$big    = ( new SafeFetcher( $this->guard(), new FakeTransport( array( $this->html( str_repeat( 'a', 101 ) ) ) ), $policy ) )->fetch( 'https://example.com/' );
		$this->assertFalse( $big->ok );
		$cut = ( new SafeFetcher( $this->guard(), new FakeTransport( array( new TransportResponse( 200, array( 'content-type' => 'text/html' ), 'x', true ) ) ), $policy ) )->fetch( 'https://example.com/' );
		$this->assertFalse( $cut->ok );
		$this->assertStringContainsString( 'exceeded', $cut->reason );
	}

	public function test_disallowed_content_type_is_refused(): void {
		$t      = new FakeTransport( array( new TransportResponse( 200, array( 'content-type' => 'application/octet-stream' ), "\x7fELF" ) ) );
		$result = ( new SafeFetcher( $this->guard(), $t ) )->fetch( 'https://example.com/bin' );
		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( 'Content type not allowed', $result->reason );
	}

	public function test_gzip_is_decoded_within_limit(): void {
		$t      = new FakeTransport( array( $this->html( (string) gzencode( '<p>hello</p>' ), array( 'content-encoding' => 'gzip' ) ) ) );
		$result = ( new SafeFetcher( $this->guard(), $t ) )->fetch( 'https://example.com/' );
		$this->assertTrue( $result->ok, $result->reason );
		$this->assertSame( '<p>hello</p>', $result->body );
	}

	public function test_gzip_bomb_is_refused(): void {
		$bomb = (string) gzencode( str_repeat( "\0", 20_000_000 ), 9 ); // ~20 KB on the wire, 20 MB decoded.
		$this->assertLessThan( 100_000, strlen( $bomb ) );
		$t      = new FakeTransport( array( $this->html( $bomb, array( 'content-encoding' => 'gzip' ) ) ) );
		$result = ( new SafeFetcher( $this->guard(), $t ) )->fetch( 'https://example.com/' );
		$this->assertFalse( $result->ok );
		$this->assertStringContainsString( 'expands beyond', $result->reason );
	}

	public function test_corrupt_or_unknown_encoding_is_refused(): void {
		$this->assertNull( SafeFetcher::decode( 'not gzip at all', 'gzip', 1000 ) );
		$this->assertNull( SafeFetcher::decode( 'x', 'br', 1000 ) );
		$this->assertSame( 'plain', SafeFetcher::decode( 'plain', '', 1000 ) );
	}

	public function test_transport_errors_are_reported_not_thrown(): void {
		$result = ( new SafeFetcher( $this->guard(), new FakeTransport( array( TransportResponse::failure( 'timed out' ) ) ) ) )->fetch( 'https://example.com/' );
		$this->assertFalse( $result->ok );
		$this->assertSame( 'Request failed: timed out', $result->reason );
		$this->assertSame( Evidence::NOT_TESTED, $result->evidence() );
	}

	public function test_location_resolution(): void {
		$this->assertSame( 'https://example.com/b', SafeFetcher::resolve( 'https://example.com/a', '/b' ) );
		$this->assertSame( 'https://example.com/dir/c', SafeFetcher::resolve( 'https://example.com/dir/a', 'c' ) );
		$this->assertSame( 'http://x.example/', SafeFetcher::resolve( 'http://example.com/', '//x.example/' ) );
		$this->assertSame( 'https://example.com:8443/z', SafeFetcher::resolve( 'https://example.com:8443/a', '/z' ) );
		$this->assertNull( SafeFetcher::resolve( 'https://example.com/', "/x\r\nSet-Cookie: a=b" ) );
		$this->assertNull( SafeFetcher::resolve( 'https://example.com/', '' ) );
	}
}
