<?php
/**
 * Bounded JSON-LD parser: blocks → flat entity list.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Schema;

/**
 * Decodes each <script type="application/ld+json"> block with depth and count
 * limits and flattens top-level arrays, @graph and nested typed objects.
 * Values are data only; nothing in them is ever executed or followed.
 */
final class JsonLdParser {

	public const MAX_DEPTH    = 32;
	public const MAX_ENTITIES = 300;

	/**
	 * Parse blocks.
	 *
	 * @param array<int, string> $blocks Raw script bodies.
	 * @return array{blocks: int, invalid: array<int, string>, no_context: int, entities: array<int, array<string, mixed>>}
	 */
	public static function parse( array $blocks ): array {
		$invalid    = array();
		$no_context = 0;
		$entities   = array();
		foreach ( $blocks as $i => $raw ) {
			$data = json_decode( trim( $raw ), true, self::MAX_DEPTH );
			if ( ! is_array( $data ) ) {
				$invalid[] = 'block ' . ( $i + 1 ) . ': ' . json_last_error_msg();
				continue;
			}
			$roots = array_is_list( $data ) ? $data : array( $data );
			foreach ( $roots as $root ) {
				if ( ! is_array( $root ) ) {
					continue;
				}
				if ( ! self::hasSchemaContext( $root['@context'] ?? null ) ) {
					++$no_context;
				}
				$items = isset( $root['@graph'] ) && is_array( $root['@graph'] ) ? $root['@graph'] : array( $root );
				foreach ( $items as $item ) {
					if ( is_array( $item ) ) {
						self::collect( $item, $entities, 0 );
					}
				}
			}
		}
		return array(
			'blocks'     => count( $blocks ),
			'invalid'    => $invalid,
			'no_context' => $no_context,
			'entities'   => $entities,
		);
	}

	/**
	 * Whether @context refers to schema.org.
	 *
	 * @param mixed $context @context value.
	 */
	public static function hasSchemaContext( mixed $context ): bool {
		if ( is_string( $context ) ) {
			return 1 === preg_match( '#^https?://schema\.org/?$#i', trim( $context ) );
		}
		if ( is_array( $context ) ) {
			foreach ( $context as $key => $value ) {
				if ( ( '@vocab' === $key || is_int( $key ) ) && self::hasSchemaContext( $value ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Types of an entity as a list of short names ("Article", not "https://schema.org/Article").
	 *
	 * @param array<string, mixed> $entity Entity.
	 * @return array<int, string>
	 */
	public static function types( array $entity ): array {
		$types = $entity['@type'] ?? array();
		$types = is_array( $types ) ? $types : array( $types );
		return array_values(
			array_filter(
				array_map( static fn ( $t ): string => is_string( $t ) ? (string) preg_replace( '#^https?://schema\.org/#i', '', trim( $t ) ) : '', $types )
			)
		);
	}

	/**
	 * Collect an object and its nested typed objects.
	 *
	 * @param array<string, mixed>             $node     Node.
	 * @param array<int, array<string, mixed>> $entities Accumulator.
	 * @param int                              $depth    Depth.
	 */
	private static function collect( array $node, array &$entities, int $depth ): void {
		if ( $depth > 6 || count( $entities ) >= self::MAX_ENTITIES ) {
			return;
		}
		if ( ! array_is_list( $node ) && ( isset( $node['@type'] ) || 0 === $depth ) ) {
			$entities[] = $node;
		}
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) && '@context' !== $key ) {
				foreach ( array_is_list( $value ) ? $value : array( $value ) as $child ) {
					if ( is_array( $child ) && ! array_is_list( $child ) && isset( $child['@type'] ) ) {
						self::collect( $child, $entities, $depth + 1 );
					}
				}
			}
		}
	}
}
