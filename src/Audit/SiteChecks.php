<?php
/**
 * Site-level checks that need only WordPress settings (no crawling, no network).
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use KHSEO\Rules\RuleRegistry;
use KHSEO\Support\Evidence;

/**
 * Deterministic checks over values the caller reads from WordPress, so the
 * logic stays testable without a WordPress install.
 */
final class SiteChecks {

	public const SOURCE = 'WordPress settings';

	/**
	 * Constructor.
	 *
	 * @param RuleRegistry $rules Rule registry.
	 */
	public function __construct( private RuleRegistry $rules ) {}

	/**
	 * Run the site checks.
	 *
	 * @param bool   $blog_public Value of the blog_public option.
	 * @param string $home_url    home_url().
	 * @param string $site_url    site_url().
	 * @return array<int, Finding>
	 */
	public function run( bool $blog_public, string $home_url, string $site_url ): array {
		$findings = array();

		$index = $this->rules->get( 'SEO-INDEX-001' );
		if ( null !== $index ) {
			$findings[] = new Finding(
				$index,
				$blog_public,
				Evidence::VERIFIED,
				$blog_public ? 'Search engines are allowed to index the site.' : '"Discourage search engines from indexing this site" is ticked.',
				self::SOURCE
			);
		}

		$https = $this->rules->get( 'SEO-HTTPS-001' );
		if ( null !== $https ) {
			$home_https = 'https' === strtolower( (string) parse_url( $home_url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, runs without WordPress; PHP >= 8.1 parse_url is consistent.
			$site_https = 'https' === strtolower( (string) parse_url( $site_url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, runs without WordPress; PHP >= 8.1 parse_url is consistent.
			$findings[] = new Finding(
				$https,
				$home_https && $site_https,
				Evidence::VERIFIED,
				sprintf( 'Site Address uses %s; WordPress Address uses %s.', $home_https ? 'HTTPS' : 'HTTP', $site_https ? 'HTTPS' : 'HTTP' ),
				self::SOURCE
			);
		}

		return $findings;
	}
}
