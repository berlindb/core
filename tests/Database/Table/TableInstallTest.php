<?php
/**
 * Tests confirming install/upgrade option hygiene and admin-only hook scope.
 *
 * Specifically verifies:
 *
 *   1. Autoloading — version and tombstone options must be autoloaded so that
 *      get_option() calls inside maybe_upgrade() are always served from
 *      WordPress's in-memory options cache rather than live DB queries.
 *
 *   2. Admin-only scope — maybe_upgrade() must never be attached to a hook
 *      that fires on frontend page loads; it must only run on admin_init.
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
 * Install/upgrade option hygiene and admin-only hook scope.
 *
 * @since 3.1.0
 */
class TableInstallTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var int */
	private $bypassed_create_count = 0;

	/** @var int */
	private $bypassed_drop_count = 0;

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
	 * Uninstall the fixture table after all tests complete.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset option state before each test.
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
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Remove ALL active instances of the WP test-framework CREATE/DROP filters.
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
	 * Restore the filter instances removed by bypass_table_filters().
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
	 * Return whether an option key appears in WordPress's autoloaded options set.
	 *
	 * wp_load_alloptions() returns every autoloaded option regardless of the
	 * WordPress version's internal autoload flag format ('yes', 'on', etc.).
	 *
	 * @since 3.1.0
	 *
	 * @param string $key Option name to check.
	 * @return bool
	 */
	private function is_option_autoloaded( string $key ): bool {
		wp_cache_delete( 'alloptions', 'options' );
		return array_key_exists( $key, wp_load_alloptions() );
	}

	// -------------------------------------------------------------------------
	// Autoloading.
	// -------------------------------------------------------------------------

	/**
	 * The version option written by install() must be autoloaded so that
	 * get_db_version() inside maybe_upgrade() never fires a live DB query.
	 *
	 * The fixture table is installed in setUpBeforeClass() (outside any
	 * per-test transaction) so the option is committed and visible here.
	 *
	 * @since 3.1.0
	 */
	public function test_version_option_is_autoloaded_after_install(): void {
		$this->assertTrue(
			$this->is_option_autoloaded( self::$table->get_db_version_key() ),
			'Version option must be autoloaded so get_option() is served from cache, not a live query.'
		);
	}

	/**
	 * The tombstone option written by uninstall() must also be autoloaded so
	 * that is_uninstalled() inside is_upgradeable() never fires a live DB query.
	 *
	 * @since 3.1.0
	 */
	public function test_tombstone_option_is_autoloaded_after_uninstall(): void {
		$this->bypass_table_filters();
		self::$table->uninstall();

		$autoloaded = $this->is_option_autoloaded( self::$table->get_db_uninstall_key() );

		self::$table->install();
		$this->restore_table_filters();

		$this->assertTrue(
			$autoloaded,
			'Tombstone option must be autoloaded so is_uninstalled() is served from cache, not a live query.'
		);
	}

	// -------------------------------------------------------------------------
	// Admin-only scope.
	// -------------------------------------------------------------------------

	/**
	 * maybe_upgrade() must not be attached to any hook that fires on frontend
	 * page loads. Running existence checks and version lookups on every
	 * frontend request would add unnecessary overhead.
	 *
	 * @since 3.1.0
	 */
	public function test_maybe_upgrade_is_not_hooked_to_frontend_actions(): void {
		$table = new TestTable();

		$frontend_hooks = array(
			'muplugins_loaded',
			'plugins_loaded',
			'init',
			'setup_theme',
			'after_setup_theme',
			'wp_loaded',
			'wp',
			'template_redirect',
			'send_headers',
			'parse_request',
			'parse_query',
		);

		foreach ( $frontend_hooks as $hook ) {
			$this->assertFalse(
				has_action( $hook, array( $table, 'maybe_upgrade' ) ),
				"maybe_upgrade() must not be hooked to '{$hook}' — upgrade checks must only run in the admin."
			);
		}
	}

	/**
	 * maybe_upgrade() must be registered on admin_init so upgrades and
	 * existence checks only run during admin page loads.
	 *
	 * A fresh instance is constructed so the hook check is immediate and does
	 * not depend on the WP test framework's per-test hook restore cycle.
	 *
	 * @since 3.1.0
	 */
	public function test_maybe_upgrade_is_hooked_to_admin_init(): void {
		$table = new TestTable();

		$this->assertNotFalse(
			has_action( 'admin_init', array( $table, 'maybe_upgrade' ) ),
			'maybe_upgrade() must be registered on admin_init.'
		);
	}

	// -------------------------------------------------------------------------
	// is_installed().
	// -------------------------------------------------------------------------

	/**
	 * is_installed() returns true once a database version is stored.
	 *
	 * Sets the version option explicitly rather than relying on ambient state:
	 * DDL in other tests implicitly commits, which can leave the shared fixture's
	 * version option in an indeterminate committed state across tests.
	 *
	 * @since 3.1.0
	 */
	public function test_is_installed_returns_true_when_version_stored(): void {
		update_option( self::$table->get_db_version_key(), self::$table->get_schema_version() );

		$this->assertTrue( self::$table->is_installed() );
	}

	/**
	 * is_installed() returns false after uninstall() clears the version.
	 *
	 * @since 3.1.0
	 */
	public function test_is_installed_returns_false_after_uninstall(): void {
		$this->bypass_table_filters();
		self::$table->uninstall();
		$installed = self::$table->is_installed();
		self::$table->install();
		$this->restore_table_filters();

		$this->assertFalse( $installed );
	}

	/**
	 * A stored version of the string '0' must still count as installed.
	 *
	 * is_installed() compares against '' rather than using empty(), which would
	 * treat '0' as empty and wrongly report the table as not installed.
	 *
	 * @since 3.1.0
	 */
	public function test_is_installed_true_for_zero_version(): void {
		$key      = self::$table->get_db_version_key();
		$previous = get_option( $key );

		update_option( $key, '0' );
		$installed = self::$table->is_installed();
		update_option( $key, $previous );

		$this->assertTrue( $installed, "A version of '0' must report as installed." );
	}
}
