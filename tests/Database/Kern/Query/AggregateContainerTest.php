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

	/**
	 * With 'groupby' the container returns one row per group (group column + aliases).
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_aggregate_returns_a_row_per_group() {
		$rows = self::$query->query(
			array(
				'aggregate' => array( 'revenue' => array( 'sum', 'priority' ) ),
				'groupby'   => 'status',
			)
		);

		$this->assertCount( 2, $rows );

		$by_status = array();
		foreach ( $rows as $row ) {
			$by_status[ $row[ 'status' ] ] = $row[ 'revenue' ];
		}

		$this->assertEquals( 30, $by_status[ 'active' ] );   // 10 + 20
		$this->assertEquals( 30, $by_status[ 'inactive' ] ); // 30
	}

	/**
	 * Multiple grouped aggregates come back together per group.
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_multiple_aggregates() {
		$rows = self::$query->query(
			array(
				'aggregate' => array(
					'total' => array( 'sum', 'priority' ),
					'peak'  => array( 'max', 'priority' ),
				),
				'groupby'   => 'status',
			)
		);

		$by_status = array();
		foreach ( $rows as $row ) {
			$by_status[ $row[ 'status' ] ] = $row;
		}

		$this->assertEquals( 30, $by_status[ 'active' ][ 'total' ] );
		$this->assertEquals( 20, $by_status[ 'active' ][ 'peak' ] );
		$this->assertEquals( 30, $by_status[ 'inactive' ][ 'total' ] );
		$this->assertEquals( 30, $by_status[ 'inactive' ][ 'peak' ] );
	}

	/**
	 * A grouped aggregate over no rows is an empty list (no groups), not a null row.
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_empty_set_is_empty_list() {
		self::$table->delete_all();
		wp_cache_flush();

		$rows = self::$query->query(
			array(
				'aggregate' => array( 'revenue' => array( 'sum', 'priority' ) ),
				'groupby'   => 'status',
			)
		);

		$this->assertSame( array(), $rows );
	}

	/**
	 * A grouped aggregate over a fan-out JOIN filter groups AFTER the distinct-primary
	 * dedup, so each base row is counted once per group (30/30), not doubled (60/60).
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_aggregate_with_join_filter_is_not_double_counted() {
		$filter   = 'berlindb_database_widgets_query_clauses';
		$callback = static function ( $clauses ) {
			$clauses[ 'join' ] = trim( ( $clauses[ 'join' ] ?? '' ) . ' JOIN ( SELECT 1 UNION ALL SELECT 2 ) AS berlin_fan ON ( 1 = 1 )' );
			return $clauses;
		};

		add_filter( $filter, $callback );

		try {
			$rows = self::$query->query(
				array(
					'aggregate' => array( 'revenue' => array( 'sum', 'priority' ) ),
					'groupby'   => 'status',
				)
			);

			$by_status = array();
			foreach ( $rows as $row ) {
				$by_status[ $row[ 'status' ] ] = $row[ 'revenue' ];
			}

			$this->assertEquals( 30, $by_status[ 'active' ] );
			$this->assertEquals( 30, $by_status[ 'inactive' ] );
		} finally {
			remove_filter( $filter, $callback );
		}
	}

	/**
	 * An aggregate alias that collides with a group column is dropped and logged (both
	 * would land under the same result key); other aggregates still compute.
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_alias_colliding_with_group_column_is_dropped() {
		$query = new TestQuery(
			array(
				'aggregate' => array(
					'status'  => array( 'max', 'priority' ), // Collides with the group column.
					'revenue' => array( 'sum', 'priority' ),
				),
				'groupby'   => 'status',
			)
		);

		$this->assertNotEmpty( $query->get_logs( array( 'code' => 'aggregate' ) ) );

		$by_status = array();
		foreach ( $query->items as $row ) {
			$by_status[ $row[ 'status' ] ] = $row;
		}

		// The colliding alias was dropped: the row's 'status' is the group value, not MAX.
		$this->assertSame( 'active', $by_status[ 'active' ][ 'status' ] );
		$this->assertEquals( 30, $by_status[ 'active' ][ 'revenue' ] );
	}

	/**
	 * An unknown 'groupby' column is treated as ungrouped (a flat assoc), never emitted
	 * as malformed grouped SQL.
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_groupby_is_treated_as_ungrouped() {
		$result = self::$query->query(
			array(
				'aggregate' => array( 'sum' => 'priority' ),
				'groupby'   => 'nonexistent',
			)
		);

		$this->assertArrayHasKey( 'sum', $result );
		$this->assertEquals( 60, $result[ 'sum' ] );
	}

	/**
	 * COUNT( * ) and a column aggregate come back together in one query - the payoff of
	 * folding count into the container.
	 *
	 * @since 3.1.0
	 */
	public function test_count_star_and_column_aggregate_together() {
		$result = self::$query->query(
			array(
				'aggregate' => array(
					'orders'  => array( 'count', '*' ),
					'revenue' => array( 'sum', 'priority' ),
				),
			)
		);

		$this->assertEquals( 3, $result[ 'orders' ] );
		$this->assertEquals( 60, $result[ 'revenue' ] );
	}

	/**
	 * The shorthand and COUNT of a column both work.
	 *
	 * @since 3.1.0
	 */
	public function test_count_shorthand_and_of_column() {
		$star = self::$query->query( array( 'aggregate' => array( 'count' => '*' ) ) );
		$this->assertEquals( 3, $star[ 'count' ] );

		$col = self::$query->query( array( 'aggregate' => array( 'n' => array( 'count', 'priority' ) ) ) );
		$this->assertEquals( 3, $col[ 'n' ] );
	}

	/**
	 * COUNT groups like any other aggregate.
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_count() {
		$rows = self::$query->query(
			array(
				'aggregate' => array( 'orders' => array( 'count', '*' ) ),
				'groupby'   => 'status',
			)
		);

		$by_status = array();
		foreach ( $rows as $row ) {
			$by_status[ $row[ 'status' ] ] = $row[ 'orders' ];
		}

		$this->assertEquals( 2, $by_status[ 'active' ] );
		$this->assertEquals( 1, $by_status[ 'inactive' ] );
	}

	/**
	 * Over an empty set, COUNT is 0 while other aggregates are null.
	 *
	 * @since 3.1.0
	 */
	public function test_count_empty_set_is_zero() {
		self::$table->delete_all();
		wp_cache_flush();

		$result = self::$query->query(
			array(
				'aggregate' => array(
					'orders'  => array( 'count', '*' ),
					'revenue' => array( 'sum', 'priority' ),
				),
			)
		);

		$this->assertEquals( 0, $result[ 'orders' ] );
		$this->assertNull( $result[ 'revenue' ] );
	}

	/**
	 * COUNT(DISTINCT col) counts unique values, unlike a plain COUNT(col).
	 *
	 * @since 3.1.0
	 */
	public function test_count_distinct_counts_unique_values() {
		$result = self::$query->query(
			array(
				'aggregate' => array(
					'statuses'     => array(
						'function' => 'count',
						'column'   => 'status',
						'distinct' => true,
					),
					'all_statuses' => array( 'count', 'status' ),
				),
			)
		);

		// Fixture: active, active, inactive - 2 distinct, 3 total.
		$this->assertEquals( 2, $result[ 'statuses' ] );
		$this->assertEquals( 3, $result[ 'all_statuses' ] );
	}

	/**
	 * DISTINCT on a non-COUNT function is rejected: the entry is dropped.
	 *
	 * @since 3.1.0
	 */
	public function test_distinct_on_non_count_is_dropped() {
		$result = self::$query->query(
			array(
				'aggregate' => array(
					'orders' => array( 'count', '*' ),
					'bad'    => array(
						'function' => 'sum',
						'column'   => 'priority',
						'distinct' => true,
					),
				),
			)
		);

		$this->assertEquals( 3, $result[ 'orders' ] );
		$this->assertArrayNotHasKey( 'bad', $result );
	}

	/**
	 * COUNT(DISTINCT *) is rejected: the entry is dropped.
	 *
	 * @since 3.1.0
	 */
	public function test_count_distinct_star_is_dropped() {
		$result = self::$query->query(
			array(
				'aggregate' => array(
					'orders' => array( 'count', '*' ),
					'bad'    => array(
						'function' => 'count',
						'column'   => '*',
						'distinct' => true,
					),
				),
			)
		);

		$this->assertEquals( 3, $result[ 'orders' ] );
		$this->assertArrayNotHasKey( 'bad', $result );
	}

	/**
	 * Grouped COUNT(DISTINCT col): a distinct count per group.
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_count_distinct() {

		// Add a within-group duplicate: active now holds priorities 10, 20, 10.
		self::$query->add_item(
			array(
				'name'     => 'Delta',
				'status'   => 'active',
				'priority' => 10,
			)
		);
		wp_cache_flush();

		$rows = self::$query->query(
			array(
				'aggregate' => array(
					'uniq' => array(
						'function' => 'count',
						'column'   => 'priority',
						'distinct' => true,
					),
				),
				'groupby'   => 'status',
			)
		);

		// Map status => distinct-priority count for order-independent assertions.
		$by_status = array();
		foreach ( $rows as $row ) {
			$by_status[ $row[ 'status' ] ] = $row[ 'uniq' ];
		}

		// active: {10, 20, 10} -> 2 distinct; inactive: {30} -> 1.
		$this->assertEquals( 2, $by_status[ 'active' ] );
		$this->assertEquals( 1, $by_status[ 'inactive' ] );
	}

	/**
	 * DISTINCT is part of the cache key: a distinct count and a plain count with
	 * the SAME alias and column do not collide in the cache.
	 *
	 * @since 3.1.0
	 */
	public function test_distinct_does_not_collide_in_cache() {

		// Runs first, warming the cache under the distinct container's key.
		$distinct = self::$query->query(
			array(
				'aggregate' => array(
					'c' => array(
						'function' => 'count',
						'column'   => 'status',
						'distinct' => true,
					),
				),
			)
		);

		// Same alias + column, but not distinct: must NOT reuse the distinct result.
		$plain = self::$query->query(
			array(
				'aggregate' => array(
					'c' => array( 'count', 'status' ),
				),
			)
		);

		$this->assertEquals( 2, $distinct[ 'c' ] );  // active, inactive
		$this->assertEquals( 3, $plain[ 'c' ] );      // all three rows
	}

	/**
	 * A grouped aggregate can be ordered by an aggregate alias, both directions.
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_ordered_by_aggregate_alias() {

		// active has 2 rows, inactive 1 - so COUNT(*) orders the groups.
		$desc = self::$query->query(
			array(
				'aggregate' => array( 'n' => array( 'count', '*' ) ),
				'groupby'   => 'status',
				'orderby'   => 'n',
				'order'     => 'DESC',
			)
		);

		$this->assertSame( 'active', $desc[ 0 ][ 'status' ] );   // n = 2
		$this->assertSame( 'inactive', $desc[ 1 ][ 'status' ] ); // n = 1

		$asc = self::$query->query(
			array(
				'aggregate' => array( 'n' => array( 'count', '*' ) ),
				'groupby'   => 'status',
				'orderby'   => 'n',
				'order'     => 'ASC',
			)
		);

		$this->assertSame( 'inactive', $asc[ 0 ][ 'status' ] );  // n = 1
		$this->assertSame( 'active', $asc[ 1 ][ 'status' ] );    // n = 2
	}

	/**
	 * A grouped aggregate can be ordered by a group column.
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_ordered_by_group_column() {
		$rows = self::$query->query(
			array(
				'aggregate' => array( 'n' => array( 'count', '*' ) ),
				'groupby'   => 'status',
				'orderby'   => 'status',
				'order'     => 'ASC',
			)
		);

		// Alphabetical by the group column.
		$this->assertSame( 'active', $rows[ 0 ][ 'status' ] );
		$this->assertSame( 'inactive', $rows[ 1 ][ 'status' ] );
	}

	/**
	 * Ordering by an unknown key is dropped: the query still returns its groups.
	 *
	 * @since 3.1.0
	 */
	public function test_grouped_ordered_by_unknown_key_is_ignored() {
		$rows = self::$query->query(
			array(
				'aggregate' => array( 'n' => array( 'count', '*' ) ),
				'groupby'   => 'status',
				'orderby'   => 'nonexistent',
			)
		);

		$this->assertCount( 2, $rows );
	}

	/**
	 * An ungrouped aggregate is a single row, so orderby is ignored.
	 *
	 * @since 3.1.0
	 */
	public function test_ungrouped_aggregate_ignores_orderby() {
		$result = self::$query->query(
			array(
				'aggregate' => array( 'n' => array( 'count', '*' ) ),
				'orderby'   => 'n',
			)
		);

		$this->assertEquals( 3, $result[ 'n' ] );
	}

	/**
	 * Ordering a grouped aggregate over a fan-out JOIN: ORDER BY is on the OUTER
	 * query (never the aliasless dedup subquery), and the counts stay deduped.
	 *
	 * @since 3.1.0
	 */
	public function test_ordered_grouped_aggregate_over_fan_out_join() {

		// A query-clauses filter fans each base row out 2x, forcing the dedup subquery.
		$filter   = 'berlindb_database_widgets_query_clauses';
		$callback = static function ( $clauses ) {
			$clauses[ 'join' ] = trim( ( $clauses[ 'join' ] ?? '' ) . ' JOIN ( SELECT 1 UNION ALL SELECT 2 ) AS berlin_fan ON ( 1 = 1 )' );
			return $clauses;
		};

		add_filter( $filter, $callback );

		try {
			$rows = self::$query->query(
				array(
					'aggregate' => array( 'n' => array( 'count', '*' ) ),
					'groupby'   => 'status',
					'orderby'   => 'n',
					'order'     => 'DESC',
				)
			);
		} finally {
			remove_filter( $filter, $callback );
		}

		// Deduped despite the 2x fan-out (2 and 1, not 4 and 2), ordered DESC by n.
		$this->assertSame( 'active', $rows[ 0 ][ 'status' ] );
		$this->assertEquals( 2, $rows[ 0 ][ 'n' ] );
		$this->assertSame( 'inactive', $rows[ 1 ][ 'status' ] );
		$this->assertEquals( 1, $rows[ 1 ][ 'n' ] );
	}

	/* HAVING - filter grouped aggregates by their results ****************/

	/**
	 * The named { compare, value } form filters groups by an aggregate result.
	 *
	 * @since 3.1.0
	 */
	public function test_having_named_form_filters_groups() {
		$rows = self::$query->query(
			array(
				'aggregate' => array( 'n' => array( 'count', '*' ) ),
				'groupby'   => 'status',
				'having'    => array(
					'n' => array(
						'compare' => '>',
						'value'   => 1,
					),
				),
			)
		);

		// Only 'active' has more than one row.
		$this->assertCount( 1, $rows );
		$this->assertSame( 'active', $rows[ 0 ][ 'status' ] );
		$this->assertEquals( 2, $rows[ 0 ][ 'n' ] );
	}

	/**
	 * The positional { compare, value } shorthand filters identically.
	 *
	 * @since 3.1.0
	 */
	public function test_having_positional_form_filters_groups() {
		$rows = self::$query->query(
			array(
				'aggregate' => array( 'n' => array( 'count', '*' ) ),
				'groupby'   => 'status',
				'having'    => array( 'n' => array( '>', 1 ) ),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'active', $rows[ 0 ][ 'status' ] );
	}

	/**
	 * HAVING on a MAX borrows the source column's placeholder, so a numeric bound
	 * compares numerically ( active peaks at 20, inactive at 30 ).
	 *
	 * @since 3.1.0
	 */
	public function test_having_on_max_uses_column_pattern() {
		$rows = self::$query->query(
			array(
				'aggregate' => array( 'peak' => array( 'max', 'priority' ) ),
				'groupby'   => 'status',
				'having'    => array( 'peak' => array( '>', 25 ) ),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'inactive', $rows[ 0 ][ 'status' ] );
		$this->assertEquals( 30, $rows[ 0 ][ 'peak' ] );
	}

	/**
	 * Multiple HAVING entries AND together ( n >= 1 keeps both, peak > 25 keeps only
	 * inactive ).
	 *
	 * @since 3.1.0
	 */
	public function test_having_multiple_entries_and_together() {
		$rows = self::$query->query(
			array(
				'aggregate' => array(
					'n'    => array( 'count', '*' ),
					'peak' => array( 'max', 'priority' ),
				),
				'groupby'   => 'status',
				'having'    => array(
					'n'    => array( '>=', 1 ),
					'peak' => array( '>', 25 ),
				),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'inactive', $rows[ 0 ][ 'status' ] );
	}

	/**
	 * A HAVING on an unknown alias is dropped and logged; the groups still return.
	 *
	 * @since 3.1.0
	 */
	public function test_having_unknown_alias_is_dropped_and_logged() {
		$query = new TestQuery(
			array(
				'aggregate' => array( 'n' => array( 'count', '*' ) ),
				'groupby'   => 'status',
				'having'    => array( 'nonexistent' => array( '>', 1 ) ),
			)
		);

		$this->assertNotEmpty( $query->get_logs( array( 'code' => 'having' ) ) );
		$this->assertCount( 2, $query->items ); // Unfiltered.
	}

	/**
	 * A HAVING with an unsupported operator is dropped and logged.
	 *
	 * @since 3.1.0
	 */
	public function test_having_unsupported_operator_is_dropped_and_logged() {
		$query = new TestQuery(
			array(
				'aggregate' => array( 'n' => array( 'count', '*' ) ),
				'groupby'   => 'status',
				'having'    => array( 'n' => array( 'LIKE', 1 ) ),
			)
		);

		$this->assertNotEmpty( $query->get_logs( array( 'code' => 'having' ) ) );
		$this->assertCount( 2, $query->items ); // Unfiltered.
	}

	/**
	 * An ungrouped aggregate is a single row, so HAVING is ignored.
	 *
	 * @since 3.1.0
	 */
	public function test_ungrouped_aggregate_ignores_having() {
		$result = self::$query->query(
			array(
				'aggregate' => array( 'n' => array( 'count', '*' ) ),
				'having'    => array( 'n' => array( '>', 100 ) ), // Would exclude everything, if applied.
			)
		);

		// Ignored: the flat assoc still reports the full count.
		$this->assertEquals( 3, $result[ 'n' ] );
	}

	/**
	 * HAVING and ORDER BY coexist: filter the groups, then order the survivors ( both
	 * peak >= 20, ordered peak DESC gives inactive (30) before active (20) ).
	 *
	 * @since 3.1.0
	 */
	public function test_having_and_orderby_together() {
		$rows = self::$query->query(
			array(
				'aggregate' => array( 'peak' => array( 'max', 'priority' ) ),
				'groupby'   => 'status',
				'having'    => array( 'peak' => array( '>=', 20 ) ),
				'orderby'   => 'peak',
				'order'     => 'DESC',
			)
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( 'inactive', $rows[ 0 ][ 'status' ] ); // peak = 30
		$this->assertSame( 'active', $rows[ 1 ][ 'status' ] );   // peak = 20
	}

	/**
	 * HAVING filters the OUTER, deduped aggregate over a fan-out JOIN: with the 2x
	 * fan-out deduped, only 'active' has more than one row ( n = 2 vs 1 ), so
	 * `n > 1` keeps it alone - proving HAVING sees the deduped counts, not the
	 * fanned-out ones ( where inactive would also be 2 and survive ).
	 *
	 * @since 3.1.0
	 */
	public function test_having_over_fan_out_join_filters_deduped_counts() {
		$filter   = 'berlindb_database_widgets_query_clauses';
		$callback = static function ( $clauses ) {
			$clauses[ 'join' ] = trim( ( $clauses[ 'join' ] ?? '' ) . ' JOIN ( SELECT 1 UNION ALL SELECT 2 ) AS berlin_fan ON ( 1 = 1 )' );
			return $clauses;
		};

		add_filter( $filter, $callback );

		try {
			$rows = self::$query->query(
				array(
					'aggregate' => array( 'n' => array( 'count', '*' ) ),
					'groupby'   => 'status',
					'having'    => array( 'n' => array( '>', 1 ) ),
				)
			);
		} finally {
			remove_filter( $filter, $callback );
		}

		$this->assertCount( 1, $rows );
		$this->assertSame( 'active', $rows[ 0 ][ 'status' ] );
		$this->assertEquals( 2, $rows[ 0 ][ 'n' ] );
	}
}
