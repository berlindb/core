<?php
/**
 * Column Preset Intercept Context.
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
 * The context a Column preset's intercept() receives alongside the value.
 *
 * Carries everything about the save operation EXCEPT the value being transformed:
 * the method (insert|update|select|delete|copy), the Column, and whether the
 * caller actually supplied this column (key presence - an omission vs an explicit
 * value, including an explicit null).
 *
 * Deliberately a value object so intercept()'s signature never has to grow: new
 * context is added here as an accessor, without changing any preset's signature.
 * That matters once the preset API ships - PHP requires an override to match the
 * base arity, so a future positional param would fatal every custom preset. One
 * Context param sidesteps that permanently.
 *
 * @since 3.1.0
 */
final class Context {

	/**
	 * The save method (insert|update|select|delete|copy).
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	private string $method;

	/**
	 * The column being intercepted.
	 *
	 * @since 3.1.0
	 * @var   Column
	 */
	private Column $column;

	/**
	 * Whether the caller supplied this column (key presence).
	 *
	 * @since 3.1.0
	 * @var   bool
	 */
	private bool $provided;

	/**
	 * Build the intercept context.
	 *
	 * @since 3.1.0
	 *
	 * @param string $method   One of insert|update|select|delete|copy.
	 * @param Column $column   The column being intercepted.
	 * @param bool   $provided Whether the caller supplied this column. Default true.
	 */
	public function __construct( string $method, Column $column, bool $provided = true ) {
		$this->method   = $method;
		$this->column   = $column;
		$this->provided = $provided;
	}

	/**
	 * The save method being performed.
	 *
	 * @since 3.1.0
	 * @return string One of insert|update|select|delete|copy.
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * The column being intercepted.
	 *
	 * @since 3.1.0
	 * @return Column
	 */
	public function column(): Column {
		return $this->column;
	}

	/**
	 * Whether the caller supplied this column.
	 *
	 * Key presence: true for an explicit value (including an explicit null), false
	 * for an omission - letting a preset act on genuine omission.
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	public function provided(): bool {
		return $this->provided;
	}

	/**
	 * The sentinel a preset returns from intercept() to drop the field.
	 *
	 * Removing the field from the write lets the DB default / generated value apply.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function unset_value(): string {
		return $this->column->get_unset_sentinel();
	}
}
