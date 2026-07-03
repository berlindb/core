<?php
/**
 * Query Variables Trait Class.
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

/**
 * The query-variable vocabulary and normalization pipeline for a Query.
 *
 * Composed onto Query, sharing its $this run-state. Exposes the query-var
 * accessors (get_query_var / get_query_vars / set_query_var and the
 * default-value helpers) and runs the parse pipeline: parse_query merges
 * defaults, normalizes the high-level container directives (by / aggregate and
 * the query-filter sentinel) and validates the structural vars. The
 * AGGREGATE_FUNCTIONS allow-list stays a Query constant (trait constants need
 * PHP 8.2; core targets 8.1), reached through the abstract
 * get_aggregate_functions() the host implements.
 *
 * @since 3.1.0
 */
trait Variables {

	/**
	 * The SQL aggregate functions the 'aggregate' container supports.
	 *
	 * Supplied by the composing class: the list is a fixed vocabulary best
	 * expressed as a class constant, but trait constants require PHP 8.2 and
	 * BerlinDB targets 8.1 - so the host owns the constant and exposes it here.
	 *
	 * @since 3.1.0
	 *
	 * @return string[]
	 */
	abstract protected function get_aggregate_functions(): array;

	/**
	 * Get the value of a single query variable by key.
	 *
	 * Returns null when the key is not present in $query_vars. Exposed as
	 * public so parser hooks (e.g. In::get_orderby_sql()) can read the
	 * query vars set for the current run.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key Query var key.
	 * @return mixed Value, or null if not set.
	 */
	public function get_query_var( $key = '' ) {
		return isset( $this->query_vars[ $key ] )
			? $this->query_vars[ $key ]
			: null;
	}

	/**
	 * Get the full array of query variables for the current run.
	 *
	 * Exposed as public so parser hooks can read the query vars set for the current
	 * run.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed> Parsed query vars for the current run.
	 */
	public function get_query_vars(): array {
		return $this->query_vars;
	}

	/**
	 * Set a query var, to both defaults and request arrays.
	 *
	 * This method is used to expose the private $query_vars array to hooks,
	 * allowing them to manipulate query vars just-in-time.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Query variable key.
	 * @param string $value The value.
	 */
	public function set_query_var( $key = '', $value = '' ): void {
		$this->query_var_defaults[ $key ] = $value;
		$this->query_vars[ $key ]         = $value;
	}

	/**
	 * Check whether a query variable strictly equals the unique default
	 * starting value.
	 *
	 * @since 1.1.0
	 * @param string $key Query variable key.
	 * @return bool
	 */
	public function is_query_var_default( $key = '' ): bool {
		return ( $this->get_query_var( $key ) === $this->query_var_default_value );
	}

	/**
	 * Check whether a raw query variable value strictly equals the unique default
	 * starting value.
	 *
	 * Internal collaborator API for Query parsers; public so parser objects can
	 * identify the unset sentinel without knowing its generated value.
	 *
	 * @since 3.1.0
	 * @internal
	 *
	 * @param mixed $value Query variable value.
	 * @return bool
	 */
	public function is_query_var_default_value( $value = null ): bool {
		return ( $value === $this->query_var_default_value );
	}

	/**
	 * Return the query-var default sentinel (the "unset" marker).
	 *
	 * Internal collaborator API for Query parsers; public so a parser can resolve
	 * its registered default (Parsers\Base::get_query_var_default()) without
	 * reaching into a protected Query property.
	 *
	 * @since 3.1.0
	 * @internal
	 *
	 * @return string
	 */
	public function get_query_var_default_value(): string {
		return $this->query_var_default_value;
	}

	/**
	 * Parses arguments passed to the item query with default query parameters.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Forces some $query_vars if counting
	 *
	 * @param array<string,mixed>|string $query Query arguments array or string.
	 */
	private function parse_query( $query = array() ): void {

		// Stash the raw query args before any defaults are merged in.
		$this->set_current( 'query_var_originals', $this->parse_args( $query ) );

		// Setup the $query_vars parsed var.
		$this->query_vars = $this->parse_args(
			$this->get_current_array( 'query_var_originals' ),
			$this->query_var_defaults
		);

		/*
		 * Canonicalize the type-stable structural query vars (so semantically
		 * identical queries hash to the same cache key).
		 */
		$this->query_vars = $this->validate_query_vars( $this->query_vars );

		/*
		 * Normalize the query vars BEFORE the action (count overrides + high-level
		 * directive translation), so hooks and the SQL parsers see canonical vars
		 * rather than raw directive state.
		 */
		$this->query_vars = $this->normalize_query_vars( $this->query_vars );

		// Generate action name based on the plural item name.
		$action_name = $this->apply_prefix( 'parse_' . $this->get_item_name_plural() . '_query' );

		/**
		 * Fires after the item query vars have been parsed.
		 *
		 * @since 1.0.0
		 *
		 * @param \BerlinDB\Database\Kern\Query $query Current instance passed by reference.
		 */
		if ( '' !== $action_name ) {
			do_action_ref_array(
				$action_name,
				array(
					&$this,
				)
			);
		}
	}

	/**
	 * Canonicalize the type-stable structural query vars.
	 *
	 * Coerces the fixed, framework-level query vars to their canonical types
	 * (ints, booleans, ASC/DESC) so that semantically identical queries - e.g.
	 * number '5' vs 5, order 'asc' vs 'ASC' - produce the SAME cache key instead
	 * of fragmenting it, and so consumers always see a clean type.
	 *
	 * Deliberately scoped: only the closed set of structural vars is touched.
	 * Column/clause vars ({col}, date_query, meta_query, orderby/fields shapes,
	 * etc.) are left to their parsers, preserving the engine's fail-open routing.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The merged query vars.
	 * @return array<string,mixed> The query vars with structural keys canonicalized.
	 */
	private function validate_query_vars( array $query_vars = array() ): array {

		// Structural query var => canonicalizing callback.
		$callbacks = array(
			'number'            => 'intval',
			'order'             => array( $this, 'parse_order' ),
			'explain'           => array( $this, 'sanitize_boolean' ),
			'distinct'          => array( $this, 'sanitize_boolean' ),
			'count'             => array( $this, 'sanitize_boolean' ),
			'no_found_rows'     => array( $this, 'sanitize_boolean' ),
			'cache_results'     => array( $this, 'sanitize_boolean' ),
			'update_item_cache' => array( $this, 'sanitize_boolean' ),
			'update_meta_cache' => array( $this, 'sanitize_boolean' ),
		);

		// Coerce each present structural var to its canonical type.
		foreach ( $callbacks as $key => $callback ) {
			if ( array_key_exists( $key, $query_vars ) ) {
				$query_vars[ $key ] = call_user_func( $callback, $query_vars[ $key ] );
			}
		}

		return $query_vars;
	}

	/**
	 * Normalize the query vars early, before parsing.
	 *
	 * The all-vars counterpart to each parser's own var-local parse_query_vars()
	 * (which runs later, isolated to its single var). Here every registered parser descriptor
	 * may rewrite the FULL query vars - translating a high-level directive into
	 * another parser's canonical var (e.g. store-backed meta_query -> relation_query,
	 * or 'relation' -> {fk}__in / relation_query). Runs BEFORE the
	 * parse_{items}_query action, so the action and the SQL parsers see canonical
	 * vars. A descriptor may return a 'query_filter_short_circuit' sentinel to fail
	 * the query closed; it is consumed here. See berlindb/core #204.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The validated query vars.
	 * @return array<string,mixed> The normalized query vars.
	 */
	private function normalize_query_vars( array $query_vars = array() ): array {

		// One per-run reset for every normalizer below.
		$this->set_current( 'query_filter_short_circuit', false );

		/*
		 * Counting overrides the other structural vars (count was canonicalized
		 * to a boolean by validate_query_vars()).
		 */
		if ( ! empty( $query_vars[ 'count' ] ) ) {
			$query_vars[ 'number' ]            = false;
			$query_vars[ 'fields' ]            = '';
			$query_vars[ 'orderby' ]           = '';
			$query_vars[ 'no_found_rows' ]     = true;
			$query_vars[ 'update_item_cache' ] = false;
			$query_vars[ 'update_meta_cache' ] = false;
		}

		// Fold the 'by' column-filter container into canonical {column}__in vars.
		$query_vars = $this->normalize_by_container( $query_vars );

		// Canonicalize the 'aggregate' container to alias => { function, column }.
		$query_vars = $this->normalize_aggregate_container( $query_vars );

		// Each registered parser descriptor may rewrite the full query vars.
		foreach ( $this->parsers as $descriptor ) {
			$query_vars = $descriptor->normalize_query_vars( $query_vars, $this );
		}

		// Apply any fail-closed sentinel a descriptor returned.
		return $this->consume_query_filter_sentinel( $query_vars );
	}

	/**
	 * Fold the 'by' column-filter container into canonical {column}__in vars.
	 *
	 * The 'by' container is a friendlier, collision-proof column-filter shorthand -
	 * array( 'by' => array( 'status' => 3, 'type' => array( 1, 2 ) ) ). Each entry is
	 * rewritten to the In parser's canonical '{column}__in' var (which renders
	 * '= value' for a single value and 'IN (...)' for a list), so a column whose bare
	 * name collides with a reserved control var (e.g. a 'count' or 'order' column)
	 * stays filterable. It lives here, not in a parser, because a parser has no logging
	 * channel of its own; a By parser could not surface these diagnostics on the Query.
	 *
	 * Rules: only In-supported columns (in => true) are translated; an explicit
	 * top-level '{column}__in' wins over the container entry; an empty value, unknown
	 * column, or malformed container is logged and ignored. The container is consumed.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The query vars, mid-normalize.
	 * @return array<string,mixed> The query vars with 'by' folded away.
	 */
	private function normalize_by_container( array $query_vars = array() ): array {

		// Consume the container up front, whatever its shape.
		$by = $query_vars[ 'by' ] ?? array();
		unset( $query_vars[ 'by' ] );

		/*
		 * A non-array 'by' is malformed (the default and an explicit list are both
		 * arrays). Check the type BEFORE emptiness, so a falsy scalar - 0, false, ''
		 * - is reported rather than silently dropped as "empty".
		 */
		if ( ! is_array( $by ) ) {
			$this->log( 'warning', 'by', 'The "by" query var must be an array of column => value(s); ignoring it.' );
			return $query_vars;
		}

		// An empty container (the default, or an explicit empty array) is a no-op.
		if ( array() === $by ) {
			return $query_vars;
		}

		// The unset-var sentinel, to tell an explicit {column}__in from a default one.
		$sentinel = $this->get_query_var_default_value();

		// Fold each column entry into its canonical {column}__in var.
		foreach ( $by as $column => $value ) {

			// Skip an empty value (no filter), matching the engine's empty-filter behavior.
			if ( ( '' === $value ) || ( array() === $value ) || ( null === $value ) ) {
				continue;
			}

			// Only translate an In-supported column; log and ignore anything else.
			if ( empty(
				$this->get_columns(
					array(
						'name' => (string) $column,
						'in'   => true,
					)
				)
			) ) {
				$this->log( 'warning', 'by', "The 'by' entry '{$column}' is not an in-filterable column; ignoring it." );
				continue;
			}

			/*
			 * An explicit top-level {column}__in wins over the container entry. The
			 * key is always present (In registers its default), so an explicit value
			 * is any that is not the unset sentinel - read it directly rather than
			 * with ??, so an explicit null is honored, not treated as absent.
			 */
			$key      = "{$column}__in";
			$existing = array_key_exists( $key, $query_vars )
				? $query_vars[ $key ]
				: $sentinel;

			if ( $existing !== $sentinel ) {
				continue;
			}

			// Rewrite to the canonical In var.
			$query_vars[ $key ] = (array) $value;
		}

		return $query_vars;
	}

	/**
	 * Canonicalize the 'aggregate' container to alias => array( function, column ).
	 *
	 * Accepts the shorthand array( 'sum' => 'amount' ) (the key is the function, the
	 * value the column, the alias defaults to the function) and the aliased forms
	 * array( 'revenue' => array( 'sum', 'amount' ) ) or array( 'revenue' => array(
	 * 'function' => 'sum', 'column' => 'amount' ) ). Each entry is validated against the
	 * aggregate function allow-list and the schema columns; an invalid entry or a
	 * duplicate alias is logged and dropped. The result replaces the container so the
	 * execution path (see #225) reads one canonical shape.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The query vars, mid-normalize.
	 * @return array<string,mixed> The query vars with a canonical 'aggregate' container.
	 */
	private function normalize_aggregate_container( array $query_vars = array() ): array {

		// Consume the container up front, whatever its shape.
		$aggregate = $query_vars[ 'aggregate' ] ?? array();
		unset( $query_vars[ 'aggregate' ] );

		// A non-array 'aggregate' is malformed.
		if ( ! is_array( $aggregate ) ) {
			$this->log( 'warning', 'aggregate', 'The "aggregate" query var must be an array of aggregates; ignoring it.' );
			return $query_vars;
		}

		// An empty container (the default, or an explicit empty array) is a no-op.
		if ( array() === $aggregate ) {
			return $query_vars;
		}

		// Canonicalize each entry to alias => array( function, column ).
		$canonical = array();

		foreach ( $aggregate as $key => $spec ) {
			$entry = $this->canonicalize_aggregate_entry( (string) $key, $spec );

			// Skip an invalid entry (already logged).
			if ( null === $entry ) {
				continue;
			}

			// Reject a duplicate alias rather than silently overwriting it.
			if ( array_key_exists( $entry[ 'alias' ], $canonical ) ) {
				$this->log( 'warning', 'aggregate', "Duplicate aggregate alias '{$entry[ 'alias' ]}'; keeping the first." );
				continue;
			}

			$canonical[ $entry[ 'alias' ] ] = array(
				'function' => $entry[ 'function' ],
				'column'   => $entry[ 'column' ],
			);
		}

		/*
		 * Set the container only when something survived, so an all-invalid container
		 * behaves like an absent one (a normal query, not an empty aggregate) and does
		 * not leak an empty 'aggregate' into the cache key.
		 */
		if ( array() !== $canonical ) {
			$query_vars[ 'aggregate' ] = $canonical;
		}

		return $query_vars;
	}

	/**
	 * Resolve one 'aggregate' entry into an { alias, function, column } triple.
	 *
	 * @since 3.1.0
	 *
	 * @param string $key  The container key (the function for shorthand, else the alias).
	 * @param mixed  $spec The column name (shorthand) or a { function, column } spec.
	 * @return array{alias: string, function: string, column: string}|null The resolved
	 *                                                                      triple, or null when invalid.
	 */
	private function canonicalize_aggregate_entry( string $key, $spec ): ?array {

		// Shorthand: array( 'sum' => 'amount' ) - key is the function, value the column.
		if ( is_string( $spec ) ) {
			$alias    = $key;
			$function = $key;
			$column   = $spec;

			// Aliased: array( 'revenue' => array( 'sum', 'amount' ) ) or a named spec.
		} elseif ( is_array( $spec ) ) {
			$alias    = $key;
			$function = (string) ( $spec[ 'function' ] ?? ( $spec[ 0 ] ?? '' ) );
			$column   = (string) ( $spec[ 'column' ] ?? ( $spec[ 1 ] ?? '' ) );

			// Anything else is malformed.
		} else {
			$this->log( 'warning', 'aggregate', "Aggregate entry '{$key}' must be a column name or a { function, column } spec; ignoring it." );
			return null;
		}

		// Validate the function against the aggregate allow-list (case-insensitive).
		$function = strtoupper( $function );

		if ( ! in_array( $function, $this->get_aggregate_functions(), true ) ) {
			$this->log( 'warning', 'aggregate', "Unsupported aggregate function for '{$alias}'; ignoring it." );
			return null;
		}

		/*
		 * COUNT( * ) is the one aggregate with no column - a row count. Every other
		 * form needs a real column (a bare '*' is only valid for COUNT); SUM and AVG
		 * additionally need a numeric one. MAX/MIN/COUNT work on any column.
		 */
		$counts_rows = ( 'COUNT' === $function ) && ( '*' === $column );

		if ( false === $counts_rows ) {

			if ( '*' === $column ) {
				$this->log( 'warning', 'aggregate', "Aggregate '{$alias}' ({$function}) needs a column, not '*'; ignoring it." );
				return null;
			}

			$column_object = $this->get_column_by( array( 'name' => $column ) );

			if ( ! ( $column_object instanceof Column ) ) {
				$this->log( 'warning', 'aggregate', "Aggregate '{$alias}' references unknown column '{$column}'; ignoring it." );
				return null;
			}

			if ( in_array( $function, array( 'SUM', 'AVG' ), true ) && ! $column_object->is_numeric() ) {
				$this->log( 'warning', 'aggregate', "Aggregate '{$alias}' ({$function}) needs a numeric column; '{$column}' is not. Ignoring it." );
				return null;
			}
		}

		return array(
			'alias'    => $alias,
			'function' => $function,
			'column'   => $column,
		);
	}

	/**
	 * Consume a fail-closed sentinel a normalizer left in the query vars.
	 *
	 * A parser descriptor cannot reach Query's private short-circuit helper, so it
	 * signals fail-closed by returning a 'query_filter_short_circuit' query var
	 * (array{source, reason}); this applies and removes it.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars The normalized query vars.
	 * @return array<string,mixed> The query vars without the sentinel.
	 */
	private function consume_query_filter_sentinel( array $query_vars ): array {

		$sentinel = $query_vars[ 'query_filter_short_circuit' ] ?? null;

		// Nothing to consume.
		if ( empty( $sentinel ) ) {
			return $query_vars;
		}

		// Remove it so it never reaches the cache key or the SQL parsers.
		unset( $query_vars[ 'query_filter_short_circuit' ] );

		$source = is_array( $sentinel ) ? (string) ( $sentinel[ 'source' ] ?? 'query_filter' ) : 'query_filter';
		$reason = is_array( $sentinel ) ? (string) ( $sentinel[ 'reason' ] ?? '' ) : '';

		$this->short_circuit_query_filter( $source, $reason );

		return $query_vars;
	}

	/**
	 * Flag the current run to return no rows (fail-closed query filter).
	 *
	 * Shared by the high-level query-filter translators (relationship filters and
	 * meta_query translation). An empty $reason marks a legitimate empty match (no
	 * log); a non-empty $reason marks a misconfigured filter and is logged as a
	 * warning under the $source channel so the failure is attributable.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $source  Log channel/code (e.g. 'relation_filter', 'meta_query').
	 * @param string               $reason  Why the filter could not be applied.
	 * @param array<string,mixed> $context Optional log context.
	 */
	private function short_circuit_query_filter( string $source, string $reason = '', array $context = array() ): void {

		// Flag the current run to return no rows.
		$this->set_current( 'query_filter_short_circuit', true );

		// Log misconfigured filters; an empty reason is a legitimate no-match.
		if ( '' !== $reason ) {
			$this->log( 'warning', $source, $reason, $context );
		}
	}
}
