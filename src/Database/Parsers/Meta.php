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
	 * @var array<string,bool>
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
	protected $sortable = true;

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
	 * Determines what first-order keys this parser recognizes.
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
	 * Normalizes 'meta_*' shorthand vars into a structured meta_query array.
	 *
	 * Called by Parser::init() as a pre-processing hook. Returns the normalized
	 * array; init() then proceeds with it rather than the raw query vars.
	 *
	 * The shorthand keys are a flat alternative to passing a full meta_query
	 * array. When both are present they are combined with an AND relation.
	 *
	 * Accepted shorthand keys:
	 *   meta_key         - the meta key name
	 *   meta_value       - the meta value to compare against
	 *   meta_compare     - comparison operator (default '=')
	 *   meta_type        - cast type for the value (default 'CHAR')
	 *   meta_compare_key - comparison operator for the key column (default '=')
	 *   meta_type_key    - cast type for the key column (default 'CHAR')
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $qv The query variables.
	 *
	 * @return array<string,mixed> The normalized meta_query array.
	 */
	protected function parse_query_vars( $qv = array() ) {

		/*
		 * If $qv is already a meta_query clause array (narrowed by the caller
		 * before init() ran), return it unchanged. A positional list has a [0];
		 * a multi-clause group has a 'relation'; a NAMED-only meta_query (its
		 * clauses keyed by string name, e.g. 'price_clause' => array( ... )) has
		 * neither, so its members are inspected. Without the named case a
		 * string-keyed meta_query is mistaken for flat meta_* vars and silently
		 * dropped - it would neither filter nor sort.
		 */
		if ( $this->is_meta_query_clauses( $qv ) ) {
			return $qv;
		}

		/*
		 * For orderby=meta_value to work correctly, simple query needs to be
		 * first (so that its table join is against an unaliased meta table) and
		 * needs to be its own clause (so it doesn't interfere with the logic of
		 * the rest of the meta_query).
		 */

		// The flat meta_* vars become one simple clause (shared with the store builder).
		$simple_meta_query = $this->get_simple_meta_clause( $qv );

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

		// Return the normalized meta_query array; Parser::init() will process it.
		return $meta_query;
	}

	/**
	 * Whether $qv is already a formed meta_query (a set of clauses) rather than
	 * the flat top-level query vars.
	 *
	 * Recognizes four shapes: a positional list ( `[0]` is a clause ), a
	 * multi-clause group ( a 'relation' ), a bare first-order clause ( the array
	 * IS a single clause, e.g. array( 'key' => ..., 'value' => ... ) ), and a NAMED
	 * set ( string-keyed clauses ). The first two are cheap key checks; the last
	 * two test for first-order meta keys, since neither carries a structural
	 * marker of its own.
	 *
	 * Full query vars always carry the 'meta_query' container key ( the parser's
	 * own sentinel, or an explicit array ), while a narrowed clause set does not;
	 * gating the key tests on its absence keeps a flat query ( whose own keys or
	 * array members like `orderby`/`fields` are not clauses ) on the simple-clause
	 * path. Uses get_first_keys() rather than is_first_order_clause() because
	 * init() runs parse_query_vars() before $this->first_keys is populated.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $qv The ( possibly narrowed ) query vars.
	 * @return bool True when $qv is already a meta_query clause set.
	 */
	private function is_meta_query_clauses( array $qv ): bool {

		// Positional list, or an explicit multi-clause relation.
		if ( isset( $qv[ 'relation' ] ) || isset( $qv[0] ) ) {
			return true;
		}

		// Full query vars carry the container key; a narrowed clause set does not.
		if ( array_key_exists( 'meta_query', $qv ) ) {
			return false;
		}

		$first_keys = $this->get_first_keys();

		// A bare first-order clause: the array itself is a single meta clause.
		if ( array() !== array_intersect( $first_keys, array_keys( $qv ) ) ) {
			return true;
		}

		// A named set: any member shaped like a first-order meta clause.
		foreach ( $qv as $member ) {
			if ( is_array( $member ) && ( array() !== array_intersect( $first_keys, array_keys( $member ) ) ) ) {
				return true;
			}
		}

		return false;
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
	 * @deprecated 3.1.0 Use get_join_where_clauses() instead.
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

		// Fall back to caller for any missing argument.
		if ( empty( $type ) ) {
			$type = $this->caller?->get_meta_type() ?? '';
		}

		if ( empty( $primary_table ) ) {
			$primary_table = $this->caller?->get_table_name() ?? '';
		}

		if ( empty( $primary_column ) ) {
			$primary_column = $this->caller?->get_primary_column_name() ?? '';
		}

		/*
		 * BC entry point: resolves the primary table by NAME (the caller-driven
		 * get_join_where_clauses() uses the table ALIAS instead).
		 */
		return $this->build_meta_join_where( $type, $primary_table, $primary_column );
	}

	/**
	 * Generates SQL clauses to be appended to a main query.
	 *
	 * @since 3.0.0
	 * @internal Query/Parser collaborator API.
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

		return $this->build_meta_join_where( $type, $primary_table, $primary_column );
	}

	/**
	 * Resolve the meta/primary table metadata, then build the JOIN/WHERE clauses.
	 *
	 * The shared tail of get_sql() and get_join_where_clauses(): derives the meta
	 * table from $type, sanitizes it, the {type}_id foreign key, and the primary
	 * table/column, stores them on the parser, then delegates to the shared base
	 * implementation. The two public entry points differ only in how they resolve
	 * their inputs (positional args + table NAME vs caller + table ALIAS).
	 *
	 * @since 3.1.0
	 * @internal
	 *
	 * The params carry no native type and are documented `string` only as the
	 * expected type: get_sql() is a back-compat entry point, so a legacy caller
	 * passing a non-string (effectively `mixed` at runtime) is sanitized to false
	 * here - as it was before this tail was extracted - rather than raising a
	 * TypeError. Pass strings; non-strings fail closed.
	 *
	 * @param string $type           Meta type (e.g. 'post'); the meta table is derived from it.
	 * @param string $primary_table  Primary table name or alias.
	 * @param string $primary_column Primary key column.
	 * @return array{join: string, where: string}|false Clause fragments, or false when unresolvable.
	 */
	private function build_meta_join_where( $type, $primary_table, $primary_column ) {

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
	 * @param array<string,mixed> $clause       Query clause (passed by reference).
	 * @param array<string,mixed> $parent_query Parent query array.
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

		/*
		 * Operators. A value-side unary predicate ( IS NULL / IS NOT NULL ) is now
		 * built ( #211 ); an unrecognized compare still falls back to '=' rather than
		 * silently no-op'ing. Unary is kept OUT of $non_numeric_operators because that
		 * set also validates the KEY side ( compare_key ), where unary is meaningless.
		 */
		$non_numeric_operators = $this->get_operators(
			array(
				'numeric' => false,
				'unary'   => false,
			)
		);
		$numeric_operators     = $this->get_operators( array( 'numeric' => true ) );
		$unary_operators       = $this->get_operators( array( 'unary' => true ) );

		// Fallback if bad comparison.
		if (
			! in_array( $clause[ 'compare' ], $non_numeric_operators, true )
			&& ! in_array( $clause[ 'compare' ], $numeric_operators, true )
			&& ! in_array( $clause[ 'compare' ], $unary_operators, true )
		) {
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

				/*
				 * Build the meta_key comparison through the operator path - the same
				 * cast_reference() + operator->get_sql_compare() + build_value() assembly
				 * the meta_value side below uses. compare_key EXISTS / NOT EXISTS are key
				 * equality / inequality ( they never reach the value-side EXISTS handling
				 * above ), so fold them to = / != before resolving the operator.
				 */
				$key_compare = $meta_compare_key;

				if ( 'EXISTS' === $key_compare ) {
					$key_compare = '=';
				} elseif ( 'NOT EXISTS' === $key_compare ) {
					$key_compare = '!=';
				}

				$key_operator = $this->get_operator( $key_compare );

				// Default empty where; an unresolvable operator fails the clause closed.
				$where = '';

				if ( false !== $key_operator ) {

					// A positive operator renders inline.
					if ( $key_operator->is_positive() ) {
						$where = $this->build_meta_key_comparison( $qt_alias, $qt_column, $key_operator, $key_compare, $clause );

						/*
						 * A negative operator nests its POSITIVE opposite inside a
						 * correlated NOT EXISTS, so a different meta row on the same object
						 * cannot satisfy it ( `key != 'x'` must exclude objects that also
						 * hold another key ). Mirrors the old bespoke subquery flip.
						 */
					} else {
						$positive_compare  = $key_operator->get_opposite_compare();
						$positive_operator = $this->get_operator( $positive_compare );

						if ( false !== $positive_operator ) {
							$i                     = count( $this->table_aliases );
							$subquery_alias        = ! empty( $i ) ? 'mt' . $i : $this->meta_table;
							$qt_subquery_alias     = $this->quote_identifier( $subquery_alias );
							$this->table_aliases[] = $subquery_alias;

							$inner = $this->build_meta_key_comparison( $qt_subquery_alias, $qt_column, $positive_operator, $positive_compare, $clause );

							if ( '' !== $inner ) {
								$where  = "NOT EXISTS (SELECT 1 FROM {$qt_meta_table} {$qt_subquery_alias} ";
								$where .= "WHERE {$qt_subquery_alias}.{$qt_meta_column} = {$qt_alias}.{$qt_meta_column} ";
								$where .= "AND {$inner} LIMIT 1)";
							}
						}
					}
				}

				/*
				 * A key filter that produced no predicate ( an empty IN / NOT IN list,
				 * or an unresolvable operator ) must match NOTHING - never widen the
				 * INNER JOIN to any meta row. Fail closed.
				 */
				$retval[ 'where' ][] = ( '' === $where )
					? '1 = 0'
					: $where;
			}
		}

		// meta_value.
		$qt_value_ref = "{$qt_alias}." . $this->quote_identifier( 'meta_value' );

		if ( ( false !== $operator ) && $operator->is_unary() ) {

			/*
			 * A value-side unary predicate ( IS NULL / IS NOT NULL ) is value-less, so
			 * any supplied 'value' is ignored. The INNER JOIN means a matching meta row
			 * must exist for the object, so key ABSENCE never satisfies IS NULL; and no
			 * CAST is applied ( NULL is NULL regardless of the declared type ).
			 */
			$retval[ 'where' ][] = "{$qt_value_ref} {$meta_sql_compare}";

			// meta_value - build_value() normalizes the mixed input.
		} elseif ( array_key_exists( 'value', $clause ) ) {
			$where = $this->build_value( $meta_compare, $clause[ 'value' ], '%s' );

			// Not empty, so maybe cast...
			if ( ! empty( $where ) ) {

				/*
				 * Optionally CAST the value column. Meta uses 'CHAR' as its "no
				 * cast" sentinel (the native string type), so map it to '' for
				 * cast_reference(); any other type wraps in CAST().
				 */
				$value_cast          = ( 'CHAR' === $meta_type ) ? '' : $meta_type;
				$retval[ 'where' ][] = $this->cast_reference( $qt_value_ref, $value_cast ) . " {$meta_sql_compare} {$where}";
			}
		}

		/*
		 * Join the WHERE clauses (for meta_key and meta_value) with AND through the
		 * shared BooleanGroup renderer: a single clause stays bare, multiple wrap in
		 * parentheses.
		 */
		$combined = \BerlinDB\Database\Clauses\BooleanGroup::combine( 'AND', $retval[ 'where' ] );

		$retval[ 'where' ] = ( '' === $combined )
			? array()
			: array( $combined );

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
	 * @internal Query/Parser collaborator API.
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

		/*
		 * Store-backed path: the meta_query was built into relation_query EXISTS,
		 * so there is no JOIN alias to order on - order by a correlated subquery
		 * against the sibling for the key normalize_query_vars() recorded (under
		 * 'meta_value' / 'meta_value_num' or a named clause). Returns '' when this
		 * token isn't a recorded store order, falling through to the WP clause path.
		 */
		$store_sql = $this->get_store_meta_orderby_sql( (string) $orderby );

		if ( '' !== $store_sql ) {
			return $store_sql;
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
		$cast      = $this->meta_cast_to_sql( $cast_str, ( 'meta_value_num' === $orderby ) );

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

	/**
	 * Build one meta_key comparison through the operator path.
	 *
	 * Mirrors the meta_value assembly: an optionally CAST-wrapped key reference, the
	 * operator's compare string, and the operator's value SQL. A list operator ( IN )
	 * treats the key as a set - a scalar is kept as a single item so build_value()
	 * does not split a delimited string. Returns '' when the value renders to nothing
	 * ( e.g. an empty IN list ), so the caller fails the clause closed.
	 *
	 * @since 3.1.0
	 *
	 * @param string                                        $qt_alias  Quoted table alias to qualify the key column.
	 * @param string                                        $qt_column Quoted meta_key column name.
	 * @param \BerlinDB\Database\Operators\Comparisons\Base $operator  The resolved ( positive ) key operator.
	 * @param string                                        $compare   The operator's compare string.
	 * @param array<string,mixed>                           $clause    The first-order meta_query clause.
	 * @return string The comparison SQL, or '' to fail closed.
	 */
	private function build_meta_key_comparison( string $qt_alias, string $qt_column, \BerlinDB\Database\Operators\Comparisons\Base $operator, string $compare, array $clause ): string {
		$key = $clause[ 'key' ];

		// A list operator treats the key as a set; keep a scalar as a single item.
		if ( $operator->is_list() ) {
			$key = (array) $key;
		}

		$value_sql = $this->build_value( $compare, $key, '%s' );

		// An empty value ( e.g. an empty IN list ) renders no predicate.
		if ( '' === $value_sql ) {
			return '';
		}

		$cast    = $this->get_meta_key_cast( $clause, $compare );
		$key_ref = $this->cast_reference( "{$qt_alias}.{$qt_column}", $cast );

		return $key_ref . ' ' . $operator->get_sql_compare() . ' ' . $value_sql;
	}

	/**
	 * Resolve the optional CAST for a meta_key comparison.
	 *
	 * WordPress honors `type_key => BINARY` only for regular-expression key
	 * comparisons (REGEXP / RLIKE), where it makes the key match case-sensitive;
	 * LIKE / = / IN never cast the key. Both the bespoke JOIN engine and the
	 * store-backed relationship path read this one rule so they cannot drift
	 * (see berlindb/core#210). Each path then applies the returned type in its
	 * own idiom (the JOIN engine emits `REGEXP BINARY`, the relationship path a
	 * `CAST(... AS BINARY)` reference) - both case-sensitive.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $clause  A first-order meta_query clause.
	 * @param string              $compare The meta_key comparison operator.
	 *
	 * @return string 'BINARY' when the key match should be case-sensitive, else ''.
	 */
	private function get_meta_key_cast( array $clause, string $compare = '' ): string {

		/*
		 * BINARY only applies to regular-expression key comparisons; ask the
		 * operator registry rather than hardcoding the regex compare list.
		 */
		$operator = $this->get_operator( strtoupper( $compare ) );

		if ( ( false === $operator ) || ! $operator->is_regex() ) {
			return '';
		}

		// Honor type_key => BINARY; anything else means no cast.
		return ( isset( $clause[ 'type_key' ] ) && ( 'BINARY' === strtoupper( (string) $clause[ 'type_key' ] ) ) )
			? 'BINARY'
			: '';
	}

	/**
	 * Resolve a Meta cast to the form cast_reference() expects.
	 *
	 * Meta uses 'CHAR' (the native string type) as its "no cast" sentinel, so it
	 * maps to '' (no SQL CAST()); any other type passes through. Numeric ordering
	 * always casts to SIGNED, regardless of the recorded cast. Centralizes the
	 * two rules so the orderby sites cannot drift apart.
	 *
	 * @since 3.1.0
	 *
	 * @param string $cast    The requested cast type ('CHAR' = none).
	 * @param bool   $numeric Whether numeric ordering is in force (forces SIGNED).
	 * @return string The cast for cast_reference() ('' when none).
	 */
	private function meta_cast_to_sql( string $cast, bool $numeric = false ): string {
		return $numeric
			? 'SIGNED'
			: ( ( 'CHAR' === $cast ) ? '' : $cast );
	}

	/** meta_query -> relationship clauses (store-backed objects, #204 Phase B) */

	/**
	 * Build relationship filters from a store-backed meta_query (early, before parsing).
	 *
	 * For a caller whose 'meta' relationship resolves to an Interfaces\MetaStore,
	 * the WordPress-shaped meta vars (meta_query / meta_key / meta_value / ...) are
	 * rewritten into relationship EXISTS clauses on relation_query against the
	 * custom sibling table, and the meta vars are stripped so this parser does not
	 * also run at build time. Callers WITHOUT a meta store are left untouched - the
	 * bespoke engine above remains the WordPress-metadata path.
	 *
	 * @since 3.1.0
	 * @internal Query/Parser collaborator API.
	 *
	 * @param array<string,mixed>          $query_vars All of the caller's query vars.
	 * @param \BerlinDB\Database\Kern\Query $caller     The Query being normalized.
	 * @return array<string,mixed> The (possibly modified) query vars.
	 */
	public function normalize_query_vars( array $query_vars, \BerlinDB\Database\Kern\Query $caller ): array {

		// Combine the simple clause + meta_query into one clause tree; nothing to do if empty.
		$meta_query = $this->combine_meta_query_clauses( $query_vars );

		if ( empty( $meta_query ) ) {
			return $query_vars;
		}

		// Only build for store-backed callers; others keep the bespoke engine.
		if ( ! $this->caller_has_meta_store( $caller ) ) {
			return $query_vars;
		}

		/*
		 * Preserve the ordered key(s)/cast(s) the query's orderby asks for, before
		 * the meta vars are stripped (Phase C): get_orderby_sql() still needs them
		 * to build a correlated subquery against the sibling. This directive is an
		 * unregistered build-time pointer - it does NOT need to be cache-keyed,
		 * because the ordered keys already ride in relation_query (the EXISTS
		 * filter, registered/cache-keyed) and in the registered `orderby` var.
		 */
		$query_vars = $this->stash_meta_orderby_directive( $query_vars, $meta_query );

		// Remove the meta vars so the bespoke engine no-ops for store queries.
		$query_vars = $this->strip_meta_query_vars( $query_vars );

		// Build a single relationship clause group from the clause tree.
		$group = $this->build_relationship_group( $meta_query );

		/*
		 * Fail closed if any clause could not be faithfully built (e.g. a
		 * negative compare_key). Signal via a sentinel the Query consumes, so this
		 * parser needs no access to a private short-circuit helper.
		 */
		if ( null === $group ) {
			$query_vars[ 'query_filter_short_circuit' ] = array(
				'source' => 'meta_query',
				'reason' => 'unsupported meta_query clause for a custom meta store',
			);

			return $query_vars;
		}

		/*
		 * AND the built meta group with any existing relationship filter.
		 * Nest the existing query as a subgroup so its own relation - including a
		 * top-level 'relation' => 'OR' - is preserved, rather than absorbing the
		 * meta group into that OR list (which would wrongly OR-combine them). The
		 * root group is an implicit AND, so the two sit side by side under it.
		 */
		$existing = $query_vars[ 'relation_query' ] ?? null;

		$query_vars[ 'relation_query' ] = is_array( $existing )
			? array( $existing, $group )
			: array( $group );

		return $query_vars;
	}

	/**
	 * Whether the caller's 'meta' relationship resolves to a MetaStore.
	 *
	 * Uses only the public Parser API (get_relationship); Query's get_meta_store()
	 * stays private to the CRUD routers.
	 *
	 * @since 3.1.0
	 *
	 * @param \BerlinDB\Database\Kern\Query $caller The Query being normalized.
	 * @return bool
	 */
	private function caller_has_meta_store( \BerlinDB\Database\Kern\Query $caller ): bool {

		$relationship = $caller->get_relationship( 'meta' );

		if ( ! ( $relationship instanceof \BerlinDB\Database\Kern\Relationship ) ) {
			return false;
		}

		$remote = $this->instantiate_class( $relationship->get_query_class() );

		return ( $remote instanceof \BerlinDB\Database\Interfaces\MetaStore );
	}

	/**
	 * Build a single "simple" first-order clause from the flat meta_* vars.
	 *
	 * Shared by parse_query_vars() (the bespoke engine, reading its narrowed vars)
	 * and combine_meta_query_clauses() (the store builder, reading all vars):
	 * meta_key / meta_compare / meta_type / meta_compare_key / meta_type_key become
	 * a clause, plus meta_value unless it is the back-compat default empty string.
	 * "simple" is WP's term for this flat-var clause (the one sorted first);
	 * centralizing it keeps the meta_value guard from drifting between the two.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $vars Query vars to read the flat meta_* vars from.
	 * @return array<string,mixed> The simple clause, or empty when none is set.
	 */
	private function get_simple_meta_clause( array $vars ): array {

		$simple = array();

		// The flat meta_* vars become a single clause.
		foreach ( array( 'key', 'compare', 'type', 'compare_key', 'type_key' ) as $key ) {
			if ( ! empty( $vars[ "meta_{$key}" ] ) ) {
				$simple[ $key ] = $vars[ "meta_{$key}" ];
			}
		}

		// meta_value joins the clause unless it is the back-compat default empty string.
		if ( isset( $vars[ 'meta_value' ] ) && ( '' !== $vars[ 'meta_value' ] ) && ( ! is_array( $vars[ 'meta_value' ] ) || $vars[ 'meta_value' ] ) ) {
			$simple[ 'value' ] = $vars[ 'meta_value' ];
		}

		return $simple;
	}

	/**
	 * Combine the simple meta clause and meta_query into one clause tree.
	 *
	 * Mirrors parse_query_vars()'s simple-clause handling but reads from the FULL
	 * query vars (this early normalization sees everything) and wraps a bare
	 * first-order meta_query so the builder treats it as one clause.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars All of the caller's query vars.
	 * @return array<int|string,mixed> The combined clause tree (possibly empty).
	 */
	private function combine_meta_query_clauses( array $query_vars ): array {

		// An explicit meta_query tree, if present.
		$meta_query = ( isset( $query_vars[ 'meta_query' ] ) && is_array( $query_vars[ 'meta_query' ] ) )
			? $query_vars[ 'meta_query' ]
			: array();

		// The flat meta_* vars become a single simple clause (shared with parse_query_vars()).
		$simple = $this->get_simple_meta_clause( $query_vars );

		// Combine the simple clause with any explicit meta_query, via AND.
		if ( ! empty( $simple ) && ! empty( $meta_query ) ) {
			return array(
				'relation' => 'AND',
				$simple,
				$meta_query,
			);
		}

		if ( ! empty( $simple ) ) {
			return array( $simple );
		}

		/*
		 * A bare first-order associative meta_query (key/value/compare/... at the top
		 * level) is a single clause, not a list - wrap it so the builder treats
		 * it as one clause rather than iterating its keys as members.
		 */
		if ( ! empty( $meta_query ) && $this->is_first_order_meta_clause( $meta_query ) ) {
			return array( $meta_query );
		}

		return $meta_query;
	}

	/**
	 * Whether a meta_query node is a first-order clause (vs a group/list).
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $node A meta_query node.
	 * @return bool
	 */
	private function is_first_order_meta_clause( array $node ): bool {

		foreach ( array( 'key', 'value', 'compare', 'type', 'compare_key', 'type_key' ) as $field ) {
			if ( array_key_exists( $field, $node ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find the first leaf clause's scalar key + cast (for a meta_value orderby).
	 *
	 * WordPress orders `meta_value` by the simple / first clause; mirror that by
	 * descending to the first leaf clause that carries a scalar `key`. The cast is
	 * mapped from the clause's `type` ('CHAR' -> '' no-cast sentinel).
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $meta_query The combined meta_query tree.
	 * @return array{key: string, cast: string}|null The ordered key + cast, or null.
	 */
	private function first_meta_orderby_key( array $meta_query ): ?array {

		foreach ( $meta_query as $member ) {

			if ( ! is_array( $member ) ) {
				continue;
			}

			// A nested group: descend into its first usable leaf.
			if ( isset( $member[ 'relation' ] ) || isset( $member[0] ) ) {
				$found = $this->first_meta_orderby_key( $member );

				if ( null !== $found ) {
					return $found;
				}

				continue;
			}

			// A leaf clause with a scalar key is orderable.
			if ( array_key_exists( 'key', $member ) && is_scalar( $member[ 'key' ] ) ) {
				return $this->orderby_entry_for_clause( $member );
			}
		}

		return null;
	}

	/**
	 * Build the { key, cast } orderby entry for a single leaf clause.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $clause A leaf clause with a scalar 'key'.
	 * @return array{key: string, cast: string} The ordered key + cast ('CHAR' -> '').
	 */
	private function orderby_entry_for_clause( array $clause ): array {

		$cast = $this->get_cast_for_type( $clause[ 'type' ] ?? '' );

		return array(
			'key'  => (string) $clause[ 'key' ],
			'cast' => $this->meta_cast_to_sql( $cast ),
		);
	}

	/**
	 * Record the ordered key(s)/cast(s) the query's orderby asks for.
	 *
	 * For a store-backed query, get_orderby_sql() needs each ordered key after the
	 * meta vars are stripped. Build an unregistered `meta_orderby` directive mapping
	 * each REQUESTED orderby token to its { key, cast }:
	 *  - `meta_value` / `meta_value_num` -> the simple / first clause (WP-parity),
	 *  - a NAMED meta_query clause (`'rating' => array( 'key' => 'rating', ... )`) ->
	 *    that clause, claimable by `orderby => 'rating'`.
	 * Only tokens the orderby actually uses are recorded, so non-meta orders add
	 * nothing.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed>     $query_vars All of the caller's query vars.
	 * @param array<int|string,mixed> $meta_query The combined meta_query tree.
	 * @return array<string,mixed> The query vars, possibly with a 'meta_orderby' directive.
	 */
	private function stash_meta_orderby_directive( array $query_vars, array $meta_query ): array {

		// The orderby tokens this query asks for (scalar string or array keys).
		$requested = $this->requested_orderby_tokens( $query_vars[ 'orderby' ] ?? '' );

		if ( empty( $requested ) ) {
			return $query_vars;
		}

		// The orderable tokens this meta_query can satisfy.
		$available = array();

		$first = $this->first_meta_orderby_key( $meta_query );

		if ( null !== $first ) {
			$available[ 'meta_value' ]     = $first; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$available[ 'meta_value_num' ] = $first;
		}

		// Named clauses are added on top (they override the meta_value defaults above).
		$available = array_merge( $available, $this->get_named_meta_orderby_keys( $meta_query ) );

		// Record only the requested tokens we can satisfy.
		$directive = array();

		foreach ( $requested as $token ) {
			if ( isset( $available[ $token ] ) ) {
				$directive[ $token ] = $available[ $token ];
			}
		}

		if ( ! empty( $directive ) ) {
			$query_vars[ 'meta_orderby' ] = $directive;
		}

		return $query_vars;
	}

	/**
	 * The orderby tokens a query requests (from a scalar string or an array).
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $orderby The 'orderby' query var.
	 * @return list<string> The requested orderby field tokens.
	 */
	private function requested_orderby_tokens( $orderby ): array {

		if ( is_string( $orderby ) && ( '' !== $orderby ) ) {
			return array( $orderby );
		}

		// An orderby array is keyed by field, valued by direction.
		if ( is_array( $orderby ) ) {
			return array_values( array_filter( array_keys( $orderby ), 'is_string' ) );
		}

		return array();
	}

	/**
	 * Return each NAMED meta_query clause's orderby entry, keyed by clause name.
	 *
	 * A clause keyed by a STRING in the tree (other than 'relation') is a named
	 * clause; map name -> { key, cast } so `orderby => '<name>'` can claim it.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $meta_query The (sub)tree.
	 * @return array<string,array{key: string, cast: string}> Named clauses, keyed by name.
	 */
	private function get_named_meta_orderby_keys( array $meta_query ): array {

		$map = array();

		foreach ( $meta_query as $key => $member ) {

			if ( ! is_array( $member ) ) {
				continue;
			}

			// A nested group: merge in its named clauses (a named clause may live inside).
			if ( isset( $member[ 'relation' ] ) || isset( $member[0] ) ) {
				$map = array_merge( $map, $this->get_named_meta_orderby_keys( $member ) );

				continue;
			}

			// A string-keyed leaf clause (not 'relation') is a NAMED, orderable clause.
			if ( is_string( $key ) && ( 'relation' !== $key ) && array_key_exists( 'key', $member ) && is_scalar( $member[ 'key' ] ) ) {
				$map[ $key ] = $this->orderby_entry_for_clause( $member );
			}
		}

		return $map;
	}

	/**
	 * Build a store-backed `meta_value` / `meta_value_num` ORDER BY fragment.
	 *
	 * For a store-backed query the meta_query was built into relation_query
	 * EXISTS, so there is no JOIN alias to order on. Order by a scalar correlated
	 * subquery against the sibling table, for the key normalize_query_vars()
	 * recorded under this orderby token in the `meta_orderby` directive. The
	 * sibling, its foreign key, and the local key are resolved from the caller's
	 * `'meta'` relationship.
	 *
	 * @since 3.1.0
	 *
	 * @param string $orderby The orderby token ('meta_value', 'meta_value_num', or a named clause).
	 * @return string The ORDER BY expression, or '' when not a store-backed order.
	 */
	private function get_store_meta_orderby_sql( string $orderby ): string {

		$caller = $this->caller;

		if ( ! ( $caller instanceof \BerlinDB\Database\Kern\Query ) ) {
			return '';
		}

		// The { key, cast } this token was recorded with by normalize_query_vars().
		$directive = $caller->get_query_var( 'meta_orderby' );
		$entry     = ( is_array( $directive ) && isset( $directive[ $orderby ] ) ) ? $directive[ $orderby ] : null;

		if ( ! is_array( $entry ) || ! isset( $entry[ 'key' ] ) || ! is_scalar( $entry[ 'key' ] ) ) {
			return '';
		}

		// Resolve the sibling via the declared 'meta' relationship.
		$relationship = $caller->get_relationship( 'meta' );

		if ( ! ( $relationship instanceof \BerlinDB\Database\Kern\Relationship ) ) {
			return '';
		}

		$remote = $this->instantiate_class( $relationship->get_query_class() );

		if ( ! ( $remote instanceof \BerlinDB\Database\Kern\Query ) ) {
			return '';
		}

		// has_many: references[0] is the sibling FK; columns[0] is the local key.
		$references = $relationship->references;
		$columns    = $relationship->columns;

		if ( ( count( $references ) !== 1 ) || ( count( $columns ) !== 1 ) ) {
			return '';
		}

		// Pre-quote identifiers (a subquery-local alias avoids any outer collision).
		$qt_sibling = $this->quote_identifier( $remote->get_table_name() );
		$qt_sub     = $this->quote_identifier( 'bdb_meta_ob' );
		$qt_fk      = $this->quote_identifier( (string) $references[0] );
		$qt_value   = $this->quote_identifier( 'meta_value' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$qt_key     = $this->quote_identifier( 'meta_key' );   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$local_ref  = $caller->get_quoted_column_name_aliased( (string) $columns[0] );

		// Correlated subquery for the ordered key's value (one row; LIMIT 1).
		$prepared_key = (string) $this->db()->prepare( '%s', (string) $entry[ 'key' ] );

		/*
		 * Determinism when an object has multiple values for the key: take the
		 * oldest row by meta_id (the preset's PK) - WP-ish "first value". Guarded
		 * on the column existing, since a MetaStore need not be the preset; without
		 * it the subquery falls back to the database's arbitrary first-row pick.
		 */
		$order_by = ! empty( $remote->get_columns( array( 'name' => 'meta_id' ) ) )
			? ' ORDER BY ' . $qt_sub . '.' . $this->quote_identifier( 'meta_id' ) . ' ASC'
			: '';

		$subquery = "( SELECT {$qt_sub}.{$qt_value} FROM {$qt_sibling} AS {$qt_sub}"
			. " WHERE {$qt_sub}.{$qt_fk} = {$local_ref}"
			. " AND {$qt_sub}.{$qt_key} = {$prepared_key}{$order_by} LIMIT 1 )";

		/*
		 * Numeric ordering casts to SIGNED; otherwise use the recorded cast
		 * (already CHAR-stripped when recorded, so the sentinel map is a no-op).
		 */
		$recorded = is_scalar( $entry[ 'cast' ] ?? '' ) ? (string) $entry[ 'cast' ] : '';
		$cast     = $this->meta_cast_to_sql( $recorded, ( 'meta_value_num' === $orderby ) );

		return $this->cast_reference( $subquery, $cast );
	}

	/**
	 * Remove the WordPress meta vars after the build.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars All of the caller's query vars.
	 * @return array<string,mixed> The query vars without the meta directive vars.
	 */
	private function strip_meta_query_vars( array $query_vars ): array {

		unset(
			$query_vars[ 'meta_query' ],
			$query_vars[ 'meta_key' ],
			$query_vars[ 'meta_value' ],
			$query_vars[ 'meta_compare' ],
			$query_vars[ 'meta_compare_key' ],
			$query_vars[ 'meta_type' ],
			$query_vars[ 'meta_type_key' ]
		);

		return $query_vars;
	}

	/**
	 * Build a relationship clause group from a meta_query group.
	 *
	 * Recursive, mirroring the meta_query tree: a member with a nested 'relation'
	 * or a numeric first element is a subgroup; any other array is a leaf clause.
	 * The result is a relation_query group ({ relation, ...clauses }) the
	 * Relationship parser composes via build_clause_group().
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $meta_query The meta_query (sub)tree.
	 * @return array<int|string,mixed>|null The clause group, or null if a clause is unsupported.
	 */
	private function build_relationship_group( array $meta_query ): ?array {

		// Boolean relation for this group ('AND' default, or 'OR').
		$relation = $this->get_clause_relation( $meta_query );

		unset( $meta_query[ 'relation' ] );

		$group = array( 'relation' => $relation );

		foreach ( $meta_query as $member ) {

			/*
			 * A clause/subgroup is always an array. A non-array member is malformed;
			 * fail closed (match no rows) rather than silently ignoring it, matching
			 * the rest of the relationship/filter API's contract.
			 */
			if ( ! is_array( $member ) ) {
				return null;
			}

			// A nested 'relation' or numeric first element marks a subgroup: recurse.
			$built = ( isset( $member[ 'relation' ] ) || isset( $member[0] ) )
				? $this->build_relationship_group( $member )
				: $this->build_relationship_clause( $member );

			// Any unsupported clause fails the whole build closed.
			if ( null === $built ) {
				return null;
			}

			$group[] = $built;
		}

		return $group;
	}

	/**
	 * Build a relationship clause from a single meta_query leaf clause.
	 *
	 * The clause becomes an EXISTS (or NOT EXISTS) over the 'meta' relationship,
	 * with meta_key and meta_value conditions on the sibling table. Value
	 * comparisons reuse the shared Operators (so LIKE/IN/BETWEEN/REGEXP/negation
	 * behave exactly as in the bespoke engine); the legacy `type` maps to an
	 * opt-in CAST on the value side via get_cast_for_type() (CHAR = no cast).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $meta_clause The meta_query leaf clause.
	 * @return array<string,mixed>|null The relationship clause, or null if unsupported.
	 */
	private function build_relationship_clause( array $meta_clause ): ?array {

		$clause_args = array( 'name' => 'meta' );
		$where       = array();

		// The value comparison; defaults to IN for array values, otherwise '='.
		if ( isset( $meta_clause[ 'compare' ] ) ) {
			$compare = strtoupper( (string) $meta_clause[ 'compare' ] );
		} elseif ( isset( $meta_clause[ 'value' ] ) && is_array( $meta_clause[ 'value' ] ) ) {
			$compare = 'IN';
		} else {
			$compare = '=';
		}

		// Resolve the operator; a value-side unary predicate ( IS NULL ) is built below.
		$compare_op = $this->get_operator( $compare );

		// The meta_key condition.
		if ( array_key_exists( 'key', $meta_clause ) ) {
			$key_condition = $this->meta_key_condition( $meta_clause );

			// An unsupported compare_key cannot be faithfully built.
			if ( null === $key_condition ) {
				return null;
			}

			/*
			 * A negative compare_key means "the object has NO matching key", so the
			 * whole clause is NOT EXISTS. It cannot also carry a value: that would
			 * mix object-level key absence with row-level value presence, which a
			 * single EXISTS clause can't express - fail closed for that combination.
			 */
			if ( $key_condition[ 'negate' ] ) {

				/*
				 * A negative compare_key is NOT EXISTS ( the object has no matching
				 * key ), so it cannot also carry a value-side condition - a bare value
				 * OR a value-side unary IS NULL - unless the compare is itself
				 * EXISTS / NOT EXISTS.
				 */
				$has_value_side = array_key_exists( 'value', $meta_clause )
					|| ( ( false !== $compare_op ) && $compare_op->is_unary() );

				if ( $has_value_side && ! in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
					return null;
				}

				$clause_args[ 'exists' ] = false;
			}

			$where = array_merge( $where, $key_condition[ 'where' ] );
		}

		// The value side: EXISTS/NOT EXISTS are key-only; otherwise compare the value.
		if ( 'NOT EXISTS' === $compare ) {
			$clause_args[ 'exists' ] = false;

		} elseif ( ( 'EXISTS' !== $compare ) && ( false !== $compare_op ) && $compare_op->is_unary() ) {

			/*
			 * A value-side unary predicate ( IS NULL / IS NOT NULL ) is value-less, so
			 * any supplied value is ignored. It rides the same relationship EXISTS as a
			 * valued compare, so key absence never satisfies it - mirroring the JOIN
			 * engine's INNER JOIN + `meta_value IS NULL`.
			 */
			$where[ 'meta_value' ] = array( 'compare' => $compare ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		} elseif ( ( 'EXISTS' !== $compare ) && array_key_exists( 'value', $meta_clause ) ) {
			$value_condition = array(
				'compare' => $compare,
				'value'   => $meta_clause[ 'value' ],
			);

			// Map the legacy meta `type` to an opt-in CAST ('CHAR' is the no-cast sentinel).
			$cast = $this->get_cast_for_type( $meta_clause[ 'type' ] ?? '' );

			if ( 'CHAR' !== $cast ) {
				$value_condition[ 'cast' ] = $cast;
			}

			$where[ 'meta_value' ] = $value_condition; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$clause_args[ 'where' ] = $where;

		return $clause_args;
	}

	/**
	 * Build the meta_key condition for a clause's compare_key.
	 *
	 * Positive comparisons map directly to the shared Operators. A NEGATIVE key
	 * comparison (`!=` / `NOT IN` / `NOT LIKE` / `NOT REGEXP` / `NOT EXISTS`) is
	 * flipped to its positive opposite and the clause is marked to negate - the
	 * caller emits it as `NOT EXISTS`. That mirrors the bespoke engine's nested
	 * NOT EXISTS: the semantics are "the object has NO meta row whose key matches"
	 * (not "has some other key"), which avoids cross-key contamination. `!=` and
	 * `NOT EXISTS` both flip to a `=`/`EXISTS` existence test.
	 *
	 * Polarity and the opposite both come from the Operator descriptors
	 * (Operators\*::$positive / $opposite_compare), so there is no negation map
	 * to keep in sync here.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $meta_clause The meta_query leaf clause.
	 * @return array{where: array<string,mixed>, negate: bool}|null The key
	 *         condition and whether the clause negates, or null if unsupported.
	 */
	private function meta_key_condition( array $meta_clause ): ?array {

		$key = $meta_clause[ 'key' ];

		// The key comparison; defaults to IN for array keys, otherwise '='.
		if ( isset( $meta_clause[ 'compare_key' ] ) ) {
			$compare_key = strtoupper( (string) $meta_clause[ 'compare_key' ] );
		} elseif ( is_array( $key ) ) {
			$compare_key = 'IN';
		} else {
			$compare_key = '=';
		}

		/*
		 * Negative key operator -> flip to its positive opposite and negate the
		 * clause. The Operator object owns both facts: is_positive() and the
		 * opposite's compare string (e.g. 'NOT REGEXP' -> 'REGEXP', '!=' -> '=').
		 */
		$operator = $this->get_operator( $compare_key );
		$negate   = ( false !== $operator ) && ! $operator->is_positive();

		if ( $negate ) {
			$compare_key = $operator->get_opposite_compare();
			$operator    = $this->get_operator( $compare_key );
		}

		/*
		 * Only a positive, non-numeric, non-unary operator is a valid key
		 * comparison (=, EXISTS, IN, LIKE, REGEXP, RLIKE). Anything else - an
		 * unknown compare, a numeric/range operator, or a unary IS NULL - cannot
		 * be expressed as a meta_key condition; fail closed.
		 */
		if ( ( false === $operator ) || $operator->is_numeric() || $operator->is_unary() ) {
			return null;
		}

		/*
		 * Shape the relationship where-condition from the operator's descriptors
		 * rather than a hardcoded compare-string switch:
		 *  - equality (Equal/Exists, sql_compare '=') -> a bare scalar,
		 *  - multi (IN) -> an array,
		 *  - otherwise a pattern op (LIKE/REGEXP/RLIKE) -> an explicit { compare,
		 *    value } with the optional type_key BINARY cast.
		 *
		 * 'meta_key' is the sibling table's own indexed column name (a
		 * relationship where-condition key), not a WP_Query meta var, so the
		 * slow-query heuristic does not apply.
		 */
		if ( '=' === $operator->get_sql_compare() ) {
			$where = array( 'meta_key' => $key ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		} elseif ( $operator->is_list() || $operator->is_range() ) {
			$where = array( 'meta_key' => (array) $key ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		} else {
			$condition = array(
				'compare' => $compare_key,
				'value'   => $key,
			);

			// type_key => BINARY makes REGEXP/RLIKE key matches case-sensitive.
			$cast = $this->get_meta_key_cast( $meta_clause, $compare_key );

			if ( '' !== $cast ) {
				$condition[ 'cast' ] = $cast;
			}

			$where = array(
				'meta_key' => $condition, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			);
		}

		return array(
			'where'  => $where,
			'negate' => $negate,
		);
	}
}
