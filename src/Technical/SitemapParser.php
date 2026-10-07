<?php
/**
 * Bounded, XXE-safe XML sitemap parser.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Technical;

use XMLReader;

/**
 * Streams <loc> values with XMLReader. No DTD/entity loading, no network
 * (LIBXML_NONET), and a hard cap on how many URLs are collected.
 */
final class SitemapParser {

	public const TYPE_URLSET  = 'urlset';
	public const TYPE_INDEX   = 'sitemapindex';
	public const TYPE_INVALID = 'invalid';

	/**
	 * Parse.
	 *
	 * @param string $xml      Body (untrusted).
	 * @param int    $max_urls Cap on collected <loc> values.
	 * @return array{type: string, locs: array<int, string>, truncated: bool, error: string}
	 */
	public static function parse( string $xml, int $max_urls ): array {
		$fail = static fn ( string $why ): array => array(
			'type'      => self::TYPE_INVALID,
			'locs'      => array(),
			'truncated' => false,
			'error'     => $why,
		);
		if ( '' === trim( $xml ) ) {
			return $fail( 'Empty document.' );
		}
		// A DOCTYPE is never needed in a sitemap and is the vector for entity attacks: refuse it.
		if ( 1 === preg_match( '/<!DOCTYPE|<!ENTITY/i', substr( $xml, 0, 4096 ) ) ) {
			return $fail( 'Document declares a DOCTYPE/ENTITY, which sitemaps must not use.' );
		}
		$reader   = new XMLReader();
		$previous = libxml_use_internal_errors( true );
		if ( ! $reader->XML( $xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			libxml_use_internal_errors( $previous );
			return $fail( 'Not XML.' );
		}
		$type      = '';
		$locs      = array();
		$truncated = false;
		$in_loc    = false;
		$buffer    = '';
		try {
			while ( @$reader->read() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed XML is reported below.
				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XMLReader API.
				if ( XMLReader::ELEMENT === $reader->nodeType ) {
					if ( '' === $type ) {
						$type = $reader->localName;
					}
					$in_loc = 'loc' === $reader->localName;
					$buffer = '';
				} elseif ( $in_loc && ( XMLReader::TEXT === $reader->nodeType || XMLReader::CDATA === $reader->nodeType ) ) {
					$buffer .= $reader->value;
				} elseif ( XMLReader::END_ELEMENT === $reader->nodeType && 'loc' === $reader->localName ) {
					if ( count( $locs ) >= $max_urls ) {
						$truncated = true;
						break;
					}
					$locs[] = trim( $buffer );
					$in_loc = false;
				}
				// phpcs:enable
			}
			$errors = libxml_get_errors();
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			$reader->close();
		}
		if ( ! $truncated && array() !== $errors ) {
			return $fail( 'Malformed XML (line ' . (int) $errors[0]->line . ').' );
		}
		if ( self::TYPE_URLSET !== $type && self::TYPE_INDEX !== $type ) {
			return $fail( 'Root element is <' . substr( $type, 0, 40 ) . '>, not <urlset> or <sitemapindex>.' );
		}
		return array(
			'type'      => $type,
			'locs'      => $locs,
			'truncated' => $truncated,
			'error'     => '',
		);
	}
}
