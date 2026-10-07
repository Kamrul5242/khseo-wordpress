<?php
/**
 * Regression tests for defects found in the v0.1.0 audit, plus kernel/governance coverage.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Tests\Unit;

use KHSEO\Autoloader;
use KHSEO\Core\Container;
use KHSEO\Governance\ChangeGate;
use KHSEO\Security\Redactor;
use KHSEO\Security\SecretStore;
use KHSEO\Security\UrlGuard;
use KHSEO\Settings\Settings;
use KHSEO\Support\Logger;
use KHSEO\Support\Risk;
use PHPUnit\Framework\TestCase;

final class HardeningTest extends TestCase {

	// --- D1: key material (no fatal error, no public key) -------------------

	public function test_no_key_material_returns_null_instead_of_throwing(): void {
		$this->assertNull( SecretStore::keyMaterialFrom( array(), '' ) );
	}

	public function test_wordpress_placeholder_salts_are_never_used_as_a_key(): void {
		$placeholder = array_fill_keys( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ), 'put your unique phrase here' );
		$this->assertNull( SecretStore::keyMaterialFrom( $placeholder, '' ) );
		$db = SecretStore::keyMaterialFrom( $placeholder, str_repeat( 'd', 64 ) );
		$this->assertSame( SecretStore::SOURCE_DATABASE, $db['source'] ?? null );
	}

	public function test_key_material_preference_order(): void {
		$salts = array_fill_keys( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ), str_repeat( 's', 64 ) );
		$this->assertSame( SecretStore::SOURCE_SALTS, SecretStore::keyMaterialFrom( $salts, str_repeat( 'd', 64 ) )['source'] ?? null );
		$own = $salts + array( 'KHSEO_SECRET_KEY' => str_repeat( 'k', 40 ) );
		$this->assertSame( SecretStore::SOURCE_CONSTANT, SecretStore::keyMaterialFrom( $own, '' )['source'] ?? null );
		$short = $salts + array( 'KHSEO_SECRET_KEY' => 'too-short' );
		$this->assertSame( SecretStore::SOURCE_SALTS, SecretStore::keyMaterialFrom( $short, '' )['source'] ?? null );
	}

	public function test_decrypt_rejects_untrusted_shapes_without_throwing(): void {
		$store = new SecretStore( str_repeat( 'k', 32 ) );
		$this->assertNull( $store->decrypt( array( 'x' ) ) );
		$this->assertNull( $store->decrypt( 12345 ) );
		$this->assertNull( $store->decrypt( 'khseo1:' . str_repeat( 'A', 9000 ) ) );
		$this->assertNull( $store->decrypt( 'khseo1:!!!not-base64!!!' ) );
		$this->assertNull( $store->decrypt( 'khseo1:' . base64_encode( 'short' ) ) );
	}

	// --- D3: logger injection / unbounded size --------------------------------

	public function test_log_message_is_one_line_tag_free_and_bounded(): void {
		$logger = new Logger( new Redactor(), 'debug' );
		$entry  = $logger->entry( 'error', "real\nFAKE 2026 security admin logged in<script>x</script>" . str_repeat( 'A', 1_000_000 ), array(), 1 );
		$this->assertNotNull( $entry );
		$this->assertStringNotContainsString( "\n", $entry['message'] );
		$this->assertStringNotContainsString( '<script>', $entry['message'] );
		$this->assertLessThanOrEqual( Logger::MAX_MESSAGE + strlen( Logger::TRUNCATED ), strlen( $entry['message'] ) );
		$this->assertStringEndsWith( Logger::TRUNCATED, $entry['message'] );
	}

	public function test_log_context_is_redacted_and_size_capped(): void {
		$logger = new Logger( new Redactor(), 'debug' );
		$small  = $logger->entry( 'info', 'm', array( 'api_key' => 'sk-abcdefghijklmnop1234', 'n' => 1 ), 1 );
		$this->assertSame( Redactor::MASK, $small['context']['api_key'] );
		$big = $logger->entry( 'info', 'm', array( 'blob' => str_repeat( 'x', 10_000 ) ), 1 );
		$this->assertSame( Logger::CONTEXT_LIMIT, $big['context'] );
	}

	public function test_log_level_filter_keeps_security_events(): void {
		$logger = new Logger( new Redactor(), 'error' );
		$this->assertNull( $logger->entry( 'info', 'noise', array(), 1 ) );
		$this->assertNotNull( $logger->entry( 'security', 'kept', array(), 1 ) );
		$this->assertSame( 'error', $logger->entry( 'bogus-level', 'x', array(), 1 )['level'] );
	}

	public function test_log_prune_tolerates_corrupt_option_values(): void {
		$this->assertSame( array(), Logger::prune( array( 'junk', 42, null ), 100, 14 ) );
	}

	// --- D4: non-canonical hosts ----------------------------------------------

	public function test_non_canonical_and_odd_hosts_are_denied_explicitly(): void {
		$guard = new UrlGuard( static fn ( string $h ): array => array( '93.184.215.14' ) ); // Would "resolve" anything.
		foreach ( array( 'http://2130706433/', 'http://0x7f000001/', 'http://0177.0.0.1/', 'http://127.1/', 'http://0x7f.1/' ) as $url ) {
			$check = $guard->check( $url );
			$this->assertFalse( $check->allowed, $url );
			$this->assertSame( 'Numeric host is not a standard IP address.', $check->reason, $url );
		}
		$this->assertFalse( $guard->check( 'http://localhost./' )->allowed, 'trailing-dot localhost' );
		$this->assertFalse( $guard->check( 'http://exаmple.com/' )->allowed, 'non-ASCII (Cyrillic a) host' );
		$this->assertTrue( $guard->check( 'https://example.com./' )->allowed, 'trailing dot on a public name is fine' );
	}

	// --- Governance ------------------------------------------------------------

	public function test_change_gate_enforces_r0_to_r4(): void {
		$allow = ChangeGate::ALLOW;
		$this->assertNotSame( $allow, ChangeGate::decide( Risk::R0, true, true, 'c1', 'c1' )['decision'] );
		$this->assertNotSame( $allow, ChangeGate::decide( Risk::R1, true, false, null, 'c1' )['decision'], 'R1 without automation or approval' );
		$this->assertSame( $allow, ChangeGate::decide( Risk::R1, true, true, null, 'c1' )['decision'] );
		$this->assertNotSame( $allow, ChangeGate::decide( Risk::R1, false, true, null, 'c1' )['decision'], 'irreversible is never R1' );
		$this->assertNotSame( $allow, ChangeGate::decide( Risk::R2, true, true, null, 'c1' )['decision'], 'automation never covers R2' );
		$this->assertNotSame( $allow, ChangeGate::decide( Risk::R2, true, false, 'other', 'c1' )['decision'], 'approval must match this change' );
		$this->assertSame( $allow, ChangeGate::decide( Risk::R2, true, false, 'c1', 'c1' )['decision'] );
		$this->assertNotSame( $allow, ChangeGate::decide( Risk::R3, true, false, 'c1', 'c1', 'none' )['decision'] );
		$this->assertSame( $allow, ChangeGate::decide( Risk::R3, true, false, 'c1', 'c1', 'available' )['decision'] );
		$this->assertNotSame( $allow, ChangeGate::decide( Risk::R4, true, false, 'c1', 'c1', 'available' )['decision'] );
		$this->assertSame( $allow, ChangeGate::decide( Risk::R4, true, false, 'c1', 'c1', 'verified' )['decision'] );
		$this->assertNotSame( $allow, ChangeGate::decide( Risk::R2, true, false, '', '' )['decision'], 'empty ids never match' );
	}

	// --- Kernel ------------------------------------------------------------------

	public function test_container_builds_once_and_caches_null(): void {
		$c     = new Container();
		$built = 0;
		$c->set( 'x', static function () use ( &$built ) {
			++$built;
			return null;
		} );
		$this->assertNull( $c->get( 'x' ) );
		$this->assertNull( $c->get( 'x' ) );
		$this->assertSame( 1, $built );
		$this->expectException( \RuntimeException::class );
		$c->get( 'missing' );
	}

	public function test_autoloader_ignores_foreign_and_traversal_class_names(): void {
		Autoloader::load( 'Other\\Thing' );
		Autoloader::load( 'KHSEO\\..\\..\\etc\\passwd' );
		Autoloader::load( 'KHSEO\\Nope/../../x' );
		$this->assertFalse( class_exists( 'KHSEO\\DoesNotExist' ) );
		$this->assertTrue( class_exists( Settings::class ) );
	}

	// --- Settings import edge cases -------------------------------------------

	public function test_import_rejects_oversized_and_wrongly_typed_payloads(): void {
		$this->assertNull( Settings::import( str_repeat( ' ', 70_000 ), Settings::DEFAULTS ) );
		$this->assertNull( Settings::import( '{"khseo_export":"1","settings":{}}', Settings::DEFAULTS ), 'string version marker' );
		$this->assertNull( Settings::import( '{"khseo_export":1,"settings":"x"}', Settings::DEFAULTS ) );
		$out = Settings::import( '{"khseo_export":1,"settings":{"ai_provider":"openai","role":"administrator","ai_api_key":"sk-injected-123456789"}}', Settings::DEFAULTS );
		$this->assertSame( 'openai', $out['ai_provider'] );
		$this->assertArrayNotHasKey( 'role', $out, 'no capability escalation field' );
		$this->assertArrayNotHasKey( 'ai_api_key', $out, 'no secret injection through import' );
	}
}
