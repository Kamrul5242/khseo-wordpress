<?php
/**
 * Detects other SEO plugins that may output titles, descriptions, canonicals, robots, OG or schema.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Compatibility;

/**
 * Detection only. KHSEO never disables another plugin and, in this version,
 * outputs no metadata of its own (metadata output is PLANNED).
 */
final class SeoProviderDetector {

	/**
	 * Plugin name => constant the plugin defines when active.
	 */
	public const SIGNATURES = array(
		'Yoast SEO'         => 'WPSEO_VERSION',
		'Rank Math'         => 'RANK_MATH_VERSION',
		'All in One SEO'    => 'AIOSEO_VERSION',
		'SEOPress'          => 'SEOPRESS_VERSION',
		'The SEO Framework' => 'THE_SEO_FRAMEWORK_VERSION',
		'Slim SEO'          => 'SLIM_SEO_VER',
		'Squirrly SEO'      => 'SQ_VERSION',
	);

	/**
	 * Active SEO plugins.
	 *
	 * @return array<int, string>
	 */
	public static function detect(): array {
		$found = array();
		foreach ( self::SIGNATURES as $name => $constant ) {
			if ( defined( $constant ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/**
	 * Human label: "None detected", one name, or "Multiple: …".
	 *
	 * @param array<int, string> $found Detected names.
	 */
	public static function label( array $found ): string {
		return match ( count( $found ) ) {
			0       => 'None detected',
			1       => $found[0],
			default => 'Multiple: ' . implode( ', ', $found ),
		};
	}
}
