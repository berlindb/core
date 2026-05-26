<?php
/**
 * Query cache key and result-caching tests.
 *
 * Verifies that the sentinel-stripping fix in get_cache_key() allows
 * BerlinDB's query-result cache to work correctly across Query instances.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for Query cache key stability and query-result caching.
 *
 * @since 2.1.0
 */
class QueryCacheTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var TestQuery */
	private static $query;

	/**
	 * Install the fixture table and query object before cache tests run.
	 *
	 * @since 2.1.0
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
	 * Uninstall the fixture table after cache tests complete.
	 *
	 * @since 2.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset cache fixture data before each test.
	 *
	 * @since 2.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		/*
		 * parent::setUp() resets the current user to 0 via clean_up_global_scope().
		 * Re-set here so add_item() passes Query::reduce_item() capability checks.
		 */
		wp_set_current_user( 1 );

		self::$table->delete_all();
		self::$query->add_item(
			array(
				'name'   => 'Cache Widget',
				'status' => 'active',
			)
		);
		wp_cache_flush();
	}

	/**
	 * Two separate Query instances with identical arguments must produce the
	 * same cache key. Before the sentinel fix, each instance embedded a
	 * per-instance random_bytes(18) value in the key, making them always differ.
	 *
	 * @since 2.1.0
	 */
	public function test_cache_key_is_stable_across_query_instances() {
		$args = array(
			'number' => 10,
			'status' => 'active',
		);

		$query_a = new TestQuery( $args );
		$query_b = new TestQuery( $args );

		$get_key = new \ReflectionMethod( TestQuery::class, 'get_cache_key' );
		if ( PHP_VERSION_ID < 80100 ) {
			$get_key->setAccessible( true );
		}

		$key_a = $get_key->invoke( $query_a );
		$key_b = $get_key->invoke( $query_b );

		$this->assertSame( $key_a, $key_b );
	}

	/**
	 * A repeated identical query should hit the cache and fire no additional
	 * SQL. If the sentinel fix is absent the second call always misses the
	 * cache because it generates a different key.
	 *
	 * @since 2.1.0
	 */
	public function test_repeated_identical_query_does_not_fire_additional_sql() {
		global $wpdb;

		$args = array(
			'number' => 10,
			'status' => 'active',
		);

		// Prime the cache.
		self::$query->query( $args );

		$queries_before = $wpdb->num_queries;
		self::$query->query( $args );
		$queries_after = $wpdb->num_queries;

		$this->assertSame( $queries_before, $queries_after );
	}

	/**
	 * After deleting an item, re-querying with the same args on the same
	 * instance must reflect the deletion — not return the stale cached result.
	 *
	 * This is the regression case from berlindb/core#160: the old
	 * update_last_changed_cache() guard (`if (empty($this->last_changed))`)
	 * prevented the cache key from advancing after a mutation, so the second
	 * query would hit the now-invalid cache entry and return the deleted item.
	 *
	 * @since 3.0.0
	 */
	public function test_cache_is_invalidated_after_delete() {
		$args = array(
			'number' => 10,
			'status' => 'active',
		);

		// Prime the cache — one item exists.
		$before = self::$query->query( $args );
		$this->assertCount( 1, $before );

		// Delete the only item.
		self::$query->delete_item( $before[0]->id );

		// Re-query: must return empty, not the stale cached item.
		$after = self::$query->query( $args );
		$this->assertCount( 0, $after );
	}
}
