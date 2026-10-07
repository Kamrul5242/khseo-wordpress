<?php
/**
 * KHSEO kernel: builds services and boots modules.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Core;

use KHSEO\Admin\AdminModule;
use KHSEO\Audit\AuditModule;
use KHSEO\Audit\AuditRunner;
use KHSEO\Findings\FindingRepository;
use KHSEO\Fixes\FixService;
use KHSEO\API\RestModule;
use KHSEO\Http\FetchPolicy;
use KHSEO\Http\SafeFetcher;
use KHSEO\Http\WpHttpTransport;
use KHSEO\Rules\RuleRegistry;
use KHSEO\Security\Redactor;
use KHSEO\Security\SecretStore;
use KHSEO\Security\UrlGuard;
use KHSEO\Settings\Settings;
use KHSEO\Settings\SettingsModule;
use KHSEO\Support\Logger;

/**
 * Singleton entry point; everything else is a service.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Services.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * Service container (for modules and extensions).
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Boot once on plugins_loaded.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'khseo', false, dirname( plugin_basename( KHSEO_FILE ) ) . '/languages' );
		Migrations::maybeRun();
		$this->registerServices();

		/**
		 * Filter the module list. Extension point for future engines.
		 *
		 * @param array<int, Module> $modules Modules to boot.
		 */
		$modules = apply_filters( 'khseo_modules', array( new SettingsModule(), new AuditModule(), new AdminModule(), new RestModule() ) );
		foreach ( $modules as $module ) {
			if ( $module instanceof Module && $module->isAvailable() ) {
				$module->register( $this->container );
			}
		}

		/**
		 * Fires after KHSEO has booted.
		 *
		 * @param Container $container KHSEO services.
		 */
		do_action( 'khseo_loaded', $this->container );
	}

	/**
	 * Core services.
	 */
	private function registerServices(): void {
		$c = $this->container;
		$c->set( 'settings', static fn (): array => Settings::normalize( get_option( Settings::OPTION, array() ) ) );
		$c->set( RuleRegistry::class, static fn (): RuleRegistry => RuleRegistry::fromFile( KHSEO_DIR . 'config/rules.php' ) );
		$c->set( Redactor::class, static fn (): Redactor => new Redactor() );
		// Null when the site has no safe key material; callers must handle that (no fatal errors).
		$c->set( SecretStore::class, static fn (): ?SecretStore => SecretStore::forSite() );
		$c->set( FindingRepository::class, static fn (): FindingRepository => new FindingRepository() );
		$c->set( AuditRunner::class, static fn ( Container $c ): AuditRunner => new AuditRunner( $c->get( RuleRegistry::class ), $c->get( FindingRepository::class ), $c->get( 'settings' ) ) );
		$c->set( FixService::class, static fn ( Container $c ): FixService => new FixService( $c->get( RuleRegistry::class ), (bool) $c->get( 'settings' )['safe_auto_fixes'] ) );
		$c->set( SafeFetcher::class, static fn (): SafeFetcher => new SafeFetcher( new UrlGuard(), new WpHttpTransport(), new FetchPolicy() ) );
		$c->set(
			Logger::class,
			static function ( Container $c ): Logger {
				$settings = $c->get( 'settings' );
				return new Logger( $c->get( Redactor::class ), (string) $settings['log_level'], (int) $settings['log_retention_days'] );
			}
		);
	}
}
