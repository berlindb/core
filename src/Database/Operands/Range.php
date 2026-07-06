<?php
/**
 * Range Operand.
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
 * A two-bound RANGE of operands: `a AND b`.
 *
 * The right-hand side of a BETWEEN / NOT BETWEEN comparison, expressed as operands
 * rather than a bare value pair - so its bounds can be columns, functions, or
 * prepared values ( `col BETWEEN low_col AND high_col` ). Holds exactly two
 * operands (the resolver rejects any other count); renders `lower AND upper`.
 *
 * @since 3.1.0
 * @internal Parser / Query collaborator; built from an operand spec.
 */
class Range extends Base {

	/**
	 * A range is a value shape ( BETWEEN bounds ): valid only on the RIGHT, and not
	 * a scalar expression ( so it cannot be wrapped in a CAST ).
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $left = false;

	/**
	 * @since 3.1.0
	 * @var bool
	 */
	protected $scalar = false;

	/**
	 * The two bound operands: [ lower, upper ].
	 *
	 * @since 3.1.0
	 * @var list<Base>
	 */
	private $operands = array();

	/**
	 * Assign the two bound operands.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type list<Base> $operands The two resolved bound operands.
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
	 * Render the range: `lower AND upper`.
	 *
	 * Anything other than exactly two rendering bounds yields '' (the caller fails
	 * the clause closed).
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_sql(): string {

		// A range is exactly two bounds.
		if ( 2 !== count( $this->operands ) ) {
			return '';
		}

		$lower = $this->operands[0]->get_sql();
		$upper = $this->operands[1]->get_sql();

		if ( ( '' === $lower ) || ( '' === $upper ) ) {
			return '';
		}

		return $lower . ' AND ' . $upper;
	}

	/**
	 * A range pairs with the range operators (BETWEEN / NOT BETWEEN).
	 *
	 * @since 3.1.0
	 *
	 * @param \BerlinDB\Database\Operators\Comparisons\Base $operator The operator being paired.
	 * @return bool
	 */
	public function pairs_with( \BerlinDB\Database\Operators\Comparisons\Base $operator ): bool {
		return $operator->is_range();
	}
}
