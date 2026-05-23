<?php
/**
 * Table class tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for BerlinDB\Database\Table.
 *
 * Requires a live database. TestTable uses table name 'berlindb_test_widgets'
 * to avoid conflicts with real WordPress tables.
 *
 * Because Table::is_testing() detects WP_TESTS_DIR, constructing a TestTable
 * automatically calls maybe_upgrade() → install() on first run.
 *
 * @since 2.1.0
 */
class TableTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var int Number of _create_temporary_tables filter instances removed by bypass. */
	private $bypassed_create_count = 0;

	/** @var int Number of _drop_temporary_tables filter instances removed by bypass. */
	private $bypassed_drop_count = 0;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();

		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
	}

	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();

		/*
		 * parent::setUp() calls clean_up_global_scope() which resets the current
		 * user to 0. Re-set here so reduce_item() passes capability checks.
		 */
		wp_set_current_user( 1 );

		/*
		 * Do NOT attempt to reinstall here. The WP test framework's
		 * _create_temporary_tables filter may be added multiple times across test
		 * runs (if tearDown doesn't drain every instance), and calling install()
		 * while any instance is still active would produce a spurious
		 * "CREATE TEMPORARY TABLE … already exists" error. Tests that drop or
		 * uninstall the table handle their own reinstall via bypass_table_filters().
		 */
		self::$table->delete_all();
		wp_cache_flush();
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Remove ALL active instances of the WP test-framework query filters that
	 * convert CREATE/DROP TABLE to their TEMPORARY variants, and record the
	 * count so restore_table_filters() can put them back exactly.
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
	// Existence.
	// -------------------------------------------------------------------------

	/**
	 * Test that the table exists after it has been installed.
	 *
	 * @since 2.1.0
	 */
	public function test_table_exists_after_install() {
		$this->assertTrue( self::$table->exists() );
	}

	/**
	 * Test that needs_upgrade returns false when the stored version is current.
	 *
	 * @since 2.1.0
	 */
	public function test_needs_upgrade_returns_false_when_current() {
		$this->assertFalse( self::$table->needs_upgrade() );
	}

	/**
	 * Test that the table no longer exists after it has been uninstalled.
	 *
	 * @since 2.1.0
	 */
	public function test_table_does_not_exist_after_uninstall() {
		$this->bypass_table_filters();
		self::$table->uninstall();
		$exists = self::$table->exists();
		self::$table->install();
		$this->restore_table_filters();

		$this->assertFalse( $exists );
	}

	// -------------------------------------------------------------------------
	// Count.
	// -------------------------------------------------------------------------

	/**
	 * Test that count returns zero when the table is empty.
	 *
	 * @since 2.1.0
	 */
	public function test_count_returns_zero_on_empty_table() {
		$this->assertSame( 0, self::$table->count() );
	}

	/**
	 * Test that count returns the correct row count after direct inserts.
	 *
	 * @since 2.1.0
	 */
	public function test_count_returns_correct_number_after_direct_inserts() {
		global $wpdb;

		$table_name = $wpdb->berlindb_database_test_widgets;
		$wpdb->insert(
			$table_name,
			array(
				'name'   => 'Widget A',
				'status' => 'active',
			)
		);
		$wpdb->insert(
			$table_name,
			array(
				'name'   => 'Widget B',
				'status' => 'active',
			)
		);
		$wpdb->insert(
			$table_name,
			array(
				'name'   => 'Widget C',
				'status' => 'inactive',
			)
		);

		$this->assertSame( 3, self::$table->count() );
	}

	// -------------------------------------------------------------------------
	// Drop / recreate.
	// -------------------------------------------------------------------------

	/**
	 * Test that drop removes the table from the database.
	 *
	 * @since 2.1.0
	 */
	public function test_drop_removes_the_table() {
		$this->bypass_table_filters();
		self::$table->drop();
		$exists = self::$table->exists();
		self::$table->install();
		$this->restore_table_filters();

		$this->assertFalse( $exists );
	}

	// -------------------------------------------------------------------------
	// Versioning.
	// -------------------------------------------------------------------------

	/**
	 * Test that get_version returns a string value.
	 *
	 * @since 2.1.0
	 */
	public function test_get_version_returns_string() {
		$version = self::$table->get_version();
		$this->assertIsString( $version );
	}

	// -------------------------------------------------------------------------
	// Upgrade flow.
	// -------------------------------------------------------------------------

	/**
	 * Test that an upgrade callback runs and performs its intended schema change.
	 *
	 * This indirectly tests that the upgrade() method correctly detects the
	 * need for an upgrade, runs the callback, and updates the stored version.
	 *
	 * Because the upgrade process is triggered by get_version() when the stored
	 * version is less than the current schema version, this test manually sets
	 * the stored version to a known pre-upgrade value before calling upgrade().
	 *
	 * @since 2.1.0
	 */
	public function test_upgrade_runs_callback_and_adds_column() {
		$this->assertFalse( self::$table->column_exists( 'notes' ) );

		update_option( self::$table->get_db_version_key(), self::$table->get_schema_version() );
		self::$table->get_version();
		self::$table->upgrade();

		$this->assertTrue( self::$table->column_exists( 'notes' ) );
		$this->assertSame( '202604231', self::$table->get_version() );
	}

	// -------------------------------------------------------------------------
	// Column inspection.
	// -------------------------------------------------------------------------

	/**
	 * Test that column_exists returns true for the id column.
	 *
	 * @since 2.1.0
	 */
	public function test_column_exists_for_id_column() {
		$this->assertTrue( self::$table->column_exists( 'id' ) );
	}

	/**
	 * Test that column_exists returns true for the name column.
	 *
	 * @since 2.1.0
	 */
	public function test_column_exists_for_name_column() {
		$this->assertTrue( self::$table->column_exists( 'name' ) );
	}

	/**
	 * Test that column_exists returns false for a column name that does not exist.
	 *
	 * @since 2.1.0
	 */
	public function test_column_exists_returns_false_for_unknown_column() {
		$this->assertFalse( self::$table->column_exists( 'nonexistent_xyz_column' ) );
	}

	// -------------------------------------------------------------------------
	// Status.
	// -------------------------------------------------------------------------

	/**
	 * Test that status returns a result object with a non-empty Name property.
	 *
	 * @since 2.1.0
	 */
	public function test_status_returns_result_with_name_property() {
		$status = self::$table->status();
		$this->assertNotEmpty( $status );
		$this->assertNotEmpty( $status->Name );
	}

	// -------------------------------------------------------------------------
	// Truncate.
	// -------------------------------------------------------------------------

	/**
	 * Test that truncate empties all rows from the table.
	 *
	 * @since 2.1.0
	 */
	public function test_truncate_empties_the_table() {
		global $wpdb;

		$table_name = $wpdb->berlindb_database_test_widgets;
		$wpdb->insert(
			$table_name,
			array(
				'name'   => 'Widget A',
				'status' => 'active',
			)
		);
		$wpdb->insert(
			$table_name,
			array(
				'name'   => 'Widget B',
				'status' => 'active',
			)
		);

		self::$table->truncate();

		$this->assertSame( 0, self::$table->count() );
	}

	// -------------------------------------------------------------------------
	// Install / uninstall version tracking.
	// -------------------------------------------------------------------------

	/**
	 * Test that install stores the expected database version option.
	 *
	 * @since 2.1.0
	 */
	public function test_install_sets_db_version() {
		$this->bypass_table_filters();
		self::$table->uninstall();
		self::$table->install();
		$version = self::$table->get_version();
		$this->restore_table_filters();

		$this->assertSame( '202604230', $version );
	}

	/**
	 * Test that uninstall removes the table from the database.
	 *
	 * @since 2.1.0
	 */
	public function test_uninstall_deletes_db_version() {
		$this->bypass_table_filters();
		self::$table->uninstall();
		$exists = self::$table->exists();
		self::$table->install();
		$this->restore_table_filters();

		$this->assertFalse( $exists );
	}
}
