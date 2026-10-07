<?php
/**
 * KHSEO settings schema, sanitizer and import/export.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Settings;

/**
 * Pure settings logic (no WordPress calls) so it can be unit-tested.
 * Secrets (API keys, OAuth tokens) are NOT settings: they live encrypted in a
 * separate option and are therefore never part of an export.
 */
final class Settings {

	public const OPTION = 'khseo_settings';

	public const COMPATIBILITY_MODES = array( 'primary', 'advisory', 'compatibility', 'metadata_disabled' );
	public const AI_PROVIDERS        = array( 'none', 'openai', 'gemini', 'claude', 'compatible', 'custom' );
	public const LOG_LEVELS          = array( 'debug', 'info', 'notice', 'warning', 'error', 'security' );

	/**
	 * Default values. Safe by default: no automation, no AI, no data deletion.
	 */
	public const DEFAULTS = array(
		'delete_data_on_uninstall' => false,
		'safe_auto_fixes'          => false,
		'scheduled_jobs'           => true,
		'compatibility_mode'       => 'advisory',
		'log_level'                => 'warning',
		'log_retention_days'       => 14,
		'ai_provider'              => 'none',
		'ai_model'                 => '',
		'ai_temperature'           => 0.3,
		'ai_max_output_tokens'     => 800,
		'ai_privacy_mode'          => true,
		'ai_send_content'          => false,
		'audit_max_pages'          => 25,
		'audit_max_sitemap_files'  => 5,
		'audit_max_total_mb'       => 20,
	);

	/**
	 * Hard upper bounds for audit limits; no setting can exceed them.
	 */
	public const AUDIT_BOUNDS = array(
		'audit_max_pages'         => array( 1, 200 ),
		'audit_max_sitemap_files' => array( 1, 20 ),
		'audit_max_total_mb'      => array( 1, 100 ),
	);

	/**
	 * Merge stored values over defaults, sanitizing everything.
	 *
	 * @param mixed $stored Raw option value.
	 * @return array<string, mixed>
	 */
	public static function normalize( mixed $stored ): array {
		return self::sanitize( is_array( $stored ) ? $stored : array(), self::DEFAULTS );
	}

	/**
	 * Sanitize untrusted input. Unknown keys are dropped; invalid values fall back to $current.
	 *
	 * @param array<string, mixed> $input   Untrusted input.
	 * @param array<string, mixed> $current Current valid settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, array $current ): array {
		$current = array_merge( self::DEFAULTS, array_intersect_key( $current, self::DEFAULTS ) );
		$out     = $current;

		foreach ( array( 'delete_data_on_uninstall', 'safe_auto_fixes', 'scheduled_jobs', 'ai_privacy_mode', 'ai_send_content' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = self::toBool( $input[ $key ] );
			}
		}

		$out['compatibility_mode'] = self::oneOf( $input, 'compatibility_mode', self::COMPATIBILITY_MODES, $current );
		$out['ai_provider']        = self::oneOf( $input, 'ai_provider', self::AI_PROVIDERS, $current );
		$out['log_level']          = self::oneOf( $input, 'log_level', self::LOG_LEVELS, $current );

		if ( array_key_exists( 'ai_model', $input ) && is_scalar( $input['ai_model'] ) ) {
			$model           = (string) $input['ai_model'];
			$out['ai_model'] = preg_match( '/^[A-Za-z0-9._:\/-]{0,100}$/', $model ) ? $model : $current['ai_model'];
		}

		$out['log_retention_days']   = self::intInRange( $input, 'log_retention_days', 1, 90, $current );
		$out['ai_max_output_tokens'] = self::intInRange( $input, 'ai_max_output_tokens', 64, 8192, $current );
		foreach ( self::AUDIT_BOUNDS as $key => [ $min, $max ] ) {
			$out[ $key ] = self::intInRange( $input, $key, $min, $max, $current );
		}

		if ( array_key_exists( 'ai_temperature', $input ) && is_numeric( $input['ai_temperature'] ) ) {
			$t                     = (float) $input['ai_temperature'];
			$out['ai_temperature'] = ( $t >= 0.0 && $t <= 1.0 ) ? round( $t, 2 ) : $current['ai_temperature'];
		}

		return $out;
	}

	/**
	 * Export settings as JSON. Secrets are never included.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 */
	public static function export( array $settings ): string {
		$payload = array(
			'khseo_export' => 1,
			'secrets'      => 'excluded',
			'settings'     => self::sanitize( $settings, self::DEFAULTS ),
		);
		return (string) json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure class, runs without WordPress; input is sanitized scalars.
	}

	/**
	 * Validate and sanitize an imported JSON export.
	 *
	 * @param string               $json    Untrusted JSON.
	 * @param array<string, mixed> $current Current settings.
	 * @return array<string, mixed>|null Sanitized settings, or null if the file is not a KHSEO export.
	 */
	public static function import( string $json, array $current ): ?array {
		if ( strlen( $json ) > 65536 ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || 1 !== ( $data['khseo_export'] ?? null ) || ! is_array( $data['settings'] ?? null ) ) {
			return null;
		}
		return self::sanitize( $data['settings'], $current );
	}

	/**
	 * Strict boolean conversion.
	 *
	 * @param mixed $value Input.
	 */
	private static function toBool( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'on' === $value || 'true' === $value;
	}

	/**
	 * Enum field.
	 *
	 * @param array<string, mixed> $input   Input.
	 * @param string               $key     Key.
	 * @param array<int, string>   $allowed Allowed values.
	 * @param array<string, mixed> $current Current settings.
	 */
	private static function oneOf( array $input, string $key, array $allowed, array $current ): string {
		if ( array_key_exists( $key, $input ) && is_string( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
			return $input[ $key ];
		}
		return (string) $current[ $key ];
	}

	/**
	 * Integer field with bounds.
	 *
	 * @param array<string, mixed> $input   Input.
	 * @param string               $key     Key.
	 * @param int                  $min     Minimum.
	 * @param int                  $max     Maximum.
	 * @param array<string, mixed> $current Current settings.
	 */
	private static function intInRange( array $input, string $key, int $min, int $max, array $current ): int {
		if ( array_key_exists( $key, $input ) && is_numeric( $input[ $key ] ) ) {
			$v = (int) $input[ $key ];
			if ( $v >= $min && $v <= $max ) {
				return $v;
			}
		}
		return (int) $current[ $key ];
	}
}
