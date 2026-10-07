<?php
/**
 * Builds the status shown on the Overview screen and by REST /status.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use KHSEO\Core\Container;
use KHSEO\Rules\RuleRegistry;
use KHSEO\Support\Evidence;

/**
 * One source for "what is the state of this site", so the dashboard and the API
 * can never disagree. Contains no secrets: only whether an AI key exists.
 */
final class StatusReport {

	/**
	 * Build the report.
	 *
	 * @param Container $container Services.
	 * @return array<string, mixed>
	 */
	public static function build( Container $container ): array {
		$rules    = $container->get( RuleRegistry::class );
		$settings = $container->get( 'settings' );
		$checks   = new SiteChecks( $rules );
		$findings = $checks->run( '1' === (string) get_option( 'blog_public', '1' ), home_url(), site_url() );

		$secrets      = get_option( 'khseo_secrets', array() );
		$ai_key_saved = is_array( $secrets ) && ! empty( $secrets['ai_api_key'] );
		$ai_ready     = 'none' !== $settings['ai_provider'] && $ai_key_saved;

		return array(
			'version'          => KHSEO_VERSION,
			'rules_registered' => $rules->count(),
			'findings'         => array_map( static fn ( Finding $f ): array => $f->toArray(), $findings ),
			'content_audit'    => array(
				'status' => Evidence::NOT_TESTED->value,
				'reason' => 'The page-level audit engine arrives in Phase 2.',
			),
			'search_console'   => array(
				'status' => Evidence::UNKNOWN->value,
				'reason' => 'Search Console not connected.',
			),
			'ai'               => array(
				'configured' => $ai_ready,
				'provider'   => $settings['ai_provider'],
				'message'    => $ai_ready ? 'AI provider configured.' : 'AI Provider Not Configured — every KHSEO check above works without AI.',
			),
			'compatibility'    => $settings['compatibility_mode'],
		);
	}
}
