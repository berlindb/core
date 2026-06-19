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
	 * @since 3.1.0
	 * @var array<string,array{sql:string,min_args:int,max_args:int,arg_kinds:list<string>}>
	 */
	private const ALLOWED = array(
		'LOWER'      => array(
			'sql'       => 'LOWER',
			'min_args'  => 1,
			'max_args'  => 1,
			'arg_kinds' => array( 'column', 'func', 'value' ),
		),
		'UPPER'      => array(
			'sql'       => 'UPPER',
			'min_args'  => 1,
			'max_args'  => 1,
			'arg_kinds' => array( 'column', 'func', 'value' ),
		),
		'LENGTH'     => array(
			'sql'       => 'LENGTH',
			'min_args'  => 1,
			'max_args'  => 1,
			'arg_kinds' => array( 'column', 'func', 'value' ),
		),
		'ABS'        => array(
			'sql'       => 'ABS',
			'min_args'  => 1,
			'max_args'  => 1,
			'arg_kinds' => array( 'column', 'func', 'value' ),
		),
		'DATE'       => array(
			'sql'       => 'DATE',
			'min_args'  => 1,
			'max_args'  => 1,
			'arg_kinds' => array( 'column', 'func', 'value' ),
		),
		'YEAR'       => array(
			'sql'       => 'YEAR',
			'min_args'  => 1,
			'max_args'  => 1,
			'arg_kinds' => array( 'column', 'func', 'value' ),
		),
		'MONTH'      => array(
			'sql'       => 'MONTH',
			'min_args'  => 1,
			'max_args'  => 1,
			'arg_kinds' => array( 'column', 'func', 'value' ),
		),
		'DAYOFMONTH' => array(
			'sql'       => 'DAYOFMONTH',
			'min_args'  => 1,
			'max_args'  => 1,
			'arg_kinds' => array( 'column', 'func', 'value' ),
		),
		'DAYOFYEAR'  => array(
			'sql'       => 'DAYOFYEAR',
			'min_args'  => 1,
			'max_args'  => 1,
			'arg_kinds' => array( 'column', 'func', 'value' ),
		),
		'DAYOFWEEK'  => array(
			'sql'       => 'DAYOFWEEK',
			'min_args'  => 1,
			'max_args'  => 1,
			'arg_kinds' => array( 'column', 'func', 'value' ),
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
	 * Build a function operand.
	 *
	 * @since 3.1.0
	 *
	 * @param string     $sql  The validated SQL function name (from a descriptor).
	 * @param list<Base> $args The resolved argument operands.
	 */
	public function __construct( string $sql, array $args ) {
		$this->sql  = $sql;
		$this->args = $args;
	}

	/**
	 * Return the allow-list descriptor for a function name, or null if not allowed.
	 *
	 * @since 3.1.0
	 *
	 * @param string $name The function name (case-insensitive).
	 * @return array{sql:string,min_args:int,max_args:int,arg_kinds:list<string>}|null
	 */
	public static function descriptor( string $name ): ?array {
		$key = strtoupper( trim( $name ) );

		return self::ALLOWED[ $key ] ?? null;
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
