<?php
/**
 * Admin shell: menu, Overview and Settings screens.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Admin;

use KHSEO\Audit\AuditRunner;
use KHSEO\Audit\StatusReport;
use KHSEO\Core\Container;
use KHSEO\Core\Module;
use KHSEO\Security\Capabilities;
use KHSEO\Security\SecretStore;
use KHSEO\Settings\Settings;
use KHSEO\Support\Logger;

/**
 * Registers the KHSEO menu. Assets load only on KHSEO screens.
 */
final class AdminModule implements Module {

	public const SLUG          = 'khseo';
	public const SETTINGS_SLUG = 'khseo-settings';
	public const SECRET_ACTION = 'khseo_save_ai_key';

	/**
	 * Services.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Hook suffixes of KHSEO screens.
	 *
	 * @var array<int, string>
	 */
	private array $screens = array();

	/**
	 * Audit, issue and fix screens.
	 *
	 * @var AuditScreens|null
	 */
	private ?AuditScreens $audit_screens = null;

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'admin';
	}

	/**
	 * Only in wp-admin.
	 */
	public function isAvailable(): bool {
		return is_admin();
	}

	/**
	 * Register hooks.
	 *
	 * @param Container $container Services.
	 */
	public function register( Container $container ): void {
		$this->container = $container;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_' . self::SECRET_ACTION, array( $this, 'saveAiKey' ) );
		add_filter( 'option_page_capability_' . Settings::OPTION, static fn (): string => Capabilities::SETTINGS );
		$this->audit_screens = new AuditScreens( $container );
		$this->audit_screens->register();
	}

	/**
	 * Menu pages.
	 */
	public function menu(): void {
		$this->screens[] = (string) add_menu_page(
			__( 'KHSEO', 'khseo' ),
			__( 'KHSEO', 'khseo' ),
			Capabilities::VIEW,
			self::SLUG,
			array( $this, 'renderOverview' ),
			'dashicons-search',
			81
		);
		$this->screens[] = (string) add_submenu_page( self::SLUG, __( 'Overview', 'khseo' ), __( 'Overview', 'khseo' ), Capabilities::VIEW, self::SLUG, array( $this, 'renderOverview' ) );
		$this->screens[] = (string) add_submenu_page( self::SLUG, __( 'Issues', 'khseo' ), __( 'Issues', 'khseo' ), Capabilities::VIEW, AuditScreens::ISSUES_SLUG, array( $this, 'renderIssues' ) );
		$this->screens[] = (string) add_submenu_page( self::SLUG, __( 'Settings', 'khseo' ), __( 'Settings', 'khseo' ), Capabilities::SETTINGS, self::SETTINGS_SLUG, array( $this, 'renderSettings' ) );
	}

	/**
	 * Enqueue admin CSS only on KHSEO screens.
	 *
	 * @param string $hook_suffix Current screen hook.
	 */
	public function assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->screens, true ) ) {
			return;
		}
		wp_enqueue_style( 'khseo-admin', KHSEO_URL . 'assets/css/admin.css', array(), KHSEO_VERSION );
		$job = AuditRunner::job();
		if ( $hook_suffix === $this->screens[0] && null !== $job && 'running' === $job['status'] && current_user_can( Capabilities::RUN_AUDIT ) ) {
			// Steps the running audit via REST (cookie auth + wp_rest nonce). No secrets are passed to JS.
			wp_enqueue_script( 'khseo-admin', KHSEO_URL . 'assets/js/admin.js', array(), KHSEO_VERSION, true );
			wp_localize_script(
				'khseo-admin',
				'khseoAdmin',
				array(
					'stepUrl' => esc_url_raw( rest_url( 'khseo/v1/audit/step' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
	}

	/**
	 * Issues screen.
	 */
	public function renderIssues(): void {
		( $this->audit_screens ?? new AuditScreens( $this->container ) )->renderIssues();
	}

	/**
	 * Overview screen.
	 */
	public function renderOverview(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view KHSEO.', 'khseo' ), 403 );
		}
		$report = StatusReport::build( $this->container );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only message from our own redirect.
		$notice = isset( $_GET['khseo_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['khseo_msg'] ) ) : '';
		View::render(
			'overview',
			array(
				'report'    => $report,
				'notice'    => $notice,
				'can_audit' => current_user_can( Capabilities::RUN_AUDIT ),
			)
		);
	}

	/**
	 * Settings screen.
	 */
	public function renderSettings(): void {
		if ( ! current_user_can( Capabilities::SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to change KHSEO settings.', 'khseo' ), 403 );
		}
		$store   = $this->container->get( SecretStore::class );
		$secrets = get_option( 'khseo_secrets', array() );
		$stored  = is_array( $secrets ) ? ( $secrets['ai_api_key'] ?? '' ) : '';
		$mask    = '';
		if ( '' !== $stored ) {
			$plain = null === $store ? null : $store->decrypt( $stored );
			$mask  = null === $plain ? __( 'A saved key exists but cannot be decrypted (site keys changed or encryption unavailable). Enter it again.', 'khseo' ) : SecretStore::mask( $plain );
		}
		View::render(
			'settings',
			array(
				'settings'      => Settings::normalize( get_option( Settings::OPTION, array() ) ),
				'ai_key_mask'   => $mask,
				'can_manage_ai' => current_user_can( Capabilities::AI ),
				'can_encrypt'   => null !== $store,
			)
		);
	}

	/**
	 * Save or remove the AI API key (admin-post handler). Key is encrypted and never echoed.
	 */
	public function saveAiKey(): void {
		if ( ! current_user_can( Capabilities::AI ) ) {
			wp_die( esc_html__( 'You do not have permission to manage AI settings.', 'khseo' ), 403 );
		}
		check_admin_referer( self::SECRET_ACTION );

		$secrets = get_option( 'khseo_secrets', array() );
		$secrets = is_array( $secrets ) ? $secrets : array();
		$status  = 'unchanged';

		if ( isset( $_POST['khseo_remove_key'] ) ) {
			unset( $secrets['ai_api_key'] );
			$status = 'removed';
		} else {
			// Not sanitize_text_field(): keys may contain characters it would strip. Only trim and length-limit.
			$key = isset( $_POST['khseo_ai_api_key'] ) ? trim( (string) wp_unslash( $_POST['khseo_ai_api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below, encrypted, never output.
			if ( '' !== $key ) {
				$store = $this->container->get( SecretStore::class );
				if ( strlen( $key ) > 512 || preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
					$status = 'invalid';
				} elseif ( null === $store ) {
					// Never store a key we cannot encrypt.
					$status = 'no_encryption';
				} else {
					$secrets['ai_api_key'] = $store->encrypt( $key );
					$status                = 'saved';
				}
			}
		}
		update_option( 'khseo_secrets', $secrets, false );
		$this->container->get( Logger::class )->log( 'security', 'AI API key ' . $status . '.', array( 'user' => get_current_user_id() ) );

		$args = array(
			'page'      => self::SETTINGS_SLUG,
			'khseo_key' => $status,
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
