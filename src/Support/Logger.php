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
 * context value is redacted, flattened to one line and size-capped before
 * storage, so secrets, forged log lines and oversized payloads never reach it.
 */
final class Logger {

	public const OPTION        = 'khseo_log';
	public const MAX_ENTRIES   = 200;
	public const MAX_MESSAGE   = 500;
	public const MAX_CONTEXT   = 2048;
	public const TRUNCATED     = '…[truncated]';
	public const CONTEXT_LIMIT = '[context too large]';

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
	 * @param string               $message Message (redacted and sanitized before storage).
	 * @param array<string, mixed> $context Context (redacted and size-capped before storage).
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		$entry = $this->entry( $level, $message, $context, time() );
		if ( null === $entry ) {
			return;
		}
		$entries   = get_option( self::OPTION, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = $entry;
		update_option( self::OPTION, self::prune( $entries, time(), $this->retention_days ), false );
	}

	/**
	 * Build a storable entry, or null when the level is filtered out (pure, testable).
	 *
	 * @param string               $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @param int                  $now     Timestamp.
	 * @return array{time: int, level: string, message: string, context: mixed}|null
	 */
	public function entry( string $level, string $message, array $context, int $now ): ?array {
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			$level = 'error';
		}
		$min = self::LEVELS[ $this->min_level ] ?? self::LEVELS['warning'];
		// Security events are always kept.
		if ( self::LEVELS[ $level ] < $min && 'security' !== $level ) {
			return null;
		}
		$context = $this->redactor->redact( $context );
		$json    = json_encode( $context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure class; only measures size.
		if ( false === $json || strlen( $json ) > self::MAX_CONTEXT ) {
			$context = self::CONTEXT_LIMIT;
		}
		return array(
			'time'    => $now,
			'level'   => $level,
			'message' => self::oneLine( $this->redactor->redactString( $message ) ),
			'context' => $context,
		);
	}

	/**
	 * Flatten to a single safe line: no control characters (log forging), no tags, bounded length.
	 *
	 * @param string $text Input.
	 */
	public static function oneLine( string $text ): string {
		// Cut first so huge inputs never reach the regex engine.
		$cut  = strlen( $text ) > self::MAX_MESSAGE * 4;
		$text = substr( $text, 0, self::MAX_MESSAGE * 4 );
		// Terminal escape sequences (colours, cursor moves, OSC titles/links) are removed whole.
		$text = (string) preg_replace( array( '/\x1B\[[0-?]*[ -\/]*[@-~]/', '/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)?/' ), '', $text );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
		$text = trim( (string) preg_replace( '/<[^>]*>/', '', $text ) );
		if ( $cut || mb_strlen( $text ) > self::MAX_MESSAGE ) {
			$text = mb_substr( $text, 0, self::MAX_MESSAGE ) . self::TRUNCATED;
		}
		return $text;
	}

	/**
	 * Drop expired entries and cap the total.
	 *
	 * @param array<int, mixed> $entries        Entries (untrusted: read from the database).
	 * @param int               $now            Current timestamp.
	 * @param int               $retention_days Retention in days.
	 * @return array<int, array<string, mixed>>
	 */
	public static function prune( array $entries, int $now, int $retention_days ): array {
		$cutoff  = $now - ( $retention_days * 86400 );
		$entries = array_values( array_filter( $entries, static fn ( $e ): bool => is_array( $e ) && (int) ( $e['time'] ?? 0 ) >= $cutoff ) );
		return array_slice( $entries, -self::MAX_ENTRIES );
	}
}
