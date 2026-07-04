<?php
/**
 * Base Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Parser
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class for parsing some $query_vars array into an array of SQL clauses.
 *
 * @since 3.0.0
 */
trait Parser {

	/**
	 * Use these traits.
	 *
	 * @since 3.0.0
	 */
	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\OperatorRegistry;

	/**
	 * Query class responsible for constructing this parser.
	 *
	 * @since 3.0.0
	 * @var \BerlinDB\Database\Kern\Query|null
	 */
	public $caller = null;

	/**
	 * Array of first-order keys.
	 *
	 * @since 3.0.0
	 * @var list<string>
	 */
	public $first_keys = array();

	/**
	 * Array of queries.
	 *
	 * @since 3.0.0
	 * @var array<string|int,mixed>
	 */
	public $queries = array();

	/**
	 * The default relation between top-level queries.
	 *
	 * Can be changed via the query arguments.
	 *
	 * Can be either 'AND' or 'OR'.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $relation = 'AND';

	/**
	 * The column to query against.
	 *
	 * Can be changed via the query arguments.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $column = '';

	/**
	 * The value comparison operator.
	 *
	 * Can be changed via the query arguments.
	 *
	 * @since 3.0.0
	 * @var   string
	 */
	public $compare = '=';

	/**
	 * The UNIX timestamp for this current time.
	 *
	 * Can be changed via query arguments.
	 *
	 * @since 3.0.0
	 * @var   int
	 */
	public $now = 0;

	/**
	 * The start of week operator.
	 *
	 * Can be changed via query arguments.
	 *
	 * @since 3.0.0
	 * @var   int
	 */
	public $start_of_week = 0;

	/**
	 * Array of clauses.
	 *
	 * @since 3.0.0
	 * @var array<string,mixed>
	 */
	public $clauses = array();

	/**
	 * Supported multi-value comparison types.
	 *
	 * @since 3.0.0
	 * @var list<string>
	 */
	public $multi_value_keys = array();

	/**
	 * Supported relation types.
	 *
	 * @since 3.0.0
	 * @var   list<string>
	 */
	public $relation_keys = array(
		'OR',
		'AND',
	);

	/**
	 * Whether the query contains any OR relations.
	 *
	 * @since 3.0.0
	 * @var   bool
	 */
	protected $has_or_relation = false;

	/**
	 * Constructor.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed>                $query_vars Array of query variables.
	 * @param \BerlinDB\Database\Kern\Query|null $caller The parent Query instance.
	 */
	public function __construct( array $query_vars = array(), mixed $caller = null ) {
		$this->init( $query_vars, $caller );
	}

	/**
	 * Initialize the parser.
	 *
	 * When 'compare' is:
	 * - 'IN' or 'NOT IN'           - arrays are accepted
	 * - 'BETWEEN' or 'NOT BETWEEN' - arrays of two valid values are required
	 *
	 * See individual argument descriptions for accepted values.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed>               $query_vars {
	 *     Array of query clauses.
	 *
	 *     @type array ...$0 {
	 *         @type string $column   Optional. The column to query against.
	 *                                Default ''.
	 *         @type string $compare  Optional. The comparison operator. Accepts '=', '!=', '>', '>=', '<', '<=',
	 *                                'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'LIKE', 'RLIKE'. Default '='.
	 *         @type string $relation Optional. The boolean relationship between the queries. Accepts 'OR' or 'AND'.
	 *                                Default 'OR'.
	 *         @type array  ...$0 {
	 *             Optional. An array of first-order clause parameters, or another fully-formed query.
	 *         }
	 *     }
	 * }
	 * @param \BerlinDB\Database\Kern\Query|null $caller The Query class that invoked this parser, or null.
	 */
	protected function init( array $query_vars = array(), mixed $caller = null ): void {

		// Allow subclasses to normalize query vars before the rest of init() runs.
		$query_vars = $this->parse_query_vars( $query_vars );

		// Set the caller & first_keys.
		$this->set_caller( $caller );
		$this->set_first_keys( array() );

		// Set the operators.
		$this->set_operators();

		// Set default class attributes from query.
		$this->now           = $this->get_now( $query_vars );
		$this->column        = $this->get_column( $query_vars );
		$this->compare       = $this->get_compare( $query_vars );
		$this->relation      = $this->get_relation( $query_vars );
		$this->start_of_week = $this->get_start_of_week( $query_vars );

		// Support for passing some key in the top level of the array.
		if ( ! isset( $query_vars[0] ) ) {

			// Apply a default alias to first-order clauses when not provided.
			if ( is_array( $query_vars ) && empty( $query_vars[ 'alias' ] ) ) {
				$query_vars[ 'alias' ] = $this->get_table_alias( $query_vars );
			}

			$query_vars = array( $query_vars );
		}

		// Set the queries.
		$this->queries = $this->sanitize_query( $query_vars );
	}

	/**
	 * Pre-process query vars before init() runs.
	 *
	 * Subclasses may override this to transform raw query vars into the
	 * normalized structure that init() expects. The default is a no-op.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query_vars The raw query vars.
	 *
	 * @return array<string,mixed> The (possibly transformed) query vars.
	 */
	protected function parse_query_vars( $query_vars = array() ) {
		return $query_vars;
	}

	/**
	 * Parse a single query variable value into a list of values.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars Array of query variables.
	 * @param string              $key Query variable key.
	 *
	 * @return false|array<mixed> False if not set, default, or empty. Array values
	 *                            are returned as-is; scalar/object values are
	 *                            wrapped; comma-separated strings are split.
	 */
	protected function parse_query_var_value( $query_vars = array(), $key = '' ) {

		// Bail if no query vars exist for that ID.
		if ( ! isset( $query_vars[ $key ] ) ) {
			return false;
		}

		// Get the value.
		$value = $query_vars[ $key ];

		// Bail if equal to the exact default random value.
		if ( true === $this->caller?->is_query_var_default_value( $value ) ) {
			return false;
		}

		/*
		 * Arrays are already a parsed list of values for __in / __not_in style
		 * query vars. Return them as-is so they are not wrapped into a nested
		 * single-item array and mistaken for a scalar comparison. Empty arrays
		 * are equivalent to no value.
		 */
		if ( is_array( $value ) ) {
			return ! empty( $value )
				? $value
				: false;
		}

		/*
		 * Early return objects, numerics, integers, or bools.
		 *
		 * These values assume the caller knew what it was doing, and are
		 * wrapped as one parsed value without any extra handling.
		 */
		if ( is_object( $value ) || is_int( $value ) || is_numeric( $value ) || is_bool( $value ) ) {
			return array( $value );
		}

		/*
		 * Attempt to determine if a string contains a comma separated list of
		 * values that should be split into an array of values for an __in type
		 * of query.
		 */
		if ( is_string( $value ) ) {

			// Bail if string is over 200s chars long.
			if ( strlen( $value ) > 200 ) {
				return array( $value );
			}

			// Contains comma?
			$comma = strpos( $value, ',' );

			// Bail if no comma.
			if ( false === $comma ) {
				return array( $value );
			}

			// Contains space?
			$space = strpos( $value, ' ' );

			// Bail if space is before comma.
			if ( ( false !== $space ) && ( $space < $comma ) ) {
				return array( $value );
			}

			// Bail if first comma is more than 20 letters in.
			if ( $comma >= 20 ) {
				return array( $value );
			}

			// Split by comma (and maybe spaces).
			return preg_split( '#,\s*#', $value, -1, PREG_SPLIT_NO_EMPTY );
		}

		// Pass the value through.
		return array( $value );
	}

	/**
	 * Normalize the caller's FULL query vars early, before parsing.
	 *
	 * Distinct from parse_query_vars(): that runs later, isolated to this parser's
	 * own var; THIS runs early, sees ALL query vars, and may rewrite cross-parser
	 * vars (e.g. translate a high-level directive into another parser's canonical
	 * var). The Query iterates its registered parser descriptors and threads the
	 * vars through each one's normalizer before the parse_{items}_query action, so
	 * the action and the SQL parsers see canonical vars. The default is a no-op.
	 *
	 * Implementations are pure: return the (possibly modified) query vars. To
	 * fail a query closed, return a 'query_filter_short_circuit' entry
	 * (array{source: string, reason: string}); the Query consumes and applies it.
	 *
	 * @since 3.1.0
	 * @internal Query/Parser collaborator API.
	 *
	 * @param array<string,mixed>           $query_vars All of the caller's query vars.
	 * @param \BerlinDB\Database\Kern\Query $caller     The Query being normalized.
	 * @return array<string,mixed> The (possibly modified) query vars.
	 */
	public function normalize_query_vars( array $query_vars, \BerlinDB\Database\Kern\Query $caller ): array {
		return $query_vars;
	}

	/**
	 * Sets the caller.
	 *
	 * @since 3.0.0
	 *
	 * @param \BerlinDB\Database\Kern\Query|null $caller The parent Query instance.
	 */
	protected function set_caller( mixed $caller = null ): void {
		$this->caller = $caller;
	}

	/**
	 * Sets the first-order keys to use.
	 *
	 * @since 3.0.0
	 *
	 * @param list<string> $first_keys Array of first-order keys.
	 */
	protected function set_first_keys( array $first_keys = array() ): void {
		$this->first_keys = $this->get_first_keys( $first_keys );
	}

	/**
	 * Populate $this->operators with one shared instance of Operator classes.
	 *
	 * Declared abstract here so that static analysis tools can see the
	 * dependency. Implemented in Parsers\Base as a concrete method so that
	 * the static cache is scoped to that class definition and shared across
	 * all subclasses (a true per-process singleton).
	 *
	 * @since 3.0.0
	 */
	abstract protected function set_operators(): void;

	/**
	 * Recursive-friendly query sanitizer.
	 *
	 * Ensures that each query-level clause has a 'relation' key, and that
	 * each first-order clause contains all the necessary keys from $defaults.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string|int,mixed> $queries Array of query clause arrays.
	 * @param array<string|int,mixed> $parent_query Parent query clause array.
	 *
	 * @return array<string|int,mixed> Sanitized queries.
	 */
	protected function sanitize_query( $queries = array(), $parent_query = array() ) {

		// Bail if bad queries.
		if ( empty( $queries ) || ! is_array( $queries ) ) {
			return array();
		}

		// Default return value.
		$retval = array();

		// Setup defaults.
		$defaults = $this->get_defaults();

		// Numeric keys should always have array values.
		foreach ( $queries as $qkey => $qvalue ) {
			if ( is_numeric( $qkey ) && ! is_array( $qvalue ) ) {
				unset( $queries[ $qkey ] );
			}
		}

		/*
		 * Each query should have a value for each default key.
		 *
		 * Inherit from the parent when possible.
		 */
		foreach ( $defaults as $dkey => $dvalue ) {

			// Skip if already set.
			if ( isset( $queries[ $dkey ] ) ) {
				continue;
			}

			// Set the query.
			$queries[ $dkey ] = isset( $parent_query[ $dkey ] )
				? $parent_query[ $dkey ]
				: $dvalue;
		}

		// Validate the values passed in the query.
		if ( $this->is_first_order_clause( $queries ) ) {
			$this->validate_values( $queries );
		}

		// Default empty relation.
		$relation = '';

		// Add queries to return array.
		foreach ( $queries as $key => $query ) {

			// Set relation.
			if ( 'relation' === $key ) {
				$relation = strtoupper( $query );
			}

			/*
			 * Non-array values and declared first-order keys pass through as-is.
			 *
			 * Trust the values and sanitize when building SQL.
			 */
			if ( ! is_array( $query ) || in_array( $key, $this->first_keys, true ) ) {
				$retval[ $key ] = $query;

				/*
				 * Arrays whose shape matches a first-order clause pass through as-is.
				 *
				 * Trust the values and sanitize when building SQL.
				 */
			} elseif ( $this->is_first_order_clause( $query ) ) {
				$retval[ $key ] = $query;

				/*
				 * Any array without a $first_key is another query, so we recurse.
				 */
			} else {
				$cleaned = $this->sanitize_query( $query, $queries );

				// Add non-empty queries only.
				if ( ! empty( $cleaned ) ) {
					$retval[ $key ] = $cleaned;
				}
			}
		}

		// Bail if nothing to do.
		if ( empty( $retval ) ) {
			return $retval;
		}

		// Sanitize the 'relation' key provided in the query.
		if ( 'OR' === $relation ) {
			$retval[ 'relation' ]  = 'OR';
			$this->has_or_relation = true;

			/*
			* If there is only a single clause, call the relation 'OR'.
			* This value will not actually be used to join clauses, but it
			* simplifies the logic around combining key-only queries.
			*/
		} elseif ( 1 === count( $retval ) ) {
			$retval[ 'relation' ] = 'OR';

			// Default to AND.
		} else {
			$retval[ 'relation' ] = 'AND';
		}

		// Return sanitized queries.
		return $retval;
	}

	/**
	 * Determine if this is a first-order clause.
	 *
	 * If it includes anything from $first_keys.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query Query clause.
	 *
	 * @return bool True if this is a first-order clause.
	 */
	protected function is_first_order_clause( $query = array() ) {
		return (bool) $this->get_first_order_clauses( $query );
	}

	/**
	 * Get the intersection of first-order keys in the $query keys.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query Query clause.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_first_order_clauses( $query = array() ) {

		// Bail if empty.
		if ( empty( $query ) || empty( $this->first_keys ) ) {
			return array();
		}

		// Get intersection.
		$intersect = array_intersect( $this->first_keys, array_keys( $query ) );

		// Bail if no intersection.
		if ( empty( $intersect ) ) {
			return array();
		}

		// Get keys & clauses.
		$retval = array_intersect_key( $query, array_flip( $intersect ) );

		return $retval;
	}

	/**
	 * Resolve an operator's logical opposite to a registered instance.
	 *
	 * Looks the opposite up by its $compare through the same registry as every
	 * other operator, so the result is the live, filtered singleton - honoring
	 * any berlindb_database_operator_classes customization - not a throwaway
	 * default. Callers that only need the opposite's identifier should read
	 * Operators\Base::get_opposite_compare() directly; this is for callers that
	 * need the full object (its $sql_compare, $multi, custom behavior, etc.).
	 *
	 * @since 3.1.0
	 *
	 * @param \BerlinDB\Database\Operators\Base $operator The operator to invert.
	 *
	 * @return \BerlinDB\Database\Operators\Base|false The opposite operator, or
	 *         false when the operator declares no opposite (e.g. 'RLIKE') or that
	 *         opposite is not in the filtered registry.
	 */
	protected function get_opposite_operator( \BerlinDB\Database\Operators\Base $operator ) {
		$compare = $operator->get_opposite_compare();

		return ( '' !== $compare )
			? $this->get_operator( $compare )
			: false;
	}

	/**
	 * Determines and validates the default values for a query or subquery.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query A query or subquery.
	 *
	 * @return array<string,mixed> The comparison operator.
	 */
	protected function get_defaults( $query = array() ) {
		return array(
			'now'           => $this->get_now( $query ),
			'column'        => $this->get_column( $query ),
			'compare'       => $this->get_compare( $query ),
			'relation'      => $this->get_relation( $query ),
			'start_of_week' => $this->get_start_of_week( $query ),
		);
	}

	/**
	 * Determines and validates the table alias for this query context.
	 *
	 * Uses an explicit alias if provided, otherwise falls back to the caller
	 * query alias.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query A query or subquery.
	 *
	 * @return string
	 */
	protected function get_table_alias( $query = array() ) {

		if ( ! empty( $query[ 'alias' ] ) ) {
			$alias = $this->sanitize_table_alias( $query[ 'alias' ] );

			return ! empty( $alias )
				? esc_sql( $alias )
				: '';
		}

		$alias = $this->caller?->get_table_alias();

		if ( ! empty( $alias ) ) {
			$alias = $this->sanitize_table_alias( $alias );

			return ! empty( $alias )
				? esc_sql( $alias )
				: '';
		}

		return '';
	}

	/**
	 * Determines and validates which column to use.
	 *
	 * Use column if passed.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query A query or subquery.
	 *
	 * @return string The comparison operator.
	 */
	protected function get_column( $query = array() ) {

		// If a column is passed, sanitize and return it.
		if ( ! empty( $query[ 'column' ] ) ) {

			// Sanitize the column name.
			$sanitized = $this->sanitize_column_name( $query[ 'column' ] );

			// Return.
			return $sanitized
				? esc_sql( $sanitized )
				: $this->column;
		}

		return $this->column;
	}

	/**
	 * Resolve a schema column to its backtick-quoted, alias-prefixed SQL name.
	 *
	 * Looks up the column by name, optionally restricting the match to columns
	 * that satisfy $filter (e.g. array('date_query' => true)). Returns an empty
	 * string when the column doesn't exist or doesn't match the filter, so callers
	 * can use empty() as a bail condition without a separate isset check.
	 *
	 * @since 3.0.0
	 *
	 * @param string              $name   Column name to look up.
	 * @param array<string,mixed> $filter Optional. Additional column attributes to match. Default empty.
	 * @param bool                $alias  Optional. Whether to prefix with the table alias. Default true.
	 *
	 * @return string Backtick-quoted SQL reference, or empty string on failure.
	 */
	protected function get_column_sql( string $name, array $filter = array(), bool $alias = true ): string {

		// Look up the column, merging any extra filter criteria.
		$col = $this->caller?->get_column_by( array_merge( array( 'name' => $name ), $filter ) );

		// Bail if the column doesn't exist or doesn't match the filter.
		if ( ! $col instanceof \BerlinDB\Database\Kern\Column ) {
			return '';
		}

		// Resolve the table alias when requested.
		$table_alias = $alias
			? ( $this->caller->get_table_alias() ?? '' )
			: '';

		// Return the qualified column name.
		return $col->get_name_sql( $table_alias );
	}

	/**
	 * Resolve a schema column's prepare() pattern.
	 *
	 * @since 3.1.0
	 *
	 * @param string $name     Column name to look up.
	 * @param string $fallback Optional. Pattern to use when the column has none. Default '%s'.
	 * @return string The column pattern, or fallback.
	 */
	protected function get_column_pattern( string $name, string $fallback = '%s' ): string {
		$pattern = $this->caller?->get_column_field( array( 'name' => $name ), 'pattern', $fallback );

		return ( is_string( $pattern ) && ( '' !== $pattern ) )
			? $pattern
			: $fallback;
	}

	/**
	 * Determines and validates which comparison operator to use.
	 *
	 * Compare must be in the $comparison_keys array.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query A query or a subquery.
	 *
	 * @return string The comparison operator.
	 */
	protected function get_compare( $query = array() ) {

		/*
		 * Exclude unary predicates (IS NULL / IS NOT NULL). Callers of this
		 * method build `{column} {compare} {value}` and have no value-less
		 * path, so a unary compare falls back to the default. Parsers that DO
		 * support unary operators (Compare, Relationship) route through
		 * operator->get_sql() instead of this method.
		 */
		$comparison_keys = $this->get_operators( array( 'unary' => false ) );

		return ! empty( $query[ 'compare' ] ) && in_array( $query[ 'compare' ], $comparison_keys, true )
			? strtoupper( $query[ 'compare' ] )
			: $this->compare;
	}

	/**
	 * Determines and validates which relation to use.
	 *
	 * Relation must be in the $relation_keys array.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query A query or a subquery.
	 *
	 * @return string The relation operator.
	 */
	protected function get_relation( $query = array() ) {
		return ! empty( $query[ 'relation' ] ) && in_array( $query[ 'relation' ], $this->relation_keys, true )
			? strtoupper( $query[ 'relation' ] )
			: $this->relation;
	}

	/**
	 * Determines and validates what the current UNIX timestamp is.
	 *
	 * Use now if passed, or time().
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query A date query or a date subquery.
	 *
	 * @return int The current UNIX timestamp.
	 */
	protected function get_now( $query = array() ) {
		return ! empty( $query[ 'now' ] ) && is_numeric( $query[ 'now' ] )
			? (int) $query[ 'now' ]
			: time();
	}

	/**
	 * Determines and validates what start_of_week to use.
	 *
	 * Use start of week if passed and valid.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query A date query or a date subquery.
	 *
	 * @return int The start of the week.
	 */
	protected function get_start_of_week( $query = array() ) {

		// Look for start_of_week in the query.
		$start = isset( $query[ 'start_of_week' ] )
			? $query[ 'start_of_week' ]
			: null;

		// Return the start of week.
		return ( null !== $start && is_numeric( $start ) && 6 >= (int) $start && 0 <= (int) $start )
			? (int) $start
			: (int) $this->start_of_week;
	}

	/**
	 * Determines and validates what first-order keys to use.
	 *
	 * Declared abstract here (like set_operators()) because the meaningful
	 * default lives on Parsers\Base, which derives per-column keys from the
	 * column_filter / column_suffix descriptor it owns. The trait is the
	 * column-agnostic engine and must not reach into that config, so it states
	 * the dependency and lets the consumer (Base, and subclasses such as
	 * Compare/Date/Meta) provide the implementation. set_first_keys() calls it.
	 *
	 * @since 3.0.0
	 *
	 * @param list<string> $first_keys Array of first-order keys.
	 *
	 * @return list<string> The first-order keys.
	 */
	abstract protected function get_first_keys( $first_keys = array() );

	/**
	 * Generates SQL clauses to be appended to a main query.
	 *
	 * The $type, $primary_table, and $primary_column parameters are preserved
	 * from Berlin 2.1.0 for backwards compatibility. Subclasses that override
	 * this method (e.g. Meta) act on the parameters, so the signature must
	 * remain consistent across the hierarchy.
	 *
	 * New code targeting Berlin 3.0.0 and later should call get_join_where_clauses()
	 * instead, which carries no legacy parameter baggage. The Query class itself was
	 * updated in 3.0.0 to use that method internally.
	 *
	 * @since 2.1.0
	 * @deprecated 3.0.0 Use get_join_where_clauses() instead.
	 *
	 * @param string $type           Optional. Object type (e.g. 'post', 'comment'). Unused at this
	 *                               level; accepted for BC and for subclass overrides. Default ''.
	 * @param string $primary_table  Optional. Primary table for the object being filtered. Unused at
	 *                               this level; accepted for BC and for subclass overrides. Default ''.
	 * @param string $primary_column Optional. Column in $primary_table that holds the object ID. Unused
	 *                               at this level; accepted for BC and for subclass overrides. Default ''.
	 *
	 * @return array{join: string, where: string} {
	 *     Array containing JOIN and WHERE SQL clauses to append to the main query,
	 *     or false if no table exists for the requested type.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	public function get_sql( $type = '', $primary_table = '', $primary_column = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $this->get_join_where_clauses();
	}

	/**
	 * Build an ORDER BY SQL fragment for a given orderby value.
	 *
	 * Called by Query::parse_single_orderby() for each registered parser.
	 * Subclasses may override this to handle orderby values that belong to
	 * their domain (e.g. the In parser handles '{column}__in' -> FIELD()).
	 * The default is a no-op.
	 *
	 * @since 3.0.0
	 * @internal Query/Parser collaborator API.
	 *
	 * @param string $orderby The raw orderby value.
	 * @param bool   $alias   Whether to prefix with the table alias.
	 * @return string SQL fragment, or empty string if this parser does not handle $orderby.
	 */
	public function get_orderby_sql( $orderby = '', $alias = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return '';
	}

	/**
	 * Generates SQL clauses to be appended to a main query.
	 *
	 * The preferred method for new code in 3.0.0 and later. Carries no legacy parameter
	 * baggage - all context is derived from the parser state set at construction time.
	 *
	 * Subclasses that need to perform setup before SQL is generated should override this
	 * method rather than get_sql().
	 *
	 * @since 3.0.0
	 * @internal Query/Parser collaborator API.
	 *
	 * @return array{join: string, where: string} {
	 *     Array containing JOIN and WHERE SQL clauses to append to the main query,
	 *     or false if no table exists for the requested type.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	public function get_join_where_clauses() {

		// Get the SQL clauses.
		$retval = $this->get_sql_clauses();

		/*
		 * If any JOINs are LEFT JOINs (as in the case of NOT EXISTS) then all
		 * JOINs should be LEFT. Otherwise items with no values will be excluded
		 * from results.
		 */
		if ( false !== strpos( $retval[ 'join' ], 'LEFT JOIN' ) ) {
			$retval[ 'join' ] = str_replace( 'INNER JOIN', 'LEFT JOIN', $retval[ 'join' ] );
		}

		// Return join/where array.
		return $retval;
	}

	/**
	 * Generate SQL clauses to be appended to a main query.
	 *
	 * Called by the public get_sql(), this method is abstracted
	 * out to maintain parity with the other Query classes.
	 *
	 * @since 3.0.0
	 *
	 * @return array{join: string, where: string} {
	 *     Array containing JOIN and WHERE SQL clauses to append to the main query.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	protected function get_sql_clauses() {

		// Get SQL join/where array.
		$queries = $this->queries;
		$retval  = $this->get_sql_for_query( $queries );

		// Maybe prefix 'where' with " AND ".
		if ( ! empty( $retval[ 'where' ] ) ) {
			$retval[ 'where' ] = ' AND ' . $retval[ 'where' ];
		}

		// Return join/where array.
		return $retval;
	}

	/**
	 * Generate SQL clauses for a single query array.
	 *
	 * If nested subqueries are found, this method recurses the tree to
	 * produce the properly nested SQL.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string|int,mixed> $query Query to parse.
	 * @param int                     $depth Optional. Number of tree levels deep we currently are. Default 0.
	 * @return array{join: string, where: string}
	 */
	protected function get_sql_for_query( &$query = array(), $depth = 0 ) {

		// SQL parts.
		$sql = array(
			'join'  => array(),
			'where' => array(),
		);

		// Default return values.
		$retval = array(
			'join'  => '',
			'where' => '',
		);

		// Default strings.
		$indent   = '';
		$relation = '';

		// Set indentation using depth.
		for ( $i = 0; $i < $depth; $i++ ) {
			$indent .= '  ';
		}

		// Bail if no query.
		if ( empty( $query ) ) {
			return $retval;
		}

		// Loop through query keys & clauses.
		foreach ( $query as $key => &$clause ) {

			// Set $relation if set.
			if ( 'relation' === $key ) {
				$relation = $query[ 'relation' ];
			}

			if ( is_array( $clause ) ) {

				// This is a first-order clause.
				if ( $this->is_first_order_clause( $clause ) ) {

					// Get clauses, then combine them (none -> '', one -> bare, many -> AND group).
					$clause_sql       = $this->get_sql_for_clause( $clause, $query, $key );
					$sql[ 'where' ][] = ( new \BerlinDB\Database\Clauses\BooleanGroup(
						array(
							'relation' => 'AND',
							'items'    => array_values( $clause_sql[ 'where' ] ),
						)
					) )->get_sql();

					// Merge joins.
					$sql[ 'join' ] = array_merge( $sql[ 'join' ], $clause_sql[ 'join' ] );

					// This is a subquery, so we recurse.
				} else {
					$clause_sql = $this->get_sql_for_query( $clause, $depth + 1 );

					// Add clauses to SQL.
					$sql[ 'join' ][]  = $clause_sql[ 'join' ];
					$sql[ 'where' ][] = $clause_sql[ 'where' ];
				}
			}
		}

		// Filter to remove empties.
		$sql[ 'join' ]  = array_filter( $sql[ 'join' ] );
		$sql[ 'where' ] = array_filter( $sql[ 'where' ] );

		// Default relation.
		if ( empty( $relation ) || ! is_string( $relation ) ) {
			$relation = 'AND';
		}

		// Remove duplicate JOIN clauses, and combine into a single string.
		if ( ! empty( $sql[ 'join' ] ) ) {
			$retval[ 'join' ] = implode( ' ', array_unique( $sql[ 'join' ] ) );
		}

		// Generate a single WHERE clause with proper brackets and indentation.
		if ( ! empty( $sql[ 'where' ] ) ) {
			$retval[ 'where' ] = '( ' . "\n  {$indent}" . implode( " \n  {$indent}{$relation} \n  {$indent}", $sql[ 'where' ] ) . "\n{$indent}" . ')';
		}

		// Return join/where array.
		return $retval;
	}

	/**
	 * Generate SQL for a query clause.
	 *
	 * Default Column-aware implementation. Validates the clause key against the
	 * schema, derives quoting and pattern from the Column object, and delegates
	 * full expression assembly to the operator. Concrete parsers should override
	 * this method when they require specialized JOIN logic, type casting, or
	 * column filtering beyond what the schema lookup provides.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed>     $clause       Query clause (passed by reference).
	 * @param array<int|string,mixed> $parent_query Parent query array.
	 * @param int|string              $clause_key   Optional. The array key used to name the clause.
	 * @return array{join: list<string>, where: list<string>}
	 */
	protected function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		// Default return value.
		$retval = array(
			'join'  => array(),
			'where' => array(),
		);

		// Maybe format compare clause.
		if ( isset( $clause[ 'compare' ] ) ) {
			$clause[ 'compare' ] = strtoupper( $clause[ 'compare' ] );

			// Or set compare clause based on value.
		} else {
			$clause_value = $clause[ 'value' ] ?? null;

			/*
			 * A list value defaults to IN, but a structured operand spec is not a
			 * list - it defaults to equality (e.g. column-to-column `=`).
			 */
			$clause[ 'compare' ] = ( is_array( $clause_value ) && ! $this->is_operand_spec( $clause_value ) )
				? 'IN'
				: '=';
		}

		// Get all comparison operators.
		$all_compares = $this->get_operators();

		// Fallback to equals.
		if ( ! in_array( $clause[ 'compare' ], $all_compares, true ) ) {
			$clause[ 'compare' ] = '=';
		}

		// Get comparison from clause.
		$compare  = $clause[ 'compare' ];
		$operator = $this->get_operator( $compare );

		// Fallback to Equal for any unrecognized compare string.
		if ( false === $operator ) {
			$operator = $this->get_operator( '=' );
		}

		// Bail if no valid operator could be resolved.
		if ( false === $operator ) {
			return $retval;
		}

		/** Build the WHERE clause */

		// Column object and value. Unary operators (IS NULL) need no value.
		if ( array_key_exists( 'key', $clause ) && ( array_key_exists( 'value', $clause ) || $operator->is_unary() ) ) {

			$alias            = $this->caller?->get_table_alias() ?? '';
			$key              = $clause[ 'key' ];
			$value            = $clause[ 'value' ] ?? null;
			$value_is_operand = $this->is_operand_spec( $value );

			// A structured left-hand operand (column / function) on the 'key'.
			if ( $this->is_operand_spec( $key ) ) {

				// Resolve against this caller's own schema (same-table operand).
				$lhs = $this->resolve_operand( $key, $this->caller, $alias );

				// Fail closed if the left operand spec was unresolvable.
				if ( ! ( $lhs instanceof \BerlinDB\Database\Operands\Base ) ) {
					return $this->unresolved_column_clause( $retval );
				}

				$expr = $this->build_operand_clause( $lhs, $operator, $value, $value_is_operand, $this->caller, $alias );

				// Fail closed if the comparison could not be built.
				if ( false === $expr ) {
					return $this->unresolved_column_clause( $retval );
				}

				// Or a bare column-name 'key'.
			} else {

				$name = $this->sanitize_column_name( $key );

				// Key doesn't sanitize to a valid column name.
				if ( empty( $name ) ) {
					return $this->unresolved_column_clause( $retval );
				}

				// Column doesn't exist in the schema.
				$col = $this->caller?->get_column_by( array( 'name' => $name ) );

				if ( empty( $col ) ) {
					return $this->unresolved_column_clause( $retval );
				}

				/*
				 * Resolve an optional, opt-in CAST for the column side. An explicit
				 * but invalid cast fails the clause closed (matches no rows) rather
				 * than silently comparing lexically.
				 */
				$cast = $this->resolve_clause_sql_cast( $col, $clause );

				if ( false === $cast ) {
					return $this->unresolved_column_clause( $retval );
				}

				// A unary operator or a structured value enters the operand path.
				if ( $operator->is_unary() || $value_is_operand ) {

					$lhs  = new \BerlinDB\Database\Operands\Column(
						array(
							'column' => $col,
							'alias'  => $alias,
							'cast'   => $cast,
						)
					);
					$expr = $this->build_operand_clause( $lhs, $operator, $value, $value_is_operand, $this->caller, $alias );

					// Fail closed if the comparison could not be built.
					if ( false === $expr ) {
						return $this->unresolved_column_clause( $retval );
					}

					// Bare key + bare value: the ordinary operator value path (unchanged).
				} else {
					$expr = $operator->get_sql( $col, $alias, $value, $cast );
				}
			}

			// Maybe add the WHERE expression.
			if ( ! empty( $expr ) ) {
				$retval[ 'where' ][] = $expr;
			}
		}

		// Multiple WHERE clauses should be joined in parentheses.
		if ( 1 < count( $retval[ 'where' ] ) ) {
			$retval[ 'where' ] = array( '( ' . implode( ' AND ', $retval[ 'where' ] ) . ' )' );
		}

		// Return join/where array.
		return $retval;
	}

	/**
	 * Fail a clause closed when its column cannot be resolved.
	 *
	 * A first-order clause reaches this point only because it requested a filter,
	 * yet its key names no real column (a typo, or a column absent from this
	 * schema). Emitting a never-true condition makes the clause match no rows -
	 * rather than dropping it, which would silently WIDEN results to every row, a
	 * dangerous outcome that is never the caller's intent.
	 *
	 * @since 3.1.0
	 *
	 * @param array{join: list<string>, where: list<string>} $retval The in-progress (empty) clause result.
	 * @return array{join: list<string>, where: list<string>}
	 */
	protected function unresolved_column_clause( array $retval ) {

		// Never-true condition: an unresolvable column matches nothing.
		$retval[ 'where' ][] = '1 = 0';

		return $retval;
	}

	/**
	 * Resolve an optional, opt-in CAST target for a clause's column side.
	 *
	 * Shared by get_sql_for_clause() and the Relationship parser so both read the
	 * 'cast' clause key the same way. 'cast' => true derives the target from the
	 * column's own declared type; a non-empty string is an explicit override,
	 * validated via sanitize_sql_cast_type(). Absent, false, null, or empty means
	 * no cast - casting is never applied by default.
	 *
	 * An explicit but invalid string is a misconfiguration: it returns false so
	 * the caller fails the clause closed (match no rows), rather than silently
	 * comparing lexically. A misspelled 'SIGNED' should never widen to an uncast
	 * compare.
	 *
	 * @since 3.1.0
	 * @internal Query/Parser collaborator API.
	 *
	 * @param \BerlinDB\Database\Kern\Column $column The column being compared.
	 * @param array<string,mixed>            $clause The clause, possibly carrying a 'cast' key.
	 * @return string|false The CAST target ('' when none), or false when an
	 *                      explicit cast is invalid (caller must fail closed).
	 */
	protected function resolve_clause_sql_cast( \BerlinDB\Database\Kern\Column $column, array $clause ) {

		// Read the opt-in directive; absent, false, and null all mean "no cast".
		$requested = $clause[ 'cast' ] ?? null;

		// 'cast' => true derives the target from the column's own declared type.
		if ( true === $requested ) {
			return $column->get_sql_cast_type();
		}

		// A non-empty string is an explicit, validated override.
		if ( is_string( $requested ) && ( '' !== trim( $requested ) ) ) {
			$cast = $this->sanitize_sql_cast_type( $requested );

			/*
			 * An explicit but invalid cast is a misconfiguration: signal the
			 * caller to fail closed, rather than falling back to no cast and
			 * comparing lexically.
			 */
			return ( '' === $cast )
				? false
				: $cast;
		}

		// No cast requested.
		return '';
	}

	/**
	 * Whether a clause value is a structured operand spec (vs a scalar or list).
	 *
	 * A structured operand is an associative array carrying an explicit 'operand'
	 * marker - e.g. `array( 'operand' => 'column', 'name' => 'last_name' )`. A bare
	 * scalar, or a numeric-keyed list (an IN list), is NOT an operand spec and is
	 * handled by the ordinary value path, so existing queries are unaffected.
	 *
	 * Classification is by KEY PRESENCE, not value: a present-but-null/invalid
	 * marker (e.g. `array( 'operand' => null )` from decoded JSON) is still an
	 * operand spec, so it reaches resolve_operand() and fails closed rather than
	 * slipping back into the ordinary IN/scalar path against the marker fields.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value The clause value to inspect.
	 * @return bool
	 */
	protected function is_operand_spec( $value ): bool {
		return is_array( $value ) && array_key_exists( 'operand', $value );
	}

	/**
	 * Resolve a structured operand spec into a renderable Operand, or fail closed.
	 *
	 * The right-hand-side counterpart of resolve_clause_sql_cast(): turns an
	 * explicit operand spec into an Operand value object the operator renders in
	 * place of a prepared scalar. Returns null when $value is not an operand spec
	 * (the caller uses the ordinary value path), or false when the spec is present
	 * but unresolvable (the caller must fail the clause closed).
	 *
	 * Supported operand kinds: `column` (a column reference, optional opt-in
	 * `cast`, validated against $source's schema), `value` (a prepared literal -
	 * mainly for nesting inside a function), and `func` (an allow-listed SQL
	 * function wrapping recursive argument operands). An unknown column, unknown
	 * function, bad arity, or disallowed argument kind all fail closed. The
	 * referenced column(s) are qualified with $alias, which the caller supplies
	 * from known query/relationship state - a caller-supplied alias string is
	 * never trusted here.
	 *
	 * @since 3.1.0
	 * @internal Query/Parser collaborator API.
	 *
	 * @param mixed                              $value  The clause value (possibly an operand spec).
	 * @param \BerlinDB\Database\Kern\Query|null $source The Query whose schema owns the referenced column.
	 * @param string                             $alias  Optional. Table alias to qualify the reference.
	 * @return \BerlinDB\Database\Operands\Base|false|null Operand on success; false to fail closed;
	 *                                                     null when $value is not an operand spec.
	 */
	protected function resolve_operand( $value, $source, string $alias = '' ) {

		// Not a structured operand spec: caller uses the ordinary value path.
		if ( ! $this->is_operand_spec( $value ) ) {
			return null;
		}

		// Normalize the operand kind.
		$kind = is_string( $value[ 'operand' ] )
			? strtolower( $value[ 'operand' ] )
			: '';

		// Dispatch by kind; an unknown kind fails closed.
		switch ( $kind ) {
			case 'column':
				return $this->resolve_column_operand( $value, $source, $alias );

			case 'value':
				return $this->resolve_value_operand( $value );

			case 'func':
				return $this->resolve_func_operand( $value, $source, $alias );

			default:
				return false;
		}
	}

	/**
	 * Resolve a `column` operand spec into a Column operand, or false (fail closed).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed>                $value  The operand spec.
	 * @param \BerlinDB\Database\Kern\Query|null $source The Query whose schema owns the column.
	 * @param string                             $alias  Table alias to qualify the reference.
	 * @return \BerlinDB\Database\Operands\Base|false
	 */
	private function resolve_column_operand( array $value, $source, string $alias ) {

		// Sanitize the referenced column name.
		$raw_name = $value[ 'name' ] ?? '';
		$name     = is_string( $raw_name )
			? $this->sanitize_column_name( $raw_name )
			: '';

		// Bail if the name doesn't sanitize to a valid column name.
		if ( '' === $name ) {
			return false;
		}

		// The referenced column must exist in the source schema.
		$column = ( $source instanceof \BerlinDB\Database\Kern\Query )
			? $source->get_column_by( array( 'name' => $name ) )
			: false;

		// Bail if the column doesn't exist in the schema.
		if ( ! ( $column instanceof \BerlinDB\Database\Kern\Column ) ) {
			return false;
		}

		// Resolve an optional, opt-in cast against the REFERENCED column's type.
		$cast = $this->resolve_clause_sql_cast( $column, $value );

		// Bail if an explicit but invalid cast was requested.
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
	 * A value operand carries a scalar that is prepared (via wpdb::prepare) here,
	 * so the resulting object holds an already-safe fragment. An optional
	 * `pattern` ('%s', '%d', or '%f') selects the placeholder; anything else
	 * defaults to a string placeholder.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $value The operand spec.
	 * @return \BerlinDB\Database\Operands\Base|false
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

		return new \BerlinDB\Database\Operands\Value( array( 'sql' => $prepared ) );
	}

	/**
	 * Resolve a `func` operand spec into a Func operand, or false (fail closed).
	 *
	 * The function name must be in the Func allow-list, the argument count within
	 * its declared arity, and every argument must resolve as an operand of an
	 * allowed kind. A column argument's declared type category must also be one
	 * the function accepts. Arguments recurse through resolve_operand(), so
	 * functions nest.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed>                $value  The operand spec.
	 * @param \BerlinDB\Database\Kern\Query|null $source The Query whose schema owns any column arguments.
	 * @param string                             $alias  Table alias to qualify column arguments.
	 * @return \BerlinDB\Database\Operands\Base|false
	 */
	private function resolve_func_operand( array $value, $source, string $alias ) {

		// The function must be in the allow-list.
		$name       = is_string( $value[ 'name' ] ?? null ) ? $value[ 'name' ] : '';
		$descriptor = \BerlinDB\Database\Operands\Func::descriptor( $name );

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
			$arg = $this->resolve_operand_argument( $arg_spec, $descriptor[ 'arg_kinds' ], $source, $alias );

			if ( ! ( $arg instanceof \BerlinDB\Database\Operands\Base ) ) {
				return false;
			}

			/*
			 * Schema-informed type check: a column argument's declared category
			 * must be one the function accepts (e.g. YEAR() rejects a numeric
			 * column). Literal and nested-function arguments are not type-checked -
			 * MySQL coerces freely, so this rejects obvious misuse, not everything.
			 */
			if ( ( $arg instanceof \BerlinDB\Database\Operands\Column ) && ! in_array( $arg->get_type_category(), $descriptor[ 'accepts' ], true ) ) {
				return false;
			}

			$resolved[] = $arg;
		}

		return new \BerlinDB\Database\Operands\Func(
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
	 * A bare scalar is value-operand sugar (allowed only when the function accepts
	 * a `value` argument). A structured spec must name one of the function's
	 * allowed argument kinds, then resolves through resolve_operand().
	 *
	 * @since 3.1.0
	 *
	 * @param mixed                              $arg_spec  The argument spec (scalar or operand spec).
	 * @param list<string>                       $arg_kinds The operand kinds this function accepts.
	 * @param \BerlinDB\Database\Kern\Query|null $source    The Query whose schema owns any column arguments.
	 * @param string                             $alias     Table alias to qualify column arguments.
	 * @return \BerlinDB\Database\Operands\Base|false
	 */
	private function resolve_operand_argument( $arg_spec, array $arg_kinds, $source, string $alias ) {

		// A bare scalar is value-operand sugar, when the function accepts a value.
		if ( ! $this->is_operand_spec( $arg_spec ) ) {

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

		$operand = $this->resolve_operand( $arg_spec, $source, $alias );

		return ( $operand instanceof \BerlinDB\Database\Operands\Base )
			? $operand
			: false;
	}

	/**
	 * Assemble a two-operand comparison: `{lhs} {compare} {rhs}`.
	 *
	 * Both sides are resolved operands (a bare column key becomes a Column
	 * operand; a structured key/value resolves to any operand). The parser owns
	 * this - rather than the operator - because operand rendering is uniform
	 * across the scalar comparison operators (the operator contributes only its
	 * SQL compare string), whereas value rendering is operator-specific (IN lists,
	 * BETWEEN pairs, LIKE wildcards). It also avoids widening the public operator
	 * get_sql() signature, which would fatally break custom operator overrides
	 * registered via berlindb_database_operator_classes.
	 *
	 * @since 3.1.0
	 *
	 * @param \BerlinDB\Database\Operands\Base $lhs         The left-hand operand.
	 * @param string                           $sql_compare The operator's SQL compare string (get_sql_compare()).
	 * @param \BerlinDB\Database\Operands\Base $rhs         The right-hand operand.
	 * @return string The assembled comparison SQL, or '' when either side renders nothing.
	 */
	protected function build_comparison_sql( \BerlinDB\Database\Operands\Base $lhs, string $sql_compare, \BerlinDB\Database\Operands\Base $rhs ): string {

		$lhs_sql = $lhs->get_sql();
		$rhs_sql = $rhs->get_sql();

		// Bail if either side rendered nothing.
		if ( ( '' === $lhs_sql ) || ( '' === $rhs_sql ) ) {
			return '';
		}

		return $lhs_sql . ' ' . $sql_compare . ' ' . $rhs_sql;
	}

	/**
	 * Assemble a unary comparison: `{lhs} {compare}` (e.g. `{col} IS NULL`).
	 *
	 * A unary operator takes no right-hand operand. The left side is a resolved
	 * operand - a bare column or a structured expression.
	 *
	 * @since 3.1.0
	 *
	 * @param \BerlinDB\Database\Operands\Base $lhs         The left-hand operand.
	 * @param string                           $sql_compare The operator's SQL compare string (get_sql_compare()).
	 * @return string The assembled unary SQL, or '' when the operand renders nothing.
	 */
	protected function build_unary_sql( \BerlinDB\Database\Operands\Base $lhs, string $sql_compare ): string {

		$lhs_sql = $lhs->get_sql();

		// Bail if the operand rendered nothing.
		if ( '' === $lhs_sql ) {
			return '';
		}

		return $lhs_sql . ' ' . $sql_compare;
	}

	/**
	 * Build a comparison from a resolved left-hand operand, or false (fail closed).
	 *
	 * Shared by the compare and relationship clause builders once the left side is
	 * an operand - a structured 'key', or a bare column wrapped as a Column
	 * operand. Three cases:
	 *
	 * - unary operator (`IS NULL`) -> `{lhs} {compare}` (no right-hand side);
	 * - a STRUCTURED right-hand operand (column-to-column, function = function) ->
	 *   limited to the scalar expression operators, paired as two operands;
	 * - a BARE right-hand value -> paired with the OPERATOR's own value rendering
	 *   (`get_value_sql`), so `IN` / `BETWEEN` / `LIKE` / `REGEXP` / scalar all work
	 *   uniformly: the operator owns the value fragment, the parser owns the LHS.
	 *
	 * @since 3.1.0
	 *
	 * @param \BerlinDB\Database\Operands\Base   $lhs              The resolved left-hand operand.
	 * @param \BerlinDB\Database\Operators\Base  $operator         The resolved operator.
	 * @param mixed                              $value            The clause value (operand spec or scalar; absent for unary).
	 * @param bool                               $value_is_operand Whether $value is a structured operand spec.
	 * @param \BerlinDB\Database\Kern\Query|null $source           The Query whose schema owns any value-side column.
	 * @param string                             $alias            Table alias to qualify any value-side column.
	 * @return string|false The comparison SQL (possibly ''), or false to fail the clause closed.
	 */
	protected function build_operand_clause( \BerlinDB\Database\Operands\Base $lhs, \BerlinDB\Database\Operators\Base $operator, $value, bool $value_is_operand, $source, string $alias ) {

		// A unary operator (IS NULL) takes no right-hand side.
		if ( $operator->is_unary() ) {
			return $this->build_unary_sql( $lhs, $operator->get_sql_compare() );
		}

		/*
		 * A structured right-hand operand (column-to-column, function = function)
		 * pairs two operands and is limited to the scalar expression operators.
		 */
		if ( $value_is_operand ) {

			if ( ! $operator->is_expression() ) {
				return false;
			}

			$rhs = $this->resolve_operand( $value, $source, $alias );

			return ( $rhs instanceof \BerlinDB\Database\Operands\Base )
				? $this->build_comparison_sql( $lhs, $operator->get_sql_compare(), $rhs )
				: false;
		}

		/*
		 * A bare right-hand value pairs the operand with the operator's own value
		 * rendering (scalar / IN / BETWEEN / LIKE / REGEXP), prepared with the left
		 * operand's comparison pattern. EXISTS renders as equality here exactly as
		 * it does on a plain column; a value-less operator (NOT EXISTS) yields ''.
		 */
		return $this->build_operand_value_sql( $lhs, $operator, $value );
	}

	/**
	 * Assemble `{operand} {compare} {operator-rendered value}` for a bare value.
	 *
	 * Pairs an operand left-hand side with the operator's existing value renderer
	 * (`get_value_sql`), so the operator keeps owning the value fragment - IN
	 * parens, BETWEEN `AND`, LIKE wildcards, prepared scalars - while the parser
	 * supplies the (possibly function/column) left side.
	 *
	 * @since 3.1.0
	 *
	 * @param \BerlinDB\Database\Operands\Base  $lhs      The left-hand operand.
	 * @param \BerlinDB\Database\Operators\Base $operator The resolved operator.
	 * @param mixed                             $value    The bare right-hand value(s).
	 * @return string The comparison SQL, or '' when the operator renders no value side.
	 */
	protected function build_operand_value_sql( \BerlinDB\Database\Operands\Base $lhs, \BerlinDB\Database\Operators\Base $operator, $value ): string {

		// Narrow the left operand's pattern to a known placeholder for prepare().
		$pattern = $lhs->get_comparison_pattern();
		$pattern = in_array( $pattern, array( '%s', '%d', '%f' ), true )
			? $pattern
			: '%s';

		// The operator renders its value fragment, typed by the left operand.
		$value_sql = $operator->get_value_sql( $value, $pattern );

		// A value-less operator (e.g. NOT EXISTS) yields nothing.
		if ( '' === $value_sql ) {
			return '';
		}

		return $lhs->get_sql() . ' ' . $operator->get_sql_compare() . ' ' . $value_sql;
	}

	/**
	 * Validates the given query values.
	 *
	 * @since 3.0.0
	 * @param array<string,mixed> $query The query array.
	 * @return bool True if all values in the query are valid, false if one or
	 *              more fail.
	 */
	protected function validate_values( $query = array() ) {

		// A non-empty query is valid by default; subclasses (e.g. Date) tighten this.
		return ! empty( $query );
	}

	/** Builders **************************************************************/

	/**
	 * Builds and validates a value string based on the comparison operator.
	 *
	 * Accepts any type: arrays are filtered to numeric values, scalars are cast
	 * to int, and non-numeric or null inputs return false. Callers do not need
	 * to pre-narrow their values before calling this method.
	 *
	 * @since 3.0.0
	 *
	 * @param string $compare The compare operator to use.
	 * @param mixed  $value   The value. Any type accepted; non-numeric values are filtered out.
	 *
	 * @return string|bool|int The value to be used in SQL or false on error.
	 */
	protected function build_numeric_value( $compare = '=', $value = null ) {

		// Bail if null value.
		if ( is_null( $value ) ) {
			return false;
		}

		// Cast to array.
		$value = (array) $value;

		// Remove non-numeric values.
		$value = array_filter( $value, 'is_numeric' );

		// Bail if no values.
		if ( empty( $value ) ) {
			return false;
		}

		// Map to ints.
		$values = array_map( 'intval', $value );

		// Compare.
		switch ( $compare ) {

			// IN & NOT IN.
			case 'IN':
			case 'NOT IN':
				return '(' . implode( ',', $values ) . ')';

			// BETWEEN & NOT BETWEEN.
			case 'BETWEEN':
			case 'NOT BETWEEN':
				// Exactly 2 values.
				if ( 2 === count( $value ) ) {
					$value = array_values( $value );

					// Not 2 values, so guess, by using first & last.
				} else {
					$value = array(
						reset( $value ),
						end( $value ),
					);
				}

				return $values[0] . ' AND ' . $values[1];

			// Everything else.
			default:
				return (int) reset( $value );
		}
	}

	/**
	 * Builds and validates a value string based on the comparison operator.
	 *
	 * Accepts any type and normalizes before passing to the operator: arrays
	 * pass through (for IN/BETWEEN), floats are cast to string, other scalars
	 * pass through, and unsupported types (bool, object, null) become null.
	 * Callers do not need to pre-narrow their values before calling this method.
	 *
	 * @since 3.0.0
	 *
	 * @param string         $compare The compare operator to use.
	 * @param mixed          $value   The value. Any type accepted; unsupported types become null.
	 * @param '%s'|'%d'|'%f' $pattern The pattern.
	 *
	 * @return string|false|int The value to be used in SQL or false on error.
	 */
	protected function build_value( $compare = '=', $value = null, $pattern = '%s' ) {

		// Look up the operator instance for this compare string.
		$operator = $this->get_operator( $compare );

		// Fall back to Equal for any unrecognized compare string.
		if ( false === $operator ) {
			$operator = $this->get_operator( '=' );
		}

		// Bail if no valid operator could be resolved.
		if ( false === $operator ) {
			return '';
		}

		/*
		 * Normalize value: arrays pass through; floats become strings;
		 * other scalars are unchanged; bools, objects, and null become null.
		 */
		if ( is_array( $value ) ) {
			$value = array_values( $value );
		} elseif ( is_float( $value ) ) {
			$value = (string) $value;
		} elseif ( ! is_int( $value ) && ! is_string( $value ) ) {
			$value = null;
		}

		// Return the operator's value SQL.
		return $operator->get_value_sql( $value, $pattern );
	}

	/**
	 * Builds a MySQL format date/time based on some query parameters.
	 *
	 * You can pass an array of values (year, month, etc.) with missing
	 * parameter values being defaulted to either the maximum or minimum values
	 * (controlled by the $default_to parameter).
	 *
	 * Alternatively you can pass a string that will be run through strtotime().
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,int>|int|string $datetime       An array of parameters or a strtotime() string.
	 * @param bool                         $default_to_max Whether to round up incomplete dates. Supported by values
	 *                                                     of $datetime that are arrays, or string values that are a
	 *                                                     subset of MySQL date format ('Y', 'Y-m', 'Y-m-d', 'Y-m-d H:i').
	 *                                                     Default: false.
	 * @param string|int                   $now            The current UNIX timestamp.
	 *
	 * @return string|false A MySQL format date/time or false on failure
	 */
	protected function build_mysql_datetime( $datetime = '', $default_to_max = false, $now = 0 ) {

		// Datetime is string.
		if ( is_string( $datetime ) ) {

			// Define matches so linters don't complain.
			$matches = array();

			/*
			 * Try to parse some common date formats, so we can detect
			 * the level of precision and support the 'inclusive' parameter.
			 */

			// Y.
			if ( preg_match( '/^(\d{4})$/', $datetime, $matches ) ) {
				$datetime = array(
					'year' => intval( $matches[1] ),
				);

				// Y-m.
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array(
					'year'  => intval( $matches[1] ),
					'month' => intval( $matches[2] ),
				);

				// Y-m-d.
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})\-(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array(
					'year'  => intval( $matches[1] ),
					'month' => intval( $matches[2] ),
					'day'   => intval( $matches[3] ),
				);

				// Y-m-d H:i.
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})\-(\d{2}) (\d{2}):(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array(
					'year'   => intval( $matches[1] ),
					'month'  => intval( $matches[2] ),
					'day'    => intval( $matches[3] ),
					'hour'   => intval( $matches[4] ),
					'minute' => intval( $matches[5] ),
				);

				// Y-m-d H:i:s.
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})\-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array(
					'year'   => intval( $matches[1] ),
					'month'  => intval( $matches[2] ),
					'day'    => intval( $matches[3] ),
					'hour'   => intval( $matches[4] ),
					'minute' => intval( $matches[5] ),
					'second' => intval( $matches[6] ),
				);
			}
		}

		// No match; may be int or string.
		if ( ! is_array( $datetime ) ) {

			// Maybe format or use as-is.
			$datetime = ! is_int( $datetime )
				? strtotime( $datetime, (int) $now )
				: (int) $datetime;

			// strtotime() may return false for unparsable input.
			if ( false === $datetime ) {
				return false;
			}

			// Return formatted.
			return gmdate( 'Y-m-d H:i:s', $datetime );
		}

		// Map to ints.
		$datetime = array_map( 'intval', $datetime );

		// Bail if no 'year' and no $now to default to.
		if ( empty( $now ) ) {
			return false;
		}

		// Year.
		if ( ! isset( $datetime[ 'year' ] ) ) {
			$datetime[ 'year' ] = (int) gmdate( 'Y', (int) $now );
		}

		// Month.
		if ( ! isset( $datetime[ 'month' ] ) ) {
			$datetime[ 'month' ] = ! empty( $default_to_max )
				? 12
				: 1;
		}

		// Day.
		if ( ! isset( $datetime[ 'day' ] ) ) {
			$datetime[ 'day' ] = ! empty( $default_to_max )
				? (int) gmdate( 't', (int) gmmktime( 0, 0, 0, (int) $datetime[ 'month' ], 1, (int) $datetime[ 'year' ] ) )
				: 1;
		}

		// Hour.
		if ( ! isset( $datetime[ 'hour' ] ) ) {
			$datetime[ 'hour' ] = ! empty( $default_to_max )
				? 23
				: 0;
		}

		// Minute.
		if ( ! isset( $datetime[ 'minute' ] ) ) {
			$datetime[ 'minute' ] = ! empty( $default_to_max )
				? 59
				: 0;
		}

		// Second.
		if ( ! isset( $datetime[ 'second' ] ) ) {
			$datetime[ 'second' ] = ! empty( $default_to_max )
				? 59
				: 0;
		}

		// Combine and return.
		return sprintf(
			'%04d-%02d-%02d %02d:%02d:%02d',
			$datetime[ 'year' ],
			$datetime[ 'month' ],
			$datetime[ 'day' ],
			$datetime[ 'hour' ],
			$datetime[ 'minute' ],
			$datetime[ 'second' ]
		);
	}

	/**
	 * Return a MySQL expression for selecting the week number based on the
	 * day that the week starts.
	 *
	 * Uses the WordPress site option, if set.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column        Database column.
	 * @param int    $start_of_week Day that week starts on. 0 = Sunday.
	 *
	 * @return string SQL clause.
	 */
	protected function build_mysql_week( $column = '', $start_of_week = 0 ) {

		// When does the week start?
		switch ( $start_of_week ) {

			// Monday.
			case 1:
				$retval = "WEEK( {$column}, 1 )";
				break;

			// Tuesday - Saturday.
			case 2:
			case 3:
			case 4:
			case 5:
			case 6:
				$retval = "WEEK( DATE_SUB( {$column}, INTERVAL {$start_of_week} DAY ), 0 )";
				break;

			// Sunday.
			case 0:
			default:
				$retval = "WEEK( {$column}, 0 )";
				break;
		}

		// Return SQL.
		return $retval;
	}

	/**
	 * Builds a query string for comparing time values (hour, minute, second).
	 *
	 * If just hour, minute, or second is set than a normal comparison will be done.
	 * However if multiple values are passed, a pseudo-decimal time will be created
	 * in order to be able to accurately compare against.
	 *
	 * @since 3.0.0
	 *
	 * @param string   $column  The column to query against. Needs to be pre-validated!.
	 * @param string   $compare The comparison operator. Needs to be pre-validated!.
	 * @param int|null $hour    Optional. An hour value (0-23).
	 * @param int|null $minute  Optional. A minute value (0-59).
	 * @param int|null $second  Optional. A second value (0-59).
	 *
	 * @return string|false A query part or false on failure.
	 */
	protected function build_time_query( $column = '', $compare = '=', $hour = null, $minute = null, $second = null ) {

		// Have to have at least one.
		if ( ! isset( $hour ) && ! isset( $minute ) && ! isset( $second ) ) {
			return false;
		}

		// Get multi-value comparison operators.
		$mvk = $this->get_operators( array( 'multi' => true ) );

		/*
		 * Complex combined queries aren't supported for multi-value queries.
		 */
		if ( in_array( $compare, $mvk, true ) ) {
			$retval = array();

			// Hour.
			if ( isset( $hour ) ) {
				$value = $this->build_numeric_value( $compare, $hour );
				if ( false !== $value ) {
					$retval[] = "HOUR( {$column} ) {$compare} {$value}";
				}
			}

			// Minute.
			if ( isset( $minute ) ) {
				$value = $this->build_numeric_value( $compare, $minute );
				if ( false !== $value ) {
					$retval[] = "MINUTE( {$column} ) {$compare} {$value}";
				}
			}

			// Second.
			if ( isset( $second ) ) {
				$value = $this->build_numeric_value( $compare, $second );
				if ( false !== $value ) {
					$retval[] = "SECOND( {$column} ) {$compare} {$value}";
				}
			}

			// Return SQL.
			return implode( ' AND ', $retval );
		}

		// Cases where just one unit is set.

		// Hour.
		if ( isset( $hour ) && ! isset( $minute ) && ! isset( $second ) ) {
			$value = $this->build_numeric_value( $compare, $hour );
			if ( false !== $value ) {
				return "HOUR( {$column} ) {$compare} {$value}";
			}

			// Minute.
		} elseif ( ! isset( $hour ) && isset( $minute ) && ! isset( $second ) ) {
			$value = $this->build_numeric_value( $compare, $minute );
			if ( false !== $value ) {
				return "MINUTE( {$column} ) {$compare} {$value}";
			}

			// Second.
		} elseif ( ! isset( $hour ) && ! isset( $minute ) && isset( $second ) ) {
			$value = $this->build_numeric_value( $compare, $second );
			if ( false !== $value ) {
				return "SECOND( {$column} ) {$compare} {$value}";
			}
		}

		/*
		 * Single units were already handled.
		 *
		 * Since hour & second isn't allowed, minute must to be set.
		 */
		if ( ! isset( $minute ) ) {
			return false;
		}

		// Defaults.
		$format = '';
		$time   = '';

		// Hour.
		if ( null !== $hour ) {
			$format .= '%H.';
			$time   .= sprintf( '%02d', $hour ) . '.';
		} else {
			$format .= '0.';
			$time   .= '0.';
		}

		// Minute.
		$format .= '%i';
		$time   .= sprintf( '%02d', $minute );

		// Second.
		if ( isset( $second ) ) {
			$format .= '%s';
			$time   .= sprintf( '%02d', $second );
		}

		// Build the SQL.
		$query = "DATE_FORMAT( {$column}, %s ) {$compare} %f";

		// Prepare the SQL.
		$prepared = $this->db()->prepare( $query, $format, $time );

		// Return the prepared SQL, or false if prepare() returns falsy.
		return is_string( $prepared )
			? $prepared
			: false;
	}

	/**
	 * Identify an existing table alias that is compatible with the current
	 * query clause.
	 *
	 * Avoid unnecessary table JOINs by allowing each clause to look for an
	 * existing table alias that is compatible with the query that it needs
	 * to perform.
	 *
	 * An existing alias is compatible if:
	 * (a) it is a sibling of $clause (under the scope of the same relation)
	 * (b) the combination of operator and relation between the clauses allows
	 *     for a shared table join.
	 *
	 * In the case of Meta, this only applies to 'IN' clauses that are connected
	 * by the relation 'OR'.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $clause       Query clause.
	 * @param array<string,mixed> $parent_query Parent query of $clause.
	 *
	 * @return string|false Table alias if found, otherwise false.
	 */
	protected function find_compatible_table_alias( $clause = array(), $parent_query = array() ) {

		// Bail if no $parent_query.
		if ( empty( $parent_query ) || ! is_array( $parent_query ) ) {
			return false;
		}

		// Default return value.
		$retval = false;

		// Loop through sibling queries.
		foreach ( $parent_query as $sibling ) {

			// Skip if the sibling is not an array or has no alias.
			if ( ! is_array( $sibling ) || empty( $sibling[ 'alias' ] ) ) {
				continue;
			}

			// Skip if not a first-order clause.
			if ( ! $this->is_first_order_clause( $sibling ) ) {
				continue;
			}

			// Default empty compares for sibling.
			$compatible_compares = array();

			/*
			 * Clauses connected by OR can share JOINs as long as they have
			 * "positive" operators.
			 */
			if ( 'OR' === $parent_query[ 'relation' ] ) {
				$compatible_compares = $this->get_operators( array( 'positive' => true ) );

				/*
				 * Clauses JOIN'ed by AND with "negative" operators share a JOIN
				 * only if they also share a key.
				 */
			} elseif ( isset( $sibling[ 'key' ] ) && isset( $clause[ 'key' ] ) && ( $sibling[ 'key' ] === $clause[ 'key' ] ) ) {
				$compatible_compares = $this->get_operators( array( 'positive' => false ) );
			}

			// Format comparisons.
			$clause_compare  = strtoupper( $clause[ 'compare' ] );
			$sibling_compare = strtoupper( $sibling[ 'compare' ] );

			// Use alias if sibling & clause comparisons are OK.
			if ( in_array( $clause_compare, $compatible_compares, true ) && in_array( $sibling_compare, $compatible_compares, true ) ) {
				$sanitized_alias = $this->sanitize_table_alias( $sibling[ 'alias' ] );

				if ( ! empty( $sanitized_alias ) ) {
					$retval = $sanitized_alias;
					break;
				}
			}
		}

		// Return the alias.
		return $retval;
	}
}
