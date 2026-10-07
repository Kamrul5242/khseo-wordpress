<?php
/**
 * Storage for audit findings: {prefix}khseo_issues (one table per site).
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Findings;

use KHSEO\Audit\RuleResult;
use KHSEO\Rules\Rule;
use KHSEO\Rules\RuleRegistry;
use KHSEO\Support\Evidence;

/**
 * - A finding is a failed (status "open") or undecided ("unknown") rule result.
 *   Passes are counted in the audit summary, not stored, so the table stays small.
 * - finding_key = sha1(rule | url | observation): the same problem is stored once.
 * - "ignored" is a user decision and survives re-audits.
 * - Growth is bounded: resolved rows are pruned and the table is capped.
 * Every query uses $wpdb->prepare(); filter values come from allow-lists.
 */
final class FindingRepository {

	public const TABLE    = 'khseo_issues';
	public const MAX_ROWS = 20000;
	public const STATUSES = array( 'open', 'resolved', 'ignored', 'unknown', 'not_tested' );

	/**
	 * Table name for the current site.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create/upgrade the table (dbDelta is idempotent).
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			finding_key char(40) NOT NULL,
			audit_id char(32) NOT NULL,
			rule_id varchar(40) NOT NULL,
			category varchar(32) NOT NULL,
			severity char(2) NOT NULL,
			evidence varchar(16) NOT NULL,
			status varchar(16) NOT NULL,
			url text NOT NULL,
			url_hash char(40) NOT NULL,
			message text NOT NULL,
			evidence_detail text NOT NULL,
			source varchar(64) NOT NULL,
			recommendation text NOT NULL,
			fix_available tinyint(1) NOT NULL DEFAULT 0,
			fix_risk char(2) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY finding_key (finding_key),
			KEY url_hash (url_hash),
			KEY rule_status (rule_id,status),
			KEY severity_status (severity,status),
			KEY created_at (created_at)
			) {$charset};"
		);
	}

	/**
	 * Drop the table (opted-in uninstall only).
	 */
	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is our own constant + prefix.
	}

	/**
	 * Store the open/unknown results of an audit (upsert by finding_key).
	 *
	 * @param array<int, RuleResult> $results     Results.
	 * @param string                 $audit_id    Audit id (32 hex).
	 * @param RuleRegistry           $rules       Registry.
	 * @param array<int, string>     $fixable     Rule ids with a registered fix.
	 * @return int Rows written.
	 */
	public function record( array $results, string $audit_id, RuleRegistry $rules, array $fixable ): int {
		global $wpdb;
		$table   = self::table();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$written = 0;
		foreach ( $results as $r ) {
			if ( ! in_array( $r->status, array( RuleResult::FAIL, RuleResult::UNKNOWN ), true ) ) {
				continue;
			}
			$rule = $rules->get( $r->rule_id );
			if ( null === $rule ) {
				continue;
			}
			$status = RuleResult::FAIL === $r->status ? 'open' : 'unknown';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, no WP API exists for it.
			$ok       = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (finding_key, audit_id, rule_id, category, severity, evidence, status, url, url_hash, message, evidence_detail, source, recommendation, fix_available, fix_risk, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s) ON DUPLICATE KEY UPDATE audit_id = VALUES(audit_id), evidence = VALUES(evidence), status = IF(status = 'ignored', 'ignored', VALUES(status)), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
					$r->key(),
					$audit_id,
					$r->rule_id,
					$rule->category,
					$rule->severity->value,
					$r->evidence->value,
					$status,
					$r->url,
					sha1( $r->url ),
					$rule->condition,
					mb_substr( $r->observation, 0, 2000 ),
					mb_substr( $r->source, 0, 64 ),
					$rule->recommendation,
					in_array( $r->rule_id, $fixable, true ) ? 1 : 0,
					$rule->risk->value,
					$now,
					$now
				)
			);
			$written += false === $ok ? 0 : 1;
		}
		return $written;
	}

	/**
	 * Mark earlier open/unknown findings for URLs re-checked in this audit as resolved.
	 *
	 * @param array<int, string> $urls     URLs analysed in this audit (plus "site").
	 * @param string             $audit_id Current audit id.
	 */
	public function resolveStale( array $urls, string $audit_id ): void {
		global $wpdb;
		$table = self::table();
		foreach ( array_chunk( array_map( 'sha1', array_values( array_unique( $urls ) ) ), 200 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built from count; values passed as one array.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'resolved', updated_at = %s WHERE audit_id <> %s AND status IN ('open','unknown') AND url_hash IN ({$in})", array_merge( array( gmdate( 'Y-m-d H:i:s' ), $audit_id ), $chunk ) ) );
		}
	}

	/**
	 * Keep growth bounded: drop resolved rows older than 30 days, then cap the table.
	 */
	public function prune(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table name only.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'resolved' AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > self::MAX_ROWS ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('resolved','unknown') ORDER BY updated_at ASC LIMIT %d", $count - self::MAX_ROWS ) );
		}
		// phpcs:enable
	}

	/**
	 * One finding by id (from the current site's table only).
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Filtered, paginated list.
	 *
	 * @param array<string, mixed> $filters  Untrusted filters (severity, category, status, evidence, url, fixable).
	 * @param int                  $page     Page (1-based).
	 * @param int                  $per_page Per page (1–100).
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( array $filters, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$table            = self::table();
		$per_page         = max( 1, min( 100, $per_page ) );
		$page             = max( 1, min( 10000, $page ) );
		[ $where, $args ] = self::where( $filters );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- WHERE is built only from allow-listed columns with placeholders.
		// prepare() without placeholders triggers a notice; with no filters the WHERE is static text.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) $wpdb->get_var( array() === $args ? $count_sql : $wpdb->prepare( $count_sql, $args ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY FIELD(severity,'P0','P1','P2','P3'), rule_id, id LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- filter values + limit/offset passed as one array.
			ARRAY_A
		);
		// phpcs:enable
		return array(
			'items'    => array_map( array( self::class, 'shape' ), is_array( $rows ) ? $rows : array() ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Counts of current (open/unknown) findings by severity.
	 *
	 * @return array<string, int>
	 */
	public function openCounts(): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT severity, status, COUNT(*) AS n FROM {$table} WHERE status IN ('open','unknown') GROUP BY severity, status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static query, table name only.
		$out   = array(
			'P0'      => 0,
			'P1'      => 0,
			'P2'      => 0,
			'P3'      => 0,
			'unknown' => 0,
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( 'unknown' === $row['status'] ) {
				$out['unknown'] += (int) $row['n'];
			} elseif ( isset( $out[ $row['severity'] ] ) ) {
				$out[ $row['severity'] ] += (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * Set a user-controlled status (only "ignored" or back to "open").
	 *
	 * @param int    $id     Id.
	 * @param string $status ignored | open.
	 */
	public function setUserStatus( int $id, string $status ): bool {
		global $wpdb;
		if ( $id <= 0 || ! in_array( $status, array( 'ignored', 'open' ), true ) ) {
			return false;
		}
		$table = self::table();
		return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status IN ('open','ignored','unknown')", $status, gmdate( 'Y-m-d H:i:s' ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
	}

	/**
	 * WHERE clause from allow-listed filters.
	 *
	 * @param array<string, mixed> $f Filters.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	public static function where( array $f ): array {
		global $wpdb;
		$sql  = array( '1=1' );
		$args = array();
		$enum = array(
			'severity' => array( 'P0', 'P1', 'P2', 'P3' ),
			'category' => Rule::CATEGORIES,
			'status'   => self::STATUSES,
			'evidence' => array_map( static fn ( Evidence $e ): string => $e->value, Evidence::cases() ),
		);
		foreach ( $enum as $col => $allowed ) {
			$value = $f[ $col ] ?? '';
			if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
				$sql[]  = "{$col} = %s";
				$args[] = $value;
			}
		}
		if ( ! isset( $f['status'] ) || '' === $f['status'] ) {
			$sql[] = "status IN ('open','unknown')"; // Default view: current problems.
		}
		if ( isset( $f['url'] ) && is_string( $f['url'] ) && '' !== $f['url'] ) {
			$sql[]  = 'url LIKE %s';
			$args[] = '%' . $wpdb->esc_like( mb_substr( $f['url'], 0, 200 ) ) . '%';
		}
		if ( isset( $f['fixable'] ) && in_array( (string) $f['fixable'], array( '0', '1' ), true ) ) {
			$sql[]  = 'fix_available = %d';
			$args[] = (int) $f['fixable'];
		}
		return array( implode( ' AND ', $sql ), $args );
	}

	/**
	 * Typed public shape of a row.
	 *
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	public static function shape( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'rule_id'         => (string) $row['rule_id'],
			'url'             => (string) $row['url'],
			'category'        => (string) $row['category'],
			'severity'        => (string) $row['severity'],
			'evidence'        => (string) $row['evidence'],
			'status'          => (string) $row['status'],
			'message'         => (string) $row['message'],
			'evidence_detail' => (string) $row['evidence_detail'],
			'source'          => (string) $row['source'],
			'recommendation'  => (string) $row['recommendation'],
			'fix_available'   => (bool) (int) $row['fix_available'],
			'fix_risk'        => (string) $row['fix_risk'],
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		);
	}
}
