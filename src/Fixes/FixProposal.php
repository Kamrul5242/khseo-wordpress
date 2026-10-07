<?php
/**
 * A proposed change, shown to the user before anything is modified.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Fixes;

use KHSEO\Support\Risk;

/**
 * Immutable. change_id is derived from (rule, target, before, after), so an approval
 * only ever covers this exact change: if the site changes, the id changes.
 */
final class FixProposal {

	/**
	 * Constructor.
	 *
	 * @param string             $rule_id    Rule the fix addresses.
	 * @param Risk               $risk       Risk level.
	 * @param string             $title      Short title.
	 * @param string             $reason     Why (from the finding).
	 * @param string             $target     What changes, e.g. "option:blog_public".
	 * @param string             $before     Current value (as shown).
	 * @param string             $after      New value (as shown).
	 * @param array<int, string> $affected   Affected URLs/options.
	 * @param string             $rollback   How it is undone.
	 * @param string             $validation How success is checked.
	 * @param string             $wp_cap     WordPress capability also required (beyond KHSEO's).
	 */
	public function __construct(
		public readonly string $rule_id,
		public readonly Risk $risk,
		public readonly string $title,
		public readonly string $reason,
		public readonly string $target,
		public readonly string $before,
		public readonly string $after,
		public readonly array $affected,
		public readonly string $rollback,
		public readonly string $validation,
		public readonly string $wp_cap
	) {}

	/**
	 * Deterministic id of this exact change.
	 */
	public function changeId(): string {
		return substr( sha1( $this->rule_id . "\0" . $this->target . "\0" . $this->before . "\0" . $this->after ), 0, 32 );
	}

	/**
	 * Public shape (no secrets: only option names/values KHSEO itself manages).
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'change_id'  => $this->changeId(),
			'rule_id'    => $this->rule_id,
			'risk'       => $this->risk->value,
			'title'      => $this->title,
			'reason'     => $this->reason,
			'target'     => $this->target,
			'before'     => $this->before,
			'after'      => $this->after,
			'affected'   => $this->affected,
			'rollback'   => $this->rollback,
			'validation' => $this->validation,
			'requires'   => $this->risk->requiresRecovery() ? 'explicit approval + recovery point' : 'explicit approval',
		);
	}
}
