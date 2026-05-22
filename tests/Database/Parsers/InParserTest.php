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

	/**
	 * Test that orderby=id__in returns rows in the exact order of the IN list.
	 *
	 * Passes all IDs in reverse-insertion order and verifies the FIELD()
	 * expression preserves that custom sequence rather than falling back to
	 * the default primary-key order.
	 *
	 * @since 3.0.0
	 */
	public function test_orderby_field_preserves_id_order() {
		$reversed = array_reverse( $this->ids );

		$results = self::$query->query( array(
			'id__in'  => implode( ', ', $reversed ),
			'orderby' => 'id__in',
			'order'   => 'ASC',
		) );

		// All 5 rows must come back in exact reverse-insertion order.
		$this->assertCount( 5, $results );
		foreach ( $reversed as $i => $expected_id ) {
			$this->assertSame( $expected_id, (int) $results[ $i ]->id );
		}
	}

	/**
	 * Test that orderby=status__in groups rows by their position in the IN list.
	 *
	 * Passes 'inactive, active' so inactive rows must precede active rows,
	 * which is the opposite of alphabetical order.
	 *
	 * @since 3.0.0
	 */
	public function test_orderby_field_groups_by_status() {
		$results = self::$query->query( array(
			'status__in' => 'inactive, active',
			'orderby'    => 'status__in',
			'order'      => 'ASC',
		) );

		/*
		 * 4 rows (Gamma + Delta = inactive; Alpha + Beta = active).
		 * The entire inactive group must come before the active group.
		 */
		$this->assertCount( 4, $results );
		$statuses = wp_list_pluck( $results, 'status' );
		$this->assertSame( 'inactive', $statuses[0] );
		$this->assertSame( 'inactive', $statuses[1] );
		$this->assertSame( 'active',   $statuses[2] );
		$this->assertSame( 'active',   $statuses[3] );
	}
}
