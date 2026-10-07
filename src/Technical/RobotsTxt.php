<?php
/**
 * Parser and matcher for robots.txt (RFC 9309, as interpreted by universal KHSEO).
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Technical;

/**
 * Semantics (same as universal KHSEO seo_probe.py):
 * - consecutive user-agent lines share one group;
 * - the most specific product-token group applies (else "*"); matching groups merge;
 * - the longest matching rule wins; Allow wins ties; an empty Disallow allows all;
 * - "*" matches any sequence, a trailing "$" anchors the end.
 */
final class RobotsTxt {

	public const MAX_BYTES = 512_000; // Google's documented limit is 500 KiB.
	public const MAX_RULES = 5000;

	/**
	 * Groups: agents + rules.
	 *
	 * @var array<int, array{agents: array<int, string>, rules: array<int, array{0: bool, 1: string}>}>
	 */
	private array $groups = array();

	/**
	 * Sitemap URLs declared (raw, untrusted).
	 *
	 * @var array<int, string>
	 */
	public array $sitemaps = array();

	/**
	 * Lines that are not valid directives (1-based line numbers).
	 *
	 * @var array<int, int>
	 */
	public array $invalid_lines = array();

	/**
	 * Parse robots.txt text.
	 *
	 * @param string $text Raw body (untrusted).
	 */
	public static function parse( string $text ): self {
		$r          = new self();
		$text       = substr( $text, 0, self::MAX_BYTES );
		$current    = -1;
		$last_agent = false;
		$rules      = 0;
		$lines      = preg_split( '/\r\n|\r|\n/', $text );
		foreach ( false === $lines ? array() : $lines as $n => $line ) {
			$line = trim( explode( '#', $line, 2 )[0] );
			if ( '' === $line ) {
				continue;
			}
			if ( ! str_contains( $line, ':' ) ) {
				$r->invalid_lines[] = $n + 1;
				continue;
			}
			[ $key, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$key             = strtolower( $key );
			if ( 'user-agent' === $key ) {
				if ( -1 === $current || ! $last_agent ) {
					$r->groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current     = count( $r->groups ) - 1;
				}
				$r->groups[ $current ]['agents'][] = strtolower( $value );
				$last_agent                        = true;
				continue;
			}
			$last_agent = false;
			if ( 'allow' === $key || 'disallow' === $key ) {
				if ( -1 !== $current && $rules < self::MAX_RULES ) {
					$r->groups[ $current ]['rules'][] = array( 'allow' === $key, $value );
					++$rules;
				} elseif ( -1 === $current ) {
					$r->invalid_lines[] = $n + 1; // Rule before any user-agent.
				}
			} elseif ( 'sitemap' === $key ) {
				if ( '' !== $value && count( $r->sitemaps ) < 50 ) {
					$r->sitemaps[] = $value;
				}
			} elseif ( ! in_array( $key, array( 'crawl-delay', 'host', 'clean-param', 'noindex', 'request-rate', 'visit-time' ), true ) ) {
				$r->invalid_lines[] = $n + 1;
			}
		}
		return $r;
	}

	/**
	 * Whether a crawler may fetch a URL.
	 *
	 * @param string $agent Product token, e.g. "googlebot".
	 * @param string $url   Absolute URL (path and query are matched).
	 */
	public function isAllowed( string $agent, string $url ): bool {
		$token  = strtolower( $agent );
		$chosen = array_filter( $this->groups, static fn ( array $g ): bool => in_array( $token, $g['agents'], true ) );
		if ( array() === $chosen ) {
			$chosen = array_filter( $this->groups, static fn ( array $g ): bool => in_array( '*', $g['agents'], true ) );
		}
		$path  = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class.
		$path  = '' === $path ? '/' : $path; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class.
		$query = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class.
		$path .= '' !== $query ? '?' . $query : '';

		$best    = -1;
		$allowed = true;
		foreach ( $chosen as $group ) {
			foreach ( $group['rules'] as [ $is_allow, $pattern ] ) {
				if ( '' === $pattern || ! self::matches( $pattern, $path ) ) {
					continue;
				}
				$len = strlen( $pattern );
				if ( $len > $best || ( $len === $best && $is_allow ) ) {
					$best    = $len;
					$allowed = $is_allow;
				}
			}
		}
		return $allowed;
	}

	/**
	 * Match a robots pattern ("*" wildcard, "$" end anchor) against a path.
	 *
	 * @param string $pattern Pattern.
	 * @param string $path    Path (+ query).
	 */
	public static function matches( string $pattern, string $path ): bool {
		$anchored = str_ends_with( $pattern, '$' );
		$body     = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
		$regex    = implode( '.*', array_map( static fn ( string $part ): string => preg_quote( $part, '#' ), explode( '*', $body ) ) );
		return 1 === preg_match( '#^' . $regex . ( $anchored ? '$' : '' ) . '#', $path );
	}

	/**
	 * Number of groups parsed.
	 */
	public function groupCount(): int {
		return count( $this->groups );
	}
}
