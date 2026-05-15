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

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
		self::$query = new TestQuery();
	}

	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();

		// parent::setUp() resets the current user to 0 via clean_up_global_scope().
		// Re-set here so add_item() passes Query::reduce_item() capability checks.
		wp_set_current_user( 1 );

		self::$table->delete_all();
		self::$query->add_item( array( 'name' => 'Cache Widget', 'status' => 'active' ) );
		wp_cache_flush();
	}

	/**
	 * Two separate Query instances with identical arguments must produce the
	 * same cache key. Before the sentinel fix, each instance embedded a
	 * per-instance random_bytes(18) value in the key, making them always differ.
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
}
