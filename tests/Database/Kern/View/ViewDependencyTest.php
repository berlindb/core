<?php
/**
 * View dependency defer-and-retry: a view whose source does not exist defers; a view
 * whose source exists creates (#235, Traits\Storage\View\Dependencies).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\View;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * A config-constructable view with auto-install off and is_testing() off, so the
 * test drives create() itself. Definition and depends_on are passed per test.
 *
 * @since 3.1.0
 */
class ViewDepTestView extends View {
	protected $name         = 'berlindb_database_dep_view';
	protected $version      = '1';
	protected $auto_install = false;

	protected function is_testing() {
		return false;
	}
}

/**
 * create() defers (does nothing, logs) while a declared dependency is absent, and
 * creates the view when the dependency exists - the deferred-foreign-key pattern, for
 * views. The source table is a raw, uniquely-named table built in setUpBeforeClass so
 * it persists past the per-test transaction (CREATE/DROP are not rolled back).
 *
 * @since 3.1.0
 */
class ViewDependencyTest extends TestCase {

	/** @var string Full, site-prefixed name of the source table. */
	private static $source_name;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		global $wpdb;

		self::$source_name = $wpdb->prefix . 'berlindb_database_dep_src';
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( self::$source_name ) . '`' );
		$wpdb->query( 'CREATE TABLE `' . esc_sql( self::$source_name ) . '` ( id BIGINT UNSIGNED NOT NULL )' );
	}

	public static function tearDownAfterClass(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( self::$source_name ) . '`' );

		parent::tearDownAfterClass();
	}

	public function tearDown(): void {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( 'DROP VIEW IF EXISTS `' . esc_sql( $wpdb->prefix . 'berlindb_database_dep_view' ) . '`' );
		$wpdb->suppress_errors( $suppress );

		parent::tearDown();
	}

	/**
	 * create() defers, and logs the missing name, while a dependency is absent.
	 *
	 * @since 3.1.0
	 */
	public function test_defers_when_dependency_missing() {
		$view = new ViewDepTestView(
			array(
				'definition' => 'SELECT 1',
				'depends_on' => array( 'berlindb_database_absent_dep' ),
			)
		);

		$this->assertFalse( $view->create() );
		$this->assertFalse( $view->exists() );

		$logs = $view->get_logs( array( 'code' => 'view_dependencies_missing' ) );
		$this->assertNotEmpty( $logs );
		$this->assertContains( 'berlindb_database_absent_dep', $logs[0]['context']['missing'] );
	}

	/**
	 * create() succeeds once the declared dependency exists.
	 *
	 * @since 3.1.0
	 */
	public function test_creates_when_dependency_exists() {
		$view = new ViewDepTestView(
			array(
				'definition' => 'SELECT * FROM ' . self::$source_name,
				'depends_on' => array( 'berlindb_database_dep_src' ),
			)
		);

		$this->assertTrue( $view->create() );
		$this->assertTrue( $view->exists() );
	}
}
