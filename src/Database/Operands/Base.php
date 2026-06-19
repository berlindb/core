<?php
/**
 * Operand Base Class.
 *
 * @package     Database
 * @subpackage  Operands
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operands;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base class for the right-hand-side operand of a comparison.
 *
 * An operator emits `{column} {compare} {operand}`. The default operand is a
 * prepared scalar value, rendered by the operator itself; an Operand object is
 * the opt-in alternative for a non-scalar right-hand side — a column reference,
 * a function-wrapped expression, or a subquery.
 *
 * Operands are built by the parser layer, which resolves and validates them
 * against the schema and fails closed on anything unresolvable. By the time an
 * operand exists, it is already safe, so get_sql() is a pure renderer that needs
 * no further validation.
 *
 * @since 3.1.0
 */
abstract class Base {

	/**
	 * Render this operand as the SQL fragment for the right-hand side.
	 *
	 * @since 3.1.0
	 *
	 * @return string The SQL fragment, or '' when the operand renders nothing.
	 */
	abstract public function get_sql(): string;
}
