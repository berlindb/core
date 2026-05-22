<?php
/**
 * In parser integration tests.
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
 * Tests for the In parser via __in query vars.
 *
 * Five fixture rows:
 *  - Alpha Widget    | active   | priority 10
 *  - Beta Widget     | active   | priority 20
 *  - Gamma Gadget    | inactive | priority 30
 *  - Delta Gadget    | inactive | priority 40
 *  - Epsilon Widget  | pending  | priority 50
 *
 * BerlinDB's parse_query_var() accepts comma-separated strings for __in
 * filters — PHP arrays must not be passed directly.
 *
 * @since 3.0.0
 */
class InParserTest extends TestCase {

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

		$this->ids[] = self::$query->add_item( array( 'name' => 'Alpha Widget',   'status' => 'active',   'priority' => 10 ) );
		$this->ids[] = self::$query->add_item( array( 'name' => 'Beta Widget',    'status' => 'active',   'priority' => 20 ) );
		$this->ids[] = self::$query->add_item( array( 'name' => 'Gamma Gadget',   'status' => 'inactive', 'priority' => 30 ) );
		$this->ids[] = self::$query->add_item( array( 'name' => 'Delta Gadget',   'status' => 'inactive', 'priority' => 40 ) );
		$this->ids[] = self::$query->add_item( array( 'name' => 'Epsilon Widget', 'status' => 'pending',  'priority' => 50 ) );

		wp_cache_flush();
	}

	/**
	 * Test that status__in with a single value returns matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_single_value_in_filter() {

		// Assert expected results.
		$results = self::$query->query( array( 'status__in' => 'pending' ) );
		$this->assertCount( 1, $results );
		$this->assertSame( 'Epsilon Widget', $results[0]->name );
	}

	/**
	 * Test that status__in with multiple values returns all matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_multiple_values_in_filter() {

		// Assert expected results.
		$results = self::$query->query( array( 'status__in' => 'active, pending' ) );
		$this->assertCount( 3, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
		$this->assertContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test that status__in with a value that matches no rows returns empty.
	 *
	 * @since 3.0.0
	 */
	public function test_no_match_returns_empty() {

		// Assert expected results.
		$results = self::$query->query( array( 'status__in' => 'archived' ) );
		$this->assertCount( 0, $results );
	}

	/**
	 * Test that priority__in filters by integer column values.
	 *
	 * @since 3.0.0
	 */
	public function test_integer_column_in_filter() {

		// Assert expected results.
		$results = self::$query->query( array( 'priority__in' => '10, 30, 50' ) );
		$this->assertCount( 3, $results );

		$priorities = wp_list_pluck( $results, 'priority' );
		$this->assertContains( '10', $priorities );
		$this->assertContains( '30', $priorities );
		$this->assertContains( '50', $priorities );
	}

	/**
	 * Test that combining __in on two columns narrows results correctly.
	 *
	 * @since 3.0.0
	 */
	public function test_combined_in_filters_narrow_results() {

		// Assert expected results.
		$results = self::$query->query( array(
			'status__in'   => 'active',
			'priority__in' => '20',
		) );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Beta Widget', $results[0]->name );
	}

	/**
	 * Test that __in count mode returns the correct count.
	 *
	 * @since 3.0.0
	 */
	public function test_in_filter_with_count_mode() {

		// Assert expected results.
		$count = self::$query->query( array(
			'status__in' => 'active',
			'count'      => true,
		) );

		$this->assertSame( 2, (int) $count );
	}
}
