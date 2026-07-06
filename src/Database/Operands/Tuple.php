<?php
/**
 * Tuple Operand.
 *
 * @package     Database
 * @subpackage  Operands
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operands;

use BerlinDB\Database\Operators\Comparisons;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A row constructor: `( a, b )`.
 *
 * A multi-column VALUE, usable on either side of a row comparison ( `( a, b ) =
 * ( c, d )` ) or as a member of a list ( `( a, b ) IN ( ( 1, 2 ), ( 3, 4 ) )` ).
 * Renders like a Collection ( parenthesised members ) but is a distinct concept:
 * a Collection is the value-SET of an IN, a Tuple is one multi-column value. Its
 * width is its member count, and a Predicate only pairs it against an operand of
 * the same width. Members are scalar operands ( column / function / value ); the
 * resolver rejects a nested tuple/collection and an empty tuple.
 *
 * @since 3.1.0
 * @internal Parser / Query collaborator; built from an operand spec.
 */
class Tuple extends Base {

	/**
	 * A tuple is a value shape ( a row constructor ), not a scalar expression, so it
	 * cannot be wrapped in a CAST. It CAN be a left subject ( the Base default ).
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $scalar = false;

	/**
	 * Default to 0 width so an operand-less tuple reports 0, not the Base default of
	 * 1; init() sets the member count for a populated tuple.
	 *
	 * @since 3.1.0
	 * @var int
	 */
	protected $width = 0;

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

		// A tuple is as wide as its member count.
		$this->width = count( $operands );
	}

	/**
	 * Render the row constructor: `( a, b )`.
	 *
	 * An empty tuple, or any member that renders nothing, yields '' so the caller
	 * fails the clause closed.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_sql(): string {
		if ( empty( $this->operands ) ) {
			return '';
		}

		$rendered = array();

		foreach ( $this->operands as $operand ) {
			$sql = $operand->get_sql();

			if ( '' === $sql ) {
				return '';
			}

			$rendered[] = $sql;
		}

		return '( ' . implode( ', ', $rendered ) . ' )';
	}

	/**
	 * A tuple is a multi-column VALUE, so it pairs with the scalar comparison
	 * operators ( `=`, `!=`, `<`, ... ) - MySQL compares row constructors
	 * lexicographically - exactly like any other single expression operand.
	 *
	 * @since 3.1.0
	 *
	 * @param Comparisons\Base $operator The operator being paired.
	 * @return bool
	 */
	public function pairs_with( Comparisons\Base $operator ): bool {
		return $operator->is_expression();
	}
}
