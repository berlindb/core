<?php
/**
 * Query scalar-aggregate tests (get_sum / get_avg / get_max / get_min).
 *
 * The aggregate methods render FUNC( column ) through the operand value objects
 * and run a scalar query reusing the SELECT path's WHERE/JOIN build. Integration
 * tests: they issue real queries against the fixture table.
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
 * Integration tests for Query::get_sum/get_avg/get_max/get_min.
 *
 * @since 3.1.0
 */
class AggregateTest extends TestCase {

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
	 * get_sum() totals a numeric column across all rows.
	 *
	 * @since 3.1.0
	 */
	public function test_get_sum() {
		$this->assertSame( 60.0, self::$query->get_sum( 'priority' ) );
	}

	/**
	 * get_avg() averages a numeric column.
	 *
	 * @since 3.1.0
	 */
	public function test_get_avg() {
		$this->assertSame( 20.0, self::$query->get_avg( 'priority' ) );
	}

	/**
	 * get_max() / get_min() return the extremes (as raw scalars).
	 *
	 * @since 3.1.0
	 */
	public function test_get_max_and_min() {
		$this->assertSame( '30', self::$query->get_max( 'priority' ) );
		$this->assertSame( '10', self::$query->get_min( 'priority' ) );
	}

	/**
	 * Aggregates honor query-var filters (only the active rows).
	 *
	 * @since 3.1.0
	 */
	public function test_aggregate_respects_filters() {
		$this->assertSame( 30.0, self::$query->get_sum( 'priority', array( 'status' => 'active' ) ) );
		$this->assertSame( '20', self::$query->get_max( 'priority', array( 'status' => 'active' ) ) );
	}

	/**
	 * SUM/AVG of a non-numeric column fails closed (null).
	 *
	 * @since 3.1.0
	 */
	public function test_sum_of_non_numeric_column_is_null() {
		$this->assertNull( self::$query->get_sum( 'name' ) );
	}

	/**
	 * An unknown column fails closed (null).
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_column_is_null() {
		$this->assertNull( self::$query->get_sum( 'does_not_exist' ) );
	}

	/**
	 * An aggregate over no matching rows is null (SQL SUM returns NULL).
	 *
	 * @since 3.1.0
	 */
	public function test_aggregate_over_no_rows_is_null() {
		self::$table->delete_all();
		wp_cache_flush();

		$this->assertNull( self::$query->get_sum( 'priority' ) );
	}

	/**
	 * A JOIN-producing filter fans out base rows one-to-many; the aggregate must not
	 * double-count. A clauses filter CROSS JOINs a two-row derived table (doubling
	 * every base row), so a naive SUM over the joined result would be 120 - the
	 * distinct-primary subquery must still return 60.
	 *
	 * @since 3.1.0
	 */
	public function test_sum_with_join_filter_is_not_double_counted() {
		$filter   = 'berlindb_database_widgets_query_clauses';
		$callback = static function ( $clauses ) {
			$clauses[ 'join' ] = trim( ( $clauses[ 'join' ] ?? '' ) . ' JOIN ( SELECT 1 UNION ALL SELECT 2 ) AS berlin_fan ON ( 1 = 1 )' );
			return $clauses;
		};

		add_filter( $filter, $callback );

		try {
			$this->assertSame( 60.0, self::$query->get_sum( 'priority' ) );
		} finally {
			remove_filter( $filter, $callback );
		}
	}

	/**
	 * MAX is idempotent under fan-out, but it still routes through the subquery when a
	 * JOIN is present and must return the correct extreme (not null, not skipped).
	 *
	 * @since 3.1.0
	 */
	public function test_max_with_join_filter_is_correct() {
		$filter   = 'berlindb_database_widgets_query_clauses';
		$callback = static function ( $clauses ) {
			$clauses[ 'join' ] = trim( ( $clauses[ 'join' ] ?? '' ) . ' JOIN ( SELECT 1 UNION ALL SELECT 2 ) AS berlin_fan ON ( 1 = 1 )' );
			return $clauses;
		};

		add_filter( $filter, $callback );

		try {
			$this->assertSame( '30', self::$query->get_max( 'priority' ) );
		} finally {
			remove_filter( $filter, $callback );
		}
	}

	/**
	 * A query-var filter (WHERE) and a JOIN-producing filter must both constrain the
	 * inner distinct-primary subquery: the active rows (priority 10 + 20) sum to 30,
	 * doubled by the fan-out only if the WHERE or the dedup were lost.
	 *
	 * @since 3.1.0
	 */
	public function test_join_filter_combines_with_where() {
		$filter   = 'berlindb_database_widgets_query_clauses';
		$callback = static function ( $clauses ) {
			$clauses[ 'join' ] = trim( ( $clauses[ 'join' ] ?? '' ) . ' JOIN ( SELECT 1 UNION ALL SELECT 2 ) AS berlin_fan ON ( 1 = 1 )' );
			return $clauses;
		};

		add_filter( $filter, $callback );

		try {
			$this->assertSame( 30.0, self::$query->get_sum( 'priority', array( 'status' => 'active' ) ) );
		} finally {
			remove_filter( $filter, $callback );
		}
	}

	/**
	 * A query-clauses filter that also adds GROUP BY (or ORDER BY / LIMIT) must not
	 * narrow the distinct-primary id set: grouping the fanned rows would collapse to
	 * one arbitrary key per group and undercount. The dedup subquery drops those
	 * result-shaping clauses, so the sum stays 60 (all three rows), not a grouped
	 * subset.
	 *
	 * @since 3.1.0
	 */
	public function test_join_filter_with_groupby_does_not_undercount() {
		$filter   = 'berlindb_database_widgets_query_clauses';
		$callback = static function ( $clauses ) {
			$clauses[ 'join' ]    = trim( ( $clauses[ 'join' ] ?? '' ) . ' JOIN ( SELECT 1 AS n UNION ALL SELECT 2 AS n ) AS berlin_fan ON ( 1 = 1 )' );
			$clauses[ 'groupby' ] = 'GROUP BY berlin_fan.n';
			return $clauses;
		};

		add_filter( $filter, $callback );

		try {
			$this->assertSame( 60.0, self::$query->get_sum( 'priority' ) );
		} finally {
			remove_filter( $filter, $callback );
		}
	}
}
