<?php
/**
 * Tests for Query public getter methods.
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
 * Tests for the public getters added in 3.0.0:
 * get_item_name(), get_item_name_plural(), get_request(),
 * get_found_items(), and get_max_num_pages().
 *
 * @since 3.0.0
 */
class QueryGettersTest extends TestCase {

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

		self::$query->add_item(
			array(
				'name'     => 'Alpha',
				'status'   => 'active',
				'priority' => 10,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Beta',
				'status'   => 'active',
				'priority' => 20,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Gamma',
				'status'   => 'inactive',
				'priority' => 30,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Delta',
				'status'   => 'inactive',
				'priority' => 40,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Epsilon',
				'status'   => 'pending',
				'priority' => 50,
			)
		);

		wp_cache_flush();
	}

	// get_item_name() / get_item_name_plural().

	/**
	 * Test that get_item_name returns the singular item name "widget".
	 *
	 * @since 3.0.0
	 */
	public function test_get_item_name_returns_widget() {
		$this->assertSame( 'widget', self::$query->get_item_name() );
	}

	/**
	 * Test that get_item_name_plural returns the plural item name "widgets".
	 *
	 * @since 3.0.0
	 */
	public function test_get_item_name_plural_returns_widgets() {
		$this->assertSame( 'widgets', self::$query->get_item_name_plural() );
	}

	// get_request().

	/**
	 * Test that get_request returns a non-empty string after a query has been run.
	 *
	 * @since 3.0.0
	 */
	public function test_get_request_is_nonempty_string_after_query() {
		self::$query->query( array( 'number' => 0 ) );
		$this->assertNotEmpty( self::$query->get_request() );
	}

	/**
	 * Test that get_request returns a SQL string containing the SELECT keyword.
	 *
	 * @since 3.0.0
	 */
	public function test_get_request_contains_select_keyword() {
		self::$query->query( array( 'number' => 0 ) );
		$this->assertStringContainsStringIgnoringCase( 'SELECT', self::$query->get_request() );
	}

	// get_found_items().

	/**
	 * Test that get_found_items matches the number of rows retrieved by a query.
	 *
	 * @since 3.0.0
	 */
	public function test_get_found_items_matches_retrieved_row_count() {
		// Default query (no_found_rows => true) — found_items equals returned count.
		self::$query->query( array( 'number' => 0 ) );
		$this->assertSame( 5, self::$query->get_found_items() );
	}

	/**
	 * Test that get_found_items returns the total row count when no_found_rows is false.
	 *
	 * @since 3.0.0
	 */
	public function test_get_found_items_with_no_found_rows_false_returns_total_rows() {
		// no_found_rows => false triggers the secondary COUNT(*) query.
		self::$query->query(
			array(
				'number'        => 2,
				'no_found_rows' => false,
			)
		);
		$this->assertSame( 5, self::$query->get_found_items() );
	}

	/**
	 * Test that get_found_items reflects the count after a status filter is applied.
	 *
	 * @since 3.0.0
	 */
	public function test_get_found_items_respects_status_filter() {
		self::$query->query(
			array(
				'number' => 0,
				'status' => 'active',
			)
		);
		$this->assertSame( 2, self::$query->get_found_items() );
	}

	// get_max_num_pages().

	/**
	 * Test that get_max_num_pages returns one when the page size exactly divides the total row count.
	 *
	 * @since 3.0.0
	 */
	public function test_get_max_num_pages_with_exact_divisor() {
		// 5 items, page size 5 → 1 page.
		self::$query->query(
			array(
				'number'        => 5,
				'no_found_rows' => false,
			)
		);
		$this->assertSame( 1, self::$query->get_max_num_pages() );
	}

	/**
	 * Test that get_max_num_pages rounds up to the nearest whole page.
	 *
	 * @since 3.0.0
	 */
	public function test_get_max_num_pages_rounds_up() {
		// 5 items, page size 2 → ceil(5/2) = 3 pages.
		self::$query->query(
			array(
				'number'        => 2,
				'no_found_rows' => false,
			)
		);
		$this->assertSame( 3, self::$query->get_max_num_pages() );
	}

	/**
	 * Test that get_max_num_pages returns zero when no LIMIT clause is applied.
	 *
	 * @since 3.0.0
	 */
	public function test_get_max_num_pages_is_zero_for_unlimited_query() {
		// number => 0 means no LIMIT clause; max_num_pages stays 0 because the
		// pagination calculation requires a non-zero page size.
		self::$query->query( array( 'number' => 0 ) );
		$this->assertSame( 0, self::$query->get_max_num_pages() );
	}
}
