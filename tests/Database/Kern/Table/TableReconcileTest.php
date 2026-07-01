<?php
/**
 * Table::reconcile() / snapshot() and the $reconcile auto-upgrade integration.
 *
 * reconcile() diffs the live table against its declared schema and applies the
 * difference, gated on a complete introspection (snapshot()). When $reconcile is
 * opted in, upgrade() runs it for a version bump that has no bespoke callback.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Diff\Snapshot;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Integration tests for Table::reconcile(), snapshot(), and auto-reconcile.
 *
 * @since 3.1.0
 */
class TableReconcileTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/**
	 * Install the shared test table once.
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
	 * snapshot() captures a complete picture of the live table.
	 *
	 * @since 3.1.0
	 */
	public function test_snapshot_returns_a_complete_snapshot() {
		$snapshot = self::$table->snapshot();

		$this->assertInstanceOf( Snapshot::class, $snapshot );
		$this->assertTrue( $snapshot->exists() );
		$this->assertTrue( $snapshot->is_complete() );
	}

	/**
	 * reconcile() is a successful no-op on a table already in sync.
	 *
	 * @since 3.1.0
	 */
	public function test_reconcile_is_a_noop_on_a_clean_table() {
		$this->assertTrue( self::$table->reconcile()->is_successful() );
		$this->assertFalse( self::$table->diverged() );
	}

	/**
	 * reconcile() reapplies a dropped index and brings the table back in sync.
	 *
	 * @since 3.1.0
	 */
	public function test_reconcile_reapplies_a_dropped_index() {
		// Introduce real drift.
		self::$table->drop_index( 'status' );
		$this->assertTrue( self::$table->diverged() );

		// Reconcile re-adds it.
		$this->assertTrue( self::$table->reconcile()->is_successful() );
		$this->assertFalse( self::$table->diverged() );
	}

	/**
	 * reconcile() with no operations is a zero-change no-op (and skips introspection).
	 *
	 * @since 3.1.0
	 */
	public function test_reconcile_with_no_operations_is_a_noop() {
		$result = self::$table->reconcile( array() );

		$this->assertTrue( $result->is_successful() );
		$this->assertSame( 0, $result->changes() );
	}

	/**
	 * reconcile() drops an undeclared index only when 'drop' is in the operations.
	 *
	 * @since 3.1.0
	 */
	public function test_reconcile_drops_only_when_opted_in() {
		// Add an index the declared schema does not have (reads as a "drop").
		self::$table->add_index(
			array(
				'name'    => 'reconcile_extra_idx',
				'columns' => array( 'status' ),
			)
		);

		// The default (add + modify) must not remove it.
		self::$table->reconcile();
		$this->assertTrue( self::$table->index_exists( 'reconcile_extra_idx' ) );
		$this->assertTrue( self::$table->diverged() );

		// Opting into drops removes it and reconciles the table.
		$this->assertTrue( self::$table->reconcile( array( 'add', 'modify', 'drop' ) )->is_successful() );
		$this->assertFalse( self::$table->index_exists( 'reconcile_extra_idx' ) );
		$this->assertFalse( self::$table->diverged() );
	}

	/**
	 * With $reconcile opted in, upgrade() reconciles drift and advances the version.
	 *
	 * The headline Phase-4 path: bump the version with no bespoke callback, and the
	 * structural drift is applied automatically.
	 *
	 * @since 3.1.0
	 */
	public function test_upgrade_auto_reconciles_when_opted_in() {
		$key   = 'berlindb_reconcile_up_version';
		$table = new TestTable(
			array(
				'name'           => 'berlindb_reconcile_up',
				'db_version_key' => $key,
				'upgrades'       => array(), // no bespoke callbacks - take the reconcile path.
				'reconcile'      => true,
			)
		);

		/*
		 * Constructing under the test harness auto-installs at the declared version.
		 * Introduce drift and roll the stored version back so an upgrade is needed.
		 */
		$target = $table->get_version();
		$table->drop_index( 'status' );
		update_option( $key, '0.0.0', true );

		$needs_upgrade = $table->needs_upgrade();
		$result        = $table->upgrade();
		$reconciled    = ! $table->diverged();
		$version_after = $table->get_version();

		// Clean up the scratch table and its version option.
		$table->drop();
		delete_option( $key );

		$this->assertTrue( $needs_upgrade );
		$this->assertTrue( $result );
		$this->assertTrue( $reconciled );
		$this->assertSame( $target, $version_after );
	}

	/**
	 * Without the opt-in, upgrade() leaves structural drift alone (just bumps version).
	 *
	 * @since 3.1.0
	 */
	public function test_upgrade_does_not_reconcile_when_not_opted_in() {
		$key   = 'berlindb_reconcile_off_version';
		$table = new TestTable(
			array(
				'name'           => 'berlindb_reconcile_off',
				'db_version_key' => $key,
				'upgrades'       => array(), // no callbacks, and no reconcile opt-in.
			)
		);

		// Drift, then roll the stored version back so an upgrade is needed.
		$target = $table->get_version();
		$table->drop_index( 'status' );
		update_option( $key, '0.0.0', true );

		$result         = $table->upgrade();
		$still_diverged = $table->diverged();
		$version_after  = $table->get_version();

		// Clean up.
		$table->drop();
		delete_option( $key );

		// Legacy behavior preserved: version advances, drift is NOT touched.
		$this->assertTrue( $result );
		$this->assertTrue( $still_diverged );
		$this->assertSame( $target, $version_after );
	}
}
