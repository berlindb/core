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
	 * An EXPLAIN query is never served from (or stored in) the result cache: it
	 * returns the current optimizer plan, so a repeated EXPLAIN must hit the database
	 * again rather than reuse a prior result.
	 *
	 * @since 3.1.0
	 */
	public function test_explain_query_is_not_cached() {
		global $wpdb;

		$args = array(
			'explain' => true,
			'number'  => 10,
			'status'  => 'active',
		);

		// Run once (a cached query would store its result here).
		self::$query->query( $args );

		// A second identical EXPLAIN must run SQL again, not hit a cache entry.
		$queries_before = $wpdb->num_queries;
		self::$query->query( $args );
		$queries_after = $wpdb->num_queries;

		$this->assertGreaterThan( $queries_before, $queries_after );
	}

	/**
	 * After deleting an item, re-querying with the same args on the same
	 * instance must reflect the deletion - not return the stale cached result.
	 *
	 * This is the regression case from berlindb/core#160: the old
	 * set_last_changed() guard (`if (empty($this->last_changed))`)
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

		// Prime the cache - one item exists.
		$before = self::$query->query( $args );
		$this->assertCount( 1, $before );

		// Delete the only item.
		self::$query->delete_item( $before[0]->id );

		// Re-query: must return empty, not the stale cached item.
		$after = self::$query->query( $args );
		$this->assertCount( 0, $after );
	}

	// -------------------------------------------------------------------------
	// cache_results query var (issue #139).
	// -------------------------------------------------------------------------

	/**
	 * cache_results=false always hits the database, even on repeated calls.
	 *
	 * @since 3.0.0
	 */
	public function test_cache_results_false_always_queries_database() {
		global $wpdb;

		$args = array(
			'number'        => 10,
			'status'        => 'active',
			'cache_results' => false,
		);

		// Prime once.
		self::$query->query( $args );

		$queries_before = $wpdb->num_queries;
		self::$query->query( $args );
		$queries_after = $wpdb->num_queries;

		$this->assertGreaterThan( $queries_before, $queries_after, 'cache_results=false must always hit the database.' );
	}

	/**
	 * cache_results=false must not write to the cache, so a subsequent
	 * cache_results=true query for the same args still fires a DB query.
	 *
	 * @since 3.0.0
	 */
	public function test_cache_results_false_does_not_populate_cache() {
		global $wpdb;

		$args_no_cache   = array(
			'number'        => 10,
			'status'        => 'active',
			'cache_results' => false,
		);
		$args_with_cache = array(
			'number'        => 10,
			'status'        => 'active',
			'cache_results' => true,
		);

		// Run a no-cache query - must not write anything to the cache.
		self::$query->query( $args_no_cache );

		// Now run the equivalent cache-enabled query - cache is cold, so DB hit expected.
		$queries_before = $wpdb->num_queries;
		self::$query->query( $args_with_cache );
		$queries_after = $wpdb->num_queries;

		$this->assertGreaterThan( $queries_before, $queries_after, 'cache_results=false must not populate the cache for subsequent queries.' );
	}

	/**
	 * cache_results=false and cache_results=true must share the same cache key
	 * so the warm-path query benefits from any cache primed by the default path.
	 *
	 * @since 3.0.0
	 */
	public function test_cache_results_excluded_from_cache_key() {
		$args_base = array(
			'number' => 10,
			'status' => 'active',
		);

		$query_cached   = new TestQuery( array_merge( $args_base, array( 'cache_results' => true ) ) );
		$query_uncached = new TestQuery( array_merge( $args_base, array( 'cache_results' => false ) ) );

		$get_key = new \ReflectionMethod( TestQuery::class, 'get_cache_key' );

		$this->assertSame(
			$get_key->invoke( $query_cached ),
			$get_key->invoke( $query_uncached ),
			'cache_results must not segment the cache key.'
		);
	}

	/**
	 * Repeated mutations replace one result entry and preserve warm cache hits.
	 *
	 * @since 3.0.1
	 */
	public function test_invalidated_results_replace_the_same_cache_entry(): void {
		global $wpdb;

		$args    = array(
			'status' => 'active',
			'number' => 10,
		);
		$get_key = new \ReflectionMethod( TestQuery::class, 'get_cache_key' );
		$group   = ( new \ReflectionMethod( TestQuery::class, 'get_cache_group' ) )->invoke( self::$query );
		$query   = new TestQuery( $args );
		$key     = $get_key->invoke( $query );

		for ( $i = 0; $i < 3; $i++ ) {
			$id = self::$query->add_item(
				array(
					'name'   => 'New Widget',
					'status' => 'active',
				)
			);
			$this->assertCount( 2, $query->query( $args ) );
			$this->assertSame( $key, $get_key->invoke( $query ) );

			self::$query->delete_item( $id );
			$this->assertCount( 1, $query->query( $args ) );
			$this->assertSame( $key, $get_key->invoke( $query ) );

			$cached = wp_cache_get( $key, $group );
			$this->assertSame( wp_cache_get( 'last_changed', $group ), $cached['last_changed'] );
			$this->assertCount( 1, $cached['item_ids'] );

			$before = $wpdb->num_queries;
			$this->assertCount( 1, $query->query( $args ) );
			$this->assertSame( $before, $wpdb->num_queries );
		}
	}

	/**
	 * Entries without a matching generation must be replaced, including empties.
	 *
	 * @since 3.0.1
	 */
	public function test_missing_or_stale_generations_are_replaced(): void {
		$args    = array(
			'status' => 'inactive',
			'number' => 10,
		);
		$query   = new TestQuery( $args );
		$get_key = new \ReflectionMethod( TestQuery::class, 'get_cache_key' );
		$group   = ( new \ReflectionMethod( TestQuery::class, 'get_cache_group' ) )->invoke( self::$query );
		$key     = $get_key->invoke( $query );

		foreach ( array( false, 'obsolete' ) as $generation ) {
			$entry = array(
				'item_ids'    => array( 999999 ),
				'found_items' => 1,
			);
			if ( false !== $generation ) {
				$entry['last_changed'] = $generation;
			}
			wp_cache_set( $key, $entry, $group );

			$this->assertSame( array(), $query->query( $args ) );
			$cached = wp_cache_get( $key, $group );
			$this->assertSame( array(), $cached['item_ids'] );
			$this->assertSame( 0, $cached['found_items'] );
			$this->assertSame( wp_cache_get( 'last_changed', $group ), $cached['last_changed'] );
		}
	}

	/**
	 * An invalidation during a database read cannot label old results as current.
	 *
	 * @since 3.0.1
	 */
	public function test_generation_is_captured_before_the_database_read(): void {
		global $wpdb;

		$args    = array(
			'status' => 'active',
			'number' => 10,
		);
		$query   = new TestQuery( $args );
		$get_key = new \ReflectionMethod( TestQuery::class, 'get_cache_key' );
		$group   = ( new \ReflectionMethod( TestQuery::class, 'get_cache_group' ) )->invoke( self::$query );
		$key     = $get_key->invoke( $query );
		wp_cache_delete( $key, $group );
		$before = wp_cache_get( 'last_changed', $group );
		$rotate = static function ( $sql ) use ( $group ) {
			if ( false !== strpos( $sql, 'SELECT' ) && false !== strpos( $sql, 'test_widgets' ) ) {
				wp_cache_set( 'last_changed', 'during-read', $group );
			}
			return $sql;
		};

		add_filter( 'query', $rotate );
		try {
			$query->query( $args );
		} finally {
			remove_filter( 'query', $rotate );
		}

		$cached = wp_cache_get( $key, $group );
		$this->assertSame( $before, $cached['last_changed'] );
		$this->assertSame( 'during-read', wp_cache_get( 'last_changed', $group ) );
		$queries_before = $wpdb->num_queries;
		$this->assertCount( 1, $query->query( $args ) );
		$this->assertGreaterThan( $queries_before, $wpdb->num_queries );
	}
}
