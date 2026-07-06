<?php
/**
 * Query Operands Trait.
 *
 * @package     Database
 * @subpackage  Trait
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Database\Traits\Query;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Operands\Base;
use BerlinDB\Database\Operands\Func;
use BerlinDB\Database\Operands\Interval;
use BerlinDB\Database\Operands\Math;
use BerlinDB\Database\Operands\Value;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Turns an operand spec into an Operand value object, against this query.
 *
 * An operand is any expression that can stand on either side of a comparison: a
 * column reference, a function over one, or a prepared literal. A caller passes a
 * structured "operand spec" and gets back an Operand that renders itself to SQL.
 * Because a query already holds everything resolving one needs - its schema (the
 * columns), its table alias, its connection (to prepare a literal) - operand
 * resolution lives here rather than in a separate collaborator that would have to
 * be handed those three things on every call. A parser resolves against its
 * caller ( `$this->caller->resolve_operand( $spec )` ); a relationship resolves a
 * remote column against the remote query, passing the joined alias.
 *
 * This is the single place that reads the operand-spec DSL, so the accepted array
 * shapes live here, discoverably:
 *
 *   - Column:   array( 'operand' => 'column', 'name' => 'last_name', 'cast' => true )
 *   - Value:    array( 'operand' => 'value',  'value' => 5, 'pattern' => '%d' )
 *   - Function: array( 'operand' => 'func',   'name' => 'LOWER', 'args'  => array( ... ) )
 *   - List:     array( 'operand' => 'list',   'items' => array( ... ) )  // IN / NOT IN, members are operands
 *   - Range:    array( 'operand' => 'range',  'items' => array( $a, $b ) )  // BETWEEN, exactly two bounds
 *   - Tuple:    array( 'operand' => 'tuple',  'items' => array( $a, $b ) )  // ( a, b ) row constructor
 *   - Math:     array( 'operand' => 'math',   'operator' => '+', 'operands' => array( ... ) )  // ( a + b ), infix arithmetic
 *   - Interval: array( 'operand' => 'interval', 'value' => 30, 'unit' => 'DAY' )  // INTERVAL 30 DAY, only inside DATE_SUB/DATE_ADD
 *
 * Any SCALAR operand ( column / value / function / cast ) may also carry a `cast`
 * key ( a validated CAST target string ) that wraps it in CAST( ... AS <type> ) -
 * e.g. `array( 'operand' => 'func', 'name' => 'LOWER', 'args' => array( ... ), 'cast' => 'CHAR' )`.
 * Columns apply the cast inline ( it also feeds their type-category check ); every
 * other scalar operand is wrapped in an Operands\Cast decorator. A `cast` on a
 * non-scalar shape ( list / range / tuple ), or a requested-but-invalid cast target,
 * fails closed.
 *
 * It only builds operands; pairing an operand with an operator into a predicate is
 * the parser's job.
 *
 * @since 3.1.0
 *
 * @phpstan-type OperandSpec array{operand: string, name?: string, value?: scalar, pattern?: string, args?: array<mixed>, items?: array<mixed>, cast?: bool|string}
 */
trait Operands {

	/**
	 * Resolve an operand spec into a renderable Operand, or fail closed.
	 *
	 * Dispatches by the spec's 'operand' kind. Column references resolve against
	 * this query's own schema and are qualified with $alias - the query's own table
	 * alias by default, or a caller-supplied one (a relationship passes the joined
	 * table's alias). Returns null when $value is not an operand spec (the caller
	 * uses the ordinary value path), or false when the spec is unresolvable.
	 *
	 * @since 3.1.0
	 * @internal Parser/collaborator API; reached via caller().
	 *
	 * @param mixed       $value The clause value (an operand spec, or not).
	 * @param string|null $alias Table alias to qualify a column reference. Null uses
	 *                           this query's own table alias. Default null.
	 * @return Base|false|null An Operand, false (fail closed), or null (not a spec).
	 */
	public function resolve_operand( $value, ?string $alias = null ) {

		// Not a structured operand spec: caller uses the ordinary value path.
		if ( ! Base::is_spec( $value ) ) {
			return null;
		}

		// Default to this query's own table alias.
		if ( null === $alias ) {
			$alias = $this->get_table_alias();
		}

		// Normalize the operand kind.
		$kind = is_string( $value[ 'operand' ] )
			? strtolower( $value[ 'operand' ] )
			: '';

		// Dispatch by kind; an unknown kind fails closed.
		switch ( $kind ) {

			/*
			 * Columns apply their own cast inline (it also drives the category check),
			 * so they resolve directly - never through the decorator wrap below.
			 */
			case 'column':
				return $this->resolve_column_operand( $value, $alias );

			case 'value':
				$operand = $this->resolve_value_operand( $value );
				break;

			case 'func':
				$operand = $this->resolve_func_operand( $value, $alias );
				break;

			case 'list':
				$operand = $this->resolve_list_operand( $value, $alias );
				break;

			case 'range':
				$operand = $this->resolve_range_operand( $value, $alias );
				break;

			case 'tuple':
				$operand = $this->resolve_tuple_operand( $value, $alias );
				break;

			case 'math':
				$operand = $this->resolve_math_operand( $value, $alias );
				break;

			case 'interval':
				$operand = $this->resolve_interval_operand( $value );
				break;

			default:
				return false;
		}

		// A resolved non-column operand may carry an opt-in cast that wraps it.
		return $this->maybe_cast_operand( $operand, $value );
	}

	/**
	 * Wrap a resolved operand in a CAST when its spec carries a `cast` key, or fail
	 * closed. Absent / false / null / '' mean "no cast" and return the operand as-is.
	 *
	 * A requested cast must be an explicit, valid target from the safe subset AND the
	 * operand must be a scalar expression ( casting a list / range / tuple is not
	 * valid SQL ); either failing returns false so the clause fails closed rather than
	 * silently dropping the cast. `true` ( "derive from the column type" ) has no
	 * meaning for a non-column operand and so also fails closed here.
	 *
	 * @since 3.1.0
	 *
	 * @param Base|false          $operand The resolved operand (or false to pass through).
	 * @param array<string,mixed> $value   The operand spec carrying the optional `cast`.
	 * @return Base|false
	 */
	private function maybe_cast_operand( $operand, array $value ) {

		// Pass a failed resolution straight through.
		if ( ! ( $operand instanceof Base ) ) {
			return $operand;
		}

		// No cast requested: absent / false / null / '' all mean "no cast".
		$requested = $value[ 'cast' ] ?? null;

		if ( ( null === $requested ) || ( false === $requested ) || ( '' === $requested ) ) {
			return $operand;
		}

		// A cast IS requested: it must validate, and the operand must be scalar.
		$cast = is_string( $requested )
			? $this->sanitize_sql_cast_type( $requested )
			: '';

		if ( ( '' === $cast ) || ! $operand->is_scalar() ) {
			return false;
		}

		return new \BerlinDB\Database\Operands\Cast(
			array(
				'operand'  => $operand,
				'cast'     => $cast,
				'pattern'  => $this->sql_cast_type_pattern( $cast ),
				'category' => $this->sql_cast_type_category( $cast ),
			)
		);
	}

	/**
	 * Resolve an opt-in SQL CAST for a clause column, or false when invalid.
	 *
	 * Absent / false / null mean "no cast" (''); `true` derives the target from the
	 * column's own declared type; a non-empty string is an explicit, validated
	 * override. An explicit-but-invalid cast returns false so the caller fails the
	 * clause closed rather than silently comparing lexically.
	 *
	 * @since 3.1.0
	 * @internal Parser/collaborator API; reached via caller().
	 *
	 * @param Column              $column The column being cast.
	 * @param array<string,mixed> $clause The clause / spec carrying the 'cast' directive.
	 * @return string|false The SQL cast type, '' for no cast, or false when invalid.
	 */
	public function resolve_sql_cast( Column $column, array $clause ) {

		// Read the opt-in directive; absent, false, null, and '' all mean "no cast".
		$requested = $clause[ 'cast' ] ?? null;

		if ( ( null === $requested ) || ( false === $requested ) || ( '' === $requested ) ) {
			return '';
		}

		// 'cast' => true derives the target from the column's own declared type.
		if ( true === $requested ) {
			return $column->get_sql_cast_type();
		}

		// A non-empty string is an explicit, validated override.
		if ( is_string( $requested ) && ( '' !== trim( $requested ) ) ) {
			$cast = $this->sanitize_sql_cast_type( $requested );

			return ( '' === $cast )
				? false
				: $cast;
		}

		/*
		 * Anything else present is a malformed directive ( an array / object, a
		 * number, a whitespace-only string ) - fail closed rather than silently
		 * ignore it, matching how maybe_cast_operand() treats a non-string cast.
		 */
		return false;
	}

	/**
	 * Resolve a `column` operand spec into a Column operand, or false.
	 *
	 * The referenced column is sanitized, looked up in this query's schema, and
	 * given an optional opt-in cast (against the REFERENCED column's type). An
	 * unknown column or an invalid explicit cast fails closed.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $value The operand spec.
	 * @param string              $alias Table alias to qualify the column.
	 * @return Base|false
	 */
	private function resolve_column_operand( array $value, string $alias ) {

		// Sanitize the referenced column name (a failed sanitize becomes '').
		$raw_name = $value[ 'name' ] ?? '';
		$name     = is_string( $raw_name )
			? (string) $this->sanitize_column_name( $raw_name )
			: '';

		// Bail if the name doesn't sanitize to a valid column name.
		if ( '' === $name ) {
			return false;
		}

		// The referenced column must exist in this query's schema.
		$column = $this->get_column_by( array( 'name' => $name ) );

		if ( ! ( $column instanceof Column ) ) {
			return false;
		}

		// Resolve an optional, opt-in cast against the REFERENCED column's type.
		$cast = $this->resolve_sql_cast( $column, $value );

		if ( false === $cast ) {
			return false;
		}

		// Return a Column operand with the cast and alias applied.
		return new \BerlinDB\Database\Operands\Column(
			array(
				'column' => $column,
				'alias'  => $alias,
				'cast'   => $cast,
			)
		);
	}

	/**
	 * Resolve a `value` operand spec into a prepared Value operand, or false.
	 *
	 * The scalar is prepared (via this query's connection) here, so the operand
	 * holds an already-safe fragment. An optional `pattern` ('%s'/'%d'/'%f') selects
	 * the placeholder; anything else defaults to a string placeholder.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $value The operand spec.
	 * @return Base|false
	 */
	private function resolve_value_operand( array $value ) {

		// A value operand must carry a scalar value.
		if ( ! array_key_exists( 'value', $value ) || ! is_scalar( $value[ 'value' ] ) ) {
			return false;
		}

		// Optional, validated prepare() pattern; default to a string placeholder.
		$pattern = ( isset( $value[ 'pattern' ] ) && in_array( $value[ 'pattern' ], array( '%s', '%d', '%f' ), true ) )
			? $value[ 'pattern' ]
			: '%s';

		// Prepare the literal; an empty result fails closed.
		$prepared = (string) $this->db()->prepare( $pattern, $value[ 'value' ] );

		if ( '' === $prepared ) {
			return false;
		}

		return new Value( array( 'sql' => $prepared ) );
	}

	/**
	 * Resolve a `func` operand spec into a Func operand, or false (fail closed).
	 *
	 * The function name must be allow-listed, the argument count within its declared
	 * arity (a variadic function has a lower bound only), and every argument must
	 * resolve as an operand of an allowed kind. A column or cast argument's declared
	 * type category must also be one the function accepts. Arguments recurse through
	 * resolve_operand() ( so functions nest, and a cast argument like
	 * `CAST(x AS CHAR)` is validated by its target category ). A descriptor whose `return_pattern` is
	 * null derives its pattern from the resolved arguments (see derive_return_pattern).
	 *
	 * The operand-func path never emits DISTINCT ( it is not read from the spec here ),
	 * so an invalid `COALESCE( DISTINCT ... )` is unreachable and needs no guard.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $value The operand spec.
	 * @param string              $alias Table alias to qualify column arguments.
	 * @return Base|false
	 */
	private function resolve_func_operand( array $value, string $alias ) {

		// The function must be in the allow-list.
		$name       = is_string( $value[ 'name' ] ?? null ) ? $value[ 'name' ] : '';
		$descriptor = Func::descriptor( $name );

		if ( null === $descriptor ) {
			return false;
		}

		/*
		 * Arguments must be an array within the declared arity ( keys are ignored -
		 * argument POSITION is by insertion order, so a keyed args array still works ).
		 */
		$args_spec = $value[ 'args' ] ?? array();

		if ( ! is_array( $args_spec ) ) {
			return false;
		}

		// A variadic function bounds the count below only; a fixed one, both ends.
		$count    = count( $args_spec );
		$variadic = ! empty( $descriptor[ 'variadic' ] );
		$max_args = $descriptor[ 'max_args' ] ?? 0;

		if ( ( $count < $descriptor[ 'min_args' ] ) || ( ! $variadic && ( $count > $max_args ) ) ) {
			return false;
		}

		/*
		 * Resolve each argument operand, enforcing the function's allowed kinds.
		 * $raw_args is kept index-aligned with $resolved (deriving a pattern from a
		 * literal reads its raw spec, so the two must line up even if $args_spec is keyed).
		 */
		$resolved = array();
		$raw_args = array();
		$position = 0;

		foreach ( $args_spec as $arg_spec ) {
			$kinds = $this->arg_kinds_for_position( $descriptor[ 'arg_kinds' ], $position );
			$arg   = $this->resolve_operand_argument( $arg_spec, $kinds, $alias );

			++$position;

			if ( ! ( $arg instanceof Base ) ) {
				return false;
			}

			/*
			 * A column or cast argument's declared category must be one the function
			 * accepts ( a cast to CHAR is 'string', to SIGNED is 'numeric', etc. );
			 * literal and nested-function arguments stay unchecked.
			 */
			if ( ( ( $arg instanceof \BerlinDB\Database\Operands\Column ) || ( $arg instanceof \BerlinDB\Database\Operands\Cast ) ) && ! in_array( $arg->get_type_category(), $descriptor[ 'accepts' ], true ) ) {
				return false;
			}

			$resolved[] = $arg;
			$raw_args[] = $arg_spec;
		}

		// A null descriptor pattern is DERIVED from the arguments (e.g. COALESCE).
		$return_pattern = ( null === $descriptor[ 'return_pattern' ] )
			? $this->derive_return_pattern( $resolved, $raw_args )
			: $descriptor[ 'return_pattern' ];

		return new Func(
			array(
				'sql'            => $descriptor[ 'sql' ],
				'args'           => $resolved,
				'return_pattern' => $return_pattern,
			)
		);
	}

	/**
	 * Derive a function's result placeholder from the common type of its arguments.
	 *
	 * For a function with no type of its own ( COALESCE returns whichever argument
	 * is non-NULL ), the placeholder a bare scalar compared against it should use is
	 * the common type of the arguments. Each argument classifies to a pattern -
	 * a column or nested function by its own resolved pattern, a literal by its PHP
	 * type - and the set unifies: all-identical keeps the type, mixed integer and
	 * float promotes to float, anything else falls back conservatively to a string
	 * placeholder ( which never truncates ). This stays local to derivation and does
	 * NOT change how a Value operand reports its own comparison pattern.
	 *
	 * @since 3.1.0
	 *
	 * @param list<Base> $resolved  The resolved argument operands, in order.
	 * @param list<mixed> $args_spec The raw argument specs, index-aligned with $resolved.
	 * @return string A wpdb::prepare() placeholder ('%s', '%d', or '%f').
	 */
	private function derive_return_pattern( array $resolved, array $args_spec ): string {
		$patterns = array();

		foreach ( $resolved as $index => $operand ) {
			$patterns[] = $this->classify_operand_pattern( $operand, $args_spec[ $index ] ?? null );
		}

		$unique = array_values( array_unique( $patterns ) );

		// A single common type carries through unchanged.
		if ( 1 === count( $unique ) ) {
			return $unique[ 0 ];
		}

		// Integer and float together promote to float; anything else is lexical.
		sort( $unique );

		return ( array( '%d', '%f' ) === $unique )
			? '%f'
			: '%s';
	}

	/**
	 * Classify one function argument to the placeholder its result compares as.
	 *
	 * A column or nested function knows its own comparison pattern; a bare literal
	 * does not survive preparation as a typed Value, so it classifies from the raw
	 * spec instead - an explicit `pattern` if given, otherwise the literal's PHP
	 * type ( int/bool -> %d, float -> %f, else %s ).
	 *
	 * @since 3.1.0
	 *
	 * @param Base  $operand  The resolved argument operand.
	 * @param mixed $arg_spec The raw argument spec (scalar or operand spec).
	 * @return string A wpdb::prepare() placeholder ('%s', '%d', or '%f').
	 */
	private function classify_operand_pattern( Base $operand, $arg_spec ): string {

		/*
		 * A column, nested function, or cast reports its own resolved pattern ( a
		 * cast's pattern comes from its target type, not the raw literal it wraps ).
		 */
		if ( ( $operand instanceof \BerlinDB\Database\Operands\Column ) || ( $operand instanceof Func ) || ( $operand instanceof \BerlinDB\Database\Operands\Cast ) ) {
			return $operand->get_comparison_pattern();
		}

		// An explicit, validated pattern on a value spec wins.
		if ( Base::is_spec( $arg_spec ) && isset( $arg_spec[ 'pattern' ] ) && in_array( $arg_spec[ 'pattern' ], array( '%s', '%d', '%f' ), true ) ) {
			return $arg_spec[ 'pattern' ];
		}

		// Otherwise classify the bare literal by its PHP type.
		$literal = Base::is_spec( $arg_spec )
			? ( $arg_spec[ 'value' ] ?? null )
			: $arg_spec;

		if ( is_int( $literal ) || is_bool( $literal ) ) {
			return '%d';
		}

		if ( is_float( $literal ) ) {
			return '%f';
		}

		return '%s';
	}

	/**
	 * Resolve a single function argument into an operand, or false (fail closed).
	 *
	 * A bare scalar is value-operand sugar (allowed only when the function accepts a
	 * `value` argument). A structured spec must name one of the function's allowed
	 * argument kinds, then resolves through resolve_operand().
	 *
	 * @since 3.1.0
	 *
	 * @param mixed        $arg_spec  The argument spec (scalar or operand spec).
	 * @param list<string> $arg_kinds The operand kinds this function accepts.
	 * @param string       $alias     Table alias to qualify column arguments.
	 * @return Base|false
	 */
	private function resolve_operand_argument( $arg_spec, array $arg_kinds, string $alias ) {

		// A bare scalar is value-operand sugar, when the function accepts a value.
		if ( ! Base::is_spec( $arg_spec ) ) {

			if ( ! in_array( 'value', $arg_kinds, true ) || ! is_scalar( $arg_spec ) ) {
				return false;
			}

			return $this->resolve_value_operand( array( 'value' => $arg_spec ) );
		}

		// A structured argument must be one of the function's allowed kinds.
		$kind = is_string( $arg_spec[ 'operand' ] )
			? strtolower( $arg_spec[ 'operand' ] )
			: '';

		if ( ! in_array( $kind, $arg_kinds, true ) ) {
			return false;
		}

		$operand = $this->resolve_operand( $arg_spec, $alias );

		return ( $operand instanceof Base )
			? $operand
			: false;
	}

	/**
	 * Resolve a `list` operand spec into a Collection operand, or false.
	 *
	 * The `items` resolve as member operands (columns / functions / values, plus
	 * bare-scalar sugar). An empty list, an unresolvable member, or a nested
	 * collection/range member fails closed - `IN ()` is invalid SQL.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $value The operand spec.
	 * @param string              $alias Table alias to qualify column members.
	 * @return Base|false
	 */
	private function resolve_list_operand( array $value, string $alias ) {

		// List members may be scalars ( `IN ( 1, 2 )` ) or tuples ( `IN ( ( 1, 2 ), ... )` ).
		$operands = $this->resolve_operand_members( $value[ 'items' ] ?? null, $alias, array( 'column', 'func', 'value', 'tuple' ) );

		if ( empty( $operands ) ) {
			return false;
		}

		$collection = new \BerlinDB\Database\Operands\Collection( array( 'operands' => $operands ) );

		// A ragged collection ( members of differing widths ) fails closed.
		return ( $collection->get_width() > 0 )
			? $collection
			: false;
	}

	/**
	 * Resolve a `range` operand spec into a Range operand, or false.
	 *
	 * A range is exactly two bound operands (columns / functions / values). Any
	 * other count, or an unresolvable bound, fails closed.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $value The operand spec.
	 * @param string              $alias Table alias to qualify column bounds.
	 * @return Base|false
	 */
	private function resolve_range_operand( array $value, string $alias ) {

		// Range bounds are scalar operands ( a range of tuples is not a thing ).
		$operands = $this->resolve_operand_members( $value[ 'items' ] ?? null, $alias, array( 'column', 'func', 'value' ) );

		return ( 2 === count( $operands ) )
			? new \BerlinDB\Database\Operands\Range( array( 'operands' => $operands ) )
			: false;
	}

	/**
	 * Resolve a `tuple` operand spec into a Tuple ( row constructor ), or false.
	 *
	 * A tuple's `items` are scalar operands ( columns / functions / values ) - a
	 * nested tuple/list/range member is not an allowed kind, so one level only. An
	 * empty tuple, or any unresolvable member, fails closed.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $value The operand spec.
	 * @param string              $alias Table alias to qualify column members.
	 * @return Base|false
	 */
	private function resolve_tuple_operand( array $value, string $alias ) {
		$operands = $this->resolve_operand_members( $value[ 'items' ] ?? null, $alias, array( 'column', 'func', 'value' ) );

		return ! empty( $operands )
			? new \BerlinDB\Database\Operands\Tuple( array( 'operands' => $operands ) )
			: false;
	}

	/**
	 * Resolve a `math` operand spec into a Math ( arithmetic ) operand, or false.
	 *
	 * The `operator` must be an allow-listed arithmetic operator ( + - * / ) and the
	 * `operands` must resolve to two or more scalar members ( columns / functions /
	 * values / nested math ). An unknown operator, fewer than two members, or any
	 * unresolvable member fails closed. The comparison pattern is numeric: division
	 * yields a float ( %f ), every other operator an integer ( %d ).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $value The operand spec.
	 * @param string              $alias Table alias to qualify column members.
	 * @return Base|false
	 */
	private function resolve_math_operand( array $value, string $alias ) {

		// Resolve the arithmetic operator by symbol; an unknown symbol fails closed.
		$symbol   = is_string( $value[ 'operator' ] ?? null ) ? $value[ 'operator' ] : '';
		$operator = ( new \BerlinDB\Database\Operators\Arithmetic\Registry() )->get_operator( $symbol );

		if ( null === $operator ) {
			return false;
		}

		/*
		 * Members must be a list of scalar operands; a raw-spec list is kept aligned
		 * with the resolved operands so a float literal member promotes the pattern.
		 */
		$members_spec = $value[ 'operands' ] ?? array();

		if ( ! is_array( $members_spec ) ) {
			return false;
		}

		$operands = array();
		$raw      = array();

		foreach ( $members_spec as $spec ) {
			$member = $this->resolve_operand_argument( $spec, array( 'column', 'func', 'value', 'math' ), $alias );

			// A single unresolvable member fails the whole expression closed.
			if ( ! ( $member instanceof Base ) ) {
				return false;
			}

			$operands[] = $member;
			$raw[]      = $spec;
		}

		// Arithmetic needs at least two operands.
		if ( count( $operands ) < 2 ) {
			return false;
		}

		/*
		 * The result is numeric. The operator owns its intrinsic pattern ( division is
		 * a float ); a float MEMBER promotes any integer operator to '%f' too, so a
		 * fractional comparison is never truncated.
		 */
		$pattern = $operator->get_pattern();

		if ( '%f' !== $pattern ) {
			foreach ( $operands as $index => $member ) {
				if ( '%f' === $this->classify_operand_pattern( $member, $raw[ $index ] ?? null ) ) {
					$pattern = '%f';
					break;
				}
			}
		}

		return new Math(
			array(
				'operator' => $operator,
				'operands' => $operands,
				'pattern'  => $pattern,
				'category' => 'numeric',
			)
		);
	}

	/**
	 * Resolve an `interval` operand spec into an Interval operand, or false.
	 *
	 * `value` is the amount ( cast to an integer, so the fragment is injection-safe )
	 * and `unit` must be one of the allow-listed INTERVAL units. An interval is only
	 * ever accepted as a date function's argument ( see DATE_SUB / DATE_ADD ); it can
	 * neither be compared nor stand alone, so a non-integer amount or an unknown unit
	 * fails closed.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $value The operand spec.
	 * @return Base|false
	 */
	private function resolve_interval_operand( array $value ) {

		// The amount must be an integer ( or an integer-like string ).
		$amount = $value[ 'value' ] ?? null;

		if ( ! is_int( $amount ) && ! ( is_string( $amount ) && ( '' !== $amount ) && ( (string) (int) $amount === $amount ) ) ) {
			return false;
		}

		// The unit must be allow-listed.
		$unit = is_string( $value[ 'unit' ] ?? null ) ? strtoupper( trim( $value[ 'unit' ] ) ) : '';

		if ( ! Interval::is_allowed_unit( $unit ) ) {
			return false;
		}

		// The amount is int-cast ( safe ) and the unit is allow-listed ( safe ).
		$sql = 'INTERVAL ' . (int) $amount . ' ' . $unit;

		return new Interval( array( 'sql' => $sql ) );
	}

	/**
	 * Return the operand kinds allowed at a given function-argument position.
	 *
	 * A flat `arg_kinds` list ( list<string> ) applies UNIFORMLY to every argument
	 * ( the common case ); a list of lists is POSITIONAL - element i is the allow-list
	 * for argument i ( so DATE_SUB's second argument can be constrained to `interval` ).
	 * A positional descriptor with no entry for a position allows nothing ( fail closed ).
	 *
	 * @since 3.1.0
	 *
	 * @param array<int,mixed> $arg_kinds The descriptor's arg_kinds ( flat or positional ).
	 * @param int              $position  The zero-based argument position.
	 * @return list<string> The operand kinds allowed at that position.
	 */
	private function arg_kinds_for_position( array $arg_kinds, int $position ): array {

		// Positional: a list of lists. Uniform: a flat list of strings.
		if ( isset( $arg_kinds[ 0 ] ) && is_array( $arg_kinds[ 0 ] ) ) {
			$kinds = $arg_kinds[ $position ] ?? array();

			return is_array( $kinds )
				? array_values( array_filter( $kinds, 'is_string' ) )
				: array();
		}

		return array_values( array_filter( $arg_kinds, 'is_string' ) );
	}

	/**
	 * Resolve the member operands of a list, range, or tuple, or an empty array on
	 * failure.
	 *
	 * $kinds is the allow-list of member operand kinds - scalar ( column / func /
	 * value ) everywhere, plus `tuple` for a list ( so `IN` takes tuples ), never for
	 * a range or a tuple ( so nesting is one level only ). Bare-scalar sugar is
	 * allowed when `value` is in $kinds. Returns an empty array when $items is not a
	 * non-empty list or ANY member is unresolvable, so the caller fails closed rather
	 * than dropping a member ( which would change meaning ).
	 *
	 * @since 3.1.0
	 *
	 * @param mixed        $items The member specs.
	 * @param string       $alias Table alias to qualify column members.
	 * @param list<string> $kinds The allowed member operand kinds.
	 * @return list<Base> The resolved members, or empty on any failure.
	 */
	private function resolve_operand_members( $items, string $alias, array $kinds ): array {
		if ( ! is_array( $items ) || empty( $items ) ) {
			return array();
		}

		$operands = array();

		foreach ( $items as $item ) {
			$operand = $this->resolve_operand_argument( $item, $kinds, $alias );

			// A single unresolvable member invalidates the whole set (never drop one).
			if ( ! ( $operand instanceof Base ) ) {
				return array();
			}

			$operands[] = $operand;
		}

		return $operands;
	}
}
