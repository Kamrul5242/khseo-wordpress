<?php
/**
 * Uninstall: deletes KHSEO data ONLY if the user opted in (default: keep everything).
 *
 * @package KHSEO
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Autoloader.php';
KHSEO\Autoloader::register( __DIR__ . '/src/' );

/**
 * Delete this site's KHSEO data if its settings allow it.
 */
$khseo_uninstall_site = static function (): void {
	$settings = KHSEO\Settings\Settings::normalize( get_option( KHSEO\Settings\Settings::OPTION, array() ) );
	if ( true !== $settings['delete_data_on_uninstall'] ) {
		return;
	}
	foreach ( array( 'khseo_settings', 'khseo_secrets', 'khseo_log', 'khseo_db_version' ) as $option ) {
		delete_option( $option );
	}
	KHSEO\Security\Capabilities::revokeAll();
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $khseo_site_id ) {
		switch_to_blog( (int) $khseo_site_id );
		$khseo_uninstall_site();
		restore_current_blog();
	}
} else {
	$khseo_uninstall_site();
}
