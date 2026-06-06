<?php
/**
 * Meta Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Meta
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Parsers;

// Exit if accessed directly.
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
class Meta extends Base {

	/**
	 * Internal identifier for this parser.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'meta';

	/**
	 * Top-level query var key this parser consumes, or null when operating per-column.
	 *
	 * @since 3.0.0
	 * @var string|null
	 */
	protected $query_var = 'meta_query';

	/**
	 * Column filter passed to get_column_names() to select relevant columns.
	 *
	 * @since 3.0.0
	 * @var array<string, bool>
	 */
	protected $column_filter = array( 'primary' => true );

	/**
	 * Suffix appended to each matching column name to form the per-column query var key.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $column_suffix = '_meta';

	/**
	 * Default value for the query var. Null defers to Query::$query_var_default_value.
	 *
	 * @since 3.0.0
	 * @var mixed
	 */
	protected $default = null;

	/**
	 * Whether this parser contributes ORDER BY SQL via get_orderby_sql().
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	public $sortable = true;

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
	 * @var list<string>
	 */
	public $table_aliases = array();

	/**
	 * Determines what first-order keys this parser recognises.
	 *
	 * Overrides the Parser trait default to fix the set of keys for meta
	 * queries: 'key', 'value', and 'meta_query'.
	 *
	 * @since 3.0.0
	 *
	 * @param list<string> $first_keys Unused. Subclass always returns a fixed set.
	 *
	 * @return list<string> The first-order keys.
	 */
	protected function get_first_keys( $first_keys = array() ) {
		return array(
			'key',
			'value',
			'meta_query',
		);
	}

	/**
	 * Normalises 'meta_*' shorthand vars into a structured meta_query array.
	 *
	 * Called by Parser::init() as a pre-processing hook. Returns the normalised
	 * array; init() then proceeds with it rather than the raw query vars.
	 *
	 * The shorthand keys are a flat alternative to passing a full meta_query
	 * array. When both are present they are combined with an AND relation.
	 *
	 * Accepted shorthand keys:
	 *   meta_key         — the meta key name
	 *   meta_value       — the meta value to compare against
	 *   meta_compare     — comparison operator (default '=')
	 *   meta_type        — cast type for the value (default 'CHAR')
	 *   meta_compare_key — comparison operator for the key column (default '=')
	 *   meta_type_key    — cast type for the key column (default 'CHAR')
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $qv The query variables.
	 *
	 * @return array<string, mixed> The normalised meta_query array.
	 */
	protected function parse_query_vars( $qv = array() ) {
		/*
		 * If $qv is already a meta_query clause array (narrowed by the caller
		 * before init() ran), return it unchanged. Numeric keys mean it's an
		 * array of clause arrays; 'relation' means a multi-clause query.
		 */
		if ( isset( $qv[ 'relation' ] ) || isset( $qv[0] ) ) {
			return $qv;
		}

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
		if ( isset( $qv[ 'meta_value' ] ) && ( '' !== $qv[ 'meta_value' ] ) && ( ! is_array( $qv[ 'meta_value' ] ) || $qv[ 'meta_value' ] ) ) {
			$simple_meta_query[ 'value' ] = $qv[ 'meta_value' ];
		}

		// Check for an existing meta_query argument.
		$existing_meta_query = isset( $qv[ 'meta_query' ] ) && is_array( $qv[ 'meta_query' ] )
			? $qv[ 'meta_query' ]
			: array();

		// Default empty query.
		$meta_query = array();

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

		// Return the normalised meta_query array; Parser::init() will process it.
		return $meta_query;
	}

	/**
	 * Generates SQL clauses to be appended to a main query.
	 *
	 * Overrides the trait method to resolve and store the meta table, meta column,
	 * primary table, and primary column before delegating to the shared implementation.
	 *
	 * The $type, $primary_table, and $primary_column parameters are restored from
	 * Berlin 2.1.0 for backwards compatibility. Callers such as Easy Digital Downloads
	 * pass these values directly and they are used as-is. When the parameters are empty
	 * (as in internal 3.0.0 usage via get_join_where_clauses()) the values are sourced
	 * from $this->caller instead.
	 *
	 * New code should call get_join_where_clauses() instead.
	 *
	 * @since 2.1.0
	 *
	 * @param string $type           Optional. Object type (e.g. 'post', 'comment'). When empty,
	 *                               sourced from $this->caller. Default ''.
	 * @param string $primary_table  Optional. Primary table for the object being filtered. When
	 *                               empty, sourced from $this->caller. Default ''.
	 * @param string $primary_column Optional. Column in $primary_table that holds the object ID.
	 *                               When empty, sourced from $this->caller. Default ''.
	 *
	 * @return array{join: string, where: string}|false Array with 'join' and 'where' SQL fragments,
	 *                                                   or false if no meta table exists for the type.
	 */
	public function get_sql( $type = '', $primary_table = '', $primary_column = '' ) {

		// Fall back to caller for missing $type.
		if ( empty( $type ) ) {
			$type = $this->caller?->get_meta_type() ?? '';
		}

		// Fall back to caller for missing primary table.
		if ( empty( $primary_table ) ) {
			$primary_table = $this->caller?->get_table_name() ?? '';
		}

		// Fall back to caller for missing primary column.
		if ( empty( $primary_column ) ) {
			$primary_column = $this->caller?->get_primary_column_name() ?? '';
		}

		// Attempt to get the secondary table.
		$meta_table = _get_meta_table( $type );

		// Bail if no object table.
		if ( empty( $meta_table ) ) {
			return false;
		}

		// Aliases.
		$this->table_aliases = array();

		// Meta.
		$meta_table_sanitized  = $this->sanitize_table_name( $meta_table );
		$meta_column_sanitized = $this->sanitize_column_name( $type . '_id' );

		// Primary.
		$primary_table_sanitized  = $this->sanitize_table_name( $primary_table );
		$primary_column_sanitized = $this->sanitize_column_name( $primary_column );

		if ( false === $meta_table_sanitized || false === $meta_column_sanitized || false === $primary_table_sanitized || false === $primary_column_sanitized ) {
			return false;
		}

		$this->meta_table     = $meta_table_sanitized;
		$this->meta_column    = $meta_column_sanitized;
		$this->primary_table  = $primary_table_sanitized;
		$this->primary_column = $primary_column_sanitized;

		// Delegate to the shared implementation (bypasses this override).
		return parent::get_join_where_clauses();
	}

	/**
	 * Generates SQL clauses to be appended to a main query.
	 *
	 * @since 3.0.0
	 *
	 * @return array{join: string, where: string}|false Array with 'join' and 'where' SQL fragments,
	 *                                                   or false if no meta table exists for the type.
	 */
	public function get_join_where_clauses() {
		/*
		 * Get primary metadata from the caller query.
		 * Use the table alias (not the full name) so the ON clause matches
		 * the alias used in the main query's FROM clause.
		 */
		$type           = $this->caller?->get_meta_type() ?? '';
		$primary_table  = $this->caller?->get_table_alias() ?? '';
		$primary_column = $this->caller?->get_primary_column_name() ?? '';

		// Attempt to get the secondary table.
		$meta_table = _get_meta_table( $type );

		// Bail if no object table.
		if ( empty( $meta_table ) ) {
			return false;
		}

		// Aliases.
		$this->table_aliases = array();

		// Meta.
		$meta_table_sanitized  = $this->sanitize_table_name( $meta_table );
		$meta_column_sanitized = $this->sanitize_column_name( $type . '_id' );

		// Primary.
		$primary_table_sanitized  = $this->sanitize_table_name( $primary_table );
		$primary_column_sanitized = $this->sanitize_column_name( $primary_column );

		if ( false === $meta_table_sanitized || false === $meta_column_sanitized || false === $primary_table_sanitized || false === $primary_column_sanitized ) {
			return false;
		}

		$this->meta_table     = $meta_table_sanitized;
		$this->meta_column    = $meta_column_sanitized;
		$this->primary_table  = $primary_table_sanitized;
		$this->primary_column = $primary_column_sanitized;

		// Delegate to the shared implementation (bypasses this override).
		return parent::get_join_where_clauses();
	}

	/**
	 * Generate SQL JOIN and WHERE clauses for a first-order query clause.
	 *
	 * "First-order" means that it's an array with a 'key' or 'value'.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $clause       Query clause (passed by reference).
	 * @param array<string, mixed> $parent_query Parent query array.
	 * @param string               $clause_key   Optional. The array key used to name the clause in the original `$meta_query`
	 *                                           parameters. If not provided, a key will be generated automatically.
	 * @return array{join: list<string>, where: list<string>} {
	 *     Array containing JOIN and WHERE SQL clause fragments for a first-order query.
	 *     Both values are arrays of strings; the caller merges them into final SQL.
	 *
	 *     @type string[] $join  JOIN fragments to append to the main JOIN clause.
	 *     @type string[] $where WHERE fragments to append to the main WHERE clause.
	 * }
	 */
	protected function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {

		// Default return value.
		$retval = array(
			'where' => array(),
			'join'  => array(),
		);

		// Default column.
		$column = 'meta_key';

		// Pre-quote class-level identifiers (already sanitized by get_sql / get_join_where_clauses).
		$qt_meta_table     = $this->quote_identifier( $this->meta_table );
		$qt_primary_table  = $this->quote_identifier( $this->primary_table );
		$qt_meta_column    = $this->quote_identifier( $this->meta_column );
		$qt_primary_column = $this->quote_identifier( $this->primary_column );
		$qt_column         = $this->quote_identifier( $column );

		/** Compare */

		if ( isset( $clause[ 'compare' ] ) ) {
			$clause[ 'compare' ] = strtoupper( $clause[ 'compare' ] );
		} else {
			$clause[ 'compare' ] = isset( $clause[ 'value' ] ) && is_array( $clause[ 'value' ] )
				? 'IN'
				: '=';
		}

		// Operators.
		$non_numeric_operators = $this->get_operators( array( 'numeric' => false ) );
		$numeric_operators     = $this->get_operators( array( 'numeric' => true ) );

		// Fallback if bad comparison.
		if ( ! in_array( $clause[ 'compare' ], $non_numeric_operators, true ) && ! in_array( $clause[ 'compare' ], $numeric_operators, true ) ) {
			$clause[ 'compare' ] = '=';
		}

		$meta_compare = $clause[ 'compare' ];

		// Resolve the SQL operator (may differ from the compare identifier).
		$operator         = $this->get_operator( $meta_compare );
		$meta_sql_compare = $operator ? $operator->get_sql_compare() : $meta_compare;

		/** Compare Key */

		if ( isset( $clause[ 'compare_key' ] ) ) {
			$clause[ 'compare_key' ] = strtoupper( $clause[ 'compare_key' ] );
		} else {
			$clause[ 'compare_key' ] = isset( $clause[ 'key' ] ) && is_array( $clause[ 'key' ] )
				? 'IN'
				: '=';
		}

		if ( ! in_array( $clause[ 'compare_key' ], $non_numeric_operators, true ) ) {
			$clause[ 'compare_key' ] = '=';
		}

		$meta_compare_key = $clause[ 'compare_key' ];

		/** JOIN clause */

		$join = '';

		/*
		 * We prefer to avoid joins if possible.
		 *
		 * Look for an existing join compatible with this clause.
		 */
		$alias = $this->find_compatible_table_alias( $clause, $parent_query );

		// No compatible alias, so make one!
		if ( false === $alias ) {
			$i        = count( $this->table_aliases );
			$alias    = ! empty( $i )
				? 'mt' . $i
				: $this->meta_table;
			$qt_alias = $this->quote_identifier( $alias );

			// JOIN clauses for NOT EXISTS have their own syntax.
			if ( 'NOT EXISTS' === $meta_compare ) {
				$join .= " LEFT JOIN {$qt_meta_table}";
				$join .= ! empty( $i )
					? " AS {$qt_alias}"
					: '';

				if ( 'LIKE' === $meta_compare_key ) {
					$join .= $this->db()->prepare( " ON ( {$qt_primary_table}.{$qt_primary_column} = {$qt_alias}.{$qt_meta_column} AND {$qt_alias}.{$qt_column} LIKE %s )", '%' . $this->db()->esc_like( $clause[ 'key' ] ) . '%' );
				} else {
					$join .= $this->db()->prepare( " ON ( {$qt_primary_table}.{$qt_primary_column} = {$qt_alias}.{$qt_meta_column} AND {$qt_alias}.{$qt_column} = %s )", $clause[ 'key' ] );
				}

				// All other JOIN clauses.
			} else {
				$join .= " INNER JOIN {$qt_meta_table}";
				$join .= ! empty( $i )
					? " AS {$qt_alias}"
					: '';
				$join .= " ON ( {$qt_primary_table}.{$qt_primary_column} = {$qt_alias}.{$qt_meta_column} )";
			}

			// Add to possible aliases.
			$this->table_aliases[] = $alias;

			// Add to return value.
			$retval[ 'join' ][] = $join;
		}

		// Save the alias to this clause, for future siblings to find.
		$clause[ 'alias' ] = $alias;

		/*
		 * (Re)quote alias here so WHERE clauses below always have it, even when
		 * find_compatible_table_alias() returned an existing alias above.
		 */
		$qt_alias = $this->quote_identifier( $alias );

		// Determine the data type.
		$meta_type        = $this->get_cast_for_type( $clause[ 'type' ] ?? '' );
		$clause[ 'cast' ] = $meta_type;

		/*
		 * Fallback for clause keys is the table alias.
		 *
		 * Key must be a string.
		 */
		if ( is_int( $clause_key ) || ! $clause_key ) {
			$clause_key = $clause[ 'alias' ];
		}

		// Ensure unique clause keys, so none are overwritten.
		$iterator        = 1;
		$clause_key_base = $clause_key;

		while ( isset( $this->clauses[ $clause_key ] ) ) {
			$clause_key = $clause_key_base . '-' . $iterator;
			++$iterator;
		}

		// Store the clause in our flat array.
		$this->clauses[ $clause_key ] =& $clause;

		/** WHERE clause */

		// meta_key.
		if ( array_key_exists( 'key', $clause ) ) {
			if ( 'NOT EXISTS' === $meta_compare ) {
				$retval[ 'where' ][] = "{$qt_alias}.{$qt_meta_column} IS NULL";

			} else {

				// Get negative operators.
				$neg = $this->get_operators( array( 'positive' => false ) );

				// Initialize subquery fragments; only populated for negative compare_key operators.
				$subquery_alias            = '';
				$qt_subquery_alias         = '';
				$meta_compare_string_start = '';
				$meta_compare_string_end   = '';

				/*
				 * In joined clauses negative operators have to be nested into a
				 * NOT EXISTS clause and flipped, to avoid returning records with
				 * matching post IDs but different meta keys. Here we prepare the
				 * nested clause.
				 */
				if ( in_array( $meta_compare_key, $neg, true ) ) {

					// Negative clauses may be reused.
					$i                 = count( $this->table_aliases );
					$subquery_alias    = ! empty( $i )
						? 'mt' . $i
						: $this->meta_table;
					$qt_subquery_alias = $this->quote_identifier( $subquery_alias );

					// Add to table_aliases.
					$this->table_aliases[] = $subquery_alias;

					// Setup start & end of meta compare SQL.
					$meta_compare_string_start  = 'NOT EXISTS (';
					$meta_compare_string_start .= "SELECT 1 FROM {$qt_meta_table} {$qt_subquery_alias} ";
					$meta_compare_string_start .= "WHERE {$qt_subquery_alias}.{$qt_meta_column} = {$qt_alias}.{$qt_meta_column} ";
					$meta_compare_string_end    = 'LIMIT 1';
					$meta_compare_string_end   .= ')';
				}

				// Default empty where.
				$where = '';

				// Which compare?
				switch ( $meta_compare_key ) {
					case '=':
					case 'EXISTS':
						$where = $this->db()->prepare( "{$qt_alias}.{$qt_column} = %s", trim( $clause[ 'key' ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						break;

					case 'LIKE':
						$meta_compare_value = '%' . $this->db()->esc_like( trim( $clause[ 'key' ] ) ) . '%';
						$where              = $this->db()->prepare( "{$qt_alias}.{$qt_column} LIKE %s", $meta_compare_value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						break;

					case 'IN':
						$meta_compare_string = "{$qt_alias}.{$qt_column} IN (" . substr( str_repeat( ',%s', count( (array) $clause[ 'key' ] ) ), 1 ) . ')';
						$where               = $this->db()->prepare( $meta_compare_string, $clause[ 'key' ] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						break;

					case 'RLIKE':
					case 'REGEXP':
						$regex_op = $meta_compare_key;
						if ( isset( $clause[ 'type_key' ] ) && 'BINARY' === strtoupper( $clause[ 'type_key' ] ) ) {
							$cast = 'BINARY';
						} else {
							$cast = '';
						}
						$where = $this->db()->prepare( "{$qt_alias}.{$qt_column} {$regex_op} {$cast} %s", trim( $clause[ 'key' ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						break;

					case '!=':
					case 'NOT EXISTS':
						$meta_compare_string = $meta_compare_string_start . "AND {$qt_subquery_alias}.{$qt_column} = %s " . $meta_compare_string_end;
						$where               = $this->db()->prepare( $meta_compare_string, $clause[ 'key' ] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						break;

					case 'NOT LIKE':
						$meta_compare_string = $meta_compare_string_start . "AND {$qt_subquery_alias}.{$qt_column} LIKE %s " . $meta_compare_string_end;
						$meta_compare_value  = '%' . $this->db()->esc_like( trim( $clause[ 'key' ] ) ) . '%';
						$where               = $this->db()->prepare( $meta_compare_string, $meta_compare_value ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						break;
					case 'NOT IN':
						$array_subclause     = '(' . substr( str_repeat( ',%s', count( (array) $clause[ 'key' ] ) ), 1 ) . ') ';
						$meta_compare_string = $meta_compare_string_start . "AND {$qt_subquery_alias}.{$qt_column} IN " . $array_subclause . $meta_compare_string_end;
						$where               = $this->db()->prepare( $meta_compare_string, $clause[ 'key' ] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						break;

					case 'NOT REGEXP':
						if ( isset( $clause[ 'type_key' ] ) && ( 'BINARY' === strtoupper( $clause[ 'type_key' ] ) ) ) {
							$cast = 'BINARY';
						} else {
							$cast = '';
						}

						$meta_compare_string = $meta_compare_string_start . "AND {$qt_subquery_alias}.{$qt_column} REGEXP {$cast} %s " . $meta_compare_string_end;
						$where               = $this->db()->prepare( $meta_compare_string, $clause[ 'key' ] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						break;
				}

				// Only add if non-empty.
				if ( ! empty( $where ) ) {
					$retval[ 'where' ][] = $where;
				}
			}
		}

		// meta_value — build_value() normalises the mixed input.
		if ( array_key_exists( 'value', $clause ) ) {
			$where = $this->build_value( $meta_compare, $clause[ 'value' ], '%s' );

			// Not empty, so maybe cast...
			if ( ! empty( $where ) ) {

				// Set column to meta_value.
				$column    = 'meta_value';
				$qt_column = $this->quote_identifier( $column );

				/*
				 * Optionally CAST the value column. Meta uses 'CHAR' as its "no
				 * cast" sentinel (the native string type), so map it to '' for
				 * cast_reference(); any other type wraps in CAST().
				 */
				$qt_ref              = "{$qt_alias}.{$qt_column}";
				$value_cast          = ( 'CHAR' === $meta_type ) ? '' : $meta_type;
				$retval[ 'where' ][] = $this->cast_reference( $qt_ref, $value_cast ) . " {$meta_sql_compare} {$where}";
			}
		}

		/*
		 * Multiple WHERE clauses (for meta_key and meta_value) should
		 * be joined in parentheses.
		 */
		if ( 1 < count( $retval[ 'where' ] ) ) {
			$retval[ 'where' ] = array( '( ' . implode( ' AND ', $retval[ 'where' ] ) . ' )' );
		}

		// Return join/where clauses.
		return $retval;
	}

	/**
	 * Build an ORDER BY fragment for meta orderby values.
	 *
	 * Handles two modes:
	 *  - Named clause key (e.g. orderby='my_clause'): looks the clause up
	 *    directly in $this->clauses, using whatever alias and cast it carries.
	 *  - 'meta_value' / 'meta_value_num': falls back to the first registered
	 *    clause (the simple meta_key clause, always processed first per
	 *    parse_query_vars()). 'meta_value_num' forces a SIGNED cast.
	 *
	 * The $alias parameter is not used; Meta always references the JOIN alias
	 * established during get_sql_for_clause(), not the primary table alias.
	 *
	 * @since 3.0.0
	 *
	 * @param string $orderby The raw orderby value.
	 * @param bool   $alias   Unused. Meta always uses its own JOIN alias.
	 *
	 * @return string SQL fragment, or empty string if no matching clause found.
	 */
	public function get_orderby_sql( $orderby = '', $alias = true ) {

		// Bail if no caller.
		if ( empty( $this->caller ) ) {
			return '';
		}

		// Named clause key: look it up directly.
		$clause = $this->clauses[ $orderby ] ?? null;

		// meta_value / meta_value_num: use the first (simple) clause.
		if ( null === $clause && ( 'meta_value' === $orderby || 'meta_value_num' === $orderby ) ) {
			$clause = ! empty( $this->clauses ) ? reset( $this->clauses ) : null;
		}

		// Bail if not array or no alias on it.
		if ( ! is_array( $clause ) || empty( $clause[ 'alias' ] ) ) {
			return '';
		}

		// Pre-quote identifiers.
		$alias_val = $clause[ 'alias' ] ?? '';
		$alias_str = is_scalar( $alias_val ) ? (string) $alias_val : '';
		$qt_alias  = $this->quote_identifier( $alias_str );
		$qt_column = $this->quote_identifier( 'meta_value' );
		$cast_val  = $clause[ 'cast' ] ?? 'CHAR';
		$cast_str  = is_scalar( $cast_val ) ? (string) $cast_val : 'CHAR';
		$cast      = ( 'meta_value_num' === $orderby )
			? 'SIGNED'
			: $cast_str;

		// Meta's 'CHAR' sentinel means no cast (native string type).
		$cast = ( 'CHAR' === $cast ) ? '' : $cast;

		/*
		 * Return the ORDER BY fragment, with casting if needed. Meta always
		 * uses the JOIN alias established in get_sql_for_clause(), never the
		 * primary table alias.
		 */
		return $this->cast_reference( "{$qt_alias}.{$qt_column}", $cast );
	}

	/**
	 * Map a meta_query 'type' value to a MySQL CAST() target.
	 *
	 * This is meta_query's own (back-compat) cast vocabulary: an empty or
	 * unsupported type falls back to 'CHAR' (Meta's "no cast" sentinel), and the
	 * legacy 'NUMERIC' alias folds to 'SIGNED'. For a clean, general SQL cast
	 * validator without these quirks, use Sanitizer::sanitize_sql_cast_type().
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
}
