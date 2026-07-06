<?php
/**
 * Boolean Group Clause.
 *
 * @package     Database
 * @subpackage  Clauses
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Clauses;

use BerlinDB\Database\Operators\Logical\Base as LogicalOperator;
use BerlinDB\Database\Operators\Logical\Conjunction;
use BerlinDB\Database\Operators\Logical\Negation;
use BerlinDB\Database\Operators\Logical\Registry;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A boolean combination of SQL fragments - `( a AND b AND ( c OR d ) )`.
 *
 * The single place that joins SQL conditions with a boolean relation, replacing
 * hand-rolled `implode( ' AND ', ... )` / `'( ' . ... . ' )'` scattered across the
 * parsers and Query. Items are raw SQL strings or nested BooleanGroups, so a
 * clause tree of any depth renders by composing groups.
 *
 * - Empty (no non-empty items) renders ''.
 * - A single item renders bare (no wrapping parens), unless negated.
 * - Multiple items render `( item1 {relation} item2 ... )`.
 * - A negated group wraps in `NOT ( ... )`.
 *
 * Negation completes the object model (AND/OR are n-ary relations, NOT is unary
 * negation). It is surfaced through the 'criteria' query var by a group's
 * 'not' => true flag (see Clauses\Where).
 *
 * @since 3.1.0
 * @internal Query/Parser collaborator API; built by the parser layer.
 */
class BooleanGroup {

	/**
	 * The logical relation joining the items ( a Conjunction / Disjunction /
	 * ExclusiveDisjunction ). Resolved from the 'relation' arg through the canonical
	 * Operators\Logical\Registry, defaulting to Conjunction ( AND ). Null only for an
	 * empty-args group ( which renders '' before the relation is ever used ).
	 *
	 * @since 3.1.0
	 * @var LogicalOperator|null
	 */
	private $relation = null;

	/**
	 * The items to combine - each a raw SQL string or a nested BooleanGroup.
	 *
	 * @since 3.1.0
	 * @var array<int,string|BooleanGroup>
	 */
	private $items = array();

	/**
	 * The unary negation wrapping the group, or null when not negated.
	 *
	 * @since 3.1.0
	 * @var Negation|null
	 */
	private $negation = null;

	/**
	 * Build a boolean group from a key-value argument array.
	 *
	 * Mirrors the Operands construction shape (constructor delegates to init())
	 * so the argument contract is uniform and can outlive the property layout.
	 * Like the operands, this is a dumb renderer and does NOT compose Traits\Base.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args See init().
	 */
	public function __construct( array $args = array() ) {
		if ( ! empty( $args ) ) {
			$this->init( $args );
		}
	}

	/**
	 * Assign constructor arguments to properties.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type string                         $relation The relation: 'AND', 'OR', or 'XOR' (anything else is AND). Default 'AND'.
	 *     @type array<int,string|BooleanGroup> $items    The SQL fragments / nested groups to combine. Default empty.
	 *     @type bool                           $negated  Whether to wrap the group in NOT. Default false.
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$this->relation = self::resolve_relation( $args[ 'relation' ] ?? null );
		$this->negation = ! empty( $args[ 'negated' ] )
			? new Negation()
			: null;

		// Keep only string fragments and nested groups.
		$items = array();

		if ( isset( $args[ 'items' ] ) && is_array( $args[ 'items' ] ) ) {
			foreach ( $args[ 'items' ] as $item ) {
				if ( is_string( $item ) || ( $item instanceof self ) ) {
					$items[] = $item;
				}
			}
		}

		$this->items = $items;
	}

	/**
	 * Resolve a relation keyword to a logical relation operator.
	 *
	 * Routes the keyword through the canonical Operators\Logical\Registry ( AND / OR /
	 * XOR ) instead of hand-coercing strings; an unknown or non-string keyword falls
	 * back to Conjunction ( AND ).
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $relation The relation keyword.
	 * @return LogicalOperator
	 */
	private static function resolve_relation( $relation ): LogicalOperator {
		if ( is_string( $relation ) ) {
			$operator = ( new Registry() )->get_operator( strtoupper( $relation ) );

			if ( $operator instanceof LogicalOperator ) {
				return $operator;
			}
		}

		return new Conjunction();
	}

	/**
	 * Render the group to SQL.
	 *
	 * @since 3.1.0
	 *
	 * @return string The combined SQL, or '' when the group has no non-empty items.
	 */
	public function get_sql(): string {

		// Render each item, dropping empties (an item that produced no SQL).
		$parts = array();

		foreach ( $this->items as $item ) {
			$sql = ( $item instanceof self )
				? $item->get_sql()
				: (string) $item;

			if ( '' !== trim( $sql ) ) {
				$parts[] = $sql;
			}
		}

		// No conditions -> no SQL.
		if ( empty( $parts ) ) {
			return '';
		}

		// Join the parts with the relation ( empty-args groups never reach here ).
		$relation = ( $this->relation instanceof LogicalOperator )
			? $this->relation
			: new Conjunction();
		$inner    = implode( " {$relation->get_symbol()} ", $parts );

		// A negated group always wraps in NOT ( ... ).
		if ( $this->negation instanceof Negation ) {
			return "{$this->negation->get_symbol()} ( {$inner} )";
		}

		// A single condition needs no grouping; many are parenthesised.
		return ( 1 === count( $parts ) )
			? $inner
			: "( {$inner} )";
	}
}
