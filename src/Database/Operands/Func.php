<?php
/**
 * Function Operand.
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
 * An operand that wraps its argument(s) in a SQL function - e.g. LOWER(col).
 *
 * Enables function-wrapped comparisons such as `LOWER(name) = LOWER('term')`.
 * Only functions in the fixed allow-list (self::ALLOWED) are permitted, each
 * with a declared arity and the operand kinds it accepts as arguments; there is
 * NO arbitrary-function or raw-SQL passthrough. The parser validates the name,
 * arity, and argument kinds and resolves each argument (itself an operand)
 * before building this object, so get_sql() is a pure renderer.
 *
 * Arguments are themselves operands (column / value / func), so functions nest.
 *
 * @since 3.1.0
 * @internal Parser collaborator; see Operands\Base.
 */
class Func extends Base {

	/**
	 * Every type category, for functions that coerce any column type (e.g. the
	 * string functions, which apply to a column's string representation).
	 *
	 * @since 3.1.0
	 * @var list<string>
	 */
	private const ANY_TYPE = array( 'numeric', 'string', 'date', 'time', 'year' );

	/**
	 * Allow-list of permitted SQL functions, keyed by their (uppercase) name.
	 *
	 * Each descriptor declares the SQL function name, its argument-count bounds,
	 * the operand kinds allowed as arguments, the placeholder for its result, and
	 * the column-type categories it accepts as a column argument. Kept deliberately
	 * small and stable; grows only as functions earn a place with tests.
	 *
	 * - `return_pattern` - the wpdb::prepare() placeholder for the function's
	 *   RESULT, used to prepare a bare scalar compared against it (`YEAR(col) =
	 *   2024` prepares 2024 as `%d`). ABS keeps `%s` because it preserves its
	 *   input's type (a `%d` would truncate `ABS(x) = 1.5` to `= 1`).
	 * - `accepts` - the type categories ('numeric' / 'string' / 'date') allowed for
	 *   a COLUMN argument; the parser fails a clause closed when a column argument's
	 *   declared type is not in this list (e.g. `YEAR(an_int_column)`). Conservative
	 *   and schema-informed - it rejects obvious misuse, not everything MySQL would
	 *   coerce; literal and nested-function arguments are not type-checked.
	 *
	 * @since 3.1.0
	 * @var array<string,array{sql:string,min_args:int,max_args:int,arg_kinds:list<string>,return_pattern:string,accepts:list<string>}>
	 */
	private const ALLOWED = array(
		'LOWER'      => array(
			'sql'            => 'LOWER',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%s',
			'accepts'        => self::ANY_TYPE,
		),
		'UPPER'      => array(
			'sql'            => 'UPPER',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%s',
			'accepts'        => self::ANY_TYPE,
		),
		'LENGTH'     => array(
			'sql'            => 'LENGTH',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
			'accepts'        => self::ANY_TYPE,
		),
		'ABS'        => array(
			'sql'            => 'ABS',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			/*
			 * ABS keeps a string placeholder (see the const docblock) so it does
			 * not truncate fractional comparisons; it accepts numeric columns only.
			 */
			'return_pattern' => '%s',
			'accepts'        => array( 'numeric' ),
		),
		'DATE'       => array(
			'sql'            => 'DATE',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%s',
			'accepts'        => array( 'date', 'string' ),
		),
		'YEAR'       => array(
			'sql'            => 'YEAR',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
			'accepts'        => array( 'date', 'string' ),
		),
		'MONTH'      => array(
			'sql'            => 'MONTH',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
			'accepts'        => array( 'date', 'string' ),
		),
		'DAYOFMONTH' => array(
			'sql'            => 'DAYOFMONTH',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
			'accepts'        => array( 'date', 'string' ),
		),
		'DAYOFYEAR'  => array(
			'sql'            => 'DAYOFYEAR',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
			'accepts'        => array( 'date', 'string' ),
		),
		'DAYOFWEEK'  => array(
			'sql'            => 'DAYOFWEEK',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
			'accepts'        => array( 'date', 'string' ),
		),
		'HOUR'       => array(
			'sql'            => 'HOUR',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
			'accepts'        => array( 'date', 'time', 'string' ),
		),
		'MINUTE'     => array(
			'sql'            => 'MINUTE',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
			'accepts'        => array( 'date', 'time', 'string' ),
		),
		'SECOND'     => array(
			'sql'            => 'SECOND',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
			'accepts'        => array( 'date', 'time', 'string' ),
		),
	);

	/**
	 * The (validated) SQL function name.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $sql = '';

	/**
	 * The function's argument operands, in order.
	 *
	 * @since 3.1.0
	 * @var list<Base>
	 */
	private $args = array();

	/**
	 * The wpdb::prepare() placeholder for this function's result.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $return_pattern = '%s';

	/**
	 * Whether to prefix the arguments with the DISTINCT quantifier.
	 *
	 * Mainly for aggregate functions - COUNT( DISTINCT col ), SUM( DISTINCT col ) -
	 * where DISTINCT is an argument modifier, not a separate function.
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	private $distinct = false;

	/**
	 * Assign constructor arguments to properties.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type string     $sql            The validated SQL function name, from a descriptor (required).
	 *     @type list<Base> $args           The resolved argument operands. Default empty.
	 *     @type string     $return_pattern The placeholder for the function's result. Default '%s'.
	 *     @type bool       $distinct       Prefix the arguments with DISTINCT. Default false.
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$this->sql = isset( $args[ 'sql' ] ) ? (string) $args[ 'sql' ] : '';

		// Keep only the resolved operand arguments (the parser supplies Base objects).
		$operands = array();

		if ( isset( $args[ 'args' ] ) && is_array( $args[ 'args' ] ) ) {
			foreach ( $args[ 'args' ] as $operand ) {
				if ( $operand instanceof Base ) {
					$operands[] = $operand;
				}
			}
		}

		$this->args           = $operands;
		$this->return_pattern = isset( $args[ 'return_pattern' ] ) ? (string) $args[ 'return_pattern' ] : '%s';
		$this->distinct       = ! empty( $args[ 'distinct' ] );
	}

	/**
	 * Return the allow-list descriptor for a function name, or null if not allowed.
	 *
	 * @since 3.1.0
	 *
	 * @param string $name The function name (case-insensitive).
	 * @return array{sql:string,min_args:int,max_args:int,arg_kinds:list<string>,return_pattern:string,accepts:list<string>}|null
	 */
	public static function descriptor( string $name ): ?array {
		$key = strtoupper( trim( $name ) );

		return self::ALLOWED[ $key ] ?? null;
	}

	/**
	 * Return the prepare() placeholder a scalar should use when compared against
	 * this function's result (e.g. `YEAR(date_col) = 2024` prepares 2024 as %d).
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_comparison_pattern(): string {
		return $this->return_pattern;
	}

	/**
	 * Render the function call: `NAME( arg1, arg2, ... )`, optionally DISTINCT.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_sql(): string {

		// Render each argument operand in order.
		$rendered = array();

		foreach ( $this->args as $arg ) {
			$rendered[] = $arg->get_sql();
		}

		// Aggregate quantifier: COUNT( DISTINCT col ) etc.
		$prefix = ( true === $this->distinct )
			? 'DISTINCT '
			: '';

		return $this->sql . '(' . $prefix . implode( ', ', $rendered ) . ')';
	}
}
