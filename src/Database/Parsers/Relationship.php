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
 *         'name'  => 'order',                         // declared relationship name
 *         'where' => array( 'status' => 'complete' ), // conditions on the joined table
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
 * Single-column relationships: belongs_to filters with an INNER JOIN;
 * has_many filters with a correlated WHERE EXISTS (semi join), which keeps each
 * local row once instead of duplicating it per matching child.
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

		// Read the relationship filter spec(s) from the calling query.
		$specs = $this->caller( 'get_query_var', $this->query_var );

		// Bail unless a non-empty array of specs was provided.
		if ( ! is_array( $specs ) || empty( $specs ) ) {
			return $retval;
		}

		// Normalize a single spec to a list of specs.
		if ( isset( $specs[ 'name' ] ) ) {
			$specs = array( $specs );
		}

		// Collected fragments.
		$joins  = array();
		$wheres = array();

		// Build each relationship clause.
		foreach ( $specs as $spec ) {

			// Skip malformed specs.
			if ( ! is_array( $spec ) || empty( $spec[ 'name' ] ) || ! is_string( $spec[ 'name' ] ) ) {
				continue;
			}

			$clause = $this->build_clause( $spec );

			// Fail closed: an unresolvable spec must not widen the result set.
			if ( false === $clause ) {
				$wheres[] = '1 = 0';
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

		// Combine fragments (de-duplicating identical JOINs).
		$retval[ 'join' ]  = implode( ' ', array_unique( $joins ) );
		$retval[ 'where' ] = implode( ' AND ', $wheres );

		return $retval;
	}

	/**
	 * Build the JOIN and WHERE fragments for a single relationship spec.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $spec Relationship filter spec ({ name, where }).
	 * @return array{join: string, where: list<string>}|false False if unresolvable.
	 */
	private function build_clause( array $spec ) {

		$name  = (string) $spec[ 'name' ];
		$conds = ( isset( $spec[ 'where' ] ) && is_array( $spec[ 'where' ] ) )
			? $spec[ 'where' ]
			: array();

		// Resolve the relationship via the calling query.
		$relationship = $this->caller( 'get_relationship', $name );

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

		// Deterministic, sanitized alias for this relationship's remote table.
		$alias = 'bdb_rel_' . (string) preg_replace( '/[^a-zA-Z0-9_]/', '_', $name );

		// Operator-driven conditions on the remote columns (shared by both
		// strategies). Returns false if any remote column is unknown.
		$conditions = $this->build_conditions( $remote, $alias, $conds );

		if ( false === $conditions ) {
			return false;
		}

		// Pre-quote the shared identifiers.
		$local      = (string) $this->caller( 'get_quoted_column_name_aliased', $columns[0] );
		$alias_sql  = $this->quote_identifier( $alias );
		$remote_sql = $this->quote_identifier( $remote_table );
		$ref_sql    = $alias_sql . '.' . $this->quote_identifier( $remote_ref );

		// belongs_to: this row's foreign key points at one remote row, so an
		// INNER JOIN is correct and never duplicates the local row.
		if ( 'belongs_to' === $type ) {
			$join = 'INNER JOIN ' . $remote_sql . ' AS ' . $alias_sql . ' ON ' . $local . ' = ' . $ref_sql;

			return array(
				'join'  => $join,
				'where' => $conditions,
			);
		}

		// has_many: many remote rows point back here. A correlated EXISTS
		// (semi join) keeps each local row once, unlike an INNER JOIN to the
		// children which would duplicate the local row per match.
		$sub_where = array_merge(
			array( $ref_sql . ' = ' . $local ),
			$conditions
		);

		$exists = 'EXISTS ( SELECT 1 FROM ' . $remote_sql . ' AS ' . $alias_sql
			. ' WHERE ' . implode( ' AND ', $sub_where ) . ' )';

		return array(
			'join'  => '',
			'where' => array( $exists ),
		);
	}

	/**
	 * Build the operator-driven WHERE conditions for a relationship's remote
	 * columns.
	 *
	 * @since 3.1.0
	 *
	 * @param Query                $remote The remote query whose schema owns the columns.
	 * @param string               $alias  The remote table alias.
	 * @param array<string, mixed> $conds  Column => condition map.
	 * @return list<string>|false List of WHERE expressions, or false on an unknown column.
	 */
	private function build_conditions( Query $remote, string $alias, array $conds ) {

		$where = array();

		foreach ( $conds as $column => $cond ) {

			$expr = $this->build_condition( $remote, $alias, (string) $column, $cond );

			// Unknown remote column: fail closed.
			if ( false === $expr ) {
				return false;
			}

			if ( '' !== $expr ) {
				$where[] = $expr;
			}
		}

		return $where;
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

		// Build the comparison SQL against the joined alias.
		$expr = $operator->get_sql( $column_object, $alias, $value );

		return is_string( $expr )
			? $expr
			: '';
	}
}
