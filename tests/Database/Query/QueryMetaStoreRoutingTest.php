<?php
/**
 * MetaStore routing tests (#204 A1).
 *
 * Kern\Query's *_item_meta() methods are routers: when the relationship named
 * 'meta' resolves to a remote implementing Interfaces\MetaStore, operations
 * delegate to the store; otherwise they fall through to the legacy WordPress
 * metadata path. Both checks are required — the accessor name picks WHICH
 * relationship, the interface proves capability.
 *
 * Uses an in-memory MetaStore fixture (static state, since the resolver
 * instantiates a fresh remote per call). No meta database table required.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Interfaces\MetaStore;
use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** The remote's minimal schema (shape only; never queried). */
class RouteStoreSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'meta_id',
			'type'    => 'bigint',
			'primary' => true,
		),
		array(
			'name' => 'thing_id',
			'type' => 'bigint',
		),
	);
}

/** An in-memory MetaStore — static state survives fresh resolution per call. */
class RouteMemoryStoreQuery extends Query implements MetaStore {
	protected $prefix       = 'rt';
	protected $table_name   = 'thing_meta';
	protected $table_schema = RouteStoreSchema::class;
	protected $cache_group  = 'thing_meta';

	/** @var array<string, list<mixed>> Static key/value store: "id:key" => values. */
	public static $data = array();

	public function add_meta( int|string $object_id, string $meta_key, mixed $meta_value, bool $unique = false ): int|false {
		$slot = "{$object_id}:{$meta_key}";

		if ( $unique && ! empty( self::$data[ $slot ] ) ) {
			return false;
		}

		self::$data[ $slot ][] = $meta_value;

		return count( self::$data, COUNT_RECURSIVE );
	}

	public function get_meta( int|string $object_id, string $meta_key = '', bool $single = false ): mixed {
		$slot   = "{$object_id}:{$meta_key}";
		$values = self::$data[ $slot ] ?? array();

		return $single
			? ( $values[0] ?? '' )
			: $values;
	}

	public function update_meta( int|string $object_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): bool {
		self::$data[ "{$object_id}:{$meta_key}" ] = array( $meta_value );

		return true;
	}

	public function delete_meta( int|string $object_id, string $meta_key, mixed $meta_value = '', bool $delete_all = false ): bool {
		unset( self::$data[ "{$object_id}:{$meta_key}" ] );

		return true;
	}

	public function delete_all_meta( int|string $object_id ): bool {
		foreach ( array_keys( self::$data ) as $slot ) {
			if ( 0 === strpos( $slot, "{$object_id}:" ) ) {
				unset( self::$data[ $slot ] );
			}
		}

		return true;
	}
}

/** A remote that is a Query but NOT a MetaStore. */
class RoutePlainRemoteQuery extends Query {
	protected $prefix       = 'rt';
	protected $table_name   = 'plain_meta';
	protected $table_schema = RouteStoreSchema::class;
	protected $cache_group  = 'plain_meta';
}

/** Primary schema declaring 'meta' → the MetaStore fixture. */
class RouteThingSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'primary'       => true,
			'relationships' => array(
				array(
					'query'  => RouteMemoryStoreQuery::class,
					'column' => 'thing_id',
					'type'   => 'has_many',
					'name'   => 'meta',
				),
			),
		),
	);
}

/** Primary schema declaring 'meta' → a NON-store remote. */
class RoutePlainSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'primary'       => true,
			'relationships' => array(
				array(
					'query'  => RoutePlainRemoteQuery::class,
					'column' => 'thing_id',
					'type'   => 'has_many',
					'name'   => 'meta',
				),
			),
		),
	);
}

/** A primary with no relationships at all. */
class RouteBareSchema extends Schema {
	public $columns = array(
		array(
			'name'    => 'id',
			'type'    => 'bigint',
			'primary' => true,
		),
	);
}

/** Primary schema whose MetaStore relationship uses a DIFFERENT accessor name. */
class RouteWrongNameSchema extends Schema {
	public $columns = array(
		array(
			'name'          => 'id',
			'type'          => 'bigint',
			'primary'       => true,
			'relationships' => array(
				array(
					'query'  => RouteMemoryStoreQuery::class,
					'column' => 'thing_id',
					'type'   => 'has_many',
					'name'   => 'extra',
				),
			),
		),
	);
}

/** Exposes the protected *_item_meta() routers for testing. */
trait RouteExposesMeta {
	public function expose_add( $id, $key, $value, $unique = false ) {
		return $this->add_item_meta( $id, $key, $value, $unique );
	}

	public function expose_get( $id, $key, $single = false ) {
		return $this->get_item_meta( $id, $key, $single );
	}

	public function expose_update( $id, $key, $value, $prev = '' ) {
		return $this->update_item_meta( $id, $key, $value, $prev );
	}

	public function expose_delete( $id, $key, $value = '', $all = false ) {
		return $this->delete_item_meta( $id, $key, $value, $all );
	}
}

/** Primary wired to the in-memory store. */
class RouteThingQuery extends Query {
	use RouteExposesMeta;

	protected $prefix       = 'rt';
	protected $table_name   = 'things';
	protected $table_schema = RouteThingSchema::class;
	protected $item_name    = 'thing';
	protected $cache_group  = 'things';
}

/** Primary wired to a non-store remote (must fall back to the WP path). */
class RoutePlainThingQuery extends Query {
	use RouteExposesMeta;

	protected $prefix       = 'rt';
	protected $table_name   = 'plains';
	protected $table_schema = RoutePlainSchema::class;
	protected $item_name    = 'plain';
	protected $cache_group  = 'plains';
}

/** Primary with no meta relationship (must fall back to the WP path). */
class RouteBareThingQuery extends Query {
	use RouteExposesMeta;

	protected $prefix       = 'rt';
	protected $table_name   = 'bares';
	protected $table_schema = RouteBareSchema::class;
	protected $item_name    = 'bare';
	protected $cache_group  = 'bares';
}

/** Primary whose store-shaped relationship is named 'extra', not 'meta'. */
class RouteWrongNameQuery extends Query {
	use RouteExposesMeta;

	protected $prefix       = 'rt';
	protected $table_name   = 'wrongs';
	protected $table_schema = RouteWrongNameSchema::class;
	protected $item_name    = 'wrong';
	protected $cache_group  = 'wrongs';
}

/**
 * Tests for MetaStore routing and fallback.
 *
 * @since 3.1.0
 */
class QueryMetaStoreRoutingTest extends TestCase {

	/**
	 * Reset the in-memory store between tests.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		RouteMemoryStoreQuery::$data = array();
	}

	/**
	 * Add/get/update/delete route to the declared MetaStore.
	 *
	 * @since 3.1.0
	 */
	public function test_meta_operations_route_to_store() {
		$query = new RouteThingQuery();

		$this->assertNotFalse( $query->expose_add( 1, 'color', 'blue' ) );
		$this->assertSame( array( 'blue' ), $query->expose_get( 1, 'color' ) );
		$this->assertSame( 'blue', $query->expose_get( 1, 'color', true ) );

		$this->assertTrue( $query->expose_update( 1, 'color', 'red' ) );
		$this->assertSame( 'red', $query->expose_get( 1, 'color', true ) );

		$this->assertTrue( $query->expose_delete( 1, 'color' ) );
		$this->assertSame( array(), $query->expose_get( 1, 'color' ) );
	}

	/**
	 * The store honors $unique through the router.
	 *
	 * @since 3.1.0
	 */
	public function test_unique_routes_through() {
		$query = new RouteThingQuery();

		$this->assertNotFalse( $query->expose_add( 1, 'once', 'a', true ) );
		$this->assertFalse( $query->expose_add( 1, 'once', 'b', true ) );
	}

	/**
	 * The existing bails run BEFORE delegation (stable protected behavior).
	 *
	 * @since 3.1.0
	 */
	public function test_bails_run_before_delegation() {
		$query = new RouteThingQuery();

		// No key, and no usable ID: both bail without touching the store.
		$this->assertFalse( $query->expose_add( 1, '', 'value' ) );
		$this->assertFalse( $query->expose_add( 0, 'key', 'value' ) );
		$this->assertSame( array(), RouteMemoryStoreQuery::$data );
	}

	/**
	 * A 'meta' relationship to a non-store remote falls back to the WP path.
	 *
	 * No {plain}meta table is registered with WordPress, so the legacy path
	 * returns false — proving the non-store remote was not treated as a store.
	 *
	 * @since 3.1.0
	 */
	public function test_non_store_remote_falls_back() {
		$query = new RoutePlainThingQuery();

		$this->assertFalse( $query->expose_add( 1, 'color', 'blue' ) );
		$this->assertSame( array(), RouteMemoryStoreQuery::$data );
	}

	/**
	 * A query with no 'meta' relationship falls back to the WP path.
	 *
	 * @since 3.1.0
	 */
	public function test_no_meta_relationship_falls_back() {
		$query = new RouteBareThingQuery();

		$this->assertFalse( $query->expose_add( 1, 'color', 'blue' ) );
	}

	/**
	 * A MetaStore under any OTHER accessor name does not route.
	 *
	 * Both checks are required: the accessor name picks WHICH relationship is
	 * the canonical meta relationship. A store-shaped remote named 'extra' must
	 * not be treated as the item meta store.
	 *
	 * @since 3.1.0
	 */
	public function test_store_under_other_accessor_does_not_route() {
		$query = new RouteWrongNameQuery();

		$this->assertFalse( $query->expose_add( 1, 'color', 'blue' ) );
		$this->assertSame( array(), RouteMemoryStoreQuery::$data );
	}

	/**
	 * The private delete_all_item_meta() purge path routes to the store.
	 *
	 * Exercised via reflection: the public caller (delete_item()) needs an
	 * installed primary table, which belongs to the integration suite.
	 *
	 * @since 3.1.0
	 */
	public function test_delete_all_item_meta_routes_to_store() {
		$query = new RouteThingQuery();

		$query->expose_add( 1, 'color', 'blue' );
		$query->expose_add( 1, 'size', 'large' );
		$query->expose_add( 2, 'color', 'green' );

		$method = new \ReflectionMethod( $query, 'delete_all_item_meta' );
		$method->setAccessible( true );
		$method->invoke( $query, 1 );

		// Object 1's meta is purged; object 2's survives.
		$this->assertSame( array(), $query->expose_get( 1, 'color' ) );
		$this->assertSame( array(), $query->expose_get( 1, 'size' ) );
		$this->assertSame( array( 'green' ), $query->expose_get( 2, 'color' ) );
	}
}
