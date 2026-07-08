<?php
/**
 * Base Column Preset.
 *
 * @package     Database
 * @subpackage  Presets
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Presets\Column;

use BerlinDB\Database\Kern\Column;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A Column preset: the re-homed behavior of a "special" column shape.
 *
 * A preset is NOT a Column subclass. It is a small strategy object that a Column
 * delegates to once a special-column DECLARATION is in play (the `uuid`/`created`/
 * `modified`/`version`/`id`/`primary` flags, or a `SERIAL` extra). The preset does
 * not invent its own trigger: it OWNS the trigger via matches(), and PROVIDES the
 * bespoke behavior the trigger implies, at the three seams that used to be if/elseif
 * chains on Column:
 *
 *  - matches()     - is this preset's declaration present in the column args?
 *  - SHAPE         - the column args this preset forces (the old special_args branch),
 *                    merged over the incoming args by set_args().
 *  - default_name  - the fallback column name, applied only when the caller gave none.
 *  - intercept()   - generate/stamp the value on save (mirrors Column::intercept()).
 *
 * More than one preset can apply to a single column (e.g. `uuid` + `primary`). Column
 * collects every preset whose matches() is true, in an explicit precedence order, and
 * applies their SHAPEs in turn, then threads the value through their intercepts.
 *
 * Validation is deliberately NOT a preset concern: Column keeps its own type-based
 * validators (validate_uuid()/validate_datetime()/...), keyed on the mirror flags. A
 * preset shapes and stamps; it does not validate.
 *
 * Presets are resolved through Presets\Column\Registry and are pluggable.
 *
 * @since 3.1.0
 */
abstract class Base {

	/**
	 * The column args this preset forces onto a matching column.
	 *
	 * Declared at the top of each preset so the intended shape is readable at a glance.
	 * set_args() merges it over the incoming args, so a SHAPE key always wins. Presets
	 * with no fixed shape (e.g. date stamps) leave this empty and act only at intercept.
	 *
	 * @since 3.1.0
	 * @var   array<string,mixed>
	 */
	protected const SHAPE = array();

	/**
	 * The preset's stable key (e.g. 'uuid'), used to register and resolve it.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	abstract public function key(): string;

	/**
	 * The boolean declaration flag that triggers this preset (e.g. 'uuid').
	 *
	 * Column reads this to auto-recognize the flag as a config arg and to consume it
	 * after shaping (so no per-preset wiring is needed on Column). Defaults to key().
	 * A preset triggered by something other than a dedicated boolean flag - e.g. Serial,
	 * which keys off the `extra` value - returns '' (it relies on an already-recognized
	 * arg) and overrides matches().
	 *
	 * @since 3.1.0
	 *
	 * @return string The flag arg key, or '' for none.
	 */
	public function flag(): string {
		return $this->key();
	}

	/**
	 * Whether this preset's declaration is present in the given column args.
	 *
	 * The default trigger is a truthy flag named after the key (e.g. `uuid => true`),
	 * matching the historical `! empty( $args[ 'uuid' ] )` checks. Presets keyed on a
	 * different signal (e.g. a SERIAL extra) override this.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Column args, or the column's own vars.
	 * @return bool
	 */
	public function matches( array $args ): bool {
		return ! empty( $args[ $this->flag() ] );
	}

	/**
	 * The fallback column name, used only when the caller supplied none.
	 *
	 * @since 3.1.0
	 *
	 * @return string Empty string for no default.
	 */
	public function default_name(): string {
		return '';
	}

	/**
	 * Merge this preset's SHAPE over the incoming args (SHAPE keys win).
	 *
	 * The $column is passed for presets whose shape is conditional on the column (e.g.
	 * SERIAL, which only promotes integer types). Shape-only presets ignore it.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args   Incoming column args.
	 * @param Column              $column The column being shaped (not yet configured).
	 * @return array<string,mixed> The shaped args.
	 */
	public function set_args( array $args, Column $column ): array {
		return array_merge( $args, static::SHAPE );
	}

	/**
	 * Intercept the column value for a save operation.
	 *
	 * Mirrors Column::intercept()'s contract exactly: return the original value for a
	 * no-op, a replacement to store, or the column's unset sentinel to remove the
	 * field. NEVER return null to mean "no-op" - null is a real incoming value.
	 *
	 * $provided reports whether the caller actually supplied this column (key
	 * presence), distinguishing an omission from an explicit value - including an
	 * explicit null. When false, $value is the column default (insert) or null
	 * (update); when true, $value is exactly what the caller passed.
	 *
	 * @since 3.1.0
	 *
	 * @param string $method   One of insert|update|select|delete|copy.
	 * @param mixed  $value    Incoming value.
	 * @param Column $column   The column being intercepted.
	 * @param bool   $provided Whether the caller supplied this column. Default true.
	 * @return mixed
	 */
	public function intercept( string $method, $value, Column $column, bool $provided = true ) {
		return $value;
	}
}
