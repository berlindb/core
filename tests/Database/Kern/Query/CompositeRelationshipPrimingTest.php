<?php
/**
 * Composite (multi-column) relationship priming (#229).
 *
 * Phase 1: the tuple-collection helper (composite analog of
 * get_local_relationship_key_values()) - distinct, all-parts-present local key
 * tuples across items, plus a stable de-dup hash. Exercised via reflection.
 *
 * Phase 2: the portable bulk-fetch helper - one OR-of-ANDs SELECT reads every row
 * matching a set of composite key tuples (decoys excluded), warming the by-id
 * cache. Exercised against a real fixture table.
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

/** Schema keyed by a ( region, account ) pair alongside its primary id. */
class CtpSchema extends Schema {
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
			'name'     => 'region',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'     => 'account',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

class CtpRow extends Row {
	public $id      = 0;
	public $region  = 0;
	public $account = 0;
}

class CtpQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'ctp_test';
	protected $table_alias      = 'ctp';
	protected $table_schema     = CtpSchema::class;
	protected $item_name        = 'ctp';
	protected $item_name_plural = 'ctps';
	protected $item_shape       = CtpRow::class;
	protected $cache_group      = 'berlindb-ctp';
}

class CtpTable extends Table {
	protected $schema  = CtpSchema::class;
	protected $name    = 'berlindb_ctp_test';
	protected $version = '202607090';
}

/**
 * Tests for composite relationship-key tuple collection + bulk fetch.
 *
 * @since 3.1.0
 */
class CompositeRelationshipPrimingTest extends TestCase {

	/** @var CtpTable */
	private static $table;

	/** @var CtpQuery */
	private static $query;

	/**
	 * Install the fixture table and construct a Query once.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new CtpTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}

		self::$query = new CtpQuery();
	}

	/**
	 * Reset rows before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		self::$table->delete_all();
		wp_cache_flush();
	}

	/**
	 * Drop the fixture table after the class.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Invoke a private Cache-trait method on the fixture query.
	 *
	 * @since 3.1.0
	 *
	 * @param string       $method Method name.
	 * @param array<mixed> $args   Positional arguments.
	 * @return mixed
	 */
	private function invoke( string $method, array $args ) {
		return ( new \ReflectionMethod( CtpQuery::class, $method ) )->invokeArgs( self::$query, $args );
	}

	// -------------------------------------------------------------------------
	// Phase 1: tuple collection.
	// -------------------------------------------------------------------------

	/**
	 * Test that complete tuples are collected and de-duplicated.
	 *
	 * @since 3.1.0
	 */
	public function test_collects_distinct_complete_tuples() {
		$items = array(
			(object) array(
				'region'  => 5,
				'account' => 7,
			),
			(object) array(
				'region'  => 5,
				'account' => 7,
			),
			(object) array(
				'region'  => 5,
				'account' => 9,
			),
		);

		$this->assertSame(
			array(
				array(
					'region'  => 5,
					'account' => 7,
				),
				array(
					'region'  => 5,
					'account' => 9,
				),
			),
			$this->invoke( 'get_local_relationship_key_tuples', array( $items, array( 'region', 'account' ) ) )
		);
	}

	/**
	 * Test that a missing or empty key part drops the whole tuple (no relation).
	 *
	 * @since 3.1.0
	 */
	public function test_drops_partial_and_empty_tuples() {
		$items = array(
			(object) array( 'region' => 5 ),
			(object) array(
				'region'  => 0,
				'account' => 7,
			),
			(object) array(
				'region'  => 8,
				'account' => 3,
			),
		);

		$this->assertSame(
			array(
				array(
					'region'  => 8,
					'account' => 3,
				),
			),
			$this->invoke( 'get_local_relationship_key_tuples', array( $items, array( 'region', 'account' ) ) )
		);
	}

	/**
	 * Test that non-objects are skipped.
	 *
	 * @since 3.1.0
	 */
	public function test_skips_non_objects() {
		$items = array(
			'not-an-object',
			(object) array(
				'region'  => 1,
				'account' => 2,
			),
		);

		$this->assertSame(
			array(
				array(
					'region'  => 1,
					'account' => 2,
				),
			),
			$this->invoke( 'get_local_relationship_key_tuples', array( $items, array( 'region', 'account' ) ) )
		);
	}

	/**
	 * Test that empty items or empty key columns collect no tuples.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_inputs_collect_no_tuples() {
		$items = array(
			(object) array(
				'region'  => 5,
				'account' => 7,
			),
		);

		$this->assertSame( array(), $this->invoke( 'get_local_relationship_key_tuples', array( $items, array() ) ) );
		$this->assertSame( array(), $this->invoke( 'get_local_relationship_key_tuples', array( array(), array( 'region' ) ) ) );
	}

	/**
	 * Test that the tuple hash separates boundary values that would otherwise merge.
	 *
	 * @since 3.1.0
	 */
	public function test_hash_distinguishes_boundary_tuples() {
		$a = $this->invoke(
			'get_relationship_tuple_hash',
			array(
				array(
					'x' => 1,
					'y' => 23,
				),
			)
		);
		$b = $this->invoke(
			'get_relationship_tuple_hash',
			array(
				array(
					'x' => 12,
					'y' => 3,
				),
			)
		);

		$this->assertNotSame( $a, $b );
		$this->assertSame(
			$a,
			$this->invoke(
				'get_relationship_tuple_hash',
				array(
					array(
						'x' => 1,
						'y' => 23,
					),
				)
			)
		);

		// Values containing the separator still cannot collide (length-prefixed).
		$sep_a = $this->invoke(
			'get_relationship_tuple_hash',
			array(
				array(
					'x' => '5|1',
					'y' => '7',
				),
			)
		);
		$sep_b = $this->invoke(
			'get_relationship_tuple_hash',
			array(
				array(
					'x' => '5',
					'y' => '1|7',
				),
			)
		);
		$this->assertNotSame( $sep_a, $sep_b );

		// String-cast de-dup: 1 (int) and '1' (string) hash alike.
		$this->assertSame(
			$this->invoke( 'get_relationship_tuple_hash', array( array( 'x' => 1 ) ) ),
			$this->invoke( 'get_relationship_tuple_hash', array( array( 'x' => '1' ) ) )
		);
	}

	// -------------------------------------------------------------------------
	// Phase 2: portable bulk fetch.
	// -------------------------------------------------------------------------

	/**
	 * The ( region, account ) pair of a fetched row, as a string for comparison.
	 *
	 * @since 3.1.0
	 *
	 * @param object $row A fetched row.
	 * @return string
	 */
	private function pair( object $row ): string {
		return "{$row->region},{$row->account}";
	}

	/**
	 * Test that one bulk fetch reads the rows matching a set of tuples, no decoys.
	 *
	 * @since 3.1.0
	 */
	public function test_bulk_fetch_matches_tuples_and_excludes_decoys() {
		self::$query->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		); // match
		self::$query->add_item(
			array(
				'region'  => 8,
				'account' => 3,
			)
		); // match
		self::$query->add_item(
			array(
				'region'  => 5,
				'account' => 9,
			)
		); // not requested
		self::$query->add_item(
			array(
				'region'  => 5,
				'account' => 3,
			)
		); // decoy: region-only
		self::$query->add_item(
			array(
				'region'  => 9,
				'account' => 7,
			)
		); // decoy: account-only

		$rows = $this->invoke(
			'get_relationship_tuple_rows',
			array(
				array( 'region', 'account' ),
				array(
					array(
						'region'  => 5,
						'account' => 7,
					),
					array(
						'region'  => 8,
						'account' => 3,
					),
				),
			)
		);

		$pairs = array_map( array( $this, 'pair' ), $rows );
		sort( $pairs );

		$this->assertSame( array( '5,7', '8,3' ), $pairs );
	}

	/**
	 * Test that an invalid reference column bails to no rows (injection guard).
	 *
	 * @since 3.1.0
	 */
	public function test_bulk_fetch_invalid_column_bails() {
		self::$query->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);

		$rows = $this->invoke(
			'get_relationship_tuple_rows',
			array(
				array( 'region', 'not_a_column' ),
				array(
					array(
						'region'       => 5,
						'not_a_column' => 7,
					),
				),
			)
		);

		$this->assertSame( array(), $rows );
	}

	/**
	 * Test that no tuples bails to no rows.
	 *
	 * @since 3.1.0
	 */
	public function test_bulk_fetch_no_tuples_bails() {
		self::$query->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);

		$this->assertSame(
			array(),
			$this->invoke( 'get_relationship_tuple_rows', array( array( 'region', 'account' ), array() ) )
		);
	}
}
