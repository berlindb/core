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

	use \BerlinDB\Database\Traits\Base;

	/**
	 * Query class responsible for constructing this parser.
	 *
	 * @since 3.0.0
	 * @var \BerlinDB\Database\Query|null
	 */
	public $caller = null;

	/**
	 * Array of first-order keys.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	public $first_keys = array();

	/**
	 * Array of queries.
	 *
	 * @since 3.0.0
	 * @var array
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
	 * @var string
	 */
	public $compare = '=';

	/**
	 * The UNIX timestamp for this current time.
	 *
	 * Can be changed via query arguments.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $now = 0;

	/**
	 * The start of week operator.
	 *
	 * Can be changed via query arguments.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $start_of_week = 0;

	/**
	 * Array of clauses.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	public $clauses = array();

	/**
	 * Array of operators.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	public $operators = array();

	/**
	 * Supported multi-value comparison types.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	public $multi_value_keys = array();

	/**
	 * Supported relation types.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	public $relation_keys = array(
		'OR',
		'AND'
	);

	/**
	 * Whether the query contains any OR relations.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $has_or_relation = false;

	/**
	 * Constructor.
	 *
	 * @since 3.0.0
	 */
	public function __construct( $query_vars = array(), $caller = null ) {
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
	 * @param array $query_vars {
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
	 * @param \BerlinDB\Database\Query|null $caller The Query class that invoked this parser, or null.
	 */
	public function init( $query_vars = array(), $caller = null ) {

		// Allow subclasses to normalise query vars before the rest of init() runs.
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
		if ( ! isset( $query_vars[ 0 ] ) ) {

			// Apply a default alias to first-order clauses when not provided.
			if ( is_array( $query_vars ) && empty( $query_vars['alias'] ) ) {
				$query_vars['alias'] = $this->get_table_alias( $query_vars );
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
	 * normalised structure that init() expects. The default is a no-op.
	 *
	 * @since 3.0.0
	 *
	 * @param array $query_vars The raw query vars.
	 *
	 * @return array The (possibly transformed) query vars.
	 */
	protected function parse_query_vars( $query_vars = array() ) {
		return $query_vars;
	}

	/**
	 * Sets the caller.
	 *
	 * @since 3.0.0
	 *
	 * @param \BerlinDB\Database\Query $caller
	 */
	protected function set_caller( $caller = null ) {
		$this->caller = $caller;
	}

	/**
	 * Sets the first-order keys to use.
	 *
	 * @since 3.0.0
	 *
	 * @param array $first_keys Array of first-order keys.
	 */
	protected function set_first_keys( $first_keys = array() ) {
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
	abstract protected function set_operators();

	/**
	 * Recursive-friendly query sanitizer.
	 *
	 * Ensures that each query-level clause has a 'relation' key, and that
	 * each first-order clause contains all the necessary keys from $defaults.
	 *
	 * @since 3.0.0
	 *
	 * @param array $queries
	 * @param array $parent_query
	 *
	 * @return array Sanitized queries.
	 */
	public function sanitize_query( $queries = array(), $parent_query = array() ) {

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

		/**
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

			/**
			 * Non-array values and declared first-order keys pass through as-is.
			 *
			 * Trust the values and sanitize when building SQL.
			 */
			if ( ! is_array( $query ) || in_array( $key, $this->first_keys, true ) ) {
				$retval[ $key ] = $query;

			/**
			 * Arrays whose shape matches a first-order clause pass through as-is.
			 *
			 * Trust the values and sanitize when building SQL.
			 */
			} elseif ( $this->is_first_order_clause( $query ) ) {
				$retval[ $key ] = $query;

			/**
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
			$retval['relation']    = 'OR';
			$this->has_or_relation = true;

		/*
		 * If there is only a single clause, call the relation 'OR'.
		 * This value will not actually be used to join clauses, but it
		 * simplifies the logic around combining key-only queries.
		 */
		} elseif ( 1 === count( $retval ) ) {
			$retval['relation'] = 'OR';

		// Default to AND.
		} else {
			$retval['relation'] = 'AND';
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
	 * @param array $query Query clause.
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
	 * @param array $query Query clause.
	 *
	 * @return array
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
	 * Get operators, possibly filtered & plucked.
	 *
	 * @since 3.0.0
	 *
	 * @param array       $filter Optional. Key => value pairs to match against each
	 *                            operator's properties. Default empty array.
	 * @param bool|string $field  Optional. A property name to pluck from each operator
	 *                            instead of returning the full object. Default 'compare'.
	 * @return array
	 */
	public function get_operators( $filter = array(), $field = 'compare' ) {
		return wp_filter_object_list( $this->operators, $filter, 'and', $field );
	}

	/**
	 * Get a single operator instance by an array of property arguments.
	 *
	 * Mirrors Query::get_column_by(). Passes $args into get_operators() with
	 * no field pluck so full objects are returned, then returns the first match.
	 *
	 * @since 3.0.0
	 *
	 * @param array $args Key => value pairs to match against operator properties.
	 *
	 * @return \BerlinDB\Database\Operators\Base|false The first matching operator, or false.
	 */
	protected function get_operator_by( $args = array() ) {
		$filter = $this->get_operators( $args, false );

		return ! empty( $filter )
			? reset( $filter )
			: false;
	}

	/**
	 * Get a single operator instance by its compare string.
	 *
	 * @since 3.0.0
	 *
	 * @param string $compare The SQL operator string, e.g. '=', 'IN', 'NOT LIKE'.
	 *
	 * @return \BerlinDB\Database\Operators\Base|false The matching operator, or false.
	 */
	protected function get_operator( $compare = '' ) {
		return $this->get_operator_by( array( 'compare' => $compare ) );
	}

	/**
	 * Determines and validates the default values for a query or subquery.
	 *
	 * @since 3.0.0
	 *
	 * @param array $query A query or subquery.
	 *
	 * @return array The comparison operator.
	 */
	public function get_defaults( $query = array() ) {
		return array(
			'now'           => $this->get_now( $query ),
			'column'        => $this->get_column( $query ),
			'compare'       => $this->get_compare( $query ),
			'relation'      => $this->get_relation( $query ),
			'start_of_week' => $this->get_start_of_week( $query )
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
	 * @param array $query A query or subquery.
	 *
	 * @return string
	 */
	protected function get_table_alias( $query = array() ) {

		if ( ! empty( $query['alias'] ) ) {
			$alias = $this->sanitize_table_alias( $query['alias'] );

			return ! empty( $alias )
				? esc_sql( $alias )
				: '';
		}

		$alias = $this->caller( 'get_table_alias' );

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
	 * @param array $query A query or subquery.
	 *
	 * @return string The comparison operator.
	 */
	protected function get_column( $query = array() ) {

		// If a column is passed, sanitize and return it.
		if ( ! empty( $query['column'] ) ) {

			// Sanitize the column name.
			$sanitized = $this->sanitize_column_name( $query['column'] );

			// Return.
			return $sanitized
				? esc_sql( $sanitized )
				: $this->column;
		}

		return $this->column;
	}

	/**
	 * Determines and validates which comparison operator to use.
	 *
	 * Compare must be in the $comparison_keys array.
	 *
	 * @since 3.0.0
	 *
	 * @param array $query A query or a subquery.
	 *
	 * @return string The comparison operator.
	 */
	protected function get_compare( $query = array() ) {
		$comparison_keys = $this->get_operators();

		return ! empty( $query['compare'] ) && in_array( $query['compare'], $comparison_keys, true )
			? strtoupper( $query['compare'] )
			: $this->compare;
	}

	/**
	 * Determines and validates which relation to use.
	 *
	 * Relation must be in the $relation_keys array.
	 *
	 * @since 3.0.0
	 *
	 * @param array $query A query or a subquery.
	 *
	 * @return string The relation operator.
	 */
	protected function get_relation( $query = array() ) {
		return ! empty( $query['relation'] ) && in_array( $query['relation'], $this->relation_keys, true )
			? strtoupper( $query['relation'] )
			: $this->relation;
	}

	/**
	 * Determines and validates what the current UNIX timestamp is.
	 *
	 * Use now if passed, or time().
	 *
	 * @since 3.0.0
	 *
	 * @param array $query A date query or a date subquery.
	 *
	 * @return int The current UNIX timestamp.
	 */
	protected function get_now( $query = array() ) {
		return ! empty( $query['now'] ) && is_numeric( $query['now'] )
			? (int) $query['now']
			: time();
	}

	/**
	 * Determines and validates what start_of_week to use.
	 *
	 * Use start of week if passed and valid.
	 *
	 * @since 3.0.0
	 *
	 * @param array $query A date query or a date subquery.
	 *
	 * @return int The comparison operator.
	 */
	protected function get_start_of_week( $query = array() ) {
		return (int) isset( $query['start_of_week'] ) && ( 6 >= (int) $query['start_of_week'] ) && ( 0 <= (int) $query['start_of_week'] )
			? $query['start_of_week']
			: $this->start_of_week;
	}

	/**
	 * Determines and validates what first-order keys to use.
	 *
	 * Use first $first_keys if passed and valid.
	 *
	 * @since 3.0.0
	 *
	 * @param array $first_keys Array of first-order keys.
	 *
	 * @return array The first-order keys.
	 */
	protected function get_first_keys( $first_keys = array() ) {
		return ! empty( $first_keys ) && is_array( $first_keys )
			? $first_keys
			: $this->first_keys;
	}

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
	 * @return array {
	 *     Array containing JOIN and WHERE SQL clauses to append to the main query,
	 *     or false if no table exists for the requested type.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	public function get_sql( $type = '', $primary_table = '', $primary_column = '' ) {
		return $this->get_join_where_clauses();
	}

	/**
	 * Build an ORDER BY SQL fragment for a given orderby value.
	 *
	 * Called by Query::parse_single_orderby() for each registered parser.
	 * Subclasses may override this to handle orderby values that belong to
	 * their domain (e.g. the In parser handles '{column}__in' → FIELD()).
	 * The default is a no-op.
	 *
	 * @since 3.0.0
	 *
	 * @param string $orderby The raw orderby value.
	 * @param bool   $alias   Whether to prefix with the table alias.
	 * @return string SQL fragment, or empty string if this parser does not handle $orderby.
	 */
	public function get_orderby_sql( $orderby = '', $alias = true ) {
		return '';
	}

	/**
	 * Generates SQL clauses to be appended to a main query.
	 *
	 * The preferred method for new code in 3.0.0 and later. Carries no legacy parameter
	 * baggage — all context is derived from the parser state set at construction time.
	 *
	 * Subclasses that need to perform setup before SQL is generated should override this
	 * method rather than get_sql().
	 *
	 * @since 3.0.0
	 *
	 * @return array {
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

		/**
		 * If any JOINs are LEFT JOINs (as in the case of NOT EXISTS) then all
		 * JOINs should be LEFT. Otherwise items with no values will be excluded
		 * from results.
		 */
		if ( false !== strpos( $retval['join'], 'LEFT JOIN' ) ) {
			$retval['join'] = str_replace( 'INNER JOIN', 'LEFT JOIN', $retval['join'] );
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
	 * @return array {
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
	 * @param array $query Query to parse.
	 * @param int   $depth Optional. Number of tree levels deep we currently are.
	 *                     Used to calculate indentation. Default 0.
	 * @return array {
	 *     Array containing JOIN and WHERE SQL clauses to append to a single query array.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
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
		$indent = $relation = '';

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

					// Get clauses & where count.
					$clause_sql = $this->get_sql_for_clause( $clause, $query, $key );
					$where_count = count( $clause_sql[ 'where' ] );

					// Empty SQL.
					if ( 0 === $where_count ) {
						$sql[ 'where' ][] = '';

					// Add clause.
					} elseif ( 1 === $where_count ) {
						$sql[ 'where' ][] = reset( $clause_sql[ 'where' ] );

					// Implode many clauses.
					} else {
						$sql[ 'where' ][] = '( ' . implode( ' AND ', $clause_sql[ 'where' ] ) . ' )';
					}

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
		if ( empty( $relation ) ) {
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
	 * @since 3.0.0
	 *
	 * @param array  $clause       Query clause (passed by reference).
	 * @param array  $parent_query Parent query array.
	 * @param string $clause_key   Optional. The array key used to name the clause.
	 *                             If not provided, a key will be generated automatically.
	 * @return array {
	 *     Array containing WHERE SQL clauses to append to a first-order query.
	 *
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	public function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {

		// Default return value.
		$retval = array(
			'join'  => array(),
			'where' => array(),
		);

		// Maybe format compare clause.
		if ( isset( $clause['compare'] ) ) {
			$clause['compare'] = strtoupper( $clause['compare'] );

		// Or set compare clause based on value.
		} else {
			$clause['compare'] = isset( $clause['value'] ) && is_array( $clause['value'] )
				? 'IN'
				: '=';
		}

		// Get all comparison operators.
		$all_compares = $this->get_operators();

		// Fallback to equals.
		if ( ! in_array( $clause['compare'], $all_compares, true ) ) {
			$clause['compare'] = '=';
		}

		// Uppercase or equals.
		if ( isset( $clause['compare_key'] ) && ( 'LIKE' === strtoupper( $clause['compare_key'] ) ) ) {
			$clause['compare_key'] = strtoupper( $clause['compare_key'] );
		} else {
			$clause['compare_key'] = '=';
		}

		// Get comparison from clause.
		$compare = $clause['compare'];

		// Resolve the SQL operator (may differ from the compare identifier).
		$operator    = $this->get_operator( $compare );
		$sql_compare = $operator ? $operator->get_sql_compare() : $compare;

		/** Build the WHERE clause ********************************************/

		// Column name and value.
		if ( array_key_exists( 'key', $clause ) && array_key_exists( 'value', $clause ) ) {
			$column = $this->sanitize_column_name( $clause['key'] );
			$where  = $this->build_value( $compare, $clause['value'], '%s' );

			// Maybe add column, compare, & where to return value.
			if ( ! empty( $where ) ) {
				$retval['where'][] = "{$column} {$sql_compare} {$where}";
			}
		}

		// Multiple WHERE clauses should be joined in parentheses.
		if ( 1 < count( $retval['where'] ) ) {
			$retval['where'] = array( '( ' . implode( ' AND ', $retval['where'] ) . ' )' );
		}

		// Return join/where array.
		return $retval;
	}

	/**
	 * Return the appropriate alias for the given type if applicable.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type MySQL type to CAST().
	 * @return string MySQL type.
	 */
	public function get_cast_for_type( $type = '' ) {

		// Bail if empty.
		if ( empty( $type ) ) {
			return 'CHAR';
		}

		// Convert to uppercase.
		$upper_type = strtoupper( $type );

		// Bail if no match.
		if ( ! preg_match( '/^(?:BINARY|CHAR|DATE|DATETIME|SIGNED|UNSIGNED|TIME|NUMERIC(?:\(\d+(?:,\s?\d+)?\))?|DECIMAL(?:\(\d+(?:,\s?\d+)?\))?)$/', $upper_type ) ) {
			return 'CHAR';
		}

		// Fallback support for old 'NUMERIC' type.
		if ( 'NUMERIC' === $upper_type ) {
			$upper_type = 'SIGNED';
		}

		// Return uppercase type.
		return $upper_type;
	}

	/**
	 * Validates the given query values.
	 *
	 * @since 3.0.0
	 * @param array $query The query array.
	 * @return bool True if all values in the query are valid, false if one or
	 *              more fail.
	 */
	public function validate_values( $query = array() ) {

		// Bail if empty.
		if ( empty( $query ) ) {
			return false;
		}

		// Default valid.
		$valid = true;

		// Values are passthroughs.
		if ( array_key_exists( 'value', $query ) ) {
			$valid = true;
		}

		// Return if valid or not.
		return $valid;
	}

	/** Builders **************************************************************/

	/**
	 * Builds and validates a value string based on the comparison operator.
	 *
	 * @since 3.0.0
	 *
	 * @param string $compare The compare operator to use
	 * @param array|int|string $value The value
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
						end( $value )
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
	 * @since 3.0.0
	 *
	 * @param string       $compare The compare operator to use.
	 * @param array|string $value   The value.
	 * @param string       $pattern The pattern.
	 *
	 * @return string|false|int The value to be used in SQL or false on error.
	 */
	protected function build_value( $compare = '=', $value = null, $pattern = '%s' ) {

		// Look up the operator instance for this compare string.
		$operator = $this->get_operator( $compare );

		// Fall back to Equal for any unrecognised compare string.
		if ( false === $operator ) {
			$operator = $this->get_operator( '=' );
		}

		return $operator->get_sql( $value, $pattern );
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
	 * @param array|int|string $datetime       An array of parameters or a strtotime() string
	 * @param bool             $default_to_max Whether to round up incomplete dates. Supported by values
	 *                                         of $datetime that are arrays, or string values that are a
	 *                                         subset of MySQL date format ('Y', 'Y-m', 'Y-m-d', 'Y-m-d H:i').
	 *                                         Default: false.
	 * @param string|int   $now                The current UNIX timestamp.
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
				? strtotime( $datetime, $now )
				: (int) $datetime;

			// strtotime() may return false for unparseable input.
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
		if ( ! isset( $datetime['year'] ) ) {
			$datetime['year'] = gmdate( 'Y', $now );
		}

		// Month.
		if ( ! isset( $datetime['month'] ) ) {
			$datetime['month'] = ! empty( $default_to_max )
				? 12
				: 1;
		}

		// Day.
		if ( ! isset( $datetime['day'] ) ) {
			$datetime['day'] = ! empty( $default_to_max )
				? (int) gmdate( 't', gmmktime( 0, 0, 0, $datetime['month'], 1, $datetime['year'] ) )
				: 1;
		}

		// Hour.
		if ( ! isset( $datetime['hour'] ) ) {
			$datetime['hour'] = ! empty( $default_to_max )
				? 23
				: 0;
		}

		// Minute.
		if ( ! isset( $datetime['minute'] ) ) {
			$datetime['minute'] = ! empty( $default_to_max )
				? 59
				: 0;
		}

		// Second.
		if ( ! isset( $datetime['second'] ) ) {
			$datetime['second'] = ! empty( $default_to_max )
				? 59
				: 0;
		}

		// Combine and return.
		return sprintf(
			'%04d-%02d-%02d %02d:%02d:%02d',
			$datetime['year'],
			$datetime['month'],
			$datetime['day'],
			$datetime['hour'],
			$datetime['minute'],
			$datetime['second']
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
	 * @param string   $column  The column to query against. Needs to be pre-validated!
	 * @param string   $compare The comparison operator. Needs to be pre-validated!
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

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database.
		if ( empty( $db ) ) {
			return false;
		}

		// Get multi-value comparison operators.
		$mvk = $this->get_operators( array( 'multi' => true ) );

		/**
		 * Complex combined queries aren't supported for multi-value queries.
		 */
		if ( in_array( $compare, $mvk, true ) ) {
			$retval = array();

			// Hour.
			if ( isset( $hour ) && false !== ( $value = $this->build_numeric_value( $compare, $hour ) ) ) {
				$retval[] = "HOUR( {$column} ) {$compare} {$value}";
			}

			// Minute.
			if ( isset( $minute ) && false !== ( $value = $this->build_numeric_value( $compare, $minute ) ) ) {
				$retval[] = "MINUTE( {$column} ) {$compare} {$value}";
			}

			// Second.
			if ( isset( $second ) && false !== ( $value = $this->build_numeric_value( $compare, $second ) ) ) {
				$retval[] = "SECOND( {$column} ) {$compare} {$value}";
			}

			// Return SQL.
			return implode( ' AND ', $retval );
		}

		// Cases where just one unit is set.

		// Hour.
		if ( isset( $hour ) && ! isset( $minute ) && ! isset( $second ) && false !== ( $value = $this->build_numeric_value( $compare, $hour ) ) ) {
			return "HOUR( {$column} ) {$compare} {$value}";

		// Minute.
		} elseif ( ! isset( $hour ) && isset( $minute ) && ! isset( $second ) && false !== ( $value = $this->build_numeric_value( $compare, $minute ) ) ) {
			return "MINUTE( {$column} ) {$compare} {$value}";

		// Second.
		} elseif ( ! isset( $hour ) && ! isset( $minute ) && isset( $second ) && false !== ( $value = $this->build_numeric_value( $compare, $second ) ) ) {
			return "SECOND( {$column} ) {$compare} {$value}";
		}

		/**
		 * Single units were already handled.
		 *
		 * Since hour & second isn't allowed, minute must to be set.
		 */
		if ( ! isset( $minute ) ) {
			return false;
		}

		// Defaults.
		$format = $time = '';

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

		// Return the prepared SQL.
		return $db->prepare( $query, $format, $time );
	}

	/**
	 * Used to generate the SQL string for IN and NOT IN clauses.
	 *
	 * The $values being passed in should not be validated, and they will be
	 * escaped before they are concatenated together and returned as a string.
	 *
	 * @since 3.0.0
	 *
	 * @param string       $column_name Column name.
	 * @param array|string $values      Array of values.
	 * @param bool         $wrap        To wrap in parenthesis.
	 * @param string       $pattern     Pattern to prepare with.
	 *
	 * @return string Escaped/prepared SQL, possibly wrapped in parenthesis.
	 */
	protected function build_in_sql( $column_name = '', $values = array(), $wrap = true, $pattern = '' ) {

		// Bail if no values or invalid column.
		if ( empty( $values ) || ! $this->caller( 'is_valid_column', array( $column_name ) ) ) {
			return '';
		}

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return '';
		}

		// Fallback to column pattern.
		if ( empty( $pattern ) || ! is_string( $pattern ) ) {
			$pattern = $this->caller( 'get_column_field', array( array( 'name' => $column_name ), 'pattern', '%s' ) );
		}

		// Fill an array of patterns to match the number of values.
		$count    = count( $values );
		$patterns = array_fill( 0, $count, $pattern );

		// Prepare.
		$sql    = implode( ', ', $patterns );
		$retval = $db->prepare( $sql, ...$values );

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
	 * @param array $clause       Query clause.
	 * @param array $parent_query Parent query of $clause.
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

			// Skip if the sibling has no alias.
			if ( empty( $sibling['alias'] ) ) {
				continue;
			}

			// Skip if not a first-order clause.
			if ( ! is_array( $sibling ) || ! $this->is_first_order_clause( $sibling ) ) {
				continue;
			}

			// Default empty compares for sibling.
			$compatible_compares = array();

			/**
			 * Clauses connected by OR can share JOINs as long as they have
			 * "positive" operators.
			 */
			if ( 'OR' === $parent_query['relation'] ) {
				$compatible_compares = $this->get_operators( array( 'positive' => true ) );

			/**
			 * Clauses JOIN'ed by AND with "negative" operators share a JOIN
			 * only if they also share a key.
			 */
			} elseif ( isset( $sibling['key'] ) && isset( $clause['key'] ) && ( $sibling['key'] === $clause['key'] ) ) {
				$compatible_compares = $this->get_operators( array( 'positive' => false ) );
			}

			// Format comparisons.
			$clause_compare  = strtoupper( $clause['compare'] );
			$sibling_compare = strtoupper( $sibling['compare'] );

			// Use alias if sibling & clause comparisons are OK.
			if ( in_array( $clause_compare, $compatible_compares, true ) && in_array( $sibling_compare, $compatible_compares, true ) ) {
				$sanitized_alias = $this->sanitize_table_alias( $sibling['alias'] );

				if ( ! empty( $sanitized_alias ) ) {
					$retval = $sanitized_alias;
					break;
				}
			}
		}

		// Return the alias.
		return $retval;
	}

	/**
	 * Call a method on the caller if it exists.
	 *
	 * @since 3.0.0
	 *
	 * @param string $method Method name.
	 * @param array  ...$args Optional. Arguments to pass to the method.
	 *
	 * @return mixed|null The return value of the called method, or null if no
	 *                    caller or method does not exist.
	 */
	protected function caller( $method = '', ...$args ) {

		// Bail if no caller.
		if ( empty( $this->caller ) ) {
			return null;
		}

		// Call it.
		return call_user_func(
			array( $this->caller, $method ),
			...$args
		);
	}
}
