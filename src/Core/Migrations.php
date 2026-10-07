<?php
/**
 * Versioned, idempotent schema migrations.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Core;

use KHSEO\Findings\FindingRepository;
use KHSEO\Security\Capabilities;

/**
 * Runs each migration step once per site, tracked by khseo_db_version.
 * Phase 1 needs no custom tables; later phases append steps that use dbDelta().
 */
final class Migrations {

	public const OPTION     = 'khseo_db_version';
	public const DB_VERSION = 3;

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
			// A step that fails stops the run WITHOUT advancing the version, so it is retried next request.
			if ( method_exists( self::class, $method ) && true !== self::$method() ) {
				return;
			}
			// Autoloaded: it is read on every request, so it must not cost a query.
			update_option( self::OPTION, $v, true );
		}
	}

	/**
	 * Version 1: options only, no tables.
	 */
	private static function migrate1(): bool {
		add_option( 'khseo_log', array(), '', false );
		return true;
	}

	/**
	 * Version 2: grant default capabilities on this site.
	 *
	 * Covers sites created after network activation (activation never ran there)
	 * and runs once per site; existing role customisations are only ever added to.
	 */
	private static function migrate2(): bool {
		Capabilities::grantDefaults();
		return true;
	}

	/**
	 * Version 3: findings table (per site prefix, so multisite sites never share rows).
	 * Succeeds only if the table really exists afterwards.
	 */
	private static function migrate3(): bool {
		global $wpdb;
		FindingRepository::install();
		$table = FindingRepository::table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
	}
}
