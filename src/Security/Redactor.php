<?php
/**
 * Removes secrets from strings and arrays before they are logged or shown.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Security;

/**
 * Pattern- and key-based redaction.
 */
final class Redactor {

	public const MASK = '[REDACTED]';

	/**
	 * Token shapes masked entirely.
	 */
	private const TOKEN_PATTERNS = array(
		'/sk-ant-[A-Za-z0-9_\-]{10,}/',            // Anthropic.
		'/sk-(?:proj-)?[A-Za-z0-9_\-]{16,}/',      // OpenAI-style.
		'/AIza[0-9A-Za-z_\-]{35}/',                // Google API key.
		'/ya29\.[0-9A-Za-z_\-\.]+/',               // Google OAuth access token.
		'/1\/\/[0-9A-Za-z_\-]{20,}/',              // Google OAuth refresh token.
		'/gh[pousr]_[A-Za-z0-9]{30,}/',            // GitHub tokens.
	);

	/**
	 * Labelled secrets: group 1 (the label) is kept, the value is masked.
	 */
	private const LABELLED_PATTERNS = array(
		'/(?i)(bearer\s+)[A-Za-z0-9_\-\.=+\/]{8,}/',
		'/(?i)((?:authorization|cookie|set-cookie)\s*:\s*)[^\r\n]+/',
		'/(?i)((?:api[_-]?key|password|passwd|secret|token)\s*[=:]\s*)[^\s&"\']+/',
		'/(?i)([?&](?:access_token|refresh_token|id_token|token|api_?key|key|password|pass|secret|client_secret|signature|sig|auth|code|session)=)[^&#\s"\']+/',
	);

	/**
	 * Array keys whose values are always redacted.
	 */
	private const SENSITIVE_KEY = '/(pass|secret|token|api[_-]?key|auth|cookie|salt|credential)/i';

	/**
	 * Exact secret values registered at runtime (e.g. the decrypted API key in use).
	 *
	 * @var array<int, string>
	 */
	private array $known = array();

	/**
	 * Register an exact value that must never appear in output.
	 *
	 * @param string $secret Secret value (ignored if shorter than 6 chars).
	 */
	public function addSecret( string $secret ): void {
		if ( strlen( $secret ) >= 6 ) {
			$this->known[] = $secret;
		}
	}

	/**
	 * Redact a string.
	 *
	 * @param string $text Input.
	 */
	public function redactString( string $text ): string {
		foreach ( $this->known as $secret ) {
			$text = str_replace( $secret, self::MASK, $text );
		}
		foreach ( self::TOKEN_PATTERNS as $pattern ) {
			$text = (string) preg_replace( $pattern, self::MASK, $text );
		}
		foreach ( self::LABELLED_PATTERNS as $pattern ) {
			$text = (string) preg_replace( $pattern, '${1}' . self::MASK, $text );
		}
		return $text;
	}

	/**
	 * Redact a value recursively: sensitive keys are masked entirely, strings are pattern-scanned.
	 *
	 * @param mixed $value Input.
	 * @return mixed
	 */
	public function redact( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return $this->redactString( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = is_string( $key ) && preg_match( self::SENSITIVE_KEY, $key ) ? self::MASK : $this->redact( $item );
			}
			return $out;
		}
		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}
		return '[' . get_debug_type( $value ) . ']';
	}
}
