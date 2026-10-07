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
use KHSEO\Security\SecretStore;
use KHSEO\Support\Evidence;

/**
 * One source for "what is the state of this site", so the dashboard and the API
 * can never disagree. Contains no secrets: only whether an AI key exists.
 *
 * Integration states are honest: a saved key is not "connected", and a feature
 * that is not built is reported as PLANNED.
 */
final class StatusReport {

	public const STATE_NOT_CONFIGURED = 'not_configured';
	public const STATE_KEY_SAVED      = 'key_saved';
	public const FEATURE_PLANNED      = 'PLANNED';

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
		$ai_key_saved = is_array( $secrets ) && is_string( $secrets['ai_api_key'] ?? null ) && '' !== $secrets['ai_api_key'];
		$ai_state     = ( 'none' !== $settings['ai_provider'] && $ai_key_saved ) ? self::STATE_KEY_SAVED : self::STATE_NOT_CONFIGURED;
		$key_material = SecretStore::siteKeyMaterial();

		return array(
			'version'          => KHSEO_VERSION,
			'rules_registered' => $rules->count(),
			'findings'         => array_map( static fn ( Finding $f ): array => $f->toArray(), $findings ),
			'content_audit'    => array(
				'status' => Evidence::NOT_TESTED->value,
				'reason' => 'The page-level audit engine arrives in Phase 2.',
			),
			'search_console'   => array(
				'state'  => self::STATE_NOT_CONFIGURED,
				'status' => Evidence::UNKNOWN->value,
				'reason' => 'Search Console not connected.',
			),
			'ai'               => array(
				'state'      => $ai_state,
				'provider'   => $settings['ai_provider'],
				'features'   => self::FEATURE_PLANNED,
				// Kept for API compatibility with 0.1.0: true only means a key is saved for a chosen provider.
				'configured' => self::STATE_KEY_SAVED === $ai_state,
				'message'    => self::STATE_KEY_SAVED === $ai_state
					? 'An API key is saved, but AI features are not built yet (PLANNED). Nothing is sent to any AI provider.'
					: 'AI Provider Not Configured — every KHSEO check above works without AI.',
			),
			'encryption'       => array(
				'available'  => null !== $key_material,
				'key_source' => null === $key_material ? 'none' : $key_material['source'],
			),
			'compatibility'    => $settings['compatibility_mode'],
		);
	}
}
