<?php
/**
 * Query CRUD operation tests.
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
 * Tests for Query CRUD methods: add_item, get_item, get_item_by,
 * update_item, delete_item, copy_item.
 *
 * wp_set_current_user(1) is required because Query::reduce_item() strips
 * columns where current_user_can( $caps[$method] ) returns false. The
 * default cap is 'exist', which requires a logged-in user.
 *
 * @since 2.1.0
 */
class QueryCrudTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/**
	 * Install the fixture table and query object before CRUD tests run.
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
	 * Uninstall the fixture table after CRUD tests complete.
	 *
	 * @since 2.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset CRUD fixture data before each test.
	 *
	 * @since 2.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		/*
		 * parent::setUp() resets the current user to 0 via clean_up_global_scope().
		 * Re-set here so Query::reduce_item() passes capability checks.
		 */
		wp_set_current_user( 1 );

		self::$table->delete_all();
		wp_cache_flush();
	}

	// add_item().

	/**
	 * Test that add_item returns a positive integer ID on success.
	 *
	 * @since 2.1.0
	 */
	public function test_add_item_returns_positive_integer_id() {
		$id = self::$query->add_item(
			array(
				'name'   => 'Widget A',
				'status' => 'active',
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test that add_item with an empty array succeeds because BerlinDB auto-fills required fields.
	 *
	 * @since 2.1.0
	 */
	public function test_add_item_with_empty_array_returns_id_via_autofill() {
		/*
		 * BerlinDB auto-fills uuid, date_created, and date_modified even when
		 * no explicit data is provided, so the insert succeeds.
		 */
		$result = self::$query->add_item( array() );
		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	/**
	 * Test that add_item automatically populates the date_created field.
	 *
	 * @since 2.1.0
	 */
	public function test_add_item_sets_date_created_automatically() {
		$id   = self::$query->add_item( array( 'name' => 'Widget A' ) );
		$item = self::$query->get_item( $id );
		$this->assertNotEmpty( $item->date_created );
		$this->assertNotSame( '0000-00-00 00:00:00', $item->date_created );
	}

	/**
	 * Test that add_item automatically populates the date_modified field.
	 *
	 * @since 2.1.0
	 */
	public function test_add_item_sets_date_modified_automatically() {
		$id   = self::$query->add_item( array( 'name' => 'Widget A' ) );
		$item = self::$query->get_item( $id );
		$this->assertNotEmpty( $item->date_modified );
		$this->assertNotSame( '0000-00-00 00:00:00', $item->date_modified );
	}

	/**
	 * Test that add_item automatically generates and stores a UUID for the new row.
	 *
	 * @since 2.1.0
	 */
	public function test_add_item_sets_uuid_automatically() {
		$id   = self::$query->add_item( array( 'name' => 'Widget A' ) );
		$item = self::$query->get_item( $id );
		$this->assertStringStartsWith( 'urn:uuid:', $item->uuid );
	}

	/**
	 * Test that add_item ignores unknown keys while saving valid columns.
	 *
	 * @since 3.0.0
	 */
	public function test_add_item_ignores_unknown_keys() {
		$id = self::$query->add_item(
			array(
				'name'                    => 'Widget With Extra Data',
				'status'                  => 'active',
				'definitely_not_a_column' => 'ignored',
			)
		);

		$item = self::$query->get_item( $id );

		$this->assertSame( 'Widget With Extra Data', $item->name );
		$this->assertSame( 'active', $item->status );
		$this->assertFalse( property_exists( $item, 'definitely_not_a_column' ) );
	}

	// get_item().

	/**
	 * Test that get_item returns a TestRow instance for a valid ID.
	 *
	 * @since 2.1.0
	 */
	public function test_get_item_returns_test_row_instance() {
		$id   = self::$query->add_item( array( 'name' => 'Widget A' ) );
		$item = self::$query->get_item( $id );
		$this->assertInstanceOf( TestRow::class, $item );
	}

	/**
	 * Test that get_item returns the row with the correct name value.
	 *
	 * @since 2.1.0
	 */
	public function test_get_item_returns_correct_name() {
		$id   = self::$query->add_item( array( 'name' => 'Widget Unique' ) );
		$item = self::$query->get_item( $id );
		$this->assertSame( 'Widget Unique', $item->name );
	}

	/**
	 * Test that get_item returns the row with the correct status value.
	 *
	 * @since 2.1.0
	 */
	public function test_get_item_returns_correct_status() {
		$id   = self::$query->add_item(
			array(
				'name'   => 'Widget A',
				'status' => 'inactive',
			)
		);
		$item = self::$query->get_item( $id );
		$this->assertSame( 'inactive', $item->status );
	}

	/**
	 * Test that get_item returns false for an ID that does not exist.
	 *
	 * @since 2.1.0
	 */
	public function test_get_item_returns_false_for_nonexistent_id() {
		$result = self::$query->get_item( 999999 );
		$this->assertFalse( $result );
	}

	// get_item_by().

	/**
	 * Test that get_item_by returns a row when querying by an existing status value.
	 *
	 * @since 2.1.0
	 */
	public function test_get_item_by_returns_row_for_existing_status() {
		self::$query->add_item(
			array(
				'name'   => 'Widget A',
				'status' => 'pending',
			)
		);
		$item = self::$query->get_item_by( 'status', 'pending' );
		$this->assertInstanceOf( TestRow::class, $item );
	}

	/**
	 * Test that get_item_by returns the correct item when querying by a unique name value.
	 *
	 * @since 2.1.0
	 */
	public function test_get_item_by_returns_correct_item() {
		$id   = self::$query->add_item(
			array(
				'name'   => 'Needle Widget',
				'status' => 'active',
			)
		);
		$item = self::$query->get_item_by( 'name', 'Needle Widget' );
		$this->assertSame( $id, (int) $item->id );
	}

	/**
	 * Test that get_item_by returns false when the field value does not exist.
	 *
	 * @since 2.1.0
	 */
	public function test_get_item_by_returns_false_for_nonexistent_value() {
		$result = self::$query->get_item_by( 'name', 'Absolutely Nonexistent XYZ' );
		$this->assertFalse( $result );
	}

	/**
	 * Test that get_item_by returns false when the column value is the string '0'.
	 *
	 * get_item_by() guards with empty($column_value), and empty('0') is true in
	 * PHP, so the lookup bails early and returns false regardless of table contents.
	 * This test documents that known behaviour so a future refactor of the guard
	 * can verify the change intentionally.
	 *
	 * @since 3.0.0
	 */
	public function test_get_item_by_returns_false_for_string_zero_value() {
		$this->assertFalse( self::$query->get_item_by( 'status', '0' ) );
	}

	/**
	 * Test that get_item_by returns false when the column value is integer 0.
	 *
	 * Same empty() guard as the string '0' case — integer 0 is also considered
	 * empty, so the method returns false before reaching the database.
	 *
	 * @since 3.0.0
	 */
	public function test_get_item_by_returns_false_for_integer_zero_value() {
		$this->assertFalse( self::$query->get_item_by( 'priority', 0 ) );
	}

	// update_item().

	/**
	 * Test that update_item successfully modifies the name of an existing item.
	 *
	 * @since 2.1.0
	 */
	public function test_update_item_modifies_name() {
		$id = self::$query->add_item( array( 'name' => 'Original' ) );
		self::$query->update_item( $id, array( 'name' => 'Updated' ) );

		wp_cache_flush();
		$item = self::$query->get_item( $id );
		$this->assertSame( 'Updated', $item->name );
	}

	/**
	 * Test that update_item successfully modifies the status of an existing item.
	 *
	 * @since 2.1.0
	 */
	public function test_update_item_modifies_status() {
		$id = self::$query->add_item(
			array(
				'name'   => 'Widget A',
				'status' => 'active',
			)
		);
		self::$query->update_item( $id, array( 'status' => 'inactive' ) );

		wp_cache_flush();
		$item = self::$query->get_item( $id );
		$this->assertSame( 'inactive', $item->status );
	}

	/**
	 * Test that update_item ignores unknown keys while saving valid columns.
	 *
	 * @since 3.0.0
	 */
	public function test_update_item_ignores_unknown_keys() {
		$id = self::$query->add_item(
			array(
				'name'   => 'Widget A',
				'status' => 'active',
			)
		);

		self::$query->update_item(
			$id,
			array(
				'name'                    => 'Updated Widget',
				'definitely_not_a_column' => 'ignored',
			)
		);

		wp_cache_flush();
		$item = self::$query->get_item( $id );

		$this->assertSame( 'Updated Widget', $item->name );
		$this->assertSame( 'active', $item->status );
		$this->assertFalse( property_exists( $item, 'definitely_not_a_column' ) );
	}

	/**
	 * Test that update_item returns false when the specified ID does not exist.
	 *
	 * @since 2.1.0
	 */
	public function test_update_item_returns_false_for_nonexistent_id() {
		$result = self::$query->update_item( 999999, array( 'name' => 'Ghost' ) );
		$this->assertFalse( $result );
	}

	/**
	 * Test that update_item returns false when called with an empty data array.
	 *
	 * @since 2.1.0
	 */
	public function test_update_item_returns_false_for_empty_data() {
		$id     = self::$query->add_item( array( 'name' => 'Widget A' ) );
		$result = self::$query->update_item( $id, array() );
		$this->assertFalse( $result );
	}

	/**
	 * Test that update_item returns false when only unknown keys are provided.
	 *
	 * @since 3.0.0
	 */
	public function test_update_item_returns_false_for_only_unknown_keys() {
		$id     = self::$query->add_item( array( 'name' => 'Widget A' ) );
		$result = self::$query->update_item(
			$id,
			array(
				'definitely_not_a_column' => 'ignored',
			)
		);

		$this->assertFalse( $result );
	}

	// delete_item().

	/**
	 * Test that delete_item removes the row so it can no longer be retrieved.
	 *
	 * @since 2.1.0
	 */
	public function test_delete_item_removes_the_row() {
		$id = self::$query->add_item( array( 'name' => 'Doomed Widget' ) );
		self::$query->delete_item( $id );

		wp_cache_flush();
		$this->assertFalse( self::$query->get_item( $id ) );
	}

	/**
	 * Test that delete_item reduces the table row count to zero when the only row is deleted.
	 *
	 * @since 2.1.0
	 */
	public function test_delete_item_reduces_count_to_zero() {
		$id = self::$query->add_item( array( 'name' => 'Only Widget' ) );
		self::$query->delete_item( $id );

		$this->assertSame( 0, self::$table->count() );
	}

	/**
	 * Test that delete_item returns false when the specified ID does not exist.
	 *
	 * @since 2.1.0
	 */
	public function test_delete_item_returns_false_for_nonexistent_id() {
		$result = self::$query->delete_item( 999999 );
		$this->assertFalse( $result );
	}

	// copy_item().

	/**
	 * Test that copy_item creates a new row with a distinct ID.
	 *
	 * @since 2.1.0
	 */
	public function test_copy_item_creates_a_new_row() {
		$id     = self::$query->add_item( array( 'name' => 'Original Widget' ) );
		$new_id = self::$query->copy_item( $id );

		$this->assertIsInt( $new_id );
		$this->assertNotSame( $id, $new_id );
		$this->assertSame( 2, self::$table->count() );
	}

	/**
	 * Test that copy_item preserves the original item's name in the copied row by default.
	 *
	 * @since 2.1.0
	 */
	public function test_copy_item_preserves_name_by_default() {
		$id     = self::$query->add_item( array( 'name' => 'Original Widget' ) );
		$new_id = self::$query->copy_item( $id );

		wp_cache_flush();
		$copy = self::$query->get_item( $new_id );
		$this->assertSame( 'Original Widget', $copy->name );
	}

	/**
	 * Test that copy_item applies override data to the copied row when provided.
	 *
	 * @since 2.1.0
	 */
	public function test_copy_item_can_override_data() {
		$id     = self::$query->add_item(
			array(
				'name'   => 'Original Widget',
				'status' => 'active',
			)
		);
		$new_id = self::$query->copy_item( $id, array( 'status' => 'inactive' ) );

		wp_cache_flush();
		$copy = self::$query->get_item( $new_id );
		$this->assertSame( 'inactive', $copy->status );
	}

	/**
	 * Test that copy_item ignores unknown override keys while saving valid columns.
	 *
	 * @since 3.0.0
	 */
	public function test_copy_item_ignores_unknown_override_keys() {
		$id = self::$query->add_item(
			array(
				'name'   => 'Original Widget',
				'status' => 'active',
			)
		);

		$new_id = self::$query->copy_item(
			$id,
			array(
				'status'                  => 'inactive',
				'definitely_not_a_column' => 'ignored',
			)
		);

		wp_cache_flush();
		$copy = self::$query->get_item( $new_id );

		$this->assertSame( 'Original Widget', $copy->name );
		$this->assertSame( 'inactive', $copy->status );
		$this->assertFalse( property_exists( $copy, 'definitely_not_a_column' ) );
	}

	/**
	 * Test that copy_item generates a distinct UUID for the copied row.
	 *
	 * The original item's UUID must not be duplicated — each row needs its own
	 * globally unique identifier. Before the fix, copy_item() carried the UUID
	 * through to add_item(), which preserved it unchanged via validate_uuid().
	 *
	 * @since 3.0.0
	 */
	public function test_copy_item_generates_distinct_uuid() {
		$id = self::$query->add_item( array( 'name' => 'Original Widget' ) );

		$new_id = self::$query->copy_item( $id );

		wp_cache_flush();
		$original = self::$query->get_item( $id );
		$copy     = self::$query->get_item( $new_id );

		$this->assertNotEmpty( $original->uuid );
		$this->assertNotEmpty( $copy->uuid );
		$this->assertNotSame( $original->uuid, $copy->uuid );
	}
}
