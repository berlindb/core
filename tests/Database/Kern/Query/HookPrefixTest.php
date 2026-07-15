<?php
/**
 * Hook-prefix isolation tests.
 *
 * Verifies that $hook_prefix namespaces a Query's hook/filter NAMES independently
 * of $prefix (which drives table/cache resolution), so a Query registered over an
 * existing table can keep $prefix aligned to that table while firing distinctly
 * namespaced hooks. See berlindb/core#242.
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
 * A Query whose hook prefix is set distinctly from its (table) prefix.
 *
 * Shares TestQuery's table ($prefix + $table_name are inherited), so it reads and
 * writes the same test_widgets rows, but its hooks are namespaced under 'acme'.
 *
 * @since 3.1.0
 */
class HookPrefixQuery extends TestQuery {

	/** @var string */
	protected $hook_prefix = 'acme';
}

/**
 * Tests that $hook_prefix drives hook/filter names without touching table resolution.
 *
 * @since 3.1.0
 */
class HookPrefixTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var HookPrefixQuery */
	private static $query;

	/**
	 * Install the shared fixture table before hook tests run.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new HookPrefixQuery();
	}

	/**
	 * Uninstall the fixture table after hook tests complete.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset table state before each test.
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
	 * Remove hooks registered by these tests (both the namespaced and default names).
	 *
	 * @since 3.1.0
	 */
	public function tearDown(): void {
		foreach (
			array(
				'acme_pre_get_widgets',
				'acme_parse_widgets_query',
				'acme_widget_deleted',
				'acme_transition_widget_status',
				'berlindb_database_pre_get_widgets',
			) as $hook
		) {
			remove_all_actions( $hook );
		}

		remove_all_filters( 'acme_the_widgets' );

		parent::tearDown();
	}

	/**
	 * get_hook_prefix() returns the $hook_prefix property when it is set.
	 *
	 * @since 3.1.0
	 */
	public function test_get_hook_prefix_returns_property_when_set() {
		$this->assertSame( 'acme', self::$query->get_hook_prefix() );
	}

	/**
	 * get_hook_prefix() falls back to $prefix when $hook_prefix is unset, so
	 * existing objects (which set only $prefix) are unchanged.
	 *
	 * @since 3.1.0
	 */
	public function test_get_hook_prefix_falls_back_to_table_prefix_when_unset() {
		$default = new TestQuery();

		$this->assertSame( 'berlindb_database', $default->get_hook_prefix() );
	}

	/**
	 * The pre-get action fires under the hook prefix, not the table prefix.
	 *
	 * @since 3.1.0
	 */
	public function test_hook_prefix_namespaces_pre_get_action() {
		$namespaced = false;
		$default    = false;

		add_action(
			'acme_pre_get_widgets',
			function () use ( &$namespaced ) {
				$namespaced = true;
			}
		);
		add_action(
			'berlindb_database_pre_get_widgets',
			function () use ( &$default ) {
				$default = true;
			}
		);

		self::$query->query( array( 'number' => 0 ) );

		$this->assertTrue( $namespaced, 'acme_pre_get_widgets should fire.' );
		$this->assertFalse( $default, 'berlindb_database_pre_get_widgets should NOT fire.' );
	}

	/**
	 * The parse-query action fires under the hook prefix.
	 *
	 * @since 3.1.0
	 */
	public function test_hook_prefix_namespaces_parse_query_action() {
		$received = null;

		add_action(
			'acme_parse_widgets_query',
			function ( $query ) use ( &$received ) {
				$received = $query;
			}
		);

		self::$query->query( array( 'number' => 0 ) );

		$this->assertSame( self::$query, $received );
	}

	/**
	 * The `the_{plural}` results filter fires under the hook prefix.
	 *
	 * @since 3.1.0
	 */
	public function test_hook_prefix_namespaces_the_items_filter() {
		$ran = false;

		add_filter(
			'acme_the_widgets',
			function ( $items ) use ( &$ran ) {
				$ran = true;
				return $items;
			}
		);

		self::$query->add_item( array( 'status' => 'active' ) );
		self::$query->query( array( 'number' => 10 ) );

		$this->assertTrue( $ran, 'acme_the_widgets should fire.' );
	}

	/**
	 * The delete action fires under the hook prefix with the item ID and result.
	 *
	 * @since 3.1.0
	 */
	public function test_hook_prefix_namespaces_deleted_action() {
		$item_id  = self::$query->add_item( array( 'status' => 'active' ) );
		$received = array();

		add_action(
			'acme_widget_deleted',
			function ( $deleted_id, $result ) use ( &$received ) {
				$received = array( $deleted_id, $result );
			},
			10,
			2
		);

		self::$query->delete_item( $item_id );

		$this->assertSame( array( $item_id, true ), $received );
	}

	/**
	 * The transition action fires under the hook prefix when a column changes.
	 *
	 * @since 3.1.0
	 */
	public function test_hook_prefix_namespaces_transition_action() {
		$fired = false;

		add_action(
			'acme_transition_widget_status',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		self::$query->add_item( array( 'status' => 'active' ) );

		$this->assertTrue( $fired, 'acme_transition_widget_status should fire.' );
	}

	/**
	 * A Query that sets only $prefix (no $hook_prefix) fires the table-prefixed
	 * hooks exactly as before - the fallback is backward-compatible.
	 *
	 * @since 3.1.0
	 */
	public function test_default_query_hooks_are_unchanged() {
		$default = new TestQuery();
		$fired   = false;

		add_action(
			'berlindb_database_pre_get_widgets',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$default->query( array( 'number' => 0 ) );

		$this->assertTrue( $fired, 'Default Query must still fire berlindb_database_pre_get_widgets.' );
	}
}
