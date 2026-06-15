<?php
/**
 * MetaStore parity tests (#204 A1, commit 2).
 *
 * Presets\Meta\Query implements Interfaces\MetaStore with WordPress metadata API
 * parity: $single/$unique handling, multiple values per key, maybe_serialize()
 * round-trips, prev-value targeting, delete-exact vs delete-all-objects, and the
 * full-object purge. Verified against a real installed sibling table, both
 * directly and routed end-to-end through the primary's *_item_meta() methods,
 * plus get_related() in both directions.
 *
 * Tables are uninstalled after the class completes (DDL bypasses the WP test
 * framework's per-test transaction rollback).
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
use BerlinDB\Database\Presets\Meta\Query as MetaQuery;
use BerlinDB\Database\Presets\Meta\Table as MetaTable;
use Yoast\WPTestUtils\WPIntegration\TestCase;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** Primary schema — declares the has_many to its meta sibling. */
class GadgetSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'primary'       => true,
			'extra'         => 'auto_increment',
			'sortable'      => true,
			'relationships' => array(
				array(
					'query'  => GadgetMetaQuery::class,
					'column' => 'gadget_id',
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

/** Primary Query. */
class GadgetQuery extends Query {
	protected $prefix       = 'mps';
	protected $table_name   = 'gadgets';
	protected $table_schema = GadgetSchema::class;
	protected $item_name    = 'gadget';
	protected $cache_group  = 'gadgets';

	public function expose_add_meta( $id, $key, $value, $unique = false ) {
		return $this->add_item_meta( $id, $key, $value, $unique );
	}

	public function expose_get_meta( $id, $key, $single = false ) {
		return $this->get_item_meta( $id, $key, $single );
	}
}

/** Primary Table. */
class GadgetTable extends Table {
	protected $prefix  = 'mps';
	protected $name    = 'gadgets';
	protected $version = '1.0.0';
	protected $schema  = GadgetSchema::class;
}

/** The meta Query stub. */
class GadgetMetaQuery extends MetaQuery {
	protected $primary_query_class = GadgetQuery::class;
}

/** The meta Table stub. */
class GadgetMetaTable extends MetaTable {
	protected $meta_query_class = GadgetMetaQuery::class;
}

/** A minimal schema with NO meta relationship (forces the legacy WP path). */
class LegacyGadgetSchema extends Schema {
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'primary'  => true,
			'extra'    => 'auto_increment',
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
 * A Query with no meta store; its meta type resolves to the real wp_postmeta
 * table, so the legacy WordPress metadata path can be exercised in tests.
 */
class LegacyGadgetQuery extends Query {
	protected $prefix       = 'mps';
	protected $table_name   = 'legacy_gadgets';
	protected $table_schema = LegacyGadgetSchema::class;
	protected $item_name    = 'legacy_gadget';
	protected $cache_group  = 'legacy_gadgets';

	/** Resolve get_meta_table_name() to wp_postmeta (present in every WP test). */
	public function get_meta_type() {
		return 'post';
	}

	public function expose_add_meta( $id, $key, $value, $unique = false ) {
		return $this->add_item_meta( $id, $key, $value, $unique );
	}

	public function expose_get_meta( $id, $key, $single = false ) {
		return $this->get_item_meta( $id, $key, $single );
	}
}

/**
 * Tests for MetaStore parity.
 *
 * @since 3.1.0
 */
class MetaStoreParityTest extends TestCase {

	/**
	 * The installed primary table.
	 *
	 * @var GadgetTable
	 */
	private static $table;

	/**
	 * The installed meta sibling table.
	 *
	 * @var GadgetMetaTable
	 */
	private static $meta_table;

	/**
	 * Install both tables once for this class.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$table      = new GadgetTable();
		self::$meta_table = new GadgetMetaTable();

		if ( ! self::$table->exists() ) {
			self::$table->install();
		}

		if ( ! self::$meta_table->exists() ) {
			self::$meta_table->install();
		}
	}

	/**
	 * Uninstall both tables after all tests in this class complete.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$meta_table->uninstall();
		self::$table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Reset rows, caches, and the acting user before each test.
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
	 * Add returns the new meta ID; get returns all values or a single value.
	 *
	 * @since 3.1.0
	 */
	public function test_add_and_get() {
		$store = new GadgetMetaQuery();

		$added = $store->add_meta( 1, 'color', 'blue' );
		$this->assertIsInt( $added );
		$this->assertGreaterThan( 0, $added );

		$this->assertSame( array( 'blue' ), $store->get_meta( 1, 'color' ) );
		$this->assertSame( 'blue', $store->get_meta( 1, 'color', true ) );

		// Missing keys: empty array, or empty string when $single.
		$this->assertSame( array(), $store->get_meta( 1, 'nope' ) );
		$this->assertSame( '', $store->get_meta( 1, 'nope', true ) );
	}

	/**
	 * Multiple values per key accumulate, oldest first; $single returns the first.
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_values_per_key() {
		$store = new GadgetMetaQuery();

		$store->add_meta( 1, 'tag', 'first' );
		$store->add_meta( 1, 'tag', 'second' );

		$this->assertSame( array( 'first', 'second' ), $store->get_meta( 1, 'tag' ) );
		$this->assertSame( 'first', $store->get_meta( 1, 'tag', true ) );
	}

	/**
	 * A $unique add refuses when the key already exists for the object.
	 *
	 * @since 3.1.0
	 */
	public function test_unique_add_refuses_duplicate() {
		$store = new GadgetMetaQuery();

		$this->assertIsInt( $store->add_meta( 1, 'sku', 'A-1', true ) );
		$this->assertFalse( $store->add_meta( 1, 'sku', 'B-2', true ) );
		$this->assertSame( array( 'A-1' ), $store->get_meta( 1, 'sku' ) );
	}

	/**
	 * An empty key returns ALL of the object's meta, grouped by key.
	 *
	 * @since 3.1.0
	 */
	public function test_get_all_meta_grouped_by_key() {
		$store = new GadgetMetaQuery();

		$store->add_meta( 1, 'color', 'blue' );
		$store->add_meta( 1, 'tag', 'first' );
		$store->add_meta( 1, 'tag', 'second' );

		$all = $store->get_meta( 1 );

		$this->assertSame( array( 'blue' ), $all['color'] );
		$this->assertSame( array( 'first', 'second' ), $all['tag'] );
	}

	/**
	 * Update adds when absent, updates in place, and no-ops on identical values.
	 *
	 * @since 3.1.0
	 */
	public function test_update_semantics() {
		$store = new GadgetMetaQuery();

		// Adds when the key does not exist.
		$this->assertTrue( $store->update_meta( 1, 'status', 'new' ) );
		$this->assertSame( 'new', $store->get_meta( 1, 'status', true ) );

		// Updates in place.
		$this->assertTrue( $store->update_meta( 1, 'status', 'used' ) );
		$this->assertSame( 'used', $store->get_meta( 1, 'status', true ) );

		// Identical single value is a no-op returning false (WP parity).
		$this->assertFalse( $store->update_meta( 1, 'status', 'used' ) );
	}

	/**
	 * A $prev_value only updates entries matching it.
	 *
	 * @since 3.1.0
	 */
	public function test_update_with_prev_value() {
		$store = new GadgetMetaQuery();

		$store->add_meta( 1, 'tag', 'first' );
		$store->add_meta( 1, 'tag', 'second' );

		// Only the matching entry updates.
		$this->assertTrue( $store->update_meta( 1, 'tag', 'changed', 'second' ) );
		$this->assertSame( array( 'first', 'changed' ), $store->get_meta( 1, 'tag' ) );

		// A non-matching previous value updates nothing.
		$this->assertFalse( $store->update_meta( 1, 'tag', 'x', 'missing' ) );
	}

	/**
	 * Non-scalar values round-trip through serialization.
	 *
	 * @since 3.1.0
	 */
	public function test_serialization_round_trip() {
		$store = new GadgetMetaQuery();
		$value = array(
			'a' => 1,
			'b' => array( 'nested' => true ),
		);

		$store->add_meta( 1, 'config', $value );

		$this->assertSame( $value, $store->get_meta( 1, 'config', true ) );
	}

	/**
	 * Delete: by exact value, by key, and across all objects with $delete_all.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_semantics() {
		$store = new GadgetMetaQuery();

		$store->add_meta( 1, 'tag', 'keep' );
		$store->add_meta( 1, 'tag', 'drop' );
		$store->add_meta( 2, 'tag', 'drop' );

		// A truthy value only deletes matching entries for the object.
		$this->assertTrue( $store->delete_meta( 1, 'tag', 'drop' ) );
		$this->assertSame( array( 'keep' ), $store->get_meta( 1, 'tag' ) );
		$this->assertSame( array( 'drop' ), $store->get_meta( 2, 'tag' ) );

		// An empty value deletes all entries for the key on that object.
		$this->assertTrue( $store->delete_meta( 1, 'tag' ) );
		$this->assertSame( array(), $store->get_meta( 1, 'tag' ) );

		// Nothing left to delete returns false.
		$this->assertFalse( $store->delete_meta( 1, 'tag' ) );

		// $delete_all ignores the object ID and clears the key everywhere.
		$store->add_meta( 1, 'tag', 'drop' );
		$this->assertTrue( $store->delete_meta( 0, 'tag', '', true ) );
		$this->assertSame( array(), $store->get_meta( 1, 'tag' ) );
		$this->assertSame( array(), $store->get_meta( 2, 'tag' ) );
	}

	/**
	 * A '0' value is a real delete filter, not "no filter" (core parity).
	 *
	 * @since 3.1.0
	 */
	public function test_delete_with_zero_string_value_filters() {
		$store = new GadgetMetaQuery();

		$store->add_meta( 1, 'rating', '0' );
		$store->add_meta( 1, 'rating', '5' );

		$this->assertTrue( $store->delete_meta( 1, 'rating', '0' ) );
		$this->assertSame( array( '5' ), $store->get_meta( 1, 'rating' ) );
	}

	/**
	 * A '0' previous value is NOT a filter — core's empty() semantics.
	 *
	 * @since 3.1.0
	 */
	public function test_update_with_zero_string_prev_value_is_no_filter() {
		$store = new GadgetMetaQuery();

		$store->add_meta( 1, 'flag', '0' );
		$store->add_meta( 1, 'flag', '1' );

		// '0' is empty(), so every entry for the key updates.
		$this->assertTrue( $store->update_meta( 1, 'flag', 'x', '0' ) );
		$this->assertSame( array( 'x', 'x' ), $store->get_meta( 1, 'flag' ) );
	}

	/**
	 * delete_all_meta() purges every key for one object only.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_all_meta_purges_one_object() {
		$store = new GadgetMetaQuery();

		$store->add_meta( 1, 'color', 'blue' );
		$store->add_meta( 1, 'tag', 'first' );
		$store->add_meta( 2, 'color', 'green' );

		$this->assertTrue( $store->delete_all_meta( 1 ) );
		$this->assertSame( array(), $store->get_meta( 1 ) );
		$this->assertSame( array( 'green' ), $store->get_meta( 2, 'color' ) );
	}

	/**
	 * The primary's *_item_meta() methods route to this store end-to-end.
	 *
	 * @since 3.1.0
	 */
	public function test_routed_end_to_end_through_primary() {
		$gadgets = new GadgetQuery();
		$store   = new GadgetMetaQuery();

		$this->assertNotFalse( $gadgets->expose_add_meta( 1, 'color', 'blue' ) );

		// Visible through both the router and the store directly.
		$this->assertSame( 'blue', $gadgets->expose_get_meta( 1, 'color', true ) );
		$this->assertSame( array( 'blue' ), $store->get_meta( 1, 'color' ) );
	}

	/**
	 * Bulk meta — extra non-column keys in add_item() — routes to the store.
	 *
	 * The WordPress registered-meta-keys gate is intentionally skipped when a
	 * store is declared: the 'meta' relationship IS the registration.
	 *
	 * @since 3.1.0
	 */
	public function test_bulk_meta_on_add_item_routes_to_store() {
		$gadgets = new GadgetQuery();
		$store   = new GadgetMetaQuery();

		// 'color' is not a gadget column, so it flows to save_extra_item_meta().
		$gadget_id = $gadgets->add_item(
			array(
				'label' => 'widget',
				'color' => 'blue',
			)
		);

		$this->assertIsInt( $gadget_id );
		$this->assertSame( 'blue', $store->get_meta( $gadget_id, 'color', true ) );
	}

	/**
	 * Bulk meta on update_item() updates values and deletes empty ones.
	 *
	 * @since 3.1.0
	 */
	public function test_bulk_meta_on_update_item_routes_to_store() {
		$gadgets = new GadgetQuery();
		$store   = new GadgetMetaQuery();

		$gadget_id = $gadgets->add_item(
			array(
				'label' => 'widget',
				'color' => 'blue',
				'tag'   => 'first',
			)
		);

		// Non-empty values update; empty values delete (legacy bulk semantics).
		$gadgets->update_item(
			$gadget_id,
			array(
				'label' => 'gizmo',
				'color' => 'red',
				'tag'   => '',
			)
		);

		$this->assertSame( 'red', $store->get_meta( $gadget_id, 'color', true ) );
		$this->assertSame( array(), $store->get_meta( $gadget_id, 'tag' ) );
	}

	/**
	 * A meta-only update_item() reports success when the store write succeeds.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_only_update_item_returns_true() {
		$gadgets = new GadgetQuery();
		$store   = new GadgetMetaQuery();

		$gadget_id = $gadgets->add_item( array( 'label' => 'widget' ) );

		// Only an extra (meta) key — no columns change.
		$this->assertTrue( $gadgets->update_item( $gadget_id, array( 'color' => 'red' ) ) );
		$this->assertSame( 'red', $store->get_meta( $gadget_id, 'color', true ) );

		// An identical meta-only value changes nothing, so it reports false.
		$this->assertFalse( $gadgets->update_item( $gadget_id, array( 'color' => 'red' ) ) );
	}

	/**
	 * Deleting an item purges all of its meta through the store.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_item_purges_meta_through_store() {
		$gadgets = new GadgetQuery();
		$store   = new GadgetMetaQuery();

		$gadget_id = $gadgets->add_item( array( 'label' => 'doomed' ) );
		$keeper_id = $gadgets->add_item( array( 'label' => 'keeper' ) );

		$store->add_meta( $gadget_id, 'color', 'blue' );
		$store->add_meta( $gadget_id, 'tag', 'first' );
		$store->add_meta( $keeper_id, 'color', 'green' );

		$this->assertTrue( $gadgets->delete_item( $gadget_id ) );

		// The deleted item's meta is purged; the survivor's is intact.
		$this->assertSame( array(), $store->get_meta( $gadget_id ) );
		$this->assertSame( array( 'green' ), $store->get_meta( $keeper_id, 'color' ) );
	}

	/**
	 * get_related() resolves both directions over real rows.
	 *
	 * @since 3.1.0
	 */
	public function test_get_related_round_trip() {
		$gadgets = new GadgetQuery();
		$store   = new GadgetMetaQuery();

		// Create a real gadget and two meta rows for it.
		$gadget_id = $gadgets->add_item( array( 'label' => 'widget' ) );
		$this->assertIsInt( $gadget_id );

		$store->add_meta( $gadget_id, 'color', 'blue' );
		$store->add_meta( $gadget_id, 'size', 'large' );

		// Primary -> meta (has_many): the full child set.
		$item = $gadgets->get_item( $gadget_id );
		$this->assertIsObject( $item );

		$meta_rows = $gadgets->get_related( $item, 'meta' );
		$this->assertIsArray( $meta_rows );
		$this->assertCount( 2, $meta_rows );

		// Meta -> primary (belongs_to): back to the same gadget.
		$parent = $store->get_related( $meta_rows[0], 'gadget' );
		$this->assertInstanceOf( Row::class, $parent );
		$this->assertEquals( $gadget_id, $parent->id );
	}

	/**
	 * get_item_meta() with an empty key reads ALL meta through the store router.
	 *
	 * @since 3.1.0
	 */
	public function test_routed_all_meta_read_with_empty_key() {
		$gadgets   = new GadgetQuery();
		$gadget_id = $gadgets->add_item( array( 'label' => 'widget' ) );

		$gadgets->expose_add_meta( $gadget_id, 'color', 'blue' );
		$gadgets->expose_add_meta( $gadget_id, 'size', 'large' );

		// Empty key is no longer rejected: it returns all meta, grouped by key.
		$all = $gadgets->expose_get_meta( $gadget_id, '' );

		$this->assertIsArray( $all );
		$this->assertSame( array( 'blue' ), $all['color'] );
		$this->assertSame( array( 'large' ), $all['size'] );
	}

	/**
	 * get_item_meta() with an empty key reads ALL meta through the legacy WP path.
	 *
	 * Uses a query whose meta type resolves to the real wp_postmeta table (no meta
	 * store declared), proving the empty-key guard removal serves both paths.
	 *
	 * @since 3.1.0
	 */
	public function test_legacy_all_meta_read_with_empty_key() {
		global $wpdb;

		$legacy = new LegacyGadgetQuery();
		$id     = 4242;

		// Clean any lingering rows from a previous run, then seed two keys.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id = {$id}" ); // phpcs:ignore WordPress.DB

		$legacy->expose_add_meta( $id, 'berlindb_legacy_color', 'blue' );
		$legacy->expose_add_meta( $id, 'berlindb_legacy_size', 'large' );

		// Empty key reaches get_metadata(), which returns all meta grouped by key.
		$all = $legacy->expose_get_meta( $id, '' );

		$this->assertIsArray( $all );
		$this->assertArrayHasKey( 'berlindb_legacy_color', $all );
		$this->assertArrayHasKey( 'berlindb_legacy_size', $all );

		// Clean up: postmeta DDL bypasses the per-test transaction rollback.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id = {$id}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * No-store bulk meta honors the register_meta() gate: only registered keys save.
	 *
	 * With no meta store declared, save_extra_item_meta() intersects the bulk keys
	 * with get_registered_meta_keys() before writing — the counterpart to the
	 * store path, which intentionally skips that gate. Drives the private no-store
	 * path directly (no primary table needed) against the real wp_postmeta table.
	 *
	 * @since 3.1.0
	 */
	public function test_no_store_bulk_meta_honors_register_meta_gate() {
		global $wpdb;

		$legacy = new LegacyGadgetQuery();
		$id     = 4343;

		// Clean any lingering rows from a previous run.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id = {$id}" ); // phpcs:ignore WordPress.DB

		// Register exactly ONE of the two bulk keys.
		register_meta(
			'post',
			'berlindb_registered',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => false,
			)
		);

		// Drive the private no-store bulk path directly.
		$method = new \ReflectionMethod( LegacyGadgetQuery::class, 'save_extra_item_meta' );
		$method->setAccessible( true );

		$saved = $method->invoke(
			$legacy,
			$id,
			array(
				'berlindb_registered'   => 'kept',
				'berlindb_unregistered' => 'dropped',
			)
		);

		// At least one write succeeded.
		$this->assertTrue( $saved );

		// The registered key persisted; the unregistered key was gated out.
		$this->assertSame( 'kept', get_metadata( 'post', $id, 'berlindb_registered', true ) );
		$this->assertSame( '', get_metadata( 'post', $id, 'berlindb_unregistered', true ) );

		// Clean up.
		unregister_meta_key( 'post', 'berlindb_registered' );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id = {$id}" ); // phpcs:ignore WordPress.DB
	}
}
