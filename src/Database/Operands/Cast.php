<?php
/**
 * Cast Operand.
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
 * An operand that wraps a scalar expression in a SQL CAST - e.g. CAST(expr AS CHAR).
 *
 * A decorator: it holds another (already-resolved) scalar operand and renders it
 * wrapped in CAST( ... AS <type> ). The parser validates the cast target (against
 * the safe subset), confirms the wrapped operand is a scalar expression, and
 * pre-computes the effective comparison pattern and type category before building
 * this object, so get_sql() is a pure renderer. Because the wrapped operand is any
 * scalar operand (column / value / function / nested cast), casts compose.
 *
 * A cast is spelled as a `cast` KEY on any scalar operand spec (e.g. a func spec
 * with `'cast' => 'SIGNED'`), not as its own operand kind; the parser turns that
 * key into this decorator. See Traits\Query\Operands.
 *
 * @since 3.1.0
 * @internal Parser collaborator; see Operands\Base.
 */
class Cast extends Base {

	/**
	 * The wrapped scalar operand.
	 *
	 * @since 3.1.0
	 * @var Base
	 */
	private $operand;

	/**
	 * The (validated, normalized) CAST target type, e.g. 'SIGNED' or 'CHAR(20)'.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $cast = '';

	/**
	 * The cast target's type category ('numeric' / 'date' / 'time' / 'string').
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $category = '';

	/**
	 * Assign constructor arguments to properties.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type Base   $operand  The wrapped scalar operand (required).
	 *     @type string $cast     The validated, normalized CAST target (required).
	 *     @type string $pattern  The compared-scalar placeholder. Default '%s'.
	 *     @type string $category The cast target's type category. Default ''.
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$operand = $args[ 'operand' ] ?? null;

		if ( $operand instanceof Base ) {
			$this->operand = $operand;
		}

		$this->cast     = isset( $args[ 'cast' ] ) ? (string) $args[ 'cast' ] : '';
		$this->pattern  = isset( $args[ 'pattern' ] ) ? (string) $args[ 'pattern' ] : '%s';
		$this->category = isset( $args[ 'category' ] ) ? (string) $args[ 'category' ] : '';
	}

	/**
	 * Render the wrapped operand inside CAST( ... AS <type> ).
	 *
	 * @since 3.1.0
	 *
	 * @return string The SQL, or '' when the wrapped operand renders nothing.
	 */
	public function get_sql(): string {
		$inner = $this->operand->get_sql();

		return ( '' === $inner )
			? ''
			: "CAST({$inner} AS {$this->cast})";
	}

	/**
	 * Return the cast target's type category, so a cast argument to a function is
	 * validated against the function's accepted categories ( just like a column ).
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_type_category(): string {
		return $this->category;
	}
}
