<?php
/**
 * Query hook smoke tests.
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
 * Tests for existing Query actions.
 *
 * @since 3.0.0
 */
class QueryHooksTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/**
	 * Install the fixture table before hook tests run.
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
	 * Uninstall the fixture table after hook tests complete.
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
	 * Remove hooks registered by these smoke tests.
	 *
	 * @since 3.0.0
	 */
	public function tearDown(): void {
		remove_all_actions( 'berlindb_database_parse_widgets_query' );
		remove_all_actions( 'berlindb_database_pre_get_widgets' );
		remove_all_actions( 'berlindb_database_widget_deleted' );

		parent::tearDown();
	}

	/**
	 * The parse query action fires with the current query instance.
	 *
	 * @since 3.0.0
	 */
	public function test_parse_query_action_fires_with_query_instance() {
		$received = null;

		add_action(
			'berlindb_database_parse_widgets_query',
			function ( $query ) use ( &$received ) {
				$received = $query;
			}
		);

		self::$query->query( array( 'number' => 0 ) );

		$this->assertSame( self::$query, $received );
	}

	/**
	 * The pre-get action fires before items are fetched.
	 *
	 * @since 3.0.0
	 */
	public function test_pre_get_action_fires_with_query_instance() {
		$received = null;

		add_action(
			'berlindb_database_pre_get_widgets',
			function ( $query ) use ( &$received ) {
				$received = $query;
			}
		);

		self::$query->query( array( 'number' => 0 ) );

		$this->assertSame( self::$query, $received );
	}

	/**
	 * The delete action fires with the deleted item ID and database result.
	 *
	 * @since 3.0.0
	 */
	public function test_deleted_action_fires_with_item_id_and_result() {
		$item_id  = self::$query->add_item( array( 'status' => 'active' ) );
		$received = array();

		add_action(
			'berlindb_database_widget_deleted',
			function ( $deleted_id, $result ) use ( &$received ) {
				$received = array( $deleted_id, $result );
			},
			10,
			2
		);

		self::$query->delete_item( $item_id );

		$this->assertSame( array( $item_id, true ), $received );
	}
}
