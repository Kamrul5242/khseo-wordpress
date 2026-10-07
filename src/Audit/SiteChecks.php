<?php
/**
 * Site-level checks that need only WordPress settings (no crawling, no network).
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

/**
 * Deterministic checks over values the caller reads from WordPress, so the
 * logic stays testable without a WordPress install.
 */
final class SiteChecks {

	public const SOURCE = 'WordPress settings';

	/**
	 * Run the site checks.
	 *
	 * @param bool   $blog_public Value of the blog_public option.
	 * @param string $home_url    home_url().
	 * @param string $site_url    site_url().
	 * @return array<int, RuleResult>
	 */
	public function run( bool $blog_public, string $home_url, string $site_url ): array {
		$home_https = 'https' === strtolower( (string) parse_url( $home_url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, runs without WordPress; PHP >= 8.1 parse_url is consistent.
		$site_https = 'https' === strtolower( (string) parse_url( $site_url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, runs without WordPress; PHP >= 8.1 parse_url is consistent.
		$https_seen = sprintf( 'Site Address uses %s; WordPress Address uses %s.', $home_https ? 'HTTPS' : 'HTTP', $site_https ? 'HTTPS' : 'HTTP' );

		return array(
			$blog_public
				? RuleResult::pass( 'SEO-INDEX-001', RuleResult::SITE, 'Search engines are allowed to index the site.', self::SOURCE )
				: RuleResult::fail( 'SEO-INDEX-001', RuleResult::SITE, '"Discourage search engines from indexing this site" is ticked.', self::SOURCE ),
			$home_https && $site_https
				? RuleResult::pass( 'SEO-HTTPS-001', RuleResult::SITE, $https_seen, self::SOURCE )
				: RuleResult::fail( 'SEO-HTTPS-001', RuleResult::SITE, $https_seen, self::SOURCE ),
		);
	}
}
