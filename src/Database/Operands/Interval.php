<?php
/**
 * Interval Operand.
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
 * A MySQL INTERVAL expression - e.g. `INTERVAL 30 DAY`.
 *
 * The temporal amount for date arithmetic: `DATE_SUB( NOW(), INTERVAL 30 DAY )`
 * ( "30 days ago" ). It is NOT a normal value - `INTERVAL n UNIT` is only valid as an
 * argument of a date function ( DATE_SUB / DATE_ADD ), never on its own or as the
 * right-hand side of a comparison. So it is a NON-scalar operand that can neither be a
 * left subject nor pair with any operator; the only place the resolver accepts it is a
 * date function's `interval` argument slot ( a positional arg kind ). The parser
 * validates the amount ( an integer ) and the unit ( an allow-list ) before building
 * this object, so get_sql() is a pure renderer.
 *
 * @since 3.1.0
 * @internal Parser collaborator; see Operands\Base.
 */
class Interval extends Base {

	/**
	 * The allow-list of INTERVAL units.
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private const ALLOWED_UNITS = array( 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' );

	/**
	 * An interval is not a scalar value shape - it is only legal inside a date
	 * function, never as a comparison subject.
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $scalar = false;

	/**
	 * An interval can never be the left subject of a comparison.
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $left = false;

	/**
	 * The pre-rendered `INTERVAL n UNIT` fragment ( amount and unit already validated ).
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $sql = '';

	/**
	 * Whether a unit is one of the allow-listed INTERVAL units.
	 *
	 * @since 3.1.0
	 *
	 * @param string $unit The (uppercased) unit to check.
	 * @return bool
	 */
	public static function is_allowed_unit( string $unit ): bool {
		return in_array( $unit, self::ALLOWED_UNITS, true );
	}

	/**
	 * Assign constructor arguments to properties.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type string $sql The validated `INTERVAL n UNIT` fragment (required).
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$this->sql = isset( $args[ 'sql' ] ) ? (string) $args[ 'sql' ] : '';
	}

	/**
	 * Render the interval fragment: `INTERVAL n UNIT`.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_sql(): string {
		return $this->sql;
	}

	/**
	 * An interval never pairs with a comparison operator - it is only a date-function
	 * argument, so any attempt to use it as a comparison value fails closed.
	 *
	 * @since 3.1.0
	 *
	 * @param Comparisons\Base $operator The operator being paired.
	 * @return bool
	 */
	public function pairs_with( Comparisons\Base $operator ): bool {
		return false;
	}
}
