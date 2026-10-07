<?php
/**
 * One SEO rule definition (value object).
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Rules;

use InvalidArgumentException;
use KHSEO\Support\Evidence;
use KHSEO\Support\Risk;
use KHSEO\Support\Severity;

/**
 * Immutable rule record. Engines evaluate rules; they never invent their own severity.
 */
final class Rule {

	public const ID_PATTERN = '/^SEO-[A-Z0-9]+-\d{3}$/';

	/**
	 * Build and validate a rule.
	 *
	 * @param string   $id             e.g. SEO-TITLE-001.
	 * @param string   $category       Category slug, e.g. metadata.
	 * @param Severity $severity       P0–P3.
	 * @param string   $condition      Plain-language trigger.
	 * @param Evidence $evidence       How the condition is established.
	 * @param string   $recommendation What the user should do.
	 * @param bool     $auto_fixable   Whether a Safe Fix exists.
	 * @param Risk     $risk           Risk of the fix.
	 * @param bool     $reversible     Whether the fix can be rolled back.
	 * @param string   $docs           Short reference (URL or doc anchor).
	 * @throws InvalidArgumentException When the definition is invalid or unsafe.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $category,
		public readonly Severity $severity,
		public readonly string $condition,
		public readonly Evidence $evidence,
		public readonly string $recommendation,
		public readonly bool $auto_fixable,
		public readonly Risk $risk,
		public readonly bool $reversible,
		public readonly string $docs = ''
	) {
		if ( ! preg_match( self::ID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( 'Invalid rule id: ' . $id );
		}
		if ( '' === trim( $category ) || '' === trim( $condition ) || '' === trim( $recommendation ) ) {
			throw new InvalidArgumentException( 'Rule ' . $id . ' is missing category, condition or recommendation.' );
		}
		// A fix that cannot be undone must never be applied automatically.
		if ( $auto_fixable && ! $reversible ) {
			throw new InvalidArgumentException( 'Rule ' . $id . ' is auto-fixable but not reversible.' );
		}
		// Auto-fixes are limited to low-risk changes.
		if ( $auto_fixable && $risk->level() > Risk::R1->level() ) {
			throw new InvalidArgumentException( 'Rule ' . $id . ' is auto-fixable above R1.' );
		}
	}

	/**
	 * Create from a config array (see config/rules.php).
	 *
	 * @param array<string, mixed> $row Rule definition.
	 * @throws InvalidArgumentException When a key is missing or invalid.
	 */
	public static function fromArray( array $row ): self {
		foreach ( array( 'id', 'category', 'severity', 'condition', 'evidence', 'recommendation', 'auto_fixable', 'risk', 'reversible' ) as $key ) {
			if ( ! array_key_exists( $key, $row ) ) {
				throw new InvalidArgumentException( 'Rule definition missing key: ' . $key );
			}
		}
		return new self(
			(string) $row['id'],
			(string) $row['category'],
			Severity::from( (string) $row['severity'] ),
			(string) $row['condition'],
			Evidence::from( (string) $row['evidence'] ),
			(string) $row['recommendation'],
			(bool) $row['auto_fixable'],
			Risk::from( (string) $row['risk'] ),
			(bool) $row['reversible'],
			isset( $row['docs'] ) ? (string) $row['docs'] : ''
		);
	}
}
