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
 * Consumes the `relation_query` query var — one spec, or a list of specs, each
 * shaped:
 *
 *     array(
 *         'name'   => 'order',                         // declared relationship name
 *         'where'  => array( 'status' => 'complete' ), // conditions on the joined table
 *         'exists' => true,                            // optional; false = "no match" (anti join)
 *         'join'   => 'inner',                         // optional; 'inner' (default) or 'left'
 *     )
 *
 * For each spec it resolves the relationship to its remote Query, emits an
 * INNER JOIN against the remote table, and builds the WHERE conditions on the
 * joined columns by delegating to the shared Operator classes (so '=', 'IN',
 * 'BETWEEN', '>', 'LIKE', etc. all work). The joined table is sourced from a
 * Relationship instead of a meta table, but the shape mirrors the Meta parser.
 *
 * Because `relation_query` is a registered parser query var, it segments the
 * result cache automatically. Any unresolvable spec (unknown relationship,
 * unsupported type, unknown remote column) fails closed by emitting a
 * "1 = 0" condition, so a misconfigured filter never widens to all rows.
 *
 * Single-column relationships. A positive belongs_to filter uses an INNER JOIN
 * (or a LEFT JOIN when the spec sets 'join' => 'left'). NOTE: LEFT keeps
 * unmatched local rows ONLY when the relationship carries no 'where' conditions
 * — conditions are emitted into the outer WHERE (not the ON clause), so any
 * condition on the joined columns excludes the NULL-joined unmatched rows, and
 * LEFT then behaves like INNER. Use a conditionless 'join' => 'left' to keep
 * unmatched rows. A positive has_many filter uses a correlated WHERE EXISTS (semi
 * join), which keeps each local row once instead of duplicating it per matching
 * child. When a spec sets 'exists' => false, either direction becomes a WHERE
 * NOT EXISTS (anti join) — rows that have no matching related row.
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

		// Read the relationship filter spec(s) from the calling query.
		$specs = $this->caller?->get_query_var( $this->query_var );

		// Bail unless a non-empty array of specs was provided.
		if ( ! is_array( $specs ) || empty( $specs ) ) {
			return $retval;
		}

		// Normalize a single spec to a list of specs.
		if ( isset( $specs[ 'name' ] ) ) {
			$specs = array( $specs );
		}

		/*
		 * Extract an optional boolean relation for the spec GROUP ('AND' default,
		 * or 'OR'), mirroring build_conditions()'s convention for column groups. OR
		 * lets a caller express EXISTS(a) OR EXISTS(b) where each clause may match a
		 * DIFFERENT related row — the shape meta_query's relation=OR needs. A single
		 * spec was wrapped above, so its inner 'where' relation is untouched.
		 */
		$relation = ( isset( $specs[ 'relation' ] ) && is_string( $specs[ 'relation' ] ) && ( 'OR' === strtoupper( $specs[ 'relation' ] ) ) )
			? 'OR'
			: 'AND';

		// 'relation' is a group directive, not a spec.
		unset( $specs[ 'relation' ] );

		// Collected fragments.
		$joins  = array();
		$wheres = array();
		$failed = false;

		/*
		 * Drop exact-duplicate specs, and count how many times each relationship
		 * name is used so repeats get DISTINCT table aliases. Two specs naming the
		 * same belongs_to with different join modes (e.g. INNER and LEFT) would
		 * otherwise both emit `AS bdb_rel_{name}` and collide ("not unique alias").
		 */
		$seen         = array();
		$alias_counts = array();

		// Build each relationship clause.
		foreach ( $specs as $spec ) {

			/*
			 * Fail closed on a malformed spec. An entry under relation_query is
			 * explicitly a relationship filter, so a missing/invalid 'name' (e.g.
			 * a 'relationship' => 'parent' typo for 'name') is a misconfiguration
			 * that must match no rows — never silently widen to all rows.
			 */
			if ( ! is_array( $spec ) || empty( $spec[ 'name' ] ) || ! is_string( $spec[ 'name' ] ) ) {
				$failed = true;
				continue;
			}

			/*
			 * Skip exact-duplicate specs (same filter and same join mode). serialize()
			 * is native (no WP coupling) and never returns false on a non-UTF-8 value
			 * the way wp_json_encode() can; md5() keeps the array key fixed-length.
			 */
			$fingerprint = md5( serialize( $spec ) );

			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;

			// Disambiguate the alias when the same relationship name repeats.
			$name         = (string) $spec[ 'name' ];
			$occurrence   = ( $alias_counts[ $name ] = ( $alias_counts[ $name ] ?? 0 ) + 1 );
			$alias_suffix = ( $occurrence > 1 )
				? '_' . (string) $occurrence
				: '';

			$clause = $this->build_clause( $spec, $alias_suffix );

			// Fail closed: an unresolvable spec must not widen the result set.
			if ( false === $clause ) {
				$failed = true;
				continue;
			}

			// Collect the JOIN fragment.
			if ( '' !== $clause[ 'join' ] ) {
				$joins[] = $clause[ 'join' ];
			}

			// Collect the WHERE fragments.
			foreach ( $clause[ 'where' ] as $where ) {
				$wheres[] = $where;
			}
		}

		/*
		 * Fail closed: ANY malformed or unresolvable spec poisons the WHOLE group,
		 * for both AND and OR. Under AND this was already true ("X AND 1 = 0"), but
		 * under OR a per-branch "1 = 0" would leave "( EXISTS(good) OR 1 = 0 )",
		 * which still returns rows — the misconfigured filter must match none. The
		 * join is dropped too, so the SQL matches the intent.
		 */
		if ( true === $failed ) {
			$retval[ 'where' ] = '1 = 0';

			return $retval;
		}

		// Combine the WHERE fragments with the group's boolean relation.
		if ( 'OR' === $relation ) {

			/*
			 * OR groups combine the per-clause WHERE fragments with OR. A clause
			 * that emits a JOIN (e.g. a belongs_to INNER JOIN) cannot participate in
			 * OR semantics — its JOIN already filters unconditionally — so an OR
			 * group containing one fails closed (join dropped) rather than silently
			 * AND-ing it in.
			 */
			if ( ! empty( $joins ) ) {
				$retval[ 'where' ] = '1 = 0';

				return $retval;
			}

			$retval[ 'where' ] = ( count( $wheres ) > 1 )
				? '( ' . implode( ' OR ', $wheres ) . ' )'
				: implode( ' OR ', $wheres );

			return $retval;
		}

		// AND group: de-duplicate identical JOINs, AND the WHERE fragments.
		$retval[ 'join' ]  = implode( ' ', array_unique( $joins ) );
		$retval[ 'where' ] = implode( ' AND ', $wheres );

		return $retval;
	}

	/**
	 * Build the JOIN and WHERE fragments for a single relationship spec.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $spec         Relationship filter spec ({ name, where }).
	 * @param string               $alias_suffix Optional suffix appended to the remote
	 *                                           table alias to keep repeated relationship
	 *                                           names distinct (e.g. '_2'). Default ''.
	 * @return array{join: string, where: list<string>}|false False if unresolvable.
	 */
	private function build_clause( array $spec, $alias_suffix = '' ) {

		$name  = (string) $spec[ 'name' ];
		$conds = ( isset( $spec[ 'where' ] ) && is_array( $spec[ 'where' ] ) )
			? $spec[ 'where' ]
			: array();

		// Resolve the relationship via the calling query.
		$relationship = $this->caller?->get_relationship( $name );

		if ( ! ( $relationship instanceof RelationshipObject ) ) {
			return false;
		}

		// Single-column belongs_to or has_many.
		$columns    = $relationship->columns;
		$references = $relationship->references;
		$type       = $relationship->type;

		if ( ( 1 !== count( $columns ) ) || ( 1 !== count( $references ) ) || ! in_array( $type, array( 'belongs_to', 'has_many' ), true ) ) {
			return false;
		}

		// Resolve the remote query class.
		$class = $relationship->get_query_class();

		if ( ( '' === $class ) || ! class_exists( $class ) ) {
			return false;
		}

		$remote = new $class();

		if ( ! ( $remote instanceof Query ) ) {
			return false;
		}

		// The referenced remote column must exist in the remote schema.
		$remote_table = $remote->get_table_name();
		$remote_ref   = $references[0];

		if ( empty( $remote->get_columns( array( 'name' => $remote_ref ) ) ) ) {
			return false;
		}

		/*
		 * Deterministic, sanitized alias for this relationship's remote table.
		 * sanitize_table_alias() applies the canonical identifier rules (and
		 * normalizes/trims underscores); the 'bdb_rel_' prefix plus the caller's
		 * suffix (when a name repeats) keep it unique across specs.
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
		$exists_positive = ! array_key_exists( 'exists', $spec ) || (bool) $spec[ 'exists' ];

		// Pre-quote the shared identifiers.
		$local      = (string) $this->caller->get_quoted_column_name_aliased( $columns[0] );
		$alias_sql  = $this->quote_identifier( $alias );
		$remote_sql = $this->quote_identifier( $remote_table );
		$ref_sql    = $alias_sql . '.' . $this->quote_identifier( $remote_ref );

		/*
		 * belongs_to (positive): this row's foreign key points at one remote
		 * row, so a join never duplicates the local row, and exposes the joined
		 * columns for later selection/ordering. INNER keeps only matched rows;
		 * LEFT (opt-in via 'join' => 'left') keeps unmatched local rows ONLY when
		 * there are no conditions — $condition_list goes into the outer WHERE, so
		 * a condition on the joined columns filters NULL-joined rows out (LEFT
		 * then behaves like INNER).
		 */
		if ( ( 'belongs_to' === $type ) && ( true === $exists_positive ) ) {
			$keyword = ( isset( $spec[ 'join' ] ) && is_string( $spec[ 'join' ] ) && ( 'left' === strtolower( $spec[ 'join' ] ) ) )
				? 'LEFT JOIN'
				: 'INNER JOIN';

			$join = $keyword . ' ' . $remote_sql . ' AS ' . $alias_sql . ' ON ' . $local . ' = ' . $ref_sql;

			return array(
				'join'  => $join,
				'where' => $condition_list,
			);
		}

		/*
		 * All other cases use a correlated (NOT) EXISTS — a semi/anti join that
		 * keeps each local row once: has_many matching (EXISTS), or "no matching
		 * relation" in either direction (NOT EXISTS).
		 */
		$sub_where = array_merge(
			array( $ref_sql . ' = ' . $local ),
			$condition_list
		);

		// EXISTS or NOT EXISTS, depending on the 'exists' spec key.
		$keyword = ( true === $exists_positive )
			? 'EXISTS'
			: 'NOT EXISTS';

		/*
		 * Subquery with the JOIN conditions in its WHERE clause; the JOIN itself is
		 * unnecessary since the remote table is only used for filtering, never for
		 * selection or ordering.
		 */
		$exists = $keyword . ' ( SELECT 1 FROM ' . $remote_sql . ' AS ' . $alias_sql
			. ' WHERE ' . implode( ' AND ', $sub_where ) . ' )';

		return array(
			'join'  => '',
			'where' => array( $exists ),
		);
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
	 * @param Query                $remote The remote query whose schema owns the columns.
	 * @param string               $alias  The remote table alias.
	 * @param array<string, mixed> $conds  Column => condition map (+ optional 'relation' and nested subgroups).
	 * @return string|false The combined WHERE group (or '' if none), or false on an unknown column.
	 */
	private function build_conditions( Query $remote, string $alias, array $conds ) {

		// Extract the boolean relation for this group ('AND' default, or 'OR').
		$relation = ( isset( $conds[ 'relation' ] ) && is_string( $conds[ 'relation' ] ) && ( 'OR' === strtoupper( $conds[ 'relation' ] ) ) )
			? 'OR'
			: 'AND';

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

		// No conditions.
		if ( empty( $where ) ) {
			return '';
		}

		// A single condition needs no grouping; otherwise wrap with the relation.
		return ( 1 === count( $where ) )
			? $where[0]
			: '( ' . implode( " {$relation} ", $where ) . ' )';
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
	 * @return string|false The WHERE expression, '' if it produced nothing, or false if unknown column.
	 */
	private function build_condition( Query $remote, string $alias, string $column, mixed $cond ) {

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
		if ( is_array( $cond ) && ( isset( $cond[ 'compare' ] ) || array_key_exists( 'value', $cond ) ) ) {
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

		// Fall back to a sane operator if the compare is not recognized.
		if ( ! in_array( $compare, $this->get_operators(), true ) ) {
			$compare = is_array( $value )
				? 'IN'
				: '=';
		}

		// Resolve the operator, falling back to equals.
		$operator = $this->get_operator( $compare );

		if ( false === $operator ) {
			$operator = $this->get_operator( '=' );
		}

		if ( false === $operator ) {
			return '';
		}

		/*
		 * Resolve an optional, opt-in CAST for the column side. 'cast' => true
		 * derives the target from the remote column's own type; a non-empty string
		 * is an explicit override. Absent, false, or empty means no cast — casting
		 * is never applied by default.
		 */
		$cast = '';

		if ( is_array( $cond ) ) {
			$requested = $cond[ 'cast' ] ?? null;

			if ( true === $requested ) {
				$cast = $column_object->get_sql_cast_type();
			} elseif ( is_string( $requested ) && ( '' !== trim( $requested ) ) ) {
				$cast = $this->sanitize_sql_cast_type( $requested );

				/*
				 * An explicit but invalid cast is a misconfiguration: fail closed,
				 * consistent with the rest of the relationship API. A misspelled
				 * 'SIGNED' should match no rows, not silently compare lexically.
				 */
				if ( '' === $cast ) {
					return false;
				}
			}
		}

		// Build the comparison SQL against the joined alias.
		$expr = $operator->get_sql( $column_object, $alias, $value, $cast );

		return is_string( $expr )
			? $expr
			: '';
	}
}
