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

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new TestQuery();
	}

	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

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

	public function test_get_item_uses_row_cast_override_for_priority() {
		$id   = self::$query->add_item( array( 'name' => 'Widget A', 'priority' => '42' ) );
		$item = self::$query->get_item( $id );

		$this->assertIsInt( $item->priority );
		$this->assertSame( 42, $item->priority );
	}

	public function test_settings_array_cast_round_trips_between_php_and_db() {
		global $wpdb;

		$settings = array(
			'color'   => 'blue',
			'enabled' => true,
		);

		$id = self::$query->add_item( array(
			'name'     => 'Widget With Settings',
			'settings' => $settings,
		) );

		$this->assertIsInt( $id );

		$table = $wpdb->berlindb_test_widgets;
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d", $id ) );

		$this->assertIsString( $raw );
		$this->assertJson( $raw );

		$item = self::$query->get_item( $id );
		$this->assertIsArray( $item->settings );
		$this->assertSame( 'blue', $item->settings['color'] );
		$this->assertTrue( $item->settings['enabled'] );
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
}
