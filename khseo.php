<?php
/**
 * Plugin Name:       KHSEO
 * Plugin URI:        https://github.com/Kamrul5242/khseo-wordpress
 * Description:       Evidence-first, security-first SEO platform for WordPress. Works fully without an AI API; AI is an optional layer.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Kamrul Hasan
 * Author URI:        https://github.com/Kamrul5242
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       khseo
 * Domain Path:       /languages
 *
 * @package KHSEO
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KHSEO_VERSION', '0.1.0' );
define( 'KHSEO_FILE', __FILE__ );
define( 'KHSEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'KHSEO_URL', plugin_dir_url( __FILE__ ) );
define( 'KHSEO_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, KHSEO_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' . esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version. */
					__( 'KHSEO requires PHP %1$s or newer. This site runs PHP %2$s, so KHSEO is inactive.', 'khseo' ),
					KHSEO_MIN_PHP,
					PHP_VERSION
				)
			) . '</p></div>';
		}
	);
	return;
}

require_once KHSEO_DIR . 'src/Autoloader.php';
KHSEO\Autoloader::register( KHSEO_DIR . 'src/' );

register_activation_hook( __FILE__, array( KHSEO\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( KHSEO\Core\Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		KHSEO\Core\Plugin::instance()->boot();
	}
);
