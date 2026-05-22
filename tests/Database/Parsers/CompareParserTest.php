<?php
/**
 * Compare parser integration tests.
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
 * Tests for the Compare parser via the 'compare_query' query var.
 *
 * Five fixture rows:
 *  - Alpha Widget    | active   | priority 10
 *  - Beta Widget     | active   | priority 20
 *  - Gamma Gadget    | inactive | priority 30
 *  - Delta Gadget    | inactive | priority 40
 *  - Epsilon Widget  | pending  | priority 50
 *
 * The compare_query var accepts a single clause array with 'key', 'value',
 * and an optional 'compare' operator (default '=').
 *
 * @since 3.0.0
 */
class CompareParserTest extends TestCase {

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

		wp_set_current_user( 1 );

		self::$table->delete_all();
		wp_cache_flush();

		self::$query->add_item( array( 'name' => 'Alpha Widget',   'status' => 'active',   'priority' => 10 ) );
		self::$query->add_item( array( 'name' => 'Beta Widget',    'status' => 'active',   'priority' => 20 ) );
		self::$query->add_item( array( 'name' => 'Gamma Gadget',   'status' => 'inactive', 'priority' => 30 ) );
		self::$query->add_item( array( 'name' => 'Delta Gadget',   'status' => 'inactive', 'priority' => 40 ) );
		self::$query->add_item( array( 'name' => 'Epsilon Widget', 'status' => 'pending',  'priority' => 50 ) );

		wp_cache_flush();
	}

	/**
	 * Test that an equality comparison returns matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_equals_comparison() {

		// Assert expected results.
		$results = self::$query->query( array(
			'compare_query' => array(
				'key'     => 'status',
				'value'   => 'active',
				'compare' => '=',
			),
		) );

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
	}

	/**
	 * Test that a not-equals comparison excludes matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_not_equals_comparison() {

		// Assert expected results.
		$results = self::$query->query( array(
			'compare_query' => array(
				'key'     => 'status',
				'value'   => 'active',
				'compare' => '!=',
			),
		) );

		$this->assertCount( 3, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertNotContains( 'Alpha Widget', $names );
		$this->assertNotContains( 'Beta Widget', $names );
	}

	/**
	 * Test that a greater-than comparison returns rows above the threshold.
	 *
	 * @since 3.0.0
	 */
	public function test_greater_than_comparison() {

		// Assert expected results.
		$results = self::$query->query( array(
			'compare_query' => array(
				'key'     => 'priority',
				'value'   => 30,
				'compare' => '>',
			),
		) );

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Delta Gadget', $names );
		$this->assertContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test that a less-than-or-equal comparison returns rows at or below the threshold.
	 *
	 * @since 3.0.0
	 */
	public function test_less_than_or_equal_comparison() {

		// Assert expected results.
		$results = self::$query->query( array(
			'compare_query' => array(
				'key'     => 'priority',
				'value'   => 20,
				'compare' => '<=',
			),
		) );

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
	}

	/**
	 * Test that a LIKE comparison performs substring matching.
	 *
	 * @since 3.0.0
	 */
	public function test_like_comparison() {

		// Assert expected results.
		$results = self::$query->query( array(
			'compare_query' => array(
				'key'     => 'name',
				'value'   => 'Gadget',
				'compare' => 'LIKE',
			),
		) );

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
	}

	/**
	 * Test that a NOT LIKE comparison excludes substring matches.
	 *
	 * @since 3.0.0
	 */
	public function test_not_like_comparison() {

		// Assert expected results.
		$results = self::$query->query( array(
			'compare_query' => array(
				'key'     => 'name',
				'value'   => 'Widget',
				'compare' => 'NOT LIKE',
			),
		) );

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
	}

	/**
	 * Test that omitting compare defaults to equals.
	 *
	 * @since 3.0.0
	 */
	public function test_default_compare_is_equals() {

		// Assert expected results.
		$results = self::$query->query( array(
			'compare_query' => array(
				'key'   => 'status',
				'value' => 'pending',
			),
		) );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Epsilon Widget', $results[0]->name );
	}

	/**
	 * Test that compare_query with count mode returns the correct count.
	 *
	 * @since 3.0.0
	 */
	public function test_compare_query_with_count_mode() {

		// Assert expected results.
		$count = self::$query->query( array(
			'compare_query' => array(
				'key'     => 'priority',
				'value'   => 30,
				'compare' => '>=',
			),
			'count' => true,
		) );

		$this->assertSame( 3, (int) $count );
	}
}
