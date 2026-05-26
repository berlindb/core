<?php
/**
 * Query transition hook tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for Query::transition_item() — the hook that fires when a column
 * declared with 'transition' => true changes value.
 *
 * TestSchema marks 'status' as a transition column, so the hook fired is:
 *   berlindb_database_transition_widget_status( $old_value, $new_value, $item_id )
 *
 * Two scenarios are covered:
 *  - add_item(): old_data is empty, so all old values are set to the string
 *    'new' (WordPress transition convention for newly created items).
 *  - update_item(): old_data is the row before the update; the hook fires only
 *    when the status value actually changes.
 *
 * @since 3.0.0
 */
class QueryTransitionTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/**
	 * Install the fixture table before transition tests run.
	 *
	 * @since 3.0.0
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
	 * Uninstall the fixture table after transition tests complete.
	 *
	 * @since 3.0.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset table state before each test.
	 *
	 * @since 3.0.0
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 1 );
		self::$table->delete_all();
		wp_cache_flush();
	}

	/**
	 * Test that add_item fires the transition hook with 'new' as the old value.
	 *
	 * When there is no prior row (old_data is empty), transition_item() sets
	 * every old value to the string 'new' — the WordPress convention for signalling
	 * "this column is transitioning from nothing to its initial value."
	 *
	 * @since 3.0.0
	 */
	public function test_transition_hook_fires_on_add_item_with_new_as_old_value() {
		$fired     = false;
		$old_value = null;
		$new_value = null;

		add_action(
			'berlindb_database_transition_widget_status',
			function ( $old, $new ) use ( &$fired, &$old_value, &$new_value ) {
				$fired     = true;
				$old_value = $old;
				$new_value = $new;
			},
			10,
			2
		);

		self::$query->add_item( array( 'status' => 'active' ) );

		$this->assertTrue( $fired, 'Transition hook did not fire on add_item.' );
		$this->assertSame( 'new', $old_value, 'Old value should be the string "new" for a newly created item.' );
		$this->assertSame( 'active', $new_value );
	}

	/**
	 * Test that update_item fires the transition hook when the status changes.
	 *
	 * @since 3.0.0
	 */
	public function test_transition_hook_fires_on_update_item_when_status_changes() {
		$id = self::$query->add_item( array( 'status' => 'active' ) );

		$fired     = false;
		$old_value = null;
		$new_value = null;

		add_action(
			'berlindb_database_transition_widget_status',
			function ( $old, $new ) use ( &$fired, &$old_value, &$new_value ) {
				$fired     = true;
				$old_value = $old;
				$new_value = $new;
			},
			10,
			2
		);

		self::$query->update_item( $id, array( 'status' => 'inactive' ) );

		$this->assertTrue( $fired, 'Transition hook did not fire when status changed.' );
		$this->assertSame( 'active', $old_value );
		$this->assertSame( 'inactive', $new_value );
	}

	/**
	 * Test that update_item does not fire the transition hook when the status is unchanged.
	 *
	 * transition_item() bails early when array_diff() finds no difference between
	 * old and new scalar values, so hooks must not fire for no-op updates.
	 *
	 * @since 3.0.0
	 */
	public function test_transition_hook_does_not_fire_when_status_unchanged() {
		$id = self::$query->add_item( array( 'status' => 'active' ) );

		$fired = false;

		add_action(
			'berlindb_database_transition_widget_status',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		self::$query->update_item( $id, array( 'status' => 'active' ) );

		$this->assertFalse( $fired, 'Transition hook must not fire when status value is unchanged.' );
	}
}
