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
 * `modified`/`version` flags, or a `preset` key). It does not set its own trigger;
 * the declaration does that. The preset only PROVIDES the bespoke behavior the
 * trigger implies, at three seams that used to be if/elseif chains on Column:
 *
 *  - set_args()   - shape the column args (the old Column::special_args() branch).
 *  - default_name - the fallback column name, applied only when none was given.
 *  - intercept()  - generate/stamp the value on save, mirroring Column::intercept().
 *
 * Validation stays a separate, OPTIONAL capability: a preset that needs a specialty
 * validator (e.g. UUID) simply defines a public validate( $value, $column ) method,
 * which Column detects via is_callable() (the way sanitize_validation already detects
 * callbacks). Presets that do not fall through to Column's generic type-based
 * validators (datetime/int/numeric/...).
 *
 * Presets are resolved through Presets\Column\Registry and are pluggable.
 *
 * @since 3.1.0
 */
abstract class Base {

	/**
	 * The preset's stable key (matches its declaration flag, e.g. 'uuid').
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	abstract public function key(): string;

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
	 * Set the preset's column shape onto the incoming args (type/length/etc.).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Incoming column args.
	 * @return array<string,mixed> The shaped args.
	 */
	public function set_args( array $args ): array {
		return $args;
	}

	/**
	 * Intercept the column value for a save operation.
	 *
	 * Mirrors Column::intercept()'s contract exactly: return the original value for a
	 * no-op, a replacement to store, or the column's unset sentinel to remove the
	 * field. NEVER return null to mean "no-op" - null is a real incoming value.
	 *
	 * @since 3.1.0
	 *
	 * @param string $method One of insert|update|select|delete|copy.
	 * @param mixed  $value  Incoming value (null when the column was not supplied).
	 * @param Column $column The column being intercepted.
	 * @return mixed
	 */
	public function intercept( string $method, $value, Column $column ) {
		return $value;
	}
}
