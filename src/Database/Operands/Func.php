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
 * An operand that wraps its argument(s) in a SQL function — e.g. LOWER(col).
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
	 * Allow-list of permitted SQL functions, keyed by their (uppercase) name.
	 *
	 * Each descriptor declares the SQL function name, its argument-count bounds,
	 * and the operand kinds allowed as arguments. Kept deliberately small and
	 * stable; grows only as functions earn a place with tests. (A richer per-
	 * function return/comparison pattern is added with the left-hand-side operand
	 * work, where the function's return type informs the comparison value.)
	 *
	 * `return_pattern` is the wpdb::prepare() placeholder for the function's RESULT
	 * — used to prepare a bare-scalar value compared against the function (e.g.
	 * `YEAR(date_col) = 2024` prepares `2024` as `%d`). (Per-argument type rules,
	 * the semantic-validation layer, are deferred to a later phase — see #211.)
	 *
	 * @since 3.1.0
	 * @var array<string,array{sql:string,min_args:int,max_args:int,arg_kinds:list<string>,return_pattern:string}>
	 */
	private const ALLOWED = array(
		'LOWER'      => array(
			'sql'            => 'LOWER',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%s',
		),
		'UPPER'      => array(
			'sql'            => 'UPPER',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%s',
		),
		'LENGTH'     => array(
			'sql'            => 'LENGTH',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
		),
		'ABS'        => array(
			'sql'            => 'ABS',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			/*
			 * ABS preserves its input's type (a decimal stays fractional), so a
			 * '%d' placeholder would truncate `ABS(x) = 1.5` to `= 1`. Use a string
			 * placeholder and let MySQL coerce, rather than force an integer.
			 */
			'return_pattern' => '%s',
		),
		'DATE'       => array(
			'sql'            => 'DATE',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%s',
		),
		'YEAR'       => array(
			'sql'            => 'YEAR',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
		),
		'MONTH'      => array(
			'sql'            => 'MONTH',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
		),
		'DAYOFMONTH' => array(
			'sql'            => 'DAYOFMONTH',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
		),
		'DAYOFYEAR'  => array(
			'sql'            => 'DAYOFYEAR',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
		),
		'DAYOFWEEK'  => array(
			'sql'            => 'DAYOFWEEK',
			'min_args'       => 1,
			'max_args'       => 1,
			'arg_kinds'      => array( 'column', 'func', 'value' ),
			'return_pattern' => '%d',
		),
	);

	/**
	 * The (validated) SQL function name.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $sql;

	/**
	 * The function's argument operands, in order.
	 *
	 * @since 3.1.0
	 * @var list<Base>
	 */
	private $args;

	/**
	 * The wpdb::prepare() placeholder for this function's result.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $return_pattern;

	/**
	 * Build a function operand.
	 *
	 * @since 3.1.0
	 *
	 * @param string     $sql            The validated SQL function name (from a descriptor).
	 * @param list<Base> $args           The resolved argument operands.
	 * @param string     $return_pattern The placeholder for the function's result. Default '%s'.
	 */
	public function __construct( string $sql, array $args, string $return_pattern = '%s' ) {
		$this->sql            = $sql;
		$this->args           = $args;
		$this->return_pattern = $return_pattern;
	}

	/**
	 * Return the allow-list descriptor for a function name, or null if not allowed.
	 *
	 * @since 3.1.0
	 *
	 * @param string $name The function name (case-insensitive).
	 * @return array{sql:string,min_args:int,max_args:int,arg_kinds:list<string>,return_pattern:string}|null
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
	 * Render the function call: `NAME( arg1, arg2, ... )`.
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

		return $this->sql . '(' . implode( ', ', $rendered ) . ')';
	}
}
