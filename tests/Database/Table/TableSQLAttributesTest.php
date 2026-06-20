<?php
/**
 * Tests for Table SQL attribute properties: $engine, $row_format,
 * $auto_increment, and set_increment().
 *
 * Each CREATE TABLE test constructs a fresh TestTable with a unique name so
 * it does not interfere with the shared fixture used by TableTest. The table
 * is dropped explicitly after each assertion because DDL statements cause an
 * implicit MySQL commit that bypasses the WP test framework's per-test
 * transaction rollback.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for $engine, $row_format, $auto_increment, and set_increment().
 *
 * @since 3.1.0
 */
class TableSQLAttributesTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var int Number of _create_temporary_tables filter instances removed. */
	private $bypassed_create_count = 0;

	/** @var int Number of _drop_temporary_tables filter instances removed. */
	private $bypassed_drop_count = 0;

	/**
	 * Install the shared fixture table before SQL-attribute tests run.
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
	 * Uninstall the shared fixture table after all tests in this class complete.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset shared fixture state before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
		self::$table->delete_all();
		delete_option( self::$table->get_db_uninstall_key() );
		wp_cache_flush();
	}

	// -------------------------------------------------------------------------
	// Helpers.
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

	/**
	 * Create a scratch TestTable with a unique name and the given extra args,
	 * bypassing the WP temporary-table filters so a real table is created.
	 *
	 * Returns the table instance. Caller must call cleanup_scratch() when done.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $suffix Short name suffix - appended to the
	 *                                     base scratch name to keep tables unique.
	 * @param array<string, mixed> $args   Additional constructor arguments (e.g.
	 *                                     engine, row_format, auto_increment).
	 * @return TestTable
	 */
	private function make_scratch_table( string $suffix, array $args = array() ): TestTable {
		return new TestTable(
			array_merge(
				array(
					'name'           => 'berlindb_sql_attr_' . $suffix,
					'db_version_key' => 'berlindb_sql_attr_' . $suffix . '_version',
				),
				$args
			)
		);
	}

	/**
	 * Drop a scratch table and delete its version option.
	 *
	 * @since 3.1.0
	 *
	 * @param TestTable $table The scratch table to clean up.
	 */
	private function cleanup_scratch( TestTable $table ): void {
		$table->drop();
		delete_option( $table->get_db_version_key() );
	}

	// -------------------------------------------------------------------------
	// $engine.
	// -------------------------------------------------------------------------

	/**
	 * When $engine is set, the storage engine must appear in SHOW TABLE STATUS.
	 *
	 * @since 3.1.0
	 */
	public function test_engine_property_is_reflected_in_table_status(): void {
		$this->bypass_table_filters();

		$table  = $this->make_scratch_table( 'engine', array( 'engine' => 'InnoDB' ) );
		$status = $table->status();
		$this->cleanup_scratch( $table );

		$this->restore_table_filters();

		$this->assertNotFalse( $status );
		$this->assertEqualsIgnoringCase( 'InnoDB', $status->Engine ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	// -------------------------------------------------------------------------
	// $row_format.
	// -------------------------------------------------------------------------

	/**
	 * When $row_format is set, the format must appear in SHOW TABLE STATUS.
	 *
	 * @since 3.1.0
	 */
	public function test_row_format_property_is_reflected_in_table_status(): void {
		$this->bypass_table_filters();

		$table  = $this->make_scratch_table( 'rowfmt', array( 'row_format' => 'DYNAMIC' ) );
		$status = $table->status();
		$this->cleanup_scratch( $table );

		$this->restore_table_filters();

		$this->assertNotFalse( $status );
		$this->assertEqualsIgnoringCase( 'Dynamic', $status->Row_format ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	// -------------------------------------------------------------------------
	// $auto_increment.
	// -------------------------------------------------------------------------

	/**
	 * When $auto_increment > 1, the counter must be set at CREATE TABLE time.
	 *
	 * @since 3.1.0
	 */
	public function test_auto_increment_property_sets_starting_counter(): void {
		$this->bypass_table_filters();

		$table  = $this->make_scratch_table( 'ai', array( 'auto_increment' => 1000 ) );
		$status = $table->status();
		$this->cleanup_scratch( $table );

		$this->restore_table_filters();

		$this->assertNotFalse( $status );
		$this->assertSame( 1000, (int) $status->Auto_increment ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	// -------------------------------------------------------------------------
	// auto_increment().
	// -------------------------------------------------------------------------

	/**
	 * auto_increment() must update the table's AUTO_INCREMENT counter and
	 * return true.
	 *
	 * @since 3.1.0
	 */
	public function test_auto_increment_method_updates_the_counter(): void {
		$result = self::$table->auto_increment( 500 );
		$status = self::$table->status();

		$this->assertTrue( $result );
		$this->assertSame( 500, (int) $status->Auto_increment ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * auto_increment(0) must return false without touching the database.
	 *
	 * @since 3.1.0
	 */
	public function test_auto_increment_method_returns_false_for_zero(): void {
		$this->assertFalse( self::$table->auto_increment( 0 ) );
	}

	/**
	 * auto_increment() with a negative value must return false.
	 *
	 * @since 3.1.0
	 */
	public function test_auto_increment_method_returns_false_for_negative_value(): void {
		$this->assertFalse( self::$table->auto_increment( -1 ) );
	}

	// -------------------------------------------------------------------------
	// engine().
	// -------------------------------------------------------------------------

	/**
	 * engine() must convert the table's storage engine and return true.
	 *
	 * Converts to InnoDB (the existing engine on the fixture table), which is
	 * effectively a no-op but still runs the ALTER TABLE successfully.
	 *
	 * @since 3.1.0
	 */
	public function test_engine_method_converts_storage_engine(): void {
		$result = self::$table->engine( 'InnoDB' );
		$status = self::$table->status();

		$this->assertTrue( $result );
		$this->assertEqualsIgnoringCase( 'InnoDB', $status->Engine ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * engine() must return false for an unrecognised engine name.
	 *
	 * @since 3.1.0
	 */
	public function test_engine_method_returns_false_for_invalid_engine(): void {
		$this->assertFalse( self::$table->engine( 'NOTANENGINE' ) );
	}

	// -------------------------------------------------------------------------
	// get_create_sql().
	// -------------------------------------------------------------------------

	/**
	 * get_create_sql() must return a non-empty string for an existing table.
	 *
	 * @since 3.1.0
	 */
	public function test_get_create_sql_returns_string_for_existing_table(): void {
		$sql = self::$table->get_create_sql();

		$this->assertIsString( $sql );
		$this->assertNotEmpty( $sql );
	}

	/**
	 * The returned SQL must contain CREATE TABLE and the fixture table name.
	 *
	 * @since 3.1.0
	 */
	public function test_get_create_sql_contains_create_table_and_table_name(): void {
		$sql = self::$table->get_create_sql();

		$this->assertIsString( $sql );
		$this->assertStringContainsStringIgnoringCase( 'CREATE TABLE', $sql );
		$this->assertStringContainsString( 'berlindb_database_test_widgets', $sql );
	}
}
