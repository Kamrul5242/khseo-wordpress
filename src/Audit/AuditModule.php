<?php
/**
 * Runs queued audit steps from WP-Cron.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use KHSEO\Core\Container;
use KHSEO\Core\Module;
use KHSEO\Support\Logger;

/**
 * Cron is a fallback driver; the dashboard can also step an audit directly.
 * Steps are bounded (pages, bytes, seconds) and locked against overlap.
 */
final class AuditModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'audit';
	}

	/**
	 * Always available.
	 */
	public function isAvailable(): bool {
		return true;
	}

	/**
	 * Register the cron hook.
	 *
	 * @param Container $container Services.
	 */
	public function register( Container $container ): void {
		add_action(
			AuditRunner::CRON_HOOK,
			static function () use ( $container ): void {
				try {
					$container->get( AuditRunner::class )->step();
				} catch ( \Throwable $e ) {
					$container->get( Logger::class )->log( 'error', 'Audit step failed: ' . $e->getMessage() );
				}
			}
		);
	}
}
