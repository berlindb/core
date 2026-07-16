<?php
/**
 * Query Aggregates Trait Class.
 *
 * @package     Database
 * @subpackage  Query
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Operands\Column as ColumnOperand;
use BerlinDB\Database\Operands\Func as FuncOperand;

/**
 * Scalar and container aggregates for a Query.
 *
 * Composed onto Query, sharing its $this run-state. Provides the scalar helpers
 * (get_sum / get_avg / get_max / get_min), executes the 'aggregate' query-var
 * container (run_aggregate and its expression rendering, groupby handling, and
 * fan-out-JOIN dedup via a distinct-primary subquery).
 * Aggregate mode is dispatched from the Execution trait; this owns the machinery.
 *
 * @since 3.1.0
 */
trait Aggregates {

	/**
	 * Return the SUM of a numeric column across the matching rows.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $column     Column name to sum.
	 * @param array<string,mixed>  $query_vars Optional query vars to filter the rows.
	 *
	 * @return float|null The sum, or null if the column is unknown/non-numeric or no
	 *                    rows matched.
	 */
	public function get_sum( string $column, array $query_vars = array() ): ?float {
		$value = $this->aggregate_scalar( 'SUM', $column, $query_vars );

		return ( null === $value )
			? null
			: (float) $value;
	}

	/**
	 * Return the AVG of a numeric column across the matching rows.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $column     Column name to average.
	 * @param array<string,mixed>  $query_vars Optional query vars to filter the rows.
	 *
	 * @return float|null The average, or null if the column is unknown/non-numeric or
	 *                    no rows matched.
	 */
	public function get_avg( string $column, array $query_vars = array() ): ?float {
		$value = $this->aggregate_scalar( 'AVG', $column, $query_vars );

		return ( null === $value )
			? null
			: (float) $value;
	}

	/**
	 * Return the MAX of a column across the matching rows.
	 *
	 * Works on any comparable column (numeric, date, string), so the raw scalar is
	 * returned as-is; cast at the call site when a specific type is expected.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $column     Column name.
	 * @param array<string,mixed>  $query_vars Optional query vars to filter the rows.
	 *
	 * @return string|null The maximum value, or null if the column is unknown or no
	 *                     rows matched.
	 */
	public function get_max( string $column, array $query_vars = array() ): ?string {
		return $this->aggregate_scalar( 'MAX', $column, $query_vars );
	}

	/**
	 * Return the MIN of a column across the matching rows.
	 *
	 * Works on any comparable column (numeric, date, string), so the raw scalar is
	 * returned as-is; cast at the call site when a specific type is expected.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $column     Column name.
	 * @param array<string,mixed>  $query_vars Optional query vars to filter the rows.
	 *
	 * @return string|null The minimum value, or null if the column is unknown or no
	 *                     rows matched.
	 */
	public function get_min( string $column, array $query_vars = array() ): ?string {
		return $this->aggregate_scalar( 'MIN', $column, $query_vars );
	}

	/**
	 * Run one scalar aggregate over the matching rows via the 'aggregate' container.
	 *
	 * The scalar methods (get_sum/get_avg/get_max/get_min) are thin wrappers over this:
	 * it fails closed on an unknown column (and, for SUM/AVG, a non-numeric one) BEFORE
	 * querying, then runs a single-entry 'aggregate' container query in isolation (so
	 * the caller's own query and results are untouched) and pulls the value out by
	 * alias. The container handles the FUNC render, filtering, JOIN fan-out dedup, and
	 * caching (see run_aggregate()); an empty set is null.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $function   The SQL aggregate (SUM/AVG/MAX/MIN);
	 *                                         SUM/AVG require a numeric column.
	 * @param string               $column     Column name to aggregate.
	 * @param array<string,mixed>  $query_vars Query vars to filter the rows.
	 *
	 * @return string|null The raw scalar result, or null.
	 */
	private function aggregate_scalar( string $function, string $column, array $query_vars ): ?string {

		// Fail closed on an unknown or wrong-typed column before running a query.
		$column_object = $this->get_column_by( array( 'name' => $column ) );

		if ( ! ( $column_object instanceof Column ) ) {
			return null;
		}

		// SUM and AVG need a numeric column; MAX and MIN work on any comparable one.
		if ( in_array( $function, array( 'SUM', 'AVG' ), true ) && ! $column_object->is_numeric() ) {
			return null;
		}

		/*
		 * A scalar method returns one value, so it is inherently ungrouped: strip any
		 * 'groupby' the caller passed, so the container returns a flat row, not a list.
		 */
		unset( $query_vars[ 'groupby' ] );

		// Run the single aggregate in isolation and pull its value out by alias.
		$alias  = strtolower( $function );
		$result = $this->run_isolated_query(
			array_merge(
				$query_vars,
				array( 'aggregate' => array( $alias => $column ) )
			)
		);

		$value = is_array( $result )
			? ( $result[ $alias ] ?? null )
			: null;

		return ( null === $value )
			? null
			: (string) $value;
	}

	/**
	 * Run a query in isolation, leaving this instance's state untouched.
	 *
	 * query() rebuilds the ephemeral run state (init_current()) and overwrites
	 * query_vars, query_var_defaults, and items. When a method needs a side query on
	 * the SAME instance - e.g. a scalar aggregate method running an 'aggregate'
	 * container - this snapshots that state, runs the query, and restores it in a
	 * finally, so the caller's own query and results survive. get_current_state() /
	 * init_current() snapshot and restore the ephemeral bag; the three properties round
	 * it out.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The side query's vars.
	 * @return array<int|string,mixed>|int The side query's result.
	 */
	private function run_isolated_query( array $query_vars = array() ) {
		$saved_current  = $this->get_current_state();
		$saved_vars     = $this->query_vars;
		$saved_defaults = $this->query_var_defaults;
		$saved_items    = $this->items;

		try {
			return $this->query( $query_vars );

		} finally {
			$this->init_current( $saved_current );
			$this->query_vars         = $saved_vars;
			$this->query_var_defaults = $saved_defaults;
			$this->items              = $saved_items;
		}
	}

	/**
	 * Render a FUNC( column ) aggregate expression through the operand value objects.
	 *
	 * The one render path shared by the scalar aggregate methods and the 'aggregate'
	 * query-var container, so the column is resolved and quoted exactly as elsewhere.
	 * COUNT with a '*' column is the row count, rendered as COUNT(*) with no operand.
	 *
	 * @since 3.1.0
	 *
	 * @param string $function The SQL aggregate (SUM/AVG/MAX/MIN/COUNT).
	 * @param string $column   The column to aggregate, or '*' for a COUNT row count.
	 * @param bool   $distinct Apply the DISTINCT quantifier, e.g. COUNT( DISTINCT col ).
	 *                         Default false. Never set for a COUNT(*) (rejected in normalize).
	 *
	 * @return string The rendered expression, e.g. SUM( `t`.`amount` ); '' if the
	 *                column could not be resolved.
	 */
	private function render_aggregate_expression( string $function, string $column, bool $distinct = false ): string {

		// COUNT( * ) is a row count - no column operand.
		if ( ( 'COUNT' === $function ) && ( '*' === $column ) ) {
			return 'COUNT(*)';
		}

		$column_object = $this->get_column_by( array( 'name' => $column ) );

		// The column was validated in normalize; bail defensively if it vanished.
		if ( ! ( $column_object instanceof Column ) ) {
			return '';
		}

		$operand = new ColumnOperand(
			array(
				'column' => $column_object,
				'alias'  => $this->get_table_alias(),
			)
		);

		return ( new FuncOperand(
			array(
				'sql'      => $function,
				'args'     => array( $operand ),
				'distinct' => $distinct,
			)
		) )->get_sql();
	}

	/**
	 * Synthesize the empty-set result for an ungrouped aggregate container.
	 *
	 * Used when a filter short-circuits to no rows WITHOUT running the query (the
	 * fail-closed flag, not a 1 = 0 WHERE, so running it would match all rows). COUNT
	 * over an empty set is 0; every other aggregate is null.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,array{function: string, column: string}> $aggregate The
	 *        canonical aggregate container.
	 * @return array<string,string|null> The empty-set result, keyed by alias.
	 */
	private function empty_aggregate_result( array $aggregate ): array {
		$result = array();

		foreach ( $aggregate as $alias => $spec ) {
			$result[ (string) $alias ] = ( 'COUNT' === ( $spec[ 'function' ] ?? '' ) )
				? '0'
				: null;
		}

		return $result;
	}

	/**
	 * Run the 'aggregate' container and return its results.
	 *
	 * Called from the query path (get_item_ids()) once the query vars are parsed, so
	 * it reads the compiled WHERE/JOIN directly. Each canonical entry renders
	 * "FUNC( column ) AS alias". A JOIN-producing filter is wrapped in a
	 * distinct-primary subquery (the same dedup the scalar methods use), so a
	 * one-to-many join does not fan out and double-count.
	 *
	 * Without a 'groupby' var the whole set is ONE row: an assoc keyed by every
	 * requested alias, each value null when its aggregate is NULL (an empty set) -
	 * unlike COUNT, an empty SUM/MAX is null, not zero. With 'groupby' the group
	 * column(s) join the SELECT and drive a GROUP BY (grouped AFTER the fan-out dedup),
	 * so it returns a LIST of rows, each carrying the group column(s) plus each alias;
	 * an empty set is an empty list (no groups).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,array{function: string, column: string}> $aggregate The
	 *        canonical aggregate container (from normalize_aggregate_container()).
	 *
	 * @return array<string,string|null>|array<int,array<string,mixed>> One row keyed by
	 *         alias, or a list of grouped rows.
	 */
	private function run_aggregate( array $aggregate ): array {

		/*
		 * A 'groupby' var puts the group column(s) into the SELECT and drives a GROUP BY,
		 * so the query returns one row per group. Resolve it to VALID columns only - an
		 * unknown column is treated as ungrouped, never emitted as malformed SQL. GROUP
		 * BY goes on the outer aggregate, after any fan-out dedup below.
		 */
		$group_names = $this->get_valid_groupby_columns( (array) $this->get_query_var( 'groupby' ) );
		$grouped     = ! empty( $group_names );

		$group_columns = array();
		foreach ( $group_names as $name ) {
			$group_columns[] = $this->get_quoted_column_name_aliased( $name, true );
		}
		$group_select = implode( ', ', $group_columns );

		/*
		 * Render "FUNC( column ) AS `alias`" for every entry, skipping an alias that
		 * collides with a group column - both would land under the same result key.
		 */
		$fields = array();

		foreach ( $aggregate as $alias => $spec ) {
			$alias = (string) $alias;

			if ( in_array( $alias, $group_names, true ) ) {
				$this->log( 'warning', 'aggregate', "Aggregate alias '{$alias}' collides with a groupby column; ignoring it." );
				continue;
			}

			$expression = $this->render_aggregate_expression( $spec[ 'function' ], $spec[ 'column' ], ! empty( $spec[ 'distinct' ] ) );

			// The column was validated in normalize; skip defensively if it could not render.
			if ( '' === $expression ) {
				continue;
			}

			$fields[ $alias ] = "{$expression} AS " . $this->quote_identifier( $alias );
		}

		// Nothing renderable resolves to an empty result set.
		if ( empty( $fields ) ) {
			return array();
		}

		$group_clause = ( true === $grouped )
			? "GROUP BY {$group_select}"
			: '';

		$fields_sql = implode( ', ', $fields );

		if ( true === $grouped ) {
			$fields_sql = "{$group_select}, {$fields_sql}";
		}

		// Compile the JOIN/WHERE from the already-parsed query vars.
		$join_where = $this->parse_join_where( $this->query_vars );
		$where      = $this->parse_where_clause( $join_where[ 'where' ] );
		$join       = $this->parse_join_clause( $join_where[ 'join' ] );

		// Assemble the aggregate clause set and apply the query-clauses filter.
		$clauses = $this->aggregate_clauses( $fields_sql, $join, $where, $group_clause );
		$clauses = $this->filter_query_clauses( $clauses );

		/*
		 * The aggregate owns its grouping through the 'groupby' var, which also shaped
		 * the SELECT's group columns; re-assert it after the filter so a query-clauses
		 * filter cannot desync the two, or inject a GROUP BY over a JOIN-only alias that
		 * exists in the inner subquery but not the outer aggregate.
		 */
		$clauses[ 'groupby' ] = $group_clause;

		// Dedup a fan-out JOIN via a distinct-primary subquery, then group the survivors.
		if ( '' !== trim( (string) ( $clauses[ 'join' ] ?? '' ) ) ) {
			$clauses = $this->aggregate_via_subquery( $fields_sql, $clauses, $group_clause );
		}

		/*
		 * Order the grouped rows by an aggregate alias or a group column. Set on the
		 * OUTER clause set - after any fan-out dedup, so it is never pushed into the
		 * inner subquery - and only when grouped (an ungrouped aggregate is one row).
		 */
		if ( true === $grouped ) {
			$clauses[ 'having' ]  = $this->resolve_aggregate_having( array_intersect_key( $aggregate, $fields ) );
			$clauses[ 'orderby' ] = $this->resolve_aggregate_orderby( $group_names, array_keys( $fields ) );
		}

		$request = $this->parse_request_clauses( $clauses );

		// Grouped: a list of rows (group column(s) + aliases), as the database returns them.
		if ( true === $grouped ) {
			return array_values( (array) $this->db()->get_results( $request, ARRAY_A ) );
		}

		// Ungrouped: one row, keyed by each requested alias, a miss defaulting to null.
		$row    = (array) $this->db()->get_row( $request, ARRAY_A );
		$retval = array();

		foreach ( array_keys( $aggregate ) as $alias ) {
			$value                     = $row[ (string) $alias ] ?? null;
			$retval[ (string) $alias ] = ( null === $value )
				? null
				: (string) $value;
		}

		return $retval;
	}

	/**
	 * Resolve the orderby/order vars into an ORDER BY clause for a grouped aggregate.
	 *
	 * A grouped aggregate may be ordered by an aggregate ALIAS (by its quoted SELECT
	 * alias) or a group COLUMN (by its qualified name) - both are in the SELECT. Reuses
	 * the standard orderby vars: a bare key uses the 'order' direction, the
	 * array( 'revenue' => 'DESC', 'status' => 'ASC' ) form gives one per key. An
	 * unknown key is dropped and logged; direction runs through parse_order (ASC/DESC).
	 * An unset/empty/'none' orderby does not order (it never defaults to the primary
	 * key, which is not in a grouped aggregate's SELECT).
	 *
	 * @since 3.1.0
	 *
	 * @param list<string> $group_names The valid group column names.
	 * @param list<string> $aliases     The surviving aggregate aliases (SELECT aliases).
	 * @return string An "ORDER BY ..." clause, or '' when nothing orders.
	 */
	private function resolve_aggregate_orderby( array $group_names, array $aliases ): string {
		$orderby = $this->get_query_var( 'orderby' );

		// An unset/empty/none orderby does not order a grouped aggregate.
		if ( empty( $orderby ) || ( 'none' === $orderby ) ) {
			return '';
		}

		$order = $this->get_query_var( 'order' );

		// Normalize to key => direction, mirroring parse_orderby's handling.
		$ordersby = (array) $orderby;

		if ( wp_is_numeric_array( $ordersby ) ) {
			$ordersby = array_fill_keys( $ordersby, $order );
		}

		$group_lookup = array_flip( $group_names );
		$alias_lookup = array_flip( $aliases );

		$fragments = array();

		foreach ( $ordersby as $key => $direction ) {
			$key       = (string) $key;
			$direction = $this->parse_order( is_string( $direction ) ? $direction : (string) $order );

			// A group column orders by its qualified name; an aggregate alias by its SELECT alias.
			if ( isset( $group_lookup[ $key ] ) ) {
				$fragments[] = $this->get_quoted_column_name_aliased( $key, true ) . ' ' . $direction;

			} elseif ( isset( $alias_lookup[ $key ] ) ) {
				$fragments[] = $this->quote_identifier( $key ) . ' ' . $direction;

			} else {
				$this->log( 'warning', 'aggregate', "Cannot order aggregate by unknown key '{$key}'; ignoring it." );
			}
		}

		return empty( $fragments )
			? ''
			: 'ORDER BY ' . implode( ', ', $fragments );
	}

	/**
	 * Resolve the 'having' var into a HAVING clause for a grouped aggregate.
	 *
	 * HAVING is a WHERE that runs AFTER grouping, filtering the groups by their
	 * aggregate results. Each entry keys a surviving aggregate ALIAS to a
	 * { compare, value } spec - named ( array( 'compare' => '>', 'value' => 1000 ) )
	 * or positional ( array( '>', 1000 ) ). The alias must be a surviving aggregate
	 * (a group column is filtered in WHERE, not HAVING); the compare must be one of
	 * the scalar comparison operators. Both are reused from the Operators library:
	 * the operator renders its own compare SQL and prepares the value, with a
	 * placeholder derived from the aggregate's function ( see having_value_pattern ).
	 * An unknown alias, unsupported operator, or empty value is dropped and logged;
	 * multiple entries AND together.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,array{function:string,column:string}> $surviving
	 *        The surviving aggregate specs, keyed by their SELECT alias.
	 * @return string A "HAVING ..." clause, or '' when nothing filters.
	 */
	private function resolve_aggregate_having( array $surviving ): string {
		$having = $this->get_query_var( 'having' );

		// Nothing filters without a structured having map.
		if ( empty( $having ) || ! is_array( $having ) ) {
			return '';
		}

		// The operator registry (default set) renders each comparison.
		$operators = new \BerlinDB\Database\Operators\Comparisons\Registry();

		$fragments = array();

		foreach ( $having as $alias => $spec ) {
			$alias = (string) $alias;

			// The alias must be a surviving aggregate (group columns filter in WHERE).
			if ( ! isset( $surviving[ $alias ] ) ) {
				$this->log( 'warning', 'having', "Cannot filter aggregate by unknown alias '{$alias}'; ignoring it." );
				continue;
			}

			// A having entry must be a { compare, value } spec, named or positional.
			if ( ! is_array( $spec ) ) {
				$this->log( 'warning', 'having', "HAVING entry '{$alias}' must be a { compare, value } spec; ignoring it." );
				continue;
			}

			$compare = (string) ( $spec[ 'compare' ] ?? ( $spec[ 0 ] ?? '' ) );
			$value   = $spec[ 'value' ] ?? ( $spec[ 1 ] ?? null );

			/*
			 * Look the operator up in the shared registry. HAVING v1 accepts the
			 * scalar comparison operators only ( =, !=, <, <=, >, >= ) - the ones
			 * that compare an expression to a value, flagged is_expression() - not
			 * the multi-value ( IN / BETWEEN ), pattern ( LIKE ), or unary ( IS NULL )
			 * operators.
			 */
			$operator = $operators->get_operator( $compare );

			if ( ! ( $operator instanceof \BerlinDB\Database\Operators\Comparisons\Base ) || ! $operator->is_expression() ) {
				$this->log( 'warning', 'having', "Unsupported HAVING operator '{$compare}' for '{$alias}'; ignoring it." );
				continue;
			}

			/*
			 * Reuse the operator value object: it renders its own compare SQL and
			 * prepares the value with the placeholder for this aggregate's result.
			 */
			$pattern   = $this->having_value_pattern( $surviving[ $alias ] );
			$value_sql = $operator->get_value_sql( $value, $pattern );

			// A value that prepares to nothing filters nothing.
			if ( '' === $value_sql ) {
				continue;
			}

			$fragments[] = $this->quote_identifier( $alias ) . ' ' . $operator->get_sql_compare() . ' ' . $value_sql;
		}

		// Join the per-alias HAVING conditions with AND through the shared renderer.
		$combined = \BerlinDB\Database\Clauses\BooleanGroup::combine( 'AND', $fragments );

		return ( '' === $combined )
			? ''
			: 'HAVING ' . $combined;
	}

	/**
	 * The wpdb::prepare() placeholder a value uses when compared, in HAVING, against
	 * an aggregate's result.
	 *
	 * COUNT is always an integer; AVG is always fractional; SUM, MIN, and MAX carry
	 * their source column's type, so they borrow the column's own placeholder (an
	 * integer column keeps `%d`, so a large integer bound is not floated through PHP,
	 * a float column gets `%f`), falling back to a string.
	 *
	 * @since 3.1.0
	 *
	 * @param array{function:string,column:string} $spec The aggregate spec.
	 * @return '%d'|'%f'|'%s' A wpdb::prepare() placeholder for the value.
	 */
	private function having_value_pattern( array $spec ): string {
		$function = (string) ( $spec[ 'function' ] ?? '' );

		// COUNT is always an integer; AVG is always fractional.
		if ( 'COUNT' === $function ) {
			return '%d';
		}

		if ( 'AVG' === $function ) {
			return '%f';
		}

		// SUM / MIN / MAX carry the source column's type, so borrow its placeholder.
		$pattern = (string) $this->get_column_field( array( 'name' => (string) ( $spec[ 'column' ] ?? '' ) ), 'pattern', '%s' );

		// Constrain an unexpected column placeholder to a safe string prepare.
		return in_array( $pattern, array( '%d', '%f' ), true )
			? $pattern
			: '%s';
	}

	/**
	 * Wrap a scalar aggregate around a distinct-primary subquery.
	 *
	 * A filter that joins the base table one-to-many (a meta / relationship filter,
	 * or a scoping hook) fans out its rows, so aggregating over the joined result
	 * double-counts. Given the compiled, already site-scoped clause set, this returns
	 * a new clause set whose inner subquery resolves each matching primary key once
	 * (via distinct_id_clauses(), which keeps the filter's JOIN/WHERE scoping),
	 * and whose outer FUNC( column ) runs over just the base rows in that set - each
	 * counted once, with no JOIN to fan out.
	 *
	 * The base table keeps its alias inside the subquery: the compiled JOIN/WHERE
	 * already reference it, and the subquery's own FROM re-scopes it, so the outer
	 * "primary IN ( ... )" stays uncorrelated.
	 *
	 * @since 3.1.0
	 *
	 * @param string                $fields  The rendered FUNC( column ) field list.
	 * @param array<string,string>  $clauses The compiled aggregate clause set; its
	 *                                       JOIN and WHERE carry the filter + scoping.
	 * @param string                $groupby The GROUP BY fragment, applied to the outer
	 *                                       aggregate over the deduped rows. Default ''.
	 *
	 * @return array<string,string> A clause set that aggregates over base rows bounded
	 *                              by a distinct-primary IN subquery, with no JOIN.
	 */
	private function aggregate_via_subquery( string $fields, array $clauses, string $groupby = '' ): array {

		$primary_ref = $this->get_quoted_column_name_aliased();

		/*
		 * The inner subquery reshapes the already-filtered clause set to select each
		 * matching primary key once, keeping the JOIN/WHERE scoping but dropping the
		 * result-shaping clauses (the shared shape select_ids() also uses).
		 */
		$inner         = $this->distinct_id_clauses( $clauses );
		$inner_request = $this->parse_request_clauses( $inner );

		/*
		 * The outer aggregate runs over the base table with no JOIN, bounded to the
		 * distinct primary keys the subquery resolved - one row each, no fan-out - and
		 * groups those deduped rows (GROUP BY belongs on the outer, not the inner).
		 */
		return $this->aggregate_clauses( $fields, '', "WHERE {$primary_ref} IN ( {$inner_request} )", $groupby );
	}

	/**
	 * Build the clause set for a scalar aggregate over the base table.
	 *
	 * The SELECT-path clause shape (so the query-clauses filter sees every key) with the
	 * aggregate expression(s) as its fields and no DISTINCT / ORDER BY / LIMIT.
	 * run_aggregate() passes the compiled JOIN/WHERE (and a GROUP BY when grouping);
	 * aggregate_via_subquery() passes no JOIN and a "primary IN ( ... )" WHERE.
	 *
	 * @since 3.1.0
	 *
	 * @param string $fields  The rendered aggregate field list, e.g. SUM( `t`.`col` ).
	 * @param string $join    The JOIN fragment (empty for the subquery-bounded outer).
	 * @param string $where   The WHERE fragment.
	 * @param string $groupby The GROUP BY fragment for a grouped aggregate. Default ''.
	 *
	 * @return array<string,string> The aggregate clause set.
	 */
	private function aggregate_clauses( string $fields, string $join, string $where, string $groupby = '' ): array {
		return array(
			'explain'     => '',
			'select'      => $this->parse_select(),
			'distinct'    => '',
			'fields'      => $fields,
			'from'        => $this->parse_from(),
			'index_hints' => '',
			'join'        => $join,
			'where'       => $where,
			'groupby'     => $groupby,
			'having'      => '',
			'orderby'     => '',
			'limits'      => '',
		);
	}
}
