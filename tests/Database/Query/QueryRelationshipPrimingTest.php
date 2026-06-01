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
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
			'sortable'  => true,
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
		self::$query->add_item(
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
}
