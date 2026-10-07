<?php
/**
 * PSR-4 autoloader for the KHSEO namespace, so the plugin runs without Composer.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO;

/**
 * Maps KHSEO\Foo\Bar to src/Foo/Bar.php.
 */
final class Autoloader {

	private const PREFIX = 'KHSEO\\';

	/**
	 * Base directory with a trailing slash.
	 *
	 * @var string
	 */
	private static string $base = '';

	/**
	 * Register the autoloader once.
	 *
	 * @param string $base_dir Absolute path to src/.
	 */
	public static function register( string $base_dir ): void {
		if ( '' !== self::$base ) {
			return;
		}
		self::$base = rtrim( $base_dir, '/\\' ) . '/';
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load a class file if it belongs to the KHSEO namespace.
	 *
	 * @param string $class_name Fully-qualified class name.
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strncmp( $class_name, self::PREFIX, strlen( self::PREFIX ) ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( self::PREFIX ) );
		// Only word characters and namespace separators: blocks path traversal.
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}
		$file = self::$base . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
}
