<?php
/**
 * Rule registry, enums, settings and site-check tests.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Tests\Unit;

use InvalidArgumentException;
use KHSEO\Audit\SiteChecks;
use KHSEO\Rules\Rule;
use KHSEO\Rules\RuleRegistry;
use KHSEO\Settings\Settings;
use KHSEO\Support\Evidence;
use KHSEO\Support\Logger;
use KHSEO\Support\Risk;
use PHPUnit\Framework\TestCase;

final class RulesAndSettingsTest extends TestCase {

	private const RULES_FILE = __DIR__ . '/../../config/rules.php';

	/**
	 * A valid rule row to mutate.
	 *
	 * @return array<string, mixed>
	 */
	private function row(): array {
		return array(
			'id'             => 'SEO-TEST-001',
			'category'       => 'test',
			'severity'       => 'P2',
			'condition'      => 'x',
			'evidence'       => 'VERIFIED',
			'recommendation' => 'y',
			'auto_fixable'   => false,
			'risk'           => 'R2',
			'reversible'     => true,
		);
	}

	public function test_bundled_rules_load_and_are_valid(): void {
		$registry = RuleRegistry::fromFile( self::RULES_FILE );
		$this->assertGreaterThanOrEqual( 8, $registry->count() );
		$this->assertNotNull( $registry->get( 'SEO-TITLE-001' ) );
		foreach ( $registry->all() as $id => $rule ) {
			$this->assertMatchesRegularExpression( Rule::ID_PATTERN, $id );
		}
	}

	public function test_duplicate_rule_ids_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		RuleRegistry::fromConfig( array( $this->row(), $this->row() ) );
	}

	public function test_auto_fix_must_be_reversible_and_low_risk(): void {
		$irreversible = array_merge( $this->row(), array( 'auto_fixable' => true, 'risk' => 'R1', 'reversible' => false ) );
		$risky        = array_merge( $this->row(), array( 'auto_fixable' => true, 'risk' => 'R3' ) );
		foreach ( array( $irreversible, $risky ) as $bad ) {
			try {
				Rule::fromArray( $bad );
				$this->fail( 'Unsafe auto-fix rule was accepted.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_bad_ids_and_labels_are_rejected(): void {
		$this->expectException( \ValueError::class );
		Rule::fromArray( array_merge( $this->row(), array( 'evidence' => 'PROBABLY' ) ) );
	}

	public function test_risk_semantics(): void {
		$this->assertTrue( Risk::R0->allowsAutoApply( false ) );
		$this->assertFalse( Risk::R1->allowsAutoApply( false ) );
		$this->assertTrue( Risk::R1->allowsAutoApply( true ) );
		$this->assertFalse( Risk::R2->allowsAutoApply( true ) );
		$this->assertTrue( Risk::R3->requiresRecovery() );
		$this->assertSame( 'NOT TESTED', Evidence::NOT_TESTED->value );
		$this->assertFalse( Evidence::INFERRED->isFact() );
	}

	public function test_settings_defaults_are_safe(): void {
		$s = Settings::normalize( null );
		$this->assertFalse( $s['delete_data_on_uninstall'] );
		$this->assertFalse( $s['safe_auto_fixes'] );
		$this->assertSame( 'none', $s['ai_provider'] );
		$this->assertFalse( $s['ai_send_content'] );
	}

	public function test_settings_sanitizer_drops_unknown_and_rejects_invalid(): void {
		$current = Settings::DEFAULTS;
		$out     = Settings::sanitize(
			array(
				'evil_option'          => 'x',
				'ai_provider'          => 'skynet',
				'compatibility_mode'   => 'primary',
				'ai_temperature'       => '7',
				'log_retention_days'   => '9999',
				'ai_model'             => '<script>alert(1)</script>',
				'ai_max_output_tokens' => '1200',
				'safe_auto_fixes'      => '1',
				'scheduled_jobs'       => '0',
			),
			$current
		);
		$this->assertArrayNotHasKey( 'evil_option', $out );
		$this->assertSame( 'none', $out['ai_provider'] );
		$this->assertSame( 'primary', $out['compatibility_mode'] );
		$this->assertSame( 0.3, $out['ai_temperature'] );
		$this->assertSame( 14, $out['log_retention_days'] );
		$this->assertSame( '', $out['ai_model'] );
		$this->assertSame( 1200, $out['ai_max_output_tokens'] );
		$this->assertTrue( $out['safe_auto_fixes'] );
		$this->assertFalse( $out['scheduled_jobs'] );
	}

	public function test_export_excludes_secrets_and_import_validates(): void {
		$json = Settings::export( Settings::DEFAULTS + array( 'ai_api_key' => 'sk-should-not-leak-123456' ) );
		$this->assertStringNotContainsString( 'sk-should-not-leak', $json );
		$this->assertStringContainsString( '"secrets": "excluded"', $json );
		$this->assertSame( Settings::DEFAULTS, Settings::import( $json, Settings::DEFAULTS ) );
		$this->assertNull( Settings::import( '{"hello":1}', Settings::DEFAULTS ) );
		$this->assertNull( Settings::import( 'not json', Settings::DEFAULTS ) );
	}

	public function test_site_checks_report_real_state_with_evidence(): void {
		$checks = new SiteChecks( RuleRegistry::fromFile( self::RULES_FILE ) );

		$bad = $checks->run( false, 'http://example.com', 'http://example.com' );
		$this->assertCount( 2, $bad );
		foreach ( $bad as $finding ) {
			$this->assertFalse( $finding->passed );
			$this->assertSame( Evidence::VERIFIED, $finding->evidence );
			$this->assertSame( SiteChecks::SOURCE, $finding->source );
		}

		$good = $checks->run( true, 'https://example.com', 'https://example.com' );
		$this->assertTrue( $good[0]->passed && $good[1]->passed );
		$this->assertSame( '', $good[0]->toArray()['recommendation'] );
	}

	public function test_mixed_https_is_a_failure(): void {
		$checks   = new SiteChecks( RuleRegistry::fromFile( self::RULES_FILE ) );
		$findings = $checks->run( true, 'https://example.com', 'http://example.com' );
		$this->assertFalse( $findings[1]->passed );
	}

	public function test_log_prune_drops_old_and_caps_size(): void {
		$now     = 1_000_000_000;
		$entries = array( array( 'time' => $now - 20 * 86400 ) );
		for ( $i = 0; $i < 250; $i++ ) {
			$entries[] = array( 'time' => $now );
		}
		$pruned = Logger::prune( $entries, $now, 14 );
		$this->assertCount( Logger::MAX_ENTRIES, $pruned );
		$this->assertSame( $now, $pruned[0]['time'] );
	}
}
