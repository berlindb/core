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

	/**
	 * Install the fixture table and query object before parser tests run.
	 *
	 * @since 3.0.0
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
	 * Uninstall the fixture table after parser tests complete.
	 *
	 * @since 3.0.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset date parser fixture data before each test.
	 *
	 * @since 3.0.0
	 */
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

		$table_name = self::$query->get_table_name();

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
	 * Test the per-column shorthand '{column}_query', where the column is implied
	 * by the var name rather than an explicit 'column' key. This is the form EDD
	 * (date_created_query) and Sugar Calendar (start_query/end_query) use, and it
	 * must produce the same result as the canonical date_query form.
	 *
	 * @since 3.1.0
	 */
	public function test_column_query_shorthand_after_matches_canonical() {

		$shorthand = self::$query->query(
			array(
				'date_created_query' => array(
					'after' => '2022-01-01',
				),
			)
		);

		$canonical = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'after'  => '2022-01-01',
					),
				),
			)
		);

		// Shorthand resolves the column from the var name: same 3 rows.
		$this->assertCount( 3, $shorthand );
		$this->assertSame(
			wp_list_pluck( $canonical, 'id' ),
			wp_list_pluck( $shorthand, 'id' )
		);

		$names = wp_list_pluck( $shorthand, 'name' );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
		$this->assertContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test the '{column}_query' shorthand with an inclusive before range, mirroring
	 * the exact shape Sugar Calendar passes (no explicit 'column').
	 *
	 * @since 3.1.0
	 */
	public function test_column_query_shorthand_before_inclusive() {

		$results = self::$query->query(
			array(
				'date_created_query' => array(
					'inclusive' => true,
					'before'    => '2021-06-01',
				),
			)
		);

		// Inclusive of 2021-06-01: Alpha (2020) and Beta (2021-06-01).
		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
		$this->assertNotContains( 'Gamma Gadget', $names );
	}

	/**
	 * Test that an unknown '{column}_query' (no matching date column) stays inert
	 * rather than bleeding: it resolves no column, so it adds no WHERE clause.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_column_query_shorthand_is_inert() {

		$all = self::$query->query( array() );

		$bogus = self::$query->query(
			array(
				'nonexistent_query' => array(
					'after' => '2022-01-01',
				),
			)
		);

		// No date column resolves, so the clause is dropped and all rows return.
		$this->assertCount( count( $all ), $bogus );
	}

	/**
	 * Test that a date_query naming a column that exists but is NOT a date column
	 * fails closed: it matches no rows, rather than dropping the clause (which
	 * would widen results to the whole table). The column is named on the clause
	 * itself, so it's an unambiguous typo/misuse - not a foreign clause Date swept
	 * up - and is safe to fail closed.
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_non_date_column_fails_closed() {

		// 'name' is a real column on the table, but not a date_query column.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'name',
						'after'  => '2020-01-01',
					),
				),
			)
		);

		// Fail closed: zero rows, not the whole table.
		$this->assertCount( 0, $results );
	}

	/**
	 * Regression: a sibling parser's clause must not bleed into the Date parser.
	 *
	 * With a top-level 'column' default and no date_query, the Date parser used
	 * to receive the full query_vars and recurse into the compare_query clause,
	 * emitting a phantom `date_created = <priority value>` ANDed into the query.
	 * Only Gamma has priority 30 (date_created 2022-03-10, not '30'), so the
	 * phantom date filter would wrongly exclude it. Cross-parser isolation in
	 * parse_join_where_parsers() now strips the sibling container.
	 *
	 * @since 3.1.0
	 */
	public function test_compare_query_does_not_bleed_into_date_column() {

		$results = self::$query->query(
			array(
				'column'        => 'date_created',
				'compare_query' => array(
					array(
						'key'     => 'priority',
						'value'   => 30,
						'compare' => '=',
					),
				),
			)
		);

		// Only the priority comparison applies; Gamma is returned, not excluded.
		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that the '{column}_query' date shorthand still works when a
	 * compare_query is also present - the shorthand (Date's own key) is kept,
	 * the sibling compare_query container is isolated, and both real filters
	 * apply with no bleed.
	 *
	 * @since 3.1.0
	 */
	public function test_column_query_shorthand_coexists_with_compare_query() {

		$results = self::$query->query(
			array(
				'date_created_query' => array(
					'after' => '2022-01-01',
				),
				'compare_query'      => array(
					array(
						'key'     => 'priority',
						'value'   => 25,
						'compare' => '>',
					),
				),
			)
		);

		// after 2022 => Gamma/Delta/Epsilon; priority > 25 => the same three.
		$names = wp_list_pluck( $results, 'name' );
		$this->assertCount( 3, $results );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
		$this->assertContains( 'Epsilon Widget', $names );
	}

	/**
	 * Capture every SQL statement run during the callback, newline-joined.
	 *
	 * @since 3.1.0
	 *
	 * @param callable $callback Code to run while capturing.
	 * @return string
	 */
	private function captured_sql( callable $callback ): string {
		$queries = array();

		$filter = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};

		add_filter( 'query', $filter );
		$callback();
		remove_filter( 'query', $filter );

		return implode( "\n", $queries );
	}

	/**
	 * Run a read with the given date_query and return the captured SQL.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $clause A single date_query clause.
	 * @return string
	 */
	private function date_query_sql( array $clause ): string {
		return $this->captured_sql(
			function () use ( $clause ) {
				self::$query->query(
					array(
						'date_query'    => array( $clause ),
						'cache_results' => false,
					)
				);
			}
		);
	}

	/**
	 * Test that a value-side IS NULL on a date column renders a unary predicate now
	 * that the value branch delegates to the shared engine ( migration slice 1 ). The
	 * unary compare is opted into with `value => null` ( Date is key-driven, so a
	 * `value` key is what makes the clause first-order ); the null value is ignored.
	 *
	 * @since 3.1.0
	 */
	public function test_value_is_null_renders_unary_predicate() {
		$sql = $this->date_query_sql(
			array(
				'column'  => 'date_modified',
				'compare' => 'IS NULL',
				'value'   => null,
			)
		);

		$this->assertMatchesRegularExpression( '/`date_modified`\s+IS NULL/i', $sql );
		$this->assertStringNotContainsString( '1 = 0', $sql );
	}

	/**
	 * Test that IS NOT NULL renders the negated unary predicate.
	 *
	 * @since 3.1.0
	 */
	public function test_value_is_not_null_renders_unary_predicate() {
		$sql = $this->date_query_sql(
			array(
				'column'  => 'date_modified',
				'compare' => 'IS NOT NULL',
				'value'   => null,
			)
		);

		$this->assertMatchesRegularExpression( '/`date_modified`\s+IS NOT NULL/i', $sql );
		$this->assertStringNotContainsString( '1 = 0', $sql );
	}

	/**
	 * Test that a value-side operand spec ( a column reference ) renders a
	 * column-to-column comparison, which the hand-built value branch could not do.
	 *
	 * @since 3.1.0
	 */
	public function test_value_operand_renders_column_reference() {
		$sql = $this->date_query_sql(
			array(
				'column'  => 'date_created',
				'compare' => '<',
				'value'   => array(
					'operand' => 'column',
					'name'    => 'date_modified',
				),
			)
		);

		$this->assertStringContainsString( '`date_created`', $sql );
		$this->assertMatchesRegularExpression( '/`date_created`\s*<\s*`?[\w]*`?\.?`date_modified`/i', $sql );
		$this->assertStringNotContainsString( '1 = 0', $sql );
	}

	/**
	 * Test that a plain scalar value compare still renders exactly as before ( the
	 * migration is byte-identical for the ordinary case ): `date_created` = '<date>'.
	 *
	 * @since 3.1.0
	 */
	public function test_value_scalar_compare_unchanged() {
		$sql = $this->date_query_sql(
			array(
				'column'  => 'date_created',
				'compare' => '=',
				'value'   => '2022-03-10 00:00:00',
			)
		);

		$this->assertStringContainsString( "`date_created` = '2022-03-10 00:00:00'", $sql );
	}

	/**
	 * Test that an empty IN list fails the clause closed ( 1 = 0 ) rather than
	 * dropping the date filter and silently widening results to every row.
	 *
	 * @since 3.1.0
	 */
	public function test_value_empty_in_list_fails_closed() {
		$sql = $this->date_query_sql(
			array(
				'column'  => 'date_created',
				'compare' => 'IN',
				'value'   => array(),
			)
		);

		$this->assertStringContainsString( '1 = 0', $sql );
	}

	/**
	 * Test that a "forget me" falsey value ( null ) is IGNORED, not failed closed:
	 * the value sub-filter is dropped ( matching the date-part keys' contract ), so
	 * the query returns all rows rather than none.
	 *
	 * @since 3.1.0
	 */
	public function test_value_null_is_ignored() {
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'  => 'date_created',
						'value'   => null,
						'compare' => '=',
					),
				),
			)
		);

		$this->assertCount( 5, $results );
	}

	/**
	 * Test that a bare `false` value is also "forget me" ( ignored ), consistent with
	 * how build_numeric_value() drops false for the date-part keys.
	 *
	 * @since 3.1.0
	 */
	public function test_value_false_is_ignored() {
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'  => 'date_created',
						'value'   => false,
						'compare' => '=',
					),
				),
			)
		);

		$this->assertCount( 5, $results );
	}

	/**
	 * Test that 0 is a REAL value ( midnight / 0000 ), not "forget me": it renders a
	 * concrete comparison ( which happens to match no fixture row ).
	 *
	 * @since 3.1.0
	 */
	public function test_value_zero_is_a_real_value() {
		$sql = $this->date_query_sql(
			array(
				'column'  => 'date_created',
				'value'   => 0,
				'compare' => '=',
			)
		);

		$this->assertStringContainsString( "`date_created` = '0'", $sql );
	}

	/**
	 * Test that a date-part query now renders through an allow-listed function operand
	 * ( YEAR( date_created ) = 2023 ) - the numeric value prepared as %d ( unquoted ),
	 * migration slice 3. The existing year/month/dayof* tests prove result parity.
	 *
	 * @since 3.1.0
	 */
	public function test_date_part_renders_via_func_operand() {
		$sql = $this->date_query_sql(
			array(
				'column'  => 'date_created',
				'year'    => 2023,
				'compare' => '=',
			)
		);

		$this->assertMatchesRegularExpression( '/YEAR\([^)]*`date_created`[^)]*\)\s*=\s*2023\b/i', $sql );
		$this->assertStringNotContainsString( "= '2023'", $sql );
	}

	/**
	 * Test that a date-part IN list renders through the operand engine too.
	 *
	 * @since 3.1.0
	 */
	public function test_date_part_in_list_renders() {
		$sql = $this->date_query_sql(
			array(
				'column'  => 'date_created',
				'month'   => array( 1, 12 ),
				'compare' => 'IN',
			)
		);

		$this->assertMatchesRegularExpression( '/MONTH\([^)]*`date_created`[^)]*\)\s+IN\s*\(\s*1,\s*12\s*\)/i', $sql );
	}

	/**
	 * Test that a SCALAR operator with a multi-value date-part array uses only the
	 * FIRST value ( build_numeric_value's `reset()` parity ), not the whole list.
	 *
	 * @since 3.1.0
	 */
	public function test_date_part_scalar_with_multi_value_uses_first() {
		$sql = $this->date_query_sql(
			array(
				'column'  => 'date_created',
				'year'    => array( 2020, 2021 ),
				'compare' => '=',
			)
		);

		$this->assertMatchesRegularExpression( '/YEAR\([^)]*\)\s*=\s*2020\b/i', $sql );
		$this->assertStringNotContainsString( '2021', $sql );
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
	 * Test that inclusive after includes rows on the boundary date.
	 *
	 * @since 3.0.0
	 */
	public function test_inclusive_after_includes_boundary_date() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'    => 'date_created',
						'after'     => '2021-06-01',
						'inclusive' => true,
					),
				),
			)
		);

		$this->assertCount( 4, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Beta Widget', $names );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
		$this->assertContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test that inclusive before includes rows on the boundary date.
	 *
	 * @since 3.0.0
	 */
	public function test_inclusive_before_includes_boundary_date() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'    => 'date_created',
						'before'    => '2021-06-01',
						'inclusive' => true,
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
	 * Test that year BETWEEN filter returns rows from the inclusive year range.
	 *
	 * @since 3.0.0
	 */
	public function test_year_between_filter() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'  => 'date_created',
						'compare' => 'BETWEEN',
						'year'    => array( 2021, 2023 ),
					),
				),
			)
		);

		$this->assertCount( 3, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Beta Widget', $names );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
	}

	/**
	 * Test that year NOT IN filter excludes rows from matching years.
	 *
	 * @since 3.0.0
	 */
	public function test_year_not_in_filter() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'  => 'date_created',
						'compare' => 'NOT IN',
						'year'    => array( 2020, 2024 ),
					),
				),
			)
		);

		$this->assertCount( 3, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Beta Widget', $names );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
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
	 * Test that month IN filter returns rows from matching months.
	 *
	 * @since 3.0.0
	 */
	public function test_month_in_filter() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'  => 'date_created',
						'compare' => 'IN',
						'month'   => array( 1, 12 ),
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
	 * Test that invalid month values return no rows.
	 *
	 * @since 3.0.0
	 */
	public function test_invalid_month_filter_returns_no_rows() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'month'  => 13,
					),
				),
			)
		);

		$this->assertSame( array(), $results );
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
	 * Test that child date clauses inherit the parent column.
	 *
	 * @since 3.0.0
	 */
	public function test_child_date_clause_inherits_parent_column() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					'column' => 'date_created',
					array(
						'after' => '2023-01-01',
					),
				),
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Delta Gadget', $names );
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

	/**
	 * Test that day filter returns rows matching the given day-of-month.
	 *
	 * Only Gamma Gadget falls on the 10th (2022-03-10).
	 *
	 * @since 3.0.0
	 */
	public function test_day_of_month_filter() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'day'    => 10,
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that dayofyear filter returns rows matching the given calendar day.
	 *
	 * 2020-01-15 is the 15th day of the year, matching Alpha Widget only.
	 *
	 * @since 3.0.0
	 */
	public function test_dayofyear_filter() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'    => 'date_created',
						'dayofyear' => 15,
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Alpha Widget', $results[0]->name );
	}

	/**
	 * Test that dayofweek filter returns all rows falling on the given weekday.
	 *
	 * MySQL DAYOFWEEK: 1=Sunday...7=Saturday. Tuesday=3.
	 * Both 2021-06-01 and 2024-12-31 are Tuesdays.
	 *
	 * @since 3.0.0
	 */
	public function test_dayofweek_filter() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'    => 'date_created',
						'dayofweek' => 3,
					),
				),
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Beta Widget', $names );    // 2021-06-01
		$this->assertContains( 'Epsilon Widget', $names ); // 2024-12-31
	}

	/**
	 * Test that monthnum is an accepted alias for month and produces the same results.
	 *
	 * March (monthnum=3) contains only Gamma Gadget (2022-03-10).
	 *
	 * @since 3.0.0
	 */
	public function test_monthnum_alias_filter() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'   => 'date_created',
						'monthnum' => 3,
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that a clause 'value' direct compare matches the correct row.
	 *
	 * Exercises the clause['value'] path in Date::build_clauses_for_column(),
	 * which passes the raw value directly to build_value() without pre-narrowing.
	 *
	 * @since 3.0.0
	 */
	public function test_value_direct_compare() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column'  => 'date_created',
						'compare' => '=',
						'value'   => '2022-03-10 00:00:00',
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that the hour clause filters rows by the hour component of the time.
	 *
	 * Alpha Widget's date_created is updated to 14:00:00 within this test so
	 * there is exactly one row at hour=14.
	 *
	 * @since 3.0.0
	 */
	public function test_hour_filter() {
		global $wpdb;

		// Move Alpha Widget to 14:00:00.
		$wpdb->update(
			self::$query->get_table_name(),
			array( 'date_created' => '2020-01-15 14:00:00' ),
			array( 'id' => $this->ids[0] ),
			array( '%s' ),
			array( '%d' )
		);
		wp_cache_flush();

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'hour'   => 14,
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Alpha Widget', $results[0]->name );
	}

	/**
	 * Test that a week query renders through the WEEK() function operand.
	 *
	 * @since 3.1.0
	 */
	public function test_week_renders_via_week_operand() {
		$sql = $this->date_query_sql(
			array(
				'column' => 'date_created',
				'week'   => 5,
			)
		);

		$this->assertMatchesRegularExpression( '/WEEK\(\s*`?[\w]*`?\.?`date_created`\s*,\s*0\s*\)\s*=\s*5\b/i', $sql );
	}

	/**
	 * Test that a non-default start_of_week shifts the date with DATE_SUB( ..., INTERVAL
	 * n DAY ) inside WEEK() - the interval operand dogfooded by the Date parser.
	 *
	 * @since 3.1.0
	 */
	public function test_week_start_of_week_uses_date_sub_interval() {
		$sql = $this->date_query_sql(
			array(
				'column'        => 'date_created',
				'week'          => 5,
				'start_of_week' => 3,
			)
		);

		$this->assertMatchesRegularExpression( '/WEEK\(\s*DATE_SUB\([^)]*`date_created`[^)]*,\s*INTERVAL 3 DAY\s*\)\s*,\s*0\s*\)/i', $sql );
	}

	/**
	 * Test that ISO day-of-week renders WEEKDAY( col ) + 1 as a ( math ) arithmetic
	 * operand.
	 *
	 * @since 3.1.0
	 */
	public function test_dayofweek_iso_renders_math_operand() {
		$sql = $this->date_query_sql(
			array(
				'column'        => 'date_created',
				'dayofweek_iso' => 3,
			)
		);

		$this->assertMatchesRegularExpression( '/\(\s*WEEKDAY\([^)]*`date_created`[^)]*\)\s*\+\s*1\s*\)\s*=\s*3\b/i', $sql );
	}

	/**
	 * Test that a combined hour + minute time query renders through DATE_FORMAT().
	 *
	 * @since 3.1.0
	 */
	public function test_combined_time_renders_date_format() {
		$sql = $this->date_query_sql(
			array(
				'column' => 'date_created',
				'hour'   => 12,
				'minute' => 30,
			)
		);

		$this->assertStringContainsString( 'DATE_FORMAT(', $sql );
		$this->assertStringContainsString( "'12.30'", $sql );
	}

	/**
	 * Test that combined minute and second clauses filter rows by both time units.
	 *
	 * Beta Widget is updated to 10:30:45 so it is the only row matching
	 * minute=30 AND second=45.
	 *
	 * @since 3.0.0
	 */
	public function test_minute_and_second_filter() {
		global $wpdb;

		// Move Beta Widget to 10:30:45.
		$wpdb->update(
			self::$query->get_table_name(),
			array( 'date_created' => '2021-06-01 10:30:45' ),
			array( 'id' => $this->ids[1] ),
			array( '%s' ),
			array( '%d' )
		);
		wp_cache_flush();

		// Assert expected results.
		$results = self::$query->query(
			array(
				'date_query' => array(
					array(
						'column' => 'date_created',
						'minute' => 30,
						'second' => 45,
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Beta Widget', $results[0]->name );
	}
}
