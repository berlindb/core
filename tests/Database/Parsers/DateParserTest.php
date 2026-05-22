<?php
/**
 * Date parser integration tests.
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
 * Tests for the Date parser via the 'date_query' query var.
 *
 * Five fixture rows are inserted and then their date_created timestamps are
 * forcibly updated to known values via wpdb so date comparisons are
 * deterministic.
 *
 * Row timeline (date_created):
 *  - Alpha Widget   | 2020-01-15
 *  - Beta Widget    | 2021-06-01
 *  - Gamma Gadget   | 2022-03-10
 *  - Delta Gadget   | 2023-08-20
 *  - Epsilon Widget | 2024-12-31
 *
 * @since 3.0.0
 */
class DateParserTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/** @var int[] */
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

		global $wpdb;

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

		$table_name = self::$table->table_name;

		$dates = array(
			'2020-01-15 00:00:00',
			'2021-06-01 00:00:00',
			'2022-03-10 00:00:00',
			'2023-08-20 00:00:00',
			'2024-12-31 00:00:00',
		);

		foreach ( $this->ids as $i => $id ) {
			$wpdb->update(
				$table_name,
				array( 'date_created' => $dates[ $i ] ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		wp_cache_flush();
	}

	/**
	 * Test that after filter returns rows created after the given date.
	 *
	 * @since 3.0.0
	 */
	public function test_after_filter_returns_matching_rows() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'after'  => '2022-01-01',
					),
				),
			)
		);

		$this->assertCount( 3, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
		$this->assertContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test that before filter returns rows created before the given date.
	 *
	 * @since 3.0.0
	 */
	public function test_before_filter_returns_matching_rows() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'before' => '2022-01-01',
					),
				),
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
	}

	/**
	 * Test that combining after and before creates a date range.
	 *
	 * @since 3.0.0
	 */
	public function test_date_range_with_after_and_before() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'after'  => '2021-01-01',
						'before' => '2023-01-01',
					),
				),
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Beta Widget', $names );
		$this->assertContains( 'Gamma Gadget', $names );
	}

	/**
	 * Test that year filter returns rows from the given year.
	 *
	 * @since 3.0.0
	 */
	public function test_year_filter() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'year'   => 2023,
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Delta Gadget', $results[0]->name );
	}

	/**
	 * Test that month filter returns rows from the given month across all years.
	 *
	 * @since 3.0.0
	 */
	public function test_month_filter() {

		// January (month 1) only has Alpha Widget (2020-01-15).
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'month'  => 1,
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Alpha Widget', $results[0]->name );
	}

	/**
	 * Test that date_query with count mode returns the correct count.
	 *
	 * @since 3.0.0
	 */
	public function test_date_query_with_count_mode() {

		// Assert expected results.
		$count = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'after'  => '2023-01-01',
					),
				),
				'count'      => true,
			)
		);

		$this->assertSame( 2, (int) $count );
	}

	/**
	 * Test that relation OR returns rows matching either date clause.
	 *
	 * @since 3.0.0
	 */
	public function test_or_relation_across_date_clauses() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					'relation' => 'OR',
					array(
						'column' => 'date_created',
						'year'   => 2020,
					),
					array(
						'column' => 'date_created',
						'year'   => 2024,
					),
				),
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test that orderby=date_created_query ASC returns rows oldest-first.
	 *
	 * @since 3.0.0
	 */
	public function test_orderby_date_created_query_asc() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'orderby' => 'date_created_query',
				'order'   => 'ASC',
			)
		);

		$this->assertCount( 5, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertSame( 'Alpha Widget', $names[0] ); // 2020-01-15
		$this->assertSame( 'Beta Widget', $names[1] ); // 2021-06-01
		$this->assertSame( 'Gamma Gadget', $names[2] ); // 2022-03-10
		$this->assertSame( 'Delta Gadget', $names[3] ); // 2023-08-20
		$this->assertSame( 'Epsilon Widget', $names[4] ); // 2024-12-31
	}

	/**
	 * Test that orderby=date_created_query DESC returns rows newest-first.
	 *
	 * @since 3.0.0
	 */
	public function test_orderby_date_created_query_desc() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'orderby' => 'date_created_query',
				'order'   => 'DESC',
			)
		);

		$this->assertCount( 5, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertSame( 'Epsilon Widget', $names[0] ); // 2024-12-31
		$this->assertSame( 'Delta Gadget', $names[1] ); // 2023-08-20
		$this->assertSame( 'Gamma Gadget', $names[2] ); // 2022-03-10
		$this->assertSame( 'Beta Widget', $names[3] ); // 2021-06-01
		$this->assertSame( 'Alpha Widget', $names[4] ); // 2020-01-15
	}
}
