<?php
/**
 * REST API: /khseo/v1/*.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\API;

use KHSEO\Audit\StatusReport;
use KHSEO\Core\Container;
use KHSEO\Core\Module;
use KHSEO\Security\Capabilities;
use WP_REST_Response;

/**
 * Every route has a real permission callback; nothing here is public.
 */
final class RestModule implements Module {

	public const NAMESPACE = 'khseo/v1';

	/**
	 * Services.
	 *
	 * @var Container|null
	 */
	private ?Container $container = null;

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'rest';
	}

	/**
	 * Always available.
	 */
	public function isAvailable(): bool {
		return true;
	}

	/**
	 * Register routes on rest_api_init.
	 *
	 * @param Container $container Services.
	 */
	public function register( Container $container ): void {
		$this->container = $container;
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::VIEW ),
				'args'                => array(),
			)
		);
	}

	/**
	 * GET /status.
	 */
	public function status(): WP_REST_Response {
		if ( null === $this->container ) {
			return new WP_REST_Response( array( 'message' => 'KHSEO is not booted.' ), 503 );
		}
		$response = new WP_REST_Response( StatusReport::build( $this->container ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
