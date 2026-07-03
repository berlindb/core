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
 * fan-out-JOIN dedup via a distinct-primary subquery), and the get_in_sql helper.
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
	 *
	 * @return string The rendered expression, e.g. SUM( `t`.`amount` ); '' if the
	 *                column could not be resolved.
	 */
	private function render_aggregate_expression( string $function, string $column ): string {

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
				'sql'  => $function,
				'args' => array( $operand ),
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

			$expression = $this->render_aggregate_expression( $spec[ 'function' ], $spec[ 'column' ] );

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
			'orderby'     => '',
			'limits'      => '',
		);
	}

	/**
	 * Used internally to generate the SQL string for IN and NOT IN clauses.
	 *
	 * The $values being passed in should not be validated, and they will be
	 * escaped before they are concatenated together and returned as a string.
	 *
	 * @since 3.0.0
	 *
	 * @param string                          $column_name Column name.
	 * @param array<array-key,mixed>|string  $values      Value(s) to escape. Arrays are
	 *                                                     flattened into the prepared statement.
	 * @param bool                            $wrap        To wrap in parenthesis.
	 * @param string                          $pattern     Pattern to prepare with.
	 *
	 * @return string Escaped/prepared SQL, possibly wrapped in parenthesis.
	 */
	public function get_in_sql( $column_name = '', $values = array(), $wrap = true, $pattern = '' ): string {

		// Bail if no values or invalid column.
		if ( empty( $values ) || ! $this->is_valid_column( $column_name ) ) {
			return '';
		}

		// Fallback to column pattern.
		if ( empty( $pattern ) || ! is_string( $pattern ) ) {
			$pattern = $this->get_column_field( array( 'name' => $column_name ), 'pattern', '%s' );
		}

		// Fill an array of patterns to match the number of values.
		$values   = (array) $values;
		$count    = count( $values );
		$patterns = array_fill( 0, $count, $pattern );

		// Prepare.
		$sql    = implode( ', ', $patterns );
		$retval = $this->db()->prepare( $sql, ...$values );

		// Set return value to empty string if prepare() returns falsy.
		if ( empty( $retval ) ) {
			$retval = '';
		}

		// Wrap them in parenthesis.
		if ( true === $wrap ) {
			$retval = "({$retval})";
		}

		// Return in SQL.
		return $retval;
	}
}
