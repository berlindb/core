<?php
/**
 * Tests exposing cache incoherence in get_item_by() when a secondary
 * cache_key column is used as the cache key.
 *
 * BerlinDB caches the full item object under the *value* of every
 * cache_key column (group "{cache_group}-by-{name}", key = column value).
 * Two defects fall out of this:
 *
 *   1. Stale-after-transition (FIXED): update_item() now invalidates the
 *      pre-update object's cache_key slots before re-warming, so a lookup by an
 *      old value no longer returns the stale row. Covered by the first two tests.
 *
 *   2. Non-unique collision (OPEN, berlindb/core #203): when several rows share
 *      a value, they all write to one slot, so the cached answer disagrees with
 *      a fresh DB read. Cache invalidation cannot fix this — it requires
 *      restricting cache_key to unique columns. The third test is skipped until
 *      that fix lands.
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
 * Cache-by-value coherence tests for get_item_by().
 *
 * @since 3.1.0
 */
class QueryCacheKeyByValueTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/**
	 * Install the fixture table before these tests run.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
	}

	/**
	 * Uninstall the fixture table after these tests complete.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset fixture state before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
		self::$table->delete_all();
		wp_cache_flush();
	}

	// -------------------------------------------------------------------------
	// Defect 1: stale slot after a cache_key value transition.
	// -------------------------------------------------------------------------

	/**
	 * After a row's status changes from 'active' to 'inactive', a lookup by the
	 * OLD value must not return the row. With zero active rows in the table,
	 * get_item_by( 'status', 'active' ) must return false.
	 *
	 * Currently fails: the 'active' cache slot is never invalidated on update,
	 * so the stale row (now inactive) is returned from cache.
	 *
	 * @since 3.1.0
	 */
	public function test_get_item_by_old_value_is_not_stale_after_transition(): void {
		$query = new TestQuery();

		$id = $query->add_item(
			array(
				'name'   => 'Widget',
				'status' => 'active',
			)
		);

		// Warm the by-status cache for 'active'.
		$query->get_item_by( 'status', 'active' );

		// Transition the only active row to inactive.
		$query->update_item( $id, array( 'status' => 'inactive' ) );

		// No active rows remain — the old-value lookup must miss.
		$result = $query->get_item_by( 'status', 'active' );

		$this->assertFalse(
			$result,
			'get_item_by() must not return a row by its pre-transition cache_key value.'
		);
	}

	/**
	 * The same invariant proven against the database: a fresh, uncached lookup
	 * by the old value returns false, so the cached lookup must agree.
	 *
	 * @since 3.1.0
	 */
	public function test_cached_and_uncached_agree_after_transition(): void {
		$query = new TestQuery();

		$id = $query->add_item(
			array(
				'name'   => 'Widget',
				'status' => 'active',
			)
		);
		$query->get_item_by( 'status', 'active' );
		$query->update_item( $id, array( 'status' => 'inactive' ) );

		// Cached answer (cache may still hold the stale 'active' slot).
		$cached = $query->get_item_by( 'status', 'active' );

		// Uncached answer (forced fresh read).
		wp_cache_flush();
		$fresh = $query->get_item_by( 'status', 'active' );

		$this->assertSame(
			$fresh,
			$cached,
			'Cached and uncached lookups by the same value must return the same result.'
		);
	}

	// -------------------------------------------------------------------------
	// Defect 2: non-unique cache_key collision.
	// -------------------------------------------------------------------------

	/**
	 * When two rows share a status value, the cached single-item lookup must
	 * agree with a fresh database read. Currently the cache holds only the
	 * last-written row, which disagrees with the DB's LIMIT 1 ordering.
	 *
	 * @since 3.1.0
	 */
	public function test_non_unique_cache_key_cached_matches_database(): void {
		$this->markTestSkipped(
			'Defect 2 (non-unique cache_key collision) is unfixed — see berlindb/core #203. '
			. 'Cache invalidation cannot resolve it; it requires restricting cache_key to unique columns. '
			. 'Remove this skip when that fix lands.'
		);

		$query = new TestQuery();

		$query->add_item(
			array(
				'name'   => 'Row A',
				'status' => 'active',
			)
		);
		$query->add_item(
			array(
				'name'   => 'Row B',
				'status' => 'active',
			)
		);

		// Cached answer (both add_item() calls warmed the single 'active' slot).
		$cached = $query->get_item_by( 'status', 'active' );

		// Uncached answer (forced fresh read).
		wp_cache_flush();
		$fresh = $query->get_item_by( 'status', 'active' );

		$this->assertSame(
			(int) ( $fresh->id ?? 0 ),
			(int) ( $cached->id ?? 0 ),
			'Cached single-item lookup must return the same row as a fresh database read.'
		);
	}
}
