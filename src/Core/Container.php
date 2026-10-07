<?php
/**
 * Minimal dependency-injection container.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Core;

use RuntimeException;

/**
 * Lazily-built shared services keyed by id (usually a class name).
 */
final class Container {

	/**
	 * Factories by id.
	 *
	 * @var array<string, callable(Container): mixed>
	 */
	private array $factories = array();

	/**
	 * Built instances by id.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Register a shared service factory.
	 *
	 * @param string                    $id      Service id.
	 * @param callable(Container):mixed $factory Builds the service once.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Get (and build once) a service.
	 *
	 * @template T
	 * @param class-string<T>|string $id Service id.
	 * @return ($id is class-string<T> ? T : mixed)
	 * @throws RuntimeException When the service is not registered.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new RuntimeException( 'Unknown service: ' . $id );
		}
		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		return $this->instances[ $id ];
	}
}
