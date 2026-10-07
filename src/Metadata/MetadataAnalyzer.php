<?php
/**
 * Title, meta description and heading checks.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Metadata;

use KHSEO\Audit\PageSnapshot;
use KHSEO\Audit\RuleResult as R;
use KHSEO\Technical\TechnicalAnalyzer;

/**
 * Length limits are character counts (documented), not pixel widths: KHSEO does
 * not claim how Google will truncate or whether it will use the description.
 */
final class MetadataAnalyzer {

	public const SOURCE         = 'fetched HTML';
	public const TITLE_MAX      = 60;
	public const TITLE_MIN      = 10;
	public const DESC_MIN       = 50;
	public const DESC_MAX       = 160;
	private const HEADING_RULES = array( 'SEO-TITLE-001', 'SEO-TITLE-003', 'SEO-TITLE-004', 'SEO-TITLE-005', 'SEO-META-001', 'SEO-META-003', 'SEO-META-004', 'SEO-META-005', 'SEO-META-006', 'SEO-H1-001', 'SEO-H1-002', 'SEO-HEAD-001', 'SEO-HEAD-002' );

	/**
	 * Analyse one page.
	 *
	 * @param PageSnapshot $p Page.
	 * @return array<int, R>
	 */
	public static function analyze( PageSnapshot $p ): array {
		$u = $p->url;
		if ( ! $p->is_html ) {
			return array_map( static fn ( string $r ): R => R::notTested( $r, $u, 'The response is not HTML.' ), self::HEADING_RULES );
		}
		$out = array();

		// Title.
		$titles = array_values( array_filter( $p->titles, static fn ( string $t ): bool => '' !== $t ) );
		$title  = $titles[0] ?? '';
		$out[]  = '' === $title
			? R::fail( 'SEO-TITLE-001', $u, array() === $p->titles ? 'No <title> element.' : '<title> is empty.', self::SOURCE )
			: R::pass( 'SEO-TITLE-001', $u, 'Title: "' . TechnicalAnalyzer::short( $title ) . '"', self::SOURCE );
		$out[]  = count( $p->titles ) > 1
			? R::fail( 'SEO-TITLE-003', $u, count( $p->titles ) . ' <title> elements found.', self::SOURCE )
			: R::pass( 'SEO-TITLE-003', $u, 'One <title> element.', self::SOURCE );
		if ( '' !== $title ) {
			$len   = mb_strlen( $title );
			$out[] = $len > self::TITLE_MAX
				? R::fail( 'SEO-TITLE-004', $u, $len . ' characters (limit used: ' . self::TITLE_MAX . ').', self::SOURCE )
				: R::pass( 'SEO-TITLE-004', $u, $len . ' characters.', self::SOURCE );
			$out[] = $len < self::TITLE_MIN
				? R::fail( 'SEO-TITLE-005', $u, $len . ' characters (minimum used: ' . self::TITLE_MIN . ').', self::SOURCE )
				: R::pass( 'SEO-TITLE-005', $u, $len . ' characters.', self::SOURCE );
		}

		// Meta description.
		$descs = array_values( array_filter( $p->descriptions, static fn ( string $d ): bool => '' !== $d ) );
		$desc  = $descs[0] ?? '';
		$out[] = '' === $desc
			? R::fail( 'SEO-META-001', $u, array() === $p->descriptions ? 'No meta description.' : 'Meta description is empty.', self::SOURCE )
			: R::pass( 'SEO-META-001', $u, 'Description present.', self::SOURCE );
		$out[] = count( $p->descriptions ) > 1
			? R::fail( 'SEO-META-003', $u, count( $p->descriptions ) . ' meta descriptions found.', self::SOURCE )
			: R::pass( 'SEO-META-003', $u, 'At most one meta description.', self::SOURCE );
		if ( '' !== $desc ) {
			$len   = mb_strlen( $desc );
			$out[] = $len < self::DESC_MIN
				? R::fail( 'SEO-META-004', $u, $len . ' characters (minimum used: ' . self::DESC_MIN . ').', self::SOURCE )
				: R::pass( 'SEO-META-004', $u, $len . ' characters.', self::SOURCE );
			$out[] = $len > self::DESC_MAX
				? R::fail( 'SEO-META-005', $u, $len . ' characters (limit used: ' . self::DESC_MAX . ').', self::SOURCE )
				: R::pass( 'SEO-META-005', $u, $len . ' characters.', self::SOURCE );
			$out[] = 1 === preg_match( '/<\/?[a-z][^>]*>/i', $desc )
				? R::fail( 'SEO-META-006', $u, 'Contains markup: "' . TechnicalAnalyzer::short( $desc ) . '"', self::SOURCE )
				: R::pass( 'SEO-META-006', $u, 'Plain text.', self::SOURCE );
		}

		// Headings.
		$h1    = count( array_filter( $p->headings, static fn ( array $h ): bool => 1 === $h['level'] ) );
		$out[] = 0 === $h1
			? R::fail( 'SEO-H1-001', $u, 'No <h1>.', self::SOURCE )
			: R::pass( 'SEO-H1-001', $u, $h1 . ' <h1> heading(s).', self::SOURCE );
		$out[] = $h1 > 1
			? R::fail( 'SEO-H1-002', $u, $h1 . ' <h1> headings.', self::SOURCE )
			: R::pass( 'SEO-H1-002', $u, 'At most one <h1>.', self::SOURCE );

		$skips = array();
		$prev  = 0;
		foreach ( $p->headings as $h ) {
			if ( $prev > 0 && $h['level'] > $prev + 1 ) {
				$skips[] = 'h' . $prev . ' → h' . $h['level'];
			}
			$prev = $h['level'];
		}
		$out[] = array() !== $skips
			? R::fail( 'SEO-HEAD-001', $u, 'Skipped levels: ' . implode( ', ', array_slice( array_unique( $skips ), 0, 5 ) ), self::SOURCE )
			: R::pass( 'SEO-HEAD-001', $u, 'No skipped heading levels.', self::SOURCE );
		$empty = count( array_filter( $p->headings, static fn ( array $h ): bool => '' === $h['text'] ) );
		$out[] = $empty > 0
			? R::fail( 'SEO-HEAD-002', $u, $empty . ' empty heading(s).', self::SOURCE )
			: R::pass( 'SEO-HEAD-002', $u, 'No empty headings.', self::SOURCE );

		return $out;
	}
}
