<?php
/**
 * Base Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Parser
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database\Traits;

// Exit if accessed directly
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
	 * @var null|Query
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
	 * Each operator includes an array of attributes to help group them in
	 * various ways that are relevant to how parsing happens.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	public $operators = array(

		// =
		array(
			'compare'  => '=',
			'positive' => true,
			'multi'    => false,
			'numeric'  => false
		),
		array(
			'compare'  => '!=',
			'positive' => false,
			'multi'    => false,
			'numeric'  => false
		),

		// >
		array(
			'compare'  => '>',
			'positive' => true,
			'multi'    => false,
			'numeric'  => true
		),
		array(
			'compare'  => '>=',
			'positive' => true,
			'multi'    => false,
			'numeric'  => true
		),

		// <
		array(
			'compare'  => '<',
			'positive' => true,
			'multi'    => false,
			'numeric'  => true
		),
		array(
			'compare'  => '<=',
			'positive' => true,
			'multi'    => false,
			'numeric'  => true
		),

		// LIKE
		array(
			'compare'  => 'LIKE',
			'positive' => true,
			'multi'    => false,
			'numeric'  => false
		),
		array(
			'compare'  => 'NOT LIKE',
			'positive' => false,
			'multi'    => false,
			'numeric'  => false
		),

		// IN
		array(
			'compare'  => 'IN',
			'positive' => true,
			'multi'    => true,
			'numeric'  => false
		),
		array(
			'compare'  => 'NOT IN',
			'positive' => false,
			'multi'    => true,
			'numeric'  => false
		),

		// BETWEEN
		array(
			'compare'  => 'BETWEEN',
			'positive' => true,
			'multi'    => true,
			'numeric'  => true
		),
		array(
			'compare'  => 'NOT BETWEEN',
			'positive' => false,
			'multi'    => true,
			'numeric'  => true
		),

		// EXISTS
		array(
			'compare'  => 'EXISTS',
			'positive' => true,
			'multi'    => false,
			'numeric'  => false
		),
		array(
			'compare'  => 'NOT EXISTS',
			'positive' => false,
			'multi'    => false,
			'numeric'  => false
		),

		// REGEXP
		array(
			'compare'  => 'REGEXP',
			'positive' => true,
			'multi'    => false,
			'numeric'  => false
		),
		array(
			'compare'  => 'NOT REGEXP',
			'positive' => false,
			'multi'    => false,
			'numeric'  => false
		),

		// RLIKE
		array(
			'compare'  => 'RLIKE',
			'positive' => true,
			'multi'    => false,
			'numeric'  => false
		)
	);

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
	 * @param Query $caller The Query class that invoked this parser.
	 */
	public function init( $query_vars = array(), $caller = null ) {

		// Set the caller & first_keys.
		$this->set_caller( $caller );
		$this->set_first_keys( array() );

		// Set default class attributes from query.
		$this->now           = $this->get_now( $query_vars );
		$this->column        = $this->get_column( $query_vars );
		$this->compare       = $this->get_compare( $query_vars );
		$this->relation      = $this->get_relation( $query_vars );
		$this->start_of_week = $this->get_start_of_week( $query_vars );

		// Support for passing some key in the top level of the array.
		if ( ! isset( $query_vars[ 0 ] ) ) {
			$query_vars = array( $query_vars );
		}

		// Set the queries.
		$this->queries = $this->sanitize_query( $query_vars );
	}

	/**
	 * Sets the caller.
	 *
	 * @since 3.0.0
	 *
	 * @param Query $caller
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
			 * This is a first-order query.
			 *
			 * Trust the values and sanitize when building SQL.
			 */
			if ( ! is_array( $query ) || in_array( $key, $this->first_keys, true ) ) {
				$retval[ $key ] = $query;

			/**
			 * This is a first-order query.
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
	 * Get $operators, possibly filtered & plucked.
	 *
	 * @since 3.0.0
	 *
	 * @param array       $filter Optional. An array of key => value arguments to match
	 *                            against each object. Default empty array.
	 * @param bool|string $field  Optional. A field from the object to place instead
	 *                            of the entire object. Default false.
	 * @return array
	 */
	public function get_operators( $filter = array(), $field = 'compare' ) {
		return wp_filter_object_list( $this->operators, $filter, 'and', $field );
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
		return ! empty( $query['column'] )
			? esc_sql( $this->validate_column( $query['column'] ) )
			: $this->column;
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
		static $comparison_keys = null;

		if ( null === $comparison_keys ) {
			$comparison_keys = $this->get_operators();
		}

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
	 * @since 3.0.0
	 *
	 * @param string $type           Type of object.
	 * @param string $primary_table  Primary table for the object being filtered.
	 * @param string $primary_column Primary column for the filtered object in $primary_table.
	 *
	 * @return string[]|false {
	 *     Array containing JOIN and WHERE SQL clauses to append to the main query,
	 *     or false if no table exists for the requested type.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	public function get_sql( $type = '', $primary_table = '', $primary_column = '' ) {

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

		// Maybe prefix 'where' with " AND "
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
		$all_compares = $this->$this->get_operators();

		// Fallback to equals
		if ( ! in_array( $clause['compare'], $all_compares, true ) ) {
			$clause['compare'] = '=';
		}

		// Uppercase or equals
		if ( isset( $clause['compare_key'] ) && ( 'LIKE' === strtoupper( $clause['compare_key'] ) ) ) {
			$clause['compare_key'] = strtoupper( $clause['compare_key'] );
		} else {
			$clause['compare_key'] = '=';
		}

		// Get comparison from clause
		$compare = $clause['compare'];

		/** Build the WHERE clause ********************************************/

		// Column name and value.
		if ( array_key_exists( 'key', $clause ) && array_key_exists( 'value', $clause ) ) {
			$column = $this->sanitize_column_name( $clause['key'] );
			$where  = $this->build_value( $compare, $clause['value'], '%s' );

			// Maybe add column, compare, & where to return value.
			if ( ! empty( $where ) ) {
				$retval['where'][] = "{$column} {$compare} {$where}";
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

	/**
	 * Validates a column name parameter.
	 *
	 * Keeps upper & lower case letters, numbers, periods, and underscores.
	 *
	 * @since 3.0.0
	 * @param string $column The user-supplied column name.
	 * @return string A validated column name value.
	 */
	protected function validate_column( $column = '' ) {
		return preg_replace( '/[^a-zA-Z0-9_$\.]/', '', $column );
	}

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

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database.
		if ( empty( $db ) ) {
			return '';
		}

		// Maybe split value by commas & spaces if multi.
		if ( is_scalar( $value ) ) {

			// Trim empties.
			$value = trim( $value );

			// Get multi-value comparison operators.
			$mvk   = $this->get_operators( array( 'multi' => true ) );

			/**
			 * Maybe split value by commas or spaces to support certain multi-
			 * value compare keys with values like: "100, 200".
			 */
			if ( in_array( $compare, $mvk, true ) ) {
				$value = preg_split( '/[,\s]+/', $value );
			}
		}

		// Compare.
		switch ( $compare ) {
			case 'IN':
			case 'NOT IN':
				$in     = '(' . substr( str_repeat( ",{$pattern}", count( $value ) ), 1 ) . ')';
				$retval = $db->prepare( $in, $value );
				break;

			case 'BETWEEN':
			case 'NOT BETWEEN':
				$value  = array_slice( $value, 0, 2 );
				$retval = $db->prepare( "{$pattern} AND {$pattern}", $value );
				break;

			case 'LIKE':
			case 'NOT LIKE':
				$value  = '%' . $db->esc_like( $value ) . '%';
				$retval = $db->prepare( $pattern, $value );
				break;

			// EXISTS with a value is interpreted as '='.
			case 'EXISTS':
				$compare = '=';
				$retval  = $db->prepare( $pattern, $value );
				break;

			// 'value' is ignored for NOT EXISTS.
			case 'NOT EXISTS':
				$retval = '';
				break;

			default:
				$retval = $db->prepare( $pattern, $value );
				break;
		}

		// Return
		return $retval;
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

		// Datetime is string
		if ( is_string( $datetime ) ) {

			// Define matches so linters don't complain
			$matches = array();

			/*
			 * Try to parse some common date formats, so we can detect
			 * the level of precision and support the 'inclusive' parameter.
			 */

			// Y
			if ( preg_match( '/^(\d{4})$/', $datetime, $matches ) ) {
				$datetime = array(
					'year' => intval( $matches[1] ),
				);

			// Y-m
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array(
					'year'  => intval( $matches[1] ),
					'month' => intval( $matches[2] ),
				);

			// Y-m-d
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})\-(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array(
					'year'  => intval( $matches[1] ),
					'month' => intval( $matches[2] ),
					'day'   => intval( $matches[3] ),
				);

			// Y-m-d H:i
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})\-(\d{2}) (\d{2}):(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array(
					'year'   => intval( $matches[1] ),
					'month'  => intval( $matches[2] ),
					'day'    => intval( $matches[3] ),
					'hour'   => intval( $matches[4] ),
					'minute' => intval( $matches[5] ),
				);

			// Y-m-d H:i:s
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

		// No match; may be int or string
		if ( ! is_array( $datetime ) ) {

			// Maybe format or use as-is
			$datetime = ! is_int( $datetime )
				? strtotime( $datetime, $now )
				: (int) $datetime;

			// Return formatted
			return gmdate( 'Y-m-d H:i:s', $datetime );
		}

		// Map to ints
		$datetime = array_map( 'intval', $datetime );

		// Year
		if ( ! isset( $datetime['year'] ) ) {
			$datetime['year'] = gmdate( 'Y', $now );
		}

		// Month
		if ( ! isset( $datetime['month'] ) ) {
			$datetime['month'] = ! empty( $default_to_max )
				? 12
				: 1;
		}

		// Day
		if ( ! isset( $datetime['day'] ) ) {
			$datetime['day'] = ! empty( $default_to_max )
				? (int) gmdate( 't', gmmktime( 0, 0, 0, $datetime['month'], 1, $datetime['year'] ) )
				: 1;
		}

		// Hour
		if ( ! isset( $datetime['hour'] ) ) {
			$datetime['hour'] = ! empty( $default_to_max )
				? 23
				: 0;
		}

		// Minute
		if ( ! isset( $datetime['minute'] ) ) {
			$datetime['minute'] = ! empty( $default_to_max )
				? 59
				: 0;
		}

		// Second
		if ( ! isset( $datetime['second'] ) ) {
			$datetime['second'] = ! empty( $default_to_max )
				? 59
				: 0;
		}

		// Combine and return
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

			// Monday
			case 1:
				$retval = "WEEK( {$column}, 1 )";
				break;

			// Tuesday - Saturday
			case 2:
			case 3:
			case 4:
			case 5:
			case 6:
				$retval = "WEEK( DATE_SUB( {$column}, INTERVAL {$start_of_week} DAY ), 0 )";
				break;

			// Sunday
			case 0:
			default:
				$retval = "WEEK( {$column}, 0 )";
				break;
		}

		// Return SQL
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

		// Cases where just one unit is set

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

		// Bail if no values or invalid column
		if ( empty( $values ) || ! $this->caller( 'is_valid_column', array( $column_name ) ) ) {
			return '';
		}

		// Get the database interface
		$db = $this->get_db();

		// Bail if no database interface is available
		if ( empty( $db ) ) {
			return '';
		}

		// Fallback to column pattern
		if ( empty( $pattern ) || ! is_string( $pattern ) ) {
			$pattern = $this->caller( 'get_column_field', array( array( 'name' => $column_name ), 'pattern', '%s' ) );
		}

		// Fill an array of patterns to match the number of values
		$count    = count( $values );
		$patterns = array_fill( 0, $count, $pattern );

		// Escape & prepare
		$sql      = implode( ', ', $patterns );
		$values   = $db->_escape( $values );       // May quote strings
		$retval   = $db->prepare( $sql, $values ); // Catches quoted strings

		// Set return value to empty string if prepare() returns falsy
		if ( empty( $retval ) ) {
			$retval = '';
		}

		// Wrap them in parenthesis
		if ( true === $wrap ) {
			$retval = "({$retval})";
		}

		// Return in SQL
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
				$retval = preg_replace( '/\W/', '_', $sibling['alias'] );
				break;
			}
		}

		// Return the alias
		return $retval;
	}

	protected function caller( $method = '', ...$args ) {

		// Bail if no caller
		if ( empty( $this->caller ) ) {
			return null;
		}

		// Call it
		return call_user_func(
			array( $this->caller, $method ),
			...$args
		);
	}
}
