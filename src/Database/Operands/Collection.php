<?php
/**
 * Collection Operand.
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
 * A parenthesised LIST of operands: `( a, b, c )`.
 *
 * The right-hand side of an IN / NOT IN comparison, expressed as operands rather
 * than a bare value list - so its members can be columns, functions, or prepared
 * values ( `col IN ( other_col, LOWER( name ), 5 )` ), which the operator's own
 * value path cannot express. Composes exactly like Func: it holds a list of
 * operands and renders each. Members are scalar operands (the resolver rejects a
 * nested collection/range and an empty list, so `IN ()` never renders).
 *
 * @since 3.1.0
 * @internal Parser / Query collaborator; built from an operand spec.
 */
class Collection extends Base {

	/**
	 * The member operands, in order.
	 *
	 * @since 3.1.0
	 * @var list<Base>
	 */
	private $operands = array();

	/**
	 * Assign the member operands.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type list<Base> $operands The resolved member operands.
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$operands = array();

		if ( isset( $args[ 'operands' ] ) && is_array( $args[ 'operands' ] ) ) {
			foreach ( $args[ 'operands' ] as $operand ) {
				if ( $operand instanceof Base ) {
					$operands[] = $operand;
				}
			}
		}

		$this->operands = $operands;
	}

	/**
	 * Render the list: `( a, b, c )`.
	 *
	 * An empty list, or any member that renders nothing, yields '' - an empty
	 * `IN ()` is invalid SQL, so the caller fails the clause closed.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_sql(): string {

		// An empty collection renders nothing (IN () is invalid SQL).
		if ( empty( $this->operands ) ) {
			return '';
		}

		$rendered = array();

		foreach ( $this->operands as $operand ) {
			$sql = $operand->get_sql();

			// A member that renders nothing invalidates the whole list.
			if ( '' === $sql ) {
				return '';
			}

			$rendered[] = $sql;
		}

		return '( ' . implode( ', ', $rendered ) . ' )';
	}

	/**
	 * A collection pairs with the list operators (IN / NOT IN).
	 *
	 * @since 3.1.0
	 *
	 * @param \BerlinDB\Database\Operators\Base $operator The operator being paired.
	 * @return bool
	 */
	public function pairs_with( \BerlinDB\Database\Operators\Base $operator ): bool {
		return $operator->is_list();
	}

	/**
	 * A collection is as wide as each of its members - 1 for a scalar list
	 * ( `IN ( 1, 2, 3 )` ), N for a list of N-wide tuples ( `IN ( ( 1, 2 ), ( 3, 4 ) )` ).
	 *
	 * Returns 0 when empty or RAGGED ( members of differing widths ), so the
	 * Predicate's width check can never match it and the clause fails closed. The
	 * resolver also rejects a ragged collection up front.
	 *
	 * @since 3.1.0
	 *
	 * @return int
	 */
	public function get_width(): int {
		if ( empty( $this->operands ) ) {
			return 0;
		}

		$width = $this->operands[0]->get_width();

		foreach ( $this->operands as $operand ) {
			if ( $operand->get_width() !== $width ) {
				return 0;
			}
		}

		return $width;
	}

	/**
	 * A collection is an IN value-set - valid only on the RIGHT, never as a left
	 * subject.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function can_be_left(): bool {
		return false;
	}
}
