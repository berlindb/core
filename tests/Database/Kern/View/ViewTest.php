<?php
/**
 * Kern View tests (#235).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Interfaces\Installable;
use BerlinDB\Database\Kern\View;
use BerlinDB\Tests\Fixtures\TestSchema;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * A view over the test_widgets table.
 *
 * @since 3.1.0
 */
class WidgetSummaryView extends View {
	protected $prefix       = 'berlindb_database';
	protected $name         = 'widget_summary';
	protected $version      = '202607120';
	protected $schema       = TestSchema::class;
	protected $auto_install = false;
}

/**
 * Kern\View: a declared VIEW that shares Table's installable lifecycle (the
 * Storage traits) but emits view DDL - CREATE OR REPLACE VIEW / DROP VIEW / a
 * VIEW-typed existence check - and carries a raw SELECT definition.
 *
 * The source table is installed REAL (not temporary): MySQL forbids a view over
 * a TEMPORARY table, and CREATE VIEW is unaffected by the test harness's
 * CREATE-TABLE rewrite.
 *
 * @since 3.1.0
 */
class ViewTest extends TestCase {

	/** @var TestTable */
	private static $table;

	/** @var string */
	private static $definition;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}

		self::$definition = 'SELECT id, name, status FROM ' . self::$table->get_table_name();
	}

	public static function tearDownAfterClass(): void {
		global $wpdb;

		// Drop the view, then the real source table.
		$wpdb->query( 'DROP VIEW IF EXISTS ' . $wpdb->prefix . 'berlindb_database_widget_summary' );
		self::$table->uninstall();

		parent::tearDownAfterClass();
	}

	/** @var WidgetSummaryView */
	private $view;

	public function setUp(): void {
		parent::setUp();

		$this->view = new WidgetSummaryView(
			array(
				'definition' => self::$definition,
			)
		);

		$this->drop_view();
	}

	public function tearDown(): void {

		/*
		 * Only the view (DDL) needs cleanup; the version option rolls back with the
		 * per-test transaction. drop_view() uses IF EXISTS, so it never errors.
		 */
		$this->drop_view();

		parent::tearDown();
	}

	/**
	 * Drop the view unconditionally (CREATE VIEW is DDL, not rolled back).
	 *
	 * @since 3.1.0
	 */
	private function drop_view(): void {
		global $wpdb;

		$name     = $this->view->get_table_name();
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( 'DROP VIEW IF EXISTS `' . esc_sql( $name ) . '`' );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * A View is an Installable relation.
	 *
	 * @since 3.1.0
	 */
	public function test_view_is_installable() {
		$this->assertInstanceOf( Installable::class, $this->view );
	}

	/**
	 * The declared SELECT is exposed.
	 *
	 * @since 3.1.0
	 */
	public function test_get_definition() {
		$this->assertSame( self::$definition, $this->view->get_definition() );
	}

	/**
	 * create() then exists() then drop() then exists(): the view lifecycle, and
	 * exists() recognizes a VIEW (not a base table).
	 *
	 * @since 3.1.0
	 */
	public function test_create_exists_and_drop() {
		$this->assertTrue( $this->view->create() );
		$this->assertTrue( $this->view->exists() );

		$this->assertTrue( $this->view->drop() );
		$this->assertFalse( $this->view->exists() );
	}

	/**
	 * install() creates the view AND records its version through the shared
	 * Storage\Installation / Versioning lifecycle (proving View reuses the seam).
	 *
	 * @since 3.1.0
	 */
	public function test_install_records_version() {
		$this->view->install();

		$this->assertTrue( $this->view->exists() );
		$this->assertTrue( $this->view->is_installed() );
		$this->assertSame( '202607120', $this->view->get_version() );
	}

	/**
	 * uninstall() drops the view and clears its version.
	 *
	 * @since 3.1.0
	 */
	public function test_uninstall_clears_version() {
		$this->view->install();
		$this->view->uninstall();

		$this->assertFalse( $this->view->exists() );
		$this->assertFalse( $this->view->is_installed() );
	}

	/**
	 * get_create_sql() returns the view's CREATE VIEW definition once it exists.
	 *
	 * @since 3.1.0
	 */
	public function test_get_create_sql_returns_view_ddl() {
		$this->view->create();

		$sql = $this->view->get_create_sql();

		$this->assertIsString( $sql );
		$this->assertStringContainsString( 'widget_summary', $sql );
	}
}
