<?php
/**
 * Renders admin templates from templates/admin/.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Admin;

/**
 * Templates receive data as variables and must escape everything they print.
 */
final class View {

	/**
	 * Render a template by fixed name (no user input reaches the path).
	 *
	 * @param string               $name Template name: letters and dashes only.
	 * @param array<string, mixed> $data Variables for the template.
	 */
	public static function render( string $name, array $data = array() ): void {
		if ( ! preg_match( '/^[a-z-]+$/', $name ) ) {
			return;
		}
		$file = KHSEO_DIR . 'templates/admin/' . $name . '.php';
		if ( ! is_file( $file ) ) {
			return;
		}
		( static function ( string $khseo_file, array $khseo_data ): void {
			extract( $khseo_data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- keys are set by KHSEO code, not user input.
			require $khseo_file;
		} )( $file, $data );
	}
}
