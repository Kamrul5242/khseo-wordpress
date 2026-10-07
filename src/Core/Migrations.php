<?php
/**
 * Versioned, idempotent schema migrations.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Core;

/**
 * Runs each migration step once per site, tracked by khseo_db_version.
 * Phase 1 needs no custom tables; later phases append steps that use dbDelta().
 */
final class Migrations {

	public const OPTION     = 'khseo_db_version';
	public const DB_VERSION = 1;

	/**
	 * Run pending migrations if the stored version is behind.
	 */
	public static function maybeRun(): void {
		$current = (int) get_option( self::OPTION, 0 );
		if ( $current >= self::DB_VERSION ) {
			return;
		}
		for ( $v = $current + 1; $v <= self::DB_VERSION; $v++ ) {
			$method = 'migrate' . $v;
			if ( method_exists( self::class, $method ) ) {
				self::$method();
			}
			// Autoloaded: it is read on every request, so it must not cost a query.
			update_option( self::OPTION, $v, true );
		}
	}

	/**
	 * Version 1: options only, no tables.
	 */
	private static function migrate1(): void {
		add_option( 'khseo_log', array(), '', false );
	}
}
