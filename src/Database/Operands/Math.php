<?php
/**
 * Math (arithmetic) Operand.
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
 * An operand that combines scalar operands with an infix arithmetic operator - e.g.
 * `( price * quantity )`, `( a + b - c )`, `( WEEKDAY( col ) + 1 )`.
 *
 * Enables arithmetic comparisons the plain operator/value model cannot: `( a + b ) > c`.
 * Only the allow-listed operators ( + - * / ) are permitted; there is NO arbitrary
 * operator or raw-SQL passthrough. The parser validates the operator, resolves each
 * member operand, and precomputes the comparison pattern before building this object,
 * so get_sql() is a pure renderer. The whole expression is wrapped in parentheses so it
 * composes safely with any surrounding SQL ( no operator-precedence surprises ).
 *
 * Members are themselves operands ( column / value / function / nested math ), so
 * arithmetic nests. Its result is numeric, so it reports the 'numeric' type category.
 *
 * @since 3.1.0
 * @internal Parser collaborator; see Operands\Base.
 */
class Math extends Base {

	/**
	 * The allow-list of infix arithmetic operators.
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private const ALLOWED_OPERATORS = array( '+', '-', '*', '/' );

	/**
	 * The validated arithmetic operator.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $operator = '+';

	/**
	 * The member operands, in order.
	 *
	 * @since 3.1.0
	 * @var list<Base>
	 */
	private $operands = array();

	/**
	 * The result's type category ( always 'numeric' for arithmetic ).
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $category = 'numeric';

	/**
	 * Whether an operator is one of the allow-listed arithmetic operators.
	 *
	 * @since 3.1.0
	 *
	 * @param string $operator The operator to check.
	 * @return bool
	 */
	public static function is_allowed_operator( string $operator ): bool {
		return in_array( $operator, self::ALLOWED_OPERATORS, true );
	}

	/**
	 * Assign constructor arguments to properties.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type string     $operator The validated arithmetic operator (required).
	 *     @type list<Base> $operands The resolved member operands (two or more).
	 *     @type string     $pattern  The compared-scalar placeholder. Default '%d'.
	 *     @type string     $category The result type category. Default 'numeric'.
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$this->operator = ( isset( $args[ 'operator' ] ) && self::is_allowed_operator( (string) $args[ 'operator' ] ) )
			? (string) $args[ 'operator' ]
			: '+';

		$operands = array();

		if ( isset( $args[ 'operands' ] ) && is_array( $args[ 'operands' ] ) ) {
			foreach ( $args[ 'operands' ] as $operand ) {
				if ( $operand instanceof Base ) {
					$operands[] = $operand;
				}
			}
		}

		$this->operands = $operands;
		$this->pattern  = isset( $args[ 'pattern' ] ) ? (string) $args[ 'pattern' ] : '%d';
		$this->category = isset( $args[ 'category' ] ) ? (string) $args[ 'category' ] : 'numeric';
	}

	/**
	 * Render the arithmetic expression: `( a {op} b {op} ... )`.
	 *
	 * @since 3.1.0
	 *
	 * @return string The SQL, or '' when there are fewer than two members or any member
	 *                renders nothing ( the caller then fails the clause closed ).
	 */
	public function get_sql(): string {

		// Arithmetic needs at least two operands.
		if ( count( $this->operands ) < 2 ) {
			return '';
		}

		$rendered = array();

		foreach ( $this->operands as $operand ) {
			$sql = $operand->get_sql();

			// A member that renders nothing invalidates the whole expression.
			if ( '' === $sql ) {
				return '';
			}

			$rendered[] = $sql;
		}

		return '( ' . implode( " {$this->operator} ", $rendered ) . ' )';
	}

	/**
	 * Return the result's type category ( 'numeric' ), so a math expression used as a
	 * function argument is validated against the function's accepted categories.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_type_category(): string {
		return $this->category;
	}
}
