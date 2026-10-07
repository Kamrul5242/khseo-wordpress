<?php
/**
 * Phase 2: parsing, analyzers, robots/sitemap, structured data, cross-page checks, score.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Tests\Unit;

use InvalidArgumentException;
use KHSEO\Audit\AuditContext;
use KHSEO\Audit\CrossPageAnalyzer;
use KHSEO\Audit\HtmlParser;
use KHSEO\Audit\PageAnalyzer;
use KHSEO\Audit\PageSnapshot;
use KHSEO\Audit\RuleResult;
use KHSEO\Audit\Url;
use KHSEO\Fixes\FixProposal;
use KHSEO\Http\FetchResult;
use KHSEO\Rules\Rule;
use KHSEO\Rules\RuleRegistry;
use KHSEO\Schema\JsonLdParser;
use KHSEO\Score\ScoreCalculator;
use KHSEO\Support\Evidence;
use KHSEO\Support\Risk;
use KHSEO\Technical\RobotsTxt;
use KHSEO\Technical\SitemapParser;
use PHPUnit\Framework\TestCase;

final class Phase2Test extends TestCase {

	private const RULES = __DIR__ . '/../../config/rules.php';

	/**
	 * Snapshot from raw HTML as if fetched from $url.
	 *
	 * @param string               $html    HTML.
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Headers.
	 * @param int                  $status  Status.
	 * @param array<int,string>    $chain   Chain.
	 */
	private function snap( string $html, string $url = 'https://shop.example/desk/', array $headers = array( 'content-type' => 'text/html; charset=utf-8' ), int $status = 200, array $chain = array() ): PageSnapshot {
		$ref = new \ReflectionMethod( FetchResult::class, 'success' );
		$res = $ref->invoke( null, $url, $status, $headers, $html, array() === $chain ? array( $url ) : $chain );
		return HtmlParser::fromFetch( $url, $res );
	}

	/**
	 * Results keyed by rule id.
	 *
	 * @param array<int, RuleResult> $results Results.
	 * @return array<string, RuleResult>
	 */
	private function byRule( array $results ): array {
		$out = array();
		foreach ( $results as $r ) {
			$out[ $r->rule_id ] = $r;
		}
		return $out;
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/../fixtures/' . $name );
	}

	// --- Rules & evidence semantics -------------------------------------------

	public function test_rule_definitions_are_complete_and_consistent(): void {
		$registry = RuleRegistry::fromFile( self::RULES );
		$this->assertGreaterThanOrEqual( 60, $registry->count() );
		foreach ( $registry->all() as $rule ) {
			$this->assertContains( $rule->category, Rule::CATEGORIES );
			$this->assertNotSame( '', $rule->docs, $rule->id . ' needs a reference' );
			$this->assertContains( $rule->evidence_requirement, array( Evidence::VERIFIED, Evidence::OBSERVED, Evidence::INFERRED ) );
		}
		foreach ( \KHSEO\Fixes\FixService::fixableRules() as $id ) {
			$this->assertNotNull( $registry->get( $id ), 'fixable rule ' . $id . ' must exist' );
		}
	}

	public function test_a_rule_cannot_claim_unknown_or_not_tested_as_its_requirement(): void {
		$row = array(
			'id'                   => 'SEO-X-001',
			'category'             => 'technical',
			'severity'             => 'P3',
			'evidence_requirement' => 'NOT TESTED',
			'risk'                 => 'R2',
			'condition'            => 'c',
			'recommendation'       => 'r',
			'auto_fixable'         => false,
			'reversible'           => true,
		);
		$this->expectException( InvalidArgumentException::class );
		Rule::fromArray( $row );
	}

	public function test_runtime_results_cannot_spoof_evidence(): void {
		foreach ( array(
			array( RuleResult::NOT_TESTED, Evidence::VERIFIED ),
			array( RuleResult::PASS, Evidence::UNKNOWN ),
			array( RuleResult::FAIL, Evidence::NOT_TESTED ),
			array( RuleResult::UNKNOWN, Evidence::VERIFIED ),
			array( 'maybe', Evidence::VERIFIED ),
		) as [ $status, $evidence ] ) {
			try {
				new RuleResult( 'SEO-TITLE-001', $status, $evidence, 'https://x.example/', 'o', 's' );
				$this->fail( "Accepted {$status} with {$evidence->value}" );
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$a = RuleResult::fail( 'SEO-TITLE-001', 'https://x.example/', 'No <title> element.', 'fetched HTML' );
		$b = RuleResult::fail( 'SEO-TITLE-001', 'https://x.example/', 'No <title> element.', 'fetched HTML' );
		$this->assertSame( $a->key(), $b->key(), 'deterministic finding key' );
		$this->assertNotSame( $a->key(), RuleResult::fail( 'SEO-TITLE-001', 'https://y.example/', 'No <title> element.', 'fetched HTML' )->key() );
	}

	// --- Parser ----------------------------------------------------------------

	public function test_parser_extracts_structure_and_never_treats_scripts_as_text(): void {
		$p = $this->snap( $this->fixture( 'messy.html' ), 'https://site.example/p/' );
		$this->assertSame( array( 'Hi alert(1)', 'Second' ), $p->titles );
		$this->assertStringNotContainsString( 'IGNORE PREVIOUS INSTRUCTIONS', $p->text, 'script content is not visible text' );
		$this->assertCount( 2, $p->json_ld );
		$this->assertSame( 'en', $p->html_lang );
		$this->assertTrue( $p->isNoindex() );
	}

	public function test_parser_tolerates_broken_and_huge_documents(): void {
		$broken = $this->snap( '<p class="a b><title>x</title><h1 <div>', 'https://shop.example/' );
		$this->assertTrue( $broken->is_html );
		$huge = $this->snap( '<html><body>' . str_repeat( '<p>word</p>', 300000 ) . '</body></html>', 'https://shop.example/' );
		$this->assertTrue( $huge->truncated );
		$this->assertLessThanOrEqual( HtmlParser::MAX_TEXT + 10, strlen( $huge->text ) );
	}

	public function test_parser_converts_declared_legacy_charset(): void {
		$html = '<html lang="fr"><head><title>' . mb_convert_encoding( 'Café crème', 'ISO-8859-1', 'UTF-8' ) . '</title></head><body></body></html>';
		$p    = $this->snap( $html, 'https://shop.example/', array( 'content-type' => 'text/html; charset=ISO-8859-1' ) );
		$this->assertSame( 'Café crème', $p->titles[0] );
	}

	public function test_x_robots_tag_for_other_bots_is_not_applied_to_googlebot(): void {
		$p = $this->snap( '<html><head><title>t</title></head></html>', 'https://shop.example/', array( 'content-type' => 'text/html', 'x-robots-tag' => 'otherbot: noindex' ) );
		$this->assertFalse( $p->isNoindex() );
		$g = $this->snap( '<html><head><title>t</title></head></html>', 'https://shop.example/', array( 'content-type' => 'text/html', 'x-robots-tag' => 'googlebot: noindex, nofollow' ) );
		$this->assertTrue( $g->isNoindex() );
	}

	// --- Page analyzers ----------------------------------------------------------

	public function test_clean_page_passes_every_page_check_it_can_measure(): void {
		$out     = PageAnalyzer::analyze( $this->snap( $this->fixture( 'clean.html' ) ), new AuditContext( 'https://shop.example/' ) );
		$failing = array_filter( $out['results'], static fn ( RuleResult $r ): bool => RuleResult::FAIL === $r->status );
		$this->assertSame( array(), array_map( static fn ( RuleResult $r ): string => $r->rule_id . ': ' . $r->observation, array_values( $failing ) ) );
		$by = $this->byRule( $out['results'] );
		$this->assertSame( RuleResult::NOT_TESTED, $by['SEO-ROBOTS-002']->status, 'robots.txt not fetched => NOT TESTED, never pass' );
		$this->assertSame( 'https://shop.example/desk/', $out['summary']['canonical'] );
		$this->assertSame( array( 'https://shop.example/chairs/' ), $out['summary']['links'] );
	}

	public function test_hostile_page_findings(): void {
		$by = $this->byRule( PageAnalyzer::analyze( $this->snap( $this->fixture( 'messy.html' ), 'https://site.example/p/' ), new AuditContext( 'https://site.example/' ) )['results'] );
		foreach ( array( 'SEO-INDEX-002', 'SEO-INDEX-003', 'SEO-CANON-003', 'SEO-CANON-005', 'SEO-CANON-007', 'SEO-TITLE-003', 'SEO-META-004', 'SEO-META-006', 'SEO-H1-002', 'SEO-HEAD-001', 'SEO-HEAD-002', 'SEO-LINK-001', 'SEO-IMG-001', 'SEO-IMG-002', 'SEO-OG-001', 'SEO-OG-002', 'SEO-OG-003', 'SEO-TW-001', 'SEO-SCHEMA-001', 'SEO-SCHEMA-006', 'SEO-SCHEMA-007' ) as $rule ) {
			$this->assertSame( RuleResult::FAIL, $by[ $rule ]->status, $rule );
		}
		$this->assertSame( Evidence::INFERRED, $by['SEO-SCHEMA-007']->evidence, 'visible-content checks are inferences, not facts' );
		$this->assertSame( Evidence::OBSERVED, $by['SEO-CANON-005']->evidence );
	}

	public function test_non_html_response_marks_html_checks_not_tested(): void {
		$p  = $this->snap( '%PDF-1.4', 'https://shop.example/file.pdf', array( 'content-type' => 'application/pdf' ) );
		$by = $this->byRule( PageAnalyzer::analyze( $p, new AuditContext( 'https://shop.example/' ) )['results'] );
		$this->assertSame( RuleResult::FAIL, $by['SEO-HTTP-003']->status );
		foreach ( array( 'SEO-TITLE-001', 'SEO-IMG-001', 'SEO-SCHEMA-001', 'SEO-OG-001', 'SEO-LANG-001' ) as $rule ) {
			$this->assertSame( RuleResult::NOT_TESTED, $by[ $rule ]->status, $rule );
		}
	}

	public function test_http_redirect_and_error_and_plain_http(): void {
		$p  = $this->snap( '<html><head><title>Gone</title></head></html>', 'http://shop.example/old', array( 'content-type' => 'text/html' ), 404, array( 'http://shop.example/old', 'http://shop.example/new' ) );
		$by = $this->byRule( PageAnalyzer::analyze( $p, new AuditContext( 'https://shop.example/' ) )['results'] );
		$this->assertSame( RuleResult::FAIL, $by['SEO-HTTP-001']->status );
		$this->assertSame( RuleResult::FAIL, $by['SEO-HTTP-002']->status );
		$this->assertSame( RuleResult::FAIL, $by['SEO-HTTPS-002']->status );
	}

	public function test_robots_txt_decides_per_url_and_unreadable_robots_is_unknown(): void {
		$robots = RobotsTxt::parse( "User-agent: *\nDisallow: /private/" );
		$ok     = $this->byRule( PageAnalyzer::analyze( $this->snap( '<html></html>', 'https://shop.example/private/x' ), new AuditContext( 'https://shop.example/', AuditContext::ROBOTS_OK, $robots ) )['results'] );
		$this->assertSame( RuleResult::FAIL, $ok['SEO-ROBOTS-002']->status );
		$down = $this->byRule( PageAnalyzer::analyze( $this->snap( '<html></html>' ), new AuditContext( 'https://shop.example/', AuditContext::ROBOTS_UNAVAILABLE ) )['results'] );
		$this->assertSame( RuleResult::UNKNOWN, $down['SEO-ROBOTS-002']->status );
	}

	// --- robots.txt ----------------------------------------------------------------

	public function test_robots_txt_semantics_match_universal_khseo(): void {
		$r = RobotsTxt::parse( "User-agent: Googlebot\nUser-agent: Bingbot\nDisallow: /a\nAllow: /a/ok\n\nUser-agent: *\nDisallow: /\nAllow: /public\nDisallow: /*.pdf$\nSitemap: https://x.example/sitemap.xml\nNot a directive\nCrawl-delay: 5" );
		$this->assertTrue( $r->isAllowed( 'googlebot', 'https://x.example/b' ), 'specific group wins over *' );
		$this->assertFalse( $r->isAllowed( 'googlebot', 'https://x.example/a/x' ) );
		$this->assertTrue( $r->isAllowed( 'googlebot', 'https://x.example/a/ok/1' ), 'longest match wins' );
		$this->assertFalse( $r->isAllowed( 'bingbot', 'https://x.example/a' ), 'consecutive user-agents share a group' );
		$this->assertFalse( $r->isAllowed( 'otherbot', 'https://x.example/anything' ) );
		$this->assertTrue( $r->isAllowed( 'otherbot', 'https://x.example/public/page' ) );
		$this->assertSame( array( 'https://x.example/sitemap.xml' ), $r->sitemaps );
		$this->assertSame( array( 11 ), $r->invalid_lines );
		$tie = RobotsTxt::parse( "User-agent: *\nDisallow: /p\nAllow: /p" );
		$this->assertTrue( $tie->isAllowed( 'x', 'https://x.example/p' ), 'allow wins ties' );
		$empty = RobotsTxt::parse( "User-agent: *\nDisallow:" );
		$this->assertTrue( $empty->isAllowed( 'x', 'https://x.example/' ), 'empty Disallow allows all' );
		$this->assertTrue( RobotsTxt::matches( '/*.pdf$', '/doc/a.pdf' ) );
		$this->assertFalse( RobotsTxt::matches( '/*.pdf$', '/doc/a.pdf?x=1' ) );
		$this->assertTrue( RobotsTxt::matches( '/search?q=', '/search?q=shoes' ), 'query is matched' );
		$this->assertFalse( RobotsTxt::matches( '/(.*)', '/anything' ), 'regex metacharacters are literal' );
	}

	// --- Sitemaps ------------------------------------------------------------------

	public function test_sitemap_parser_handles_urlset_index_and_caps(): void {
		$urlset = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc> https://x.example/a </loc></url><url><loc>https://x.example/b</loc></url><url><loc>https://x.example/c</loc></url></urlset>';
		$p      = SitemapParser::parse( $urlset, 2 );
		$this->assertSame( 'urlset', $p['type'] );
		$this->assertSame( array( 'https://x.example/a', 'https://x.example/b' ), $p['locs'] );
		$this->assertTrue( $p['truncated'] );
		$idx = SitemapParser::parse( '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://x.example/s1.xml</loc></sitemap></sitemapindex>', 10 );
		$this->assertSame( 'sitemapindex', $idx['type'] );
	}

	public function test_sitemap_parser_refuses_xxe_and_malformed_xml(): void {
		$xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><urlset><url><loc>&e;</loc></url></urlset>';
		$this->assertSame( 'invalid', SitemapParser::parse( $xxe, 10 )['type'] );
		$this->assertSame( 'invalid', SitemapParser::parse( '<urlset><url><loc>https://x</loc></url>', 10 )['type'] );
		$this->assertSame( 'invalid', SitemapParser::parse( '<html><body>not a sitemap</body></html>', 10 )['type'] );
		$this->assertSame( 'invalid', SitemapParser::parse( '', 10 )['type'] );
	}

	// --- Structured data --------------------------------------------------------------

	public function test_json_ld_parser_flattens_graph_and_nested_entities(): void {
		$p     = JsonLdParser::parse( array( '{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"A"},{"@type":"Product","name":"P","offers":{"@type":"Offer","price":"1","priceCurrency":"EUR"}}]}' ) );
		$types = array_map( static fn ( array $e ): string => JsonLdParser::types( $e )[0] ?? '', $p['entities'] );
		$this->assertSame( array( 'Organization', 'Product', 'Offer' ), $types );
		$this->assertSame( 0, $p['no_context'] );
	}

	public function test_json_ld_depth_bomb_and_missing_context_are_reported(): void {
		$deep = str_repeat( '{"a":', 100 ) . '1' . str_repeat( '}', 100 );
		$p    = JsonLdParser::parse( array( $deep, '{"@type":"Thing","name":"x"}' ) );
		$this->assertCount( 1, $p['invalid'], 'nesting beyond the depth limit is rejected, not recursed' );
		$this->assertSame( 1, $p['no_context'] );
		$this->assertTrue( JsonLdParser::hasSchemaContext( array( '@vocab' => 'https://schema.org/' ) ) );
		$this->assertFalse( JsonLdParser::hasSchemaContext( 'https://evil.example/schema.org' ) );
	}

	public function test_schema_checks_required_properties_conflicts_and_urls(): void {
		$html = '<html><head><title>T</title><script type="application/ld+json">[{"@context":"https://schema.org","@type":"Article"},'
			. '{"@context":"https://schema.org","@type":"Organization","@id":"https://x.example/#o","name":"One","sameAs":["/relative"]},'
			. '{"@context":"https://schema.org","@type":"Organization","@id":"https://x.example/#o","name":"Two"},'
			. '{"@context":"https://schema.org","@type":"MadeUpType"}]</script></head><body></body></html>';
		$by   = $this->byRule( \KHSEO\Schema\SchemaAnalyzer::analyze( $this->snap( $html, 'https://x.example/' ) ) );
		$this->assertSame( RuleResult::FAIL, $by['SEO-SCHEMA-003']->status, 'Article without headline' );
		$this->assertSame( RuleResult::FAIL, $by['SEO-SCHEMA-004']->status, 'same @id, different names' );
		$this->assertSame( RuleResult::FAIL, $by['SEO-SCHEMA-005']->status );
		$this->assertSame( RuleResult::FAIL, $by['SEO-SCHEMA-009']->status );
		$this->assertStringContainsString( 'NOT TESTED', \KHSEO\Schema\SchemaAnalyzer::analyze( $this->snap( $this->fixture( 'clean.html' ) ) )[3]->observation, 'Rich Results Test is labelled NOT TESTED' );
	}

	public function test_page_without_json_ld_is_not_tested_for_schema_details(): void {
		$by = $this->byRule( \KHSEO\Schema\SchemaAnalyzer::analyze( $this->snap( '<html><head><title>T</title></head><body>x</body></html>' ) ) );
		$this->assertSame( RuleResult::FAIL, $by['SEO-SCHEMA-008']->status );
		$this->assertSame( RuleResult::NOT_TESTED, $by['SEO-SCHEMA-003']->status );
	}

	// --- Cross-page ----------------------------------------------------------------------

	/**
	 * Page summary for cross-page tests.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function page( array $over ): array {
		return $over + array(
			'final'        => $over['url'],
			'status'       => 200,
			'html'         => true,
			'noindex'      => false,
			'title'        => 'Unique ' . $over['url'],
			'desc'         => 'Desc ' . $over['url'],
			'canonical'    => $over['url'],
			'links'        => array(),
			'from_sitemap' => false,
		);
	}

	public function test_cross_page_checks_only_claim_what_was_fetched(): void {
		$pages = array(
			$this->page( array( 'url' => 'https://x.example/a', 'title' => 'Same', 'links' => array( 'https://x.example/b', 'https://x.example/never-fetched' ), 'canonical' => 'https://x.example/b', 'from_sitemap' => true ) ),
			$this->page( array( 'url' => 'https://x.example/b', 'title' => 'same', 'status' => 404, 'from_sitemap' => true ) ),
			$this->page( array( 'url' => 'https://x.example/c', 'title' => 'Same', 'links' => array( 'https://x.example/elsewhere' ), 'canonical' => 'https://x.example/outside' ) ),
		);
		$results = CrossPageAnalyzer::analyze( $pages );
		$find    = static function ( string $rule, string $url ) use ( $results ): ?RuleResult {
			foreach ( $results as $r ) {
				if ( $r->rule_id === $rule && $r->url === $url ) {
					return $r;
				}
			}
			return null;
		};
		$this->assertSame( RuleResult::FAIL, $find( 'SEO-TITLE-002', 'https://x.example/a' )?->status, 'a and c share a title (404 page b excluded)' );
		$this->assertNull( $find( 'SEO-TITLE-002', 'https://x.example/b' ), 'non-indexable pages are not compared' );
		$this->assertSame( RuleResult::FAIL, $find( 'SEO-LINK-002', 'https://x.example/a' )?->status, 'link to fetched 404' );
		$this->assertStringContainsString( '1 other link target(s) not checked', (string) $find( 'SEO-LINK-002', 'https://x.example/a' )?->observation );
		$this->assertSame( RuleResult::NOT_TESTED, $find( 'SEO-LINK-002', 'https://x.example/c' )?->status );
		$this->assertSame( RuleResult::FAIL, $find( 'SEO-CANON-006', 'https://x.example/a' )?->status );
		$this->assertSame( RuleResult::NOT_TESTED, $find( 'SEO-CANON-006', 'https://x.example/c' )?->status );
		$this->assertSame( RuleResult::FAIL, $find( 'SEO-SITEMAP-003', 'https://x.example/b' )?->status );
		$this->assertSame( RuleResult::PASS, $find( 'SEO-SITEMAP-003', 'https://x.example/a' )?->status );
	}

	// --- Score -----------------------------------------------------------------------------

	public function test_score_is_deterministic_documented_and_capped(): void {
		$rules  = RuleRegistry::fromFile( self::RULES );
		$counts = array(
			'SEO-TITLE-001' => array( 'pass' => 3, 'fail' => 1 ), // P1 metadata: earned 12 / 16.
			'SEO-IMG-002'   => array( 'fail' => 2 ),              // P3 images: 0 / 2.
			'SEO-H1-001'    => array( 'not_tested' => 5 ),         // Excluded.
			'SEO-FAKE-999'  => array( 'pass' => 1000 ),            // Unknown rule: ignored.
		);
		$a = ScoreCalculator::calculate( $counts, $rules );
		$b = ScoreCalculator::calculate( array_reverse( $counts, true ), $rules );
		$this->assertSame( $a, $b, 'same inputs, same score, any order' );
		$this->assertSame( 75, $a['categories']['metadata']['score'] );
		$this->assertSame( 0, $a['categories']['images']['score'] );
		$this->assertNull( $a['categories']['content']['score'], 'not-tested categories have no score' );
		$this->assertSame( (int) round( ( 15 * 75 + 5 * 0 ) / 20 ), $a['score'] );
		$this->assertSame( 5, $a['not_tested'] );
		$this->assertStringContainsString( 'not a Google ranking score', $a['disclaimer'] );

		// One P0 failure drowned in 500 passes would round to ~100 without the cap.
		$p0 = ScoreCalculator::calculate( array( 'SEO-INDEX-001' => array( 'fail' => 1 ), 'SEO-INDEX-002' => array( 'pass' => 500 ), 'SEO-TITLE-001' => array( 'pass' => 500 ) ), $rules );
		$this->assertSame( ScoreCalculator::P0_CAP, $p0['score'] );
		$this->assertTrue( $p0['capped'] );
		$low = ScoreCalculator::calculate( array( 'SEO-INDEX-001' => array( 'fail' => 1 ), 'SEO-TITLE-001' => array( 'pass' => 1 ) ), $rules );
		$this->assertSame( 43, $low['score'], 'already below the cap: (20×0 + 15×100) / 35' );
		$this->assertFalse( $low['capped'] );
		$this->assertNull( ScoreCalculator::calculate( array(), $rules )['score'], 'no evidence => no score, never 100' );
		$neg = ScoreCalculator::calculate( array( 'SEO-TITLE-001' => array( 'pass' => -50, 'fail' => 1 ) ), $rules );
		$this->assertSame( 0, $neg['categories']['metadata']['score'], 'negative counts cannot inflate a score' );
	}

	// --- URLs & fixes --------------------------------------------------------------------

	public function test_url_normalisation(): void {
		$this->assertSame( 'https://x.example/a/b?q=1', Url::normalize( 'HTTPS://X.Example:443/a/./c/../b?q=1#frag' ) );
		$this->assertSame( 'http://x.example:8080/', Url::normalize( 'http://x.example:8080' ) );
		$this->assertNull( Url::normalize( 'javascript:alert(1)' ) );
		$this->assertNull( Url::normalize( '/relative' ) );
		$this->assertTrue( Url::sameOrigin( 'https://x.example/a', 'https://x.example:443/b' ) );
		$this->assertFalse( Url::sameOrigin( 'https://x.example/', 'http://x.example/' ) );
	}

	public function test_fix_change_id_covers_exactly_one_change(): void {
		$make = static fn ( string $before ): FixProposal => new FixProposal( 'SEO-INDEX-001', Risk::R3, 't', 'r', 'option:blog_public', $before, '1', array(), 'rb', 'v', 'manage_options' );
		$this->assertSame( $make( '0' )->changeId(), $make( '0' )->changeId() );
		$this->assertNotSame( $make( '0' )->changeId(), $make( '2' )->changeId(), 'a different current value is a different change' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $make( '0' )->changeId() );
	}
}
