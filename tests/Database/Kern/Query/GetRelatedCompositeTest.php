<?php
/**
 * Composite (multi-column) foreign-key get_related() (#211 Lever D, slice 2).
 *
 * A child belongs_to a parent on a TWO-column key ( region, account ). get_related()
 * must build the remote lookup from all key columns and fetch the matching parent,
 * and return null when any key part does not match.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Relationship;
use BerlinDB\Database\Kern\Row;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/** Parent schema: identified by a ( region, account ) pair ( not the primary ). */
class Cfk2ParentSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'     => 'region',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'     => 'account',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
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
 * Child schema: ( region, account ) belongs_to the parent's ( region, account ).
 *
 * A composite ( multi-column ) relationship is declared via get_relationships() with
 * explicit columns/references arrays - the per-column `relationships` shorthand only
 * models single-column keys.
 */
class Cfk2ChildSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'     => 'region',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'     => 'account',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);

	public function get_relationships() {
		return array(
			new Relationship(
				array(
					'name'       => 'parent',
					'query'      => Cfk2ParentQuery::class,
					'type'       => 'belongs_to',
					'columns'    => array( 'region', 'account' ),
					'references' => array( 'region', 'account' ),
				)
			),
		);
	}
}

class Cfk2ParentRow extends Row {
	public $id      = 0;
	public $region  = 0;
	public $account = 0;
}
class Cfk2ChildRow extends Row {
	public $id      = 0;
	public $region  = 0;
	public $account = 0;
}

class Cfk2ParentQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'cfk2_parent_test';
	protected $table_alias      = 'cpp';
	protected $table_schema     = Cfk2ParentSchema::class;
	protected $item_name        = 'cfk2_parent';
	protected $item_name_plural = 'cfk2_parents';
	protected $item_shape       = Cfk2ParentRow::class;
	protected $cache_group      = 'berlindb-cfk2-parent';
}
class Cfk2ChildQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'cfk2_child_test';
	protected $table_alias      = 'cpc';
	protected $table_schema     = Cfk2ChildSchema::class;
	protected $item_name        = 'cfk2_child';
	protected $item_name_plural = 'cfk2_children';
	protected $item_shape       = Cfk2ChildRow::class;
	protected $cache_group      = 'berlindb-cfk2-child';
}

class Cfk2ParentTable extends Table {
	protected $schema  = Cfk2ParentSchema::class;
	protected $name    = 'berlindb_cfk2_parent_test';
	protected $version = '202607070';
}
class Cfk2ChildTable extends Table {
	protected $schema  = Cfk2ChildSchema::class;
	protected $name    = 'berlindb_cfk2_child_test';
	protected $version = '202607070';
}

/**
 * Functional tests for composite-key get_related().
 *
 * @since 3.1.0
 */
class GetRelatedCompositeTest extends TestCase {

	/** @var Cfk2ParentTable */
	private static $parent_table;

	/** @var Cfk2ChildTable */
	private static $child_table;

	/** @var Cfk2ParentQuery */
	private static $parents;

	/** @var Cfk2ChildQuery */
	private static $children;

	/**
	 * Install both tables and seed a parent + children.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$parent_table = new Cfk2ParentTable();
		if ( ! self::$parent_table->exists() ) {
			self::$parent_table->install();
		}
		self::$child_table = new Cfk2ChildTable();
		if ( ! self::$child_table->exists() ) {
			self::$child_table->install();
		}

		self::$parents  = new Cfk2ParentQuery();
		self::$children = new Cfk2ChildQuery();
	}

	/**
	 * Reset rows before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		self::$child_table->delete_all();
		self::$parent_table->delete_all();
		wp_cache_flush();
	}

	/**
	 * Drop both tables after the class.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$child_table->uninstall();
		self::$parent_table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * get_related() resolves a composite belongs_to by matching ALL key columns.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_belongs_to_resolves_by_all_key_columns() {
		// The matching parent, plus a decoy that shares only ONE key part.
		self::$parents->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);
		self::$parents->add_item(
			array(
				'region'  => 5,
				'account' => 9,
			)
		);

		$child_id = self::$children->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);
		$child    = self::$children->get_item( $child_id );

		$parent = self::$children->get_related( $child, 'parent' );

		$this->assertNotNull( $parent );
		$this->assertSame( 5, (int) $parent->region );
		$this->assertSame( 7, (int) $parent->account );
	}

	/**
	 * A child whose composite key matches no parent resolves to null.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_belongs_to_with_no_full_match_is_null() {
		self::$parents->add_item(
			array(
				'region'  => 5,
				'account' => 7,
			)
		);

		// Shares region but not account -> no full-key match.
		$child_id = self::$children->add_item(
			array(
				'region'  => 5,
				'account' => 99,
			)
		);
		$child    = self::$children->get_item( $child_id );

		$this->assertNull( self::$children->get_related( $child, 'parent' ) );
	}
}
