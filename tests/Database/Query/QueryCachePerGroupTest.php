<?php
/**
 * Tests for per-group last_changed invalidation of secondary cache_key lookups.
 *
 * Each secondary cache_key column has its own "{cache_group}-by-{column}" group
 * with an independent last_changed generation. The invalidation matrix is:
 *
 *   | write                       | Query (primary) group | secondary -by-X group |
 *   |-----------------------------|-----------------------|-----------------------|
 *   | update non-cache_key column | rotates               | does NOT rotate       |
 *   | update cache_key column X   | rotates               | rotates (X only)      |
 *   | insert                      | rotates               | rotates (all)         |
 *   | delete                      | rotates               | rotates (all)         |
 *
 * The payoff: a write that cannot affect a column's value→ID mapping leaves that
 * column's get_item_by() lookups warm — yet they still resolve fresh objects,
 * because resolution goes through the by-id cache, which every write refreshes.
 *
 * The TestSchema fixture has one secondary cache_key column ('status') and the
 * primary ('id'), which is enough to exercise every row of the matrix.
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
 * Per-group last_changed invalidation tests.
 *
 * @since 3.1.0
 */
class QueryCachePerGroupTest extends TestCase {

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

	/**
	 * Read the current last_changed generation for a column's cache group.
	 *
	 * Uses reflection because the cache-group and last_changed resolvers are
	 * private. Passing the primary column name yields the Query group's
	 * generation; a secondary column yields that column's "-by-{column}" group.
	 *
	 * @since 3.1.0
	 *
	 * @param TestQuery $query  Query instance.
	 * @param string    $column Column name.
	 * @return string
	 */
	private function generation( TestQuery $query, string $column ): string {
		$group = ( new \ReflectionMethod( $query, 'get_cache_group_for_column' ) )->invoke( $query, $column );

		return (string) ( new \ReflectionMethod( $query, 'get_last_changed_cache' ) )->invoke( $query, $group );
	}

	/**
	 * Return the primary column name for the fixture query.
	 *
	 * @since 3.1.0
	 *
	 * @param TestQuery $query Query instance.
	 * @return string
	 */
	private function primary_column( TestQuery $query ): string {
		return (string) ( new \ReflectionMethod( $query, 'get_primary_column_name' ) )->invoke( $query );
	}

	// -------------------------------------------------------------------------
	// The matrix.
	// -------------------------------------------------------------------------

	/**
	 * Updating a non-cache_key column rotates the Query group but leaves the
	 * secondary 'status' group untouched — the win.
	 *
	 * @since 3.1.0
	 */
	public function test_non_cache_key_update_preserves_secondary_group(): void {
		$query = new TestQuery();
		$id    = $this->add_widget(
			array(
				'name'   => 'Original',
				'status' => 'active',
			)
		);

		$query->get_item_by( 'status', 'active' ); // Warm.

		$status_before = $this->generation( $query, 'status' );
		$query_before  = $this->generation( $query, $this->primary_column( $query ) );

		$query->update_item( $id, array( 'name' => 'Renamed' ) ); // Non-cache_key column.

		$this->assertSame(
			$status_before,
			$this->generation( $query, 'status' ),
			'A non-cache_key update must NOT rotate the status lookup group.'
		);
		$this->assertNotSame(
			$query_before,
			$this->generation( $query, $this->primary_column( $query ) ),
			'Any update must rotate the Query group (result-list cache).'
		);
	}

	/**
	 * Updating the cache_key column rotates its own secondary group.
	 *
	 * @since 3.1.0
	 */
	public function test_cache_key_update_rotates_its_group(): void {
		$query = new TestQuery();
		$id    = $this->add_widget( array( 'status' => 'active' ) );

		$query->get_item_by( 'status', 'active' );
		$before = $this->generation( $query, 'status' );

		$query->update_item( $id, array( 'status' => 'inactive' ) );

		$this->assertNotSame(
			$before,
			$this->generation( $query, 'status' ),
			'Changing the status value must rotate the status lookup group.'
		);
	}

	/**
	 * Inserting a row rotates every secondary group (the new row can become the
	 * first match for any value).
	 *
	 * @since 3.1.0
	 */
	public function test_insert_rotates_secondary_group(): void {
		$query = new TestQuery();
		$this->add_widget( array( 'status' => 'active' ) );

		$query->get_item_by( 'status', 'active' );
		$before = $this->generation( $query, 'status' );

		$this->add_widget( array( 'status' => 'pending' ) );

		$this->assertNotSame(
			$before,
			$this->generation( $query, 'status' ),
			'An insert must rotate the secondary lookup group.'
		);
	}

	/**
	 * Deleting a row rotates every secondary group (its value→ID mappings are
	 * gone).
	 *
	 * @since 3.1.0
	 */
	public function test_delete_rotates_secondary_group(): void {
		$query = new TestQuery();
		$id    = $this->add_widget( array( 'status' => 'active' ) );

		$query->get_item_by( 'status', 'active' );
		$before = $this->generation( $query, 'status' );

		$query->delete_item( $id );

		$this->assertNotSame(
			$before,
			$this->generation( $query, 'status' ),
			'A delete must rotate the secondary lookup group.'
		);
	}

	// -------------------------------------------------------------------------
	// Coherence under the optimization.
	// -------------------------------------------------------------------------

	/**
	 * A warm status lookup that survives an unrelated update must still resolve
	 * the row's FRESH data — the pointer stays valid, but the object is read
	 * through the by-id cache that the update refreshed.
	 *
	 * @since 3.1.0
	 */
	public function test_surviving_lookup_returns_fresh_object(): void {
		$query = new TestQuery();
		$id    = $this->add_widget(
			array(
				'name'   => 'Original',
				'status' => 'active',
			)
		);

		$first = $query->get_item_by( 'status', 'active' );
		$this->assertSame( 'Original', $first->name );

		$query->update_item( $id, array( 'name' => 'Renamed' ) );

		$second = $query->get_item_by( 'status', 'active' );
		$this->assertSame( $id, (int) $second->id );
		$this->assertSame(
			'Renamed',
			$second->name,
			'A surviving (warm) lookup must still return fresh object data.'
		);
	}
}
