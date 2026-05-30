<?php
/**
 * Coherence tests for get_item_by() and the cache_key column caching path.
 *
 * BerlinDB caches single items looked up by a cache_key column. Secondary
 * (non-primary) lookups cache the matching primary ID using the lookup group's
 * last_changed salt and are populated lazily from the actual query result, so:
 *
 *   1. Stale-after-transition: writes that can affect a cache_key mapping bump
 *      that lookup group's last_changed, so a lookup by an old value re-resolves
 *      from the database instead of returning a stale row.
 *
 *   2. Non-unique collision: the cached entry is the actual WHERE col = value
 *      LIMIT 1 result, so it agrees with a fresh database read by construction.
 *
 * See berlindb/core #203.
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
 * Cache coherence tests for get_item_by().
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
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Insert a widget and return its new ID.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $data Column data to merge over the defaults.
	 * @return int
	 */
	private function add_widget( array $data ): int {
		$query = new TestQuery();

		return (int) $query->add_item(
			array_merge(
				array(
					'name'   => 'Widget',
					'status' => 'active',
				),
				$data
			)
		);
	}

	// -------------------------------------------------------------------------
	// Stale-after-transition.
	// -------------------------------------------------------------------------

	/**
	 * After a row's only matching value changes, a lookup by the OLD value must
	 * not return the row. With zero active rows remaining, the lookup misses.
	 *
	 * @since 3.1.0
	 */
	public function test_lookup_by_old_value_misses_after_transition(): void {
		$query = new TestQuery();
		$id    = $this->add_widget( array( 'status' => 'active' ) );

		$query->get_item_by( 'status', 'active' );          // Warm.
		$query->update_item( $id, array( 'status' => 'inactive' ) );

		$this->assertFalse(
			$query->get_item_by( 'status', 'active' ),
			'A lookup by a pre-transition cache_key value must not return the row.'
		);
	}

	/**
	 * Cached and uncached lookups by the old value must agree after a transition.
	 *
	 * @since 3.1.0
	 */
	public function test_cached_and_uncached_agree_after_transition(): void {
		$query = new TestQuery();
		$id    = $this->add_widget( array( 'status' => 'active' ) );

		$query->get_item_by( 'status', 'active' );
		$query->update_item( $id, array( 'status' => 'inactive' ) );

		$cached = $query->get_item_by( 'status', 'active' );
		wp_cache_flush();
		$fresh = $query->get_item_by( 'status', 'active' );

		$this->assertSame( $fresh, $cached );
	}

	/**
	 * After a transition, a lookup by the NEW value returns the row with its
	 * updated data.
	 *
	 * @since 3.1.0
	 */
	public function test_lookup_by_new_value_returns_row_after_transition(): void {
		$query = new TestQuery();
		$id    = $this->add_widget( array( 'status' => 'active' ) );

		$query->get_item_by( 'status', 'active' );          // Warm the old value.
		$query->update_item( $id, array( 'status' => 'inactive' ) );

		$result = $query->get_item_by( 'status', 'inactive' );

		$this->assertIsObject( $result );
		$this->assertSame( $id, (int) $result->id );
		$this->assertSame( 'inactive', $result->status );
	}

	/**
	 * A write to a NON-cache_key column may leave the secondary lookup warm, but
	 * it must still resolve fresh object data through the primary by-id cache.
	 *
	 * @since 3.1.0
	 */
	public function test_lookup_reflects_update_to_non_cache_key_column(): void {
		$query = new TestQuery();
		$id    = $this->add_widget(
			array(
				'name'   => 'Original',
				'status' => 'active',
			)
		);

		$first = $query->get_item_by( 'status', 'active' );  // Warm with name "Original".
		$this->assertSame( 'Original', $first->name );

		$query->update_item( $id, array( 'name' => 'Renamed' ) );

		$second = $query->get_item_by( 'status', 'active' );
		$this->assertSame(
			'Renamed',
			$second->name,
			'Lookup must reflect the updated name, even when the value-to-ID lookup survives.'
		);
	}

	// -------------------------------------------------------------------------
	// Delete invalidation.
	// -------------------------------------------------------------------------

	/**
	 * After a row is deleted, a lookup by its cache_key value must miss.
	 *
	 * @since 3.1.0
	 */
	public function test_lookup_misses_after_delete(): void {
		$query = new TestQuery();
		$id    = $this->add_widget( array( 'status' => 'active' ) );

		$query->get_item_by( 'status', 'active' );          // Warm.
		$query->delete_item( $id );

		$this->assertFalse(
			$query->get_item_by( 'status', 'active' ),
			'A lookup by a deleted row cache_key value must not return it.'
		);
	}

	/**
	 * Cached and uncached lookups must agree after a delete.
	 *
	 * @since 3.1.0
	 */
	public function test_cached_and_uncached_agree_after_delete(): void {
		$query = new TestQuery();
		$id    = $this->add_widget( array( 'status' => 'active' ) );

		$query->get_item_by( 'status', 'active' );
		$query->delete_item( $id );

		$cached = $query->get_item_by( 'status', 'active' );
		wp_cache_flush();
		$fresh = $query->get_item_by( 'status', 'active' );

		$this->assertSame( $fresh, $cached );
	}

	// -------------------------------------------------------------------------
	// Non-unique collision.
	// -------------------------------------------------------------------------

	/**
	 * get_item_by() is documented for unique-value columns only. This test does
	 * not make non-unique columns a supported feature — it is a regression guard
	 * that the salted, lazily-populated lookup stays coherent with the database
	 * (cached result equals a fresh WHERE ... LIMIT 1 read) instead of silently
	 * returning the last-written row, which is what the pre-#203 cache did.
	 *
	 * @since 3.1.0
	 */
	public function test_non_unique_cached_matches_database(): void {
		$query = new TestQuery();
		$this->add_widget(
			array(
				'name'   => 'Row A',
				'status' => 'active',
			)
		);
		$this->add_widget(
			array(
				'name'   => 'Row B',
				'status' => 'active',
			)
		);

		$cached = $query->get_item_by( 'status', 'active' );
		wp_cache_flush();
		$fresh = $query->get_item_by( 'status', 'active' );

		$this->assertSame( (int) $fresh->id, (int) $cached->id );
	}

	/**
	 * Repeated lookups within the same generation (no intervening write) return
	 * a consistent row.
	 *
	 * @since 3.1.0
	 */
	public function test_repeated_lookups_are_stable_within_a_generation(): void {
		$query = new TestQuery();
		$this->add_widget(
			array(
				'name'   => 'Row A',
				'status' => 'active',
			)
		);
		$this->add_widget(
			array(
				'name'   => 'Row B',
				'status' => 'active',
			)
		);

		$first  = $query->get_item_by( 'status', 'active' );
		$second = $query->get_item_by( 'status', 'active' );

		$this->assertSame( (int) $first->id, (int) $second->id );
	}

	/**
	 * Repeated secondary lookups should use the value-to-ID cache plus the
	 * canonical by-id object cache, without another database read.
	 *
	 * @since 3.1.0
	 */
	public function test_repeated_secondary_lookup_does_not_fire_additional_sql(): void {
		global $wpdb;

		$query = new TestQuery();
		$id    = $this->add_widget(
			array(
				'name'   => 'Cached',
				'status' => 'active',
			)
		);

		$first = $query->get_item_by( 'status', 'active' );
		$this->assertSame( $id, (int) $first->id );

		$queries_before = $wpdb->num_queries;
		$second         = $query->get_item_by( 'status', 'active' );
		$queries_after  = $wpdb->num_queries;

		$this->assertSame( $id, (int) $second->id );
		$this->assertSame( $queries_before, $queries_after );
	}

	// -------------------------------------------------------------------------
	// Distinct values.
	// -------------------------------------------------------------------------

	/**
	 * Distinct cache_key values must not collide: each lookup returns its own row.
	 *
	 * @since 3.1.0
	 */
	public function test_distinct_values_do_not_collide(): void {
		$query       = new TestQuery();
		$id_active   = $this->add_widget(
			array(
				'name'   => 'Active',
				'status' => 'active',
			)
		);
		$id_inactive = $this->add_widget(
			array(
				'name'   => 'Inactive',
				'status' => 'inactive',
			)
		);

		$active   = $query->get_item_by( 'status', 'active' );
		$inactive = $query->get_item_by( 'status', 'inactive' );

		$this->assertSame( $id_active, (int) $active->id );
		$this->assertSame( $id_inactive, (int) $inactive->id );

		// Re-read once warm to confirm the cached entries are still distinct.
		$this->assertSame( $id_active, (int) $query->get_item_by( 'status', 'active' )->id );
		$this->assertSame( $id_inactive, (int) $query->get_item_by( 'status', 'inactive' )->id );
	}

	// -------------------------------------------------------------------------
	// Falsy values.
	// -------------------------------------------------------------------------

	/**
	 * A cache_key value of the string '0' is a valid lookup and must round-trip
	 * through the cache (regression guard for the empty() bail).
	 *
	 * @since 3.1.0
	 */
	public function test_lookup_by_string_zero_value(): void {
		$query = new TestQuery();
		$id    = $this->add_widget(
			array(
				'name'   => 'Zero',
				'status' => '0',
			)
		);

		$first  = $query->get_item_by( 'status', '0' );      // DB → cache.
		$second = $query->get_item_by( 'status', '0' );      // Cache hit.

		$this->assertSame( $id, (int) $first->id );
		$this->assertSame( $id, (int) $second->id );
	}

	// -------------------------------------------------------------------------
	// Non-cache_key and primary columns.
	// -------------------------------------------------------------------------

	/**
	 * A lookup by a non-cache_key column is not cached but must still return the
	 * correct row from the database.
	 *
	 * @since 3.1.0
	 */
	public function test_lookup_by_non_cache_key_column_returns_row(): void {
		$query = new TestQuery();
		$id    = $this->add_widget(
			array(
				'name'     => 'Prioritised',
				'status'   => 'active',
				'priority' => 7,
			)
		);

		$result = $query->get_item_by( 'priority', 7 );

		$this->assertIsObject( $result );
		$this->assertSame( $id, (int) $result->id );
	}

	/**
	 * A lookup by the primary column returns the row through the by-id cache.
	 *
	 * @since 3.1.0
	 */
	public function test_lookup_by_primary_returns_row(): void {
		$query = new TestQuery();
		$id    = $this->add_widget( array( 'name' => 'ByID' ) );

		$first  = $query->get_item_by( 'id', $id );
		$second = $query->get_item_by( 'id', $id );

		$this->assertSame( $id, (int) $first->id );
		$this->assertSame( $id, (int) $second->id );
		$this->assertSame( 'ByID', $second->name );
	}

	/**
	 * A lookup by a value that matches no row returns false.
	 *
	 * @since 3.1.0
	 */
	public function test_lookup_with_no_match_returns_false(): void {
		$query = new TestQuery();
		$this->add_widget( array( 'status' => 'active' ) );

		$this->assertFalse( $query->get_item_by( 'status', 'nonexistent' ) );
	}
}
