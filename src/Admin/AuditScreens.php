<?php
/**
 * Admin screens and form handlers for audits, issues and fixes.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Admin;

use KHSEO\Audit\AuditRunner;
use KHSEO\Core\Container;
use KHSEO\Findings\FindingRepository;
use KHSEO\Fixes\FixService;
use KHSEO\Rules\RuleRegistry;
use KHSEO\Security\Capabilities;
use KHSEO\Support\Logger;

/**
 * Every state-changing action is an admin-post handler with a capability check,
 * a nonce, validated input and a safe redirect. Output is escaped in templates.
 */
final class AuditScreens {

	public const ISSUES_SLUG = 'khseo-issues';

	/**
	 * Constructor.
	 *
	 * @param Container $container Services.
	 */
	public function __construct( private Container $container ) {}

	/**
	 * Register handlers.
	 */
	public function register(): void {
		$handlers = array(
			'khseo_audit_start'  => array( $this, 'auditStart' ),
			'khseo_audit_step'   => array( $this, 'auditStep' ),
			'khseo_audit_cancel' => array( $this, 'auditCancel' ),
			'khseo_issue_status' => array( $this, 'issueStatus' ),
			'khseo_fix_approve'  => array( $this, 'fixApprove' ),
			'khseo_fix_apply'    => array( $this, 'fixApply' ),
			'khseo_fix_rollback' => array( $this, 'fixRollback' ),
		);
		foreach ( $handlers as $action => $callback ) {
			add_action( 'admin_post_' . $action, $callback );
		}
	}

	/**
	 * Issues list / detail screen.
	 */
	public function renderIssues(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view KHSEO.', 'khseo' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters; every value is allow-listed by FindingRepository.
		$repo = $this->repo();
		$id   = isset( $_GET['issue'] ) ? absint( $_GET['issue'] ) : 0;
		if ( $id > 0 ) {
			$issue    = $repo->find( $id );
			$proposal = null === $issue ? null : $this->container->get( FixService::class )->preview( $issue['rule_id'] );
			$rule     = null === $issue ? null : $this->container->get( RuleRegistry::class )->get( $issue['rule_id'] );
			View::render(
				'issue',
				array(
					'issue'    => $issue,
					'rule'     => $rule,
					'proposal' => null === $proposal ? null : $proposal->toArray(),
					'can_fix'  => current_user_can( Capabilities::MANAGE ) && current_user_can( 'manage_options' ),
					'can_edit' => current_user_can( Capabilities::EDIT ),
					'journal'  => FixService::log(),
					'notice'   => isset( $_GET['khseo_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['khseo_msg'] ) ) : '',
				)
			);
			return;
		}
		$filters = array();
		foreach ( array( 'severity', 'category', 'status', 'evidence', 'url', 'fixable' ) as $key ) {
			$filters[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		View::render(
			'issues',
			array(
				'result'  => $repo->query( $filters, $page, 25 ),
				'filters' => $filters,
			)
		);
	}

	/**
	 * Start an audit.
	 */
	public function auditStart(): void {
		$this->requireCap( Capabilities::RUN_AUDIT );
		check_admin_referer( 'khseo_audit_start' );
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$out   = $this->container->get( AuditRunner::class )->start( $scope, $url, get_current_user_id() );
		if ( $out['ok'] ) {
			$this->container->get( AuditRunner::class )->step(); // First batch now; the rest via the dashboard or cron.
		}
		$this->back( AdminModule::SLUG, $out['message'] );
	}

	/**
	 * Process one batch (works without JavaScript).
	 */
	public function auditStep(): void {
		$this->requireCap( Capabilities::RUN_AUDIT );
		check_admin_referer( 'khseo_audit_step' );
		$this->container->get( AuditRunner::class )->step();
		$this->back( AdminModule::SLUG, '' );
	}

	/**
	 * Cancel the running audit.
	 */
	public function auditCancel(): void {
		$this->requireCap( Capabilities::RUN_AUDIT );
		check_admin_referer( 'khseo_audit_cancel' );
		$this->back( AdminModule::SLUG, AuditRunner::cancel() ? 'Audit cancelled.' : 'No audit was running.' );
	}

	/**
	 * Ignore / re-open an issue.
	 */
	public function issueStatus(): void {
		$this->requireCap( Capabilities::EDIT );
		check_admin_referer( 'khseo_issue_status' );
		$id     = isset( $_POST['issue'] ) ? absint( $_POST['issue'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$ok     = null !== $this->repo()->find( $id ) && $this->repo()->setUserStatus( $id, $status );
		$this->back( self::ISSUES_SLUG, $ok ? 'Issue updated.' : 'Issue not updated.', $id );
	}

	/**
	 * Approve a fix.
	 */
	public function fixApprove(): void {
		$this->fixAction( 'khseo_fix_approve', 'approve' );
	}

	/**
	 * Apply an approved fix.
	 */
	public function fixApply(): void {
		$this->fixAction( 'khseo_fix_apply', 'apply' );
	}

	/**
	 * Roll back a journaled fix.
	 */
	public function fixRollback(): void {
		$this->requireCap( Capabilities::ROLLBACK );
		check_admin_referer( 'khseo_fix_rollback' );
		$journal = isset( $_POST['journal_id'] ) ? sanitize_key( wp_unslash( $_POST['journal_id'] ) ) : '';
		$issue   = isset( $_POST['issue'] ) ? absint( $_POST['issue'] ) : 0;
		$out     = 1 === preg_match( '/^[0-9a-f]{16}$/', $journal ) ? $this->container->get( FixService::class )->rollback( $journal ) : array( 'message' => 'Invalid journal id.' );
		$this->log( 'Fix rollback: ' . $out['message'] );
		$this->back( self::ISSUES_SLUG, $out['message'], $issue );
	}

	/**
	 * Shared approve/apply handler.
	 *
	 * @param string $action Nonce action.
	 * @param string $method approve | apply.
	 */
	private function fixAction( string $action, string $method ): void {
		$this->requireCap( Capabilities::MANAGE );
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'This fix changes a core WordPress setting and needs the manage_options capability.', 'khseo' ), 403 );
		}
		$change = isset( $_POST['change_id'] ) ? sanitize_key( wp_unslash( $_POST['change_id'] ) ) : '';
		$issue  = isset( $_POST['issue'] ) ? absint( $_POST['issue'] ) : 0;
		$out    = 1 === preg_match( '/^[0-9a-f]{32}$/', $change )
			? $this->container->get( FixService::class )->{$method}( $change, get_current_user_id() )
			: array( 'message' => 'Invalid change id.' );
		$this->log( 'Fix ' . $method . ': ' . $out['message'] );
		$this->back( self::ISSUES_SLUG, $out['message'], $issue );
	}

	/**
	 * Capability check, or die with 403. Each handler then verifies its own nonce.
	 *
	 * @param string $cap Capability.
	 */
	private function requireCap( string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'khseo' ), 403 );
		}
	}

	/**
	 * Redirect back with a short message.
	 *
	 * @param string $page    Admin page slug.
	 * @param string $message Message (plain text).
	 * @param int    $issue   Issue id for the detail view.
	 */
	private function back( string $page, string $message, int $issue = 0 ): void {
		$args = array( 'page' => $page );
		if ( '' !== $message ) {
			$args['khseo_msg'] = rawurlencode( mb_substr( $message, 0, 300 ) );
		}
		if ( $issue > 0 ) {
			$args['issue'] = $issue;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Security log.
	 *
	 * @param string $message Message.
	 */
	private function log( string $message ): void {
		$this->container->get( Logger::class )->log( 'security', $message, array( 'user' => get_current_user_id() ) );
	}

	/**
	 * Repository.
	 */
	private function repo(): FindingRepository {
		return $this->container->get( FindingRepository::class );
	}
}
