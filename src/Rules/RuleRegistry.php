<?php
/**
 * Central registry of SEO rules — the single source of truth.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Rules;

use InvalidArgumentException;

/**
 * Holds validated rules keyed by id.
 */
final class RuleRegistry {

	/**
	 * Rules by id.
	 *
	 * @var array<string, Rule>
	 */
	private array $rules = array();

	/**
	 * Build from config rows; duplicate ids are rejected.
	 *
	 * @param array<int, array<string, mixed>> $rows Rule definitions.
	 */
	public static function fromConfig( array $rows ): self {
		$registry = new self();
		foreach ( $rows as $row ) {
			$registry->add( Rule::fromArray( $row ) );
		}
		return $registry;
	}

	/**
	 * Load the bundled rule file.
	 *
	 * @param string $file Path to a PHP file returning an array of rule rows.
	 * @throws InvalidArgumentException When the file does not return an array.
	 */
	public static function fromFile( string $file ): self {
		$rows = require $file;
		if ( ! is_array( $rows ) ) {
			throw new InvalidArgumentException( 'Rule file must return an array.' );
		}
		return self::fromConfig( $rows );
	}

	/**
	 * Add one rule.
	 *
	 * @param Rule $rule Rule to add.
	 * @throws InvalidArgumentException When the id already exists.
	 */
	public function add( Rule $rule ): void {
		if ( isset( $this->rules[ $rule->id ] ) ) {
			throw new InvalidArgumentException( 'Duplicate rule id: ' . $rule->id );
		}
		$this->rules[ $rule->id ] = $rule;
	}

	/**
	 * Get a rule by id, or null.
	 *
	 * @param string $id Rule id.
	 */
	public function get( string $id ): ?Rule {
		return $this->rules[ $id ] ?? null;
	}

	/**
	 * All rules, sorted by id.
	 *
	 * @return array<string, Rule>
	 */
	public function all(): array {
		$rules = $this->rules;
		ksort( $rules );
		return $rules;
	}

	/**
	 * Rules in one category.
	 *
	 * @param string $category Category slug.
	 * @return array<string, Rule>
	 */
	public function byCategory( string $category ): array {
		return array_filter( $this->all(), static fn ( Rule $r ): bool => $r->category === $category );
	}

	/**
	 * Number of rules.
	 */
	public function count(): int {
		return count( $this->rules );
	}
}
