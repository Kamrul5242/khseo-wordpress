<?php
/**
 * Outcome of evaluating one rule against one URL (or the site) at runtime.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use InvalidArgumentException;
use KHSEO\Support\Evidence;

/**
 * The ONLY place where evidence about the current site is recorded.
 *
 * - pass / fail: the check ran; $evidence says how strongly the outcome is known.
 * - unknown:     the check ran but could not decide (e.g. data was ambiguous).
 * - not_tested:  the check did not run (e.g. target outside scope); never counted as pass.
 */
final class RuleResult {

	public const PASS       = 'pass';
	public const FAIL       = 'fail';
	public const UNKNOWN    = 'unknown';
	public const NOT_TESTED = 'not_tested';

	public const SITE = 'site';

	/**
	 * Constructor.
	 *
	 * @param string   $rule_id     Rule id from config/rules.php.
	 * @param string   $status      pass | fail | unknown | not_tested.
	 * @param Evidence $evidence    How the outcome is known.
	 * @param string   $url         Analysed URL, or "site" for site-wide checks.
	 * @param string   $observation What was actually seen (plain text; never secrets).
	 * @param string   $source      Where the data came from (e.g. "fetched HTML", "robots.txt").
	 * @throws InvalidArgumentException On an inconsistent status/evidence pair.
	 */
	public function __construct(
		public readonly string $rule_id,
		public readonly string $status,
		public readonly Evidence $evidence,
		public readonly string $url,
		public readonly string $observation,
		public readonly string $source
	) {
		if ( ! in_array( $status, array( self::PASS, self::FAIL, self::UNKNOWN, self::NOT_TESTED ), true ) ) {
			throw new InvalidArgumentException( 'Invalid result status.' );
		}
		// Evidence must match the outcome: no "VERIFIED" for something that was not measured.
		if ( self::NOT_TESTED === $status && Evidence::NOT_TESTED !== $evidence ) {
			throw new InvalidArgumentException( 'A not_tested result must carry NOT TESTED evidence.' );
		}
		if ( self::UNKNOWN === $status && ! in_array( $evidence, array( Evidence::UNKNOWN, Evidence::ASSUMED ), true ) ) {
			throw new InvalidArgumentException( 'An unknown result must carry UNKNOWN or ASSUMED evidence.' );
		}
		if ( in_array( $status, array( self::PASS, self::FAIL ), true ) && ! in_array( $evidence, array( Evidence::VERIFIED, Evidence::OBSERVED, Evidence::INFERRED ), true ) ) {
			throw new InvalidArgumentException( 'A pass/fail result needs measured or inferred evidence.' );
		}
	}

	/**
	 * Passed check.
	 *
	 * @param string   $rule_id     Rule.
	 * @param string   $url         URL.
	 * @param string   $observation Observation.
	 * @param string   $source      Source.
	 * @param Evidence $evidence    Evidence (default VERIFIED).
	 */
	public static function pass( string $rule_id, string $url, string $observation, string $source, Evidence $evidence = Evidence::VERIFIED ): self {
		return new self( $rule_id, self::PASS, $evidence, $url, $observation, $source );
	}

	/**
	 * Failed check.
	 *
	 * @param string   $rule_id     Rule.
	 * @param string   $url         URL.
	 * @param string   $observation Observation.
	 * @param string   $source      Source.
	 * @param Evidence $evidence    Evidence (default VERIFIED).
	 */
	public static function fail( string $rule_id, string $url, string $observation, string $source, Evidence $evidence = Evidence::VERIFIED ): self {
		return new self( $rule_id, self::FAIL, $evidence, $url, $observation, $source );
	}

	/**
	 * Check could not decide.
	 *
	 * @param string $rule_id Rule.
	 * @param string $url     URL.
	 * @param string $reason  Why.
	 * @param string $source  Source.
	 */
	public static function unknown( string $rule_id, string $url, string $reason, string $source ): self {
		return new self( $rule_id, self::UNKNOWN, Evidence::UNKNOWN, $url, $reason, $source );
	}

	/**
	 * Check did not run.
	 *
	 * @param string $rule_id Rule.
	 * @param string $url     URL.
	 * @param string $reason  Why.
	 */
	public static function notTested( string $rule_id, string $url, string $reason ): self {
		return new self( $rule_id, self::NOT_TESTED, Evidence::NOT_TESTED, $url, $reason, 'not measured' );
	}

	/**
	 * Stable identity for de-duplication: same rule + URL + observation = same finding.
	 */
	public function key(): string {
		return sha1( $this->rule_id . "\0" . $this->url . "\0" . $this->observation );
	}
}
