<?php
/**
 * Evidence labels shared with the universal KHSEO specification.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Support;

/**
 * How a statement is known. Every finding, metric and recommendation carries one.
 */
enum Evidence: string {
	case VERIFIED    = 'VERIFIED';
	case OBSERVED    = 'OBSERVED';
	case INFERRED    = 'INFERRED';
	case RECOMMENDED = 'RECOMMENDED';
	case ASSUMED     = 'ASSUMED';
	case UNKNOWN     = 'UNKNOWN';
	case NOT_TESTED  = 'NOT TESTED';

	/**
	 * True when the label describes a measured fact rather than an opinion or gap.
	 */
	public function isFact(): bool {
		return self::VERIFIED === $this || self::OBSERVED === $this;
	}
}
