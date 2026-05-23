<?php
/**
 * NotIn parser integration tests.
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
 * Tests for the NotIn parser via __not_in query vars.
 *
 * Five fixture rows:
 *  - Alpha Widget    | active   | priority 10
 *  - Beta Widget     | active   | priority 20
 *  - Gamma Gadget    | inactive | priority 30
 *  - Delta Gadget    | inactive | priority 40
 *  - Epsilon Widget  | pending  | priority 50
 *
 * BerlinDB's parse_query_var() accepts comma-separated strings for __not_in
 * filters — PHP arrays must not be passed directly.
 *
 * @since 3.0.0
 */
class NotInParserTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/** @var int[] IDs of the five fixture rows, refreshed in setUp(). */
	private $ids = array();

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

		wp_set_current_user( 1 );

		self::$table->delete_all();
		wp_cache_flush();

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

	/**
	 * Test that status__not_in with a single value excludes matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_single_value_not_in_filter() {

		// Assert expected results.
		$results = self::$query->query( array( 'status__not_in' => 'pending' ) );
		$this->assertCount( 4, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertNotContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test that status__not_in with multiple values excludes all matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_multiple_values_not_in_filter() {

		// Assert expected results.
		$results = self::$query->query( array( 'status__not_in' => 'active, pending' ) );
		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
	}

	/**
	 * Test that status__not_in with a value matching no rows returns all rows.
	 *
	 * @since 3.0.0
	 */
	public function test_no_match_returns_all_rows() {

		// Assert expected results.
		$results = self::$query->query( array( 'status__not_in' => 'archived' ) );
		$this->assertCount( 5, $results );
	}

	/**
	 * Test that priority__not_in excludes by integer column values.
	 *
	 * @since 3.0.0
	 */
	public function test_integer_column_not_in_filter() {

		// Assert expected results.
		$results = self::$query->query( array( 'priority__not_in' => '10, 20, 30, 40' ) );
		$this->assertCount( 1, $results );
		$this->assertSame( 'Epsilon Widget', $results[0]->name );
	}

	/**
	 * Test that __not_in excluding all rows returns empty.
	 *
	 * @since 3.0.0
	 */
	public function test_not_in_excluding_all_rows_returns_empty() {

		// Assert expected results.
		$results = self::$query->query( array( 'status__not_in' => 'active, inactive, pending' ) );
		$this->assertCount( 0, $results );
	}

	/**
	 * Test that __not_in count mode returns the correct count.
	 *
	 * @since 3.0.0
	 */
	public function test_not_in_filter_with_count_mode() {

		// Assert expected results.
		$count = self::$query->query(
			array(
				'status__not_in' => 'inactive',
				'count'          => true,
			)
		);

		$this->assertSame( 3, (int) $count );
	}
}
