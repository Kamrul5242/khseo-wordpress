<?php
/**
 * Phase 1 final hardening: redirects (RFC 3986), downgrade, credentials, trusted origin,
 * logger terminal sequences, query-string redaction, encryption strength.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Tests\Unit;

use KHSEO\Audit\StatusReport;
use KHSEO\Http\FetchPolicy;
use KHSEO\Http\SafeFetcher;
use KHSEO\Http\TransportResponse;
use KHSEO\Security\Redactor;
use KHSEO\Security\SecretStore;
use KHSEO\Security\UrlGuard;
use KHSEO\Support\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Phase1FinalTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function references(): array {
		$b = 'https://ex.com/a/b/c?q=1';
		return array(
			'parent'            => array( '../d', 'https://ex.com/a/d' ),
			'current'           => array( './d', 'https://ex.com/a/b/d' ),
			'above root'        => array( '../../../../x', 'https://ex.com/x' ),
			'query only'        => array( '?z=2', 'https://ex.com/a/b/c?z=2' ),
			'fragment only'     => array( '#frag', 'https://ex.com/a/b/c?q=1' ),
			'protocol-relative' => array( '//other.com/p/../q', 'https://other.com/q' ),
			'absolute path'     => array( '/p/./q/../r', 'https://ex.com/p/r' ),
			'encoded traversal' => array( '%2e%2e/secret', 'https://ex.com/a/b/%2e%2e/secret' ),
			'empty'             => array( ' ', null ),
			'control chars'     => array( "/x\r\nSet-Cookie: a", null ),
			'oversized'         => array( '/' . str_repeat( 'a', 3000 ), null ),
		);
	}

	#[DataProvider( 'references' )]
	public function test_rfc3986_resolution( string $ref, ?string $expected ): void {
		$this->assertSame( $expected, SafeFetcher::resolve( 'https://ex.com/a/b/c?q=1', $ref ) );
	}

	private function fetcher( array $queue, ?UrlGuard $guard = null, ?FetchPolicy $policy = null ): array {
		$t = new FakeTransport( $queue );
		$g = $guard ?? new UrlGuard( static fn ( string $h ): array => array( 'ex.com' => array( '93.184.215.14' ), 'other.com' => array( '93.184.215.15' ) )[ $h ] ?? array() );
		return array( new SafeFetcher( $g, $t, $policy ?? new FetchPolicy() ), $t );
	}

	public function test_https_to_http_downgrade_is_refused_but_upgrade_allowed(): void {
		[ $f, $t ] = $this->fetcher( array( new TransportResponse( 301, array( 'location' => 'http://ex.com/' ) ), new TransportResponse( 200, array( 'content-type' => 'text/html' ), 'x' ) ) );
		$r         = $f->fetch( 'https://ex.com/' );
		$this->assertFalse( $r->ok );
		$this->assertSame( 'Refused redirect from HTTPS to plain HTTP.', $r->reason );
		$this->assertCount( 1, $t->calls, 'the downgraded hop is never requested' );

		[ $f2 ] = $this->fetcher( array( new TransportResponse( 301, array( 'location' => 'https://ex.com/' ) ), new TransportResponse( 200, array( 'content-type' => 'text/html' ), 'x' ) ) );
		$this->assertTrue( $f2->fetch( 'http://ex.com/' )->ok, 'http → https is fine' );
	}

	public function test_redirect_with_credentials_is_never_silently_cleaned(): void {
		$this->assertSame( 'https://user:pw@ex.com/', SafeFetcher::resolve( 'https://ex.com/', 'https://user:pw@ex.com/' ) );
		[ $f ] = $this->fetcher( array( new TransportResponse( 302, array( 'location' => 'https://user:pw@ex.com/' ) ) ) );
		$this->assertStringContainsString( 'credentials', $f->fetch( 'https://ex.com/' )->reason );
	}

	public function test_trusted_origin_is_exact_and_redirects_away_are_rechecked(): void {
		$dns   = static fn ( string $h ): array => array( 'intranet.local' => array( '10.0.0.5' ), 'db.internal' => array( '10.0.0.9' ) )[ $h ] ?? array();
		$guard = new UrlGuard( $dns, array( 80, 443 ), array( 'http://intranet.local:8080/' ) );
		$this->assertTrue( $guard->check( 'http://intranet.local:8080/page' )->allowed, 'own site on a private address' );
		$this->assertFalse( $guard->check( 'http://intranet.local:9090/' )->allowed, 'same host, other port: not trusted' );
		$this->assertFalse( $guard->check( 'https://intranet.local:8080/' )->allowed, 'other scheme: not trusted' );
		$this->assertFalse( $guard->check( 'http://user:pw@intranet.local:8080/' )->allowed, 'credentials still refused' );
		$this->assertFalse( $guard->check( 'http://db.internal/' )->allowed );

		[ $f, $t ] = $this->fetcher( array( new TransportResponse( 302, array( 'location' => 'http://db.internal/' ) ) ), $guard );
		$r         = $f->fetch( 'http://intranet.local:8080/' );
		$this->assertStringStartsWith( 'Blocked:', $r->reason );
		$this->assertCount( 1, $t->calls );
		$this->assertSame( '10.0.0.5', $t->calls[0][1], 'trusted origin is still pinned to its resolved IP' );
	}

	public function test_logger_strips_terminal_escape_sequences(): void {
		$line = Logger::oneLine( "ok \x1b[31mRED\x1b[0m \x1b]8;;http://evil.example\x07link\x1b]8;;\x07 end \x1b[2J" );
		$this->assertSame( 'ok RED link end', $line );
		$this->assertStringNotContainsString( "\x1b", $line );
	}

	public function test_secret_query_parameters_are_redacted(): void {
		$out = ( new Redactor() )->redactString( 'GET https://x.test/cb?code=abc123&state=ok&access_token=ya.b.c&sig=ZZZ&api_key=K1#f' );
		foreach ( array( 'abc123', 'ya.b.c', 'ZZZ', 'K1' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $out );
		}
		$this->assertStringContainsString( 'state=ok', $out );
	}

	public function test_encryption_strength_is_reported_honestly(): void {
		$this->assertSame( 'dedicated', StatusReport::strength( SecretStore::SOURCE_CONSTANT ) );
		$this->assertSame( 'standard', StatusReport::strength( SecretStore::SOURCE_SALTS ) );
		$this->assertSame( 'degraded', StatusReport::strength( SecretStore::SOURCE_DATABASE ) );
		$this->assertSame( 'unavailable', StatusReport::strength( 'none' ) );
	}
}
