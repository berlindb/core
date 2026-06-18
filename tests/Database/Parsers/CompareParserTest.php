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
	 * matches Delta only — Epsilon (50, pending) passes the priority test but fails
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
}
