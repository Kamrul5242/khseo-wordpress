<?php
/**
 * REST API: /khseo/v1/*.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\API;

use KHSEO\Audit\AuditRunner;
use KHSEO\Audit\StatusReport;
use KHSEO\Core\Container;
use KHSEO\Core\Module;
use KHSEO\Findings\FindingRepository;
use KHSEO\Fixes\FixService;
use KHSEO\Rules\Rule;
use KHSEO\Security\Capabilities;
use KHSEO\Support\Evidence;
use KHSEO\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Every route has a real permission_callback; nothing here is public. Cookie
 * authentication additionally requires the wp_rest nonce (WordPress core).
 * Arguments are typed and validated; ids from the client are only looked up in
 * the current site's own table and never trusted further.
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
		$cap  = static fn ( string $c ): \Closure => static fn (): bool => current_user_can( $c );
		$both = static fn ( string $c ): \Closure => static fn (): bool => current_user_can( $c ) && current_user_can( 'manage_options' );
		$v    = array(
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_request_arg',
		);
		$hex  = static fn ( int $len, bool $required = true ): array => array(
			'type'     => 'string',
			'pattern'  => '^[0-9a-f]{' . $len . '}$',
			'required' => $required,
		) + $v;

		$this->route( '/status', 'GET', array( $this, 'status' ), $cap( Capabilities::VIEW ) );
		$this->route( '/audit', 'GET', array( $this, 'auditState' ), $cap( Capabilities::VIEW ) );
		$this->route(
			'/audit',
			'POST',
			array( $this, 'auditStart' ),
			$cap( Capabilities::RUN_AUDIT ),
			array(
				'scope' => array(
					'type'     => 'string',
					'enum'     => AuditRunner::SCOPES,
					'required' => true,
				) + $v,
				'url'   => array(
					'type'      => 'string',
					'format'    => 'uri',
					'maxLength' => 2048,
					'default'   => '',
				) + $v,
			)
		);
		$this->route( '/audit/step', 'POST', array( $this, 'auditStep' ), $cap( Capabilities::RUN_AUDIT ) );
		$this->route( '/audit/cancel', 'POST', array( $this, 'auditCancel' ), $cap( Capabilities::RUN_AUDIT ) );

		$enum = static fn ( array $values ): array => array(
			'type' => 'string',
			'enum' => array_merge( array( '' ), $values ),
		) + $v;
		$this->route(
			'/issues',
			'GET',
			array( $this, 'issues' ),
			$cap( Capabilities::VIEW ),
			array(
				'severity' => $enum( array( 'P0', 'P1', 'P2', 'P3' ) ),
				'category' => $enum( Rule::CATEGORIES ),
				'status'   => $enum( FindingRepository::STATUSES ),
				'evidence' => $enum( array_map( static fn ( Evidence $e ): string => $e->value, Evidence::cases() ) ),
				'url'      => array(
					'type'      => 'string',
					'maxLength' => 200,
				) + $v,
				'fixable'  => $enum( array( '0', '1' ) ),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10000,
					'default' => 1,
				) + $v,
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				) + $v,
			)
		);
		$id   = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			) + $v,
		);
		$this->route( '/issues/(?P<id>\d+)', 'GET', array( $this, 'issue' ), $cap( Capabilities::VIEW ), $id );
		$this->route(
			'/issues/(?P<id>\d+)/status',
			'POST',
			array( $this, 'issueStatus' ),
			$cap( Capabilities::EDIT ),
			$id + array(
				'status' => array(
					'type'     => 'string',
					'enum'     => array( 'ignored', 'open' ),
					'required' => true,
				) + $v,
			)
		);
		$this->route(
			'/fixes/preview',
			'POST',
			array( $this, 'fixPreview' ),
			$both( Capabilities::MANAGE ),
			array(
				'issue_id' => array(
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				) + $v,
			)
		);
		$this->route( '/fixes/approve', 'POST', array( $this, 'fixApprove' ), $both( Capabilities::MANAGE ), array( 'change_id' => $hex( 32 ) ) );
		$this->route( '/fixes/apply', 'POST', array( $this, 'fixApply' ), $both( Capabilities::MANAGE ), array( 'change_id' => $hex( 32 ) ) );
		$this->route( '/fixes/rollback', 'POST', array( $this, 'fixRollback' ), $both( Capabilities::ROLLBACK ), array( 'journal_id' => $hex( 16 ) ) );
	}

	/**
	 * Register one route.
	 *
	 * @param non-falsy-string     $path       Path.
	 * @param string               $method     Method.
	 * @param callable             $callback   Handler.
	 * @param callable             $permission Permission callback.
	 * @param array<string, mixed> $args       Arguments.
	 */
	private function route( string $path, string $method, callable $callback, callable $permission, array $args = array() ): void {
		register_rest_route(
			self::NAMESPACE,
			$path,
			array(
				'methods'             => $method,
				'callback'            => $callback,
				'permission_callback' => $permission,
				'args'                => $args,
			)
		);
	}

	/**
	 * GET /status.
	 */
	public function status(): WP_REST_Response {
		return $this->respond( StatusReport::build( $this->services() ) );
	}

	/**
	 * GET /audit.
	 */
	public function auditState(): WP_REST_Response {
		$job = AuditRunner::job();
		return $this->respond(
			array(
				'audit'      => null === $job ? null : AuditRunner::publicJob( $job ),
				'last_audit' => get_option( AuditRunner::LAST, null ),
			)
		);
	}

	/**
	 * POST /audit.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function auditStart( WP_REST_Request $request ): WP_REST_Response {
		$out = $this->runner()->start( (string) $request['scope'], (string) $request['url'], get_current_user_id() );
		$this->services()->get( Logger::class )->log( 'info', 'Audit start requested: ' . (string) $request['scope'] . ' (' . ( $out['ok'] ? 'started' : 'refused' ) . ').', array( 'user' => get_current_user_id() ) );
		return $this->respond( $out, $out['ok'] ? 202 : 409 );
	}

	/**
	 * POST /audit/step.
	 */
	public function auditStep(): WP_REST_Response {
		return $this->respond( array( 'audit' => $this->runner()->step() ) );
	}

	/**
	 * POST /audit/cancel.
	 */
	public function auditCancel(): WP_REST_Response {
		return $this->respond( array( 'cancelled' => AuditRunner::cancel() ) );
	}

	/**
	 * GET /issues.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function issues( WP_REST_Request $request ): WP_REST_Response {
		$filters = array();
		foreach ( array( 'severity', 'category', 'status', 'evidence', 'url', 'fixable' ) as $key ) {
			$filters[ $key ] = (string) ( $request[ $key ] ?? '' );
		}
		$result   = ( new FindingRepository() )->query( $filters, (int) $request['page'], (int) $request['per_page'] );
		$response = $this->respond( $result );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $result['per_page'] ) ) );
		return $response;
	}

	/**
	 * GET /issues/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function issue( WP_REST_Request $request ): WP_REST_Response {
		$issue = ( new FindingRepository() )->find( (int) $request['id'] );
		return null === $issue ? $this->respond( array( 'message' => 'Not found.' ), 404 ) : $this->respond( $issue );
	}

	/**
	 * POST /issues/{id}/status.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function issueStatus( WP_REST_Request $request ): WP_REST_Response {
		$repo = new FindingRepository();
		if ( null === $repo->find( (int) $request['id'] ) ) {
			return $this->respond( array( 'message' => 'Not found.' ), 404 );
		}
		$ok = $repo->setUserStatus( (int) $request['id'], (string) $request['status'] );
		return $this->respond( array( 'ok' => $ok ), $ok ? 200 : 400 );
	}

	/**
	 * POST /fixes/preview.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function fixPreview( WP_REST_Request $request ): WP_REST_Response {
		$issue = ( new FindingRepository() )->find( (int) $request['issue_id'] );
		if ( null === $issue ) {
			return $this->respond( array( 'message' => 'Not found.' ), 404 );
		}
		$proposal = $this->fixes()->preview( $issue['rule_id'] );
		return null === $proposal
			? $this->respond( array( 'message' => 'No fix is available for this issue (or the site no longer needs it).' ), 404 )
			: $this->respond( $proposal->toArray() );
	}

	/**
	 * POST /fixes/approve.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function fixApprove( WP_REST_Request $request ): WP_REST_Response {
		$out = $this->fixes()->approve( (string) $request['change_id'], get_current_user_id() );
		$this->audit( 'Fix approval ' . ( $out['ok'] ? 'recorded' : 'refused' ) . '.' );
		return $this->respond( $out, $out['ok'] ? 200 : 409 );
	}

	/**
	 * POST /fixes/apply.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function fixApply( WP_REST_Request $request ): WP_REST_Response {
		$out = $this->fixes()->apply( (string) $request['change_id'], get_current_user_id() );
		$this->audit( 'Fix apply ' . ( $out['ok'] ? 'succeeded' : 'refused' ) . ': ' . $out['message'] );
		return $this->respond( $out, $out['ok'] ? 200 : 409 );
	}

	/**
	 * POST /fixes/rollback.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function fixRollback( WP_REST_Request $request ): WP_REST_Response {
		$out = $this->fixes()->rollback( (string) $request['journal_id'] );
		$this->audit( 'Fix rollback ' . ( $out['ok'] ? 'succeeded' : 'refused' ) . ': ' . $out['message'] );
		return $this->respond( $out, $out['ok'] ? 200 : 409 );
	}

	/**
	 * Services (registered during boot).
	 */
	private function services(): Container {
		return $this->container ?? \KHSEO\Core\Plugin::instance()->container();
	}

	/**
	 * Audit runner.
	 */
	private function runner(): AuditRunner {
		return $this->services()->get( AuditRunner::class );
	}

	/**
	 * Fix service.
	 */
	private function fixes(): FixService {
		return $this->services()->get( FixService::class );
	}

	/**
	 * Security log entry for state-changing actions.
	 *
	 * @param string $message Message.
	 */
	private function audit( string $message ): void {
		$this->services()->get( Logger::class )->log( 'security', $message, array( 'user' => get_current_user_id() ) );
	}

	/**
	 * JSON response that is never cached.
	 *
	 * @param mixed $data   Data.
	 * @param int   $status Status.
	 */
	private function respond( mixed $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
