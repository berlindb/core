<?php
/**
 * Characterization of the query "modes" - the return-shape contract per query kind.
 *
 * A query resolves to one of three modes from its vars: rows (default), count, or
 * aggregate; groupby and explain are modifiers layered on top. These tests pin the
 * CURRENT return shape of each so an upcoming get_items() decomposition (making the
 * mode a first-class, resolved-once concept - berlindb/core #217) can be proven
 * behavior-preserving. They are the executable spec of the modes, not new behavior.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestRow;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Characterization tests for the rows / count / aggregate query modes.
 *
 * @since 3.1.0
 */
class QueryModeCharacterizationTest extends TestCase {

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
	 * rows mode: returns a list of shaped item objects.
	 *
	 * @since 3.1.0
	 */
	public function test_rows_mode_returns_shaped_items() {
		$items = self::$query->query( array( 'number' => 100 ) );

		$this->assertIsArray( $items );
		$this->assertCount( 3, $items );
		$this->assertContainsOnlyInstancesOf( TestRow::class, $items );
	}

	/**
	 * count mode (plain): returns an int.
	 *
	 * @since 3.1.0
	 */
	public function test_count_mode_returns_int() {
		$count = self::$query->query( array( 'count' => true ) );

		$this->assertIsInt( $count );
		$this->assertSame( 3, $count );
	}

	/**
	 * count mode (grouped): returns a list of rows.
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_count_returns_rows() {
		$rows = self::$query->query(
			array(
				'count'   => true,
				'groupby' => 'status',
			)
		);

		$this->assertIsArray( $rows );
		$this->assertCount( 2, $rows );
	}

	/**
	 * aggregate mode (ungrouped): returns an associative array keyed by alias.
	 *
	 * @since 3.1.0
	 */
	public function test_aggregate_mode_returns_assoc() {
		$result = self::$query->query( array( 'aggregate' => array( 'total' => array( 'sum', 'priority' ) ) ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertEquals( 60, $result[ 'total' ] );
	}

	/**
	 * aggregate mode (grouped): returns a list of rows.
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_aggregate_returns_rows() {
		$rows = self::$query->query(
			array(
				'aggregate' => array( 'total' => array( 'sum', 'priority' ) ),
				'groupby'   => 'status',
			)
		);

		$this->assertIsArray( $rows );
		$this->assertCount( 2, $rows );
		$this->assertArrayHasKey( 'total', $rows[ 0 ] );
	}

	/**
	 * Each mode is cache-stable: a second (cache-hit) run returns the same result as
	 * the first (cache-miss) run.
	 *
	 * @since 3.1.0
	 */
	public function test_each_mode_is_cache_stable() {
		$rows_a = self::$query->query( array( 'number' => 100 ) );
		$rows_b = self::$query->query( array( 'number' => 100 ) );
		$this->assertEquals( $rows_a, $rows_b );

		$count_a = self::$query->query( array( 'count' => true ) );
		$count_b = self::$query->query( array( 'count' => true ) );
		$this->assertSame( $count_a, $count_b );

		$agg_a = self::$query->query( array( 'aggregate' => array( 'total' => array( 'sum', 'priority' ) ) ) );
		$agg_b = self::$query->query( array( 'aggregate' => array( 'total' => array( 'sum', 'priority' ) ) ) );
		$this->assertSame( $agg_a, $agg_b );
	}

	/**
	 * The 'explain' modifier prepends EXPLAIN to the request (and is never cached).
	 *
	 * @since 3.1.0
	 */
	public function test_explain_modifier_prepends_explain() {
		$captured = '';
		$callback = static function ( $sql ) use ( &$captured ) {
			if ( false !== stripos( (string) $sql, 'EXPLAIN' ) ) {
				$captured = $sql;
			}
			return $sql;
		};

		add_filter( 'query', $callback );

		try {
			self::$query->query(
				array(
					'explain' => true,
					'number'  => 100,
				)
			);
		} finally {
			remove_filter( 'query', $callback );
		}

		$this->assertStringContainsStringIgnoringCase( 'EXPLAIN', $captured );
	}
}
