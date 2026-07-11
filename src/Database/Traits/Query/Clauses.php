<?php
/**
 * Query Clauses Trait Class.
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

use BerlinDB\Database\Kern\Schema;

/**
 * SQL clause assembly for a Query.
 *
 * Composed onto Query, sharing its $this run-state. Turns parsed query vars into
 * the SQL clause strings a SELECT is built from - SELECT / DISTINCT / fields,
 * FROM, JOIN / WHERE (via the parsers and the fully-qualified Clauses\Builder),
 * GROUP BY, ORDER BY, LIMIT, COUNT - plus the index-hint fragment. These build
 * clause strings; the eventual Clauses\Builder collaborator (#214) can grow out
 * of this seam.
 *
 * @since 3.1.0
 */
trait Clauses {

	/**
	 * Parse all of the $query_vars.
	 *
	 * Optionally accepts an array of custom $query_vars that can be used
	 * instead of the default ones.
	 *
	 * Calls filter_query_clauses() on the return value.
	 *
	 * @since 3.0.0
	 * @param array<string,mixed> $query_vars Optional. Default empty array.
	 *                                         Fallback to Query::query_vars.
	 * @return array<string,mixed> Query clauses, parsed from Query vars.
	 */
	private function parse_query_vars( $query_vars = array() ): array {

		// Maybe fallback to $query_vars.
		if ( empty( $query_vars ) && ! empty( $this->query_vars ) ) {
			$query_vars = $this->query_vars;
		}

		// Parse arguments.
		$r = $this->parse_args( $query_vars );

		// Parse $query_vars.
		$join_where = $this->parse_join_where( $r );

		/*
		 * An OR relation across JOIN-emitting clauses (e.g. an OR meta_query) can match
		 * one base row through more than one joined row and DUPLICATE it. When the query
		 * vars carry an OR relation anywhere AND a JOIN was emitted - and the caller
		 * asked for no grouping of its own - group by the primary key to dedupe,
		 * mirroring WP_Query's meta_query->has_or_relation() handling. The decision is
		 * stored so the count paths dedupe the total the same way (COUNT(DISTINCT
		 * primary)) instead of over-counting the fanned-out rows. A date-only OR never
		 * joins, so it never trips this. WP's wpdb drops ONLY_FULL_GROUP_BY, so GROUP BY
		 * primary is safe alongside any ORDER BY (unlike SELECT DISTINCT).
		 */
		$dedupe_or_join = empty( $r[ 'groupby' ] )
			&& ! empty( $join_where[ 'join' ] )
			&& $this->query_vars_have_or_relation( $r );
		$this->set_current( 'dedupe_or_join', $dedupe_or_join );

		// In row mode, the dedupe groups by the primary key; count mode uses COUNT(DISTINCT).
		$groupby = ( $dedupe_or_join && empty( $r[ 'count' ] ) )
			? $this->get_primary_column_name()
			: $r[ 'groupby' ];

		// Parse all clauses.
		$clauses = array(
			'explain'     => $this->parse_explain( $r[ 'explain' ] ),
			'select'      => $this->parse_select(),
			'distinct'    => $this->parse_distinct( $r[ 'distinct' ], $r[ 'count' ] ),
			'fields'      => $this->parse_fields( $r[ 'fields' ], $r[ 'count' ], $r[ 'groupby' ] ),
			'from'        => $this->parse_from(),
			'index_hints' => $this->parse_index_hints( $r[ 'index_hints' ] ),
			'join'        => $this->parse_join_clause( $join_where[ 'join' ] ),
			'where'       => $this->parse_where_clause( $join_where[ 'where' ] ),
			'groupby'     => $this->parse_groupby( $groupby, 'GROUP BY' ),
			'orderby'     => $this->parse_orderby( $r[ 'orderby' ], $r[ 'order' ], 'ORDER BY' ),
			'limits'      => $this->parse_limits( $r[ 'number' ], $r[ 'offset' ] ),
		);

		// Return clauses.
		return $this->filter_query_clauses( $clauses );
	}

	/**
	 * Whether the query vars carry an OR relation anywhere in their clause tree.
	 *
	 * A pure recursive scan of the (multidimensional) vars for a `'relation' => 'OR'`
	 * leaf at any depth - meta_query, date_query, compare_query, a nested subgroup, a
	 * relation_query group. Case-insensitive to match the sanitizer. Used with
	 * "a JOIN was emitted" to decide primary-key dedupe grouping (see
	 * parse_query_vars()). Iterating leaves lets it stop at the first OR.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string|int,mixed> $query_vars The parsed query vars to scan.
	 * @return bool
	 */
	private function query_vars_have_or_relation( array $query_vars ): bool {
		$leaves = new \RecursiveIteratorIterator(
			new \RecursiveArrayIterator( $query_vars )
		);

		foreach ( $leaves as $key => $value ) {
			if ( ( 'relation' === $key ) && is_string( $value ) && ( 'OR' === strtoupper( $value ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse the 'join' and 'where' $query_vars for all known columns.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query_vars Already-parsed query vars (from parse_query_vars()).
	 * @return array{join: list<string>, where: list<string>} Array of 'join' and 'where' clauses.
	 */
	private function parse_join_where( array $query_vars = array() ): array {

		// Phase 1: run the parsers (the sole caller passes already-parsed vars).
		$parsers = $this->parse_join_where_parsers( $query_vars );

		/*
		 * Read the cross-parser directive from the passed vars only when 'criteria'
		 * is NOT a real column. A same-named column makes the var a column filter
		 * (the 'by' parser handles it); its value - or its unset sentinel - must not
		 * be mistaken for the directive and failed closed. With no such column, the
		 * directive is read as given (an array tree applies; a malformed scalar fails
		 * closed). Reading it from $query_vars (rather than $this) keeps this method
		 * pure on its input, so Operations can compile a WHERE from arbitrary vars.
		 */
		$criteria = $this->is_valid_column( 'criteria' )
			? array()
			: ( $query_vars[ 'criteria' ] ?? array() );

		// Phase 2: assemble their per-parser fragments into the final clause lists.
		$builder = new \BerlinDB\Database\Clauses\Builder(
			array(
				'criteria' => $criteria,
				'join'     => $parsers[ 'join' ],
				'where'    => $parsers[ 'where' ],
				'parsers'  => array_keys( $this->parsers ),
			)
		);

		// Surface any criteria misconfiguration the builder recorded.
		foreach ( $builder->get_warnings() as $warning ) {
			$this->log( 'warning', 'criteria', $warning );
		}

		return array(
			'join'  => $builder->get_join_clauses(),
			'where' => $builder->get_where_clauses(),
		);
	}

	/**
	 * Parse join/where subclauses for query var parser objects.
	 *
	 * Used by parse_join_where().
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $query_vars Query vars.
	 * @return array{join: array<string,mixed>, where: array<string,string>}
	 */
	private function parse_join_where_parsers( $query_vars = array() ): array {

		// Bail if no parsers.
		if ( empty( $this->parsers ) ) {
			return array(
				'join'  => array(),
				'where' => array(),
			);
		}

		// Default values.
		$join    = array();
		$where   = array();
		$parsers = array();

		/*
		 * Every parser's dedicated container query var (e.g. compare_query,
		 * date_query, meta_query, relation_query). A parser handed the FULL
		 * query_vars must not see another parser's container, or it can recurse
		 * into a clause it does not own (cross-parser bleed). Collected once and
		 * stripped per parser below.
		 */
		$container_vars = array();
		foreach ( $this->parsers as $descriptor ) {
			$container_var = $descriptor->get_query_var();
			if ( is_string( $container_var ) && ( '' !== $container_var ) ) {
				$container_vars[] = $container_var;
			}
		}

		// Loop through parsers.
		foreach ( $this->parsers as $key => $descriptor ) {

			// Derive the class from the already-instantiated descriptor.
			$class = get_class( $descriptor );

			// Default to all $query_vars.
			$qv       = $query_vars;
			$narrowed = false;

			// Check if $query_vars contains the query_var for this parser.
			$parser_query_var = $descriptor->get_query_var();
			if ( ! is_null( $parser_query_var ) && ! empty( $query_vars[ $parser_query_var ] ) ) {

				/*
				 * Narrow the scope to just this parser's query_var sub-array,
				 * but only when the user has explicitly set it to an array value
				 * (i.e. not the default sentinel and not a scalar). This restricts
				 * narrowing to Meta, Date, and Compare parsers, which expect an
				 * array of clauses (e.g. meta_query, date_query, compare_query).
				 *
				 * By has a null query_var so this branch never fires.
				 * In/NotIn: users set {col}__in at the top level; in_query stays
				 *   at its sentinel so the sentinel check below stays false.
				 * Search: uses a scalar 'search' key at the top level of
				 *   $query_vars; it needs the full array so its clause handler
				 *   can read $clause[ 'search' ] and $clause[ 'search_columns' ].
				 *   The is_array() guard keeps it on the full $query_vars.
				 */
				if (
					$this->query_var_default_value !== $query_vars[ $parser_query_var ]
					&&
					is_array( $query_vars[ $parser_query_var ] )
				) {
					$qv       = $query_vars[ $parser_query_var ];
					$narrowed = true;
				}
			}

			/*
			 * Cross-parser isolation: when a parser operates on the full
			 * query_vars (it was not narrowed to its own sub-array), strip the
			 * sibling container vars so it cannot recurse into a clause owned by
			 * another parser. This is what fed e.g. a compare_query clause to the
			 * Date parser. A parser's own container is kept; per-column keys
			 * (status__in, name_search, date_created_query, ...) are not
			 * containers and are untouched.
			 */
			if ( false === $narrowed ) {
				foreach ( $container_vars as $container_var ) {
					if ( $container_var !== $parser_query_var ) {
						unset( $qv[ $container_var ] );
					}
				}
			}

			// Instantiate the active parser for this query run.
			$parsers[ $key ] = new $class( $qv, $this );
			$new_parser      = $parsers[ $key ];

			// Default no subclauses.
			$subclauses = false;

			// Set the callback.
			$callback = array( $new_parser, 'get_join_where_clauses' );

			// Try to get the SQL subclauses.
			if ( is_callable( $callback ) ) {
				$subclauses = call_user_func( $callback );
			}

			// Skip if no SQL subclauses.
			if ( false === $subclauses ) {
				continue;
			}

			// Set join.
			if ( ! empty( $subclauses[ 'join' ] ) ) {
				$join[ $key ] = $subclauses[ 'join' ];
			}

			// Set where (removing " AND " from subclauses).
			if ( ! empty( $subclauses[ 'where' ] ) ) {
				$where[ $key ] = (string) preg_replace( '/^\s*AND\s*/', '', $subclauses[ 'where' ] );
			}
		}

		// Store completed parser instances so post-parse hooks can read their state.
		$this->set_current( 'parsers', $parsers );

		// Return join/where subclauses.
		return array(
			'join'  => $join,
			'where' => $where,
		);
	}

	/**
	 * Parse if query to be EXPLAIN'ed.
	 *
	 * @since 3.0.0
	 * @param bool $explain Default false. True to EXPLAIN.
	 * @return string
	 */
	private function parse_explain( $explain = false ): string {

		// Maybe fallback to $query_vars.
		if ( empty( $explain ) ) {
			$explain = $this->get_query_var( 'explain' );
		}

		// Default return value.
		$retval = '';

		// Maybe explaining.
		if ( ! empty( $explain ) ) {
			$retval = 'EXPLAIN';
		}

		// Return SQL.
		return $retval;
	}

	/**
	 * Parse the "SELECT" part of the SQL.
	 *
	 * @since 3.0.0
	 * @return string Default "SELECT".
	 */
	private function parse_select(): string {
		return 'SELECT';
	}

	/**
	 * Parse whether the "SELECT" should be DISTINCT.
	 *
	 * Driven by the 'distinct' query var (like 'explain'); Operations\Delete's ID
	 * selection also asks for it explicitly by passing true. Suppressed when counting:
	 * COUNT carries its own DISTINCT (see parse_count()), so a standalone SELECT
	 * DISTINCT keyword would be redundant. Owning that guard here keeps the clause
	 * assembly a clean one-liner (the same way parse_fields() reads 'count').
	 *
	 * @since 3.1.0
	 * @param bool $distinct Default false. True to de-duplicate the selected rows.
	 * @param bool $count    Default false. True when this is a COUNT request.
	 * @return string Default empty string, or "DISTINCT".
	 */
	private function parse_distinct( $distinct = false, $count = false ): string {

		// A COUNT carries its own DISTINCT; no standalone keyword.
		if ( ! empty( $count ) ) {
			return '';
		}

		// Maybe fallback to $query_vars.
		if ( empty( $distinct ) ) {
			$distinct = $this->get_query_var( 'distinct' );
		}

		// Return SQL.
		return ! empty( $distinct )
			? 'DISTINCT'
			: '';
	}

	/**
	 * Parse which fields to query for.
	 *
	 * If making a 'count' request, this will return either an empty string or
	 * the same columns that are being used for the "GROUP BY" to avoid errors.
	 *
	 * If not counting, this always only includes the Primary column to more
	 * predictably hit the cache, but that may change in a future version.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Moved COUNT() SQL to parse_count() and uses parse_groupby()
	 *              when counting to satisfy MySQL 8 and higher.
	 *
	 * @param string|string[] $fields Field or fields to return.
	 * @param bool            $count Whether to return a count instead of results.
	 * @param string|string[] $groupby Column name to group results by.
	 * @param bool            $alias Whether to include the table alias prefix.
	 * @return string
	 */
	private function parse_fields( $fields = '', $count = false, $groupby = '', $alias = true ): string {

		// Maybe fallback to $query_vars.
		if ( empty( $count ) ) {
			$count = $this->get_query_var( 'count' );
		}

		// Default return value.
		$retval = '';

		// Counting, so use groupby.
		if ( ! empty( $count ) ) {

			// Use count instead.
			$groupby_sql = is_array( $groupby ) ? implode( ', ', $groupby ) : $groupby;
			$retval      = $this->parse_count( (bool) $count, $groupby_sql );

			// Not counting, so use primary column.
		} else {

			// Maybe fallback to $query_vars.
			if ( empty( $fields ) ) {
				$fields = $this->get_query_var( 'fields' );
			}

			// Get the primary column name.
			$primary = $this->get_primary_column_name();

			// Default return value.
			$retval = $this->get_quoted_column_name_aliased( $primary, $alias );
		}

		// Return fields.
		return $retval;
	}

	/**
	 * Parse if counting.
	 *
	 * When counting with groups, parse_fields() will return the required SQL to
	 * prevent errors.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Added the $distinct parameter.
	 * @param bool   $count Whether to return a count instead of results.
	 * @param string $groupby Column name to group results by.
	 * @param string $name Column name.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @param bool   $distinct Count distinct primary IDs (COUNT(DISTINCT id)) instead of rows.
	 * @return string
	 */
	private function parse_count( $count = false, $groupby = '', $name = 'count', $alias = true, $distinct = false ): string {

		// Maybe fallback to $query_vars.
		if ( empty( $count ) ) {
			$count = $this->get_query_var( 'count' );
		}

		// Bail if not counting.
		if ( empty( $count ) ) {
			return '';
		}

		// Maybe fallback to $query_vars.
		if ( empty( $distinct ) ) {
			$distinct = $this->get_query_var( 'distinct' );
		}

		/*
		 * An OR relation across JOINs (e.g. an OR meta_query) fans a base row into
		 * several joined rows; the row query groups by primary to dedupe, so the total
		 * must count DISTINCT primary IDs too, or a bare COUNT(*) over-reports. See
		 * parse_query_vars().
		 */
		if ( empty( $distinct ) && $this->get_current( 'dedupe_or_join', false ) ) {
			$distinct = true;
		}

		/*
		 * Count distinct primary IDs when DISTINCT is requested, so a JOIN that
		 * multiplies rows does not inflate the total; a bare COUNT(*) would. The
		 * DISTINCT belongs inside the COUNT, not as a standalone SELECT keyword.
		 */
		$retval = ! empty( $distinct )
			? 'COUNT(DISTINCT ' . $this->get_quoted_column_name_aliased( $this->get_primary_column_name(), $alias ) . ')'
			: 'COUNT(*)';

		// Check for "GROUP BY".
		$groupby_names = $this->parse_groupby( $groupby, '', $alias );

		// Reformat if grouping counts together.
		if ( ! empty( $groupby_names ) ) {
			$retval = "{$groupby_names}, {$retval} as {$name}";
		}

		// Return SQL.
		return $retval;
	}

	/**
	 * Parse which table to query and whether to follow it with an alias.
	 *
	 * @since 3.0.0
	 * @param string $table Optional. Default empty string.
	 *                      Fallback to get_table_name().
	 * @param string $alias Optional. Default empty string.
	 *                      Fallback to get_table_alias().
	 * @return string
	 */
	private function parse_from( $table = '', $alias = '' ): string {

		// Maybe fallback to get_table_name().
		if ( empty( $table ) ) {
			$table = $this->get_table_name();
		}

		// Maybe fallback to get_table_alias().
		if ( empty( $alias ) ) {
			$alias = $this->get_table_alias();
		}

		// Return.
		return "FROM {$table} {$alias}";
	}

	/**
	 * Parses and sanitizes the 'groupby' keys passed into the item query.
	 *
	 * @since 1.0.0
	 *
	 * @param string $groupby Column name to group results by.
	 * @param string $before SQL fragment to prepend.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @return string
	 */
	private function parse_groupby( $groupby = '', $before = '', $alias = true ): string {

		// Maybe fallback to $query_vars.
		if ( empty( $groupby ) ) {
			$groupby = $this->get_query_var( 'groupby' );
		}

		// Bail if empty.
		if ( empty( $groupby ) ) {
			return '';
		}

		// Maybe cast to array.
		if ( ! is_array( $groupby ) ) {
			$groupby = (array) $groupby;
		}

		$groupby = array_values( $groupby );

		/*
		 * Resolve to VALID column names only. An unknown column is dropped, never
		 * quoted into malformed SQL; all-unknown yields no GROUP BY (ungrouped).
		 */
		$intersect = $this->get_valid_groupby_columns( $groupby );

		// Bail if no valid columns.
		if ( empty( $intersect ) ) {
			return '';
		}

		// Column names array.
		$names = array();

		// Maybe prepend table alias to key.
		foreach ( $intersect as $key ) {
			$names[] = $this->get_quoted_column_name_aliased( $key, $alias );
		}

		// Format column names.
		$retval = implode( ',', $names );

		// Return columns.
		return implode( ' ', array( $before, $retval ) );
	}

	/**
	 * Resolve a groupby input to the names of the columns that actually exist.
	 *
	 * The single source of truth for "which requested groupby columns are real",
	 * shared by parse_groupby() (which quotes the survivors into a GROUP BY) and the
	 * aggregate path (which selects the group columns and decides grouped vs
	 * ungrouped). get_columns_field_by() yields a `false` fallback for each unknown
	 * column; dropping those is what keeps an unknown groupby column from becoming
	 * malformed SQL - it is treated as ungrouped instead.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $groupby The requested groupby values.
	 * @return list<string> The names of the valid columns, in request order.
	 */
	private function get_valid_groupby_columns( array $groupby ): array {
		$names  = (array) $this->get_columns_field_by( 'name', $groupby );
		$retval = array();

		// Keep only real columns: get_columns_field_by() yields a false for each unknown.
		foreach ( $names as $name ) {
			if ( is_string( $name ) && ( '' !== $name ) ) {
				$retval[] = $name;
			}
		}

		return $retval;
	}

	/**
	 * Parse the ORDER BY clause.
	 *
	 * @since 1.0.0 As get_order_by
	 * @since 3.0.0 Renamed to parse_orderby and accepts $orderby, $order, $before, and $alias
	 *
	 * @param string $orderby Column name to order results by.
	 * @param string $order Sort direction (ASC or DESC).
	 * @param string $before SQL fragment to prepend.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @return string
	 */
	private function parse_orderby( $orderby = '', $order = '', $before = '', $alias = true ): string {

		// Maybe fallback to $query_vars.
		if ( empty( $orderby ) ) {
			$orderby = $this->get_query_var( 'orderby' );
		}

		// Bail if counting.
		if ( $this->get_query_var( 'count' ) ) {
			return '';
		}

		// Bail if $orderby is a value that could cancel ordering.
		if ( in_array( $orderby, array( 'none', array(), false, null ), true ) ) {
			return '';
		}

		// Default return value.
		$retval = '';

		// Fallback to default orderby & order.
		if ( empty( $orderby ) ) {
			$parsed = $this->parse_single_orderby( (string) $orderby, $alias );
			$retval = $this->parse_single_orderby_fragment( $parsed, $order );

			// Ordering by something, so figure it out.
		} else {

			/*
			 * A single operand spec ( orderby => array( 'operand' => 'func', ... ) ) is
			 * one expression term; otherwise cast the string / list / assoc to an array.
			 * A spec cannot be an assoc key, so expression terms ride the numeric-list
			 * form and share the top-level $order direction.
			 */
			$ordersby = \BerlinDB\Database\Operands\Base::is_spec( $orderby )
				? array( $orderby )
				: (array) $orderby;

			/*
			 * A numeric list orders by each ELEMENT ( a column name or an operand spec )
			 * with the shared $order; a non-numeric array stays the historical column =>
			 * direction map ( keyed by column, unchanged ). Decided at the array level,
			 * not per key, so a mixed array behaves exactly as before.
			 */
			$is_list = wp_is_numeric_array( $ordersby );

			// Default return value.
			$orderby_array = array();

			// Loop through orderby's.
			foreach ( $ordersby as $key => $value ) {

				if ( true === $is_list ) {

					/*
					 * The element is the term: an operand spec renders its expression, a
					 * column name resolves as before; both take the shared $order.
					 */
					$direction = $order;
					$parsed    = \BerlinDB\Database\Operands\Base::is_spec( $value )
						? $this->parse_operand_orderby( $value, $alias )
						: ( is_string( $value ) ? $this->parse_single_orderby( $value, $alias ) : '' );

				} else {

					// The historical map: the key is the column, the value the direction.
					$direction = $value;
					$parsed    = $this->parse_single_orderby( $key, $alias );
				}

				// Skip if empty.
				if ( empty( $parsed ) ) {
					continue;
				}

				// Append the orderby fragment (with optional NULLS emulation) to array.
				$orderby_array[] = $this->parse_single_orderby_fragment( $parsed, $direction );
			}

			// Only set if valid orderby.
			if ( ! empty( $orderby_array ) ) {
				$retval = implode( ', ', $orderby_array );
			}
		}

		// Bail if nothing to orderby.
		if ( empty( $retval ) && ! empty( $before ) ) {
			return '';
		}

		// Return parsed orderby.
		return implode( ' ', array( $before, $retval ) );
	}

	/**
	 * Parse all of the where clauses.
	 *
	 * @since 3.0.0
	 * @param list<string> $where WHERE SQL clause fragments.
	 * @return string A single SQL statement.
	 */
	private function parse_where_clause( $where = array() ): string {

		// Combine the parser WHERE fragments with a boolean AND.
		$sql = \BerlinDB\Database\Clauses\BooleanGroup::combine( 'AND', array_values( (array) $where ) );

		// Prefix WHERE only when there is something to filter.
		return ( '' === $sql )
			? ''
			: "WHERE {$sql}";
	}

	/**
	 * Parse all of the join clauses.
	 *
	 * @since 3.0.0
	 * @param list<string> $join JOIN SQL clause fragments.
	 * @return string A single SQL statement.
	 */
	private function parse_join_clause( $join = array() ): string {

		// Bail if no join.
		if ( empty( $join ) ) {
			return '';
		}

		// Return SQL.
		return implode( ' ', $join );
	}

	/**
	 * Parse all of the SQL query clauses.
	 *
	 * @since 3.0.0
	 * @param array<string,mixed> $clauses SQL clause fragments.
	 * @return array<string,mixed>
	 */
	private function parse_query_clauses( $clauses = array() ): array {

		// Maybe fallback to query_clauses.
		if ( empty( $clauses ) ) {
			$clauses = $this->get_current_array( 'query_clauses' );
		}

		// Default return value.
		$retval = $this->parse_args( $clauses );

		// Return array of clauses.
		return $retval;
	}

	/**
	 * Parse all SQL $request_clauses into a single SQL query string.
	 *
	 * @since 3.0.0
	 * @param array<string,mixed> $clauses SQL clause fragments.
	 * @return string A single SQL statement.
	 */
	private function parse_request_clauses( $clauses = array() ): string {

		// Maybe fallback to request_clauses.
		if ( empty( $clauses ) ) {
			$clauses = $this->get_current_array( 'request_clauses' );
		}

		// Bail if empty clauses.
		if ( empty( $clauses ) ) {
			return '';
		}

		// Remove empties.
		$filtered = array_filter( $clauses );
		$retval   = array_map( 'trim', $filtered );

		// Return SQL.
		return implode( ' ', $retval );
	}

	/**
	 * Parses the 'number' and 'offset' keys passed to the item query.
	 *
	 * @since 3.0.0
	 *
	 * @param int $number Maximum number of items to return.
	 * @param int $offset Number of items to skip.
	 * @return string
	 */
	private function parse_limits( $number = 0, $offset = 0 ): string {

		// Default return value.
		$retval = '';

		// No negative numbers.
		$limit  = $this->sanitize_absint( $number );
		$offset = $this->sanitize_absint( $offset );

		// Only limit & offset if not limit empty.
		if ( ! empty( $limit ) ) {
			$retval = ! empty( $offset )
				? "LIMIT {$offset}, {$limit}"
				: "LIMIT {$limit}";
		}

		// Return.
		return $retval;
	}

	/**
	 * Parses and sanitizes a single 'orderby' key passed to the item query.
	 *
	 * This method assumes that $orderby is a valid Column name.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses get_in_sql()
	 *
	 * @param string $orderby Field for the items to be ordered by.
	 * @param bool   $alias   Whether to append the table alias.
	 * @return string Value to used in the ORDER BY clause.
	 */
	private function parse_single_orderby( $orderby = '', $alias = true ): string {

		// Fallback to primary column.
		if ( empty( $orderby ) ) {
			$orderby = $this->get_primary_column_name();
		}

		// Default return value.
		$retval = '';

		// Ask each sortable parser if it handles this orderby value.
		foreach ( $this->get_parsers( array( 'sortable' => true ) ) as $parser ) {

			// Maybe get the SQL for this parser's orderby.
			$sql = $parser->get_orderby_sql( $orderby, $alias );
			if ( ! empty( $sql ) ) {
				$retval = $sql;
				break;
			}
		}

		// Specific sortable column (only when no parser claimed it).
		if ( empty( $retval ) ) {
			$sortables = $this->get_column_names( array( 'sortable' => true ) );
			if ( in_array( $orderby, $sortables, true ) ) {
				$retval = $this->get_quoted_column_name_aliased( $orderby, $alias );
			}
		}

		// Return SQL.
		return $retval;
	}

	/**
	 * Resolve an operand-spec orderby term into its ORDER BY expression, or ''.
	 *
	 * Sorts by a rendered expression - a column reference or an allow-listed
	 * function over one ( `orderby => array( 'operand' => 'func', 'name' => 'LENGTH',
	 * 'args' => array( ... ) )` -> `ORDER BY LENGTH( name )` ). Only a `column` or
	 * `func` operand is meaningful to sort by; a list/range/tuple/value spec, or an
	 * unresolvable one, returns '' so the term is simply dropped ( ORDER BY never
	 * changes which rows match, so an invalid term is ignored, not failed closed ).
	 * The expression is resolved through the same operand machinery as WHERE, so its
	 * column is schema-checked and quoted and its members are safe.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $spec  The operand spec.
	 * @param bool  $alias Whether to qualify a column with the table alias.
	 * @return string The ORDER BY expression SQL, or '' to drop the term.
	 */
	private function parse_operand_orderby( $spec, bool $alias ): string {

		// Only a column or function expression is meaningful to sort by.
		$kind = ( is_array( $spec ) && is_string( $spec[ 'operand' ] ?? null ) )
			? strtolower( $spec[ 'operand' ] )
			: '';

		if ( ! in_array( $kind, array( 'column', 'func' ), true ) ) {
			return '';
		}

		// Resolve against this query's own schema; null alias uses its table alias.
		$operand = $this->resolve_operand( $spec, ( true === $alias ) ? null : '' );

		return ( $operand instanceof \BerlinDB\Database\Operands\Base )
			? $operand->get_sql()
			: '';
	}

	/**
	 * Parses an 'order' query variable and cast it to 'ASC' or 'DESC' as
	 * necessary.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Default to 'DESC'
	 *
	 * @param string $order The 'order' query variable.
	 * @return string The sanitized 'order' query variable.
	 */
	private function parse_order( $order = 'DESC' ): string {

		// Bail if malformed.
		if ( empty( $order ) || ! is_string( $order ) ) {
			return 'DESC';
		}

		// Compare the leading word, so a trailing NULLS FIRST/LAST is ignored here.
		return ( 'ASC' === strtoupper( (string) strtok( trim( $order ), ' ' ) ) )
			? 'ASC'
			: 'DESC';
	}

	/**
	 * Build one column's ORDER BY fragment, emulating an optional NULLS FIRST/LAST.
	 *
	 * Combines the already-resolved column SQL with its ASC/DESC direction. MySQL has
	 * no NULLS FIRST/LAST syntax (it groups NULLs first under ASC, last under DESC),
	 * so when the direction value carries a trailing "NULLS FIRST"/"NULLS LAST" (e.g.
	 * 'ASC NULLS LAST'), a leading ISNULL( col ) sort key forces the grouping
	 * deterministically -- ISNULL is 1 for NULL, so DESC floats nulls first and ASC
	 * sinks them last.
	 *
	 * @since 3.1.0
	 *
	 * @param string $column_sql The already-resolved column SQL to order by.
	 * @param string $value      Direction ('ASC'/'DESC'), optionally with 'NULLS FIRST'/'NULLS LAST'.
	 * @return string The ORDER BY fragment ('col ASC|DESC', optionally ISNULL-prefixed).
	 */
	private function parse_single_orderby_fragment( string $column_sql, $value = '' ): string {

		// The ASC/DESC direction; parse_order() reads the leading word, ignoring NULLS.
		$order = $this->parse_order( $value );

		// The single place the NULLS FIRST/LAST suffix is parsed; absent -> plain term.
		if ( ! is_string( $value ) || ! preg_match( '/\bNULLS\s+(FIRST|LAST)\b/i', $value, $matches ) ) {
			return "{$column_sql} {$order}";
		}

		// ISNULL( col ) is 1 for NULL, so DESC floats nulls first, ASC sinks them last.
		$nulls_order = ( 'FIRST' === strtoupper( $matches[ 1 ] ) ) ? 'DESC' : 'ASC';

		return "ISNULL( {$column_sql} ) {$nulls_order}, {$column_sql} {$order}";
	}

	/** Index Hints ***********************************************************/

	/**
	 * Sanitize the 'index_hints' query var into a clean list of validated specs.
	 *
	 * Accepts a single associative spec or a list of them. Each spec shapes to:
	 *   array(
	 *     'type'    => 'use' | 'force' | 'ignore',
	 *     'indexes' => list of declared index names (or 'primary'),
	 *     'for'     => '' | 'join' | 'order by' | 'group by',
	 *   )
	 *
	 * A hint never affects which rows return, so this fails OPEN: an unknown index
	 * name, an unknown type, or a USE/FORCE conflict drops the offending name/spec
	 * and logs it rather than failing the query. Index names are validated against
	 * the schema's declared indexes plus PRIMARY, which also closes off injection.
	 *
	 * MySQL forbids mixing USE and FORCE on one table reference, so the first of the
	 * two seen wins and later conflicting specs are dropped. IGNORE always coexists.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $hints A single hint spec, or a list of them.
	 * @return array<int,array{type:string,indexes:list<string>,for:string}> Clean specs, possibly empty.
	 */
	private function sanitize_index_hints( $hints = array() ): array {

		// Nothing to do for an empty or non-array value.
		if ( empty( $hints ) || ! is_array( $hints ) ) {
			return array();
		}

		// A single associative spec (carrying a recognized key) is a one-element list.
		if ( isset( $hints[ 'type' ] ) || isset( $hints[ 'indexes' ] ) || isset( $hints[ 'for' ] ) ) {
			$hints = array( $hints );
		}

		// Allowed type vocabulary, and the FOR-scope vocabulary (with friendly aliases).
		$types   = array( 'use', 'force', 'ignore' );
		$for_map = array(
			''         => '',
			'join'     => 'join',
			'order by' => 'order by',
			'group by' => 'group by',
			'orderby'  => 'order by',
			'groupby'  => 'group by',
		);

		// The schema owns index identity; null if unavailable (every name then drops).
		$schema = ( $this->schema_object instanceof Schema )
			? $this->schema_object
			: null;

		// Normalize each spec.
		$clean     = array();
		$exclusive = ''; // First of use|force wins; the other conflicts (USE+FORCE is a MySQL error).

		foreach ( $hints as $hint ) {

			// A spec must be an array carrying a type.
			if ( ! is_array( $hint ) || ! isset( $hint[ 'type' ] ) ) {
				$this->log( 'warning', 'index_hints', 'Dropped a malformed index hint (not a spec).' );
				continue;
			}

			// Type must be one of the closed set.
			$type = is_string( $hint[ 'type' ] )
				? strtolower( trim( $hint[ 'type' ] ) )
				: '';

			if ( ! in_array( $type, $types, true ) ) {
				$this->log( 'warning', 'index_hints', 'Dropped an index hint with an unknown type.' );
				continue;
			}

			// USE and FORCE cannot be mixed for the same table; the first one wins.
			if ( in_array( $type, array( 'use', 'force' ), true ) ) {
				if ( '' === $exclusive ) {
					$exclusive = $type;
				} elseif ( $type !== $exclusive ) {
					$this->log( 'warning', 'index_hints', 'Dropped a conflicting index hint (USE and FORCE cannot be mixed).' );
					continue;
				}
			}

			// FOR scope is optional; an unknown value coerces to no scope.
			$raw_for = isset( $hint[ 'for' ] ) && is_string( $hint[ 'for' ] )
				? strtolower( trim( $hint[ 'for' ] ) )
				: '';

			if ( ! array_key_exists( $raw_for, $for_map ) ) {
				$this->log( 'warning', 'index_hints', 'Ignored an unknown FOR scope on an index hint.' );
				$raw_for = '';
			}

			// Validate each index name against declared indexes (+ PRIMARY); dedupe.
			$raw_indexes = isset( $hint[ 'indexes' ] )
				? (array) $hint[ 'indexes' ]
				: array();

			$names = array();

			foreach ( $raw_indexes as $name ) {
				$canonical = ( null !== $schema )
					? $schema->canonical_index_name( $name )
					: '';

				if ( '' === $canonical ) {
					$this->log( 'warning', 'index_hints', 'Dropped an unknown index name from an index hint.' );
					continue;
				}

				if ( ! in_array( $canonical, $names, true ) ) {
					$names[] = $canonical;
				}
			}

			// A hint with no valid index is dropped (v1 does not emit USE INDEX ()).
			if ( empty( $names ) ) {
				$this->log( 'warning', 'index_hints', 'Dropped an index hint with no valid indexes.' );
				continue;
			}

			$clean[] = array(
				'type'    => $type,
				'indexes' => $names,
				'for'     => $for_map[ $raw_for ],
			);
		}

		return $clean;
	}

	/**
	 * Render the 'index_hints' query var as the SQL that follows the table reference.
	 *
	 * Self-sanitizing: it runs sanitize_index_hints() itself rather than trusting the
	 * caller, because a parse_{plural}_query / pre_get_{plural} hook can replace the
	 * 'index_hints' var via set_query_var() after validation, and raw input reaching
	 * MySQL would break fail-open. Keeping the "raw input -> safe SQL" boundary inside
	 * the renderer means no call site can bypass it. Specs are declarative, not
	 * sequential - MySQL collects them by type and scope - so the order here is
	 * cosmetic. PRIMARY is emitted bare; every other name is quoted. The fragment has
	 * NO leading space (it is its own clause slot; the assembler space-joins clauses).
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $hints The 'index_hints' query var (raw or sanitized).
	 * @return string e.g. "FORCE INDEX FOR JOIN (`idx_status`)", or '' when there are none.
	 */
	private function parse_index_hints( $hints = array() ): string {

		// Sanitize at the render boundary (handles hook-mutated raw input).
		$hints = $this->sanitize_index_hints( $hints );

		// Nothing to render.
		if ( empty( $hints ) ) {
			return '';
		}

		// Keyword + scope vocabularies (INDEX and KEY are synonyms; we emit INDEX).
		$keywords = array(
			'use'    => 'USE INDEX',
			'force'  => 'FORCE INDEX',
			'ignore' => 'IGNORE INDEX',
		);
		$scopes   = array(
			'join'     => ' FOR JOIN',
			'order by' => ' FOR ORDER BY',
			'group by' => ' FOR GROUP BY',
		);

		// Render each spec.
		$parts = array();

		foreach ( $hints as $hint ) {

			// Defensive: the sanitizer guarantees the shape, but never trust blindly.
			if ( ! is_array( $hint ) ) {
				continue;
			}

			$type    = isset( $hint[ 'type' ] ) && is_string( $hint[ 'type' ] ) ? $hint[ 'type' ] : '';
			$for     = isset( $hint[ 'for' ] ) && is_string( $hint[ 'for' ] ) ? $hint[ 'for' ] : '';
			$indexes = isset( $hint[ 'indexes' ] ) && is_array( $hint[ 'indexes' ] ) ? $hint[ 'indexes' ] : array();

			if ( ! isset( $keywords[ $type ] ) || empty( $indexes ) ) {
				continue;
			}

			// Quote each index name (PRIMARY is a special name and stays bare).
			$names = array();

			foreach ( $indexes as $name ) {
				$name    = (string) $name;
				$names[] = ( 'PRIMARY' === $name )
					? 'PRIMARY'
					: $this->quote_identifier( $name );
			}

			// Assemble: KEYWORD [FOR SCOPE] (name, ...).
			$scope   = ( '' !== $for ) && isset( $scopes[ $for ] ) ? $scopes[ $for ] : '';
			$parts[] = $keywords[ $type ] . $scope . ' (' . implode( ', ', $names ) . ')';
		}

		// Bail if nothing rendered.
		if ( empty( $parts ) ) {
			return '';
		}

		// No leading space: this is its own clause slot, space-joined by the assembler.
		return implode( ' ', $parts );
	}
}
