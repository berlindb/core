<?php
/**
 * Tests for Table uninstall tombstone and $auto_install flag.
 *
 * Covers the two controls introduced in 3.1.0 to prevent a table that was
 * intentionally uninstalled from being recreated automatically on the next
 * admin page load (berlindb/core discussions #176):
 *
 *   Tombstone  - uninstall() writes a persistent option that is recognised
 *                by is_upgradeable(), causing maybe_upgrade() to bail.
 *                install() clears it so the table can be intentionally
 *                reinstalled later.
 *
 *   $auto_install - when set to false in a subclass, add_hooks() skips the
 *                   admin_init hook entirely, making installation fully
 *                   explicit.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Table;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

// ---------------------------------------------------------------------------
// Fixture: Table subclass with auto_install disabled.
// ---------------------------------------------------------------------------

/**
 * Minimal Table subclass that opts out of automatic installation.
 *
 * No schema is provided so create() bails immediately and the fixture never
 * writes a real table to the database.
 */
class TableAutoInstallDisabledFixture extends Table {
	protected $name         = 'berlindb_no_auto_install_stub';
	protected $auto_install = false;
	protected $version      = '1';
}

// ---------------------------------------------------------------------------
// Test class.
// ---------------------------------------------------------------------------

/**
 * Tests for the uninstall tombstone and $auto_install flag introduced in 3.1.0.
 *
 * @since 3.1.0
 */
class TableUninstallTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var int Number of _create_temporary_tables filter instances removed by bypass. */
	private $bypassed_create_count = 0;

	/** @var int Number of _drop_temporary_tables filter instances removed by bypass. */
	private $bypassed_drop_count = 0;

	/**
	 * Install the fixture table before uninstall tests run.
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
	 * Uninstall the fixture table and clear any leftover tombstone after all
	 * tests in this class complete.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		delete_option( self::$table->get_db_uninstall_key() );
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Ensure a clean tombstone state and a live table before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
		delete_option( self::$table->get_db_uninstall_key() );
		wp_cache_flush();
	}

	// -------------------------------------------------------------------------
	// Helpers (mirrored from TableTest - kept private so there is no coupling).
	// -------------------------------------------------------------------------

	/**
	 * Remove ALL active instances of the WP test-framework query filters that
	 * convert CREATE/DROP TABLE to their TEMPORARY variants.
	 *
	 * @since 3.1.0
	 */
	private function bypass_table_filters(): void {
		$this->bypassed_create_count = 0;
		while ( has_filter( 'query', array( $this, '_create_temporary_tables' ) ) ) {
			remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
			++$this->bypassed_create_count;
		}

		$this->bypassed_drop_count = 0;
		while ( has_filter( 'query', array( $this, '_drop_temporary_tables' ) ) ) {
			remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			++$this->bypassed_drop_count;
		}
	}

	/**
	 * Restore the exact number of filter instances that bypass_table_filters() removed.
	 *
	 * @since 3.1.0
	 */
	private function restore_table_filters(): void {
		for ( $i = 0; $i < $this->bypassed_create_count; $i++ ) {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		}
		for ( $i = 0; $i < $this->bypassed_drop_count; $i++ ) {
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	// -------------------------------------------------------------------------
	// Tombstone - written by uninstall().
	// -------------------------------------------------------------------------

	/**
	 * uninstall() must write the tombstone option to persistent storage.
	 *
	 * @since 3.1.0
	 */
	public function test_uninstall_writes_tombstone(): void {
		$this->bypass_table_filters();
		self::$table->uninstall();
		$tombstone = get_option( self::$table->get_db_uninstall_key() );
		self::$table->install();
		$this->restore_table_filters();

		$this->assertNotEmpty( $tombstone );
	}

	// -------------------------------------------------------------------------
	// Tombstone - blocks maybe_upgrade().
	// -------------------------------------------------------------------------

	/**
	 * is_upgradeable() must return false while the tombstone is set.
	 *
	 * @since 3.1.0
	 */
	public function test_is_upgradeable_returns_false_when_tombstone_set(): void {
		$this->bypass_table_filters();
		self::$table->uninstall();
		$upgradeable = self::$table->is_upgradeable();
		self::$table->install();
		$this->restore_table_filters();

		$this->assertFalse( $upgradeable );
	}

	/**
	 * maybe_upgrade() must not recreate the table after uninstall() - this is
	 * the core regression test for discussions #176.
	 *
	 * @since 3.1.0
	 */
	public function test_maybe_upgrade_does_not_reinstall_after_uninstall(): void {
		$this->bypass_table_filters();
		self::$table->uninstall();

		// Simulate the admin_init auto-install that caused the original loop.
		self::$table->maybe_upgrade();
		$exists = self::$table->exists();

		self::$table->install();
		$this->restore_table_filters();

		$this->assertFalse( $exists, 'maybe_upgrade() must not reinstall a tombstoned table.' );
	}

	// -------------------------------------------------------------------------
	// Tombstone - cleared by install().
	// -------------------------------------------------------------------------

	/**
	 * install() must delete the tombstone option.
	 *
	 * @since 3.1.0
	 */
	public function test_install_clears_tombstone(): void {
		$this->bypass_table_filters();
		self::$table->uninstall();
		self::$table->install();
		$tombstone = get_option( self::$table->get_db_uninstall_key() );
		$this->restore_table_filters();

		$this->assertFalse( $tombstone );
	}

	/**
	 * After uninstall() + install(), the table must exist and be upgradeable again.
	 *
	 * @since 3.1.0
	 */
	public function test_reinstall_restores_table_and_clears_tombstone(): void {
		$this->bypass_table_filters();
		self::$table->uninstall();
		self::$table->install();
		$exists      = self::$table->exists();
		$upgradeable = self::$table->is_upgradeable();
		$this->restore_table_filters();

		$this->assertTrue( $exists );
		$this->assertTrue( $upgradeable );
	}

	// -------------------------------------------------------------------------
	// $auto_install flag.
	// -------------------------------------------------------------------------

	/**
	 * A table with $auto_install = false must not register maybe_upgrade on
	 * the admin_init hook.
	 *
	 * @since 3.1.0
	 */
	public function test_auto_install_false_does_not_register_admin_init_hook(): void {
		$table = new TableAutoInstallDisabledFixture();

		$this->assertFalse(
			has_action( 'admin_init', array( $table, 'maybe_upgrade' ) ),
			'$auto_install = false must not hook maybe_upgrade to admin_init.'
		);
	}

	/**
	 * A table with $auto_install = true (the default) must register
	 * maybe_upgrade on the admin_init hook.
	 *
	 * A fresh instance is constructed so the hook check is immediate and does
	 * not depend on the WP test framework's per-test hook restore cycle.
	 *
	 * @since 3.1.0
	 */
	public function test_auto_install_true_registers_admin_init_hook(): void {
		$table = new TestTable();

		$this->assertNotFalse(
			has_action( 'admin_init', array( $table, 'maybe_upgrade' ) ),
			'$auto_install = true must hook maybe_upgrade to admin_init.'
		);
	}
}
