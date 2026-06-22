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
 * automatically calls maybe_upgrade() -> install() on first run.
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

	/**
	 * Install the fixture table before table tests run.
	 *
	 * @since 2.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();

		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
	}

	/**
	 * Uninstall the fixture table after table tests complete.
	 *
	 * @since 2.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset table fixture state before each test.
	 *
	 * @since 2.1.0
	 */
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
		 * "CREATE TEMPORARY TABLE ... already exists" error. Tests that drop or
		 * uninstall the table handle their own reinstall via bypass_table_filters().
		 */
		self::$table->delete_all();
		delete_option( self::$table->get_db_uninstall_key() );
		wp_cache_flush();
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Remove ALL active instances of the WP test-framework query filters that
	 * convert CREATE/DROP TABLE to their TEMPORARY variants, and record the
	 * count so restore_table_filters() can put them back exactly.
	 *
	 * @since 2.1.0
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
	 * @since 2.1.0
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

	// -------------------------------------------------------------------------
	// Duplicate.
	// -------------------------------------------------------------------------

	/**
	 * Test that duplicate creates a table with the same structure as the original.
	 *
	 * The copy receives the full prefixed name: $wpdb->prefix + plugin prefix +
	 * the name passed in. TestTable has no plugin prefix, so the copy lands at
	 * {$wpdb->prefix}berlindb_database_test_widgets_dup.
	 *
	 * @since 3.0.0
	 */
	public function test_duplicate_creates_table_with_structure_of_original() {
		global $wpdb;

		$copy_base = 'berlindb_database_test_widgets_dup';
		$copy_name = $wpdb->prefix . $copy_base;

		$this->bypass_table_filters();

		$result = self::$table->duplicate( $copy_base );
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $copy_name ) );

		if ( $exists ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $copy_name ) . '`' );
		}

		$this->restore_table_filters();

		$this->assertTrue( $result );
		$this->assertTrue( $exists );
	}

	/**
	 * Test that copy inserts the source table rows into an existing duplicate.
	 *
	 * @since 3.0.0
	 */
	public function test_copy_inserts_rows_into_duplicate_table() {
		global $wpdb;

		$copy_base = 'berlindb_database_test_widgets_copy';
		$copy_name = $wpdb->prefix . $copy_base;
		$table     = $wpdb->berlindb_database_test_widgets;

		$wpdb->insert(
			$table,
			array(
				'name'   => 'Widget A',
				'status' => 'active',
			)
		);
		$wpdb->insert(
			$table,
			array(
				'name'   => 'Widget B',
				'status' => 'inactive',
			)
		);

		$this->bypass_table_filters();

		self::$table->duplicate( $copy_base );
		$result = self::$table->copy( $copy_base );
		$count  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $copy_name ) . '`' );

		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $copy_name ) . '`' );

		$this->restore_table_filters();

		$this->assertTrue( $result );
		$this->assertSame( 2, $count );
	}

	/**
	 * Test that rename rejects an invalid destination table name.
	 *
	 * @since 3.0.0
	 */
	public function test_rename_returns_false_for_invalid_table_name() {
		$this->assertFalse( self::$table->rename( '' ) );
	}

	/**
	 * Test that rename moves a duplicate table without touching the source table.
	 *
	 * @since 3.0.0
	 */
	public function test_rename_moves_table_to_new_name() {
		global $wpdb;

		$from_base = 'berlindb_database_test_widgets_rename_from';
		$to_base   = 'berlindb_database_test_widgets_rename_to';
		$from_name = $wpdb->prefix . $from_base;
		$to_name   = $wpdb->prefix . $to_base;

		$this->bypass_table_filters();

		self::$table->duplicate( $from_base );

		$temp_table = new TestTable(
			array(
				'name'           => $from_base,
				'db_version_key' => 'berlindb_database_test_widgets_rename_from_version',
			)
		);

		$result      = $temp_table->rename( $to_base );
		$from_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $from_name ) );
		$to_exists   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $to_name ) );

		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $from_name ) . '`' );
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $to_name ) . '`' );
		delete_option( 'berlindb_database_test_widgets_rename_from_version' );

		$this->restore_table_filters();

		$this->assertTrue( $result );
		$this->assertFalse( $from_exists );
		$this->assertTrue( $to_exists );
		$this->assertTrue( self::$table->exists() );
	}

	// -------------------------------------------------------------------------
	// Delete all.
	// -------------------------------------------------------------------------

	/**
	 * Test that delete_all removes all rows and returns true.
	 *
	 * @since 3.0.0
	 */
	public function test_delete_all_removes_all_rows_and_returns_true() {
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

		$result = self::$table->delete_all();

		$this->assertTrue( $result );
		$this->assertSame( 0, self::$table->count() );
	}

	// -------------------------------------------------------------------------
	// Columns.
	// -------------------------------------------------------------------------

	/**
	 * Test that columns returns an array of objects.
	 *
	 * @since 3.0.0
	 */
	public function test_columns_returns_array_of_column_objects() {
		$result = self::$table->columns();
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
		$this->assertIsObject( $result[0] );
	}

	/**
	 * Test that the columns result includes the id column.
	 *
	 * @since 3.0.0
	 */
	public function test_columns_result_includes_id_column() {
		$result = self::$table->columns();
		$fields = array_column( (array) $result, 'Field' );
		$this->assertContains( 'id', $fields );
	}

	// -------------------------------------------------------------------------
	// Indexes.
	// -------------------------------------------------------------------------

	/**
	 * Test that indexes returns an array of objects.
	 *
	 * @since 3.0.0
	 */
	public function test_indexes_returns_array_of_index_objects() {
		$result = self::$table->indexes();
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
		$this->assertIsObject( $result[0] );
	}

	/**
	 * Test that index_exists returns true for the primary key.
	 *
	 * @since 3.0.0
	 */
	public function test_index_exists_returns_true_for_primary_key() {
		$this->assertTrue( self::$table->index_exists( 'PRIMARY' ) );
	}

	/**
	 * Test that index_exists returns false for an index that does not exist.
	 *
	 * @since 3.0.0
	 */
	public function test_index_exists_returns_false_for_nonexistent_index() {
		$this->assertFalse( self::$table->index_exists( 'nonexistent_idx_xyz' ) );
	}

	/**
	 * Test the full add_index / drop_index lifecycle.
	 *
	 * @since 3.0.0
	 */
	public function test_add_index_and_drop_index_lifecycle() {
		$added = self::$table->add_index(
			array(
				'name'    => 'test_name_idx',
				'type'    => 'key',
				'columns' => array( 'name' ),
			)
		);
		$this->assertTrue( $added );
		$this->assertTrue( self::$table->index_exists( 'test_name_idx' ) );

		$dropped = self::$table->drop_index( 'test_name_idx' );
		$this->assertTrue( $dropped );
		$this->assertFalse( self::$table->index_exists( 'test_name_idx' ) );
	}

	// -------------------------------------------------------------------------
	// Upgrade helpers.
	// -------------------------------------------------------------------------

	/**
	 * Test that is_upgradeable returns true for a non-global table.
	 *
	 * @since 3.0.0
	 */
	public function test_is_upgradeable_returns_true_for_non_global_table() {
		$this->assertTrue( self::$table->is_upgradeable() );
	}

	/**
	 * Test that get_pending_upgrades returns an empty array when the stored
	 * version is equal to or greater than all registered upgrade versions.
	 *
	 * @since 3.0.0
	 */
	public function test_get_pending_upgrades_returns_empty_when_version_is_current() {
		update_option( self::$table->get_db_version_key(), '999999999' );
		self::$table->get_version();

		$pending = self::$table->get_pending_upgrades();

		update_option( self::$table->get_db_version_key(), self::$table->get_schema_version() );
		self::$table->get_version();

		$this->assertEmpty( $pending );
	}

	/**
	 * Test that get_pending_upgrades returns the registered callback when the
	 * stored version is lower than a registered upgrade version.
	 *
	 * @since 3.0.0
	 */
	public function test_get_pending_upgrades_returns_callback_when_version_is_outdated() {
		update_option( self::$table->get_db_version_key(), '202604229' );
		self::$table->get_version();

		$pending = self::$table->get_pending_upgrades();

		update_option( self::$table->get_db_version_key(), self::$table->get_schema_version() );
		self::$table->get_version();

		$this->assertArrayHasKey( '202604231', $pending );
	}

	// -------------------------------------------------------------------------
	// Maintenance.
	// -------------------------------------------------------------------------

	/**
	 * Test that analyze returns a string message or false.
	 *
	 * @since 3.0.0
	 */
	public function test_analyze_returns_string_or_false() {
		$result = self::$table->analyze();
		$this->assertTrue( is_string( $result ) || false === $result );
	}

	/**
	 * Test that check returns a string message or false.
	 *
	 * @since 3.0.0
	 */
	public function test_check_returns_string_or_false() {
		$result = self::$table->check();
		$this->assertTrue( is_string( $result ) || false === $result );
	}

	/**
	 * Test that checksum returns a value or false.
	 *
	 * @since 3.0.0
	 */
	public function test_checksum_returns_value_or_false() {
		$result = self::$table->checksum();
		$this->assertTrue( is_string( $result ) || is_int( $result ) || false === $result );
	}

	/**
	 * Test that optimize returns a string message or false.
	 *
	 * @since 3.0.0
	 */
	public function test_optimize_returns_string_or_false() {
		$result = self::$table->optimize();
		$this->assertTrue( is_string( $result ) || false === $result );
	}

	/**
	 * Test that repair returns a string message or false.
	 *
	 * @since 3.0.0
	 */
	public function test_repair_returns_string_or_false() {
		$result = self::$table->repair();
		$this->assertTrue( is_string( $result ) || false === $result );
	}
}
