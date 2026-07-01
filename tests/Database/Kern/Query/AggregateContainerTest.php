<?php
/**
 * The 'aggregate' query-var container (#225).
 *
 * `array( 'aggregate' => array( 'revenue' => array( 'sum', 'amount' ) ) )` computes
 * one or more aggregates in a single query and returns them keyed by alias. It
 * always returns an associative array (the scalar get_sum()/etc. wrappers are the
 * friendly scalar path); an empty set is null per alias, not zero. Integration
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
 * Integration tests for the 'aggregate' container.
 *
 * @since 3.1.0
 */
class AggregateContainerTest extends TestCase {

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
	 * A single shorthand aggregate returns an assoc keyed by the function name.
	 *
	 * @since 3.1.0
	 */
	public function test_single_aggregate_returns_assoc() {
		$result = self::$query->query( array( 'aggregate' => array( 'sum' => 'priority' ) ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'sum', $result );
		$this->assertEquals( 60, $result[ 'sum' ] );
	}

	/**
	 * Multiple aggregates come back in one query, keyed by alias.
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_aggregates_in_one_query() {
		$result = self::$query->query(
			array(
				'aggregate' => array(
					'total' => array( 'sum', 'priority' ),
					'peak'  => array( 'max', 'priority' ),
				),
			)
		);

		$this->assertEquals( 60, $result[ 'total' ] );
		$this->assertEquals( 30, $result[ 'peak' ] );
	}

	/**
	 * The named { function, column } spec form works.
	 *
	 * @since 3.1.0
	 */
	public function test_named_spec_form() {
		$result = self::$query->query(
			array(
				'aggregate' => array(
					'total' => array(
						'function' => 'sum',
						'column'   => 'priority',
					),
				),
			)
		);

		$this->assertEquals( 60, $result[ 'total' ] );
	}

	/**
	 * Aggregates honor the query's filters.
	 *
	 * @since 3.1.0
	 */
	public function test_aggregate_respects_filter() {
		$result = self::$query->query(
			array(
				'aggregate'  => array( 'sum' => 'priority' ),
				'status__in' => array( 'active' ),
			)
		);

		$this->assertEquals( 30, $result[ 'sum' ] );
	}

	/**
	 * An aggregate over no matching rows is null per alias (not zero).
	 *
	 * @since 3.1.0
	 */
	public function test_aggregate_empty_set_is_null() {
		self::$table->delete_all();
		wp_cache_flush();

		$result = self::$query->query( array( 'aggregate' => array( 'sum' => 'priority' ) ) );

		$this->assertArrayHasKey( 'sum', $result );
		$this->assertNull( $result[ 'sum' ] );
	}

	/**
	 * An unknown column and a non-numeric SUM/AVG column are dropped and logged.
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_entries_are_logged() {
		$unknown = new TestQuery( array( 'aggregate' => array( 'sum' => 'nonexistent' ) ) );
		$this->assertNotEmpty( $unknown->get_logs( array( 'code' => 'aggregate' ) ) );

		$non_numeric = new TestQuery( array( 'aggregate' => array( 'sum' => 'name' ) ) );
		$this->assertNotEmpty( $non_numeric->get_logs( array( 'code' => 'aggregate' ) ) );
	}

	/**
	 * The cache key distinguishes aggregates by function, not just alias: the same
	 * alias with a different function must not return a stale cached value.
	 *
	 * @since 3.1.0
	 */
	public function test_cache_key_distinguishes_function() {
		$sum = self::$query->query( array( 'aggregate' => array( 'x' => array( 'sum', 'priority' ) ) ) );
		$max = self::$query->query( array( 'aggregate' => array( 'x' => array( 'max', 'priority' ) ) ) );

		$this->assertEquals( 60, $sum[ 'x' ] );
		$this->assertEquals( 30, $max[ 'x' ] );
	}
}
