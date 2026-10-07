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

	public function test_no_hidden_characters_or_stray_tool_output_in_source(): void {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::ROOT, \FilesystemIterator::SKIP_DOTS ) );
		$checked  = 0;
		foreach ( $iterator as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			if ( preg_match( '#/(vendor|\.git|\.phpunit\.cache|node_modules)/#', $path ) || ! preg_match( '/\.(php|md|txt|json|yml|sh|css|dist|neon)$|VERSION$|\.git(ignore|attributes)$/', $path ) ) {
				continue;
			}
			$text = (string) file_get_contents( $path );
			++$checked;
			// Bidi controls, zero-width characters and BOM (Trojan Source), plus C0 controls except tab/newline/CR.
			$this->assertDoesNotMatchRegularExpression( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]|[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $text, $path . ' contains hidden characters' );
			// A local tool once wrote its status line into files; never ship it.
			$this->assertStringNotContainsString( 'Command outcome ' . 'recorded', $text, $path . ' contains stray tool output' );
		}
		$this->assertGreaterThan( 30, $checked );
	}
}
