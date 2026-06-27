<?php
/**
 * Column Preset Registry.
 *
 * @package     Database
 * @subpackage  Presets
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Presets\Column;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Resolves column-preset keys to preset instances.
 *
 * The built-ins (uuid/created/modified/version/id) are memoized lazily; a registered
 * preset of the same key overrides a built-in. Keys are the extensibility surface,
 * including namespaced keys like 'laravel.timestamps' for a future convention family
 * (no PHP-namespace grouping). Built-ins follow BerlinDB/WordPress conventions.
 *
 * @since 3.1.0
 */
final class Registry {

	/**
	 * Registered (custom / override) presets, keyed by Base::key().
	 *
	 * @since 3.1.0
	 * @var   array<string,Base>
	 */
	private static $registered = array();

	/**
	 * Register a preset (overrides a built-in or registered preset of the same key).
	 *
	 * @since 3.1.0
	 *
	 * @param Base $preset The preset to register.
	 * @return void
	 */
	public static function register( Base $preset ): void {
		self::$registered[ $preset->key() ] = $preset;
	}

	/**
	 * Clear all registered (custom / override) presets; built-ins are untouched.
	 *
	 * The registry is static, so a registration persists across requests under a
	 * long-lived worker-mode runtime, and across test methods. Call this in a test
	 * teardown - or at a request boundary - to drop custom presets.
	 *
	 * @since 3.1.0
	 * @internal Test / lifecycle helper.
	 * @return void
	 */
	public static function reset(): void {
		self::$registered = array();
	}

	/**
	 * Resolve a preset by key (registered first, then built-in), or null when unknown.
	 *
	 * @since 3.1.0
	 *
	 * @param string $key The preset key (e.g. 'uuid').
	 * @return Base|null
	 */
	public static function get( string $key ): ?Base {
		return self::$registered[ $key ] ?? ( self::builtins()[ $key ] ?? null );
	}

	/**
	 * All presets, keyed by name (registered overriding built-ins).
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,Base>
	 */
	public static function all(): array {
		return self::$registered + self::builtins();
	}

	/**
	 * The memoized built-in presets, keyed by name.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,Base>
	 */
	private static function builtins(): array {
		static $builtins = null;

		if ( null === $builtins ) {
			$builtins = array();

			foreach ( array( new Uuid(), new Created(), new Modified(), new Version(), new Id() ) as $preset ) {
				$builtins[ $preset->key() ] = $preset;
			}
		}

		return $builtins;
	}
}
