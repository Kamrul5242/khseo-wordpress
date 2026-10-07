<?php
/**
 * Site facts shared by page analyzers during one audit.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use KHSEO\Technical\RobotsTxt;

/**
 * Immutable per-audit context.
 */
final class AuditContext {

	public const ROBOTS_OK          = 'ok';
	public const ROBOTS_ABSENT      = 'absent';      // 4xx: crawlers may crawl everything (RFC 9309).
	public const ROBOTS_UNAVAILABLE = 'unavailable'; // 5xx / network error: crawlers assume full disallow.
	public const ROBOTS_NOT_FETCHED = 'not_fetched';

	/**
	 * Constructor.
	 *
	 * @param string         $origin_url   Home URL of the site being audited.
	 * @param string         $robots_state One of the ROBOTS_* constants.
	 * @param RobotsTxt|null $robots       Parsed robots.txt when state is ok.
	 */
	public function __construct(
		public readonly string $origin_url,
		public readonly string $robots_state = self::ROBOTS_NOT_FETCHED,
		public readonly ?RobotsTxt $robots = null
	) {}
}
