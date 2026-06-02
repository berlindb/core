<?php
/**
 * Unit tests for the Relationship query parser (berlindb/core #193).
 *
 * These exercise Parsers\Relationship in isolation: a lightweight fake caller
 * supplies the relationship specs and resolved Relationship objects, and a
 * no-database remote Query fixture supplies the joined schema. No table is
 * installed and no query is executed — the tests assert the JOIN/WHERE SQL the
 * parser generates (and its fail-closed behaviour) directly.
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
 * The parser only calls get_query_var(), get_relationship() and
 * get_quoted_column_name_aliased() on its caller, so a tiny object suffices.
 */
class RelationshipParserCaller {

	/** @var mixed */
	public $specs;

	/** @var array<string, mixed> */
	public $relationships;

	/**
	 * @param mixed                 $specs         The relation_query value to return.
	 * @param array<string, mixed>  $relationships Map of name => Relationship|false.
	 */
	public function __construct( $specs = null, array $relationships = array() ) {
		$this->specs         = $specs;
		$this->relationships = $relationships;
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	public function get_query_var( $key = '' ) {
		return ( 'relation_query' === $key )
			? $this->specs
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
	 * Run the parser with given specs + a relationship map, return join/where.
	 *
	 * @param mixed                $specs
	 * @param array<string, mixed> $relationships
	 * @return array{join: string, where: string}
	 */
	private function parse( $specs, array $relationships = array() ) {
		$caller = new RelationshipParserCaller( $specs, $relationships );
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
	 * Test that a single belongs_to spec emits an INNER JOIN with a sane alias.
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

		// The remote table name is resolved connection-side (and is empty here
		// because no table is installed); assert the parser-owned structure.
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
	 * Test that a single (unwrapped) spec is normalized to a list.
	 *
	 * @since 3.1.0
	 */
	public function test_single_spec_is_normalized() {
		$result = $this->parse(
			array( 'name' => 'parent' ),
			array( 'parent' => $this->relationship() )
		);

		$this->assertStringContainsString( 'INNER JOIN', $result['join'] );
	}

	/**
	 * Test that multiple specs emit multiple JOINs.
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_specs_emit_multiple_joins() {
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
	 * Test that a spec with only a join (no where) emits the JOIN and no WHERE.
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

		// Force an unusual name straight onto the resolved object's accessor by
		// resolving under that key.
		$result = $this->parse(
			array( 'name' => 'weird name!' ),
			array( 'weird name!' => $rel )
		);

		// Non-identifier chars collapse to underscores; sanitize_table_alias()
		// normalizes runs and trims trailing underscores ('weird name!' -> 'weird_name').
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

		// EXISTS lives entirely in the WHERE — there is no JOIN.
		$this->assertSame( '', $result['join'] );
		$this->assertStringContainsString( 'EXISTS ( SELECT 1 FROM', $result['where'] );
		$this->assertStringContainsString( 'AS `bdb_rel_items`', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_items`.`order_id` = `o`.`id`', $result['where'] );
		$this->assertStringContainsString( '`bdb_rel_items`.`status`', $result['where'] );
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
	 * Test that a malformed spec (no name) is skipped without error.
	 *
	 * @since 3.1.0
	 */
	public function test_malformed_spec_skipped() {
		$result = $this->parse(
			array(
				array( 'where' => array( 'status' => 'active' ) ), // no 'name'
			),
			array()
		);

		$this->assertSame( '', $result['join'] );
		$this->assertSame( '', $result['where'] );
	}

	/**
	 * Test that a belongs_to spec with exists => false emits a NOT EXISTS (anti
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
	 * Test that a has_many spec with exists => false emits a NOT EXISTS.
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
		// 'relation' would not be a valid column; if it were treated as one this
		// would fail closed. Instead it selects AND/OR and the real column wins.
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
	 * Test that a belongs_to spec with join => 'left' emits a LEFT JOIN.
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

		// The OR group is nested inside the outer AND group: two opening parens
		// appear before the inner OR keyword.
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
	 * Test that two specs naming the SAME belongs_to relationship with different
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
	 * Test that two exact-duplicate specs collapse to a single JOIN (no redundant
	 * second join and no spurious disambiguation suffix).
	 *
	 * @since 3.1.0
	 */
	public function test_identical_relationship_specs_are_deduped() {
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
