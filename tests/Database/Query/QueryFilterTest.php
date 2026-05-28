<?php
/**
 * Query filtering and sorting tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestRow;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for Query::query() filtering, ordering, pagination, and count mode.
 *
 * Five fixture rows are created once for the whole class:
 *  - Alpha Widget    | active   | priority 10
 *  - Beta Widget     | active   | priority 20
 *  - Gamma Gadget    | inactive | priority 30
 *  - Delta Gadget    | inactive | priority 40
 *  - Epsilon Widget  | pending  | priority 50
 *
 * BerlinDB's parse_query_var() accepts comma-separated strings for __in and
 * __not_in filters — PHP arrays must not be passed directly, as BerlinDB
 * wraps them in another array, taking the single-value code path.
 *
 * @since 2.1.0
 */
class QueryFilterTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/** @var int[] IDs of the five fixture rows, refreshed in setUp(). */
	private $ids = array();

	/**
	 * Install the fixture table and query object before filter tests run.
	 *
	 * @since 2.1.0
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
	 * Uninstall the fixture table after filter tests complete.
	 *
	 * @since 2.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset filter fixture data before each test.
	 *
	 * @since 2.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		/*
		 * parent::setUp() resets the current user to 0 via clean_up_global_scope().
		 * Re-set here so add_item() passes Query::reduce_item() capability checks.
		 */
		wp_set_current_user( 1 );

		self::$table->delete_all();
		wp_cache_flush();

		// Insert fresh fixture rows for every test so IDs are always valid.
		$this->ids[] = self::$query->add_item(
			array(
				'name'     => 'Alpha Widget',
				'status'   => 'active',
				'priority' => 10,
			)
		);
		$this->ids[] = self::$query->add_item(
			array(
				'name'     => 'Beta Widget',
				'status'   => 'active',
				'priority' => 20,
			)
		);
		$this->ids[] = self::$query->add_item(
			array(
				'name'     => 'Gamma Gadget',
				'status'   => 'inactive',
				'priority' => 30,
			)
		);
		$this->ids[] = self::$query->add_item(
			array(
				'name'     => 'Delta Gadget',
				'status'   => 'inactive',
				'priority' => 40,
			)
		);
		$this->ids[] = self::$query->add_item(
			array(
				'name'     => 'Epsilon Widget',
				'status'   => 'pending',
				'priority' => 50,
			)
		);

		wp_cache_flush();
	}

	// Default query.

	/**
	 * Test that a query with number set to zero returns all items.
	 *
	 * @since 2.1.0
	 */
	public function test_query_returns_all_items_with_unlimited_number() {
		$items = self::$query->query( array( 'number' => 0 ) );
		$this->assertCount( 5, $items );
	}

	/**
	 * Test that query results are TestRow instances.
	 *
	 * @since 2.1.0
	 */
	public function test_query_returns_test_row_instances() {
		$items = self::$query->query( array( 'number' => 1 ) );
		$this->assertInstanceOf( TestRow::class, $items[0] );
	}

	// Status filtering.

	/**
	 * Test that filtering by a single status value returns the correct item count.
	 *
	 * @since 2.1.0
	 */
	public function test_filter_by_status_single_value_returns_correct_count() {
		$items = self::$query->query(
			array(
				'number' => 0,
				'status' => 'active',
			)
		);
		$this->assertCount( 2, $items );
	}

	/**
	 * Test that filtering by a single status value returns only items with that status.
	 *
	 * @since 2.1.0
	 */
	public function test_filter_by_status_single_value_returns_only_matching_items() {
		$items = self::$query->query(
			array(
				'number' => 0,
				'status' => 'active',
			)
		);
		foreach ( $items as $item ) {
			$this->assertSame( 'active', $item->status );
		}
	}

	/**
	 * Test that filtering by status__in with multiple values returns the correct item count.
	 *
	 * @since 2.1.0
	 */
	public function test_filter_by_status_in_returns_correct_count() {
		// BerlinDB parse_query_var expects comma-separated strings, not PHP arrays.
		$items = self::$query->query(
			array(
				'number'     => 0,
				'status__in' => 'active, pending',
			)
		);
		$this->assertCount( 3, $items );
	}

	/**
	 * Test that filtering by status__not_in excludes items with the inactive status.
	 *
	 * @since 2.1.0
	 */
	public function test_filter_by_status_not_in_excludes_inactive() {
		$items = self::$query->query(
			array(
				'number'         => 0,
				'status__not_in' => 'inactive',
			)
		);
		$this->assertCount( 3, $items );
	}

	/**
	 * Test that filtering by status__not_in ensures none of the returned items match the excluded status.
	 *
	 * @since 2.1.0
	 */
	public function test_filter_by_status_not_in_excludes_matching_items() {
		$items = self::$query->query(
			array(
				'number'         => 0,
				'status__not_in' => 'inactive',
			)
		);
		foreach ( $items as $item ) {
			$this->assertNotSame( 'inactive', $item->status );
		}
	}

	// Priority filtering.

	/**
	 * Test that filtering by priority__in with multiple values returns the correct item count.
	 *
	 * @since 2.1.0
	 */
	public function test_filter_by_priority_in_returns_correct_count() {
		$items = self::$query->query(
			array(
				'number'       => 0,
				'priority__in' => '10, 30, 50',
			)
		);
		$this->assertCount( 3, $items );
	}

	// ID filtering.

	/**
	 * Test that filtering by id__in returns only the items with the specified IDs.
	 *
	 * @since 2.1.0
	 */
	public function test_filter_by_id_in_returns_matching_items() {
		$id_string = implode( ', ', array( $this->ids[0], $this->ids[1] ) );
		$items     = self::$query->query(
			array(
				'number' => 0,
				'id__in' => $id_string,
			)
		);
		$this->assertCount( 2, $items );
	}

	/**
	 * Test that filtering by id__not_in excludes the specified item from the results.
	 *
	 * @since 2.1.0
	 */
	public function test_filter_by_id_not_in_excludes_one_item() {
		$items = self::$query->query(
			array(
				'number'     => 0,
				'id__not_in' => (string) $this->ids[0],
			)
		);
		$this->assertCount( 4, $items );
	}

	// Search.

	/**
	 * Test that a search for "Widget" returns three matching items.
	 *
	 * @since 2.1.0
	 */
	public function test_search_by_widget_returns_three_items() {
		$items = self::$query->query(
			array(
				'number' => 0,
				'search' => 'Widget',
			)
		);
		$this->assertCount( 3, $items );
	}

	/**
	 * Test that a search for "Gadget" returns two matching items.
	 *
	 * @since 2.1.0
	 */
	public function test_search_by_gadget_returns_two_items() {
		$items = self::$query->query(
			array(
				'number' => 0,
				'search' => 'Gadget',
			)
		);
		$this->assertCount( 2, $items );
	}

	// Ordering.

	/**
	 * Test that ordering by name ascending returns the alphabetically first item at index zero.
	 *
	 * @since 2.1.0
	 */
	public function test_orderby_name_asc_returns_alpha_first() {
		$items = self::$query->query(
			array(
				'number'  => 0,
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$this->assertSame( 'Alpha Widget', $items[0]->name );
	}

	/**
	 * Test that ordering by name descending returns Gamma Gadget at index zero.
	 *
	 * @since 2.1.0
	 */
	public function test_orderby_name_desc_returns_gamma_first() {
		$items = self::$query->query(
			array(
				'number'  => 0,
				'orderby' => 'name',
				'order'   => 'DESC',
			)
		);
		$this->assertSame( 'Gamma Gadget', $items[0]->name );
	}

	/**
	 * Test that ordering by priority descending returns the highest-priority item first.
	 *
	 * @since 2.1.0
	 */
	public function test_orderby_priority_desc_returns_highest_first() {
		$items = self::$query->query(
			array(
				'number'  => 1,
				'orderby' => 'priority',
				'order'   => 'DESC',
			)
		);
		$this->assertSame( 50, (int) $items[0]->priority );
	}

	/**
	 * Test that ordering by priority ascending returns the lowest-priority item first.
	 *
	 * @since 2.1.0
	 */
	public function test_orderby_priority_asc_returns_lowest_first() {
		$items = self::$query->query(
			array(
				'number'  => 1,
				'orderby' => 'priority',
				'order'   => 'ASC',
			)
		);
		$this->assertSame( 10, (int) $items[0]->priority );
	}

	/**
	 * Test that an empty orderby string falls back to the primary column.
	 *
	 * parse_orderby() passes '' to parse_single_orderby(), which immediately
	 * falls back to get_primary_column_name() (= 'id') when orderby is empty.
	 * The query must still return results rather than producing broken SQL.
	 *
	 * @since 3.0.0
	 */
	public function test_orderby_empty_string_falls_back_to_primary_column() {
		$items = self::$query->query(
			array(
				'number'  => 0,
				'orderby' => '',
			)
		);
		$this->assertNotEmpty( $items );

		// Results must be in ascending ID order (default when falling back to primary).
		$ids = array_column( (array) $items, 'id' );
		$this->assertSame( $ids, array_values( $ids ) );
	}

	// Pagination.

	/**
	 * Test that the number argument limits the number of items returned.
	 *
	 * @since 2.1.0
	 */
	public function test_number_limits_result_count() {
		$items = self::$query->query( array( 'number' => 2 ) );
		$this->assertCount( 2, $items );
	}

	/**
	 * Test that the offset argument skips the correct number of items between pages.
	 *
	 * @since 2.1.0
	 */
	public function test_offset_skips_items() {
		$first_page  = self::$query->query(
			array(
				'number'  => 2,
				'offset'  => 0,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		$second_page = self::$query->query(
			array(
				'number'  => 2,
				'offset'  => 2,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$this->assertCount( 2, $first_page );
		$this->assertCount( 2, $second_page );
		$this->assertNotSame( $first_page[0]->id, $second_page[0]->id );
	}

	// Count mode.

	/**
	 * Test that a count query returns the total number of rows in the table.
	 *
	 * @since 2.1.0
	 */
	public function test_count_query_returns_total_row_count() {
		$count = self::$query->query( array( 'count' => true ) );
		$this->assertSame( 5, (int) $count );
	}

	/**
	 * Test that a count query combined with a status filter returns the correct count.
	 *
	 * @since 2.1.0
	 */
	public function test_count_query_with_status_filter_returns_correct_count() {
		$count = self::$query->query(
			array(
				'count'  => true,
				'status' => 'active',
			)
		);
		$this->assertSame( 2, (int) $count );
	}

	/**
	 * Test that a count query combined with a not_in filter returns the correct count.
	 *
	 * @since 2.1.0
	 */
	public function test_count_query_with_not_in_filter() {
		$count = self::$query->query(
			array(
				'count'          => true,
				'status__not_in' => 'inactive',
			)
		);
		$this->assertSame( 3, (int) $count );
	}

	// Fields mode.

	/**
	 * Test that querying with fields set to "ids" returns an array of integer values.
	 *
	 * @since 2.1.0
	 */
	public function test_fields_ids_returns_array_of_integers() {
		$ids = self::$query->query(
			array(
				'number' => 0,
				'fields' => 'ids',
			)
		);
		$this->assertIsArray( $ids );
		foreach ( $ids as $id ) {
			$this->assertIsInt( (int) $id );
		}
	}

	/**
	 * Test that querying with fields set to "ids" returns IDs for all items.
	 *
	 * @since 2.1.0
	 */
	public function test_fields_ids_returns_all_item_ids() {
		$ids = self::$query->query(
			array(
				'number' => 0,
				'fields' => 'ids',
			)
		);
		$this->assertCount( 5, $ids );
	}

	/**
	 * Test that querying specific fields returns stdClass objects with only those fields.
	 *
	 * @since 3.0.0
	 */
	public function test_fields_array_returns_stdclass_objects_with_requested_fields() {
		$items = self::$query->query(
			array(
				'number'  => 0,
				'fields'  => array( 'id', 'name' ),
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$item = array_values( $items )[0];

		$this->assertInstanceOf( \stdClass::class, $item );
		$this->assertSame( $this->ids[0], (int) $item->id );
		$this->assertSame( 'Alpha Widget', $item->name );
		$this->assertFalse( property_exists( $item, 'status' ) );
		$this->assertFalse( property_exists( $item, 'priority' ) );
	}

	/**
	 * Test that field selection can omit the primary field from returned objects.
	 *
	 * @since 3.0.0
	 */
	public function test_fields_array_can_omit_primary_field_from_objects() {
		$items = self::$query->query(
			array(
				'number'  => 0,
				'fields'  => array( 'name', 'status' ),
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$item = array_values( $items )[0];

		$this->assertInstanceOf( \stdClass::class, $item );
		$this->assertSame( 'Alpha Widget', $item->name );
		$this->assertSame( 'active', $item->status );
		$this->assertFalse( property_exists( $item, 'id' ) );
		$this->assertFalse( property_exists( $item, 'priority' ) );
	}

	/**
	 * Test that cached query IDs can be reshaped for different field selections.
	 *
	 * @since 3.0.0
	 */
	public function test_cached_query_ids_are_reshaped_for_each_fields_request() {
		$args = array(
			'number'  => 1,
			'orderby' => 'id',
			'order'   => 'ASC',
		);

		$name_items   = self::$query->query(
			array_merge(
				$args,
				array(
					'fields' => array( 'id', 'name' ),
				)
			)
		);
		$status_items = self::$query->query(
			array_merge(
				$args,
				array(
					'fields' => array( 'id', 'status' ),
				)
			)
		);

		$name_item   = array_values( $name_items )[0];
		$status_item = array_values( $status_items )[0];

		$this->assertSame( $this->ids[0], (int) $name_item->id );
		$this->assertSame( 'Alpha Widget', $name_item->name );
		$this->assertFalse( property_exists( $name_item, 'status' ) );

		$this->assertSame( $this->ids[0], (int) $status_item->id );
		$this->assertSame( 'active', $status_item->status );
		$this->assertFalse( property_exists( $status_item, 'name' ) );
	}

	/**
	 * Test that unknown fields are ignored when selecting specific fields.
	 *
	 * @since 3.0.0
	 */
	public function test_fields_array_ignores_unknown_fields() {
		$items = self::$query->query(
			array(
				'number'  => 1,
				'fields'  => array( 'id', 'bogus_field' ),
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$item = array_values( $items )[0];

		$this->assertInstanceOf( \stdClass::class, $item );
		$this->assertSame( $this->ids[0], (int) $item->id );
		$this->assertFalse( property_exists( $item, 'bogus_field' ) );
		$this->assertFalse( property_exists( $item, 'name' ) );
	}

	/**
	 * Test that empty fields behaves like a normal full-row query.
	 *
	 * @since 3.0.0
	 */
	public function test_empty_fields_array_returns_full_row_objects() {
		$items = self::$query->query(
			array(
				'number'  => 1,
				'fields'  => array(),
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$item = $items[0];

		$this->assertInstanceOf( TestRow::class, $item );
		$this->assertSame( $this->ids[0], (int) $item->id );
		$this->assertSame( 'Alpha Widget', $item->name );
		$this->assertSame( 'active', $item->status );
	}

	/**
	 * Test that a scalar field name returns objects with only that field.
	 *
	 * @since 3.0.0
	 */
	public function test_scalar_field_returns_objects_with_only_that_field() {
		$items = self::$query->query(
			array(
				'number'  => 1,
				'fields'  => 'name',
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$item = array_values( $items )[0];

		$this->assertInstanceOf( \stdClass::class, $item );
		$this->assertSame( 'Alpha Widget', $item->name );
		$this->assertFalse( property_exists( $item, 'id' ) );
		$this->assertFalse( property_exists( $item, 'status' ) );
	}

	// Found rows / pagination.

	/**
	 * Test that setting no_found_rows to false causes max_num_pages to be populated.
	 *
	 * @since 2.1.0
	 */
	public function test_no_found_rows_false_populates_max_num_pages() {
		self::$query->query(
			array(
				'number'        => 2,
				'no_found_rows' => false,
			)
		);

		$this->assertGreaterThan( 1, self::$query->get_max_num_pages() );
	}
}
