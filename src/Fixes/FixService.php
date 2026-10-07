<?php
/**
 * Preview → approve → apply → validate → journal → rollback, always through ChangeGate.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Fixes;

use KHSEO\Governance\ChangeGate;
use KHSEO\Rules\RuleRegistry;
use KHSEO\Support\Risk;

/**
 * The only component allowed to modify site data on behalf of an SEO finding.
 *
 * Fixes available in this version: SEO-INDEX-001 (Settings → Reading → search
 * engine visibility). Its risk comes from the rule (R3), so it needs an explicit
 * approval of this exact change AND a recovery point, which is the journal entry
 * written before the change. Approvals are single-use and expire after one hour.
 */
final class FixService {

	public const APPROVALS = 'khseo_fix_approvals';
	public const JOURNAL   = 'khseo_change_journal';
	public const MAX_LOG   = 100;
	public const TTL       = 3600;

	/**
	 * Constructor.
	 *
	 * @param RuleRegistry $rules      Rules (risk comes from the rule, never from the caller).
	 * @param bool         $automation Safe (R1) auto-fixes enabled in settings.
	 */
	public function __construct( private RuleRegistry $rules, private bool $automation ) {}

	/**
	 * Rule ids that have a fix in this version.
	 *
	 * @return array<int, string>
	 */
	public static function fixableRules(): array {
		return array( 'SEO-INDEX-001' );
	}

	/**
	 * Proposal for a rule given the CURRENT site state, or null when nothing needs fixing.
	 *
	 * @param string $rule_id Rule id.
	 */
	public function preview( string $rule_id ): ?FixProposal {
		$rule = $this->rules->get( $rule_id );
		if ( null === $rule || 'SEO-INDEX-001' !== $rule_id ) {
			return null;
		}
		if ( '0' !== (string) get_option( 'blog_public', '1' ) ) {
			return null; // Already visible: nothing to change.
		}
		return new FixProposal(
			$rule_id,
			$rule->risk,
			'Allow search engines to index this site',
			$rule->condition,
			'option:blog_public',
			'0 (Discourage search engines: ticked)',
			'1 (Discourage search engines: unticked)',
			array( 'option blog_public', home_url( '/' ) ),
			'Restore blog_public to 0 from the change journal (blocked if it was changed again since).',
			'Re-read blog_public and confirm it is 1.',
			'manage_options'
		);
	}

	/**
	 * Record an approval for an exact change id, if it matches a current proposal.
	 *
	 * @param string $change_id Change id from preview().
	 * @param int    $user_id   Approving user.
	 * @return array{ok: bool, message: string}
	 */
	public function approve( string $change_id, int $user_id ): array {
		$proposal = $this->current( $change_id );
		if ( null === $proposal ) {
			return self::result( false, 'No current proposal has this change id. Preview the fix again.' );
		}
		if ( ! current_user_can( $proposal->wp_cap ) ) {
			return self::result( false, 'This fix also needs the WordPress capability ' . $proposal->wp_cap . '.' );
		}
		$approvals               = self::approvals();
		$approvals[ $change_id ] = array(
			'user'    => $user_id,
			'expires' => time() + self::TTL,
		);
		update_option( self::APPROVALS, $approvals, false );
		return self::result( true, 'Approved. The approval covers only this change and expires in one hour.' );
	}

	/**
	 * Apply an approved change through ChangeGate, then validate and journal it.
	 *
	 * @param string $change_id Change id.
	 * @param int    $user_id   User.
	 * @return array{ok: bool, message: string, journal_id?: string}
	 */
	public function apply( string $change_id, int $user_id ): array {
		$proposal = $this->current( $change_id );
		if ( null === $proposal ) {
			return self::result( false, 'The site no longer matches this proposal (it may already be fixed). Preview again.' );
		}
		if ( ! current_user_can( $proposal->wp_cap ) ) {
			return self::result( false, 'This fix also needs the WordPress capability ' . $proposal->wp_cap . '.' );
		}
		$approvals = self::approvals();
		$approved  = isset( $approvals[ $change_id ] ) && $approvals[ $change_id ]['expires'] >= time() ? $change_id : null;

		// Recovery point first: the journal entry holds the value to restore.
		$journal_id = $this->journal(
			array(
				'change_id' => $change_id,
				'rule_id'   => $proposal->rule_id,
				'target'    => $proposal->target,
				'before'    => '0',
				'after'     => '1',
				'user'      => $user_id,
				'status'    => 'prepared',
			)
		);
		$recovery   = null === $journal_id ? 'none' : 'available';
		$decision   = ChangeGate::decide( $proposal->risk, true, $this->automation, $approved, $change_id, $recovery );
		if ( ChangeGate::ALLOW !== $decision['decision'] ) {
			$this->setJournalStatus( $journal_id, 'denied' );
			return self::result( false, 'Not applied: ' . $decision['reason'] );
		}

		unset( $approvals[ $change_id ] ); // Single use.
		update_option( self::APPROVALS, $approvals, false );
		update_option( 'blog_public', '1' );

		if ( '1' !== (string) get_option( 'blog_public' ) ) {
			update_option( 'blog_public', '0' );
			$this->setJournalStatus( $journal_id, 'failed_validation_restored' );
			return self::result( false, 'Validation failed; the previous value was restored.' );
		}
		$this->setJournalStatus( $journal_id, 'applied' );
		return array(
			'ok'         => true,
			'message'    => 'Applied and validated: blog_public is now 1. You can roll this back from the journal.',
			'journal_id' => (string) $journal_id,
		);
	}

	/**
	 * Roll back a journaled change, only if the current value is still what KHSEO wrote.
	 *
	 * @param string $journal_id Journal entry id.
	 * @return array{ok: bool, message: string}
	 */
	public function rollback( string $journal_id ): array {
		$log   = self::log();
		$entry = $log[ $journal_id ] ?? null;
		if ( ! is_array( $entry ) || 'applied' !== ( $entry['status'] ?? '' ) ) {
			return self::result( false, 'No applied change with this journal id.' );
		}
		if ( 'option:blog_public' !== $entry['target'] || ! current_user_can( 'manage_options' ) ) {
			return self::result( false, 'Rollback is not permitted for this entry.' );
		}
		if ( (string) get_option( 'blog_public' ) !== (string) $entry['after'] ) {
			$this->setJournalStatus( $journal_id, 'rollback_blocked' );
			return self::result( false, 'ROLLBACK BLOCKED: the current value differs from what KHSEO wrote, so it was changed since. Nothing was modified.' );
		}
		update_option( 'blog_public', (string) $entry['before'] );
		$ok = (string) get_option( 'blog_public' ) === (string) $entry['before'];
		$this->setJournalStatus( $journal_id, $ok ? 'rolled_back' : 'rollback_failed' );
		return self::result( $ok, $ok ? 'Rolled back: blog_public is ' . $entry['before'] . ' again.' : 'Rollback could not be validated.' );
	}

	/**
	 * Journal entries, newest first.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function log(): array {
		$log = get_option( self::JOURNAL, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Current proposal matching a change id.
	 *
	 * @param string $change_id Change id.
	 */
	private function current( string $change_id ): ?FixProposal {
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $change_id ) ) {
			return null;
		}
		foreach ( self::fixableRules() as $rule_id ) {
			$proposal = $this->preview( $rule_id );
			if ( null !== $proposal && hash_equals( $proposal->changeId(), $change_id ) ) {
				return $proposal;
			}
		}
		return null;
	}

	/**
	 * Write a journal entry; returns its id or null if it could not be stored.
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	private function journal( array $entry ): ?string {
		$id     = bin2hex( random_bytes( 8 ) );
		$log    = array( $id => $entry + array( 'time' => time() ) ) + self::log();
		$log    = array_slice( $log, 0, self::MAX_LOG, true );
		$saved  = update_option( self::JOURNAL, $log, false );
		$stored = self::log();
		return $saved && isset( $stored[ $id ] ) ? $id : null;
	}

	/**
	 * Update an entry's status.
	 *
	 * @param string|null $id     Entry id.
	 * @param string      $status Status.
	 */
	private function setJournalStatus( ?string $id, string $status ): void {
		if ( null === $id ) {
			return;
		}
		$log = self::log();
		if ( isset( $log[ $id ] ) ) {
			$log[ $id ]['status']     = $status;
			$log[ $id ]['updated_at'] = time();
			update_option( self::JOURNAL, $log, false );
		}
	}

	/**
	 * Approvals with expired ones removed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function approvals(): array {
		$a = get_option( self::APPROVALS, array() );
		$a = is_array( $a ) ? $a : array();
		return array_filter( $a, static fn ( $x ): bool => is_array( $x ) && (int) ( $x['expires'] ?? 0 ) >= time() );
	}

	/**
	 * Result shape.
	 *
	 * @param bool   $ok      Success.
	 * @param string $message Message.
	 * @return array{ok: bool, message: string}
	 */
	private static function result( bool $ok, string $message ): array {
		return array(
			'ok'      => $ok,
			'message' => $message,
		);
	}
}
