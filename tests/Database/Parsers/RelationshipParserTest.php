<?php
/**
 * Unit tests for the Relationship query parser (berlindb/core #193).
 *
 * These exercise Parsers\Relationship in isolation: a lightweight fake caller
 * supplies the relationship clauses and resolved Relationship objects, and a
 * no-database remote Query fixture supplies the joined schema. No table is
 * installed and no query is executed - the tests assert the JOIN/WHERE SQL the
 * parser generates (and its fail-closed behavior) directly.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Relationship as RelationshipObject;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Parsers\Relationship as RelationshipParser;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Fixtures: a remote schema/query to be joined, and a fake parser caller.
// ---------------------------------------------------------------------------

/**
 * Remote schema with a few columns to filter on.
 */
class RelationshipParserRemoteSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'    => 'status',
			'type'    => 'varchar',
			'length'  => '20',
			'default' => '',
		),
		array(
			'name'     => 'total',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'     => 'order_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
	);
}

/**
 * Remote query bound to the remote schema. Never executed in these tests.
 */
class RelationshipParserRemoteQuery extends Query {
	protected $prefix       = 'bdb';
	protected $table_name   = 'parser_orders';
	protected $table_alias  = 'po';
	protected $table_schema = RelationshipParserRemoteSchema::class;
	protected $item_name    = 'parser_order';
	protected $item_shape   = 'stdClass';
	protected $cache_group  = 'bdb-parser-orders';
}

/**
 * Minimal duck-typed caller for the parser.
 *
 * The parser calls get_query_var(), get_relationship(),
 * get_quoted_column_name_aliased() and get_columns() on its caller, so a tiny
 * object suffices.
 */
class RelationshipParserCaller {

	/** @var mixed */
	public $clauses;

	/** @var array<string, mixed> */
	public $relationships;

	/**
	 * @param mixed                 $clauses         The relation_query value to return.
	 * @param array<string, mixed>  $relationships Map of name => Relationship|false.
	 */
	public function __construct( $clauses = null, array $relationships = array() ) {
		$this->clauses       = $clauses;
		$this->relationships = $relationships;
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	public function get_query_var( $key = '' ) {
		return ( 'relation_query' === $key )
			? $this->clauses
			: null;
	}

	/**
	 * @param string $name
	 * @return mixed
	 */
	public function get_relationship( $name = '' ) {
		return $this->relationships[ $name ] ?? false;
	}

	/**
	 * @param string $column_name
	 * @return string
	 */
	public function get_quoted_column_name_aliased( $column_name = '' ) {
		return '`o`.`' . $column_name . '`';
	}

	/**
	 * @param array<string, mixed> $args
	 * @param string               $operator
	 * @param bool|string          $field
	 * @return array<int|string, mixed>
	 */
	public function get_columns( $args = array(), $operator = 'and', $field = false ) {
		return array();
	}

	/**
	 * @return string
	 */
	public function get_table_alias() {
		return '';
	}

	/**
	 * @param array<string, mixed> $args
	 * @return \BerlinDB\Database\Kern\Column|false
	 */
	public function get_column_by( $args = array() ) {
		return false;
	}
}

/**
 * Relationship parser with its query var disabled.
 *
 * The query_var property is declared nullable so a subclass can opt out of
 * consuming a top-level var; this fixture exercises that null-query_var guard.
 */
class RelationshipParserNullQueryVar extends RelationshipParser {
	protected $query_var = null;
}

/**
 * Tests for Parsers\Relationship.
 *
 * @since 3.1.0
 */
class RelationshipParserTest extends TestCase {

	/**
	 * Build a belongs_to Relationship to the remote fixture query.
	 *
	 * @param string $name    Accessor name.
	 * @param string $type    Relationship type.
	 * @param array  $columns Local columns.
	 * @return RelationshipObject
	 */
	private function relationship( $name = 'parent', $type = 'belongs_to', $columns = array( 'parent_id' ), $references = array( 'id' ) ) {
		return new RelationshipObject(
			array(
				'name'       => $name,
				'type'       => $type,
				'columns'    => $columns,
				'references' => $references,
				'query'      => RelationshipParserRemoteQuery::class,
			)
		);
	}

	/**
	 * Run the parser with given clauses + a relationship map, return join/where.
	 *
	 * @param mixed                $clauses
	 * @param array<string, mixed> $relationships
	 * @return array{join: string, where: string}
	 */
	private function parse( $clauses, array $relationships = array() ) {
		$caller = new RelationshipParserCaller( $clauses, $relationships );
		$parser = new RelationshipParser( array(), $caller );

		return $parser->get_join_where_clauses();
	}

	/**
	 * Test that no relation_query yields empty clauses.
	 *
	 * @since 3.1.0
	 */
	public function test_no_relation_query_is_empty() {
		$result = $this->parse( null );

		$this->assertSame( '', $result['join'] );
		$this->assertSame( '', $result['where'] );
	}

	/**
	 * Test that a non-array relation_query yields empty clauses.
	 *
	 * @since 3.1.0
	 */
	public function test_scalar_relation_query_is_empty() {
		$result = $this->parse( 'nonsense' );

		$this->assertSame( '', $result['join'] );
		$this->assertSame( '', $result['where'] );
	}

	/**
	 * Test that a null query var bails with empty clauses, even when the caller
	 * holds otherwise-valid clauses and a resolvable relationship.
	 *
	 * @since 3.1.0
	 */
	public function test_null_query_var_is_empty() {
		$caller = new RelationshipParserCaller(
			array(
				'name'  => 'parent',
				'where' => array( 'status' => 'parent' ),
			),
			array( 'parent' => $this->relationship() )
		);
		$parser = new RelationshipParserNullQueryVar( array(), $caller );

		$result = $parser->get_join_where_clauses();

		$this->assertSame( '', $result['join'] );
		$this->assertSame( '', $result['where'] );
	}

	/**
	 * Test that a single belongs_to clause emits an INNER JOIN with a sane alias.
	 *
	 * @since 3.1.0
	 */
	public function test_single_belongs_to_emits_inner_join() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array( 'status' => 'active' ),
			),
			array( 'parent' => $this->relationship() )
		);

		/*
		 * The remote table name is resolved connection-side (and is empty here
		 * because no table is installed); assert the parser-owned structure.
		 */
		$this->assertStringContainsString( 'INNER JOIN', $result['join'] );
		$this->assertStringContainsString( 'AS `bdb_rel_parent`', $result['join'] );
		$this->assertStringContainsString( 'ON `o`.`parent_id` = `bdb_rel_parent`.`id`', $result['join'] );
	}

	/**
	 * Test that a scalar where condition becomes an equality on the joined alias.
	 *
	 * @since 3.1.0
	 */
	public function test_equality_where_condition() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array( 'status' => 'active' ),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( '`bdb_rel_parent`.`status`', $result['where'] );
		$this->assertStringContainsString( 'active', $result['where'] );
	}

	/**
	 * Test that an array value uses an IN comparison.
	 *
	 * @since 3.1.0
	 */
	public function test_array_value_uses_in() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array( 'status' => array( 'active', 'pending' ) ),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsStringIgnoringCase( 'IN', $result['where'] );
		$this->assertStringContainsString( 'active', $result['where'] );
		$this->assertStringContainsString( 'pending', $result['where'] );
	}

	/**
	 * Test that an explicit { compare, value } descriptor selects the operator.
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_compare_operator() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'total' => array(
						'compare' => '>',
						'value'   => 100,
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( '`bdb_rel_parent`.`total`', $result['where'] );
		$this->assertStringContainsString( '>', $result['where'] );
		$this->assertStringContainsString( '100', $result['where'] );
	}

	/**
	 * Test that an explicit cast string wraps the column side in CAST().
	 *
	 * @since 3.1.0
	 */
	public function test_where_condition_explicit_cast() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'total' => array(
						'compare' => '>',
						'value'   => 100,
						'cast'    => 'SIGNED',
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( 'CAST(`bdb_rel_parent`.`total` AS SIGNED)', $result['where'] );
	}

	/**
	 * Test that cast => true derives the CAST target from the remote column's
	 * own type (an unsigned bigint => UNSIGNED).
	 *
	 * @since 3.1.0
	 */
	public function test_where_condition_cast_derived_from_column() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'total' => array(
						'compare' => '>',
						'value'   => 100,
						'cast'    => true,
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( 'CAST(`bdb_rel_parent`.`total` AS UNSIGNED)', $result['where'] );
	}

	/**
	 * Test that an explicit but invalid cast fails closed (no rows), consistent
	 * with the rest of the relationship API - a misspelled 'SIGNED' must not fall
	 * back to a silent lexical string comparison.
	 *
	 * @since 3.1.0
	 */
	public function test_where_condition_invalid_cast_fails_closed() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'total' => array(
						'compare' => '>',
						'value'   => 100,
						'cast'    => 'nonsense',
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( '1 = 0', $result['where'] );
		$this->assertStringNotContainsString( '`bdb_rel_parent`.`total`', $result['where'] );
	}

	/**
	 * Test that a column operand compares two columns on the joined remote table
	 * (column-to-column against the relationship's table).
	 *
	 * @since 3.1.0
	 */
	public function test_where_condition_column_operand() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'total' => array(
						'compare' => '>',
						'value'   => array(
							'operand' => 'column',
							'name'    => 'order_id',
						),
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		// Both sides are joined-table column references; no prepared literal.
		$this->assertStringContainsString( '`bdb_rel_parent`.`total`', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_parent`.`order_id`', $result['where'] );
		$this->assertStringContainsString( '>', $result['where'] );
		$this->assertStringNotContainsString( '1 = 0', $result['where'] );
	}

	/**
	 * Test that a column operand referencing an unknown remote column fails the
	 * condition closed.
	 *
	 * @since 3.1.0
	 */
	public function test_where_condition_column_operand_unknown_column_fails_closed() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'total' => array(
						'compare' => '>',
						'value'   => array(
							'operand' => 'column',
							'name'    => 'nonexistent_column',
						),
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( '1 = 0', $result['where'] );
		$this->assertStringNotContainsString( '`bdb_rel_parent`.`total`', $result['where'] );
	}

	/**
	 * Test that a descriptor-form operand with an unrecognized compare falls back
	 * to equality (column-to-column), consistent with the bare-operand and Compare
	 * paths - not to IN, which would reject the operand and fail closed.
	 *
	 * @since 3.1.0
	 */
	public function test_where_condition_operand_with_bad_compare_falls_back_to_equality() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'total' => array(
						'compare' => 'NOT_AN_OPERATOR',
						'value'   => array(
							'operand' => 'column',
							'name'    => 'order_id',
						),
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		// Falls back to '=' and renders the column-to-column comparison.
		$this->assertStringContainsString( '`bdb_rel_parent`.`total` = `bdb_rel_parent`.`order_id`', $result['where'] );
		$this->assertStringNotContainsString( '1 = 0', $result['where'] );
	}

	/**
	 * Test that a function operand wraps a remote column on the joined table.
	 *
	 * @since 3.1.0
	 */
	public function test_where_condition_func_operand() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'total' => array(
						'compare' => '>',
						'value'   => array(
							'operand' => 'func',
							'name'    => 'ABS',
							'args'    => array(
								array(
									'operand' => 'column',
									'name'    => 'order_id',
								),
							),
						),
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( '`bdb_rel_parent`.`total` > ABS(`bdb_rel_parent`.`order_id`)', $result['where'] );
		$this->assertStringNotContainsString( '1 = 0', $result['where'] );
	}

	/**
	 * Test that a column operand on a non-expression operator (LIKE) fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_where_condition_column_operand_non_expression_operator_fails_closed() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'status' => array(
						'compare' => 'LIKE',
						'value'   => array(
							'operand' => 'column',
							'name'    => 'total',
						),
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( '1 = 0', $result['where'] );
	}

	/**
	 * Test that multiple where conditions are AND-combined.
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_where_conditions_anded() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'status' => 'active',
					'total'  => array(
						'compare' => '>',
						'value'   => 0,
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( '`bdb_rel_parent`.`status`', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_parent`.`total`', $result['where'] );
		$this->assertStringContainsString( ' AND ', $result['where'] );
	}

	/**
	 * Test that a single (unwrapped) clause is normalized to a list.
	 *
	 * @since 3.1.0
	 */
	public function test_single_clause_is_normalized() {
		$result = $this->parse(
			array( 'name' => 'parent' ),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( 'INNER JOIN', $result['join'] );
	}

	/**
	 * Test that multiple clauses emit multiple JOINs.
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_clauses_emit_multiple_joins() {
		$result = $this->parse(
			array(
				array( 'name' => 'parent' ),
				array( 'name' => 'shop' ),
			),
			array(
				'parent' => $this->relationship( 'parent', 'belongs_to', array( 'parent_id' ) ),
				'shop'   => $this->relationship( 'shop', 'belongs_to', array( 'shop_id' ) ),
			)
		);

		$this->assertStringContainsString( 'AS `bdb_rel_parent`', $result['join'] );
		$this->assertStringContainsString( 'AS `bdb_rel_shop`', $result['join'] );
	}

	/**
	 * Test that a clause with only a join (no where) emits the JOIN and no WHERE.
	 *
	 * @since 3.1.0
	 */
	public function test_join_without_where() {
		$result = $this->parse(
			array( 'name' => 'parent' ),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( 'INNER JOIN', $result['join'] );
		$this->assertSame( '', $result['where'] );
	}

	/**
	 * Test that the alias is sanitized for relationship names with odd chars.
	 *
	 * @since 3.1.0
	 */
	public function test_alias_is_sanitized() {
		$rel = new RelationshipObject(
			array(
				'name'       => 'parent',
				'type'       => 'belongs_to',
				'columns'    => array( 'parent_id' ),
				'references' => array( 'id' ),
				'query'      => RelationshipParserRemoteQuery::class,
			)
		);

		/*
		 * Force an unusual name straight onto the resolved object's accessor by
		 * resolving under that key.
		 */
		$result = $this->parse(
			array( 'name' => 'weird name!' ),
			array( 'weird name!' => $rel )
		);

		/*
		 * Non-identifier chars collapse to underscores; sanitize_table_alias()
		 * normalizes runs and trims trailing underscores ('weird name!' -> 'weird_name').
		 */
		$this->assertStringContainsString( 'AS `bdb_rel_weird_name`', $result['join'] );
	}

	/**
	 * Test that an unknown relationship fails closed (1 = 0, no join).
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_relationship_fails_closed() {
		$result = $this->parse(
			array( 'name' => 'missing' ),
			array() // no relationships resolve
		);

		$this->assertSame( '', $result['join'] );
		$this->assertStringContainsString( '1 = 0', $result['where'] );
	}

	/**
	 * Test that a has_many relationship emits a correlated WHERE EXISTS (semi
	 * join), with no JOIN, so local rows are not duplicated per matching child.
	 *
	 * @since 3.1.0
	 */
	public function test_has_many_emits_exists_semi_join() {
		$result = $this->parse(
			array(
				'name'  => 'items',
				'where' => array( 'status' => 'active' ),
			),
			array(
				'items' => $this->relationship( 'items', 'has_many', array( 'id' ), array( 'order_id' ) ),
			)
		);

		// EXISTS lives entirely in the WHERE - there is no JOIN.
		$this->assertSame( '', $result['join'] );
		$this->assertStringContainsString( 'EXISTS ( SELECT 1 FROM', $result['where'] );
		$this->assertStringContainsString( 'AS `bdb_rel_items`', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_items`.`order_id` = `o`.`id`', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_items`.`status`', $result['where'] );
	}

	/**
	 * A clause group with relation=OR combines EXISTS clauses with OR.
	 *
	 * This is the shape meta_query's relation=OR needs: EXISTS(a) OR EXISTS(b),
	 * where each EXISTS may match a DIFFERENT related row. The same has_many is
	 * named twice with different conditions, so the second gets a distinct alias.
	 *
	 * @since 3.1.0
	 */
	public function test_or_clause_group_combines_exists_with_or() {
		$result = $this->parse(
			array(
				'relation' => 'OR',
				array(
					'name'  => 'items',
					'where' => array( 'status' => 'active' ),
				),
				array(
					'name'  => 'items',
					'where' => array(
						'total' => array(
							'compare' => '>',
							'value'   => 1000,
						),
					),
				),
			),
			array(
				'items' => $this->relationship( 'items', 'has_many', array( 'id' ), array( 'order_id' ) ),
			)
		);

		// No JOIN (both clauses are correlated EXISTS), combined with OR in WHERE.
		$this->assertSame( '', $result['join'] );
		$this->assertStringContainsString( ' OR ', $result['where'] );
		$this->assertStringStartsWith( '( ', $result['where'] );
		$this->assertStringContainsString( 'AS `bdb_rel_items`', $result['where'] );
		$this->assertStringContainsString( 'AS `bdb_rel_items_2`', $result['where'] );
	}

	/**
	 * A relation=OR group with a single clause emits no OR and no wrapping parens.
	 *
	 * @since 3.1.0
	 */
	public function test_or_clause_group_single_clause_has_no_or() {
		$result = $this->parse(
			array(
				'relation' => 'OR',
				array(
					'name'  => 'items',
					'where' => array( 'status' => 'active' ),
				),
			),
			array(
				'items' => $this->relationship( 'items', 'has_many', array( 'id' ), array( 'order_id' ) ),
			)
		);

		$this->assertStringContainsString( 'EXISTS ( SELECT 1 FROM', $result['where'] );
		$this->assertStringNotContainsString( ' OR ', $result['where'] );
	}

	/**
	 * A relation=OR group containing a JOIN clause fails closed.
	 *
	 * A belongs_to INNER JOIN filters unconditionally, so it cannot participate in
	 * OR semantics; the group must match no rows rather than silently AND it in.
	 *
	 * @since 3.1.0
	 */
	public function test_or_clause_group_with_join_clause_fails_closed() {
		$result = $this->parse(
			array(
				'relation' => 'OR',
				array(
					'name'  => 'items',
					'where' => array( 'status' => 'active' ),
				),
				array( 'name' => 'parent' ),
			),
			array(
				'items'  => $this->relationship( 'items', 'has_many', array( 'id' ), array( 'order_id' ) ),
				'parent' => $this->relationship( 'parent', 'belongs_to', array( 'parent_id' ) ),
			)
		);

		$this->assertSame( '1 = 0', $result['where'] );

		// The pointless JOIN is dropped when failing closed.
		$this->assertSame( '', $result['join'] );
	}

	/**
	 * An unresolvable clause poisons the WHOLE OR group, not just its own branch.
	 *
	 * "( EXISTS(good) OR 1 = 0 )" would still return rows; a misconfigured filter
	 * must match none, so the entire group fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_or_clause_group_unresolvable_clause_fails_whole_group() {
		$result = $this->parse(
			array(
				'relation' => 'OR',
				array(
					'name'  => 'items',
					'where' => array( 'status' => 'active' ),
				),
				array( 'name' => 'missing' ),
			),
			array(
				'items' => $this->relationship( 'items', 'has_many', array( 'id' ), array( 'order_id' ) ),
				// 'missing' does not resolve.
			)
		);

		$this->assertSame( '1 = 0', $result['where'] );
		$this->assertSame( '', $result['join'] );
	}

	/**
	 * A nested OR group composes (ANDs) with a sibling clause at the root.
	 *
	 * This is the shape the meta_query mapper emits when meta_query relation=OR is
	 * combined with another, AND-ed relationship filter: the OR group stays
	 * isolated in its own parentheses, AND-ed with the sibling EXISTS.
	 *
	 * @since 3.1.0
	 */
	public function test_nested_or_group_ands_with_sibling_clause() {
		$result = $this->parse(
			array(
				array(
					'name'  => 'items',
					'where' => array( 'status' => 'shipped' ),
				),
				array(
					'relation' => 'OR',
					array(
						'name'  => 'items',
						'where' => array( 'status' => 'active' ),
					),
					array(
						'name'  => 'items',
						'where' => array(
							'total' => array(
								'compare' => '>',
								'value'   => 1000,
							),
						),
					),
				),
			),
			array(
				'items' => $this->relationship( 'items', 'has_many', array( 'id' ), array( 'order_id' ) ),
			)
		);

		// Sibling EXISTS AND ( EXISTS OR EXISTS ): three distinct aliases, both keywords.
		$this->assertSame( '', $result['join'] );
		$this->assertStringContainsString( ' AND ', $result['where'] );
		$this->assertStringContainsString( ' OR ', $result['where'] );
		$this->assertStringContainsString( 'AS `bdb_rel_items`', $result['where'] );
		$this->assertStringContainsString( 'AS `bdb_rel_items_2`', $result['where'] );
		$this->assertStringContainsString( 'AS `bdb_rel_items_3`', $result['where'] );
	}

	/**
	 * Duplicate suppression is local to each group: "A AND ( A OR B )" keeps the
	 * nested A (a distinct boolean context), with its own alias.
	 *
	 * Sharing the dedup set across groups would skip the nested A and collapse the
	 * query to the narrower "A AND B".
	 *
	 * @since 3.1.0
	 */
	public function test_duplicate_clause_across_groups_is_preserved() {
		$a = array(
			'name'  => 'items',
			'where' => array( 'status' => 'active' ),
		);

		$result = $this->parse(
			array(
				$a, // A
				array(
					'relation' => 'OR',
					$a, // A again, in a DIFFERENT group - must not be suppressed.
					array(
						'name'  => 'items',
						'where' => array( 'total' => 100 ), // B
					),
				),
			),
			array(
				'items' => $this->relationship( 'items', 'has_many', array( 'id' ), array( 'order_id' ) ),
			)
		);

		// Three clauses built (root A, nested A, nested B) -> three distinct aliases.
		$this->assertStringContainsString( 'AS `bdb_rel_items`', $result['where'] );
		$this->assertStringContainsString( 'AS `bdb_rel_items_2`', $result['where'] );
		$this->assertStringContainsString( 'AS `bdb_rel_items_3`', $result['where'] );

		// The duplicated A condition appears twice (root + nested), not collapsed.
		$this->assertSame( 2, substr_count( $result['where'], "`status` = 'active'" ) );
	}

	/**
	 * A JOIN clause inside a nested group fails closed.
	 *
	 * JOINs are only expressible at the root AND context; a belongs_to inside any
	 * nested group cannot be composed safely, so the group fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_join_inside_nested_group_fails_closed() {
		$result = $this->parse(
			array(
				array(
					'relation' => 'AND',
					array( 'name' => 'parent' ),
				),
			),
			array(
				'parent' => $this->relationship( 'parent', 'belongs_to', array( 'parent_id' ) ),
			)
		);

		$this->assertSame( '1 = 0', $result['where'] );
		$this->assertSame( '', $result['join'] );
	}

	/**
	 * Test that a composite (multi-column) relationship fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_relationship_fails_closed() {
		$result = $this->parse(
			array( 'name' => 'parent' ),
			array( 'parent' => $this->relationship( 'parent', 'belongs_to', array( 'parent_id', 'tenant_id' ) ) )
		);

		$this->assertSame( '', $result['join'] );
		$this->assertStringContainsString( '1 = 0', $result['where'] );
	}

	/**
	 * Test that a where condition on an unknown remote column fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_remote_column_fails_closed() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array( 'nonexistent' => 'x' ),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertSame( '', $result['join'] );
		$this->assertStringContainsString( '1 = 0', $result['where'] );
	}

	/**
	 * Test that a malformed clause (no name) FAILS CLOSED rather than being
	 * silently skipped - an explicit-but-misconfigured relationship filter must
	 * match no rows, not all rows.
	 *
	 * @since 3.1.0
	 */
	public function test_malformed_clause_fails_closed() {
		$result = $this->parse(
			array(
				array( 'where' => array( 'status' => 'active' ) ), // no 'name'
			),
			array()
		);

		$this->assertSame( '', $result['join'] );
		$this->assertSame( '1 = 0', $result['where'] );
	}

	/**
	 * Test that a belongs_to clause with exists => false emits a NOT EXISTS (anti
	 * join) instead of an INNER JOIN.
	 *
	 * @since 3.1.0
	 */
	public function test_belongs_to_negation_emits_not_exists() {
		$result = $this->parse(
			array(
				'name'   => 'parent',
				'where'  => array( 'status' => 'active' ),
				'exists' => false,
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertSame( '', $result['join'] );
		$this->assertStringContainsString( 'NOT EXISTS ( SELECT 1 FROM', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_parent`.`id` = `o`.`parent_id`', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_parent`.`status`', $result['where'] );
	}

	/**
	 * Test that a has_many clause with exists => false emits a NOT EXISTS.
	 *
	 * @since 3.1.0
	 */
	public function test_has_many_negation_emits_not_exists() {
		$result = $this->parse(
			array(
				'name'   => 'items',
				'exists' => false,
			),
			array(
				'items' => $this->relationship( 'items', 'has_many', array( 'id' ), array( 'order_id' ) ),
			)
		);

		$this->assertSame( '', $result['join'] );
		$this->assertStringContainsString( 'NOT EXISTS ( SELECT 1 FROM', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_items`.`order_id` = `o`.`id`', $result['where'] );
	}

	/**
	 * Test that a where with relation => OR combines conditions with OR, in a
	 * parenthesized group.
	 *
	 * @since 3.1.0
	 */
	public function test_where_relation_or_groups_conditions() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'relation' => 'OR',
					'status'   => 'active',
					'total'    => array(
						'compare' => '>',
						'value'   => 100,
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( ' OR ', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_parent`.`status`', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_parent`.`total`', $result['where'] );

		// The group is parenthesized so it composes safely.
		$this->assertStringStartsWith( '(', trim( $result['where'] ) );
	}

	/**
	 * Test that 'relation' is treated as a directive, not a column condition.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_key_is_not_a_column() {
		/*
		 * 'relation' would not be a valid column; if it were treated as one this
		 * would fail closed. Instead it selects AND/OR and the real column wins.
		 */
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'relation' => 'AND',
					'status'   => 'active',
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( '`bdb_rel_parent`.`status`', $result['where'] );
		$this->assertStringNotContainsString( '1 = 0', $result['where'] );
	}

	/**
	 * Test that a belongs_to clause with join => 'left' emits a LEFT JOIN.
	 *
	 * @since 3.1.0
	 */
	public function test_belongs_to_left_join() {
		$result = $this->parse(
			array(
				'name' => 'parent',
				'join' => 'left',
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( 'LEFT JOIN', $result['join'] );
		$this->assertStringNotContainsString( 'INNER JOIN', $result['join'] );
		$this->assertStringContainsString( 'ON `o`.`parent_id` = `bdb_rel_parent`.`id`', $result['join'] );
	}

	/**
	 * Test that an unrecognized join keyword falls back to INNER JOIN.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_join_keyword_defaults_to_inner() {
		$result = $this->parse(
			array(
				'name' => 'parent',
				'join' => 'cross',
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( 'INNER JOIN', $result['join'] );
	}

	/**
	 * Test that an integer-keyed array member is treated as a nested subgroup,
	 * recursing with its own relation (Meta-parser-style boolean nesting).
	 *
	 * @since 3.1.0
	 */
	public function test_nested_subgroup_recurses_with_own_relation() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'relation' => 'AND',
					'status'   => 'active',
					array(
						'relation' => 'OR',
						'total'    => array(
							'compare' => '>',
							'value'   => 1000,
						),
						'order_id' => 5,
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		// Outer group glues the leaf and the subgroup with AND.
		$this->assertStringContainsString( ' AND ', $result['where'] );

		// Inner subgroup glues its two members with OR, parenthesized.
		$this->assertStringContainsString( ' OR ', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_parent`.`status`', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_parent`.`total`', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_parent`.`order_id`', $result['where'] );

		/*
		 * The OR group is nested inside the outer AND group: two opening parens
		 * appear before the inner OR keyword.
		 */
		$or_position    = strpos( $result['where'], ' OR ' );
		$prefix         = substr( $result['where'], 0, (int) $or_position );
		$opening_parens = substr_count( $prefix, '(' );
		$this->assertGreaterThanOrEqual( 2, $opening_parens );
	}

	/**
	 * Test that an unknown column inside a nested subgroup fails closed, with the
	 * false propagating up through the recursion.
	 *
	 * @since 3.1.0
	 */
	public function test_nested_subgroup_unknown_column_fails_closed() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'status' => 'active',
					array(
						'relation'    => 'OR',
						'nonexistent' => 'x',
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertSame( '', $result['join'] );
		$this->assertStringContainsString( '1 = 0', $result['where'] );
	}

	/**
	 * Test that subgroups nest to arbitrary depth.
	 *
	 * @since 3.1.0
	 */
	public function test_deeply_nested_subgroups() {
		$result = $this->parse(
			array(
				'name'  => 'parent',
				'where' => array(
					'relation' => 'OR',
					'status'   => 'active',
					array(
						'relation' => 'AND',
						'total'    => array(
							'compare' => '>',
							'value'   => 100,
						),
						array(
							'relation' => 'OR',
							'order_id' => array( 1, 2, 3 ),
							'status'   => 'pending',
						),
					),
				),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringNotContainsString( '1 = 0', $result['where'] );
		$this->assertStringContainsString( ' OR ', $result['where'] );
		$this->assertStringContainsString( ' AND ', $result['where'] );
		$this->assertStringContainsStringIgnoringCase( 'IN', $result['where'] );

		// Three levels deep => at least three opening parens overall.
		$this->assertGreaterThanOrEqual( 3, substr_count( $result['where'], '(' ) );
	}

	/**
	 * Test that two clauses naming the SAME belongs_to relationship with different
	 * join modes get DISTINCT aliases, instead of emitting `AS bdb_rel_parent`
	 * twice (which MySQL rejects as a duplicate alias).
	 *
	 * @since 3.1.0
	 */
	public function test_same_relationship_distinct_join_modes_get_distinct_aliases() {
		$result = $this->parse(
			array(
				array(
					'name' => 'parent',
					'join' => 'inner',
				),
				array(
					'name' => 'parent',
					'join' => 'left',
				),
			),
			array( 'parent' => $this->relationship() )
		);

		// Both join modes are present...
		$this->assertStringContainsString( 'INNER JOIN', $result['join'] );
		$this->assertStringContainsString( 'LEFT JOIN', $result['join'] );

		// ...under DISTINCT aliases (base + disambiguated), never colliding.
		$this->assertStringContainsString( 'AS `bdb_rel_parent`', $result['join'] );
		$this->assertStringContainsString( 'AS `bdb_rel_parent_2`', $result['join'] );
		$this->assertSame( 1, substr_count( $result['join'], 'AS `bdb_rel_parent`' ) );
	}

	/**
	 * Test that two exact-duplicate clauses collapse to a single JOIN (no redundant
	 * second join and no spurious disambiguation suffix).
	 *
	 * @since 3.1.0
	 */
	public function test_identical_relationship_clauses_are_deduped() {
		$result = $this->parse(
			array(
				array( 'name' => 'parent' ),
				array( 'name' => 'parent' ),
			),
			array( 'parent' => $this->relationship() )
		);

		$this->assertSame( 1, substr_count( $result['join'], 'AS `bdb_rel_parent`' ) );
		$this->assertStringNotContainsString( 'bdb_rel_parent_2', $result['join'] );
	}
}
