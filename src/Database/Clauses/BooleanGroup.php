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
 * Negation is supported by the renderer so the object model is complete (AND/OR
 * are n-ary relations, NOT is unary negation); exposing it through a public query
 * var is a separate, later decision.
 *
 * @since 3.1.0
 * @internal Query/Parser collaborator API; built by the parser layer.
 */
class BooleanGroup {

	/**
	 * The boolean relation joining the items: 'AND' or 'OR'.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $relation = 'AND';

	/**
	 * The items to combine - each a raw SQL string or a nested BooleanGroup.
	 *
	 * @since 3.1.0
	 * @var array<int,string|BooleanGroup>
	 */
	private $items = array();

	/**
	 * Whether the group is negated (wrapped in NOT).
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	private $negated = false;

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
	 *     @type string                         $relation The relation: 'AND' or 'OR' (anything else is AND). Default 'AND'.
	 *     @type array<int,string|BooleanGroup> $items    The SQL fragments / nested groups to combine. Default empty.
	 *     @type bool                           $negated  Whether to wrap the group in NOT. Default false.
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$relation       = ( isset( $args[ 'relation' ] ) && is_string( $args[ 'relation' ] ) ) ? strtoupper( $args[ 'relation' ] ) : 'AND';
		$this->relation = ( 'OR' === $relation ) ? 'OR' : 'AND';
		$this->negated  = ! empty( $args[ 'negated' ] );

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

		// Join the parts with the relation.
		$inner = implode( " {$this->relation} ", $parts );

		// A negated group always wraps in NOT( ... ).
		if ( $this->negated ) {
			return "NOT ( {$inner} )";
		}

		// A single condition needs no grouping; many are parenthesised.
		return ( 1 === count( $parts ) )
			? $inner
			: "( {$inner} )";
	}
}
