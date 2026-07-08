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
 * The built-ins (id/primary/serial/uuid/created/modified/version/wp_meta_key/
 * wp_meta_value/datetime) are memoized lazily;
 * a registered preset of the same key overrides a built-in. Keys are the extensibility surface,
 * including namespaced keys like 'laravel.timestamps' for a future convention family
 * (no PHP-namespace grouping). Built-ins follow BerlinDB/WordPress conventions.
 *
 * @since 3.1.0
 */
final class Registry {

	/**
	 * The built-in preset classes (keyed by key() below).
	 *
	 * A declarative list: adding a built-in is one line here. Column owns the order
	 * presets actually apply in (Column::PRESET_PRECEDENCE); this is just the catalog.
	 *
	 * @since 3.1.0
	 * @var   array<int,class-string<Base>>
	 */
	private const BUILTINS = array(
		Id::class,
		Primary::class,
		Serial::class,
		Uuid::class,
		Created::class,
		Modified::class,
		Version::class,
		WpMetaKey::class,
		WpMetaValue::class,
		DateTime::class,
	);

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
	 * All presets, keyed by name, in a stable order (this IS the apply precedence).
	 *
	 * Built-ins first, in BUILTINS order; a registered preset of the same key overrides
	 * its built-in IN PLACE (keeping its precedence slot), and a registered new key is
	 * appended last. Column iterates this to resolve and apply presets, so the order is
	 * deterministic rather than registration-order-dependent.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,Base>
	 */
	public static function all(): array {
		$all = self::builtins();

		foreach ( self::$registered as $key => $preset ) {
			$all[ $key ] = $preset;
		}

		return $all;
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

			foreach ( self::BUILTINS as $class ) {
				$preset = new $class();

				$builtins[ $preset->key() ] = $preset;
			}
		}

		return $builtins;
	}
}
