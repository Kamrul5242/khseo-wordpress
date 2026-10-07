<?php
/**
 * Structured-data checks: validity, context/type, required properties, entity
 * consistency, identifier URLs and visible-content consistency.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Schema;

use KHSEO\Audit\PageSnapshot;
use KHSEO\Audit\RuleResult as R;
use KHSEO\Support\Evidence;

/**
 * Detection ≠ eligibility ≠ correctness. KHSEO checks a documented subset of
 * properties; Google's Rich Results Test is NOT run ("NOT TESTED"), and only
 * JSON-LD is read (microdata/RDFa are not checked).
 */
final class SchemaAnalyzer {

	public const SOURCE = 'JSON-LD in fetched HTML';

	/**
	 * Types KHSEO validates => required properties (alternatives separated by "|").
	 */
	public const REQUIRED = array(
		'Organization'   => array( 'name' ),
		'WebSite'        => array( 'name', 'url' ),
		'WebPage'        => array( 'name|headline' ),
		'BreadcrumbList' => array( 'itemListElement' ),
		'Article'        => array( 'headline' ),
		'BlogPosting'    => array( 'headline' ),
		'NewsArticle'    => array( 'headline' ),
		'Product'        => array( 'name' ),
		'Offer'          => array( 'price|priceSpecification', 'priceCurrency|priceSpecification' ),
		'LocalBusiness'  => array( 'name', 'address' ),
		'FAQPage'        => array( 'mainEntity' ),
		'Person'         => array( 'name' ),
	);

	/**
	 * Common supporting types: recognised, not separately validated.
	 */
	public const KNOWN = array( 'ListItem', 'Question', 'Answer', 'PostalAddress', 'ImageObject', 'SearchAction', 'EntryPoint', 'AggregateRating', 'Rating', 'Review', 'Brand', 'ContactPoint', 'GeoCoordinates', 'OpeningHoursSpecification', 'PriceSpecification', 'UnitPriceSpecification', 'MonetaryAmount', 'Thing', 'CollectionPage', 'ItemPage', 'AboutPage', 'ContactPage', 'ProfilePage', 'SearchResultsPage', 'ReadAction', 'VideoObject', 'WPHeader', 'WPFooter', 'WPSideBar', 'SiteNavigationElement', 'Store', 'Restaurant', 'Corporation', 'NGO', 'ItemList', 'Place', 'Event', 'Course', 'Recipe', 'HowTo', 'SoftwareApplication', 'MerchantReturnPolicy', 'OfferShippingDetails', 'QuantitativeValue', 'DefinedRegion', 'ShippingDeliveryTime' );

	/**
	 * Analyse one page.
	 *
	 * @param PageSnapshot $p Page.
	 * @return array<int, R>
	 */
	public static function analyze( PageSnapshot $p ): array {
		$u     = $p->url;
		$rules = array( 'SEO-SCHEMA-001', 'SEO-SCHEMA-002', 'SEO-SCHEMA-003', 'SEO-SCHEMA-004', 'SEO-SCHEMA-005', 'SEO-SCHEMA-006', 'SEO-SCHEMA-007', 'SEO-SCHEMA-008', 'SEO-SCHEMA-009' );
		if ( ! $p->is_html ) {
			return array_map( static fn ( string $r ): R => R::notTested( $r, $u, 'The response is not HTML.' ), $rules );
		}
		if ( array() === $p->json_ld ) {
			$out = array( R::fail( 'SEO-SCHEMA-008', $u, 'No JSON-LD found (microdata/RDFa were not checked).', self::SOURCE ) );
			foreach ( array_slice( $rules, 0, 7 ) as $r ) {
				$out[] = R::notTested( $r, $u, 'No JSON-LD on the page.' );
			}
			$out[] = R::notTested( 'SEO-SCHEMA-009', $u, 'No JSON-LD on the page.' );
			return $out;
		}
		$parsed = JsonLdParser::parse( $p->json_ld );
		$out    = array( R::pass( 'SEO-SCHEMA-008', $u, $parsed['blocks'] . ' JSON-LD block(s), ' . count( $parsed['entities'] ) . ' entity/entities.', self::SOURCE ) );
		$out[]  = array() !== $parsed['invalid']
			? R::fail( 'SEO-SCHEMA-001', $u, 'Invalid JSON: ' . implode( '; ', array_slice( $parsed['invalid'], 0, 3 ) ), self::SOURCE )
			: R::pass( 'SEO-SCHEMA-001', $u, 'All JSON-LD blocks are valid JSON.', self::SOURCE );

		$untyped = 0;
		$missing = array();
		$unknown = array();
		$badurls = array();
		$by_id   = array();
		$claims  = array();
		$ratings = false;
		foreach ( $parsed['entities'] as $entity ) {
			$types = JsonLdParser::types( $entity );
			if ( array() === $types ) {
				++$untyped;
				continue;
			}
			foreach ( $types as $type ) {
				if ( isset( self::REQUIRED[ $type ] ) ) {
					foreach ( self::REQUIRED[ $type ] as $need ) {
						if ( ! self::hasAny( $entity, explode( '|', $need ) ) ) {
							$missing[] = $type . '.' . str_replace( '|', ' or ', $need );
						}
					}
				} elseif ( ! in_array( $type, self::KNOWN, true ) ) {
					$unknown[] = $type;
				}
				if ( in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle', 'Product' ), true ) ) {
					$claim = self::scalar( $entity['headline'] ?? $entity['name'] ?? null );
					if ( '' !== $claim ) {
						$claims[] = $claim;
					}
				}
			}
			if ( isset( $entity['aggregateRating'] ) || isset( $entity['review'] ) || in_array( 'Review', $types, true ) || in_array( 'AggregateRating', $types, true ) ) {
				$ratings = true;
			}
			foreach ( array( 'url', 'sameAs' ) as $key ) {
				foreach ( (array) ( $entity[ $key ] ?? array() ) as $value ) {
					if ( is_string( $value ) && 1 !== preg_match( '#^https?://[^\s/]+#i', $value ) ) {
						$badurls[] = $key . ' "' . mb_substr( $value, 0, 60 ) . '"';
					}
				}
			}
			if ( isset( $entity['@id'] ) && is_string( $entity['@id'] ) ) {
				$sig                             = implode( ',', $types ) . '|' . self::scalar( $entity['name'] ?? '' );
				$by_id[ $entity['@id'] ][ $sig ] = true;
			}
		}

		$out[]     = ( $untyped > 0 || $parsed['no_context'] > 0 )
			? R::fail( 'SEO-SCHEMA-002', $u, sprintf( '%d entity/entities without @type; %d block(s) without a schema.org @context.', $untyped, $parsed['no_context'] ), self::SOURCE )
			: R::pass( 'SEO-SCHEMA-002', $u, 'Every entity has @type and a schema.org @context.', self::SOURCE );
		$out[]     = array() !== $missing
			? R::fail( 'SEO-SCHEMA-003', $u, 'Missing: ' . implode( ', ', array_slice( array_unique( $missing ), 0, 8 ) ), self::SOURCE )
			: R::pass( 'SEO-SCHEMA-003', $u, 'Required properties KHSEO checks are present. Google Rich Results validation: NOT TESTED.', self::SOURCE );
		$conflicts = array_keys( array_filter( $by_id, static fn ( array $sigs ): bool => count( $sigs ) > 1 ) );
		$out[]     = array() !== $conflicts
			? R::fail( 'SEO-SCHEMA-004', $u, 'Conflicting declarations for @id: ' . implode( ', ', array_slice( $conflicts, 0, 3 ) ), self::SOURCE )
			: R::pass( 'SEO-SCHEMA-004', $u, 'No conflicting @id declarations.', self::SOURCE );
		$out[]     = array() !== $unknown
			? R::fail( 'SEO-SCHEMA-005', $u, 'Types not checked by KHSEO: ' . implode( ', ', array_slice( array_unique( $unknown ), 0, 8 ) ), self::SOURCE, Evidence::OBSERVED )
			: R::pass( 'SEO-SCHEMA-005', $u, 'All types are ones KHSEO recognises.', self::SOURCE, Evidence::OBSERVED );

		$haystack       = mb_strtolower( implode( ' ', array_merge( $p->titles, array( $p->text ) ) ) );
		$absent         = array_values( array_filter( $claims, static fn ( string $c ): bool => ! str_contains( $haystack, mb_strtolower( $c ) ) ) );
		$out[]          = array() === $claims
			? R::notTested( 'SEO-SCHEMA-006', $u, 'No Article/Product name or headline to compare.' )
			: ( array() !== $absent
				? R::fail( 'SEO-SCHEMA-006', $u, 'Not found in the page text: "' . mb_substr( $absent[0], 0, 80 ) . '"', self::SOURCE, Evidence::INFERRED )
				: R::pass( 'SEO-SCHEMA-006', $u, 'Names/headlines appear in the page text.', self::SOURCE, Evidence::INFERRED ) );
		$visible_rating = 1 === preg_match( '/\b(review|rating|rated|stars?)\b|★/iu', $p->text );
		$out[]          = ! $ratings
			? R::pass( 'SEO-SCHEMA-007', $u, 'No rating/review markup.', self::SOURCE, Evidence::INFERRED )
			: ( $visible_rating
				? R::pass( 'SEO-SCHEMA-007', $u, 'Rating/review markup and rating/review wording are both present.', self::SOURCE, Evidence::INFERRED )
				: R::fail( 'SEO-SCHEMA-007', $u, 'Rating/review markup, but no review or rating wording in the visible text.', self::SOURCE, Evidence::INFERRED ) );
		$out[]          = array() !== $badurls
			? R::fail( 'SEO-SCHEMA-009', $u, 'Not absolute: ' . implode( '; ', array_slice( $badurls, 0, 3 ) ), self::SOURCE )
			: R::pass( 'SEO-SCHEMA-009', $u, 'url/sameAs values are absolute URLs.', self::SOURCE );
		return $out;
	}

	/**
	 * Whether any of the keys has a non-empty value.
	 *
	 * @param array<string, mixed> $entity Entity.
	 * @param array<int, string>   $keys   Keys.
	 */
	private static function hasAny( array $entity, array $keys ): bool {
		foreach ( $keys as $key ) {
			$v = $entity[ $key ] ?? null;
			if ( null !== $v && '' !== $v && array() !== $v ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A scalar value as trimmed text.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
