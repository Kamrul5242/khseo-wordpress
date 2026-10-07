<?php
/**
 * Structured, redacted, size-bounded logger.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Support;

use KHSEO\Security\Redactor;

/**
 * Keeps the most recent entries in a non-autoloaded option. Every message and
 * context value passes through the Redactor first, so secrets never reach storage.
 */
final class Logger {

	public const OPTION      = 'khseo_log';
	public const MAX_ENTRIES = 200;

	private const LEVELS = array(
		'debug'    => 0,
		'info'     => 1,
		'notice'   => 2,
		'warning'  => 3,
		'error'    => 4,
		'security' => 5,
	);

	/**
	 * Constructor.
	 *
	 * @param Redactor $redactor       Secret redactor.
	 * @param string   $min_level      Lowest level that is stored.
	 * @param int      $retention_days Entries older than this are pruned.
	 */
	public function __construct(
		private Redactor $redactor,
		private string $min_level = 'warning',
		private int $retention_days = 14
	) {}

	/**
	 * Log a message.
	 *
	 * @param string               $level   One of debug, info, notice, warning, error, security.
	 * @param string               $message Message (redacted before storage).
	 * @param array<string, mixed> $context Context (redacted before storage).
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			$level = 'error';
		}
		$min = self::LEVELS[ $this->min_level ] ?? self::LEVELS['warning'];
		// Security events are always kept.
		if ( self::LEVELS[ $level ] < $min && 'security' !== $level ) {
			return;
		}
		$entry     = array(
			'time'    => time(),
			'level'   => $level,
			'message' => $this->redactor->redactString( $message ),
			'context' => $this->redactor->redact( $context ),
		);
		$entries   = get_option( self::OPTION, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = $entry;
		update_option( self::OPTION, self::prune( $entries, time(), $this->retention_days ), false );
	}

	/**
	 * Drop expired entries and cap the total.
	 *
	 * @param array<int, array<string, mixed>> $entries        Entries.
	 * @param int                              $now            Current timestamp.
	 * @param int                              $retention_days Retention in days.
	 * @return array<int, array<string, mixed>>
	 */
	public static function prune( array $entries, int $now, int $retention_days ): array {
		$cutoff  = $now - ( $retention_days * 86400 );
		$entries = array_values( array_filter( $entries, static fn ( $e ): bool => is_array( $e ) && (int) ( $e['time'] ?? 0 ) >= $cutoff ) );
		return array_slice( $entries, -self::MAX_ENTRIES );
	}
}
