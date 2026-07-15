<?php
/**
 * Temporary (session-scoped) table mode tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Table;
use BerlinDB\Tests\Fixtures\EngineSkips;
use BerlinDB\Tests\Fixtures\TestSchema;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * A TEMPORARY table over the shared test schema.
 *
 * @since 3.1.0
 */
class TempWidgetsTable extends Table {
	protected $schema       = TestSchema::class;
	protected $name         = 'berlindb_database_temp_widgets';
	protected $version      = '202607120';
	protected $temporary    = true;
	protected $auto_install = false;

	public function get_db_version_key(): string {
		return $this->db_version_key;
	}
}

/**
 * The `temporary` Table mode: CREATE/DROP TEMPORARY TABLE, a SHOW-TABLES-safe
 * exists() probe, and skipped persistence (no version option, no auto-install).
 *
 * @since 3.1.0
 */
class TableTemporaryTest extends TestCase {

	use EngineSkips;

	/** @var TempWidgetsTable */
	private $table;

	public function setUp(): void {
		parent::setUp();

		$this->table = new TempWidgetsTable();

		/*
		 * Start clean: temp-table DDL is not rolled back with the transaction, so a
		 * prior method on the shared connection could have left one behind.
		 */
		$this->drop_temp_table();
	}

	/**
	 * Drop the session temp table so it does not persist into the next test.
	 *
	 * @since 3.1.0
	 */
	public function tearDown(): void {
		$this->drop_temp_table();

		parent::tearDown();
	}

	/**
	 * Unconditionally drop the session temp table (IF EXISTS, errors suppressed).
	 *
	 * @since 3.1.0
	 */
	private function drop_temp_table(): void {
		global $wpdb;

		$name     = $this->table->get_table_name();
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS `' . esc_sql( $name ) . '`' );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * The mode flag is reported.
	 *
	 * @since 3.1.0
	 */
	public function test_is_temporary() {
		$this->assertTrue( $this->table->is_temporary() );
	}

	/**
	 * create() then exists() then drop() then exists() - the exists() probe
	 * correctly tracks a temporary table, which a SHOW TABLES check could not.
	 *
	 * @since 3.1.0
	 */
	public function test_temporary_create_exists_and_drop() {
		$this->assertTrue( $this->table->create() );
		$this->assertTrue( $this->table->exists() );

		$this->assertTrue( $this->table->drop() );
		$this->assertFalse( $this->table->exists() );
	}

	/**
	 * The created table really is TEMPORARY: exists() (probe) finds it, but SHOW
	 * TABLES does not list it - the property that makes the probe necessary.
	 *
	 * @since 3.1.0
	 */
	public function test_temporary_table_is_not_listed_by_show_tables() {
		global $wpdb;

		/*
		 * MariaDB 11+ lists temporary tables in SHOW TABLES; MySQL and MariaDB 10.x
		 * hide them (the portable, expected behavior). Tracked in berlindb/core#249.
		 */
		$this->skip_on_mariadb_at_least( '11', 'SHOW TABLES lists temporary tables on MariaDB 11+; tracked in berlindb/core#249.' );

		$this->table->create();

		$this->assertTrue( $this->table->exists() );

		$full   = $this->table->get_table_name();
		$listed = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) );

		$this->assertNull( $listed );
	}

	/**
	 * install() on a temporary table creates it but persists NO version option -
	 * a stored version would outlive the session-scoped table and mislead upgrades.
	 *
	 * @since 3.1.0
	 */
	public function test_install_skips_the_version_option() {
		$this->table->install();

		$this->assertTrue( $this->table->exists() );
		$this->assertEmpty( get_option( $this->table->get_db_version_key(), '' ) );
	}

	/**
	 * The public maybe_upgrade()/upgrade() path also persists NO version for a
	 * temporary table - set_db_version() no-ops for it, so no direct caller can
	 * store a version that would outlive the session-scoped table.
	 *
	 * @since 3.1.0
	 */
	public function test_maybe_upgrade_persists_no_version_for_temporary() {
		$this->table->create();
		$this->table->maybe_upgrade();

		$this->assertEmpty( get_option( $this->table->get_db_version_key(), '' ) );
	}

	/**
	 * uninstall() writes no tombstone for a temporary table (it never auto-installs,
	 * so a tombstone would be a meaningless orphan option).
	 *
	 * @since 3.1.0
	 */
	public function test_uninstall_writes_no_tombstone_for_temporary() {
		$this->table->install();
		$this->table->uninstall();

		$this->assertEmpty( get_option( $this->table->get_db_version_key() . '_uninstalled', '' ) );
	}
}
