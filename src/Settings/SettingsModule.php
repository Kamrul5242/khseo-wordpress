<?php
/**
 * Registers the settings option and its sanitizer on every request.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Settings;

use KHSEO\Core\Container;
use KHSEO\Core\Module;

/**
 * Always available: the sanitizer must run for every write path (options.php,
 * REST, WP-CLI, other plugins), not only for saves made in wp-admin.
 */
final class SettingsModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'settings';
	}

	/**
	 * Always available.
	 */
	public function isAvailable(): bool {
		return true;
	}

	/**
	 * Register on init.
	 *
	 * @param Container $container Services.
	 */
	public function register( Container $container ): void {
		add_action( 'init', array( $this, 'registerSetting' ) );
	}

	/**
	 * Register the option with WordPress.
	 */
	public function registerSetting(): void {
		register_setting(
			Settings::OPTION,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => static fn ( $input ): array => Settings::sanitize( is_array( $input ) ? $input : array(), Settings::normalize( get_option( Settings::OPTION, array() ) ) ),
				'show_in_rest'      => false,
				'default'           => Settings::DEFAULTS,
			)
		);
	}
}
