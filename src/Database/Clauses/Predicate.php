<?php
/**
 * Predicate Clause.
 *
 * @package     Database
 * @subpackage  Clauses
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Clauses;

use BerlinDB\Database\Operands\Base as Operand;
use BerlinDB\Database\Operators\Base as Operator;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A single comparison: a left operand, an operator, and a right side.
 *
 * The reusable unit every filtered clause is made of - a WHERE condition, a
 * relationship condition, a HAVING condition. It pairs a left Operand with an
 * Operator and renders itself, so callers assemble comparisons without repeating
 * the "left compare right" shape. The right side is one of three things, and the
 * predicate renders each accordingly:
 *
 *   - nothing        - a unary operator ( IS NULL ) takes no right side.
 *   - an Operand     - a resolved expression ( column / function / value ); the two
 *                      operands render identically, so either side can be either.
 *   - a bare value   - a scalar or list the OPERATOR renders in its own syntax
 *                      ( `= 5`, `IN (1,2,3)`, `BETWEEN x AND y`, `LIKE '%x%'` ),
 *                      typed by the left operand's comparison pattern.
 *
 * Operands resolve themselves elsewhere ( a query, from an operand spec ); by the
 * time they reach here they are safe, so this is a pure renderer.
 *
 * @since 3.1.0
 * @internal Parser / Query collaborator; the public surface is the clause DSL.
 */
class Predicate {

	/**
	 * The left-hand operand.
	 *
	 * @since 3.1.0
	 * @var Operand
	 */
	private $left;

	/**
	 * The comparison operator.
	 *
	 * @since 3.1.0
	 * @var Operator
	 */
	private $operator;

	/**
	 * The right-hand side: an Operand, a bare value, or null (unary).
	 *
	 * @since 3.1.0
	 * @var mixed
	 */
	private $right;

	/**
	 * Build a predicate from its left operand, operator, and right side.
	 *
	 * @since 3.1.0
	 *
	 * @param Operand  $left     The left-hand operand.
	 * @param Operator $operator The comparison operator.
	 * @param mixed    $right    The right side: an Operand, a bare value, or null for
	 *                           a unary operator. Default null.
	 */
	public function __construct( Operand $left, Operator $operator, $right = null ) {
		$this->left     = $left;
		$this->operator = $operator;
		$this->right    = $right;
	}

	/**
	 * Render the predicate as SQL.
	 *
	 * @since 3.1.0
	 *
	 * @return string|false The comparison SQL, '' when it renders nothing, or false to
	 *                      fail closed ( a structured operand on a non-expression
	 *                      operator - e.g. IN - which cannot pair two operands ).
	 */
	public function to_sql() {

		// A unary operator (IS NULL) takes no right-hand side.
		if ( $this->operator->is_unary() ) {
			return $this->assemble_unary();
		}

		// A resolved operand pairs with the operator by shape (scalar / list / range).
		if ( $this->right instanceof Operand ) {
			return $this->right->pairs_with( $this->operator )
				? $this->assemble_comparison( $this->right )
				: false;
		}

		// A bare value: the operator renders its own value fragment.
		return $this->assemble_value( $this->right );
	}

	/**
	 * Assemble a unary predicate: `{left} {compare}` ( e.g. `col IS NULL` ).
	 *
	 * @since 3.1.0
	 *
	 * @return string The SQL, or '' when the operand renders nothing.
	 */
	private function assemble_unary(): string {
		$left = $this->left->get_sql();

		return ( '' === $left )
			? ''
			: $left . ' ' . $this->operator->get_sql_compare();
	}

	/**
	 * Assemble a two-operand comparison: `{left} {compare} {right}`.
	 *
	 * @since 3.1.0
	 *
	 * @param Operand $right The right-hand operand.
	 * @return string|false The SQL, or false when either operand renders nothing - a
	 *                      resolved operand that renders empty is broken (e.g. an
	 *                      empty collection), so fail closed rather than widen.
	 */
	private function assemble_comparison( Operand $right ) {
		$left_sql  = $this->left->get_sql();
		$right_sql = $right->get_sql();

		return ( ( '' === $left_sql ) || ( '' === $right_sql ) )
			? false
			: $left_sql . ' ' . $this->operator->get_sql_compare() . ' ' . $right_sql;
	}

	/**
	 * Assemble a comparison against a bare value, rendered by the operator.
	 *
	 * The operator renders its own value fragment ( scalar / IN / BETWEEN / LIKE ),
	 * prepared with the left operand's comparison pattern. A value-less operator
	 * ( e.g. NOT EXISTS ) yields nothing.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value The bare value.
	 * @return string The SQL, or '' when the operator renders nothing.
	 */
	private function assemble_value( $value ): string {

		// Narrow the left operand's pattern to a known placeholder for prepare().
		$pattern = $this->left->get_comparison_pattern();
		$pattern = in_array( $pattern, array( '%s', '%d', '%f' ), true )
			? $pattern
			: '%s';

		// The operator renders its value fragment, typed by the left operand.
		$value_sql = $this->operator->get_value_sql( $value, $pattern );

		if ( '' === $value_sql ) {
			return '';
		}

		return $this->left->get_sql() . ' ' . $this->operator->get_sql_compare() . ' ' . $value_sql;
	}
}
