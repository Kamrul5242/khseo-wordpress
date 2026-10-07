<?php
/**
 * Activation and deactivation.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Core;

use KHSEO\Security\Capabilities;
use KHSEO\Settings\Settings;

/**
 * Activation grants capabilities and seeds defaults. Deactivation never deletes data.
 */
final class Activator {

	/**
	 * Activate (per site; on network activation, for every site).
	 *
	 * @param bool $network_wide Whether network-activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activateSite();
				restore_current_blog();
			}
			return;
		}
		self::activateSite();
	}

	/**
	 * Deactivate: only stop scheduled work. Data and capabilities stay.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'khseo_daily_health' );
		wp_clear_scheduled_hook( 'khseo_audit_step' );
	}

	/**
	 * Per-site activation.
	 */
	private static function activateSite(): void {
		Capabilities::grantDefaults();
		add_option( Settings::OPTION, Settings::DEFAULTS, '', false );
		Migrations::maybeRun();
	}
}
