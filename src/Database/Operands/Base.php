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
 * the opt-in alternative for a non-scalar right-hand side - a column reference,
 * a function-wrapped expression, or a subquery.
 *
 * Operands are built by the parser layer, which resolves and validates them
 * against the schema and fails closed on anything unresolvable. By the time an
 * operand exists, it is already safe, so get_sql() is a pure renderer that needs
 * no further validation.
 *
 * @since 3.1.0
 * @internal Parser collaborator. Operands are constructed from operand specs by
 *           the parser and consumed by it; the public surface is the operand spec
 *           array (`array( 'operand' => 'column', ... )`), not these classes. The
 *           methods are public only because the parser (a separate class) calls
 *           them - PHP has no friend/package visibility.
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

	/**
	 * Return the wpdb::prepare() placeholder a scalar should use when compared
	 * against this operand on the OTHER side of a comparison.
	 *
	 * Lets a bare-scalar value derive its placeholder from the expression it is
	 * compared with (e.g. an integer column or an integer-returning function takes
	 * '%d'). The base default is a string placeholder; subclasses that know their
	 * type override it.
	 *
	 * @since 3.1.0
	 *
	 * @return string A wpdb::prepare() placeholder ('%s', '%d', or '%f').
	 */
	public function get_comparison_pattern(): string {
		return '%s';
	}
}
