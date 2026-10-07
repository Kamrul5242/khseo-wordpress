<?php
/**
 * Encrypts API keys and other secrets at rest with libsodium.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Security;

use RuntimeException;

/**
 * Authenticated encryption (XSalsa20-Poly1305) for secrets stored in options.
 *
 * Secrets are never rendered, logged, exported or sent to JavaScript; callers
 * only ever display mask().
 */
final class SecretStore {

	private const PREFIX = 'khseo1:';

	/**
	 * 32-byte encryption key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string $key_material Any secret string (KHSEO_SECRET_KEY or WordPress salts).
	 * @throws RuntimeException When the key material is too short.
	 */
	public function __construct( string $key_material ) {
		if ( strlen( $key_material ) < 16 ) {
			throw new RuntimeException( 'Secret key material is too short.' );
		}
		$this->key = sodium_crypto_generichash( $key_material, 'khseo-secret-store', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Key material for this site: KHSEO_SECRET_KEY if defined, else WordPress salts.
	 */
	public static function siteKeyMaterial(): string {
		if ( defined( 'KHSEO_SECRET_KEY' ) && is_string( KHSEO_SECRET_KEY ) && '' !== KHSEO_SECRET_KEY ) {
			return KHSEO_SECRET_KEY;
		}
		$material = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $const ) {
			if ( defined( $const ) ) {
				$material .= (string) constant( $const );
			}
		}
		return $material;
	}

	/**
	 * Encrypt a secret. Empty input returns an empty string.
	 *
	 * @param string $plain Secret value.
	 */
	public function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $this->key );
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe storage, not obfuscation.
	}

	/**
	 * Decrypt a stored secret. Returns null if it was tampered with or the key changed.
	 *
	 * @param string $stored Value produced by encrypt().
	 */
	public function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );
		return false === $plain ? null : $plain;
	}

	/**
	 * Safe display form of a secret: never more than the last 4 characters.
	 *
	 * @param string $plain Secret value.
	 */
	public static function mask( string $plain ): string {
		$len = strlen( $plain );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len < 12 ) {
			return '••••';
		}
		return '••••' . substr( $plain, -4 );
	}
}
