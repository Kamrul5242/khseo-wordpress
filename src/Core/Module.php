<?php
/**
 * Contract every KHSEO engine/module implements.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Core;

/**
 * A module adds WordPress hooks for one engine. Modules talk to each other only
 * through services in the container, never directly.
 */
interface Module {

	/**
	 * Stable module id, e.g. "admin", "rest", "technical".
	 */
	public function id(): string;

	/**
	 * Whether the module should load in this environment (e.g. WooCommerce active).
	 */
	public function isAvailable(): bool;

	/**
	 * Register hooks. Must be cheap: no network calls, no full-site scans.
	 *
	 * @param Container $container Services.
	 */
	public function register( Container $container ): void;
}
