<?php
/**
 * One audit result: a rule evaluated against real data.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use KHSEO\Rules\Rule;
use KHSEO\Support\Evidence;

/**
 * Immutable finding. "passed" findings are kept too, so reports can show what was checked.
 */
final class Finding {

	/**
	 * Constructor.
	 *
	 * @param Rule     $rule     The rule evaluated.
	 * @param bool     $passed   Whether the page/site satisfies the rule.
	 * @param Evidence $evidence How the result is known.
	 * @param string   $observed What was actually seen (plain text, no secrets).
	 * @param string   $source   Where the data came from, e.g. "WordPress database".
	 */
	public function __construct(
		public readonly Rule $rule,
		public readonly bool $passed,
		public readonly Evidence $evidence,
		public readonly string $observed,
		public readonly string $source
	) {}

	/**
	 * Array form for REST/JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'rule'           => $this->rule->id,
			'category'       => $this->rule->category,
			'severity'       => $this->rule->severity->value,
			'passed'         => $this->passed,
			'evidence'       => $this->evidence->value,
			'observed'       => $this->observed,
			'source'         => $this->source,
			'recommendation' => $this->passed ? '' : $this->rule->recommendation,
			'risk'           => $this->rule->risk->value,
		);
	}
}
