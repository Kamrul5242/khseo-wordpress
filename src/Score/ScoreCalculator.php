<?php
/**
 * KHSEO Score — KHSEO's internal diagnostic score, NOT a Google ranking score.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Score;

use KHSEO\Rules\Rule;
use KHSEO\Rules\RuleRegistry;

/**
 * Deterministic formula (documented in docs/ARCHITECTURE.md):
 *
 * 1. Every pass/fail result counts once. Weight w = severity weight (P0 8, P1 4, P2 2, P3 1).
 *    unknown / not_tested results are EXCLUDED and reported as coverage gaps.
 * 2. Category score = round(100 × Σ w(passed) / Σ w(evaluated)).
 * 3. Overall = round(Σ category_weight × category_score / Σ category_weight), over the
 *    categories that had at least one evaluated result.
 * 4. If any P0 check failed, the overall score is capped at 49.
 *
 * The same inputs always give the same score. It says nothing about rankings.
 */
final class ScoreCalculator {

	public const P0_CAP = 49;

	public const CATEGORY_WEIGHTS = array(
		'crawlability'    => 20,
		'indexability'    => 20,
		'technical'       => 15,
		'metadata'        => 15,
		'content'         => 10,
		'links'           => 5,
		'images'          => 5,
		'structured_data' => 5,
		'social'          => 5,
	);

	public const DISCLAIMER = "KHSEO's internal diagnostic score based on the checks performed in this audit — not a Google ranking score.";

	/**
	 * Calculate.
	 *
	 * @param array<string, array{pass?: int, fail?: int, unknown?: int, not_tested?: int}> $counts Per-rule outcome counts.
	 * @param RuleRegistry                                                                  $rules  Registry.
	 * @return array{score: int|null, capped: bool, categories: array<string, array{score: int|null, evaluated: int, failed: int}>, evaluated: int, failed: int, unknown: int, not_tested: int, disclaimer: string}
	 */
	public static function calculate( array $counts, RuleRegistry $rules ): array {
		$earned    = array_fill_keys( Rule::CATEGORIES, 0 );
		$possible  = array_fill_keys( Rule::CATEGORIES, 0 );
		$cat_eval  = array_fill_keys( Rule::CATEGORIES, 0 );
		$cat_fail  = array_fill_keys( Rule::CATEGORIES, 0 );
		$totals    = array(
			'evaluated'  => 0,
			'failed'     => 0,
			'unknown'    => 0,
			'not_tested' => 0,
		);
		$p0_failed = false;
		ksort( $counts );
		foreach ( $counts as $rule_id => $c ) {
			$rule = $rules->get( (string) $rule_id );
			if ( null === $rule ) {
				continue; // Unknown rule ids can never move the score.
			}
			$pass = max( 0, (int) ( $c['pass'] ?? 0 ) );
			$fail = max( 0, (int) ( $c['fail'] ?? 0 ) );
			$w    = $rule->severity->weight();
			$cat  = $rule->category;

			$earned[ $cat ]   += $w * $pass;
			$possible[ $cat ] += $w * ( $pass + $fail );
			$cat_eval[ $cat ] += $pass + $fail;
			$cat_fail[ $cat ] += $fail;

			$totals['evaluated']  += $pass + $fail;
			$totals['failed']     += $fail;
			$totals['unknown']    += max( 0, (int) ( $c['unknown'] ?? 0 ) );
			$totals['not_tested'] += max( 0, (int) ( $c['not_tested'] ?? 0 ) );
			if ( $fail > 0 && 'P0' === $rule->severity->value ) {
				$p0_failed = true;
			}
		}

		$categories = array();
		$weighted   = 0;
		$weights    = 0;
		foreach ( self::CATEGORY_WEIGHTS as $cat => $cw ) {
			$score              = $possible[ $cat ] > 0 ? (int) round( 100 * $earned[ $cat ] / $possible[ $cat ] ) : null;
			$categories[ $cat ] = array(
				'score'     => $score,
				'evaluated' => $cat_eval[ $cat ],
				'failed'    => $cat_fail[ $cat ],
			);
			if ( null !== $score ) {
				$weighted += $cw * $score;
				$weights  += $cw;
			}
		}
		$overall = $weights > 0 ? (int) round( $weighted / $weights ) : null;
		$capped  = $p0_failed && null !== $overall && $overall > self::P0_CAP;
		if ( $capped ) {
			$overall = self::P0_CAP;
		}
		return array(
			'score'      => $overall,
			'capped'     => $capped,
			'categories' => $categories,
			'evaluated'  => $totals['evaluated'],
			'failed'     => $totals['failed'],
			'unknown'    => $totals['unknown'],
			'not_tested' => $totals['not_tested'],
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
