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

	public const SOURCE_CONSTANT = 'KHSEO_SECRET_KEY';
	public const SOURCE_SALTS    = 'wp-config salts';
	public const SOURCE_DATABASE = 'database salt';

	/**
	 * Placeholder that WordPress ships in wp-config-sample.php. A key built from it is public.
	 */
	private const SALT_PLACEHOLDER = 'put your unique phrase here';

	private const MIN_MATERIAL = 32;

	/**
	 * 32-byte encryption key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string $key_material Secret string of at least 32 bytes.
	 * @throws RuntimeException When the key material is too short.
	 */
	public function __construct( string $key_material ) {
		if ( strlen( $key_material ) < self::MIN_MATERIAL ) {
			throw new RuntimeException( 'Secret key material is too short.' );
		}
		$this->key = sodium_crypto_generichash( $key_material, 'khseo-secret-store', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Store for this site, or null when no safe key material exists (never throws).
	 */
	public static function forSite(): ?self {
		$material = self::siteKeyMaterial();
		if ( null === $material ) {
			return null;
		}
		try {
			return new self( $material['material'] );
		} catch ( RuntimeException $e ) {
			return null;
		}
	}

	/**
	 * Key material for this site with its source label.
	 *
	 * @return array{material: string, source: string}|null
	 */
	public static function siteKeyMaterial(): ?array {
		$constants = array();
		foreach ( array( 'KHSEO_SECRET_KEY', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $name ) {
			if ( defined( $name ) ) {
				$constants[ $name ] = (string) constant( $name );
			}
		}
		$db_salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		return self::keyMaterialFrom( $constants, $db_salt );
	}

	/**
	 * Choose key material (pure, testable).
	 *
	 * Order: KHSEO_SECRET_KEY → wp-config salts (placeholders ignored) → WordPress's database salt.
	 *
	 * @param array<string, string> $constants Defined constants by name.
	 * @param string                $db_salt   wp_salt('auth') value, '' if unavailable.
	 * @return array{material: string, source: string}|null
	 */
	public static function keyMaterialFrom( array $constants, string $db_salt ): ?array {
		$own = $constants['KHSEO_SECRET_KEY'] ?? '';
		if ( strlen( $own ) >= self::MIN_MATERIAL ) {
			return array(
				'material' => $own,
				'source'   => self::SOURCE_CONSTANT,
			);
		}
		$salts = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $name ) {
			$value = $constants[ $name ] ?? '';
			if ( strlen( $value ) >= 16 && self::SALT_PLACEHOLDER !== $value ) {
				$salts .= $value;
			}
		}
		if ( strlen( $salts ) >= self::MIN_MATERIAL ) {
			return array(
				'material' => $salts,
				'source'   => self::SOURCE_SALTS,
			);
		}
		if ( strlen( $db_salt ) >= self::MIN_MATERIAL && ! str_contains( $db_salt, self::SALT_PLACEHOLDER ) ) {
			return array(
				'material' => $db_salt,
				'source'   => self::SOURCE_DATABASE,
			);
		}
		return null;
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
	 * Decrypt a stored secret. Returns null if it was tampered with, the key changed,
	 * or the value is not KHSEO ciphertext (never throws).
	 *
	 * @param mixed $stored Value produced by encrypt() (untrusted: read from the database).
	 */
	public function decrypt( mixed $stored ): ?string {
		if ( ! is_string( $stored ) ) {
			return null;
		}
		if ( '' === $stored ) {
			return '';
		}
		if ( ! str_starts_with( $stored, self::PREFIX ) || strlen( $stored ) > 8192 ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );
		} catch ( \SodiumException $e ) {
			return null;
		}
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
