<?php
/**
 * Query Execution Trait Class.
 *
 * @package     Database
 * @subpackage  Query
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Query;

use BerlinDB\Database\Kern\Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SELECT execution for a Query: run a query and produce its result.
 *
 * Composed onto Query, sharing its $this run-state. Owns the read pipeline that
 * query() / get_items() drives: fire the pre-get scope hook, resolve the query
 * mode (rows / count / aggregate), build the clauses and request, execute, and
 * shape the raw result - with the cache read/write and pagination in between.
 * Also holds select_ids(), the fail-closed primary-ID resolver the write verbs
 * use. Delegates clause building to the Clauses trait and hydration to Hydration.
 *
 * @since 3.1.0
 */
trait Execution {

	/**
	 * Fire the "pre_get_{plural}" action, where installs scope a query just in time.
	 *
	 * Shared by get_items() (the SELECT path) and select_ids() (delete-by-filter), so
	 * an install's pre-get scoping constrains both equally.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	private function pre_get_items(): void {

		// Generate action name based on the plural item name.
		$action_name = $this->apply_hook_prefix( 'pre_get_' . $this->get_item_name_plural() );

		// Bail if no action name.
		if ( '' === $action_name ) {
			return;
		}

		/**
		 * Fires before object items are retrieved.
		 *
		 * @since 1.0.0
		 *
		 * @param Query $query Current instance passed by reference.
		 */
		do_action_ref_array(
			$action_name,
			array(
				&$this,
			)
		);
	}

	/**
	 * Resolve which mode this query run is in, from its (parsed, normalized) vars.
	 *
	 * A query is in exactly one mode: 'aggregate' (the aggregate container), 'count'
	 * (a COUNT request), or 'rows' (the default - select and shape matching items).
	 * 'aggregate' outranks 'count' (an aggregate container wins if both are set), the
	 * same precedence get_item_ids() dispatches in. Resolved ONCE per run and stored in
	 * the ephemeral state (get_query_mode() reads it); groupby/explain are modifiers on
	 * a mode, not modes of their own.
	 *
	 * @since 3.1.0
	 *
	 * @return string 'aggregate', 'count', or 'rows'.
	 */
	private function resolve_query_mode(): string {
		$aggregate = $this->get_query_var( 'aggregate' );

		if ( ! empty( $aggregate ) && is_array( $aggregate ) ) {
			return 'aggregate';
		}

		if ( $this->get_query_var( 'count' ) ) {
			return 'count';
		}

		return 'rows';
	}

	/**
	 * Get the resolved mode for the current run.
	 *
	 * @since 3.1.0
	 *
	 * @return string 'aggregate', 'count', or 'rows' (the default before resolution).
	 */
	private function get_query_mode(): string {
		return (string) $this->get_current( 'query_mode', 'rows' );
	}

	/**
	 * Get the items, populate them, and return them.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int|string,mixed>|int Array of items, or number of items when 'count' is passed as a query var.
	 */
	private function get_items(): array|int {

		// Fire the pre-get action, where installs scope the query just in time.
		$this->pre_get_items();

		// Resolve the query mode once, after the last mode-affecting hook; immutable hereafter.
		$this->set_current( 'query_mode', $this->resolve_query_mode() );

		/*
		 * A normalized query directive resolved to no possible matches: return
		 * nothing without caching (an empty resolved set must never widen to all rows).
		 */
		if ( true === $this->get_current( 'query_filter_short_circuit', false ) ) {
			$this->set_found_items( array() );

			// An aggregate over no matching rows is null per alias (grouped: no groups).
			if ( 'aggregate' === $this->get_query_mode() ) {
				$this->items = ! empty( $this->get_valid_groupby_columns( (array) $this->get_query_var( 'groupby' ) ) )
					? array()
					: $this->empty_aggregate_result( (array) $this->get_query_var( 'aggregate' ) );
				return $this->items;
			}

			/*
			 * Mirror get_items()'s count/non-count return shapes for empty: a plain
			 * count is 0; a grouped count or a non-count query is an empty array.
			 */
			$is_plain_count = ( 'count' === $this->get_query_mode() ) && ! $this->get_query_var( 'groupby' );

			$this->items = $is_plain_count
				? 0
				: array();

			return $this->items;
		}

		/*
		 * Check the cache. EXPLAIN returns a plan (not rows) and must reflect the
		 * current optimizer state, so it is never served from or stored in the cache.
		 */
		$cache_results = (bool) $this->get_query_var( 'cache_results' )
			&& empty( $this->get_query_var( 'explain' ) );
		$cache_key     = $this->get_cache_key();
		$cache_value   = ( true === $cache_results )
			? $this->cache_get( $cache_key, $this->cache_group )
			: false;

		// No cache value.
		if ( false === $cache_value ) {

			// Query for item IDs.
			$result = $this->get_item_ids();

			// Set the number of found items.
			$this->set_found_items( $result );

			// Format the cached value.
			$cache_value = array(
				'item_ids'    => $result,
				'found_items' => $this->get_current_int( 'found_items' ),
			);

			// Only store when caching is enabled for this query.
			if ( true === $cache_results ) {
				$this->cache_add( $cache_key, $cache_value, $this->cache_group );
			}

			// Value exists in cache.
		} elseif ( is_array( $cache_value ) ) {
			$result          = $cache_value[ 'item_ids' ] ?? array();
			$found_items_val = $cache_value[ 'found_items' ] ?? 0;
			$this->set_current( 'found_items', (int) $found_items_val );
		} else {
			$result = array();
			$this->set_current( 'found_items', 0 );
		}

		// Pagination.
		$found_items = $this->get_current_int( 'found_items' );
		if ( ! empty( $found_items ) ) {
			$number = $this->get_query_var( 'number' );

			if ( is_int( $number ) || is_string( $number ) ) {
				$number_int = (int) $number;
				if ( ! empty( $number_int ) ) {
					$this->set_current( 'max_num_pages', (int) ceil( $found_items / $number_int ) );
				}
			}
		}

		// Shape the raw result into this run's return value, by mode.
		return $this->shape_result( $result );
	}

	/**
	 * Shape the raw execution result into this run's return value, by mode.
	 *
	 * The final stage of get_items(): aggregate returns its alias-keyed assoc (or
	 * grouped rows) and count its int (or grouped rows) as-is; the default rows mode
	 * hydrates the selected primary IDs into shaped item objects. Runs identically on a
	 * cache hit and a cache miss - it shapes whatever get_item_ids() or the cache gave.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed>|int $result The raw result: IDs, a count, or aggregates.
	 * @return array<int|string,mixed>|int The shaped return value.
	 */
	private function shape_result( $result ): array|int {

		// Aggregate: the alias-keyed assoc, or grouped rows.
		if ( 'aggregate' === $this->get_query_mode() ) {
			$this->items = is_array( $result )
				? $result
				: array();
			return $this->items;
		}

		// Count: the int total, or grouped count rows.
		if ( 'count' === $this->get_query_mode() ) {
			$this->items = $result;
			return $this->items;
		}

		// Rows: hydrate the selected primary IDs into shaped item objects.
		if ( is_array( $result ) ) {
			/** @var list<int|string> $result */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$this->hydrate_items( $result );
		} else {
			$this->hydrate_items( array() );
		}

		return is_array( $this->items ) ? $this->items : array();
	}

	/**
	 * Set query_clauses by parsing $query_vars.
	 *
	 * @since 3.0.0
	 */
	private function set_query_clauses(): void {
		$this->set_current( 'query_clauses', $this->parse_query_vars() );
	}

	/**
	 * Set the request_clauses.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses parse_query_clauses() with support for new clauses.
	 */
	private function set_request_clauses(): void {
		$this->set_current( 'request_clauses', $this->parse_query_clauses() );
	}

	/**
	 * Set the request SQL string.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses parse_request_clauses() on request_clauses.
	 */
	private function set_request(): void {
		$this->set_current( 'request', $this->parse_request_clauses() );
	}

	/**
	 * Used internally to get a list of item IDs matching the query vars.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses wp_parse_list() instead of wp_parse_id_list()
	 *
	 * @return array<bool|float|int|string>|array<string,mixed>[]|array<string,string|null>|int Item IDs for a full query, int/rows for a count query, or aggregate results keyed by alias.
	 */
	private function get_item_ids(): array|int {

		/*
		 * Aggregate mode computes its result from its own request (run_aggregate()),
		 * with no primary-ID selection, so it returns before the shared request below.
		 */
		if ( 'aggregate' === $this->get_query_mode() ) {
			return $this->run_aggregate( (array) $this->get_query_var( 'aggregate' ) );
		}

		// Count and rows both run the assembled request; build it once.
		$this->set_query_clauses();
		$this->set_request_clauses();
		$this->set_request();

		$request = $this->get_current_string( 'request' ) ?? '';

		// Dispatch to the mode's execution.
		return ( 'count' === $this->get_query_mode() )
			? $this->execute_count( $request )
			: $this->execute_row_ids( $request );
	}

	/**
	 * Execute a count query: the total as an int, or per-group rows when grouping.
	 *
	 * @since 3.1.0
	 *
	 * @param string $request The assembled COUNT request.
	 * @return array<int,array<string,mixed>>|int The count, or grouped count rows.
	 */
	private function execute_count( string $request ) {
		return ! $this->get_query_var( 'groupby' )
			? (int) $this->db()->get_var( $request )
			: (array) $this->db()->get_results( $request, ARRAY_A );
	}

	/**
	 * Execute a rows query's ID selection: the matching primary IDs.
	 *
	 * The default mode selects primary IDs here; get_items() hydrates them into shaped
	 * item objects after the cache, so a cache hit skips straight to hydration.
	 *
	 * @since 3.1.0
	 *
	 * @param string $request The assembled SELECT request.
	 * @return array<bool|float|int|string> The matching primary IDs.
	 */
	private function execute_row_ids( string $request ): array {
		return wp_parse_list( $this->db()->get_col( $request ) );
	}

	/**
	 * Select the primary IDs of the rows matching a set of query-var filters.
	 *
	 * A narrow, side-effect-free companion to get_item_ids(): it compiles the
	 * passed vars into a JOIN/WHERE (running the parsers + Clauses\Builder, the
	 * same construction the SELECT path uses) and returns the distinct primary IDs,
	 * without the get_item_ids() lifecycle - no cache, no found_items, no count or
	 * pagination handling, and no mutation of the query's stored clauses/request.
	 *
	 * Fails closed: if the filters compile to no WHERE, this refuses and returns an
	 * empty array rather than selecting every row. A malformed 'criteria' tree
	 * compiles to a non-empty "1 = 0" WHERE (the Clauses\Where safeguard) and so
	 * simply matches nothing. DISTINCT guards against JOINs multiplying rows.
	 *
	 * @since 3.1.0
	 * @internal Operation collaborator (Operations\Delete, and later Update).
	 *
	 * @param array<string,mixed> $query_vars Query-var filters (same vocabulary as query()).
	 * @return array<int,int|string> Distinct primary IDs, or an empty array when refused / unmatched.
	 */
	public function select_ids( array $query_vars = array() ): array {

		/*
		 * Mirror the SELECT path's parse_query preparation, but ON $this->query_vars
		 * and snapshot/restore so it leaves no trace. Parser callbacks read state
		 * back off the Query (e.g. the Relationship parser fetches its translated
		 * relation_query via caller()->get_query_var()), so the normalized vars must
		 * be the Query's vars for the whole build, not just a local copy.
		 */
		/*
		 * Snapshot both the vars and their defaults: a scoping hook may call
		 * set_query_var(), which writes both, and this transient ID selection must
		 * leave no trace on the reused Query instance.
		 */
		$saved_query_vars     = $this->query_vars;
		$saved_query_defaults = $this->query_var_defaults;

		try {

			/*
			 * Run the SELECT path's full preparation on $this->query_vars: merge
			 * defaults, canonicalize, normalize high-level directives (relation,
			 * store-backed meta_query, ...) AND fire the parse_{plural}_query action,
			 * where installs scope the query via set_query_var(). Parser callbacks read
			 * these vars back off the Query, so skipping any step would let a delete
			 * reach rows a normal query() would have excluded.
			 */
			$this->parse_query( $query_vars );

			// Fire the same pre-get scoping action a normal read does, before SQL.
			$this->pre_get_items();

			// A filter normalized to "no possible matches" resolves to nothing.
			if ( true === $this->get_current( 'query_filter_short_circuit', false ) ) {
				return array();
			}

			// Compile the JOIN/WHERE via the parsers and Clauses\Builder.
			$join_where = $this->parse_join_where( $this->query_vars );

			// Render the WHERE and JOIN fragments.
			$where = $this->parse_where_clause( $join_where[ 'where' ] );
			$join  = $this->parse_join_clause( $join_where[ 'join' ] );

			/*
			 * Fail closed: a delete must never resolve to "all rows", so require a WHERE
			 * constraint. A JOIN alone is not safe to rely on -- a LEFT JOIN does not
			 * constrain the primary table, and even an INNER JOIN's effect depends on
			 * its ON clause -- so a filter that compiles to only a JOIN deletes nothing
			 * rather than risk an unbounded delete. (A relationship filter that bounds
			 * solely through a JOIN, with no remote WHERE, is therefore a no-op here for
			 * now; constrain via a WHERE, or add explicit JOIN-strategy support later.)
			 */
			if ( '' === $where ) {
				$this->log( 'warning', 'operation', 'Refusing to select IDs without a WHERE clause.' );
				return array();
			}

			/*
			 * Assemble a narrow "SELECT DISTINCT primary" clause set, in the same shape
			 * the SELECT path produces (so the query-clauses filter sees every key).
			 */
			$clauses = $this->distinct_id_clauses(
				array(
					'join'  => $join,
					'where' => $where,
				)
			);

			/*
			 * Apply the same {plural}_query_clauses filter the SELECT path runs, so any
			 * install-level scoping (tenant/ownership/status/capability predicates a site
			 * adds to WHERE/JOIN) also constrains which rows can be resolved and deleted.
			 * The empty-WHERE refusal above is intentionally checked on the user's filter
			 * BEFORE this: site scoping narrows a delete, it never enables an unbounded one.
			 */
			$clauses = $this->filter_query_clauses( $clauses );

			// Join the non-empty fragments into a single statement.
			$request = $this->parse_request_clauses( $clauses );

			// Execute, then shape each raw ID to the primary column type.
			$item_ids = $this->db()->get_col( $request );

			return array_map( array( $this, 'shape_item_id' ), wp_parse_list( $item_ids ) );

		} finally {

			// Always restore the Query's own vars and defaults, even on an early return.
			$this->query_vars         = $saved_query_vars;
			$this->query_var_defaults = $saved_query_defaults;
		}
	}

	/**
	 * Reshape a clause set to select the DISTINCT primary key.
	 *
	 * Forces the id-selection shape: DISTINCT on, fields set to the aliased primary key,
	 * a plain SELECT over the base table, no hints. It keeps the clauses that decide
	 * WHICH rows match - JOIN and WHERE - so a query-clauses filter's scoping still
	 * applies, but drops the clauses that reshape the RESULT - GROUP BY, ORDER BY, LIMIT
	 * - because those would narrow the id set (one key per group, or a truncated page)
	 * and undercount an aggregate.
	 *
	 * Like the SELECT path, this assumes a query-clauses filter SCOPES the base query -
	 * adding JOIN/WHERE predicates against the base table's alias - and reads FROM the
	 * base table; it deliberately does not honor a filter that rewrites FROM to a
	 * different source (the field/alias references are the base table's throughout, as
	 * everywhere else in Query). select_ids() passes just its compiled JOIN/WHERE; an
	 * aggregate over a fan-out JOIN passes its whole filtered clause set (see
	 * aggregate_via_subquery()). The canonical clause order is fixed here, so
	 * the imploded SQL is well-formed no matter the caller's key order.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,string> $clauses A (possibly partial) clause set to reshape.
	 *
	 * @return array<string,string> The clause set selecting DISTINCT the primary key.
	 */
	private function distinct_id_clauses( array $clauses ): array {
		return array(
			'explain'     => '',
			'select'      => $this->parse_select(),
			'distinct'    => $this->parse_distinct( true ),
			'fields'      => $this->get_quoted_column_name_aliased(),
			'from'        => $this->parse_from(),
			'index_hints' => '', // ID resolution is deliberately un-hinted.
			'join'        => $clauses[ 'join' ] ?? '',
			'where'       => $clauses[ 'where' ] ?? '',
			'groupby'     => '',
			'orderby'     => '',
			'limits'      => '',
		);
	}

	/**
	 * Populates found_items for the current query.
	 *
	 * If the limit clause was used.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses filter_found_items_query().
	 *
	 * @param mixed $item_ids Optional array of item IDs, or count from a COUNT query.
	 */
	private function set_found_items( $item_ids = array() ): void {

		// Aggregate mode has no row count (and builds no request_clauses to reuse).
		if ( 'aggregate' === $this->get_query_mode() ) {
			$this->set_current( 'found_items', 0 );
			return;
		}

		/*
		 * Count mode: a plain count IS the found-items total; a grouped count reports
		 * the number of group rows it returned.
		 */
		if ( 'count' === $this->get_query_mode() ) {
			$retval = ( is_numeric( $item_ids ) && ! $this->get_query_var( 'groupby' ) )
				? $item_ids
				: count( (array) $item_ids );

			$this->set_current( 'found_items', (int) $retval );
			return;
		}

		// Rows mode: this page's rows, or the supplementary total when paginating.
		$this->set_current( 'found_items', $this->count_found_items( $item_ids ) );
	}

	/**
	 * Count the total matching rows for a rows-mode query.
	 *
	 * Defaults to the number of primary IDs this page returned. When the query is
	 * paginated - a 'number' limit with found-rows enabled - it instead runs the
	 * supplementary count that reuses the request clauses: parse_count() renders
	 * COUNT(DISTINCT primary) under DISTINCT so a row-multiplying JOIN does not inflate
	 * the total, and LIMIT / ORDER BY / the standalone DISTINCT keyword are dropped.
	 * get_items() turns the result into max_num_pages. A rows-mode concern only - count
	 * and aggregate never reach it.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $item_ids The primary IDs this page returned.
	 * @return int The total matching rows.
	 */
	private function count_found_items( $item_ids ): int {

		// This page's rows - the total unless a paginated query needs the full count.
		$retval = count( (array) $item_ids );

		// No pagination requested: the page count is the total.
		if ( ! empty( $this->get_query_var( 'no_found_rows' ) ) || empty( $this->get_query_var( 'number' ) ) ) {
			return $retval;
		}

		// Reuse the request clauses, overriding a few to make it a clean COUNT.
		$overrides = array(
			'fields'   => $this->parse_count( true ),
			'limits'   => '',
			'orderby'  => '',
			'distinct' => '',
		);

		/*
		 * When the row request applied the implicit primary-key dedupe grouping (an OR
		 * relation across JOINs - see parse_query_vars()), parse_count() above already
		 * switched to COUNT(DISTINCT primary). Drop the leftover GROUP BY too, or the
		 * COUNT becomes per-group and get_var() reads only the first group's total.
		 */
		if ( $this->get_current( 'dedupe_or_join', false ) ) {
			$overrides[ 'groupby' ] = '';
		}

		$r = $this->parse_args( $overrides, $this->get_current_array( 'request_clauses' ) );

		// Build and filter the found-items query.
		$query = $this->filter_found_items_query( $this->parse_request_clauses( $r ) );

		// Run it when there is one; otherwise keep this page's count.
		return ! empty( $query )
			? (int) $this->db()->get_var( $query )
			: $retval;
	}
}
