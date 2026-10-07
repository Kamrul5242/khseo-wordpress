<?php
/**
 * Builds the status shown on the Overview screen and by REST /status.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use KHSEO\Compatibility\SeoProviderDetector;
use KHSEO\Core\Container;
use KHSEO\Findings\FindingRepository;
use KHSEO\Rules\RuleRegistry;
use KHSEO\Security\SecretStore;
use KHSEO\Support\Evidence;

/**
 * One source for "what is the state of this site", so the dashboard and the API
 * can never disagree. Contains no secrets: only whether an AI key exists.
 * Everything not measured is labelled UNKNOWN / NOT TESTED / PLANNED.
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
		$site     = ( new SiteChecks() )->run( '1' === (string) get_option( 'blog_public', '1' ), home_url(), site_url() );

		$secrets      = get_option( 'khseo_secrets', array() );
		$ai_key_saved = is_array( $secrets ) && is_string( $secrets['ai_api_key'] ?? null ) && '' !== $secrets['ai_api_key'];
		$ai_state     = ( 'none' !== $settings['ai_provider'] && $ai_key_saved ) ? self::STATE_KEY_SAVED : self::STATE_NOT_CONFIGURED;
		$key_material = SecretStore::siteKeyMaterial();
		$last         = get_option( AuditRunner::LAST, null );
		$job          = AuditRunner::job();
		$providers    = SeoProviderDetector::detect();

		return array(
			'version'          => KHSEO_VERSION,
			'rules_registered' => $rules->count(),
			'findings'         => array_map(
				static fn ( RuleResult $r ): array => array(
					'rule'           => $r->rule_id,
					'passed'         => RuleResult::PASS === $r->status,
					'status'         => $r->status,
					'evidence'       => $r->evidence->value,
					'observed'       => $r->observation,
					'source'         => $r->source,
					'severity'       => $rules->get( $r->rule_id )?->severity->value,
					'risk'           => $rules->get( $r->rule_id )?->risk->value,
					'recommendation' => RuleResult::PASS === $r->status ? '' : (string) $rules->get( $r->rule_id )?->recommendation,
				),
				$site
			),
			'last_audit'       => is_array( $last ) ? $last : null,
			'audit'            => null === $job ? null : AuditRunner::publicJob( $job ),
			'open_findings'    => self::openCounts(),
			'content_audit'    => is_array( $last )
				? array(
					'status' => 'done',
					'reason' => 'Last audit: ' . $last['scope'] . '.',
				)
				: array(
					'status' => Evidence::NOT_TESTED->value,
					'reason' => 'No audit has been run yet.',
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
					: 'AI Provider Not Configured — every KHSEO check works without AI.',
			),
			'encryption'       => array(
				'available'  => null !== $key_material,
				'key_source' => null === $key_material ? 'none' : $key_material['source'],
				// The database salt sits next to the ciphertext: report it as degraded, never as equivalent.
				'strength'   => self::strength( null === $key_material ? 'none' : $key_material['source'] ),
			),
			'seo_provider'     => array(
				'detected'       => $providers,
				'label'          => SeoProviderDetector::label( $providers ),
				'khseo_metadata' => self::FEATURE_PLANNED,
			),
			'compatibility'    => $settings['compatibility_mode'],
		);
	}

	/**
	 * Open finding counts, or an empty array if the table is not ready yet.
	 *
	 * @return array<string, int>
	 */
	private static function openCounts(): array {
		global $wpdb;
		$table = FindingRepository::table();
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
			return array();
		}
		return ( new FindingRepository() )->openCounts();
	}

	/**
	 * Encryption strength label for a key source.
	 *
	 * @param string $source SecretStore::SOURCE_* or 'none'.
	 */
	public static function strength( string $source ): string {
		return match ( $source ) {
			SecretStore::SOURCE_CONSTANT => 'dedicated',
			SecretStore::SOURCE_SALTS    => 'standard',
			SecretStore::SOURCE_DATABASE => 'degraded',
			default                      => 'unavailable',
		};
	}
}
