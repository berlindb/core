<?php
/**
 * Logical Operator Base Class.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operators\Logical;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base class for a LOGICAL operator ( AND, OR, XOR, NOT ).
 *
 * The third operator kind, distinct from Operators\Comparisons\Base ( which builds a
 * boolean PREDICATE, `{a} = {b}` ) and Operators\Arithmetic\Base ( which builds a value
 * EXPRESSION, `{a} + {b}` ). A logical operator COMBINES boolean fragments: AND/OR/XOR
 * are n-ary infix RELATIONS ( pick one to join a set of conditions ), while NOT is a
 * unary NEGATION that wraps a single fragment.
 *
 * Like the other operator families, these are thin symbol carriers - the renderer
 * ( Clauses\BooleanGroup ) owns joining, parentheses, empties, and negation. An operator
 * reports only its keyword and whether it is unary; it does not render.
 *
 * The n-ary relations are resolved by Operators\Logical\Registry ( the canonical
 * allow-list ). NOT is orthogonal to the relation - a group has a relation AND may be
 * negated - so Negation is a family member but is NOT in the relation registry.
 *
 * @since 3.1.0
 * @internal Clause collaborator; relations resolved by Operators\Logical\Registry.
 */
abstract class Base {

	/**
	 * The SQL keyword ( 'AND', 'OR', 'XOR', 'NOT' ).
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $symbol = '';

	/**
	 * Whether the operator is unary ( negates one fragment ) rather than an n-ary
	 * relation ( joins many ). Only Negation is unary.
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $unary = false;

	/**
	 * Return the SQL keyword.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_symbol(): string {
		return $this->symbol;
	}

	/**
	 * Return whether the operator is unary ( negation ) rather than an n-ary relation.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_unary(): bool {
		return $this->unary;
	}
}
