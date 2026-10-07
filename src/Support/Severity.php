<?php
/**
 * Issue priority P0–P3.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Support;

/**
 * How urgent an issue is. Independent of Risk.
 */
enum Severity: string {
	case P0 = 'P0'; // Blocks crawling/indexing or exposes data.
	case P1 = 'P1'; // Significant ranking or trust impact.
	case P2 = 'P2'; // Meaningful improvement.
	case P3 = 'P3'; // Polish.

	/**
	 * Score weight used by prioritisation; higher is more urgent.
	 */
	public function weight(): int {
		return match ( $this ) {
			self::P0 => 8,
			self::P1 => 4,
			self::P2 => 2,
			self::P3 => 1,
		};
	}
}
