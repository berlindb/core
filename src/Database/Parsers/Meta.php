<?php
/**
 * Meta Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Meta
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.1.0
 */
namespace BerlinDB\Database\Parsers;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Class for generating SQL clauses that filter a primary query according to
 * meta.
 *
 * A helper that allows primary Query classes to filter their results by object
 * metadata, by generating `JOIN` and `WHERE` subclauses to be attached to the
 * primary SQL query string.
 *
 * @since 3.0.0
 *
 * @param array $query {
 *     Array of meta query clauses. When first-order clauses or sub-clauses use strings as
 *     their array keys, they may be referenced in the 'orderby' parameter of the parent query.
 *
 *     @type string $relation Optional. The MySQL keyword used to join the clauses of the query.
 *                            Accepts 'AND' or 'OR'. Default 'AND'.
 *     @type array  ...$0 {
 *         Optional. An array of first-order clause parameters, or another fully-formed meta query.
 *
 *         @type string|string[] $key         Meta key or keys to filter by.
 *         @type string          $compare_key MySQL operator used for comparing the $key. Accepts:
 *                                            - '='
 *                                            - '!='
 *                                            - 'LIKE'
 *                                            - 'NOT LIKE'
 *                                            - 'IN'
 *                                            - 'NOT IN'
 *                                            - 'REGEXP'
 *                                            - 'NOT REGEXP'
 *                                            - 'RLIKE',
 *                                            - 'EXISTS' (alias of '=')
 *                                            - 'NOT EXISTS' (alias of '!=')
 *                                            Default is 'IN' when `$key` is an array, '=' otherwise.
 *         @type string          $type_key    MySQL data type that the meta_key column will be CAST to for
 *                                            comparisons. Accepts 'BINARY' for case-sensitive regular expression
 *                                            comparisons. Default is ''.
 *         @type string|string[] $value       Meta value or values to filter by.
 *         @type string          $compare     MySQL operator used for comparing the $value. Accepts:
 *                                            - '=',
 *                                            - '!='
 *                                            - '>'
 *                                            - '>='
 *                                            - '<'
 *                                            - '<='
 *                                            - 'LIKE'
 *                                            - 'NOT LIKE'
 *                                            - 'IN'
 *                                            - 'NOT IN'
 *                                            - 'BETWEEN'
 *                                            - 'NOT BETWEEN'
 *                                            - 'REGEXP'
 *                                            - 'NOT REGEXP'
 *                                            - 'RLIKE'
 *                                            - 'EXISTS'
 *                                            - 'NOT EXISTS'
 *                                            Default is 'IN' when `$value` is an array, '=' otherwise.
 *         @type string          $type        MySQL data type that the meta_value column will be CAST to for
 *                                            comparisons. Accepts:
 *                                            - 'NUMERIC'
 *                                            - 'BINARY'
 *                                            - 'CHAR'
 *                                            - 'DATE'
 *                                            - 'DATETIME'
 *                                            - 'DECIMAL'
 *                                            - 'SIGNED'
 *                                            - 'TIME'
 *                                            - 'UNSIGNED'
 *                                            Default is 'CHAR'.
 *     }
 * }
 */
class Meta {

	use \BerlinDB\Database\Traits\Parser {
		get_sql as get_trait_sql;
	}

	/**
	 * Database table to query for the metadata.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $meta_table = '';

	/**
	 * Column in meta_table that represents the ID of the object the metadata
	 * belongs to.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $meta_column = '';

	/**
	 * Database table where the metadata objects are stored.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $primary_table = '';

	/**
	 * Column in primary_table that represents the ID of the object.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $primary_column = '';

	/**
	 * A flat list of table aliases used in JOIN clauses.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	public $table_aliases = array();

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
		$first_keys = array(
			'key',
			'value',
			'meta_query'
		);

		return $first_keys;
	}

	/**
	 * Constructs a meta query based on 'meta_*' query vars
	 *
	 * @since 3.0.0
	 *
	 * @param array $qv     The query variables.
	 * @param Query $caller Query class.
	 */
	public function parse_query_vars( $qv = array(), $caller = null ) {

		// Default empty query.
		$meta_query = array();

		/*
		 * For orderby=meta_value to work correctly, simple query needs to be
		 * first (so that its table join is against an unaliased meta table) and
		 * needs to be its own clause (so it doesn't interfere with the logic of
		 * the rest of the meta_query).
		 */
		$simple_keys       = array( 'key', 'compare', 'type', 'compare_key', 'type_key' );
		$simple_meta_query = array();

		// Loop through simple keys.
		foreach ( $simple_keys as $key ) {
			if ( ! empty( $qv[ "meta_{$key}" ] ) ) {
				$simple_meta_query[ $key ] = $qv[ "meta_{$key}" ];
			}
		}

		// Back-compat for setting 'meta_value' = '' by default.
		if ( isset( $qv['meta_value'] ) && ( '' !== $qv['meta_value'] ) && ( ! is_array( $qv['meta_value'] ) || $qv['meta_value'] ) ) {
			$simple_meta_query['value'] = $qv['meta_value'];
		}

		// Already exists?
		$existing_meta_query = isset( $qv['meta_query'] ) && is_array( $qv['meta_query'] )
			? $qv['meta_query']
			: array();

		// Combine via "AND" relation.
		if ( ! empty( $simple_meta_query ) && ! empty( $existing_meta_query ) ) {
			$meta_query = array(
				'relation' => 'AND',
				$simple_meta_query,
				$existing_meta_query,
			);

		// Only primary.
		} elseif ( ! empty( $simple_meta_query ) ) {
			$meta_query = array(
				$simple_meta_query,
			);

		// Only existing.
		} elseif ( ! empty( $existing_meta_query ) ) {
			$meta_query = $existing_meta_query;
		}

		// Setup
		$this->__construct( $meta_query, $caller );
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

		// Attempt to get the secondary table.
		$meta_table = _get_meta_table( $type );

		// Bail if no object table.
		if ( empty( $meta_table ) ) {
			return false;
		}

		// Aliases.
		$this->table_aliases  = array();

		// Meta.
		$this->meta_table     = $this->sanitize_table_name( $meta_table );
		$this->meta_column    = $this->sanitize_column_name( "{$type}_id" );

		// Primary.
		$this->primary_table  = $this->sanitize_table_name( $primary_table );
		$this->primary_column = $this->sanitize_column_name( $primary_column );

		// Return parent.
		return $this->get_trait_sql( $type, $primary_table, $primary_column );
	}

	/**
	 * Generate SQL JOIN and WHERE clauses for a first-order query clause.
	 *
	 * "First-order" means that it's an array with a 'key' or 'value'.
	 *
	 * @since 3.0.0
	 *
	 * @param array  $clause       Query clause (passed by reference).
	 * @param array  $parent_query Parent query array.
	 * @param string $clause_key   Optional. The array key used to name the clause in the original `$meta_query`
	 *                             parameters. If not provided, a key will be generated automatically.
	 * @return string[] {
	 *     Array containing JOIN and WHERE SQL clauses to append to a first-order query.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	public function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Default return value.
		$retval = array(
			'where' => array(),
			'join'  => array(),
		);

		return $retval;

		// Default column.
		$column = 'meta_key';

		$hello = $this->get_first_order_clauses( $clause );

		//var_dump( $hello );

		/** Compare ***********************************************************/

		if ( isset( $clause['compare'] ) ) {
			$clause['compare'] = strtoupper( $clause['compare'] );
		} else {
			$clause['compare'] = isset( $clause['value'] ) && is_array( $clause['value'] )
				? 'IN'
				: '=';
		}

		// Operators.
		$non_numeric_operators = wp_filter_object_list( $this->operators, array( 'numeric' => false ), 'AND', 'compare' );
		$numeric_operators     = wp_filter_object_list( $this->operators, array( 'numeric' => true  ), 'AND', 'compare' );

		// Fallback if bad comparison.
		if ( ! in_array( $clause['compare'], $non_numeric_operators, true ) && ! in_array( $clause['compare'], $numeric_operators, true ) ) {
			$clause['compare'] = '=';
		}

		$meta_compare = $clause['compare'];

		/** Compare Key *******************************************************/

		if ( isset( $clause['compare_key'] ) ) {
			$clause['compare_key'] = strtoupper( $clause['compare_key'] );
		} else {
			$clause['compare_key'] = isset( $clause['key'] ) && is_array( $clause['key'] )
				? 'IN'
				: '=';
		}

		if ( ! in_array( $clause['compare_key'], $non_numeric_operators, true ) ) {
			$clause['compare_key'] = '=';
		}

		$meta_compare_key = $clause['compare_key'];

		/** JOIN clause *******************************************************/

		$join = '';

		/**
		 * We prefer to avoid joins if possible.
		 *
		 * Look for an existing join compatible with this clause.
		 */
		$alias = $this->find_compatible_table_alias( $clause, $parent_query );

		// No compatible alias, sooo make one!
		if ( false === $alias ) {
			$i     = count( $this->table_aliases );
			$alias = ! empty( $i )
				? 'mt' . $i
				: $this->meta_table;

			// JOIN clauses for NOT EXISTS have their own syntax.
			if ( 'NOT EXISTS' === $meta_compare ) {
				$join .= " LEFT JOIN {$this->meta_table}";
				$join .= ! empty( $i )
					? " AS {$alias}"
					: '';

				if ( 'LIKE' === $meta_compare_key ) {
					$join .= $db->prepare( " ON ( {$this->primary_table}.{$this->primary_column} = {$alias}.{$this->meta_column} AND {$alias}.{$column} LIKE %s )", '%' . $db->esc_like( $clause['key'] ) . '%' );
				} else {
					$join .= $db->prepare( " ON ( {$this->primary_table}.{$this->primary_column} = {$alias}.{$this->meta_column} AND {$alias}.{$column} = %s )", $clause['key'] );
				}

			// All other JOIN clauses.
			} else {
				$join .= " INNER JOIN {$this->meta_table}";
				$join .= ! empty( $i )
					? " AS {$alias}"
					: '';
				$join .= " ON ( {$this->primary_table}.{$this->primary_column} = {$alias}.{$this->meta_column} )";
			}

			// Add to possible aliases.
			$this->table_aliases[] = $alias;

			// Add to return value.
			$retval['join'][]  = $join;
		}

		// Save the alias to this clause, for future siblings to find.
		$clause['alias'] = $alias;

		// Determine the data type.
		$_meta_type      = isset( $clause['type'] )
			? $clause['type']
			: '';
		$meta_type       = $this->get_cast_for_type( $_meta_type );
		$clause['cast']  = $meta_type;

		/**
		 * Fallback for clause keys is the table alias.
		 *
		 * Key must be a string.
		 */
		if ( is_int( $clause_key ) || ! $clause_key ) {
			$clause_key = $clause['alias'];
		}

		// Ensure unique clause keys, so none are overwritten.
		$iterator        = 1;
		$clause_key_base = $clause_key;

		while ( isset( $this->clauses[ $clause_key ] ) ) {
			$clause_key = $clause_key_base . '-' . $iterator;
			$iterator++;
		}

		// Store the clause in our flat array.
		$this->clauses[ $clause_key ] =& $clause;

		/** WHERE clause ******************************************************/

		// meta_key.
		if ( array_key_exists( 'key', $clause ) ) {
			if ( 'NOT EXISTS' === $meta_compare ) {
				$retval['where'][] = "{$alias}.{$this->meta_column} IS NULL";

			} else {

				// Get negative operators.
				$neg = wp_filter_object_list( $this->operators, array( 'positive' => false ), 'AND', 'compare' );

				/**
				 * In joined clauses negative operators have to be nested into a
				 * NOT EXISTS clause and flipped, to avoid returning records with
				 * matching post IDs but different meta keys. Here we prepare the
				 * nested clause.
				 */
				if ( in_array( $meta_compare_key, $neg, true ) ) {

					// Negative clauses may be reused.
					$i              = count( $this->table_aliases );
					$subquery_alias = ! empty( $i )
						? 'mt' . $i
						: $this->meta_table;

					// Add to table_aliases.
					$this->table_aliases[] = $subquery_alias;

					// Setup start & end of meta compare SQL.
					$meta_compare_string_start  = 'NOT EXISTS (';
					$meta_compare_string_start .= "SELECT 1 FROM {$db->postmeta} {$subquery_alias} ";
					$meta_compare_string_start .= "WHERE {$subquery_alias}.post_ID = {$alias}.post_ID ";
					$meta_compare_string_end    = 'LIMIT 1';
					$meta_compare_string_end   .= ')';
				}

				// Default empty where.
				$where = '';

				// Which compare?
				switch ( $meta_compare_key ) {
					case '=':
					case 'EXISTS':
						$where = $db->prepare( "{$alias}.{$column} = %s", trim( $clause['key'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						break;

					case 'LIKE':
						$meta_compare_value = '%' . $db->esc_like( trim( $clause['key'] ) ) . '%';
						$where              = $db->prepare( "{$alias}.{$column} LIKE %s", $meta_compare_value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						break;

					case 'IN':
						$meta_compare_string = "{$alias}.{$column} IN (" . substr( str_repeat( ',%s', count( $clause['key'] ) ), 1 ) . ')';
						$where               = $db->prepare( $meta_compare_string, $clause['key'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						break;

					case 'RLIKE':
					case 'REGEXP':
						$operator = $meta_compare_key;
						if ( isset( $clause['type_key'] ) && 'BINARY' === strtoupper( $clause['type_key'] ) ) {
							$cast = 'BINARY';
						} else {
							$cast = '';
						}
						$where = $db->prepare( "{$alias}.{$column} {$operator} {$cast} %s", trim( $clause['key'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						break;

					case '!=':
					case 'NOT EXISTS':
						$meta_compare_string = $meta_compare_string_start . "AND {$subquery_alias}.{$column} = %s " . $meta_compare_string_end;
						$where               = $db->prepare( $meta_compare_string, $clause['key'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						break;

					case 'NOT LIKE':
						$meta_compare_string = $meta_compare_string_start . "AND {$subquery_alias}.{$column} LIKE %s " . $meta_compare_string_end;
						$meta_compare_value  = '%' . $db->esc_like( trim( $clause['key'] ) ) . '%';
						$where               = $db->prepare( $meta_compare_string, $meta_compare_value ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						break;
					case 'NOT IN':
						$array_subclause     = '(' . substr( str_repeat( ',%s', count( $clause['key'] ) ), 1 ) . ') ';
						$meta_compare_string = $meta_compare_string_start . "AND {$subquery_alias}.{$column} IN " . $array_subclause . $meta_compare_string_end;
						$where               = $db->prepare( $meta_compare_string, $clause['key'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						break;

					case 'NOT REGEXP':
						$operator = $meta_compare_key;

						if ( isset( $clause['type_key'] ) && ( 'BINARY' === strtoupper( $clause['type_key'] ) ) ) {
							$cast = 'BINARY';
						} else {
							$cast = '';
						}

						$meta_compare_string = $meta_compare_string_start . "AND {$subquery_alias}.{$column} REGEXP {$cast} %s " . $meta_compare_string_end;
						$where               = $db->prepare( $meta_compare_string, $clause['key'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						break;
				}

				// Only add if non-empty.
				if ( ! empty( $where ) ) {
					$retval['where'][] = $where;
				}
			}
		}

		// meta_value.
		if ( array_key_exists( 'value', $clause ) ) {
			$where = $this->build_value( $meta_compare, $clause['value'], '%s' );

			// Not empty, so maybe cast...
			if ( ! empty( $where ) ) {

				// Set column to meta_value
				$column = 'meta_value';

				// Default.
				if ( 'CHAR' === $meta_type ) {
					$retval['where'][] = "{$alias}.{$column} {$meta_compare} {$where}";

				// CAST().
				} else {
					$retval['where'][] = "CAST({$alias}.{$column} AS {$meta_type}) {$meta_compare} {$where}";
				}
			}
		}

		/*
		 * Multiple WHERE clauses (for meta_key and meta_value) should
		 * be joined in parentheses.
		 */
		if ( 1 < count( $retval['where'] ) ) {
			$retval['where'] = array( '( ' . implode( ' AND ', $retval['where'] ) . ' )' );
		}

		// Return join/where clauses.
		return $retval;
	}
}
