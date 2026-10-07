<?php
/**
 * Change risk levels R0–R4 (universal KHSEO governance).
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Support;

/**
 * Risk of applying a change. Priority is not permission: a P0 issue can still be R3.
 */
enum Risk: string {
	case R0 = 'R0'; // Read only.
	case R1 = 'R1'; // Safe and reversible.
	case R2 = 'R2'; // Review required.
	case R3 = 'R3'; // Explicit confirmation + recovery.
	case R4 = 'R4'; // Confirmation + verified recovery gate.

	/**
	 * Whether KHSEO may apply the change without a per-change confirmation.
	 * Only R0 and R1, and R1 only when the user enabled automation.
	 *
	 * @param bool $automation_enabled User opted into safe automatic fixes.
	 */
	public function allowsAutoApply( bool $automation_enabled ): bool {
		return self::R0 === $this || ( self::R1 === $this && $automation_enabled );
	}

	/**
	 * Whether the change needs a recovery path (backup/snapshot) before applying.
	 */
	public function requiresRecovery(): bool {
		return self::R3 === $this || self::R4 === $this;
	}

	/**
	 * Numeric order for comparisons (R0 = 0 … R4 = 4).
	 */
	public function level(): int {
		return (int) substr( $this->value, 1 );
	}
}
