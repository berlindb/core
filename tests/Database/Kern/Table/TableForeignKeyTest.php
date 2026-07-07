<?php
/**
 * Runtime enforced foreign-key emission (#205 / #193 Phase 5).
 *
 * SchemaForeignKeyTest covers the DDL STRING layer (get_foreign_key_strings /
 * get_create_table_string). This exercises the RUNTIME path end to end: install a
 * referenced table and a referencing table that declares an ENFORCED belongs_to, then
 * call Table::add_foreign_keys() and confirm a real FOREIGN KEY constraint lands.
 * Both tables force InnoDB so the constraint is honored (MyISAM ignores FKs).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/** Referenced ( parent ) schema: an id other rows point at. */
class FkParentSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Referenced ( parent ) query - resolves the remote table name for the FK. */
class FkParentQuery extends Query {
	protected $prefix       = 'berlindb';
	protected $table_name   = 'fk_parent_test';
	protected $table_alias  = 'fkp';
	protected $table_schema = FkParentSchema::class;
	protected $item_name    = 'fk_parent';
	protected $cache_group  = 'berlindb-fk-parent';
}

/** Referenced ( parent ) table ( InnoDB so it can be pointed at ). */
class FkParentTable extends Table {
	protected $schema  = FkParentSchema::class;
	protected $name    = 'berlindb_fk_parent_test';
	protected $version = '202607070';
	protected $engine  = 'InnoDB';
}

/** Referencing ( child ) schema: parent_id ENFORCES a real FOREIGN KEY. */
class FkChildSchema extends Schema {
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
			'name'          => 'parent_id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'default'       => 0,
			'relationships' => array(
				array(
					'name'    => 'parent',
					'query'   => FkParentQuery::class,
					'column'  => 'id',
					'type'    => 'belongs_to',
					'enforce' => true,
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

/** Referencing ( child ) table ( deferred FK by default ). */
class FkChildTable extends Table {
	protected $schema  = FkChildSchema::class;
	protected $name    = 'berlindb_fk_child_test';
	protected $version = '202607070';
	protected $engine  = 'InnoDB';
}

/**
 * Runtime integration tests for Table::add_foreign_keys().
 *
 * @since 3.1.0
 */
class TableForeignKeyTest extends TestCase {

	/** @var FkParentTable */
	private static $parent;

	/** @var FkChildTable */
	private static $child;

	/**
	 * Install the referenced table first, then the referencing table.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$parent = new FkParentTable();
		if ( ! self::$parent->exists() ) {
			self::$parent->install();
		}

		self::$child = new FkChildTable();
		if ( ! self::$child->exists() ) {
			self::$child->install();
		}
	}

	/**
	 * Drop the child first ( it references the parent ), then the parent.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$child->uninstall();
		self::$parent->uninstall();

		parent::tearDownAfterClass();
	}

	/**
	 * The enforced FK is DEFERRED: it is not part of the CREATE TABLE, so before
	 * add_foreign_keys() runs the child table has no referential constraint.
	 *
	 * @since 3.1.0
	 */
	public function test_enforced_foreign_key_is_deferred_at_install() {
		$this->assertCount( 0, $this->referential_constraints() );
	}

	/**
	 * add_foreign_keys() adds the real constraint once both tables exist.
	 *
	 * @since 3.1.0
	 */
	public function test_add_foreign_keys_creates_the_constraint() {
		global $wpdb;

		$this->assertTrue( self::$child->add_foreign_keys() );

		$constraints = $this->referential_constraints();

		$this->assertCount( 1, $constraints );
		$this->assertSame( 'parent_id', $constraints[0]->COLUMN_NAME );
		$this->assertSame( 'id', $constraints[0]->REFERENCED_COLUMN_NAME );
		$this->assertSame(
			$wpdb->prefix . 'berlindb_fk_parent_test',
			$constraints[0]->REFERENCED_TABLE_NAME
		);
	}

	/**
	 * Read this child table's foreign-key columns from information_schema.
	 *
	 * @since 3.1.0
	 *
	 * @return list<object>
	 */
	private function referential_constraints(): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
				 FROM information_schema.KEY_COLUMN_USAGE
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND TABLE_NAME = %s
				   AND REFERENCED_TABLE_NAME IS NOT NULL',
				$wpdb->prefix . 'berlindb_fk_child_test'
			)
		);
	}
}
