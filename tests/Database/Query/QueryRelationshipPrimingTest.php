<?php
/**
 * Tests for belongs_to relationship cache priming (berlindb/core #193, Phase 3).
 *
 * Uses a self-referential fixture (parent_id -> id) so a single table exercises
 * the full priming path. After a query that returns only child rows, the parent
 * rows they reference should be warmed in the item cache, so a later get_item()
 * for a parent fires no additional SQL.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Row;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

// ---------------------------------------------------------------------------
// Self-referential fixtures: rows belong_to a parent row in the same table.
// ---------------------------------------------------------------------------

/**
 * Schema with a parent_id column that belongs_to the same query's id column.
 */
class PrimingSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'extra'         => 'auto_increment',
			'cache_key'     => true,
			'sortable'      => true,
			'relationships' => array(
				array(
					'name'   => 'children',
					'query'  => PrimingQuery::class,
					'column' => 'parent_id',
					'type'   => 'has_many',
				),
			),
		),
		array(
			'name'          => 'status',
			'type'          => 'varchar',
			'length'        => '20',
			'default'       => '',
			'cache_key'     => true,
			'in'            => true,
			// A relationship pointing at a real class that is NOT a Query, to
			// exercise resolve_remote_query()'s instanceof guard (fails closed).
			'relationships' => array(
				array(
					'name'   => 'bogus',
					'query'  => 'stdClass',
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
		),
		array(
			'name'          => 'parent_id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'default'       => 0,
			'in'            => true,
			'relationships' => array(
				array(
					'query'  => PrimingQuery::class,
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
		),

		// A numeric-valued string column, to exercise typed CAST comparisons.
		array(
			'name'    => 'amount',
			'type'    => 'varchar',
			'length'  => '20',
			'default' => '',
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/**
 * Table for the priming fixture.
 */
class PrimingTable extends Table {
	protected $schema  = PrimingSchema::class;
	protected $name    = 'berlindb_priming_test';
	protected $version = '202606020';
}

/**
 * Row shape for the priming fixture.
 */
class PrimingRow extends Row {
	public $id        = 0;
	public $status    = '';
	public $parent_id = 0;
	public $amount    = '';
}

/**
 * Query for the priming fixture.
 */
class PrimingQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'priming_test';
	protected $table_alias      = 'pt';
	protected $table_schema     = PrimingSchema::class;
	protected $item_name        = 'priming';
	protected $item_name_plural = 'primings';
	protected $item_shape       = PrimingRow::class;
	protected $cache_group      = 'berlindb-priming';
}

/**
 * Tests for belongs_to relationship cache priming.
 *
 * @since 3.1.0
 */
class QueryRelationshipPrimingTest extends TestCase {

	/** @var PrimingTable */
	private static $table;

	/** @var PrimingQuery */
	private static $query;

	/** @var int Parent row id for the current test. */
	private $parent_id = 0;

	/** @var int Child row id for the current test. */
	private $child_id = 0;

	/**
	 * Install the fixture table once.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$table = new PrimingTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new PrimingQuery();
	}

	/**
	 * Uninstall the fixture table after the suite.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Seed one parent and one child, then start each test with a cold cache.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// reduce_item() runs capability checks before saving.
		wp_set_current_user( 1 );

		self::$table->delete_all();

		$this->parent_id = (int) self::$query->add_item( array( 'status' => 'parent' ) );
		$this->child_id  = (int) self::$query->add_item(
			array(
				'status'    => 'child',
				'parent_id' => $this->parent_id,
			)
		);

		wp_cache_flush();
	}

	/**
	 * Number of SQL queries fired by a get_item() call for the parent row.
	 *
	 * @since 3.1.0
	 *
	 * @return int
	 */
	private function queries_to_fetch_parent(): int {
		global $wpdb;

		$before = $wpdb->num_queries;
		self::$query->get_item( $this->parent_id );

		return $wpdb->num_queries - $before;
	}

	/**
	 * Test that naming a belongs_to relationship in 'with' warms its cache, so
	 * fetching the parent later fires no SQL.
	 *
	 * @since 3.1.0
	 */
	public function test_belongs_to_priming_warms_remote_cache() {
		self::$query->query(
			array(
				'status' => 'child',
				'with'   => array( 'parent' ),
			)
		);

		$this->assertSame( 0, $this->queries_to_fetch_parent() );
	}

	/**
	 * Test that priming is quiet by default — the parent is not warmed, so
	 * fetching it later hits the database.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_priming_is_quiet_by_default() {
		self::$query->query( array( 'status' => 'child' ) );

		$this->assertGreaterThan( 0, $this->queries_to_fetch_parent() );
	}

	/**
	 * Test that a 'with' list naming only unrelated relationships primes nothing.
	 *
	 * @since 3.1.0
	 */
	public function test_with_arg_unknown_name_does_not_prime() {
		self::$query->query(
			array(
				'status' => 'child',
				'with'   => array( 'nope' ),
			)
		);

		$this->assertGreaterThan( 0, $this->queries_to_fetch_parent() );
	}

	// get_related() (#193, Phase 4).

	/**
	 * Test that get_related() returns the parent Row for a belongs_to.
	 *
	 * @since 3.1.0
	 */
	public function test_get_related_returns_belongs_to_row() {
		$child  = self::$query->get_item( $this->child_id );
		$parent = self::$query->get_related( $child, 'parent' );

		$this->assertInstanceOf( PrimingRow::class, $parent );
		$this->assertSame( $this->parent_id, (int) $parent->id );
	}

	/**
	 * Test that get_related() returns the child collection for a has_many.
	 *
	 * @since 3.1.0
	 */
	public function test_get_related_returns_has_many_collection() {
		$parent   = self::$query->get_item( $this->parent_id );
		$children = self::$query->get_related( $parent, 'children' );

		$this->assertIsArray( $children );
		$this->assertCount( 1, $children );
		$this->assertSame( $this->child_id, (int) $children[0]->id );
	}

	/**
	 * Test that a belongs_to with an empty local key returns null.
	 *
	 * @since 3.1.0
	 */
	public function test_get_related_belongs_to_empty_key_is_null() {
		// The parent row has parent_id = 0, so its 'parent' relation is empty.
		$parent = self::$query->get_item( $this->parent_id );

		$this->assertNull( self::$query->get_related( $parent, 'parent' ) );
	}

	/**
	 * Test that an unknown relationship name returns null.
	 *
	 * @since 3.1.0
	 */
	public function test_get_related_unknown_name_is_null() {
		$child = self::$query->get_item( $this->child_id );

		$this->assertNull( self::$query->get_related( $child, 'nope' ) );
	}

	/**
	 * Test that a primed belongs_to is fetched by get_related() with no SQL.
	 *
	 * @since 3.1.0
	 */
	public function test_get_related_uses_primed_cache() {
		global $wpdb;

		// Prime the parent via the child query.
		$results = self::$query->query(
			array(
				'status' => 'child',
				'with'   => array( 'parent' ),
			)
		);
		$child   = reset( $results );

		$before = $wpdb->num_queries;
		$parent = self::$query->get_related( $child, 'parent' );
		$after  = $wpdb->num_queries;

		$this->assertSame( $this->parent_id, (int) $parent->id );
		$this->assertSame( $before, $after );
	}

	/**
	 * Test that get_related() fails closed when the relationship's query class
	 * is a real class but NOT a sibling Query (resolve_remote_query() guard).
	 *
	 * @since 3.1.0
	 */
	public function test_get_related_non_query_class_is_null() {
		// The 'bogus' relationship points at stdClass — a class that exists but
		// is not a Query, so the remote can't be resolved.
		$item = (object) array( 'status' => 'anything' );

		$this->assertNull( self::$query->get_related( $item, 'bogus' ) );
	}

	/**
	 * Test the empty-relationship-key policy: a 0 or '0' local key means "no
	 * relation", so get_related() returns null for a belongs_to without querying.
	 *
	 * @since 3.1.0
	 */
	public function test_get_related_zero_key_is_no_relation() {
		// Integer zero and string '0' both count as unset.
		$this->assertNull( self::$query->get_related( (object) array( 'parent_id' => 0 ), 'parent' ) );
		$this->assertNull( self::$query->get_related( (object) array( 'parent_id' => '0' ), 'parent' ) );

		// A real key still resolves (sanity: the guard isn't over-broad).
		$resolved = self::$query->get_related( (object) array( 'parent_id' => $this->parent_id ), 'parent' );
		$this->assertSame( $this->parent_id, (int) $resolved->id );
	}

	// has_many collection priming (#193).

	/**
	 * Test that priming a has_many warms the child collection, so fetching it
	 * with get_related() fires no SQL.
	 *
	 * @since 3.1.0
	 */
	public function test_has_many_priming_warms_collection() {
		global $wpdb;

		// Query the parent with its children primed in bulk.
		self::$query->query(
			array(
				'status' => 'parent',
				'with'   => array( 'children' ),
			)
		);

		$parent = self::$query->get_item( $this->parent_id );

		$before   = $wpdb->num_queries;
		$children = self::$query->get_related( $parent, 'children' );
		$after    = $wpdb->num_queries;

		$this->assertCount( 1, $children );
		$this->assertSame( $this->child_id, (int) $children[0]->id );
		$this->assertSame( $before, $after );
	}

	/**
	 * Test that childless parents are primed as an empty collection (cache hit).
	 *
	 * @since 3.1.0
	 */
	public function test_has_many_priming_caches_empty_collection() {
		global $wpdb;

		// The child row has no children of its own.
		self::$query->query(
			array(
				'status' => 'child',
				'with'   => array( 'children' ),
			)
		);

		$child = self::$query->get_item( $this->child_id );

		$before   = $wpdb->num_queries;
		$children = self::$query->get_related( $child, 'children' );
		$after    = $wpdb->num_queries;

		$this->assertSame( array(), $children );
		$this->assertSame( $before, $after );
	}

	/**
	 * Test that the collection cache invalidates when a new child is written.
	 *
	 * @since 3.1.0
	 */
	public function test_has_many_collection_invalidates_on_write() {
		// Prime and read the initial collection.
		self::$query->query(
			array(
				'status' => 'parent',
				'with'   => array( 'children' ),
			)
		);

		$parent = self::$query->get_item( $this->parent_id );
		$this->assertCount( 1, self::$query->get_related( $parent, 'children' ) );

		// Add a second child; the write rotates last_changed.
		self::$query->add_item(
			array(
				'status'    => 'child',
				'parent_id' => $this->parent_id,
			)
		);

		// The stale collection must not be served.
		$this->assertCount( 2, self::$query->get_related( $parent, 'children' ) );
	}

	/**
	 * Test that get_related() returns the FULL child set even past the default
	 * 100-row page, and that the primed and unprimed paths agree.
	 *
	 * Regression for the cache/limit mismatch: priming stored every child ID,
	 * but get_related() queried at the default limit, so a primed lookup (all
	 * children) and an unprimed one (the first 100) disagreed. Both now query
	 * with number => 0.
	 *
	 * @since 3.1.0
	 */
	public function test_has_many_returns_all_children_past_default_limit() {

		// A parent with more children than the default page (100).
		$big_parent = (int) self::$query->add_item( array( 'status' => 'big-parent' ) );

		for ( $i = 0; $i < 101; $i++ ) {
			self::$query->add_item(
				array(
					'status'    => 'child',
					'parent_id' => $big_parent,
				)
			);
		}

		// Unprimed: a relationship accessor must return all 101, not the first 100.
		wp_cache_flush();
		$parent = self::$query->get_item( $big_parent );
		$this->assertCount( 101, self::$query->get_related( $parent, 'children' ) );

		// Primed via 'with': must agree with the unprimed count exactly.
		wp_cache_flush();
		self::$query->query(
			array(
				'status' => 'big-parent',
				'with'   => array( 'children' ),
			)
		);
		$parent = self::$query->get_item( $big_parent );
		$this->assertCount( 101, self::$query->get_related( $parent, 'children' ) );
	}

	// Relationship filtering — 'in' strategy (#193, Phase 5).

	/**
	 * Test that the 'in' strategy filters local rows by a belongs_to parent's
	 * attribute (only the child has a parent with status 'parent').
	 *
	 * @since 3.1.0
	 */
	public function test_relation_in_filters_by_belongs_to() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'where'    => array( 'status' => 'parent' ),
					'strategy' => 'in',
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( $this->child_id, (int) $results[0]->id );
	}

	/**
	 * Test that a filter matching no parents returns NOTHING (not every row).
	 * This is the critical empty-subquery short-circuit.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_in_empty_match_returns_nothing() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'  => 'parent',
					'where' => array( 'status' => 'does-not-exist' ),
				),
			)
		);

		$this->assertSame( array(), $results );
	}

	/**
	 * Test that an unknown relationship name fails closed (no rows), never open.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_in_unknown_name_fails_closed() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'  => 'nope',
					'where' => array( 'status' => 'parent' ),
				),
			)
		);

		$this->assertSame( array(), $results );
	}

	/**
	 * Test that the 'in' strategy on a has_many relationship fails closed
	 * (unsupported), rather than ignoring the filter and returning all rows.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_in_on_has_many_fails_closed() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'  => 'children',
					'where' => array( 'status' => 'child' ),
				),
			)
		);

		$this->assertSame( array(), $results );
	}

	// Relationship filtering — 'join' strategy (#193, Phase 5b).

	/**
	 * Test that the 'join' strategy filters local rows by a belongs_to parent's
	 * attribute via a real INNER JOIN (only the child has a 'parent' parent).
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_filters_by_belongs_to() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'where'    => array( 'status' => 'parent' ),
					'strategy' => 'join',
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( $this->child_id, (int) $results[0]->id );
	}

	/**
	 * Test that a join filter matching no parents returns no rows.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_empty_match_returns_nothing() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'where'    => array( 'status' => 'does-not-exist' ),
					'strategy' => 'join',
				),
			)
		);

		$this->assertSame( array(), $results );
	}

	/**
	 * Test that the 'join' strategy delegates to Operators (array value => IN).
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_uses_operators() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'where'    => array( 'status' => array( 'parent', 'other' ) ),
					'strategy' => 'join',
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( $this->child_id, (int) $results[0]->id );
	}

	/**
	 * Test end-to-end that an opt-in cast changes which rows match. The parent's
	 * amount is stored as text ('100', '30'); filtering children by
	 * parent.amount > '50' returns nothing under a string compare ('1' < '5'),
	 * but returns the child of the '100' parent once the value is CAST to SIGNED.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_typed_cast_changes_results() {

		// Two parents whose amounts sort differently as text vs as numbers.
		$big_parent   = (int) self::$query->add_item(
			array(
				'status' => 'parent',
				'amount' => '100',
			)
		);
		$small_parent = (int) self::$query->add_item(
			array(
				'status' => 'parent',
				'amount' => '30',
			)
		);

		$big_child = (int) self::$query->add_item(
			array(
				'status'    => 'child',
				'parent_id' => $big_parent,
			)
		);
		self::$query->add_item(
			array(
				'status'    => 'child',
				'parent_id' => $small_parent,
			)
		);

		$filter = array(
			'name'     => 'parent',
			'strategy' => 'join',
			'where'    => array(
				'amount' => array(
					'compare' => '>',
					'value'   => '50',
				),
			),
		);

		// Without a cast, a lexical string compare matches no parent ('1'/'3' < '5').
		$string_results = self::$query->query( array( 'relation' => array( $filter ) ) );
		$this->assertSame( array(), $string_results );

		// With an explicit SIGNED cast, the numeric compare matches the '100' parent.
		$filter['where']['amount']['cast'] = 'SIGNED';

		$cast_results = self::$query->query( array( 'relation' => array( $filter ) ) );
		$this->assertCount( 1, $cast_results );
		$this->assertSame( $big_child, (int) $cast_results[0]->id );
	}

	/**
	 * Test that an unknown relationship name fails closed for the join strategy.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_unknown_name_fails_closed() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'nope',
					'where'    => array( 'status' => 'parent' ),
					'strategy' => 'join',
				),
			)
		);

		$this->assertSame( array(), $results );
	}

	/**
	 * Test that a has_many join filter (WHERE EXISTS) returns each parent once.
	 * Only the parent row has a child with status 'child'.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_has_many_filters_via_exists() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'children',
					'where'    => array( 'status' => 'child' ),
					'strategy' => 'join',
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( $this->parent_id, (int) $results[0]->id );
	}

	/**
	 * Test a belongs_to anti join (exists => false): rows whose parent does not
	 * have status 'parent'. Only the parent row (which has no parent) qualifies.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_belongs_to_anti() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'where'    => array( 'status' => 'parent' ),
					'strategy' => 'join',
					'exists'   => false,
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( $this->parent_id, (int) $results[0]->id );
	}

	/**
	 * Test a has_many anti join (exists => false): rows that have no child with
	 * status 'child'. Only the child row (which has no children) qualifies.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_has_many_anti() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'children',
					'where'    => array( 'status' => 'child' ),
					'strategy' => 'join',
					'exists'   => false,
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( $this->child_id, (int) $results[0]->id );
	}

	/**
	 * Test that a relation where with relation => OR matches via either branch.
	 * The child's parent has status 'parent', satisfying the OR group.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_where_or() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'strategy' => 'join',
					'where'    => array(
						'relation' => 'OR',
						'status'   => array( 'parent', 'unused' ),
						'id'       => array(
							'compare' => '>',
							'value'   => 999999,
						),
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( $this->child_id, (int) $results[0]->id );
	}

	/**
	 * Test that a belongs_to LEFT JOIN keeps unmatched local rows. With no
	 * conditions, both the parent (no parent of its own) and the child are
	 * returned — unlike an INNER JOIN, which would return only the child.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_left_includes_unmatched() {
		$results = self::$query->query(
			array(
				'orderby'  => 'id',
				'order'    => 'ASC',
				'relation' => array(
					'name'     => 'parent',
					'strategy' => 'join',
					'join'     => 'left',
				),
			)
		);

		$ids = array_map( 'intval', wp_list_pluck( $results, 'id' ) );

		$this->assertCount( 2, $results );
		$this->assertContains( $this->parent_id, $ids );
		$this->assertContains( $this->child_id, $ids );
	}

	/**
	 * Test that a LEFT JOIN WITH a where condition filters unmatched rows — the
	 * condition is emitted into the outer WHERE, so the unmatched parent row
	 * (parent_id = 0, NULL-joined) is excluded and LEFT behaves like INNER. Pins
	 * the documented caveat: 'join' => 'left' keeps unmatched rows only when
	 * there are no conditions.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_left_with_condition_filters_unmatched() {
		$results = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'strategy' => 'join',
					'join'     => 'left',
					'where'    => array( 'status' => 'parent' ),
				),
			)
		);

		// Only the child matches (its parent has status 'parent'); the parent
		// row has no parent, so the condition filters it out despite the LEFT.
		$this->assertCount( 1, $results );
		$this->assertSame( $this->child_id, (int) $results[0]->id );
	}

	/**
	 * Test that a malformed relation spec (missing 'name', e.g. a 'relationship'
	 * => 'parent' typo) FAILS CLOSED — returns no rows, never every row.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_malformed_spec_fails_closed() {
		$results = self::$query->query(
			array(
				'relation' => array(
					array( 'where' => array( 'status' => 'parent' ) ), // no 'name'
				),
			)
		);

		$this->assertSame( array(), $results );
	}

	/**
	 * Test that a non-array (scalar) relation fails closed — `relation =>
	 * 'parent'` is an explicit-but-unusable filter, so it returns no rows rather
	 * than being ignored and widening to all rows.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_scalar_fails_closed() {
		$results = self::$query->query(
			array(
				'relation' => 'parent',
			)
		);

		$this->assertSame( array(), $results );
	}

	/**
	 * Test that 'with' does NOT segment the result cache key. It only controls
	 * relationship priming side effects, not which rows return — so a query that
	 * differs from a cached one ONLY by 'with' must hit the same cache entry.
	 *
	 * @since 3.1.0
	 */
	public function test_with_does_not_segment_result_cache() {
		global $wpdb;

		// Warm the result cache with 'with' present (also primes the parent).
		self::$query->query(
			array(
				'status' => 'child',
				'with'   => array( 'parent' ),
			)
		);

		// The same query WITHOUT 'with' must be a cache hit (no SQL) — same key.
		$before  = $wpdb->num_queries;
		$results = self::$query->query( array( 'status' => 'child' ) );
		$after   = $wpdb->num_queries;

		$this->assertSame( $before, $after );
		$this->assertCount( 1, $results );
		$this->assertSame( $this->child_id, (int) $results[0]->id );
	}

	// Cache-key segmentation across different relationship filters (#193).

	/**
	 * Test that two different 'in'-strategy relationship filters resolve to
	 * distinct cache keys — the second filter must not be served the first's
	 * cached result. The 'in' strategy rewrites to a {fk}__in var, which already
	 * segments the key; this pins that it stays true.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_in_filters_segment_the_cache_key() {

		// Two parents with distinct statuses, each with its own child.
		$red_parent  = (int) self::$query->add_item( array( 'status' => 'red' ) );
		$blue_parent = (int) self::$query->add_item( array( 'status' => 'blue' ) );
		$red_child   = (int) self::$query->add_item(
			array(
				'status'    => 'child',
				'parent_id' => $red_parent,
			)
		);
		$blue_child  = (int) self::$query->add_item(
			array(
				'status'    => 'child',
				'parent_id' => $blue_parent,
			)
		);

		// Filter to children of the red parent (warms a cache entry).
		$red = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'where'    => array( 'status' => 'red' ),
					'strategy' => 'in',
				),
			)
		);

		// Filter to children of the blue parent — must compute a different key.
		$blue = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'where'    => array( 'status' => 'blue' ),
					'strategy' => 'in',
				),
			)
		);

		$this->assertSame( array( $red_child ), array_map( 'intval', wp_list_pluck( $red, 'id' ) ) );
		$this->assertSame( array( $blue_child ), array_map( 'intval', wp_list_pluck( $blue, 'id' ) ) );
	}

	/**
	 * Test that two different 'join'-strategy filters resolve to distinct cache
	 * keys. The 'join' strategy routes specs into the 'relation_query' parser var,
	 * which is registered and so segments the cache key per filter.
	 *
	 * @since 3.1.0
	 */
	public function test_relation_join_filters_segment_the_cache_key() {

		$red_parent  = (int) self::$query->add_item( array( 'status' => 'red' ) );
		$blue_parent = (int) self::$query->add_item( array( 'status' => 'blue' ) );
		$red_child   = (int) self::$query->add_item(
			array(
				'status'    => 'child',
				'parent_id' => $red_parent,
			)
		);
		$blue_child  = (int) self::$query->add_item(
			array(
				'status'    => 'child',
				'parent_id' => $blue_parent,
			)
		);

		$red = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'where'    => array( 'status' => 'red' ),
					'strategy' => 'join',
				),
			)
		);

		$blue = self::$query->query(
			array(
				'relation' => array(
					'name'     => 'parent',
					'where'    => array( 'status' => 'blue' ),
					'strategy' => 'join',
				),
			)
		);

		$this->assertSame( array( $red_child ), array_map( 'intval', wp_list_pluck( $red, 'id' ) ) );
		$this->assertSame( array( $blue_child ), array_map( 'intval', wp_list_pluck( $blue, 'id' ) ) );
	}
}
