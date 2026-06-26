<?php
/**
 * Query::get_related() worked-example tests across two distinct tables.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Row;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Schema for categories: a category has_many widgets (widget.category_id -> id).
 */
class CategorySchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'extra'         => 'auto_increment',
			'cache_key'     => true,
			'sortable'      => true,
			'relationships' => array(
				array(
					'name'   => 'widgets',
					'query'  => WidgetQuery::class,
					'column' => 'category_id',
					'type'   => 'has_many',
				),
			),
		),
		array(
			'name'    => 'name',
			'type'    => 'varchar',
			'length'  => '100',
			'default' => '',
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/**
 * Schema for widgets: a widget belongs_to a category (category_id -> category.id).
 */
class WidgetSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
			'sortable'  => true,
		),
		array(
			'name'    => 'name',
			'type'    => 'varchar',
			'length'  => '100',
			'default' => '',
		),
		array(
			'name'          => 'category_id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'default'       => 0,
			'in'            => true,
			'relationships' => array(
				array(
					'name'   => 'category',
					'query'  => CategoryQuery::class,
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Category table. */
class CategoryTable extends Table {
	protected $schema  = CategorySchema::class;
	protected $name    = 'berlindb_category_test';
	protected $version = '202606250';
}

/** Widget table. */
class WidgetTable extends Table {
	protected $schema  = WidgetSchema::class;
	protected $name    = 'berlindb_widget_test';
	protected $version = '202606250';
}

/** Category row shape. */
class CategoryRow extends Row {
	public $id   = 0;
	public $name = '';
}

/** Widget row shape. */
class WidgetRow extends Row {
	public $id          = 0;
	public $name        = '';
	public $category_id = 0;
}

/** Category query. */
class CategoryQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'category_test';
	protected $table_alias      = 'ct';
	protected $table_schema     = CategorySchema::class;
	protected $item_name        = 'category';
	protected $item_name_plural = 'categories';
	protected $item_shape       = CategoryRow::class;
	protected $cache_group      = 'berlindb-category';
}

/** Widget query. */
class WidgetQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'widget_test';
	protected $table_alias      = 'wt';
	protected $table_schema     = WidgetSchema::class;
	protected $item_name        = 'widget';
	protected $item_name_plural = 'widgets';
	protected $item_shape       = WidgetRow::class;
	protected $cache_group      = 'berlindb-widget';
}

/**
 * Worked example for Query::get_related() over a real two-table relationship.
 *
 * This is how a consumer traverses declared relationships: a Widget belongs_to a
 * Category, and a Category has_many Widgets. It exercises both directions, the
 * no-relation case, and the primed (zero-SQL) read - the read-side payoff of the
 * relationship feature (#193 Phase 4).
 *
 * @since 3.1.0
 */
class GetRelatedTest extends TestCase {

	/** @var CategoryTable */
	private static $category_table;

	/** @var WidgetTable */
	private static $widget_table;

	/** @var CategoryQuery */
	private static $categories;

	/** @var WidgetQuery */
	private static $widgets;

	/** @var int Seeded "Tools" category id. */
	private $tools_id = 0;

	/** @var int Seeded "Hammer" widget id (in Tools). */
	private $hammer_id = 0;

	/**
	 * Install both fixture tables and the query objects.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$category_table = new CategoryTable();
		if ( ! self::$category_table->exists() ) {
			self::$category_table->install();
		}

		self::$widget_table = new WidgetTable();
		if ( ! self::$widget_table->exists() ) {
			self::$widget_table->install();
		}

		self::$categories = new CategoryQuery();
		self::$widgets    = new WidgetQuery();
	}

	/**
	 * Uninstall both fixture tables after the suite.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$widget_table->uninstall();
		self::$category_table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Seed one category with two widgets, plus one un-categorized widget.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		self::$widget_table->delete_all();
		self::$category_table->delete_all();
		wp_cache_flush();

		$this->tools_id = self::$categories->add_item( array( 'name' => 'Tools' ) );

		$this->hammer_id = self::$widgets->add_item(
			array(
				'name'        => 'Hammer',
				'category_id' => $this->tools_id,
			)
		);
		self::$widgets->add_item(
			array(
				'name'        => 'Wrench',
				'category_id' => $this->tools_id,
			)
		);
		self::$widgets->add_item(
			array(
				'name'        => 'Orphan',
				'category_id' => 0,
			)
		);

		wp_cache_flush();
	}

	/**
	 * Test belongs_to: a widget resolves to its parent category Row.
	 *
	 * @since 3.1.0
	 */
	public function test_belongs_to_resolves_parent_category() {
		$hammer   = self::$widgets->get_item( $this->hammer_id );
		$category = self::$widgets->get_related( $hammer, 'category' );

		$this->assertInstanceOf( CategoryRow::class, $category );
		$this->assertSame( $this->tools_id, (int) $category->id );
		$this->assertSame( 'Tools', $category->name );
	}

	/**
	 * Test has_many: a category resolves to its full collection of widgets.
	 *
	 * @since 3.1.0
	 */
	public function test_has_many_resolves_child_widgets() {
		$tools   = self::$categories->get_item( $this->tools_id );
		$widgets = self::$categories->get_related( $tools, 'widgets' );

		$this->assertIsArray( $widgets );
		$this->assertCount( 2, $widgets );

		$names = array_map( static fn( $w ) => $w->name, $widgets );
		sort( $names );
		$this->assertSame( array( 'Hammer', 'Wrench' ), $names );
	}

	/**
	 * Test belongs_to with no relation (category_id 0) returns null.
	 *
	 * @since 3.1.0
	 */
	public function test_belongs_to_no_category_is_null() {
		$orphan = null;
		foreach ( self::$widgets->query( array( 'number' => 100 ) ) as $widget ) {
			if ( 'Orphan' === $widget->name ) {
				$orphan = $widget;
			}
		}

		$this->assertInstanceOf( WidgetRow::class, $orphan );
		$this->assertNull( self::$widgets->get_related( $orphan, 'category' ) );
	}

	/**
	 * Test has_many on a childless category returns an empty array, not null.
	 *
	 * @since 3.1.0
	 */
	public function test_has_many_empty_is_array() {
		$empty_id = self::$categories->add_item( array( 'name' => 'Empty' ) );
		wp_cache_flush();

		$empty = self::$categories->get_item( $empty_id );

		$this->assertSame( array(), self::$categories->get_related( $empty, 'widgets' ) );
	}

	/**
	 * Test an unknown relationship name returns null.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_relationship_is_null() {
		$hammer = self::$widgets->get_item( $this->hammer_id );

		$this->assertNull( self::$widgets->get_related( $hammer, 'nope' ) );
	}

	/**
	 * Test that priming the relationship makes get_related() fire no further SQL.
	 *
	 * @since 3.1.0
	 */
	public function test_has_many_primed_fires_no_sql() {
		global $wpdb;

		// Query the category WITH its widgets primed in bulk.
		$primed = self::$categories->query(
			array(
				'include' => array( $this->tools_id ),
				'with'    => array( 'widgets' ),
				'number'  => 10,
			)
		);
		$this->assertCount( 1, $primed );

		// A subsequent get_related() must be served from the warmed cache.
		$queries_before = $wpdb->num_queries;
		$widgets        = self::$categories->get_related( $primed[0], 'widgets' );
		$queries_after  = $wpdb->num_queries;

		$this->assertCount( 2, $widgets );
		$this->assertSame( $queries_before, $queries_after );
	}
}
