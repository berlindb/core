<?php
/**
 * The 'by' column-filter container.
 *
 * `array( 'by' => array( 'col' => value ) )` is friendlier, collision-proof column
 * filtering: it folds to the In parser's canonical `{col}__in` var during normalize,
 * so it renders `= value` for a single value and `IN (...)` for a list, and a column
 * whose bare name collides with a reserved control var stays filterable. Integration
 * tests: real queries against the fixture table.
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
 * Integration tests for the 'by' container.
 *
 * @since 3.1.0
 */
class ByContainerTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/**
	 * @since 3.1.0
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
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Seed three rows: priorities 10/20 (active) and 30 (inactive).
	 *
	 * @since 3.1.0
	 */
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

		wp_cache_flush();
	}

	/**
	 * A single 'by' value filters the rows (like a bare column filter).
	 *
	 * @since 3.1.0
	 */
	public function test_by_single_value_filters() {
		$count = self::$query->query(
			array(
				'by'    => array( 'status' => 'active' ),
				'count' => true,
			)
		);

		$this->assertSame( 2, $count );
	}

	/**
	 * A 'by' entry matches its explicit '{column}__in' equivalent exactly.
	 *
	 * @since 3.1.0
	 */
	public function test_by_matches_explicit_column_in() {
		$by = self::$query->query(
			array(
				'by'    => array( 'status' => 'active' ),
				'count' => true,
			)
		);

		$in = self::$query->query(
			array(
				'status__in' => array( 'active' ),
				'count'      => true,
			)
		);

		$this->assertSame( 2, $by );
		$this->assertSame( $in, $by );
	}

	/**
	 * An array 'by' value renders an IN list.
	 *
	 * @since 3.1.0
	 */
	public function test_by_array_value_is_in_list() {
		$count = self::$query->query(
			array(
				'by'    => array( 'priority' => array( 10, 20 ) ),
				'count' => true,
			)
		);

		$this->assertSame( 2, $count );
	}

	/**
	 * An explicit top-level '{column}__in' wins over a 'by' entry for the same column.
	 *
	 * @since 3.1.0
	 */
	public function test_by_explicit_column_in_wins() {
		$count = self::$query->query(
			array(
				'priority__in' => array( 10, 20 ), // Explicit: 2 rows.
				'by'           => array( 'priority' => array( 30 ) ), // Would be 1 row, but loses.
				'count'        => true,
			)
		);

		$this->assertSame( 2, $count );
	}

	/**
	 * An unknown column in 'by' is ignored (no filter) and logged.
	 *
	 * @since 3.1.0
	 */
	public function test_by_unknown_column_is_ignored_and_logged() {
		$count = self::$query->query(
			array(
				'by'    => array( 'nonexistent' => 5 ),
				'count' => true,
			)
		);

		$this->assertSame( 3, $count );

		$logged = new TestQuery( array( 'by' => array( 'nonexistent' => 5 ) ) );
		$this->assertNotEmpty( $logged->get_logs( array( 'code' => 'by' ) ) );
	}

	/**
	 * A malformed (non-array) 'by' is ignored and logged - including a falsy scalar,
	 * which must not be silently swallowed as "empty".
	 *
	 * @since 3.1.0
	 */
	public function test_by_non_array_is_ignored_and_logged() {
		$string = new TestQuery( array( 'by' => 'garbage' ) );
		$this->assertNotEmpty( $string->get_logs( array( 'code' => 'by' ) ) );

		$falsy = new TestQuery( array( 'by' => false ) );
		$this->assertNotEmpty( $falsy->get_logs( array( 'code' => 'by' ) ) );
	}

	/**
	 * An explicit empty 'by' array is a silent no-op (no warning).
	 *
	 * @since 3.1.0
	 */
	public function test_by_empty_array_is_silent_noop() {
		$query = new TestQuery( array( 'by' => array() ) );
		$this->assertEmpty( $query->get_logs( array( 'code' => 'by' ) ) );
	}
}
