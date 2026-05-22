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

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * TestMetaQuery overrides get_meta_type() to return 'post' so that the Meta
 * parser resolves against the real wp_postmeta table that exists in every WP
 * test environment. Widget rows are then joined against postmeta rows that
 * share the same numeric ID as the widget — wp_postmeta has no FK constraint,
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
 * add_metadata('post', $widget_id, ...) — this stores rows in wp_postmeta
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

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new TestMetaQuery();
	}

	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		self::$table->delete_all();

		// Clean up any lingering postmeta from previous test runs.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'berlindb_test_%'" );

		wp_cache_flush();

		$this->ids[0] = self::$query->add_item( array( 'name' => 'Alpha Widget',   'status' => 'active',   'priority' => 10 ) );
		$this->ids[1] = self::$query->add_item( array( 'name' => 'Beta Widget',    'status' => 'active',   'priority' => 20 ) );
		$this->ids[2] = self::$query->add_item( array( 'name' => 'Gamma Gadget',   'status' => 'inactive', 'priority' => 30 ) );

		// Add metadata using the 'post' type so rows land in wp_postmeta
		// with post_id matching the widget IDs above.
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
		$results = self::$query->query( array(
			'meta_key'   => 'berlindb_test_color',
			'meta_value' => 'red',
		) );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Alpha Widget', $results[0]->name );
	}

	/**
	 * Test that meta_query with exists compare returns rows that have the key.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_exists() {

		// Assert expected results.
		$results = self::$query->query( array(
			'meta_query' => array(
				array(
					'key'     => 'berlindb_test_color',
					'compare' => 'EXISTS',
				),
			),
		) );

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
		$results = self::$query->query( array(
			'meta_query' => array(
				array(
					'key'     => 'berlindb_test_color',
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		// Only Gamma Gadget has no color meta.
		$this->assertCount( 1, $results );
		$this->assertSame( 'Gamma Gadget', $results[0]->name );
	}

	/**
	 * Test that meta_query with a numeric comparison works correctly.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_numeric_comparison() {

		// Assert expected results.
		$results = self::$query->query( array(
			'meta_query' => array(
				array(
					'key'     => 'berlindb_test_score',
					'value'   => 15,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
		) );

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Beta Widget', $names );
		$this->assertContains( 'Gamma Gadget', $names );
	}

	/**
	 * Test that multiple meta clauses with AND relation narrow results.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_and_relation() {

		// Assert expected results.
		$results = self::$query->query( array(
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
		) );

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
		$results = self::$query->query( array(
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
		) );

		$this->assertCount( 2, $results );

		$names = wp_list_pluck( $results, 'name' );
		$this->assertContains( 'Alpha Widget', $names );
		$this->assertContains( 'Beta Widget', $names );
	}

	/**
	 * Test that meta_query with count mode returns the correct count.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_query_with_count_mode() {

		// Assert expected results.
		$count = self::$query->query( array(
			'meta_query' => array(
				array(
					'key'     => 'berlindb_test_color',
					'compare' => 'EXISTS',
				),
			),
			'count' => true,
		) );

		$this->assertSame( 2, (int) $count );
	}
}
