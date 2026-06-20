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
	 * Reset parser fixture data before each test.
	 *
	 * @since 3.0.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		self::$table->delete_all();
		wp_cache_flush();

		self::$query->add_item(
			array(
				'name'     => 'Alpha Widget',
				'status'   => 'active',
				'priority' => 10,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Beta Widget',
				'status'   => 'active',
				'priority' => 20,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Gamma Gadget',
				'status'   => 'inactive',
				'priority' => 30,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Delta Gadget',
				'status'   => 'inactive',
				'priority' => 40,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Epsilon Widget',
				'status'   => 'pending',
				'priority' => 50,
			)
		);

		wp_cache_flush();
	}

	/**
	 * Test that an equality comparison returns matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_equals_comparison() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'status',
					'value'   => 'active',
					'compare' => '=',
				),
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
	}

	/**
	 * Test that get_query_var_keys() reports the container var and the per-column
	 * shorthand keys derived from column_filter + column_suffix.
	 *
	 * @since 3.1.0
	 */
	public function test_get_query_var_keys_lists_container_and_per_column() {

		// Container parser: Compare reports its container var.
		$compare = new \BerlinDB\Database\Parsers\Compare( array(), self::$query );
		$this->assertContains( 'compare_query', $compare->get_query_var_keys() );

		/*
		 * Per-column parser: In reports a {column}__in shorthand for in-enabled
		 * columns ('status' carries 'in' => true in the fixture schema).
		 */
		$in = new \BerlinDB\Database\Parsers\In( array(), self::$query );
		$this->assertContains( 'status__in', $in->get_query_var_keys() );
	}

	/**
	 * Test that a not-equals comparison excludes matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_not_equals_comparison() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'status',
					'value'   => 'active',
					'compare' => '!=',
				),
			)
		);

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
		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'priority',
					'value'   => 30,
					'compare' => '>',
				),
			)
		);

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
		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'priority',
					'value'   => 20,
					'compare' => '<=',
				),
			)
		);

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
		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'name',
					'value'   => 'Gadget',
					'compare' => 'LIKE',
				),
			)
		);

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
		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'name',
					'value'   => 'Widget',
					'compare' => 'NOT LIKE',
				),
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
	}

	/**
	 * Test that LIKE comparison treats a percent sign as a literal character.
	 *
	 * @since 3.0.0
	 */
	public function test_like_comparison_escapes_literal_percent_sign() {
		self::$query->add_item(
			array(
				'name'     => 'Literal 50% Match',
				'status'   => 'active',
				'priority' => 60,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Literal 50x Match',
				'status'   => 'active',
				'priority' => 70,
			)
		);

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'name',
					'value'   => '50%',
					'compare' => 'LIKE',
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Literal 50% Match', $results[0]->name );
	}

	/**
	 * Test that NOT LIKE comparison treats an underscore as a literal character.
	 *
	 * @since 3.0.0
	 */
	public function test_not_like_comparison_escapes_literal_underscore() {
		self::$query->add_item(
			array(
				'name'     => 'Literal code_1 Match',
				'status'   => 'active',
				'priority' => 60,
			)
		);
		self::$query->add_item(
			array(
				'name'     => 'Literal codeA1 Match',
				'status'   => 'active',
				'priority' => 70,
			)
		);

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'name',
					'value'   => 'code_1',
					'compare' => 'NOT LIKE',
				),
				'orderby'       => 'priority',
				'order'         => 'ASC',
			)
		);

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Literal codeA1 Match', $names );
		$this->assertNotContains( 'Literal code_1 Match', $names );
	}

	/**
	 * Test that omitting compare defaults to equals.
	 *
	 * @since 3.0.0
	 */
	public function test_default_compare_is_equals() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'   => 'status',
					'value' => 'pending',
				),
			)
		);

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
		$count = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'priority',
					'value'   => 30,
					'compare' => '>=',
				),
				'count'         => true,
			)
		);

		$this->assertSame( 3, (int) $count );
	}

	/**
	 * Test that a top-level relation => OR unions two first-order clauses.
	 *
	 * This proves recursion is an engine feature (Traits\Parser::get_sql_for_query),
	 * not a Meta-only one: the Compare parser inherits the same boolean tree.
	 *
	 * @since 3.0.0
	 */
	public function test_relation_or_unions_first_order_clauses() {

		// ( status = 'active' OR priority = 50 ) => Alpha, Beta, Epsilon.
		$results = self::$query->query(
			array(
				'compare_query' => array(
					'relation' => 'OR',
					array(
						'key'   => 'status',
						'value' => 'active',
					),
					array(
						'key'     => 'priority',
						'value'   => 50,
						'compare' => '=',
					),
				),
			)
		);

		$this->assertCount( 3, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
		$this->assertContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test that an AND subgroup nested inside a top-level OR recurses correctly.
	 *
	 * @since 3.0.0
	 */
	public function test_and_subgroup_nested_in_or() {

		/*
		 * ( status = 'pending' OR ( status = 'inactive' AND priority > 30 ) )
		 *   => Epsilon (pending) + Delta (inactive, 40) = 2 rows. Gamma (30) is
		 *   excluded because the inner AND requires priority > 30.
		 */
		$results = self::$query->query(
			array(
				'compare_query' => array(
					'relation' => 'OR',
					array(
						'key'   => 'status',
						'value' => 'pending',
					),
					array(
						'relation' => 'AND',
						array(
							'key'   => 'status',
							'value' => 'inactive',
						),
						array(
							'key'     => 'priority',
							'value'   => 30,
							'compare' => '>',
						),
					),
				),
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Epsilon Widget', $names );
		$this->assertContains( 'Delta Gadget', $names );
		$this->assertNotContains( 'Gamma Gadget', $names );
	}

	/**
	 * Test that an OR subgroup nested inside a top-level AND is properly grouped.
	 *
	 * This is the discriminating case: only correct parenthesization yields the
	 * right rows. ( priority > 30 AND ( status = 'inactive' OR status = 'active' ) )
	 * matches Delta only - Epsilon (50, pending) passes the priority test but fails
	 * the grouped OR; if the OR were not grouped, operator precedence would let the
	 * active rows leak in.
	 *
	 * @since 3.0.0
	 */
	public function test_or_subgroup_nested_in_and_is_grouped() {

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'relation' => 'AND',
					array(
						'key'     => 'priority',
						'value'   => 30,
						'compare' => '>',
					),
					array(
						'relation' => 'OR',
						array(
							'key'   => 'status',
							'value' => 'inactive',
						),
						array(
							'key'   => 'status',
							'value' => 'active',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Delta Gadget', $results[0]->name );
	}

	/**
	 * Test that a comparison against a column that does not exist fails CLOSED:
	 * the clause matches no rows, rather than being silently dropped (a dropped
	 * filter would widen results to the entire table). A requested filter that
	 * cannot be expressed must match nothing.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_column_fails_closed() {

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'   => 'does_not_exist',
					'value' => 'whatever',
				),
			)
		);

		// Fail closed: zero rows, not all five.
		$this->assertCount( 0, $results );
	}

	/**
	 * Test that an unknown column ANDed with a valid clause fails the whole group
	 * closed, rather than leaking the rows matched by the valid sibling.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_column_in_and_fails_closed() {

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'relation' => 'AND',
					array(
						'key'   => 'status',
						'value' => 'active',
					),
					array(
						'key'   => 'nonexistent_column',
						'value' => 'whatever',
					),
				),
			)
		);

		// status = 'active' matches 2, but the bad column ANDs the group to nothing.
		$this->assertCount( 0, $results );
	}

	/**
	 * Test that an unknown column ORed with a valid clause is neutralized: the
	 * never-true branch contributes nothing to an OR, so the valid sibling's rows
	 * are returned unchanged (contrast with AND, which fails the whole group).
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_column_in_or_is_neutralized() {

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'relation' => 'OR',
					array(
						'key'   => 'status',
						'value' => 'active',
					),
					array(
						'key'   => 'nonexistent_column',
						'value' => 'whatever',
					),
				),
			)
		);

		// Only the valid branch contributes: Alpha + Beta.
		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
	}

	/**
	 * Build the WHERE SQL the Compare parser emits for a single clause.
	 *
	 * Drives the parser directly (the first constructor arg is the clause) and
	 * returns its WHERE fragment, so cast wiring can be asserted at the SQL level.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $clause A single compare_query clause.
	 * @return string The emitted WHERE SQL.
	 */
	private function compare_where_sql( array $clause ): string {
		$parser = new \BerlinDB\Database\Parsers\Compare( $clause, self::$query );
		$result = $parser->get_join_where_clauses();

		return $result['where'];
	}

	/**
	 * Test that an explicit string cast wraps the column side in CAST( ... AS X ).
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_string_cast_wraps_column() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'value'   => 25,
				'compare' => '>',
				'cast'    => 'SIGNED',
			)
		);

		$this->assertStringContainsString( 'CAST(', $where );
		$this->assertStringContainsString( 'AS SIGNED)', $where );
	}

	/**
	 * Test that 'cast' => true derives the CAST target from the column's own type
	 * (the unsigned bigint 'priority' column casts to UNSIGNED).
	 *
	 * @since 3.1.0
	 */
	public function test_cast_true_derives_target_from_column() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'value'   => 25,
				'compare' => '>',
				'cast'    => true,
			)
		);

		$this->assertStringContainsString( 'AS UNSIGNED)', $where );
	}

	/**
	 * Test that an absent cast emits no CAST() - casting is opt-in, never default.
	 *
	 * @since 3.1.0
	 */
	public function test_absent_cast_emits_no_cast() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'value'   => 25,
				'compare' => '>',
			)
		);

		$this->assertStringNotContainsString( 'CAST(', $where );
	}

	/**
	 * Test that an explicit but invalid cast fails the clause closed (matches no
	 * rows) rather than silently comparing without a cast - mirroring the
	 * relationship parser's fail-closed behavior.
	 *
	 * @since 3.1.0
	 */
	public function test_invalid_cast_fails_closed() {

		// The emitted SQL is a never-true condition, not an uncast compare.
		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'value'   => 25,
				'compare' => '>',
				'cast'    => 'nonsense',
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
		$this->assertStringNotContainsString( 'priority', $where );

		// And end-to-end: a query with that clause returns no rows.
		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'priority',
					'value'   => 25,
					'compare' => '>',
					'cast'    => 'nonsense',
				),
			)
		);

		$this->assertCount( 0, $results );
	}

	/**
	 * Test that a valid cast leaves results correct on a numeric column - the
	 * cast is additive, not a behavior change for already-numeric comparisons.
	 *
	 * @since 3.1.0
	 */
	public function test_valid_cast_preserves_numeric_results() {

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'priority',
					'value'   => 25,
					'compare' => '>',
					'cast'    => 'SIGNED',
				),
			)
		);

		// priority > 25 -> Gamma (30), Delta (40), Epsilon (50).
		$this->assertCount( 3, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertContains( 'Delta Gadget', $names );
		$this->assertContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test that a column operand renders a column reference on the right-hand
	 * side (column-to-column) rather than a prepared literal.
	 *
	 * @since 3.1.0
	 */
	public function test_column_operand_renders_column_reference() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '>',
				'value'   => array(
					'operand' => 'column',
					'name'    => 'id',
				),
			)
		);

		// Both sides are quoted column references joined by the operator.
		$this->assertStringContainsString( '`priority`', $where );
		$this->assertStringContainsString( '`id`', $where );
		$this->assertStringContainsString( ' > ', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test that a column operand with no explicit compare defaults to equality
	 * (a structured operand is not treated as an IN list).
	 *
	 * @since 3.1.0
	 */
	public function test_column_operand_defaults_to_equality() {

		$where = $this->compare_where_sql(
			array(
				'key'   => 'status',
				'value' => array(
					'operand' => 'column',
					'name'    => 'name',
				),
			)
		);

		$this->assertStringContainsString( '`name`', $where );
		$this->assertStringContainsString( ' = ', $where );
		$this->assertStringNotContainsString( ' IN ', $where );
	}

	/**
	 * Test that an optional cast on a column operand wraps the REFERENCED column.
	 *
	 * @since 3.1.0
	 */
	public function test_column_operand_with_cast_wraps_referenced_column() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '>',
				'value'   => array(
					'operand' => 'column',
					'name'    => 'id',
					'cast'    => 'SIGNED',
				),
			)
		);

		$this->assertStringContainsString( 'CAST(', $where );
		$this->assertStringContainsString( 'AS SIGNED)', $where );
		$this->assertStringContainsString( '`id`', $where );
	}

	/**
	 * Test that an unknown referenced column fails the clause closed (no rows).
	 *
	 * @since 3.1.0
	 */
	public function test_column_operand_unknown_column_fails_closed() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '>',
				'value'   => array(
					'operand' => 'column',
					'name'    => 'nonexistent_column',
				),
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that a column operand on an operator that does not accept expression
	 * operands (e.g. LIKE) fails closed rather than emitting meaningless SQL.
	 *
	 * @since 3.1.0
	 */
	public function test_column_operand_on_non_expression_operator_fails_closed() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'name',
				'compare' => 'LIKE',
				'value'   => array(
					'operand' => 'column',
					'name'    => 'status',
				),
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that an unsupported operand kind (phase 1 supports 'column' only)
	 * fails the clause closed.
	 *
	 * @since 3.1.0
	 */
	public function test_unsupported_operand_kind_fails_closed() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'name',
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'LOWER',
				),
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that a present-but-null operand marker is treated as a (malformed)
	 * operand spec and fails closed, rather than slipping into an IN/scalar
	 * comparison against the marker fields (e.g. from decoded JSON input).
	 *
	 * @since 3.1.0
	 */
	public function test_null_operand_marker_fails_closed() {

		$where = $this->compare_where_sql(
			array(
				'key'   => 'status',
				'value' => array(
					'operand' => null,
					'name'    => 'name',
				),
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
		$this->assertStringNotContainsString( ' IN ', $where );
	}

	/**
	 * Test column-to-column comparison end-to-end: a row whose status equals its
	 * own name matches `status = {column: name}`.
	 *
	 * @since 3.1.0
	 */
	public function test_column_operand_matches_rows_end_to_end() {

		// A row where the two columns are equal (the others all differ).
		self::$query->add_item(
			array(
				'name'     => 'active',
				'status'   => 'active',
				'priority' => 60,
			)
		);
		wp_cache_flush();

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'status',
					'compare' => '=',
					'value'   => array(
						'operand' => 'column',
						'name'    => 'name',
					),
				),
			)
		);

		// Only the row whose name and status are both 'active'.
		$this->assertCount( 1, $results );
		$this->assertSame( 'active', $results[0]->name );
	}

	/**
	 * Test that a function operand wraps a column argument on the right-hand side.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_wraps_column_argument() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'ABS',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'id',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( 'ABS(', $where );
		$this->assertStringContainsString( '`id`', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test that function operands nest (a function argument that is itself a func).
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_nests() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'ABS',
					'args'    => array(
						array(
							'operand' => 'func',
							'name'    => 'ABS',
							'args'    => array(
								array(
									'operand' => 'column',
									'name'    => 'id',
								),
							),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( 'ABS(ABS(', $where );
	}

	/**
	 * Test that a function operand accepts a bare-scalar argument as value sugar.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_bare_scalar_argument() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'name',
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'LOWER',
					'args'    => array( 'JoHn' ),
				),
			)
		);

		$this->assertStringContainsString( 'LOWER(', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test that an unknown function fails the clause closed.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_unknown_function_fails_closed() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'NOT_A_FUNCTION',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'id',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that a function called with the wrong argument count fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_bad_arity_fails_closed() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'ABS',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'id',
						),
						array(
							'operand' => 'column',
							'name'    => 'priority',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that a function argument referencing an unknown column fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_unknown_column_argument_fails_closed() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'ABS',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'nonexistent_column',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that a function argument of an unrecognized operand kind fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_invalid_arg_kind_fails_closed() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'ABS',
					'args'    => array(
						array(
							'operand' => 'bogus_kind',
							'name'    => 'id',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that an explicit value operand renders a prepared literal end-to-end:
	 * `priority = ABS(-30)` matches the row with priority 30.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_value_argument_matches_rows() {

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => 'priority',
					'compare' => '=',
					'value'   => array(
						'operand' => 'func',
						'name'    => 'ABS',
						'args'    => array(
							array(
								'operand' => 'value',
								'value'   => -30,
								'pattern' => '%d',
							),
						),
					),
				),
			)
		);

		// ABS(-30) = 30 -> the Gamma Gadget row.
		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that a function operand on the 'key' (left side) wraps the key column,
	 * with a bare scalar right side prepared with the function's return pattern.
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_func_operand_wraps_key_column() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'LOWER',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'name',
						),
					),
				),
				'compare' => '=',
				'value'   => 'john',
			)
		);

		$this->assertStringContainsString( 'LOWER(', $where );
		$this->assertStringContainsString( '`name`', $where );
		$this->assertStringContainsString( ' = ', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test a function on BOTH sides: LOWER(name) = LOWER('term').
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_func_equals_rhs_func() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'LOWER',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'name',
						),
					),
				),
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'LOWER',
					'args'    => array(
						array(
							'operand' => 'value',
							'value'   => 'ALPHA WIDGET',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( 'LOWER(`', $where );
		$this->assertStringContainsString( ' = LOWER(', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test that a column operand on the 'key' renders a bare column reference
	 * (the regular grammar: any operand spec is valid on either side).
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_column_operand() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'column',
					'name'    => 'status',
				),
				'compare' => '=',
				'value'   => 'active',
			)
		);

		$this->assertStringContainsString( '`status`', $where );
		$this->assertStringContainsString( ' = ', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test a unary operator on a left-hand function operand: LOWER(name) IS NULL.
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_func_operand_is_null() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'LOWER',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'name',
						),
					),
				),
				'compare' => 'IS NULL',
			)
		);

		$this->assertStringContainsString( 'LOWER(', $where );
		$this->assertStringContainsString( 'IS NULL', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test a left-hand function operand end-to-end with a numeric return pattern:
	 * LENGTH(status) = 6 matches the 'active' rows ('active' is 6 characters).
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_func_operand_matches_rows_end_to_end() {

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => array(
						'operand' => 'func',
						'name'    => 'LENGTH',
						'args'    => array(
							array(
								'operand' => 'column',
								'name'    => 'status',
							),
						),
					),
					'compare' => '=',
					'value'   => 6,
				),
			)
		);

		// status 'active' has length 6 -> Alpha + Beta.
		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
	}

	/**
	 * Test that a left-hand function operand pairs with a bare value through the
	 * operator's own value rendering - LOWER(name) LIKE '%x%' (the operator owns
	 * the LIKE wildcards; the operand supplies the left side).
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_func_operand_with_like() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'LOWER',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'name',
						),
					),
				),
				'compare' => 'LIKE',
				'value'   => 'x',
			)
		);

		$this->assertStringContainsString( 'LOWER(', $where );
		$this->assertStringContainsString( 'LIKE', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test a function operand LHS with an IN list: YEAR(date_created) IN (...).
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_func_operand_in_list() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'YEAR',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'date_created',
						),
					),
				),
				'compare' => 'IN',
				'value'   => array( 2023, 2024 ),
			)
		);

		$this->assertStringContainsString( 'YEAR(', $where );
		$this->assertStringContainsString( ' IN ', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test a function operand LHS with a BETWEEN range: LENGTH(name) BETWEEN a AND b.
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_func_operand_between() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'LENGTH',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'name',
						),
					),
				),
				'compare' => 'BETWEEN',
				'value'   => array( 3, 20 ),
			)
		);

		$this->assertStringContainsString( 'LENGTH(', $where );
		$this->assertStringContainsString( 'BETWEEN', $where );
		$this->assertStringContainsString( 'AND', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test an operand LHS + IN end-to-end: LENGTH(status) IN (6, 8) matches the
	 * 'active' (6) and 'inactive' (8) rows.
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_func_operand_in_matches_rows() {

		$results = self::$query->query(
			array(
				'compare_query' => array(
					'key'     => array(
						'operand' => 'func',
						'name'    => 'LENGTH',
						'args'    => array(
							array(
								'operand' => 'column',
								'name'    => 'status',
							),
						),
					),
					'compare' => 'IN',
					'value'   => array( 6, 8 ),
				),
			)
		);

		// 'active' (6): Alpha, Beta; 'inactive' (8): Gamma, Delta. 'pending' (7) excluded.
		$this->assertCount( 4, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Gamma Gadget', $names );
		$this->assertNotContains( 'Epsilon Widget', $names );
	}

	/**
	 * Test that a left-hand function operand over an unknown column fails closed.
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_func_operand_unknown_column_fails_closed() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'LOWER',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'nonexistent_column',
						),
					),
				),
				'compare' => '=',
				'value'   => 'x',
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that a fractional scalar compared against ABS() is not truncated:
	 * `ABS(priority) = 1.5` must keep the 1.5, not collapse to 1 (ABS preserves
	 * its input type, so its return pattern is not an integer placeholder).
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_abs_func_preserves_fractional_value() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'ABS',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'priority',
						),
					),
				),
				'compare' => '=',
				'value'   => 1.5,
			)
		);

		$this->assertStringContainsString( 'ABS(', $where );
		$this->assertStringContainsString( '1.5', $where );
	}

	/**
	 * Test that an unknown function on the 'key' fails the clause closed.
	 *
	 * @since 3.1.0
	 */
	public function test_lhs_unknown_func_fails_closed() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'NOT_A_FUNCTION',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'name',
						),
					),
				),
				'compare' => '=',
				'value'   => 'x',
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that a date function accepts a date/datetime column argument.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_date_function_accepts_date_column() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'YEAR',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'date_created',
						),
					),
				),
				'compare' => '=',
				'value'   => 2024,
			)
		);

		$this->assertStringContainsString( 'YEAR(', $where );
		$this->assertStringContainsString( '`date_created`', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test that a date function rejects a numeric column argument (e.g. YEAR(id))
	 * - schema-informed type validation fails the clause closed.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_date_function_rejects_numeric_column() {

		$where = $this->compare_where_sql(
			array(
				'key'     => array(
					'operand' => 'func',
					'name'    => 'YEAR',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'priority',
						),
					),
				),
				'compare' => '=',
				'value'   => 2024,
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that ABS rejects a non-numeric (string) column argument.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_abs_rejects_non_numeric_column() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'ABS',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'name',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '1 = 0', $where );
	}

	/**
	 * Test that an explicit cast on a function's column argument is honored by the
	 * type check: `ABS(CAST(name AS SIGNED))` is valid even though `name` is a
	 * string column, because the cast makes the effective type numeric.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_arg_cast_satisfies_type_check() {

		$where = $this->compare_where_sql(
			array(
				'key'     => 'priority',
				'compare' => '=',
				'value'   => array(
					'operand' => 'func',
					'name'    => 'ABS',
					'args'    => array(
						array(
							'operand' => 'column',
							'name'    => 'name',
							'cast'    => 'SIGNED',
						),
					),
				),
			)
		);

		// The cast makes the string column numeric, so ABS accepts it.
		$this->assertStringContainsString( 'ABS(CAST(', $where );
		$this->assertStringContainsString( 'AS SIGNED)', $where );
		$this->assertStringNotContainsString( '1 = 0', $where );
	}

	/**
	 * Test that the time functions (HOUR/MINUTE/SECOND) wrap a date column.
	 *
	 * @since 3.1.0
	 */
	public function test_func_operand_time_functions() {

		foreach ( array( 'HOUR', 'MINUTE', 'SECOND' ) as $fn ) {

			$where = $this->compare_where_sql(
				array(
					'key'     => array(
						'operand' => 'func',
						'name'    => $fn,
						'args'    => array(
							array(
								'operand' => 'column',
								'name'    => 'date_created',
							),
						),
					),
					'compare' => '=',
					'value'   => 0,
				)
			);

			$this->assertStringContainsString( $fn . '(', $where );
			$this->assertStringNotContainsString( '1 = 0', $where );
		}
	}
}
