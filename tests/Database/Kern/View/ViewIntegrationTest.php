<?php
/**
 * View end-to-end integration: read rows THROUGH a view, writes refused (#235 step 7).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\View;
use BerlinDB\Tests\Fixtures\TestQuery;
use BerlinDB\Tests\Fixtures\TestRow;
use BerlinDB\Tests\Fixtures\TestSchema;
use BerlinDB\Tests\Fixtures\TestTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * The view's read-only schema, shared by the view and its reading query - declare
 * read_only once on the schema and both derive it.
 *
 * @since 3.1.0
 */
class ViewIntegrationSchema extends TestSchema {
	protected $read_only = true;
}

/**
 * The view: a pass-through SELECT over test_widgets (definition set at construction).
 *
 * @since 3.1.0
 */
class ViewIntegrationView extends View {
	protected $prefix       = 'berlindb_database';
	protected $name         = 'widget_view_int';
	protected $version      = '202607120';
	protected $schema       = ViewIntegrationSchema::class;
	protected $auto_install = false;
}

/**
 * A Query pointed at the view with the SAME read-only schema (matching prefix +
 * table_name resolve to the relation the view registered).
 *
 * @since 3.1.0
 */
class ViewIntegrationQuery extends Query {
	protected $prefix           = 'berlindb_database';
	protected $table_name       = 'widget_view_int';
	protected $table_alias      = 'wvi';
	protected $table_schema     = ViewIntegrationSchema::class;
	protected $item_name        = 'widget_view_int';
	protected $item_name_plural = 'widget_view_ints';
	protected $item_shape       = TestRow::class;
	protected $cache_group      = 'berlindb-view-int';
}

/**
 * End-to-end: a View creates the view over a real source table; a Query pointed at
 * the view READS its rows; and - because they share a read-only schema - that Query
 * REFUSES writes. The developer declares read_only once, on the shared schema.
 *
 * @since 3.1.0
 */
class ViewIntegrationTest extends TestCase {

	/** @var TestTable */
	private static $table;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table = new TestTable();
		if ( ! self::$table->exists() ) {
			self::$table->install();
		}
	}

	public static function tearDownAfterClass(): void {
		global $wpdb;

		$wpdb->query( 'DROP VIEW IF EXISTS ' . $wpdb->prefix . 'berlindb_database_widget_view_int' );
		self::$table->uninstall();

		parent::tearDownAfterClass();
	}

	/** @var ViewIntegrationView */
	private $view;

	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		$this->view = new ViewIntegrationView(
			array(
				'definition' => 'SELECT * FROM ' . self::$table->get_table_name(),
			)
		);

		$this->drop_view();
	}

	public function tearDown(): void {
		$this->drop_view();

		parent::tearDown();
	}

	private function drop_view(): void {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( 'DROP VIEW IF EXISTS ' . $this->view->get_table_name() );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * A Query reads rows THROUGH the view, and refuses to write to it.
	 *
	 * @since 3.1.0
	 */
	public function test_reads_through_view_and_refuses_writes() {

		// Seed the source table through a writable query.
		$writer = new TestQuery();
		$this->assertNotEmpty( $writer->add_item( array( 'name' => 'alpha' ) ) );
		$this->assertNotEmpty( $writer->add_item( array( 'name' => 'beta' ) ) );

		// Create the view over the seeded source.
		$this->assertTrue( $this->view->create() );

		// A read-only query pointed at the view reads its rows.
		$reader = new ViewIntegrationQuery( array( 'number' => 100 ) );
		$names  = wp_list_pluck( $reader->items, 'name' );

		$this->assertContains( 'alpha', $names );
		$this->assertContains( 'beta', $names );

		// ...but the same query refuses to write (its schema is read-only).
		$this->assertFalse( $reader->can_write() );
		$this->assertFalse( $reader->add_item( array( 'name' => 'gamma' ) ) );
	}
}
