<?php
/**
 * Meta parser integration tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Parsers\Meta;
use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * TestMetaQuery overrides get_meta_type() to return 'post' so that the Meta
 * parser resolves against the real wp_postmeta table that exists in every WP
 * test environment. Widget rows are then joined against postmeta rows that
 * share the same numeric ID as the widget - wp_postmeta has no FK constraint,
 * so inserting meta for arbitrary object IDs is safe in tests.
 *
 * @since 3.0.0
 */
class TestMetaQuery extends TestQuery {

	/**
	 * Return 'post' so _get_meta_table() resolves to wp_postmeta.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_meta_type() {
		return 'post';
	}
}

/**
 * Tests for the Meta parser via the 'meta_query' / 'meta_key' / 'meta_value'
 * query vars.
 *
 * Three fixture rows are inserted. Metadata is added for them using
 * add_metadata('post', $widget_id, ...) - this stores rows in wp_postmeta
 * keyed by the same numeric ID as the widget. The JOIN produced by the Meta
 * parser then naturally links widget.id = wp_postmeta.post_id.
 *
 * @since 3.0.0
 */
class MetaParserTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestMetaQuery */
	private static $query;

	/** @var int[] */
	private $ids = array();

	/**
	 * Install the fixture table and meta-aware query object before parser tests run.
	 *
	 * @since 3.0.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new TestMetaQuery();
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
	 * Reset parser fixture data and metadata before each test.
	 *
	 * @since 3.0.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		self::$table->delete_all();

		// Clean up any lingering postmeta from previous test runs.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'berlindb_test_%'" );

		wp_cache_flush();

		$this->ids[0] = self::$query->add_item(
			array(
				'name'     => 'Alpha Widget',
				'status'   => 'active',
				'priority' => 10,
			)
		);
		$this->ids[1] = self::$query->add_item(
			array(
				'name'     => 'Beta Widget',
				'status'   => 'active',
				'priority' => 20,
			)
		);
		$this->ids[2] = self::$query->add_item(
			array(
				'name'     => 'Gamma Gadget',
				'status'   => 'inactive',
				'priority' => 30,
			)
		);

		/*
		 * Add metadata using the 'post' type so rows land in wp_postmeta
		 * with post_id matching the widget IDs above.
		 */
		add_metadata( 'post', $this->ids[0], 'berlindb_test_color', 'red' );
		add_metadata( 'post', $this->ids[1], 'berlindb_test_color', 'blue' );
		// Gamma Gadget intentionally has no color meta.

		add_metadata( 'post', $this->ids[0], 'berlindb_test_score', '10' );
		add_metadata( 'post', $this->ids[1], 'berlindb_test_score', '20' );
		add_metadata( 'post', $this->ids[2], 'berlindb_test_score', '30' );

		wp_cache_flush();
	}

	/**
	 * Test that meta_key + meta_value returns only matching rows.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_key_and_value_filter() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'meta_key'   => 'berlindb_test_color',
				'meta_value' => 'red',
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Alpha Widget', $results[0]->name );
	}

	/**
	 * Regression: a meta_query whose key collides with a real column name must
	 * not bleed into the Compare parser.
	 *
	 * 'status' is both a real column on the widgets table and the meta key used
	 * here. Before Compare scoped itself to its own compare_query, it received
	 * the full query_vars (its own var being unset), walked the meta_query
	 * clause, recognized 'status' as a valid column, and emitted a spurious
	 * `widgets.status = 'shipped'` - which excluded the row the meta JOIN had
	 * correctly matched, returning 0 rows instead of 1.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_key_colliding_with_column_does_not_bleed() {
		global $wpdb;

		// setUp() only cleans 'berlindb_test_%' keys; clear our colliding key.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'status'" );

		// Alpha's real column status is 'active'; give it postmeta status 'shipped'.
		add_metadata( 'post', $this->ids[0], 'status', 'shipped' );
		wp_cache_flush();

		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'     => 'status',
						'value'   => 'shipped',
						'compare' => '=',
					),
				),
			)
		);

		// Matched via postmeta only; the column status ('active') must be ignored.
		$this->assertCount( 1, $results );
		$this->assertSame( 'Alpha Widget', $results[0]->name );

		// Cleanup so the colliding key doesn't leak into sibling tests.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'status'" );
	}

	/**
	 * Test that meta_query with exists compare returns rows that have the key.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_exists() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'     => 'berlindb_test_color',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		// Only Alpha and Beta have the color key.
		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
	}

	/**
	 * Test that meta_query with NOT EXISTS returns rows without the key.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_not_exists() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'     => 'berlindb_test_color',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		// Only Gamma Gadget has no color meta.
		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that compare_key LIKE matches meta keys by substring.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_compare_key_like() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'         => 'color',
						'compare_key' => 'LIKE',
						'compare'     => 'EXISTS',
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
	 * Test that compare_key REGEXP honors type_key BINARY (case-sensitive key).
	 *
	 * Locks the bespoke JOIN engine's pre-existing behavior so the shared
	 * get_meta_key_cast() rule can't regress it. Gamma gets a capital-cased key
	 * that a case-insensitive REGEXP would match; BINARY must exclude it.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_query_compare_key_regexp_binary_is_case_sensitive() {
		add_metadata( 'post', $this->ids[2], 'berlindb_test_Color', 'green' );
		wp_cache_flush();

		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'         => '^berlindb_test_color$',
						'compare_key' => 'REGEXP',
						'type_key'    => 'BINARY',
						'compare'     => 'EXISTS',
					),
				),
			)
		);

		$names = wp_list_pluck( $results, 'name' );
		sort( $names );

		// Only the exact lowercase key matches; Gamma's 'berlindb_test_Color' does not.
		$this->assertSame( array( 'Alpha Widget', 'Beta Widget' ), $names );
	}

	/**
	 * Test that compare_key NOT LIKE excludes rows that have matching meta keys.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_compare_key_not_like() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'         => 'color',
						'compare_key' => 'NOT LIKE',
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that compare_key IN accepts an array of meta keys.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_compare_key_in_array() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'         => array(
							'berlindb_test_color',
							'berlindb_test_score',
						),
						'compare_key' => 'IN',
					),
				),
			)
		);

		$names = array_unique( wp_list_pluck( $results, 'name' ) );
		sort( $names );

		$this->assertSame(
			array(
				'Alpha Widget',
				'Beta Widget',
				'Gamma Gadget',
			),
			$names
		);
	}

	/**
	 * Test that compare_key != excludes rows that have the exact key.
	 *
	 * Pins the bespoke negative-key path (NOT EXISTS subquery) that the operator
	 * polarity check drives; only Gamma lacks the color key.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_query_compare_key_not_equal() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'         => 'berlindb_test_color',
						'compare_key' => '!=',
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that compare_key NOT IN excludes rows holding any listed key.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_query_compare_key_not_in() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'         => array( 'berlindb_test_color' ),
						'compare_key' => 'NOT IN',
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that compare_key NOT REGEXP excludes rows whose keys match the pattern.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_query_compare_key_not_regexp() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'         => 'color',
						'compare_key' => 'NOT REGEXP',
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that a unary compare (IS NULL) is not built by the meta engine yet,
	 * falling back to '=' rather than silently no-op'ing (#211).
	 *
	 * @since 3.1.0
	 */
	public function test_meta_query_is_null_value_falls_back_to_equals() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'     => 'berlindb_test_color',
						'value'   => 'red',
						'compare' => 'IS NULL',
					),
				),
			)
		);

		// Treated as `meta_value = 'red'`, so only Alpha (color red) matches.
		$this->assertCount( 1, $results );
		$this->assertSame( 'Alpha Widget', $results[0]->name );
	}

	/**
	 * Test that meta_query with a numeric comparison works correctly.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_numeric_comparison() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'     => 'berlindb_test_score',
						'value'   => 15,
						'compare' => '>',
						'type'    => 'NUMERIC',
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
	 * Test that an invalid compare falls back to equality.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_invalid_compare_falls_back_to_equals() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'     => 'berlindb_test_score',
						'value'   => '20',
						'compare' => 'BOGUS',
					),
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Beta Widget', $results[0]->name );
	}

	/**
	 * Test that multiple meta clauses with AND relation narrow results.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_and_relation() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => 'berlindb_test_color',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'berlindb_test_score',
						'value'   => 15,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		// Only Beta Widget has color AND score > 15.
		$this->assertCount( 1, $results );
		$this->assertSame( 'Beta Widget', $results[0]->name );
	}

	/**
	 * Test that multiple meta clauses with OR relation broaden results.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_or_relation() {

		// Assert expected results.
		$results = self::$query->query(
			array(
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => 'berlindb_test_color',
						'value' => 'red',
					),
					array(
						'key'   => 'berlindb_test_color',
						'value' => 'blue',
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
	 * Test that an OR relation can combine an equality clause with NOT EXISTS.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_or_relation_with_not_exists() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => 'berlindb_test_color',
						'value' => 'red',
					),
					array(
						'key'     => 'berlindb_test_color',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Gamma Gadget', $names );
	}

	/**
	 * Test that meta_query with count mode returns the correct count.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_with_count_mode() {

		// Assert expected results.
		$count = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'     => 'berlindb_test_color',
						'compare' => 'EXISTS',
					),
				),
				'count'      => true,
			)
		);

		$this->assertSame( 2, (int) $count );
	}

	/**
	 * Test that orderby=meta_value sorts alphabetically ascending.
	 *
	 * @since 3.0.0
	 */
	public function test_orderby_meta_value_asc() {
		$results = self::$query->query(
			array(
				'meta_key' => 'berlindb_test_color',
				'orderby'  => 'meta_value',
				'order'    => 'ASC',
			)
		);

		/*
		 * Only Alpha (red) and Beta (blue) have color meta.
		 * Alphabetical ASC: 'blue' < 'red' -> Beta first.
		 */
		$this->assertCount( 2, $results );
		$this->assertSame( 'Beta Widget', $results[0]->name );
		$this->assertSame( 'Alpha Widget', $results[1]->name );
	}

	/**
	 * Test that orderby=meta_value sorts alphabetically descending.
	 *
	 * @since 3.0.0
	 */
	public function test_orderby_meta_value_desc() {
		$results = self::$query->query(
			array(
				'meta_key' => 'berlindb_test_color',
				'orderby'  => 'meta_value',
				'order'    => 'DESC',
			)
		);

		// Alphabetical DESC: 'red' > 'blue' -> Alpha first.
		$this->assertCount( 2, $results );
		$this->assertSame( 'Alpha Widget', $results[0]->name );
		$this->assertSame( 'Beta Widget', $results[1]->name );
	}

	/**
	 * Test that orderby=meta_value_num sorts numerically, not lexically.
	 *
	 * Uses rank values '2', '10', '20': string sort gives '10', '2', '20'
	 * but numeric sort gives 2, 10, 20 - proving CAST(AS SIGNED) is applied.
	 *
	 * @since 3.0.0
	 */
	public function test_orderby_meta_value_num_asc() {
		add_metadata( 'post', $this->ids[0], 'berlindb_test_rank', '2'  );
		add_metadata( 'post', $this->ids[1], 'berlindb_test_rank', '10' );
		add_metadata( 'post', $this->ids[2], 'berlindb_test_rank', '20' );

		$results = self::$query->query(
			array(
				'meta_key' => 'berlindb_test_rank',
				'orderby'  => 'meta_value_num',
				'order'    => 'ASC',
			)
		);

		/*
		 * Numeric ASC: 2, 10, 20 -> Alpha, Beta, Gamma.
		 * String ASC would give: '10', '2', '20' -> Beta, Alpha, Gamma.
		 */
		$this->assertCount( 3, $results );
		$this->assertSame( 'Alpha Widget', $results[0]->name );
		$this->assertSame( 'Beta Widget', $results[1]->name );
		$this->assertSame( 'Gamma Gadget', $results[2]->name );
	}

	/**
	 * Test that a named meta_query clause key can be used as an orderby value.
	 *
	 * Orders DESC on purpose: scores 10/20/30 descend to Gamma, Beta, Alpha -
	 * the REVERSE of insertion/id order, so this fails if the named clause is
	 * dropped and rows fall back to the default primary-key order.
	 *
	 * @since 3.0.0
	 */
	public function test_orderby_named_clause_key_desc() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					'score_clause' => array(
						'key' => 'berlindb_test_score',
					),
				),
				'orderby'    => 'score_clause',
				'order'      => 'DESC',
			)
		);

		// All three rows have a score (10, 20, 30). DESC -> Gamma, Beta, Alpha.
		$this->assertCount( 3, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
		$this->assertSame( 'Beta Widget', $results[1]->name );
		$this->assertSame( 'Alpha Widget', $results[2]->name );
	}

	/**
	 * A NAMED (string-keyed) meta_query clause must FILTER, like a positional one.
	 *
	 * Regression test: the named clause was previously dropped before SQL was
	 * built (mistaken for flat meta_* vars), so it neither filtered nor sorted -
	 * the query returned every row. score >= 20 (NUMERIC) keeps Beta (20) and
	 * Gamma (30) but not Alpha (10).
	 *
	 * @since 3.1.0
	 */
	public function test_named_meta_query_clause_filters() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					'score_clause' => array(
						'key'     => 'berlindb_test_score',
						'value'   => '20',
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$names = wp_list_pluck( $results, 'name' );
		sort( $names );

		$this->assertSame( array( 'Beta Widget', 'Gamma Gadget' ), $names );
	}

	/**
	 * A bare first-order associative meta_query ( the array IS one clause, with
	 * no [0], 'relation', or named wrapper ) must filter on the bespoke path.
	 *
	 * Regression test ( companion to the store-backed coverage ): like a named
	 * clause, this shape was mistaken for flat meta_* vars and dropped. color
	 * 'red' matches Alpha only.
	 *
	 * @since 3.1.0
	 */
	public function test_bare_first_order_meta_query_filters() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					'key'   => 'berlindb_test_color',
					'value' => 'red',
				),
			)
		);

		$this->assertCount( 1, $results );
		$this->assertSame( 'Alpha Widget', $results[0]->name );
	}

	/**
	 * Two named meta_query clauses can both drive a multi-column orderby.
	 *
	 * grp ties Alpha & Beta (both 1); the secondary score key breaks that tie.
	 * Confirms parse_orderby() resolves every meta token in an array orderby,
	 * each against its own JOIN alias.
	 *
	 * @since 3.1.0
	 */
	public function test_orderby_multiple_named_clauses() {

		// Group Alpha & Beta together (1); Gamma alone (2).
		add_metadata( 'post', $this->ids[0], 'berlindb_test_grp', '1' );
		add_metadata( 'post', $this->ids[1], 'berlindb_test_grp', '1' );
		add_metadata( 'post', $this->ids[2], 'berlindb_test_grp', '2' );

		$results = self::$query->query(
			array(
				'meta_query' => array(
					'grp_clause'   => array(
						'key'  => 'berlindb_test_grp',
						'type' => 'NUMERIC',
					),
					'score_clause' => array(
						'key'  => 'berlindb_test_score',
						'type' => 'NUMERIC',
					),
				),
				'orderby'    => array(
					'grp_clause'   => 'ASC',
					'score_clause' => 'DESC',
				),
			)
		);

		// grp ASC: 1 ( Alpha, Beta ), 2 ( Gamma ). Within grp 1, score DESC: 20 > 10.
		$this->assertCount( 3, $results );
		$this->assertSame( 'Beta Widget', $results[0]->name );
		$this->assertSame( 'Alpha Widget', $results[1]->name );
		$this->assertSame( 'Gamma Gadget', $results[2]->name );
	}

	/**
	 * A meta clause and a real sortable column can be mixed in one orderby.
	 *
	 * The meta-driven JOIN-alias ORDER BY expression and the plain column
	 * expression must coexist; the column ( name DESC ) breaks the grp tie.
	 *
	 * @since 3.1.0
	 */
	public function test_orderby_meta_clause_then_column() {

		// Group Alpha & Beta together (1); Gamma alone (2).
		add_metadata( 'post', $this->ids[0], 'berlindb_test_grp', '1' );
		add_metadata( 'post', $this->ids[1], 'berlindb_test_grp', '1' );
		add_metadata( 'post', $this->ids[2], 'berlindb_test_grp', '2' );

		$results = self::$query->query(
			array(
				'meta_query' => array(
					'grp_clause' => array(
						'key'  => 'berlindb_test_grp',
						'type' => 'NUMERIC',
					),
				),
				'orderby'    => array(
					'grp_clause' => 'ASC',
					'name'       => 'DESC',
				),
			)
		);

		// grp ASC: 1 ( Alpha, Beta ), 2 ( Gamma ). Within grp 1, name DESC: Beta > Alpha.
		$this->assertCount( 3, $results );
		$this->assertSame( 'Beta Widget', $results[0]->name );
		$this->assertSame( 'Alpha Widget', $results[1]->name );
		$this->assertSame( 'Gamma Gadget', $results[2]->name );
	}

	/**
	 * Test that meta_query with an array value and IN compare returns matching rows.
	 *
	 * Exercises the clause['value'] path in Meta::build_clause_sql() now that
	 * build_value() handles array normalization directly. Scores 10 and 30
	 * belong to Alpha Widget and Gamma Gadget respectively.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_value_in_array() {
		$results = self::$query->query(
			array(
				'meta_query' => array(
					array(
						'key'     => 'berlindb_test_score',
						'value'   => array( '10', '30' ),
						'compare' => 'IN',
					),
				),
			)
		);

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Gamma Gadget', $names );
	}

	// get_cast_for_type() - meta_query's own cast vocabulary.

	/**
	 * Test that get_cast_for_type() accepts supported MySQL cast targets.
	 *
	 * @since 3.0.0
	 *
	 * @dataProvider valid_cast_type_provider
	 *
	 * @param string $type     Input cast type.
	 * @param string $expected Expected normalized cast type.
	 */
	public function test_get_cast_for_type_accepts_supported_types( string $type, string $expected ) {
		$this->assertSame( $expected, ( new Meta() )->get_cast_for_type( $type ) );
	}

	/**
	 * Test that get_cast_for_type() falls back to CHAR for empty or unsupported
	 * types (CHAR is Meta's "no cast" sentinel).
	 *
	 * @since 3.0.0
	 *
	 * @dataProvider invalid_cast_type_provider
	 *
	 * @param string $type Unsupported cast type.
	 */
	public function test_get_cast_for_type_falls_back_to_char( string $type ) {
		$this->assertSame( 'CHAR', ( new Meta() )->get_cast_for_type( $type ) );
	}

	/**
	 * Valid cast type provider.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, array{string, string}>
	 */
	public function valid_cast_type_provider(): array {
		return array(
			'binary'            => array( 'binary', 'BINARY' ),
			'char'              => array( 'char', 'CHAR' ),
			'date'              => array( 'date', 'DATE' ),
			'datetime'          => array( 'datetime', 'DATETIME' ),
			'signed'            => array( 'signed', 'SIGNED' ),
			'unsigned'          => array( 'unsigned', 'UNSIGNED' ),
			'time'              => array( 'time', 'TIME' ),
			'numeric alias'     => array( 'numeric', 'SIGNED' ),
			'numeric precision' => array( 'numeric(10, 2)', 'NUMERIC(10, 2)' ),
			'decimal precision' => array( 'decimal(10,2)', 'DECIMAL(10,2)' ),
		);
	}

	/**
	 * Invalid cast type provider.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_cast_type_provider(): array {
		return array(
			'empty'        => array( '' ),
			'varchar'      => array( 'varchar' ),
			'sql fragment' => array( 'SIGNED) UNSIGNED' ),
		);
	}

	/**
	 * Test that get_opposite_operator() resolves a negative operator to its
	 * positive opposite through the shared registry.
	 *
	 * The resolver returns the live, registered instance (NOT a throwaway
	 * `new In()`), so it honors any operator-class filtering.
	 *
	 * @since 3.1.0
	 */
	public function test_get_opposite_operator_resolves_through_registry() {
		$parser  = new Meta();
		$resolve = new \ReflectionMethod( $parser, 'get_opposite_operator' );
		$resolve->setAccessible( true );

		$opposite = $resolve->invoke( $parser, new \BerlinDB\Database\Operators\NotIn() );

		$this->assertInstanceOf( \BerlinDB\Database\Operators\In::class, $opposite );
		$this->assertSame( 'IN', $opposite->compare );
	}

	/**
	 * Test that get_opposite_operator() returns false when no opposite exists.
	 *
	 * RLIKE is a REGEXP synonym with no distinct negation class.
	 *
	 * @since 3.1.0
	 */
	public function test_get_opposite_operator_returns_false_without_opposite() {
		$parser  = new Meta();
		$resolve = new \ReflectionMethod( $parser, 'get_opposite_operator' );
		$resolve->setAccessible( true );

		$this->assertFalse( $resolve->invoke( $parser, new \BerlinDB\Database\Operators\Rlike() ) );
	}
}
