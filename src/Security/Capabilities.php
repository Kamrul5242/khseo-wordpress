<?php
/**
 * KHSEO capabilities and their default role mapping.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Security;

/**
 * Administrators get every KHSEO capability; editors get read-only access.
 */
final class Capabilities {

	public const VIEW         = 'view_khseo';
	public const RUN_AUDIT    = 'run_khseo_audit';
	public const EDIT         = 'edit_khseo';
	public const MANAGE       = 'manage_khseo';
	public const SETTINGS     = 'manage_khseo_settings';
	public const AI           = 'manage_khseo_ai';
	public const INTEGRATIONS = 'manage_khseo_integrations';
	public const ROLLBACK     = 'rollback_khseo_changes';

	/**
	 * Every KHSEO capability.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::VIEW,
			self::RUN_AUDIT,
			self::EDIT,
			self::MANAGE,
			self::SETTINGS,
			self::AI,
			self::INTEGRATIONS,
			self::ROLLBACK,
		);
	}

	/**
	 * Default grants per role. Editors must never receive admin-level powers by default.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function defaultMap(): array {
		return array(
			'administrator' => self::all(),
			'editor'        => array( self::VIEW ),
		);
	}

	/**
	 * Add default capabilities to existing roles (on activation).
	 */
	public static function grantDefaults(): void {
		foreach ( self::defaultMap() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove every KHSEO capability from every role (only on opted-in uninstall).
	 */
	public static function revokeAll(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
