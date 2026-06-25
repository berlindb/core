<?php
/**
 * add_items() Operation tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * End-to-end tests for Query::add_items() and the Operations\Add it drives.
 *
 * add_items() inserts each element of its input through add_item(), so per-item
 * default values, primary-key generation, sanitization, and cache priming still
 * happen. Unlike delete_items()/update_items() it does not resolve a set of
 * existing IDs - the input is always a plain list of new-item data arrays - and it
 * returns the new IDs (in input order, false in any failed slot) rather than a count.
 *
 * @since 3.1.0
 */
class AddItemsTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/**
	 * Install the fixture table and query object before tests run.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new TestQuery();
	}

	/**
	 * Uninstall the fixture table after tests complete.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Start each test from an empty table.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		self::$table->delete_all();
		wp_cache_flush();
	}

	/**
	 * Count rows currently in the table.
	 *
	 * @since 3.1.0
	 *
	 * @return int
	 */
	private function row_count(): int {
		return count( self::$query->query( array( 'number' => 100 ) ) );
	}

	/**
	 * Test that several rows are inserted and their new IDs are returned in order.
	 *
	 * @since 3.1.0
	 */
	public function test_add_multiple_returns_new_ids() {
		$ids = self::$query->add_items(
			array(
				array(
					'name'   => 'Alpha',
					'status' => 'active',
				),
				array(
					'name'   => 'Beta',
					'status' => 'pending',
				),
				array(
					'name'   => 'Gamma',
					'status' => 'inactive',
				),
			)
		);

		$this->assertCount( 3, $ids );
		$this->assertContainsOnly( 'int', $ids );
		$this->assertSame( 3, $this->row_count() );

		// Each returned ID resolves to the row that was inserted for it.
		$this->assertSame( 'Alpha', self::$query->get_item( $ids[0] )->name );
		$this->assertSame( 'Beta', self::$query->get_item( $ids[1] )->name );
		$this->assertSame( 'Gamma', self::$query->get_item( $ids[2] )->name );
	}

	/**
	 * Test that a single-element input returns a single-element ID list.
	 *
	 * @since 3.1.0
	 */
	public function test_add_single_row() {
		$ids = self::$query->add_items( array( array( 'name' => 'Solo' ) ) );

		$this->assertCount( 1, $ids );
		$this->assertSame( 'Solo', self::$query->get_item( $ids[0] )->name );
	}

	/**
	 * Test that an empty input inserts nothing and returns an empty array.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_input_adds_nothing() {
		$this->assertSame( array(), self::$query->add_items( array() ) );
		$this->assertSame( 0, $this->row_count() );
	}

	/**
	 * Test that inserting routes through add_item(): an omitted column takes its
	 * schema default (a raw multi-row INSERT would not apply defaults).
	 *
	 * @since 3.1.0
	 */
	public function test_add_applies_column_defaults() {
		$ids = self::$query->add_items( array( array( 'name' => 'Defaulted' ) ) );

		// 'status' was omitted, so it should take the column default of 'active'.
		$this->assertSame( 'active', self::$query->get_item( $ids[0] )->status );
	}

	/**
	 * Test that inserting routes through add_item(): the transition action fires
	 * once per row (a single multi-row INSERT would fire none).
	 *
	 * @since 3.1.0
	 */
	public function test_add_routes_through_add_item() {
		$fired = array();

		add_action(
			'berlindb_database_transition_widget_status',
			static function ( $old, $new, $item_id ) use ( &$fired ) {
				$fired[] = array( $old, $new, $item_id );
			},
			10,
			3
		);

		$ids = self::$query->add_items(
			array(
				array(
					'name'   => 'One',
					'status' => 'pending',
				),
				array(
					'name'   => 'Two',
					'status' => 'inactive',
				),
			)
		);

		$this->assertCount( 2, $fired );

		// On insert the "old" value is the literal string "new" for every row.
		$this->assertSame( 'new', $fired[0][0] );
		$this->assertSame( 'pending', $fired[0][1] );
		$this->assertSame( $ids[0], $fired[0][2] );

		$this->assertSame( 'new', $fired[1][0] );
		$this->assertSame( 'inactive', $fired[1][1] );
		$this->assertSame( $ids[1], $fired[1][2] );
	}

	/**
	 * Test that a non-array element fails its slot (false) without inserting, while
	 * valid elements around it still insert.
	 *
	 * @since 3.1.0
	 */
	public function test_non_array_element_fails_its_slot() {
		$ids = self::$query->add_items(
			array(
				array( 'name' => 'First' ),
				'not-a-row',
				array( 'name' => 'Third' ),
			)
		);

		$this->assertCount( 3, $ids );
		$this->assertIsInt( $ids[0] );
		$this->assertFalse( $ids[1] );
		$this->assertIsInt( $ids[2] );

		// Only the two valid rows were inserted.
		$this->assertSame( 2, $this->row_count() );
	}

	/**
	 * Test that a duplicate primary key fails that slot but does not abort the batch.
	 *
	 * @since 3.1.0
	 */
	public function test_failed_insert_is_false_slot() {
		$first = self::$query->add_item( array( 'name' => 'Existing' ) );

		$ids = self::$query->add_items(
			array(
				// Collides with an existing primary key, so this slot fails.
				array(
					'id'   => $first,
					'name' => 'Duplicate',
				),
				array( 'name' => 'Fresh' ),
			)
		);

		$this->assertCount( 2, $ids );
		$this->assertFalse( $ids[0] );
		$this->assertIsInt( $ids[1] );
	}
}
