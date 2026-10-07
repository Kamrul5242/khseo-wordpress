<?php
/**
 * Safe, bounded HTML → PageSnapshot parser.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use KHSEO\Http\FetchResult;

/**
 * Parses with libxml's HTML parser (tolerates malformed markup, no network, no
 * entity expansion, never runs JavaScript). Input is capped before parsing so a
 * huge page cannot exhaust memory, and every extracted list is capped too.
 */
final class HtmlParser {

	public const MAX_PARSE_BYTES = 2_000_000;
	public const MAX_ITEMS       = 2000;
	public const MAX_JSONLD      = 20;
	public const MAX_JSONLD_SIZE = 200_000;
	public const MAX_TEXT        = 300_000;

	/**
	 * Build a snapshot from a fetch result.
	 *
	 * @param string      $requested_url Normalised URL that was requested.
	 * @param FetchResult $result        Successful fetch.
	 */
	public static function fromFetch( string $requested_url, FetchResult $result ): PageSnapshot {
		$headers      = $result->headers;
		$content_type = (string) ( $headers['content-type'] ?? '' );
		$media        = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
		$is_html      = in_array( $media, array( 'text/html', 'application/xhtml+xml' ), true ) || ( '' === $media && '' !== $result->body );
		$header_cs    = preg_match( '/charset=([a-z0-9_\-]+)/i', $content_type, $m ) ? strtolower( $m[1] ) : '';
		$base         = array(
			'url'              => $requested_url,
			'final_url'        => $result->final_url,
			'status'           => $result->status,
			'chain'            => $result->chain,
			'content_type'     => $content_type,
			'x_robots_tag'     => strtolower( (string) ( $headers['x-robots-tag'] ?? '' ) ),
			'content_language' => (string) ( $headers['content-language'] ?? '' ),
		);
		if ( ! $is_html || '' === $result->body ) {
			return self::build( $base, array( 'charset' => $header_cs ) );
		}
		return self::parse( $base, $result->body, $header_cs );
	}

	/**
	 * Parse HTML into a snapshot.
	 *
	 * @param array<string, mixed> $base      Snapshot header fields.
	 * @param string               $html      Raw HTML (untrusted).
	 * @param string               $header_cs Charset from the Content-Type header.
	 */
	public static function parse( array $base, string $html, string $header_cs = '' ): PageSnapshot {
		$truncated = strlen( $html ) > self::MAX_PARSE_BYTES;
		$html      = substr( $html, 0, self::MAX_PARSE_BYTES );
		$meta_cs   = preg_match( '/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9_\-]+)/i', substr( $html, 0, 4096 ), $m ) ? strtolower( $m[1] ) : '';
		$charset   = '' !== $header_cs ? $header_cs : $meta_cs;
		$html      = self::toUtf8( $html, $charset );

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The XML-encoding hint makes libxml read the (now UTF-8) bytes as UTF-8. LIBXML_NONET: no network access.
		$loaded = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return self::build(
				$base,
				array(
					'charset'   => $charset,
					'is_html'   => true,
					'truncated' => $truncated,
				)
			);
		}
		$xp = new DOMXPath( $doc );

		$titles = self::texts( $xp, '//title' );
		$descs  = array();
		$robots = array();
		$og     = array();
		$tw     = array();
		foreach ( self::nodes( $xp, '//meta' ) as $meta ) {
			$name    = strtolower( trim( $meta->getAttribute( 'name' ) ) );
			$prop    = strtolower( trim( $meta->getAttribute( 'property' ) ) );
			$content = self::clean( $meta->getAttribute( 'content' ) );
			if ( 'description' === $name ) {
				$descs[] = $content;
			} elseif ( 'robots' === $name || 'googlebot' === $name ) {
				$robots[] = strtolower( $content );
			}
			if ( str_starts_with( $prop, 'og:' ) ) {
				$og[ $prop ][] = $content;
			}
			$tw_key = str_starts_with( $name, 'twitter:' ) ? $name : ( str_starts_with( $prop, 'twitter:' ) ? $prop : '' );
			if ( '' !== $tw_key ) {
				$tw[ $tw_key ][] = $content;
			}
		}

		$canonicals = array();
		foreach ( self::nodes( $xp, '//link[@rel]' ) as $link ) {
			if ( in_array( 'canonical', self::tokens( $link->getAttribute( 'rel' ) ), true ) ) {
				$canonicals[] = trim( $link->getAttribute( 'href' ) );
			}
		}

		$headings = array();
		foreach ( self::nodes( $xp, '//h1|//h2|//h3|//h4|//h5|//h6' ) as $h ) {
			$headings[] = array(
				'level' => (int) substr( strtolower( $h->nodeName ), 1 ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
				'text'  => self::clean( $h->textContent ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			);
		}

		$links = array();
		foreach ( self::nodes( $xp, '//a[@href]' ) as $a ) {
			$text = self::clean( $a->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			if ( '' === $text ) {
				$text = self::clean( $a->getAttribute( 'aria-label' ) . ' ' . $a->getAttribute( 'title' ) );
			}
			if ( '' === $text ) {
				foreach ( self::nodes( $xp, './/img[@alt]', $a ) as $img ) {
					$text = self::clean( $img->getAttribute( 'alt' ) );
				}
			}
			$links[] = array(
				'href' => trim( $a->getAttribute( 'href' ) ),
				'text' => $text,
				'rel'  => self::tokens( $a->getAttribute( 'rel' ) ),
			);
		}

		$images = array();
		foreach ( self::nodes( $xp, '//img' ) as $img ) {
			$images[] = array(
				'src'     => trim( $img->getAttribute( 'src' ) ),
				'alt'     => $img->hasAttribute( 'alt' ) ? self::clean( $img->getAttribute( 'alt' ) ) : null,
				'sized'   => '' !== trim( $img->getAttribute( 'width' ) ) && '' !== trim( $img->getAttribute( 'height' ) ),
				'loading' => strtolower( trim( $img->getAttribute( 'loading' ) ) ),
			);
		}

		$json_ld = array();
		foreach ( self::nodes( $xp, '//script[@type]' ) as $script ) {
			if ( 'application/ld+json' === strtolower( trim( explode( ';', $script->getAttribute( 'type' ) )[0] ) ) && count( $json_ld ) < self::MAX_JSONLD ) {
				$json_ld[] = substr( (string) $script->textContent, 0, self::MAX_JSONLD_SIZE ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			}
		}

		$lang_nodes = self::nodes( $xp, '/html[@lang]' );
		$html_lang  = array() === $lang_nodes ? null : trim( $lang_nodes[0]->getAttribute( 'lang' ) );

		// Visible text: body without script/style/noscript/template. Never executed, only read.
		foreach ( self::nodes( $xp, '//script|//style|//noscript|//template' ) as $hidden ) {
			$hidden->parentNode?->removeChild( $hidden ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
		}
		$body = self::nodes( $xp, '//body' );
		$text = self::clean( mb_substr( (string) ( array() === $body ? $doc->textContent : $body[0]->textContent ), 0, self::MAX_TEXT ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.

		return self::build(
			$base,
			array(
				'charset'      => '' !== $charset ? $charset : 'utf-8-assumed',
				'is_html'      => true,
				'truncated'    => $truncated,
				'titles'       => $titles,
				'descriptions' => $descs,
				'canonicals'   => $canonicals,
				'robots_meta'  => $robots,
				'html_lang'    => $html_lang,
				'headings'     => array_slice( $headings, 0, self::MAX_ITEMS ),
				'links'        => array_slice( $links, 0, self::MAX_ITEMS ),
				'images'       => array_slice( $images, 0, self::MAX_ITEMS ),
				'json_ld'      => $json_ld,
				'open_graph'   => $og,
				'twitter'      => $tw,
				'text'         => $text,
				'word_count'   => '' === $text ? 0 : count( (array) preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ),
			)
		);
	}

	/**
	 * Build a snapshot from header fields and extracted values.
	 *
	 * @param array<string, mixed> $base Header fields (url, final_url, status, chain, content_type, x_robots_tag, content_language).
	 * @param array<string, mixed> $x    Extracted values keyed like the PageSnapshot parameters.
	 */
	private static function build( array $base, array $x ): PageSnapshot {
		return new PageSnapshot(
			url: (string) $base['url'],
			final_url: (string) $base['final_url'],
			status: (int) $base['status'],
			chain: array_values( array_map( 'strval', (array) $base['chain'] ) ),
			content_type: (string) $base['content_type'],
			x_robots_tag: (string) $base['x_robots_tag'],
			content_language: (string) $base['content_language'],
			charset: (string) ( $x['charset'] ?? '' ),
			is_html: (bool) ( $x['is_html'] ?? false ),
			truncated: (bool) ( $x['truncated'] ?? false ),
			titles: $x['titles'] ?? array(),
			descriptions: $x['descriptions'] ?? array(),
			canonicals: $x['canonicals'] ?? array(),
			robots_meta: $x['robots_meta'] ?? array(),
			html_lang: $x['html_lang'] ?? null,
			headings: $x['headings'] ?? array(),
			links: $x['links'] ?? array(),
			images: $x['images'] ?? array(),
			json_ld: $x['json_ld'] ?? array(),
			open_graph: $x['open_graph'] ?? array(),
			twitter: $x['twitter'] ?? array(),
			text: (string) ( $x['text'] ?? '' ),
			word_count: (int) ( $x['word_count'] ?? 0 )
		);
	}

	/**
	 * Convert to valid UTF-8 (unknown charsets are treated as UTF-8 and scrubbed).
	 *
	 * @param string $html    Raw bytes.
	 * @param string $charset Declared charset.
	 */
	private static function toUtf8( string $html, string $charset ): string {
		$charset = strtolower( $charset );
		if ( '' !== $charset && ! in_array( $charset, array( 'utf-8', 'utf8' ), true ) && in_array( strtoupper( $charset ), array_map( 'strtoupper', mb_list_encodings() ), true ) ) {
			$converted = mb_convert_encoding( $html, 'UTF-8', $charset );
			if ( is_string( $converted ) ) {
				return $converted;
			}
		}
		return mb_scrub( $html, 'UTF-8' );
	}

	/**
	 * Matching elements (capped).
	 *
	 * @param DOMXPath        $xp      XPath.
	 * @param string          $query   Query.
	 * @param DOMElement|null $context Context node.
	 * @return array<int, DOMElement>
	 */
	private static function nodes( DOMXPath $xp, string $query, ?DOMElement $context = null ): array {
		$list = null === $context ? $xp->query( $query ) : $xp->query( $query, $context );
		$out  = array();
		if ( false !== $list ) {
			foreach ( $list as $node ) {
				if ( $node instanceof DOMElement ) {
					$out[] = $node;
					if ( count( $out ) >= self::MAX_ITEMS ) {
						break;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Cleaned text of every match.
	 *
	 * @param DOMXPath $xp    XPath.
	 * @param string   $query Query.
	 * @return array<int, string>
	 */
	private static function texts( DOMXPath $xp, string $query ): array {
		return array_map( static fn ( DOMElement $n ): string => self::clean( $n->textContent ), self::nodes( $xp, $query ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
	}

	/**
	 * Space-separated tokens, lower-cased.
	 *
	 * @param string $value Attribute value.
	 * @return array<int, string>
	 */
	private static function tokens( string $value ): array {
		return array_values( array_filter( (array) preg_split( '/\s+/', strtolower( trim( $value ) ), -1, PREG_SPLIT_NO_EMPTY ) ) );
	}

	/**
	 * Collapse whitespace and trim.
	 *
	 * @param string $text Text.
	 */
	public static function clean( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
