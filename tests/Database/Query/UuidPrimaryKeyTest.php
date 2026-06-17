<?php
/**
 * End-to-end support for a varchar/UUID primary key (not auto_increment).
 *
 * Proves the full lifecycle — add (with a supplied key), get, query+shape,
 * update, delete, and store-backed meta — works when the primary key is a string
 * rather than a bigint.
 *
 * Tables are uninstalled after the class (DDL bypasses the per-test rollback).
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
use BerlinDB\Database\Presets\Meta\Query as MetaQuery;
use BerlinDB\Database\Presets\Meta\Table as MetaTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/** Schema with a varchar(36) UUID primary key (no auto_increment) + a meta sibling. */
class UuidThingSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'varchar',
			'length'        => '36',
			'primary'       => true,
			'relationships' => array(
				array(
					'query'  => UuidThingMetaQuery::class,
					'column' => 'thing_id',
					'type'   => 'has_many',
					'name'   => 'meta',
				),
			),
		),
		array(
			'name'   => 'label',
			'type'   => 'varchar',
			'length' => '50',
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Query over the UUID-keyed table. */
class UuidThingQuery extends Query {
	protected $prefix       = 'uuidpk';
	protected $table_name   = 'things';
	protected $table_schema = UuidThingSchema::class;
	protected $item_name    = 'thing';
	protected $cache_group  = 'uuidpk_things';

	public function expose_add_meta( $id, $key, $value, $unique = false ) {
		return $this->add_item_meta( $id, $key, $value, $unique );
	}

	public function expose_get_meta( $id, $key, $single = false ) {
		return $this->get_item_meta( $id, $key, $single );
	}
}

/** Table for the UUID-keyed schema. */
class UuidThingTable extends Table {
	protected $prefix  = 'uuidpk';
	protected $name    = 'things';
	protected $version = '1.0.0';
	protected $schema  = UuidThingSchema::class;
}

/** Meta Query + Table stubs (the FK mirrors the varchar(36) primary). */
class UuidThingMetaQuery extends MetaQuery {
	protected $primary_query_class = UuidThingQuery::class;
}
class UuidThingMetaTable extends MetaTable {
	protected $meta_query_class = UuidThingMetaQuery::class;
}

/** A varchar-keyed schema with NO meta relationship (forces the legacy WP path). */
class UuidNoStoreSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'varchar',
			'length'  => '36',
			'primary' => true,
		),
		array(
			'name'   => 'label',
			'type'   => 'varchar',
			'length' => '50',
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** A varchar-keyed query with no meta store; item meta falls to the legacy WP path. */
class UuidNoStoreQuery extends Query {
	protected $prefix       = 'uuidpk';
	protected $table_name   = 'nostore';
	protected $table_schema = UuidNoStoreSchema::class;
	protected $item_name    = 'uuid_nostore';
	protected $cache_group  = 'uuidpk_nostore';

	public function expose_add_meta( $id, $key, $value, $unique = false ) {
		return $this->add_item_meta( $id, $key, $value, $unique );
	}

	public function expose_get_meta( $id, $key, $single = false ) {
		return $this->get_item_meta( $id, $key, $single );
	}
}

/**
 * UUID primary-key tests.
 *
 * @since 3.1.0
 */
class UuidPrimaryKeyTest extends TestCase {

	/** @var UuidThingTable */
	private static $table;

	/** @var UuidThingMetaTable */
	private static $meta_table;

	/**
	 * Install the tables once.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table      = new UuidThingTable();
		self::$meta_table = new UuidThingMetaTable();

		if ( ! self::$table->exists() ) {
			self::$table->install();
		}

		if ( ! self::$meta_table->exists() ) {
			self::$meta_table->install();
		}
	}

	/**
	 * Uninstall the tables after the class.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$meta_table->uninstall();
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset rows + acting user before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
		self::$table->delete_all();
		self::$meta_table->delete_all();
		wp_cache_flush();
	}

	/**
	 * Full CRUD lifecycle against a UUID primary key.
	 *
	 * @since 3.1.0
	 */
	public function test_crud_with_uuid_primary_key() {
		$query = new UuidThingQuery();
		$uuid  = '550e8400-e29b-41d4-a716-446655440000';

		// add_item with a supplied UUID returns that UUID (not 0 / insert_id).
		$added = $query->add_item(
			array(
				'id'    => $uuid,
				'label' => 'alpha',
			)
		);
		$this->assertSame( $uuid, $added );

		// get_item by UUID returns the row.
		$item = $query->get_item( $uuid );
		$this->assertIsObject( $item );
		$this->assertSame( $uuid, $item->id );
		$this->assertSame( 'alpha', $item->label );

		// query( fields => all ) shapes the row, not a garbage object.
		$rows = (array) ( new UuidThingQuery() )->query( array( 'label' => 'alpha' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( $uuid, $rows[0]->id );
		$this->assertSame( 'alpha', $rows[0]->label );

		// update + delete by UUID.
		$this->assertTrue( $query->update_item( $uuid, array( 'label' => 'beta' ) ) );
		$this->assertSame( 'beta', $query->get_item( $uuid )->label );

		$this->assertTrue( $query->delete_item( $uuid ) );
		$this->assertEmpty( $query->get_item( $uuid ) );
	}

	/**
	 * Store-backed item meta works against a UUID-keyed object.
	 *
	 * The *_item_meta() routers accept a string/UUID ID and route it to the meta
	 * store (whose foreign key mirrors the varchar primary).
	 *
	 * @since 3.1.0
	 */
	public function test_store_backed_meta_with_uuid_primary_key() {
		$query = new UuidThingQuery();
		$store = new UuidThingMetaQuery();
		$uuid  = 'a1b2c3d4-e5f6-4789-abcd-ef0123456789';

		$query->add_item(
			array(
				'id'    => $uuid,
				'label' => 'gamma',
			)
		);

		// Routes to the store with a UUID object_id (previously is_int-rejected).
		$this->assertNotFalse( $query->expose_add_meta( $uuid, 'color', 'blue' ) );
		$this->assertSame( 'blue', $query->expose_get_meta( $uuid, 'color', true ) );

		// The store addresses the row by its UUID foreign key.
		$this->assertSame( array( 'blue' ), $store->get_meta( $uuid, 'color' ) );
	}

	/**
	 * copy_item() copies a UUID-keyed row to a new caller-supplied UUID.
	 *
	 * The new key must be preserved (a string PK cannot be auto-generated), unlike
	 * an auto-increment key which is dropped so the DB regenerates it.
	 *
	 * @since 3.1.0
	 */
	public function test_copy_item_with_uuid_primary_key() {
		$query = new UuidThingQuery();
		$src   = '11111111-1111-4111-8111-111111111111';
		$dst   = '22222222-2222-4222-8222-222222222222';

		$query->add_item(
			array(
				'id'    => $src,
				'label' => 'orig',
			)
		);

		// Copy to a new supplied UUID; copy_item() returns the new key.
		$copied = $query->copy_item( $src, array( 'id' => $dst ) );
		$this->assertSame( $dst, $copied );

		// Both rows exist, with the source data carried to the copy.
		$this->assertSame( 'orig', $query->get_item( $src )->label );
		$this->assertSame( 'orig', $query->get_item( $dst )->label );
	}

	/**
	 * The legacy WordPress metadata fallback refuses a non-int (UUID) ID.
	 *
	 * With no meta store, item meta would route to the integer-keyed {type}meta
	 * tables; a UUID is rejected (returns false) rather than silently cast to 0.
	 *
	 * @since 3.1.0
	 */
	public function test_legacy_meta_fallback_refuses_uuid_id() {
		$query = new UuidNoStoreQuery();
		$uuid  = '33333333-3333-4333-8333-333333333333';

		$this->assertFalse( $query->expose_add_meta( $uuid, 'color', 'blue' ) );
		$this->assertFalse( $query->expose_get_meta( $uuid, 'color', true ) );
	}
}
