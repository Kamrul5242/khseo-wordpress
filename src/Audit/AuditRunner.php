<?php
/**
 * Bounded, resumable audit job (WP-Cron or step-by-step from the dashboard).
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use KHSEO\Findings\FindingRepository;
use KHSEO\Fixes\FixService;
use KHSEO\Http\FetchPolicy;
use KHSEO\Http\SafeFetcher;
use KHSEO\Http\WpHttpTransport;
use KHSEO\Rules\RuleRegistry;
use KHSEO\Score\ScoreCalculator;
use KHSEO\Security\UrlGuard;
use KHSEO\Technical\RobotsTxt;
use KHSEO\Technical\SitemapParser;

/**
 * Lifecycle: start() → step() … step() → done | cancelled | failed.
 *
 * Every request goes through SafeFetcher. Only this site's own origin is trusted
 * (it may sit on a private network). Hard limits bound pages, sitemap files,
 * total bytes and time per step. Each step holds a lock, so overlapping cron
 * runs cannot process the same job twice.
 */
final class AuditRunner {

	public const JOB                 = 'khseo_audit_job';
	public const LAST                = 'khseo_last_audit';
	public const LOCK                = 'khseo_audit_lock';
	public const CRON_HOOK           = 'khseo_audit_step';
	public const SCOPES              = array( 'homepage', 'url', 'sitemap' );
	public const STEP_PAGES          = 5;
	public const STEP_SECS           = 20;
	public const MAX_LINKS           = 50;
	private const SITEMAP_CANDIDATES = array( '/wp-sitemap.xml', '/sitemap.xml', '/sitemap_index.xml' );

	/**
	 * Constructor.
	 *
	 * @param RuleRegistry         $rules    Rules.
	 * @param FindingRepository    $findings Findings storage.
	 * @param array<string, mixed> $settings Normalised settings.
	 */
	public function __construct( private RuleRegistry $rules, private FindingRepository $findings, private array $settings ) {}

	/**
	 * Start a new audit.
	 *
	 * @param string $scope   homepage | url | sitemap.
	 * @param string $url     For scope "url": a URL on this site.
	 * @param int    $user_id User.
	 * @return array{ok: bool, message: string, job?: array<string, mixed>}
	 */
	public function start( string $scope, string $url, int $user_id ): array {
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			return array(
				'ok'      => false,
				'message' => 'Unknown scope.',
			);
		}
		$current = self::job();
		if ( null !== $current && 'running' === $current['status'] && (int) $current['updated'] > time() - HOUR_IN_SECONDS ) {
			return array(
				'ok'      => false,
				'message' => 'An audit is already running. Cancel it first.',
			);
		}
		$home = Url::normalize( home_url( '/' ) ) ?? home_url( '/' );
		$seed = array();
		if ( 'url' === $scope ) {
			$target = Url::normalize( $url );
			// Only this site's own pages can be audited: the API never fetches arbitrary URLs.
			if ( null === $target || ! Url::sameOrigin( $target, $home ) ) {
				return array(
					'ok'      => false,
					'message' => 'The URL must be a page on this site (' . $home . ').',
				);
			}
			$seed[] = $target;
		} else {
			$seed[] = $home;
		}
		$job = array(
			'id'       => bin2hex( random_bytes( 16 ) ),
			'scope'    => $scope,
			'status'   => 'running',
			'phase'    => 'discover',
			'home'     => $home,
			'queue'    => $seed,
			'sitemap'  => array(),
			'pages'    => array(),
			'counts'   => array(),
			'site'     => array(),
			'bytes'    => 0,
			'failed'   => array(),
			'notes'    => array(),
			'listed'   => 0,
			'user'     => $user_id,
			'started'  => time(),
			'updated'  => time(),
			'finished' => 0,
		);
		self::save( $job );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
		return array(
			'ok'      => true,
			'message' => 'Audit started.',
			'job'     => self::publicJob( $job ),
		);
	}

	/**
	 * Cancel the running audit (its stored findings so far are kept).
	 */
	public static function cancel(): bool {
		$job = self::job();
		if ( null === $job || 'running' !== $job['status'] ) {
			return false;
		}
		$job['status']   = 'cancelled';
		$job['finished'] = time();
		self::save( $job );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		return true;
	}

	/**
	 * Process one bounded batch. Safe to call from cron and from the dashboard.
	 *
	 * @return array<string, mixed>|null Public job state, or null if there is no job.
	 * @throws \Throwable Re-thrown after the job is marked failed and the lock released.
	 */
	public function step(): ?array {
		$job = self::job();
		if ( null === $job || 'running' !== $job['status'] ) {
			return null === $job ? null : self::publicJob( $job );
		}
		if ( ! self::lock() ) {
			return self::publicJob( $job ); // Another request is processing it.
		}
		try {
			$deadline = time() + self::STEP_SECS;
			$fetcher  = $this->fetcher();
			if ( 'discover' === $job['phase'] ) {
				$job          = $this->discover( $job, $fetcher );
				$job['phase'] = 'pages';
			}
			$done = 0;
			while ( 'pages' === $job['phase'] && array() !== $job['queue'] && $done < self::STEP_PAGES && time() < $deadline ) {
				$url = (string) array_shift( $job['queue'] );
				$job = $this->page( $job, $fetcher, $url );
				++$done;
				if ( $job['bytes'] > (int) $this->settings['audit_max_total_mb'] * 1_000_000 ) {
					$job['notes'][] = 'Stopped early: the download budget of ' . (int) $this->settings['audit_max_total_mb'] . ' MB was reached; ' . count( $job['queue'] ) . ' URL(s) not analysed.';
					$job['queue']   = array();
				}
			}
			if ( 'pages' === $job['phase'] && array() === $job['queue'] ) {
				$job = $this->finish( $job );
			}
			$job['updated'] = time();
			self::save( $job );
			if ( 'running' === $job['status'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + 5, self::CRON_HOOK );
			}
		} catch ( \Throwable $e ) {
			$job['status']  = 'failed';
			$job['notes'][] = 'Audit stopped by an internal error (details are in the KHSEO log).';
			self::save( $job );
			throw $e;
		} finally {
			self::unlock();
		}
		return self::publicJob( $job );
	}

	/**
	 * Site-level checks: WordPress settings, robots.txt and sitemap discovery.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param SafeFetcher          $fetcher Fetcher.
	 * @return array<string, mixed>
	 */
	private function discover( array $job, SafeFetcher $fetcher ): array {
		$site    = RuleResult::SITE;
		$results = ( new SiteChecks() )->run( '1' === (string) get_option( 'blog_public', '1' ), home_url(), site_url() );
		$origin  = rtrim( self::originBase( $job['home'] ), '/' );

		// robots.txt.
		$robots_url    = $origin . '/robots.txt';
		$res           = $fetcher->fetch( $robots_url );
		$job['bytes'] += strlen( $res->body );
		$robots        = null;
		if ( ! $res->ok || $res->status >= 500 ) {
			$state     = AuditContext::ROBOTS_UNAVAILABLE;
			$results[] = RuleResult::fail( 'SEO-ROBOTS-001', $site, $res->ok ? 'robots.txt returned ' . $res->status . '.' : 'robots.txt could not be fetched: ' . $res->reason, 'robots.txt' );
		} elseif ( $res->status >= 400 ) {
			$state     = AuditContext::ROBOTS_ABSENT;
			$results[] = RuleResult::pass( 'SEO-ROBOTS-001', $site, 'robots.txt returned ' . $res->status . ' (no rules: crawling is not restricted).', 'robots.txt' );
		} else {
			$state     = AuditContext::ROBOTS_OK;
			$robots    = RobotsTxt::parse( $res->body );
			$results[] = RuleResult::pass( 'SEO-ROBOTS-001', $site, 'robots.txt returned ' . $res->status . ' with ' . $robots->groupCount() . ' group(s).', 'robots.txt' );
			$results[] = array() !== $robots->invalid_lines
				? RuleResult::fail( 'SEO-ROBOTS-003', $site, 'Invalid lines: ' . implode( ', ', array_slice( $robots->invalid_lines, 0, 10 ) ), 'robots.txt' )
				: RuleResult::pass( 'SEO-ROBOTS-003', $site, 'All lines are valid directives.', 'robots.txt' );
			$results[] = array() === $robots->sitemaps
				? RuleResult::fail( 'SEO-ROBOTS-004', $site, 'No Sitemap: line.', 'robots.txt' )
				: RuleResult::pass( 'SEO-ROBOTS-004', $site, count( $robots->sitemaps ) . ' Sitemap declaration(s).', 'robots.txt' );
		}
		$job['robots'] = array(
			'state' => $state,
			'body'  => null === $robots ? '' : substr( $res->body, 0, RobotsTxt::MAX_BYTES ),
		);

		// Sitemaps: robots.txt declarations on this origin first, then WordPress's defaults.
		$candidates = array();
		$offsite    = 0;
		foreach ( null === $robots ? array() : $robots->sitemaps as $declared ) {
			$n = Url::normalize( $declared );
			if ( null !== $n && Url::sameOrigin( $n, $job['home'] ) ) {
				$candidates[] = $n;
			} else {
				++$offsite;
			}
		}
		foreach ( self::SITEMAP_CANDIDATES as $path ) {
			$candidates[] = $origin . $path;
		}
		$max_files = (int) $this->settings['audit_max_sitemap_files'];
		$max_pages = (int) $this->settings['audit_max_pages'];
		$found     = '';
		$fetched   = 0;
		$urls      = array();
		$invalid   = 0;
		$queue     = array_values( array_unique( $candidates ) );
		while ( array() !== $queue && $fetched < $max_files ) {
			$sm_url = (string) array_shift( $queue );
			$sm     = $fetcher->fetch( $sm_url );
			++$fetched;
			$job['bytes'] += strlen( $sm->body );
			if ( ! $sm->ok || 200 !== $sm->status ) {
				if ( '' !== $found || ! in_array( substr( $sm_url, strlen( $origin ) ), self::SITEMAP_CANDIDATES, true ) ) {
					$results[] = RuleResult::fail( 'SEO-SITEMAP-002', $sm_url, $sm->ok ? 'Returned ' . $sm->status . '.' : 'Could not fetch: ' . $sm->reason, 'sitemap' );
				}
				continue;
			}
			$parsed = SitemapParser::parse( $sm->body, 50000 );
			if ( SitemapParser::TYPE_INVALID === $parsed['type'] ) {
				$results[] = RuleResult::fail( 'SEO-SITEMAP-002', $sm_url, 'Malformed: ' . $parsed['error'], 'sitemap' );
				continue;
			}
			$results[] = RuleResult::pass( 'SEO-SITEMAP-002', $sm_url, ucfirst( $parsed['type'] ) . ' with ' . count( $parsed['locs'] ) . ' entr(y/ies)' . ( $parsed['truncated'] ? ' (capped)' : '' ) . '.', 'sitemap' );
			if ( '' === $found ) {
				$found = $sm_url;
				// A sitemap was found: stop probing the other default locations (their 404s are not errors).
				$queue = array_values( array_filter( $queue, static fn ( string $q ): bool => ! in_array( substr( $q, strlen( $origin ) ), self::SITEMAP_CANDIDATES, true ) ) );
			}
			foreach ( $parsed['locs'] as $loc ) {
				$n = Url::normalize( $loc );
				if ( null === $n || ! Url::sameOrigin( $n, $job['home'] ) ) {
					++$invalid;
				} elseif ( SitemapParser::TYPE_INDEX === $parsed['type'] ) {
					$queue[] = $n;
				} else {
					$urls[ $n ] = true;
				}
			}
			if ( SitemapParser::TYPE_URLSET === $parsed['type'] && '' !== $found && 'sitemap' !== $job['scope'] ) {
				break; // Only presence/validity matters outside sitemap scope.
			}
		}
		$results[] = '' === $found
			? RuleResult::fail( 'SEO-SITEMAP-001', $site, 'No valid XML sitemap found (checked ' . $fetched . ' location(s)).', 'sitemap' )
			: RuleResult::pass( 'SEO-SITEMAP-001', $site, 'Sitemap found: ' . $found, 'sitemap' );
		if ( '' !== $found ) {
			$results[] = $invalid + $offsite > 0
				? RuleResult::fail( 'SEO-SITEMAP-004', $site, ( $invalid + $offsite ) . ' sitemap URL(s) are invalid or on another host (not fetched).', 'sitemap' )
				: RuleResult::pass( 'SEO-SITEMAP-004', $site, 'All sitemap URLs are on this site.', 'sitemap' );
		}
		if ( array() !== $queue ) {
			$job['notes'][] = count( $queue ) . ' sitemap file(s) not read (limit ' . $max_files . ').';
		}

		$job['listed'] = count( $urls );
		if ( 'sitemap' === $job['scope'] ) {
			$sample         = array_slice( array_keys( $urls ), 0, max( 0, $max_pages - 1 ) );
			$job['sitemap'] = $sample;
			$job['queue']   = array_values( array_unique( array_merge( $job['queue'], $sample ) ) );
			if ( count( $urls ) > count( $sample ) ) {
				$job['notes'][] = 'Sitemap lists ' . count( $urls ) . ' URL(s); the first ' . count( $sample ) . ' were analysed (limit ' . $max_pages . ' pages per audit).';
			}
		}
		$job['site'] = array_map( array( self::class, 'resultToArray' ), $results );
		return $this->tally( $job, $results );
	}

	/**
	 * Fetch and analyse one page.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param SafeFetcher          $fetcher Fetcher.
	 * @param string               $url     URL.
	 * @return array<string, mixed>
	 */
	private function page( array $job, SafeFetcher $fetcher, string $url ): array {
		$res           = $fetcher->fetch( $url );
		$job['bytes'] += strlen( $res->body );
		if ( ! $res->ok ) {
			$job['failed'][] = $url;
			return $this->tally( $job, array( RuleResult::unknown( 'SEO-HTTP-001', $url, 'Could not fetch: ' . $res->reason, 'HTTP response' ) ) );
		}
		$robots = '' === ( $job['robots']['body'] ?? '' ) ? null : RobotsTxt::parse( (string) $job['robots']['body'] );
		$ctx    = new AuditContext( $job['home'], (string) ( $job['robots']['state'] ?? AuditContext::ROBOTS_NOT_FETCHED ), $robots );
		$out    = PageAnalyzer::analyze( HtmlParser::fromFetch( $url, $res ), $ctx, in_array( $url, $job['sitemap'], true ) );

		$summary          = $out['summary'];
		$summary['links'] = array_slice( $summary['links'], 0, self::MAX_LINKS );
		$job['pages'][]   = $summary;
		return $this->tally( $job, $out['results'] );
	}

	/**
	 * Cross-page checks, stale-finding resolution, score and the "last audit" record.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	private function finish( array $job ): array {
		$job = $this->tally( $job, CrossPageAnalyzer::analyze( $job['pages'] ) );
		$this->findings->resolveStale( array_merge( array( RuleResult::SITE ), array_column( $job['pages'], 'url' ), $job['failed'] ), $job['id'] );
		$this->findings->prune();

		$score     = ScoreCalculator::calculate( $job['counts'], $this->rules );
		$evaluated = array();
		$untested  = array();
		foreach ( $job['counts'] as $rule => $c ) {
			if ( ( $c['pass'] ?? 0 ) + ( $c['fail'] ?? 0 ) > 0 ) {
				$evaluated[] = $rule;
			} else {
				$untested[] = $rule;
			}
		}
		$never     = array_values( array_diff( array_keys( $this->rules->all() ), array_keys( $job['counts'] ) ) );
		$with_fail = count( array_unique( $job['fail_urls'] ?? array() ) );
		$analysed  = count( $job['pages'] );

		$job['phase']    = 'done';
		$job['status']   = 'done';
		$job['finished'] = time();
		$job['score']    = $score;
		update_option(
			self::LAST,
			array(
				'id'       => $job['id'],
				'scope'    => self::scopeLabel( $job ),
				'finished' => $job['finished'],
				'started'  => $job['started'],
				'score'    => $score,
				'coverage' => array(
					'urls_analysed'         => $analysed,
					'urls_fetch_failed'     => count( $job['failed'] ),
					'urls_with_failures'    => $with_fail,
					'urls_without_failures' => max( 0, $analysed - $with_fail ),
					'rules_evaluated'       => count( $evaluated ),
					'rules_not_tested'      => array_values( array_merge( $untested, $never ) ),
					'js_rendering'          => 'NOT TESTED',
					'google_index_status'   => 'NOT TESTED (Search Console not connected)',
					'rich_results_test'     => 'NOT TESTED',
				),
				'notes'    => $job['notes'],
			),
			false
		);
		$job['pages'] = array(); // The summaries were only needed for cross-page checks.
		return $job;
	}

	/**
	 * Store results and count outcomes per rule.
	 *
	 * @param array<string, mixed>   $job     Job.
	 * @param array<int, RuleResult> $results Results.
	 * @return array<string, mixed>
	 */
	private function tally( array $job, array $results ): array {
		$this->findings->record( $results, $job['id'], $this->rules, FixService::fixableRules() );
		foreach ( $results as $r ) {
			$job['counts'][ $r->rule_id ][ $r->status ] = ( $job['counts'][ $r->rule_id ][ $r->status ] ?? 0 ) + 1;
			if ( RuleResult::FAIL === $r->status && RuleResult::SITE !== $r->url ) {
				$job['fail_urls'][] = $r->url;
			}
		}
		if ( isset( $job['fail_urls'] ) ) {
			$job['fail_urls'] = array_values( array_unique( $job['fail_urls'] ) );
		}
		return $job;
	}

	/**
	 * Fetcher that trusts only this site's own origin(s).
	 */
	private function fetcher(): SafeFetcher {
		return new SafeFetcher( new UrlGuard( null, array( 80, 443 ), array( home_url( '/' ), site_url( '/' ) ) ), new WpHttpTransport(), new FetchPolicy() );
	}

	/**
	 * "scheme://host[:port]" of a URL.
	 *
	 * @param string $url URL.
	 */
	private static function originBase( string $url ): string {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || ! isset( $p['scheme'], $p['host'] ) ) {
			return $url;
		}
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	/**
	 * Exact description of what was analysed.
	 *
	 * @param array<string, mixed> $job Job.
	 */
	public static function scopeLabel( array $job ): string {
		$n = count( $job['pages'] ) + count( $job['failed'] );
		return match ( $job['scope'] ) {
			'homepage' => 'Homepage only (1 URL)',
			'url'      => '1 URL',
			default    => in_array( $job['home'], $job['sitemap'], true )
				? $n . ' URL(s): ' . count( $job['sitemap'] ) . ' of ' . (int) $job['listed'] . ' sitemap URL(s), including the homepage'
				: $n . ' URL(s): the homepage plus ' . count( $job['sitemap'] ) . ' of ' . (int) $job['listed'] . ' sitemap URL(s)',
		};
	}

	/**
	 * Result as plain array.
	 *
	 * @param RuleResult $r Result.
	 * @return array<string, string>
	 */
	public static function resultToArray( RuleResult $r ): array {
		return array(
			'rule'        => $r->rule_id,
			'status'      => $r->status,
			'evidence'    => $r->evidence->value,
			'url'         => $r->url,
			'observation' => $r->observation,
			'source'      => $r->source,
		);
	}

	/**
	 * Current job or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function job(): ?array {
		$job = get_option( self::JOB, null );
		return is_array( $job ) && isset( $job['id'], $job['status'] ) ? $job : null;
	}

	/**
	 * Public, bounded view of a job (no queues or page bodies).
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	public static function publicJob( array $job ): array {
		$processed = count( $job['pages'] ?? array() ) + count( $job['failed'] ?? array() );
		return array(
			'id'        => (string) $job['id'],
			'scope'     => (string) $job['scope'],
			'status'    => (string) $job['status'],
			'phase'     => (string) $job['phase'],
			'processed' => 'done' === $job['status'] ? null : $processed,
			'remaining' => count( $job['queue'] ?? array() ),
			'notes'     => array_values( (array) ( $job['notes'] ?? array() ) ),
			'started'   => (int) $job['started'],
			'finished'  => (int) $job['finished'],
		);
	}

	/**
	 * Save job (not autoloaded).
	 *
	 * @param array<string, mixed> $job Job.
	 */
	private static function save( array $job ): void {
		update_option( self::JOB, $job, false );
	}

	/**
	 * Acquire the step lock (expires after 2 minutes in case a request died).
	 */
	private static function lock(): bool {
		if ( add_option( self::LOCK, time() + 120, '', false ) ) {
			return true;
		}
		if ( (int) get_option( self::LOCK, 0 ) < time() ) {
			delete_option( self::LOCK );
			return add_option( self::LOCK, time() + 120, '', false );
		}
		return false;
	}

	/**
	 * Release the lock.
	 */
	private static function unlock(): void {
		delete_option( self::LOCK );
	}
}
