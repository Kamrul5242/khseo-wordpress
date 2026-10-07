<?php
/**
 * The single decision point for whether a proposed change may be applied.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Governance;

use KHSEO\Support\Risk;

/**
 * Enforces R0–R4 for every future engine. Engines propose; this gate decides.
 *
 * R0 read only            → nothing to apply
 * R1 safe + reversible    → auto only if the user enabled safe auto-fixes, else explicit approval
 * R2 review required      → explicit approval for this change
 * R3 confirm + recovery   → explicit approval AND a recovery point (snapshot/backup)
 * R4 confirm + verified   → explicit approval AND a recovery point that was verified restorable
 *
 * "Approval" means the user approved THIS change set (matching id), not a generic "fix everything".
 */
final class ChangeGate {

	public const ALLOW = 'allow';
	public const DENY  = 'deny';

	/**
	 * Decide.
	 *
	 * @param Risk        $risk               Risk of the change.
	 * @param bool        $reversible         Whether the change can be undone.
	 * @param bool        $automation_enabled User enabled safe (R1) auto-fixes.
	 * @param string|null $approved_change_id Id the user approved, or null.
	 * @param string      $change_id          Id of this change set.
	 * @param string      $recovery           'none' | 'available' | 'verified'.
	 * @return array{decision: string, reason: string}
	 */
	public static function decide( Risk $risk, bool $reversible, bool $automation_enabled, ?string $approved_change_id, string $change_id, string $recovery = 'none' ): array {
		if ( Risk::R0 === $risk ) {
			return self::deny( 'R0 is read-only; there is nothing to apply.' );
		}
		$approved = null !== $approved_change_id && '' !== $change_id && hash_equals( $change_id, $approved_change_id );

		if ( Risk::R1 === $risk ) {
			if ( ! $reversible ) {
				return self::deny( 'An irreversible change cannot be treated as R1.' );
			}
			if ( $automation_enabled || $approved ) {
				return self::allow( $automation_enabled ? 'R1 safe fix; automation is enabled.' : 'R1 fix approved.' );
			}
			return self::deny( 'R1 fix needs approval (safe auto-fixes are off).' );
		}
		if ( ! $approved ) {
			return self::deny( $risk->value . ' change needs explicit approval of this change set.' );
		}
		if ( Risk::R2 === $risk ) {
			return self::allow( 'R2 change approved.' );
		}
		if ( Risk::R3 === $risk ) {
			return in_array( $recovery, array( 'available', 'verified' ), true )
				? self::allow( 'R3 change approved with a recovery point.' )
				: self::deny( 'R3 change needs a recovery point before applying.' );
		}
		return 'verified' === $recovery
			? self::allow( 'R4 change approved with a verified recovery point.' )
			: self::deny( 'R4 change needs a verified (restorable) recovery point.' );
	}

	/**
	 * Allow.
	 *
	 * @param string $reason Reason.
	 * @return array{decision: string, reason: string}
	 */
	private static function allow( string $reason ): array {
		return array(
			'decision' => self::ALLOW,
			'reason'   => $reason,
		);
	}

	/**
	 * Deny.
	 *
	 * @param string $reason Reason.
	 * @return array{decision: string, reason: string}
	 */
	private static function deny( string $reason ): array {
		return array(
			'decision' => self::DENY,
			'reason'   => $reason,
		);
	}
}
