<?php
/**
 * Schema::snapshot() factory integration tests.
 *
 * snapshot() introspects a live table into a Snapshot carrying the completeness
 * signal. These tests issue live SHOW queries against the WordPress test database,
 * grounded in the well-known structure of wp_posts (which always exists and has
 * only ordinary, representable indexes).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Diff\Snapshot;
use BerlinDB\Database\Kern\Schema;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Integration tests for Schema::snapshot().
 *
 * @since 3.1.0
 */
class SchemaSnapshotTest extends TestCase {

	/**
	 * snapshot() returns a Snapshot instance.
	 *
	 * @since 3.1.0
	 */
	public function test_returns_a_snapshot() {
		global $wpdb;

		$this->assertInstanceOf( Snapshot::class, Schema::snapshot( $wpdb->posts ) );
	}

	/**
	 * A real table is captured complete: exists, indexes complete, has columns.
	 *
	 * @since 3.1.0
	 */
	public function test_real_table_is_complete() {
		global $wpdb;

		$snapshot = Schema::snapshot( $wpdb->posts );

		$this->assertTrue( $snapshot->exists() );
		$this->assertTrue( $snapshot->indexes_complete() );
		$this->assertTrue( $snapshot->is_complete() );
		$this->assertNotEmpty( $snapshot->schema()->columns );
	}

	/**
	 * A real table's indexes are actually introspected (not an empty-but-complete).
	 *
	 * wp_posts carries a PRIMARY key plus several ordinary indexes, all representable.
	 *
	 * @since 3.1.0
	 */
	public function test_real_table_captures_indexes() {
		global $wpdb;

		$snapshot = Schema::snapshot( $wpdb->posts );

		$this->assertNotEmpty( $snapshot->schema()->indexes );
	}

	/**
	 * A nonexistent table is captured as not-found and not-complete.
	 *
	 * @since 3.1.0
	 */
	public function test_missing_table_is_not_found() {
		global $wpdb;

		$snapshot = Schema::snapshot( $wpdb->prefix . 'does_not_exist_berlin_test' );

		$this->assertFalse( $snapshot->exists() );
		$this->assertFalse( $snapshot->is_complete() );
		$this->assertEmpty( $snapshot->schema()->columns );
	}

	/**
	 * An empty table name is captured as not-found.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_table_name_is_not_found() {
		$snapshot = Schema::snapshot( '' );

		$this->assertFalse( $snapshot->exists() );
		$this->assertFalse( $snapshot->is_complete() );
	}

	/**
	 * from_table() returns exactly snapshot()->schema() (the BC wrapper holds).
	 *
	 * @since 3.1.0
	 */
	public function test_from_table_matches_snapshot_schema() {
		global $wpdb;

		$from_table = Schema::from_table( $wpdb->posts );
		$snapshot   = Schema::snapshot( $wpdb->posts )->schema();

		$this->assertSame(
			array_keys( $from_table->columns ),
			array_keys( $snapshot->columns )
		);
	}
}
