<?php
/**
 * Version sources stay synchronised; no stray hook output or hidden characters in source.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProjectConsistencyTest extends TestCase {

	private const ROOT = __DIR__ . '/../../';

	private function read( string $file ): string {
		return (string) file_get_contents( self::ROOT . $file );
	}

	public function test_version_sources_match(): void {
		$version = trim( $this->read( 'VERSION' ) );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $version, 'VERSION must be semver' );
		$main = $this->read( 'khseo.php' );
		$this->assertStringContainsString( ' * Version:           ' . $version . "\n", $main, 'plugin header' );
		$this->assertStringContainsString( "define( 'KHSEO_VERSION', '" . $version . "' );", $main, 'KHSEO_VERSION constant' );
		$this->assertStringContainsString( "define( 'KHSEO_VERSION', '" . $version . "' );", $this->read( 'tools/phpstan-constants.php' ) );
		$this->assertStringContainsString( 'Stable tag: ' . $version, $this->read( 'readme.txt' ) );
		$this->assertMatchesRegularExpression( '/^## \[' . preg_quote( $version, '/' ) . '\]/m', $this->read( 'CHANGELOG.md' ), 'newest CHANGELOG entry' );
	}

	public function test_db_version_matches_the_highest_migration_step(): void {
		// Regression: a stale DB_VERSION silently skipped migration v2 (capabilities on new multisite sites).
		$steps = array_filter( get_class_methods( \KHSEO\Core\Migrations::class ) ?: array(), static fn ( string $m ): bool => 1 === preg_match( '/^migrate\d+$/', $m ) );
		$this->assertSame( array(), $steps, 'migration steps must stay private' );
		$reflection = new \ReflectionClass( \KHSEO\Core\Migrations::class );
		$numbers    = array();
		foreach ( $reflection->getMethods() as $method ) {
			if ( preg_match( '/^migrate(\d+)$/', $method->getName(), $m ) ) {
				$numbers[] = (int) $m[1];
			}
		}
		sort( $numbers );
		$this->assertSame( range( 1, count( $numbers ) ), $numbers, 'migration steps must be contiguous from 1' );
		$this->assertSame( max( $numbers ), \KHSEO\Core\Migrations::DB_VERSION, 'DB_VERSION must equal the highest migrateN step' );
	}

	public function test_only_the_safe_fetcher_makes_outbound_requests(): void {
		$forbidden = '/\b(wp_remote_(get|post|head|request)|wp_safe_remote_(get|post|head|request)|curl_exec|curl_init|fsockopen|stream_socket_client|file_get_contents\s*\(\s*[\'"]https?:)/i';
		$offenders = array();
		$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::ROOT . 'src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			if ( str_contains( $path, '/src/Http/' ) || ! str_ends_with( $path, '.php' ) ) {
				continue;
			}
			if ( preg_match( $forbidden, (string) file_get_contents( $path ) ) ) {
				$offenders[] = $path;
			}
		}
		$this->assertSame( array(), $offenders, 'Outbound HTTP must go through KHSEO\Http\SafeFetcher (SSRF guard + pinning + limits).' );
		$this->assertMatchesRegularExpression( $forbidden, 'wp_remote_get( $u );', 'guard regex sanity check' );
	}

	public function test_no_hidden_characters_or_stray_tool_output_in_source(): void {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::ROOT, \FilesystemIterator::SKIP_DOTS ) );
		$checked  = 0;
		foreach ( $iterator as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			if ( preg_match( '#/(vendor|\.git|\.phpunit\.cache|node_modules|\.fablize)/#', $path ) || str_ends_with( $path, '/LICENSE' ) ) {
				continue;
			}
			$text = (string) file_get_contents( $path );
			++$checked;
			// Bidi controls, zero-width characters and BOM (Trojan Source), plus C0 controls except tab/newline/CR.
			$this->assertDoesNotMatchRegularExpression( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]|[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $text, $path . ' contains hidden characters' );
			// A local tool once wrote its status line into files; never ship it.
			$this->assertStringNotContainsString( 'Command outcome ' . 'recorded', $text, $path . ' contains stray tool output' );
		}
		// Every file is scanned (not only known extensions): a local hook once created stray
		// extension-less files such as "options" containing its status line.
		$this->assertGreaterThan( 30, $checked );
	}
}
