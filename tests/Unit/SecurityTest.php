<?php
/**
 * Secret store, redactor and capability map tests.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Tests\Unit;

use KHSEO\Security\Capabilities;
use KHSEO\Security\Redactor;
use KHSEO\Security\SecretStore;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase {

	public function test_secret_round_trip_and_ciphertext_hides_plaintext(): void {
		$store  = new SecretStore( str_repeat( 'k', 32 ) );
		$secret = 'sk-test-1234567890abcdefXYZ';
		$cipher = $store->encrypt( $secret );
		$this->assertStringNotContainsString( $secret, $cipher );
		$this->assertNotSame( $cipher, $store->encrypt( $secret ), 'random nonce per encryption' );
		$this->assertSame( $secret, $store->decrypt( $cipher ) );
	}

	public function test_tampered_or_foreign_ciphertext_is_rejected(): void {
		$store  = new SecretStore( str_repeat( 'k', 32 ) );
		$cipher = $store->encrypt( 'sk-test-1234567890abcdef' );
		$this->assertNull( ( new SecretStore( str_repeat( 'x', 32 ) ) )->decrypt( $cipher ), 'other key' );
		$this->assertNull( $store->decrypt( substr( $cipher, 0, -2 ) . 'AA' ), 'tampered' );
		$this->assertNull( $store->decrypt( 'plain-text-value' ), 'no prefix' );
	}

	public function test_short_key_material_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		new SecretStore( 'short' );
	}

	public function test_mask_never_reveals_more_than_last_four(): void {
		$this->assertSame( '••••cdef', SecretStore::mask( 'sk-1234567890abcdef' ) );
		$this->assertSame( '••••', SecretStore::mask( 'short-key' ) );
		$this->assertSame( '', SecretStore::mask( '' ) );
	}

	public function test_redactor_masks_known_token_shapes(): void {
		$r    = new Redactor();
		$text = 'openai sk-proj-abcdefghijklmnop1234 google AIzaSyA1234567890abcdefghijklmnopqrstuv oauth ya29.a0AfH6SMBx';
		$out  = $r->redactString( $text );
		$this->assertStringNotContainsString( 'sk-proj-abcdefghijklmnop1234', $out );
		$this->assertStringNotContainsString( 'AIzaSyA1234567890', $out );
		$this->assertStringNotContainsString( 'ya29.a0AfH6SMBx', $out );
	}

	public function test_redactor_keeps_labels_but_hides_values(): void {
		$out = ( new Redactor() )->redactString( "Authorization: Bearer abc.def.ghi123\npassword=hunter2&x=1" );
		$this->assertStringContainsString( 'Authorization: ', $out );
		$this->assertStringNotContainsString( 'abc.def.ghi123', $out );
		$this->assertStringContainsString( 'password=' . Redactor::MASK, $out );
		$this->assertStringNotContainsString( 'hunter2', $out );
		$this->assertStringContainsString( '&x=1', $out );
	}

	public function test_redactor_masks_sensitive_keys_and_registered_secrets(): void {
		$r = new Redactor();
		$r->addSecret( 'custom-secret-value' );
		$out = $r->redact(
			array(
				'api_key' => 'anything',
				'nested'  => array( 'auth_token' => 'x', 'note' => 'uses custom-secret-value here' ),
				'count'   => 3,
			)
		);
		$this->assertSame( Redactor::MASK, $out['api_key'] );
		$this->assertSame( Redactor::MASK, $out['nested']['auth_token'] );
		$this->assertSame( 'uses ' . Redactor::MASK . ' here', $out['nested']['note'] );
		$this->assertSame( 3, $out['count'] );
	}

	public function test_editors_get_read_only_access_by_default(): void {
		$map = Capabilities::defaultMap();
		$this->assertSame( array( Capabilities::VIEW ), $map['editor'] );
		$this->assertSame( Capabilities::all(), $map['administrator'] );
		$this->assertCount( 8, array_unique( Capabilities::all() ) );
	}
}
