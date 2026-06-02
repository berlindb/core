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
			'name'      => 'status',
			'type'      => 'varchar',
			'length'    => '20',
			'default'   => '',
			'cache_key' => true,
			'in'        => true,
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
	protected $version = '202606010';
}

/**
 * Row shape for the priming fixture.
 */
class PrimingRow extends Row {
	public $id        = 0;
	public $status    = '';
	public $parent_id = 0;
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
}
