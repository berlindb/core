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
			case 'column':
				return $this->resolve_column_operand( $value, $alias );

			case 'value':
				return $this->resolve_value_operand( $value );

			case 'func':
				return $this->resolve_func_operand( $value, $alias );

			case 'list':
				return $this->resolve_list_operand( $value, $alias );

			case 'range':
				return $this->resolve_range_operand( $value, $alias );

			default:
				return false;
		}
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

		// Read the opt-in directive; absent, false, and null all mean "no cast".
		$requested = $clause[ 'cast' ] ?? null;

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

		// No cast requested.
		return '';
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
	 * arity, and every argument must resolve as an operand of an allowed kind. A
	 * column argument's declared type category must also be one the function accepts.
	 * Arguments recurse through resolve_operand(), so functions nest.
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

		// Arguments must be a list within the declared arity.
		$args_spec = $value[ 'args' ] ?? array();

		if ( ! is_array( $args_spec ) ) {
			return false;
		}

		$count = count( $args_spec );

		if ( ( $count < $descriptor[ 'min_args' ] ) || ( $count > $descriptor[ 'max_args' ] ) ) {
			return false;
		}

		// Resolve each argument operand, enforcing the function's allowed kinds.
		$resolved = array();

		foreach ( $args_spec as $arg_spec ) {
			$arg = $this->resolve_operand_argument( $arg_spec, $descriptor[ 'arg_kinds' ], $alias );

			if ( ! ( $arg instanceof Base ) ) {
				return false;
			}

			// A column argument's declared category must be one the function accepts.
			if ( ( $arg instanceof \BerlinDB\Database\Operands\Column ) && ! in_array( $arg->get_type_category(), $descriptor[ 'accepts' ], true ) ) {
				return false;
			}

			$resolved[] = $arg;
		}

		return new Func(
			array(
				'sql'            => $descriptor[ 'sql' ],
				'args'           => $resolved,
				'return_pattern' => $descriptor[ 'return_pattern' ],
			)
		);
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
		$operands = $this->resolve_operand_members( $value[ 'items' ] ?? null, $alias );

		return ! empty( $operands )
			? new \BerlinDB\Database\Operands\Collection( array( 'operands' => $operands ) )
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
		$operands = $this->resolve_operand_members( $value[ 'items' ] ?? null, $alias );

		return ( 2 === count( $operands ) )
			? new \BerlinDB\Database\Operands\Range( array( 'operands' => $operands ) )
			: false;
	}

	/**
	 * Resolve the member operands of a list or range, or an empty array on failure.
	 *
	 * Members are scalar operands (column / func / value), plus bare-scalar sugar; a
	 * nested list/range member is not an allowed kind, so it fails. Returns an empty
	 * array when $items is not a non-empty list or ANY member is unresolvable, so the
	 * caller fails closed rather than dropping a member (which would change meaning).
	 *
	 * @since 3.1.0
	 *
	 * @param mixed  $items The list/range member specs.
	 * @param string $alias Table alias to qualify column members.
	 * @return list<Base> The resolved members, or empty on any failure.
	 */
	private function resolve_operand_members( $items, string $alias ): array {
		if ( ! is_array( $items ) || empty( $items ) ) {
			return array();
		}

		$operands = array();

		foreach ( $items as $item ) {
			$operand = $this->resolve_operand_argument( $item, array( 'column', 'func', 'value' ), $alias );

			// A single unresolvable member invalidates the whole set (never drop one).
			if ( ! ( $operand instanceof Base ) ) {
				return array();
			}

			$operands[] = $operand;
		}

		return $operands;
	}
}
