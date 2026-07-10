<?php
/**
 * Relationship Query Parser Class.
 *
 * @package     Database
 * @subpackage  Parsers
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Parsers;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Relationship as RelationshipObject;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Parser for the 'join' relationship-filter strategy (berlindb/core #193).
 *
 * Consumes the `relation_query` query var - one clause, or a list of clauses, each
 * shaped:
 *
 *     array(
 *         'name'   => 'order',                         // declared relationship name
 *         'where'  => array( 'status' => 'complete' ), // conditions on the joined table
 *         'exists' => true,                            // optional; false = "no match" (anti join)
 *         'join'   => 'inner',                         // optional; 'inner' (default) or 'left'
 *     )
 *
 * For each clause it resolves the relationship to its remote Query, emits an
 * INNER JOIN against the remote table, and builds the WHERE conditions on the
 * joined columns by delegating to the shared Operator classes (so '=', 'IN',
 * 'BETWEEN', '>', 'LIKE', etc. all work). The joined table is sourced from a
 * Relationship instead of a meta table, but the shape mirrors the Meta parser.
 *
 * Because `relation_query` is a registered parser query var, it segments the
 * result cache automatically. Any unresolvable clause (unknown relationship,
 * unsupported type, unknown remote column) fails closed by emitting a
 * "1 = 0" condition, so a misconfigured filter never widens to all rows.
 *
 * Single-column relationships. A positive belongs_to filter uses an INNER JOIN
 * (or a LEFT JOIN when the clause sets 'join' => 'left'). NOTE: LEFT keeps
 * unmatched local rows ONLY when the relationship carries no 'where' conditions
 * - conditions are emitted into the outer WHERE (not the ON clause), so any
 * condition on the joined columns excludes the NULL-joined unmatched rows, and
 * LEFT then behaves like INNER. Use a conditionless 'join' => 'left' to keep
 * unmatched rows. A positive has_many filter uses a correlated WHERE EXISTS (semi
 * join), which keeps each local row once instead of duplicating it per matching
 * child. When a clause sets 'exists' => false, either direction becomes a WHERE
 * NOT EXISTS (anti join) - rows that have no matching related row.
 *
 * RIGHT and FULL OUTER joins are intentionally unsupported: a RIGHT join would
 * turn a local-row filter into a remote-driven result with null local IDs, and
 * MySQL has no native FULL OUTER join. Query the inverse relationship instead.
 *
 * @since 3.1.0
 */
class Relationship extends Base {

	/**
	 * Internal identifier for this parser.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $name = 'relation';

	/**
	 * Top-level query var this parser consumes.
	 *
	 * @since 3.1.0
	 * @var string|null
	 */
	protected $query_var = 'relation_query';

	/**
	 * Generate the JOIN and WHERE clauses for the relationship filter.
	 *
	 * @since 3.1.0
	 * @internal Query/Parser collaborator API.
	 *
	 * @return array{join: string, where: string}
	 */
	public function get_join_where_clauses() {

		// Default return value.
		$retval = array(
			'join'  => '',
			'where' => '',
		);

		// Bail if this parser has no query var to read.
		if ( null === $this->query_var ) {
			return $retval;
		}

		// Read the relationship filter clause(s) from the calling query.
		$clauses = $this->caller?->get_query_var( $this->query_var );

		// Bail unless a non-empty array of clauses was provided.
		if ( ! is_array( $clauses ) || empty( $clauses ) ) {
			return $retval;
		}

		// Normalize a single clause to a list of clauses.
		if ( isset( $clauses[ 'name' ] ) ) {
			$clauses = array( $clauses );
		}

		/*
		 * Shared across the whole (possibly nested) clause tree: per-relationship-name
		 * alias counters (so a repeated name gets DISTINCT aliases at any depth) and
		 * the collected JOIN fragments (which only ever come from the root AND
		 * context - see build_clause_group()). Duplicate-clause suppression is NOT
		 * shared - it is local to each sibling group, so an identical clause in a
		 * different boolean group (e.g. the A in "A AND ( A OR B )") is preserved.
		 */
		$alias_counts = array();
		$joins        = array();

		// Build the root group; false === fail closed (a malformed/unresolvable clause).
		$where = $this->build_clause_group( $clauses, $alias_counts, $joins, true );

		if ( false === $where ) {
			$retval[ 'where' ] = '1 = 0';

			return $retval;
		}

		$retval[ 'join' ]  = implode( ' ', array_unique( $joins ) );
		$retval[ 'where' ] = $where;

		return $retval;
	}

	/**
	 * Translate the high-level 'relation' directive into canonical query vars.
	 *
	 * The early, all-vars normalizer (run before parsing). Reads the 'relation'
	 * convenience var - a single clause or a list, each { name, where, strategy } -
	 * and rewrites each into native query vars: the 'in' strategy runs a subquery
	 * and constrains the local foreign key via {fk}__in; the 'join' strategy hands
	 * the clause to this parser's own relation_query (consumed at build time). The
	 * 'relation' var is then removed. Any unresolvable or empty-matching filter
	 * fails closed via the 'query_filter_short_circuit' sentinel the Query consumes
	 * - never widening to all rows. See berlindb/core #193.
	 *
	 * @since 3.1.0
	 * @internal Query/Parser collaborator API.
	 *
	 * @param array<string,mixed> $query_vars All of the caller's query vars.
	 * @param Query               $caller     The Query being normalized.
	 * @return array<string,mixed> The (possibly modified) query vars.
	 */
	public function normalize_query_vars( array $query_vars, Query $caller ): array {

		$relation = $query_vars[ 'relation' ] ?? null;

		// Nothing to do without a relation directive.
		if ( empty( $relation ) ) {
			return $query_vars;
		}

		/*
		 * A non-empty but non-array relation (e.g. relation => 'parent') is a
		 * misconfiguration: an explicit filter the pipeline can't act on. Fail
		 * closed rather than ignoring it and widening to all rows.
		 */
		if ( ! is_array( $relation ) ) {
			unset( $query_vars[ 'relation' ] );

			return $this->short_circuit( $query_vars, 'relation must be an array clause (or a list of clauses)' );
		}

		// Normalize a single clause to a list of clauses.
		$clauses = isset( $relation[ 'name' ] )
			? array( $relation )
			: $relation;

		// Resolve each clause by its strategy.
		foreach ( $clauses as $clause_args ) {

			/*
			 * Fail closed on a malformed clause (missing/invalid 'name'), then stop:
			 * an explicit but unactionable filter must match no rows.
			 */
			if ( ! is_array( $clause_args ) || empty( $clause_args[ 'name' ] ) || ! is_string( $clause_args[ 'name' ] ) ) {
				$query_vars = $this->short_circuit( $query_vars, 'malformed relation clause (missing or invalid "name")' );
				break;
			}

			/*
			 * Resolve the strategy. An explicit 'join' / 'in' is honored. With no
			 * explicit strategy, a COMPOSITE ( multi-column ) key or a many_to_many
			 * defaults to 'join': the 'in' materialize strategy is single-column
			 * belongs_to only, so those would otherwise default to 'in' and fail
			 * closed. Single-column belongs_to / has_many keep the 'in' default.
			 */
			$explicit = ( isset( $clause_args[ 'strategy' ] ) && is_string( $clause_args[ 'strategy' ] ) )
				? strtolower( $clause_args[ 'strategy' ] )
				: '';

			/*
			 * A nested `relation` (an array clause/list, not the AND/OR boolean) filters
			 * two or more hops out, expressible only as a correlated EXISTS - so it
			 * forces the 'join' strategy, and an explicit 'in' with one fails closed.
			 */
			$has_nested = isset( $clause_args[ 'relation' ] ) && is_array( $clause_args[ 'relation' ] );

			if ( 'in' === $explicit ) {
				if ( true === $has_nested ) {
					$query_vars = $this->short_circuit( $query_vars, 'relation strategy "in" cannot express a nested relation' );
					break;
				}

				$strategy = 'in';
			} elseif ( 'join' === $explicit ) {
				$strategy = 'join';
			} else {
				$relationship = $caller->get_relationship( (string) $clause_args[ 'name' ] );
				$strategy     = (
					( true === $has_nested )
					|| (
						( $relationship instanceof RelationshipObject )
						&& ( ( 'many_to_many' === $relationship->type ) || ( count( $relationship->columns ) > 1 ) )
					)
				)
					? 'join'
					: 'in';
			}

			if ( 'in' === $strategy ) {
				$query_vars = $this->resolve_in_filter( $clause_args, $query_vars, $caller );
			} else {

				// 'join' strategy: append the clause to this parser's relation_query.
				$existing = $query_vars[ 'relation_query' ] ?? null;
				$list     = is_array( $existing ) ? $existing : array();

				// Normalize an existing single clause to a list before appending.
				if ( isset( $list[ 'name' ] ) ) {
					$list = array( $list );
				}

				$list[]                         = $clause_args;
				$query_vars[ 'relation_query' ] = $list;
			}
		}

		// The 'relation' var is consumed; remove it so column parsers never see it.
		unset( $query_vars[ 'relation' ] );

		return $query_vars;
	}

	/**
	 * Resolve a single 'in'-strategy relationship filter to a {fk}__in query var.
	 *
	 * Runs a subquery against the remote query for the clause's 'where' vars, then
	 * constrains this query's local foreign key to the matching remote primary IDs.
	 * Single-column belongs_to only, and the local foreign key must declare
	 * 'in' => true. Fail-closed conditions return the query vars with a sentinel.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $clause_args The relation clause ({ name, where }).
	 * @param array<string,mixed> $query_vars  All of the caller's query vars.
	 * @param Query               $caller      The Query being normalized.
	 * @return array<string,mixed> The (possibly modified) query vars.
	 */
	private function resolve_in_filter( array $clause_args, array $query_vars, Query $caller ): array {

		$name  = (string) $clause_args[ 'name' ];
		$where = ( isset( $clause_args[ 'where' ] ) && is_array( $clause_args[ 'where' ] ) )
			? $clause_args[ 'where' ]
			: array();

		// The relationship must be declared.
		$relationship = $caller->get_relationship( $name );

		if ( ! ( $relationship instanceof RelationshipObject ) ) {
			return $this->short_circuit( $query_vars, "unknown relationship: {$name}" );
		}

		/*
		 * The 'in' materialize strategy is single-column belongs_to only. A composite
		 * key cannot be a single {fk}__in list; composite relationships default to (and
		 * must use) the 'join' / EXISTS strategy, which correlates on every key column.
		 */
		$columns    = $relationship->columns;
		$references = $relationship->references;

		if ( ( 'belongs_to' !== $relationship->type ) || ( count( $columns ) !== 1 ) || ( count( $references ) !== 1 ) ) {
			return $this->short_circuit( $query_vars, "relation strategy 'in' supports single-column belongs_to only ( composite keys use the 'join' strategy ): {$name}" );
		}

		// The local foreign-key column must support __in.
		$local_fk = $columns[0];

		if ( empty( $caller->get_column_field( array( 'name' => $local_fk ), 'in', false ) ) ) {
			return $this->short_circuit( $query_vars, "column '{$local_fk}' must declare 'in' => true for relation strategy 'in'" );
		}

		// Resolve the remote query instance (null when unresolvable).
		$remote = $this->resolve_remote_query( $relationship );

		if ( null === $remote ) {
			return $this->short_circuit( $query_vars, "remote query class not resolved for relation: {$name}" );
		}

		// Must reference the remote primary key, so the IDs match the local FK.
		$primary = $remote->get_primary_column_name();

		if ( $references[0] !== $primary ) {
			return $this->short_circuit( $query_vars, "relation strategy 'in' requires referencing the remote primary key: {$name}" );
		}

		/*
		 * Resolve every matching remote row (number => 0 means no limit). Full rows
		 * are fetched so primary IDs read with correct typing; this also warms the
		 * remote item cache for any later related access.
		 */
		$remote_rows = $remote->query(
			array_merge(
				$where,
				array( 'number' => 0 )
			)
		);

		$remote_ids = array();

		if ( is_array( $remote_rows ) ) {
			foreach ( $remote_rows as $row ) {
				if ( is_object( $row ) && isset( $row->{$primary} ) && is_scalar( $row->{$primary} ) ) {
					$remote_ids[] = $row->{$primary};
				}
			}
		}

		// No matches: the local result must be empty (legitimate, no log).
		if ( empty( $remote_ids ) ) {
			return $this->short_circuit( $query_vars, '' );
		}

		// Combine with any existing __in on the same column (AND semantics).
		$var      = "{$local_fk}__in";
		$existing = $query_vars[ $var ] ?? null;

		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$remote_ids = array_values( array_intersect( $existing, $remote_ids ) );

			if ( empty( $remote_ids ) ) {
				return $this->short_circuit( $query_vars, '' );
			}
		}

		// Apply as a native {fk}__in filter for this run.
		$query_vars[ $var ] = $remote_ids;

		return $query_vars;
	}

	/**
	 * Set the fail-closed sentinel (consumed by the Query) and return the vars.
	 *
	 * A parser descriptor cannot reach Query's private short-circuit helper, so it
	 * signals fail-closed by returning a 'query_filter_short_circuit' query var. An
	 * empty $reason marks a legitimate empty match (no log).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars All of the caller's query vars.
	 * @param string              $reason     Why the filter could not be applied.
	 * @return array<string,mixed> The query vars carrying the sentinel.
	 */
	private function short_circuit( array $query_vars, string $reason ): array {

		$query_vars[ 'query_filter_short_circuit' ] = array(
			'source' => 'relation_filter',
			'reason' => $reason,
		);

		return $query_vars;
	}

	/**
	 * Resolve a relationship's remote Query class to a usable instance.
	 *
	 * @since 3.1.0
	 *
	 * @param RelationshipObject $relationship The relationship to resolve.
	 * @return Query|null The remote Query instance, or null when unresolvable.
	 */
	private function resolve_remote_query( RelationshipObject $relationship ): ?Query {

		$remote = $this->instantiate_class( $relationship->get_query_class() );

		return ( $remote instanceof Query )
			? $remote
			: null;
	}

	/**
	 * Recursively build the WHERE fragment for a relationship clause group.
	 *
	 * A group is a list whose members are either clauses ({ name, where, ... }) or
	 * NESTED groups (a member that is itself such a list). 'relation' => 'OR'
	 * combines the members with OR; AND is the default - mirroring
	 * build_conditions()'s convention for column groups. This lets a caller
	 * express EXISTS(a) OR EXISTS(b) (each matching a DIFFERENT related row) and
	 * compose that group with other, AND-ed filters - the shape meta_query's
	 * relation=OR needs.
	 *
	 * Fail-closed: ANY malformed or unresolvable member fails the WHOLE tree
	 * (returns false), so the caller emits "1 = 0". Under OR a per-branch "1 = 0"
	 * would otherwise leave "( EXISTS(good) OR 1 = 0 )", which still returns rows.
	 *
	 * JOINs are honored ONLY at the root AND context: a belongs_to INNER JOIN
	 * filters unconditionally, so it cannot live inside an OR (or a nested group
	 * that an OR ancestor might short-circuit) - a JOIN anywhere but the root AND
	 * fails the group closed. The meta mapper only ever emits EXISTS, so this
	 * costs it nothing.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $clauses        The clause group (members + optional 'relation').
	 * @param array<string,int>       $alias_counts Shared per-name alias counters (by reference).
	 * @param list<string>            $joins        Shared collected JOIN fragments (by reference).
	 * @param bool                    $is_root      Whether this is the outermost group.
	 * @return string|false The combined WHERE fragment ('' when empty), or false (fail closed).
	 */
	private function build_clause_group( array $clauses, array &$alias_counts, array &$joins, bool $is_root = false ): string|false {

		// Extract the boolean relation for this group ('AND' default, or 'OR').
		$relation = $this->get_clause_relation( $clauses );

		// 'relation' is a group directive, not a clause.
		unset( $clauses[ 'relation' ] );

		// JOINs are only expressible at the root AND context (see the method doc).
		$allow_joins = ( true === $is_root ) && ( 'AND' === $relation );

		/*
		 * Duplicate-clause suppression is LOCAL to this sibling group: an identical
		 * clause in a different boolean group is semantically distinct (the A in
		 * "A AND ( A OR B )") and must be preserved, so $seen is not shared down
		 * the recursion. The alias counter IS shared, so the preserved duplicate
		 * still gets its own table alias.
		 */
		$seen   = array();
		$wheres = array();

		foreach ( $clauses as $clause_args ) {

			// Every member must be an array (a clause or a nested group).
			if ( ! is_array( $clause_args ) ) {
				return false;
			}

			// A member without a 'name' is a nested group: recurse.
			if ( ! isset( $clause_args[ 'name' ] ) ) {
				$sub = $this->build_clause_group( $clause_args, $alias_counts, $joins, false );

				if ( false === $sub ) {
					return false;
				}

				if ( '' !== $sub ) {
					$wheres[] = $sub;
				}

				continue;
			}

			// A named clause with an invalid name fails closed.
			if ( empty( $clause_args[ 'name' ] ) || ! is_string( $clause_args[ 'name' ] ) ) {
				return false;
			}

			/*
			 * Skip exact-duplicate clauses (same filter and same join mode). serialize()
			 * is native (no WP coupling) and never returns false on a non-UTF-8 value
			 * the way wp_json_encode() can; md5() keeps the array key fixed-length.
			 */
			$fingerprint = md5( serialize( $clause_args ) );

			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;

			// Disambiguate the alias when the same relationship name repeats.
			$name         = (string) $clause_args[ 'name' ];
			$occurrence   = ( $alias_counts[ $name ] = ( $alias_counts[ $name ] ?? 0 ) + 1 );
			$alias_suffix = ( $occurrence > 1 )
				? '_' . (string) $occurrence
				: '';

			$clause = $this->build_clause( $clause_args, $alias_suffix );

			// Fail closed: an unresolvable clause must not widen the result set.
			if ( false === $clause ) {
				return false;
			}

			// A JOIN clause is only valid at the root AND context (see method doc).
			if ( '' !== $clause[ 'join' ] ) {
				if ( ! $allow_joins ) {
					return false;
				}

				$joins[] = $clause[ 'join' ];
			}

			// Collect the WHERE fragments.
			foreach ( $clause[ 'where' ] as $where ) {
				$wheres[] = $where;
			}
		}

		// Combine the WHERE fragments with the relation ( bare single, wrapped many ).
		return \BerlinDB\Database\Clauses\BooleanGroup::combine( $relation, $wheres );
	}

	/**
	 * Build the JOIN and WHERE fragments for a single relationship clause.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $clause_args  Relationship filter clause ({ name, where }).
	 * @param string              $alias_suffix Optional suffix appended to the remote
	 *                                          table alias to keep repeated relationship
	 *                                          names distinct (e.g. '_2'). Default ''.
	 * @return array{join: string, where: list<string>}|false False if unresolvable.
	 */
	private function build_clause( array $clause_args, $alias_suffix = '' ): array|false {

		$name  = (string) $clause_args[ 'name' ];
		$conds = ( isset( $clause_args[ 'where' ] ) && is_array( $clause_args[ 'where' ] ) )
			? $clause_args[ 'where' ]
			: array();

		// Resolve the relationship via the calling query.
		$relationship = $this->caller?->get_relationship( $name );

		if ( ! ( $relationship instanceof RelationshipObject ) ) {
			return false;
		}

		// A many_to_many filters through a pivot table (two hops); see the helper.
		if ( 'many_to_many' === $relationship->type ) {
			return $this->build_many_to_many_clause( $relationship, $clause_args, (string) $alias_suffix );
		}

		/*
		 * A clause carrying a nested `relation` (an array, not the AND/OR boolean
		 * string) filters two or more hops out. Every hop must be a correlated
		 * EXISTS (a JOIN cannot correlate inside a subquery), so the whole chain
		 * takes the recursive EXISTS path from this query down.
		 */
		if ( isset( $clause_args[ 'relation' ] ) && is_array( $clause_args[ 'relation' ] ) ) {
			if ( ! ( $this->caller instanceof Query ) ) {
				return false;
			}

			$alias_counter = 0;
			$exists        = $this->build_relationship_exists( $this->caller, $this->caller->get_table_alias(), $clause_args, $alias_counter );

			return ( false === $exists )
				? false
				: array(
					'join'  => '',
					'where' => array( $exists ),
				);
		}

		// belongs_to or has_many, single- or multi-column ( composite ) key.
		$columns    = $relationship->columns;
		$references = $relationship->references;
		$type       = $relationship->type;

		if (
			! in_array( $type, array( 'belongs_to', 'has_many' ), true )
			|| empty( $columns )
			|| ( count( $columns ) !== count( $references ) )
		) {
			return false;
		}

		// Resolve the remote query instance (null when unresolvable).
		$remote = $this->resolve_remote_query( $relationship );

		if ( null === $remote ) {
			return false;
		}

		// Every referenced remote column must exist in the remote schema.
		$remote_table = $remote->get_table_name();

		foreach ( $references as $remote_ref ) {
			if ( empty( $remote->get_columns( array( 'name' => $remote_ref ) ) ) ) {
				return false;
			}
		}

		/*
		 * Deterministic, sanitized alias for this relationship's remote table.
		 * sanitize_table_alias() applies the canonical identifier rules (and
		 * normalizes/trims underscores); the 'bdb_rel_' prefix plus the caller's
		 * suffix (when a name repeats) keep it unique across clauses.
		 */
		$alias = 'bdb_rel_' . (string) $this->sanitize_table_alias( $name ) . $alias_suffix;

		/*
		 * Operator-driven conditions on the remote columns (shared by both
		 * strategies). Returns false if any remote column is unknown.
		 */
		$conditions = $this->build_conditions( $remote, $alias, $conds );

		if ( false === $conditions ) {
			return false;
		}

		// Wrap the combined group as a list element (empty when no conditions).
		$condition_list = ( '' === $conditions )
			? array()
			: array( $conditions );

		/*
		 * Whether to match rows that HAVE a matching relation (default) or, when
		 * 'exists' is explicitly false, rows that do NOT (anti / NOT EXISTS).
		 */
		$exists_positive = ! array_key_exists( 'exists', $clause_args ) || (bool) $clause_args[ 'exists' ];

		// Pre-quote the shared identifiers.
		$alias_sql  = $this->quote_identifier( $alias );
		$remote_sql = $this->quote_identifier( $remote_table );

		/*
		 * One local = remote equality per positional key-column pair; a composite key
		 * ANDs them. Two orders are kept so single-column SQL is unchanged: the JOIN ON
		 * reads local = remote, the correlated EXISTS reads remote = local.
		 */
		$join_pairs = array();
		$corr_pairs = array();

		foreach ( $columns as $i => $local_col ) {
			$local_sql = $this->caller->get_quoted_column_name_aliased( $local_col );
			$ref_sql   = $alias_sql . '.' . $this->quote_identifier( $references[ $i ] );

			$join_pairs[] = $local_sql . ' = ' . $ref_sql;
			$corr_pairs[] = $ref_sql . ' = ' . $local_sql;
		}

		/*
		 * belongs_to (positive): this row's foreign key points at one remote
		 * row, so a join never duplicates the local row, and exposes the joined
		 * columns for later selection/ordering. INNER keeps only matched rows;
		 * LEFT (opt-in via 'join' => 'left') keeps unmatched local rows ONLY when
		 * there are no conditions - $condition_list goes into the outer WHERE, so
		 * a condition on the joined columns filters NULL-joined rows out (LEFT
		 * then behaves like INNER).
		 */
		if ( ( 'belongs_to' === $type ) && ( true === $exists_positive ) ) {
			$keyword = ( isset( $clause_args[ 'join' ] ) && is_string( $clause_args[ 'join' ] ) && ( 'left' === strtolower( $clause_args[ 'join' ] ) ) )
				? 'LEFT JOIN'
				: 'INNER JOIN';

			$join = $keyword . ' ' . $remote_sql . ' AS ' . $alias_sql
				. ' ON ' . \BerlinDB\Database\Clauses\BooleanGroup::combine( 'AND', $join_pairs );

			return array(
				'join'  => $join,
				'where' => $condition_list,
			);
		}

		/*
		 * All other cases use a correlated (NOT) EXISTS - a semi/anti join that
		 * keeps each local row once: has_many matching (EXISTS), or "no matching
		 * relation" in either direction (NOT EXISTS).
		 */
		$sub_where = array_merge( $corr_pairs, $condition_list );

		// EXISTS or NOT EXISTS, depending on the 'exists' clause key.
		$keyword = ( true === $exists_positive )
			? 'EXISTS'
			: 'NOT EXISTS';

		/*
		 * Subquery with the JOIN conditions in its WHERE clause; the JOIN itself is
		 * unnecessary since the remote table is only used for filtering, never for
		 * selection or ordering.
		 */
		$correlation = \BerlinDB\Database\Clauses\BooleanGroup::combine( 'AND', $sub_where );

		$exists = $keyword . ' ( SELECT 1 FROM ' . $remote_sql . ' AS ' . $alias_sql
			. ' WHERE ' . $correlation . ' )';

		return array(
			'join'  => '',
			'where' => array( $exists ),
		);
	}

	/**
	 * Build the WHERE fragment filtering this query's rows by a many_to_many.
	 *
	 * A pivot relationship is two hops, so it emits a NESTED correlated EXISTS: the
	 * outer subquery finds pivot rows pointing at this row (pivot.through_columns =
	 * local.columns), and its inner EXISTS finds a target row the pivot points at
	 * (target.references = pivot.through_references) that satisfies the clause's
	 * `where` conditions. `exists => false` negates the OUTER as NOT EXISTS (anti):
	 * rows with no matching related target. Composite keys AND their pairs. Any
	 * unresolvable pivot / target, unknown column, or shape mismatch fails closed
	 * (false), so the caller matches no rows rather than widening to all.
	 *
	 * @since 3.1.0
	 *
	 * @param RelationshipObject $relationship The many_to_many relationship.
	 * @param array<string,mixed> $clause_args The relation clause (name, where, exists).
	 * @param string             $alias_suffix Uniquifier when a name repeats across clauses.
	 * @return array{join:string,where:list<string>}|false
	 */
	private function build_many_to_many_clause( RelationshipObject $relationship, array $clause_args, string $alias_suffix = '' ): array|false {

		$name  = (string) $clause_args[ 'name' ];
		$conds = ( isset( $clause_args[ 'where' ] ) && is_array( $clause_args[ 'where' ] ) )
			? $clause_args[ 'where' ]
			: array();

		// The caller Query is needed to quote this row's local columns; fail closed without it.
		$caller = $this->caller;

		if ( ! ( $caller instanceof Query ) ) {
			return false;
		}

		$columns            = $relationship->columns;
		$through_columns    = $relationship->through_columns;
		$through_references = $relationship->through_references;
		$references         = $relationship->references;

		// Bail unless both hops pair up positionally.
		if (
			empty( $columns ) || empty( $through_columns )
			|| empty( $through_references ) || empty( $references )
			|| ( count( $columns ) !== count( $through_columns ) )
			|| ( count( $through_references ) !== count( $references ) )
		) {
			return false;
		}

		// Resolve the pivot and target queries; bail if either is unresolvable.
		$pivot = $this->instantiate_class( $relationship->through );

		if ( ! ( $pivot instanceof Query ) ) {
			return false;
		}

		$target = $this->resolve_remote_query( $relationship );

		if ( null === $target ) {
			return false;
		}

		/*
		 * Every local column named must exist on this query's schema (fail closed:
		 * get_quoted_column_name_aliased() would otherwise quote an unknown column).
		 */
		foreach ( $columns as $local_col ) {
			if ( empty( $caller->get_columns( array( 'name' => $local_col ) ) ) ) {
				return false;
			}
		}

		// Every pivot / target column named must exist on its schema (closes injection).
		foreach ( array_merge( $through_columns, $through_references ) as $pivot_col ) {
			if ( empty( $pivot->get_columns( array( 'name' => $pivot_col ) ) ) ) {
				return false;
			}
		}

		foreach ( $references as $remote_ref ) {
			if ( empty( $target->get_columns( array( 'name' => $remote_ref ) ) ) ) {
				return false;
			}
		}

		// Deterministic, sanitized aliases for the pivot and target tables.
		$base         = 'bdb_rel_' . (string) $this->sanitize_table_alias( $name );
		$pivot_alias  = $base . '_pv' . $alias_suffix;
		$target_alias = $base . '_tg' . $alias_suffix;

		// Operator-driven conditions on the target columns (the clause's `where`).
		$conditions = $this->build_conditions( $target, $target_alias, $conds );

		if ( false === $conditions ) {
			return false;
		}

		// Pre-quote the shared identifiers.
		$pivot_alias_sql  = $this->quote_identifier( $pivot_alias );
		$target_alias_sql = $this->quote_identifier( $target_alias );
		$pivot_table_sql  = $this->quote_identifier( $pivot->get_table_name() );
		$target_table_sql = $this->quote_identifier( $target->get_table_name() );

		// Hop 1 correlation: pivot.through_columns = this row's local columns.
		$hop1_pairs = array();

		foreach ( $columns as $i => $local_col ) {
			$hop1_pairs[] = $pivot_alias_sql . '.' . $this->quote_identifier( $through_columns[ $i ] )
				. ' = ' . $caller->get_quoted_column_name_aliased( $local_col );
		}

		// Hop 2 correlation: target.references = pivot.through_references.
		$hop2_pairs = array();

		foreach ( $references as $i => $ref_col ) {
			$hop2_pairs[] = $target_alias_sql . '.' . $this->quote_identifier( $ref_col )
				. ' = ' . $pivot_alias_sql . '.' . $this->quote_identifier( $through_references[ $i ] );
		}

		// Inner EXISTS: a target row the pivot points at, satisfying the conditions.
		$inner_where = ( '' === $conditions )
			? $hop2_pairs
			: array_merge( $hop2_pairs, array( $conditions ) );

		$inner = 'EXISTS ( SELECT 1 FROM ' . $target_table_sql . ' AS ' . $target_alias_sql
			. ' WHERE ' . \BerlinDB\Database\Clauses\BooleanGroup::combine( 'AND', $inner_where ) . ' )';

		// Outer (NOT) EXISTS: a pivot row for this row whose target satisfies the inner.
		$outer_where     = array_merge( $hop1_pairs, array( $inner ) );
		$exists_positive = ! array_key_exists( 'exists', $clause_args ) || (bool) $clause_args[ 'exists' ];
		$keyword         = ( true === $exists_positive )
			? 'EXISTS'
			: 'NOT EXISTS';

		$exists = $keyword . ' ( SELECT 1 FROM ' . $pivot_table_sql . ' AS ' . $pivot_alias_sql
			. ' WHERE ' . \BerlinDB\Database\Clauses\BooleanGroup::combine( 'AND', $outer_where ) . ' )';

		return array(
			'join'  => '',
			'where' => array( $exists ),
		);
	}

	/**
	 * Build a correlated EXISTS for one relationship hop, recursing into any nested.
	 *
	 * The engine of nested-relationship filtering (order.customer.region...). Given a
	 * base query and the SQL alias its rows are exposed under, this resolves the named
	 * relationship, emits `EXISTS ( SELECT 1 FROM {remote} AS {alias} WHERE {remote.ref
	 * = base.col} AND {where} [AND {nested EXISTS}] )`, and - when the clause carries a
	 * nested `relation` (an array clause/list, never the AND/OR boolean string) -
	 * recurses with the remote as the new base, one hop deeper. Every hop is an EXISTS
	 * (never a JOIN): a JOIN cannot correlate inside a subquery. `exists => false`
	 * negates THIS hop to NOT EXISTS. $alias_counter is shared across the whole tree so
	 * every subquery alias is unique regardless of depth or repeated relationship names.
	 *
	 * Nested chains are belongs_to / has_many only; a many_to_many hop in a chain fails
	 * closed (its pivot indirection is a separate builder). Any unknown relationship,
	 * column, or unresolvable remote at any depth returns false, failing the whole
	 * clause closed (1 = 0) rather than widening.
	 *
	 * @since 3.1.0
	 *
	 * @param Query               $base_query   The query this hop correlates back to.
	 * @param string              $base_alias   The SQL alias the base rows are exposed under.
	 * @param array<string,mixed> $clause_args  The relation clause (name, where, exists, relation).
	 * @param int                 $alias_counter Shared, by-reference alias uniquifier.
	 * @return string|false The EXISTS fragment, or false on any failure (fail closed).
	 */
	private function build_relationship_exists( Query $base_query, string $base_alias, array $clause_args, int &$alias_counter ): string|false {

		$name = ( isset( $clause_args[ 'name' ] ) && is_string( $clause_args[ 'name' ] ) )
			? $clause_args[ 'name' ]
			: '';

		if ( '' === $name ) {
			return false;
		}

		// Resolve the relationship on the CURRENT base query (not the top caller).
		$relationship = $base_query->get_relationship( $name );

		if ( ! ( $relationship instanceof RelationshipObject ) ) {
			return false;
		}

		// Nested chains carry single-hop belongs_to / has_many only.
		$columns    = $relationship->columns;
		$references = $relationship->references;

		if (
			! in_array( $relationship->type, array( 'belongs_to', 'has_many' ), true )
			|| empty( $columns )
			|| ( count( $columns ) !== count( $references ) )
		) {
			return false;
		}

		// Resolve the remote query for this hop; bail if unresolvable.
		$remote = $this->resolve_remote_query( $relationship );

		if ( null === $remote ) {
			return false;
		}

		// A unique alias for this hop's subquery, shared across the whole tree.
		++$alias_counter;
		$remote_alias     = 'bdb_nrel_' . (string) $alias_counter;
		$remote_alias_sql = $this->quote_identifier( $remote_alias );

		/*
		 * Correlate this hop to the base: remote.reference = base.column, one per
		 * positional pair (a composite key ANDs them). Both sides are validated
		 * against their schemas, so an unknown column fails the clause closed.
		 */
		$corr_pairs = array();

		foreach ( $columns as $i => $base_col ) {
			$base_sql = $this->quote_relationship_column( $base_query, $base_alias, $base_col );

			if ( false === $base_sql ) {
				return false;
			}

			if ( empty( $remote->get_columns( array( 'name' => $references[ $i ] ) ) ) ) {
				return false;
			}

			$corr_pairs[] = $remote_alias_sql . '.' . $this->quote_identifier( $references[ $i ] ) . ' = ' . $base_sql;
		}

		// Operator-driven conditions on this hop's remote columns (the clause `where`).
		$conds = ( isset( $clause_args[ 'where' ] ) && is_array( $clause_args[ 'where' ] ) )
			? $clause_args[ 'where' ]
			: array();

		$conditions = $this->build_conditions( $remote, $remote_alias, $conds );

		if ( false === $conditions ) {
			return false;
		}

		$sub_where = ( '' === $conditions )
			? $corr_pairs
			: array_merge( $corr_pairs, array( $conditions ) );

		/*
		 * Recurse one hop deeper for a nested `relation` (array clause or list). The
		 * remote becomes the new base; each nested EXISTS is AND-ed into this WHERE.
		 */
		if ( isset( $clause_args[ 'relation' ] ) && is_array( $clause_args[ 'relation' ] ) ) {
			$nested_clauses = isset( $clause_args[ 'relation' ][ 'name' ] )
				? array( $clause_args[ 'relation' ] )
				: $clause_args[ 'relation' ];

			/*
			 * An explicit but empty nested `relation` (e.g. `'relation' => array()`) is
			 * malformed: fail closed rather than silently dropping the deeper hop, which
			 * would widen the filter to "this hop alone" instead of matching no rows.
			 */
			if ( empty( $nested_clauses ) ) {
				return false;
			}

			foreach ( $nested_clauses as $nested_clause ) {
				if ( ! is_array( $nested_clause ) ) {
					return false;
				}

				$nested = $this->build_relationship_exists( $remote, $remote_alias, $nested_clause, $alias_counter );

				if ( false === $nested ) {
					return false;
				}

				$sub_where[] = $nested;
			}
		}

		// EXISTS (default) or NOT EXISTS (exists => false) at THIS hop.
		$exists_positive = ! array_key_exists( 'exists', $clause_args ) || (bool) $clause_args[ 'exists' ];
		$keyword         = ( true === $exists_positive )
			? 'EXISTS'
			: 'NOT EXISTS';

		return $keyword . ' ( SELECT 1 FROM ' . $this->quote_identifier( $remote->get_table_name() ) . ' AS ' . $remote_alias_sql
			. ' WHERE ' . \BerlinDB\Database\Clauses\BooleanGroup::combine( 'AND', $sub_where ) . ' )';
	}

	/**
	 * Validate a column on a base query and return it quoted with an explicit alias.
	 *
	 * Unlike get_quoted_column_name_aliased() (which uses the query's OWN table alias
	 * and quotes unknown identifiers rather than failing), this pairs an arbitrary
	 * subquery alias with a column and fails closed on an unknown one - the recursion
	 * gives each hop a synthetic alias, and unknown columns must match no rows.
	 *
	 * @since 3.1.0
	 *
	 * @param Query  $base_query The query whose schema owns the column.
	 * @param string $base_alias The SQL alias to prefix.
	 * @param string $column     The column name to validate and quote.
	 * @return string|false `{alias}`.`{column}`, or false when the column is unknown.
	 */
	private function quote_relationship_column( Query $base_query, string $base_alias, string $column ): string|false {

		// Bail on a column the base schema does not declare (fail closed).
		if ( empty( $base_query->get_columns( array( 'name' => $column ) ) ) ) {
			return false;
		}

		return $this->quote_identifier( $base_alias ) . '.' . $this->quote_identifier( $column );
	}

	/**
	 * Build the operator-driven WHERE group for a relationship's remote columns.
	 *
	 * Conditions are joined by AND by default, or by OR when the group carries a
	 * 'relation' => 'OR' key (mirroring the engine's boolean convention). A group
	 * of more than one member is parenthesized so it composes safely with the
	 * surrounding clauses.
	 *
	 * Nesting is recursive, like the Meta parser's query tree: a member keyed by
	 * a STRING is a leaf condition ( column => value ), while a member keyed by an
	 * INTEGER whose value is an array is a nested subgroup that recurses here with
	 * its own 'relation'. This keeps the terse column => value shorthand for the
	 * common (flat) case yet allows arbitrary depth, e.g.:
	 *
	 *     array(
	 *         'relation' => 'AND',
	 *         'status'   => 'active',
	 *         array(
	 *             'relation' => 'OR',
	 *             'tier'  => 'gold',
	 *             'total' => array( 'compare' => '>', 'value' => 1000 ),
	 *         ),
	 *     )
	 *     // => ( status = 'active' AND ( tier = 'gold' OR total > 1000 ) )
	 *
	 * @since 3.1.0
	 *
	 * @param Query               $remote The remote query whose schema owns the columns.
	 * @param string              $alias  The remote table alias.
	 * @param array<string,mixed> $conds  Column => condition map (+ optional 'relation' and nested subgroups).
	 * @return string|false The combined WHERE group (or '' if none), or false on an unknown column.
	 */
	private function build_conditions( Query $remote, string $alias, array $conds ): string|false {

		// Extract the boolean relation for this group ('AND' default, or 'OR').
		$relation = $this->get_clause_relation( $conds );

		// 'relation' is a directive, not a column condition.
		unset( $conds[ 'relation' ] );

		$where = array();

		foreach ( $conds as $column => $cond ) {

			// Integer-keyed array members are nested subgroups: recurse.
			$expr = ( is_int( $column ) && is_array( $cond ) )
				? $this->build_conditions( $remote, $alias, $cond )
				: $this->build_condition( $remote, $alias, (string) $column, $cond );

			// Unknown remote column (at any depth): fail closed.
			if ( false === $expr ) {
				return false;
			}

			if ( '' !== $expr ) {
				$where[] = $expr;
			}
		}

		// Combine the WHERE fragments with the relation ( bare single, wrapped many ).
		return \BerlinDB\Database\Clauses\BooleanGroup::combine( $relation, $where );
	}

	/**
	 * Build a single WHERE condition on a joined remote column, via an Operator.
	 *
	 * Accepts a scalar (equality), a list (IN), or an explicit
	 * array{ compare, value } to choose the operator.
	 *
	 * @since 3.1.0
	 *
	 * @param Query  $remote The remote query whose schema owns the column.
	 * @param string $alias  The joined table alias.
	 * @param string $column The remote column name.
	 * @param mixed  $cond   The condition value or { compare, value } descriptor.
	 * @return string|false The WHERE expression, '' if it produced nothing, or
	 *                      false on an unknown column or an explicit invalid cast.
	 */
	private function build_condition( Query $remote, string $alias, string $column, mixed $cond ): string|false {

		// Resolve the remote Column object.
		$name = $this->sanitize_column_name( $column );

		if ( empty( $name ) || ! is_string( $name ) ) {
			return false;
		}

		$columns = $remote->get_columns( array( 'name' => $name ) );

		if ( empty( $columns ) ) {
			return false;
		}

		$column_object = reset( $columns );

		// Determine the comparison operator and the value.
		if ( \BerlinDB\Database\Operands\Base::is_spec( $cond ) ) {

			// A bare operand spec (e.g. column-to-column) defaults to equality.
			$compare = '=';
			$value   = $cond;
		} elseif ( is_array( $cond ) && ( isset( $cond[ 'compare' ] ) || array_key_exists( 'value', $cond ) ) ) {
			$compare = isset( $cond[ 'compare' ] )
				? strtoupper( (string) $cond[ 'compare' ] )
				: '=';
			$value   = $cond[ 'value' ] ?? null;
		} elseif ( is_array( $cond ) ) {
			$compare = 'IN';
			$value   = $cond;
		} else {
			$compare = '=';
			$value   = $cond;
		}

		/*
		 * Fall back to a sane operator if the compare is not recognized. A list
		 * value falls back to IN, but an operand spec is not a list - it falls
		 * back to equality, consistent with the bare-operand and Compare paths.
		 */
		if ( ! in_array( $compare, $this->get_operators(), true ) ) {
			$compare = ( is_array( $value ) && ! \BerlinDB\Database\Operands\Base::is_spec( $value ) )
				? 'IN'
				: '=';
		}

		// Resolve the operator, falling back to equals.
		$operator = $this->get_operator( $compare );

		// Fall back to equals if the operator is unresolvable.
		if ( false === $operator ) {
			$operator = $this->get_operator( '=' );
		}

		// Bail if the operator is still unresolvable.
		if ( false === $operator ) {
			return '';
		}

		/*
		 * Resolve an optional, opt-in CAST for the column side (shared with the
		 * generic clause builder via the remote query's resolve_sql_cast()). An explicit but
		 * invalid cast fails closed - consistent with the rest of the relationship
		 * API - so a misspelled 'SIGNED' matches no rows, not a lexical compare.
		 */
		// A nested-array condition (not an operand spec) may carry an opt-in cast.
		$cast_source = ( is_array( $cond ) && ! \BerlinDB\Database\Operands\Base::is_spec( $cond ) )
			? $cond
			: array();

		$cast = $remote->resolve_sql_cast( $column_object, $cast_source );

		/*
		 * Fail closed on an explicit but invalid cast (e.g. a misspelled 'SIGNED').
		 * A column with no useful cast yields '' (no cast) here, never false.
		 */
		if ( false === $cast ) {
			return false;
		}

		/*
		 * A structured operand (e.g. column-to-column or a function against the
		 * joined table) replaces the value side. The local column is the left
		 * operand; the right operand's column(s) resolve against the REMOTE schema
		 * and the joined alias. The shared builder fails closed on a non-expression
		 * operator or an unresolvable operand.
		 */
		if ( \BerlinDB\Database\Operands\Base::is_spec( $value ) ) {

			$lhs  = new \BerlinDB\Database\Operands\Column(
				array(
					'column' => $column_object,
					'alias'  => $alias,
					'cast'   => $cast,
				)
			);
			$expr = $this->build_operand_clause( $lhs, $operator, $value, true, $remote, $alias );

			return ( false === $expr )
				? false
				: $expr;
		}

		/*
		 * Build the comparison SQL against the joined alias. get_sql() always
		 * returns a string; a value-less operator (e.g. NOT EXISTS) yields '',
		 * which build_conditions() drops as "produced nothing".
		 */
		return $operator->get_sql( $column_object, $alias, $value, $cast );
	}
}
