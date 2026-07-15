<?php
/**
 * Query Meta Trait Class.
 *
 * @package     Database
 * @subpackage  Query
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Kern\Relationship;

/**
 * Item-meta routing for a Query.
 *
 * Composed onto Query, sharing its $this run-state. Memoizes the resolved meta
 * store in its own $meta_store property and routes the *_item_meta() API to
 * either WordPress core metadata (when this object's table has its own meta
 * table) or a related 'meta' relationship store, and owns the meta-table and
 * meta-type helpers.
 *
 * @since 3.1.0
 */
trait Meta {

	/**
	 * Memoized 'meta' relationship store, resolved lazily by get_meta_store().
	 *
	 * Reused across this instance's *_item_meta() calls because building the store
	 * instantiates the remote meta Query (and its primary) - wasteful to repeat.
	 *
	 * Three states in one property: the sentinel `false` means "not resolved yet";
	 * `null` is a valid resolution (this object has no meta store); a MetaStore is
	 * the resolved store.
	 *
	 * @since 3.1.0
	 * @var   \BerlinDB\Database\Interfaces\MetaStore|null|false
	 */
	private $meta_store = false;

	/**
	 * Resolve this object's meta store, when it has one (memoized per instance).
	 *
	 * The *_item_meta() methods are routers: when this object's relationship
	 * named 'meta' resolves to a remote implementing Interfaces\MetaStore, meta
	 * operations delegate to that store (the custom sibling-table path);
	 * otherwise they fall through to the legacy WordPress metadata API. Both
	 * checks are required - the accessor name picks WHICH relationship is the
	 * canonical meta relationship; the interface proves the remote can actually
	 * perform meta operations.
	 *
	 * The result is memoized on this instance ($meta_store, with `false` as the
	 * "not resolved yet" sentinel since `null` validly means "no store") and reused
	 * by every subsequent *_item_meta() call. Why: resolving the store
	 * instantiates the remote meta Query - which, for the Meta preset, also builds
	 * its primary Query and schema (~0.5ms) - so a loop of per-item meta operations
	 * on the same Query would otherwise pay that cost on every call.
	 *
	 * Reuse is safe: the store is addressed by object ID *per method call* (nothing
	 * is baked into the instance for a specific object), each store operation runs
	 * its own lifecycle (per-run ephemeral state is reset by run()), and its reads
	 * go through the standard query cache with last_changed invalidation - so a
	 * memoized store never serves stale meta. (The test suite already reuses a
	 * single store instance across many operations, exercising this.) The cache is
	 * not invalidated during the instance's life because a Query's declared
	 * relationships are fixed at construction.
	 *
	 * @since 3.1.0
	 *
	 * @return \BerlinDB\Database\Interfaces\MetaStore|null The store, or null.
	 */
	private function get_meta_store(): ?\BerlinDB\Database\Interfaces\MetaStore {

		/*
		 * Return the memoized result. `false` means "not resolved yet"; `null` is a
		 * valid resolution (this object has no meta store).
		 */
		if ( false !== $this->meta_store ) {
			return $this->meta_store;
		}

		// Resolve the remote behind the relationship named 'meta', if declared.
		$relationship = $this->get_relationship( 'meta' );
		$remote       = ( $relationship instanceof Relationship )
			? $this->resolve_remote_query( $relationship )
			: null;

		// Cache the store (it must prove capability via the contract), or null.
		$this->meta_store = ( $remote instanceof \BerlinDB\Database\Interfaces\MetaStore )
			? $remote
			: null;

		return $this->meta_store;
	}

	/**
	 * Add meta data to an item.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Routes to the meta store when one is declared (see get_meta_store()).
	 *
	 * @param int|string $item_id Item ID.
	 * @param string     $meta_key Meta key.
	 * @param string     $meta_value Meta value.
	 * @param bool       $unique Whether the meta key should be unique per item.
	 * @return int|false The meta ID on success, false on failure.
	 */
	protected function add_item_meta( $item_id = 0, $meta_key = '', $meta_value = '', $unique = false ) {

		// Bail if this query is read-only (e.g. a view); meta writes are refused too.
		if ( ! $this->can_write() ) {
			return false;
		}

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail without a usable item ID or a meta key.
		if ( empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Route to the meta store when one is declared (accepts a string/UUID ID).
		$store = $this->get_meta_store();
		if ( null !== $store ) {
			return $store->add_meta( $item_id, $meta_key, $meta_value, (bool) $unique );
		}

		// The legacy WordPress metadata fallback is integer-keyed ({type}meta).
		if ( ! is_int( $item_id ) ) {
			return false;
		}

		// Bail if no meta table exists.
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		// Return results of adding meta data.
		return add_metadata( $meta_type, $item_id, $meta_key, $meta_value, $unique );
	}

	/**
	 * Get meta data for an item.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Routes to the meta store when one is declared (see get_meta_store()).
	 *
	 * @param int|string $item_id Item ID.
	 * @param string     $meta_key Meta key.
	 * @param bool       $single Whether to return a single value.
	 * @return mixed Single metadata value, or array of values
	 */
	protected function get_item_meta( $item_id = 0, $meta_key = '', $single = false ) {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		/*
		 * Bail without a usable item ID. An empty meta key IS allowed: it reads ALL
		 * meta for the item, a shape both the meta store and get_metadata() support.
		 */
		if ( empty( $item_id ) ) {
			return false;
		}

		// Route to the meta store when one is declared (accepts a string/UUID ID).
		$store = $this->get_meta_store();
		if ( null !== $store ) {
			return $store->get_meta( $item_id, $meta_key, (bool) $single );
		}

		// The legacy WordPress metadata fallback is integer-keyed ({type}meta).
		if ( ! is_int( $item_id ) ) {
			return false;
		}

		// Bail if no meta table exists.
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		// Return results of getting meta data.
		return get_metadata( $meta_type, $item_id, $meta_key, $single );
	}

	/**
	 * Update meta data for an item.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Routes to the meta store when one is declared (see get_meta_store()).
	 *
	 * @param int|string $item_id Item ID.
	 * @param string     $meta_key Meta key.
	 * @param string     $meta_value Meta value.
	 * @param string     $prev_value Previous meta value to target when updating.
	 * @return bool True on successful update, false on failure.
	 */
	protected function update_item_meta( $item_id = 0, $meta_key = '', $meta_value = '', $prev_value = '' ) {

		// Bail if this query is read-only (e.g. a view); meta writes are refused too.
		if ( ! $this->can_write() ) {
			return false;
		}

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail without a usable item ID or a meta key.
		if ( empty( $item_id ) || empty( $meta_key ) ) {
			return false;
		}

		// Route to the meta store when one is declared (accepts a string/UUID ID).
		$store = $this->get_meta_store();
		if ( null !== $store ) {
			return $store->update_meta( $item_id, $meta_key, $meta_value, $prev_value );
		}

		// The legacy WordPress metadata fallback is integer-keyed ({type}meta).
		if ( ! is_int( $item_id ) ) {
			return false;
		}

		// Bail if no meta table exists.
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		// Return results of updating meta data.
		return (bool) update_metadata( $meta_type, $item_id, $meta_key, $meta_value, $prev_value );
	}

	/**
	 * Delete meta data for an item.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Routes to the meta store when one is declared (see get_meta_store()).
	 *
	 * @param int|string $item_id Item ID.
	 * @param string     $meta_key Meta key.
	 * @param string     $meta_value Meta value.
	 * @param bool       $delete_all Whether to delete all entries regardless of value.
	 * @return bool True on successful delete, false on failure.
	 */
	protected function delete_item_meta( $item_id = 0, $meta_key = '', $meta_value = '', $delete_all = false ) {

		// Bail if this query is read-only (e.g. a view); meta writes are refused too.
		if ( ! $this->can_write() ) {
			return false;
		}

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// A meta key is always required.
		if ( empty( $meta_key ) ) {
			return false;
		}

		/*
		 * A global purge ($delete_all) deletes the key across every object, so it
		 * ignores the item ID - the store and delete_metadata() both do. Otherwise
		 * require a valid integer ID (metadata requires integer IDs).
		 */
		if ( empty( $delete_all ) && empty( $item_id ) ) {
			return false;
		}

		// Route to the meta store when one is declared (accepts a string/UUID ID).
		$store = $this->get_meta_store();
		if ( null !== $store ) {
			return $store->delete_meta( $item_id, $meta_key, $meta_value, (bool) $delete_all );
		}

		/*
		 * The legacy WordPress metadata fallback is integer-keyed ({type}meta); a
		 * global purge ignores the ID, so a non-int ID only blocks per-object deletes.
		 */
		if ( empty( $delete_all ) && ! is_int( $item_id ) ) {
			return false;
		}

		// Bail if no meta table exists.
		if ( false === $this->get_meta_table_name() ) {
			return false;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		/*
		 * Return results of deleting meta data. The id is an int on the per-object
		 * path (guarded above) and ignored by delete_metadata() when $delete_all.
		 */
		return delete_metadata( $meta_type, (int) $item_id, $meta_key, $meta_value, $delete_all );
	}

	/**
	 * Get registered meta data keys.
	 *
	 * @since 1.0.0
	 *
	 * @param string $object_subtype The sub-type of meta keys.
	 *
	 * @return array<string,mixed>
	 */
	private function get_registered_meta_keys( $object_subtype = '' ): array {

		// Get the object type.
		$object_type = $this->get_meta_type();

		// Return the keys.
		return get_registered_meta_keys( $object_type, $object_subtype );
	}

	/**
	 * Maybe update meta values on item update/save.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Routes to the meta store when one is declared (see
	 *              get_meta_store()), and returns whether any write succeeded.
	 *
	 * @param int|string           $item_id Item ID.
	 * @param array<string,mixed> $meta Array of meta key/value pairs.
	 * @return bool True when any per-key write (update or delete) succeeded.
	 */
	private function save_extra_item_meta( $item_id = 0, $meta = array() ): bool {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if there is no bulk meta to save.
		if ( empty( $item_id ) || empty( $meta ) ) {
			return false;
		}

		/*
		 * The legacy WordPress path applies two gates: a registered {type}meta
		 * table must exist, and only register_meta()'d keys are saved. When a
		 * meta store is declared, both are intentionally skipped - the WP
		 * registry is a WP-core-types concept, and for a custom sibling table
		 * the declared 'meta' relationship IS the registration.
		 */
		$store = $this->get_meta_store();
		if ( null === $store ) {

			// Bail if no meta table exists.
			if ( false === $this->get_meta_table_name() ) {
				return false;
			}

			// Only save registered keys.
			$keys = $this->get_registered_meta_keys();
			$meta = array_intersect_key( $meta, $keys );

			// Bail if no registered meta keys.
			if ( empty( $meta ) ) {
				return false;
			}
		}

		// Default return value.
		$retval = false;

		/*
		 * Save or delete meta data - directly on the store when one is declared
		 * (resolved once above), else through the legacy WordPress helpers.
		 */
		foreach ( $meta as $key => $value ) {

			if ( null !== $store ) {
				$saved = ! empty( $value )
					? $store->update_meta( $item_id, $key, $value )
					: $store->delete_meta( $item_id, $key );
			} else {
				$saved = ! empty( $value )
					? $this->update_item_meta( $item_id, $key, $value )
					: $this->delete_item_meta( $item_id, $key );
			}

			if ( ! empty( $saved ) ) {
				$retval = true;
			}
		}

		return $retval;
	}

	/**
	 * Delete all meta data for an item.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 Routes to the meta store when one is declared (see get_meta_store()).
	 *
	 * @param int|string $item_id Item ID.
	 */
	private function delete_all_item_meta( $item_id = 0 ): void {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID.
		if ( empty( $item_id ) ) {
			return;
		}

		// Route to the meta store when one is declared.
		$store = $this->get_meta_store();
		if ( null !== $store ) {
			$store->delete_all_meta( $item_id );

			return;
		}

		// Get the meta table name.
		$table = $this->get_meta_table_name();

		// Bail if no meta table exists.
		if ( empty( $table ) ) {
			return;
		}

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		/*
		 * The meta table's object-id column is {meta_type}_{primary} (get_meta_type()
		 * already prefixes / honors an explicit type, so it is not re-prefixed here). This
		 * is equivalent to the old item-name derivation when the type is unset.
		 */
		$item_id_column  = $this->get_meta_type() . '_' . $primary;
		$item_id_pattern = $this->get_column_field( array( 'name' => $primary ), 'pattern', '%s' );

		// Get meta IDs.
		$query    = "SELECT meta_id FROM {$table} WHERE {$item_id_column} = {$item_id_pattern}";
		$prepared = $this->db()->prepare( $query, $item_id );
		$meta_ids = $this->db()->get_col( $prepared );

		// Bail if no meta IDs to delete.
		if ( empty( $meta_ids ) ) {
			return;
		}

		// Get the meta type.
		$meta_type = $this->get_meta_type();

		// Delete all meta data for this item ID.
		foreach ( $meta_ids as $mid ) {
			delete_metadata_by_mid( $meta_type, $mid );
		}
	}

	/**
	 * Get the meta table for this query.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Minor refactor to improve readability.
	 *
	 * @return string|false Table name if exists, false if not.
	 */
	private function get_meta_table_name(): string|false {

		// Get the meta type.
		$type = $this->get_meta_type();

		// Append "meta" to end of meta type.
		$table = "{$type}meta";

		// If not empty, return table name.
		$table_name = $this->db()->get_table_prefix( $table );
		if ( ! empty( $table_name ) ) {
			return $table_name;
		}

		// Return.
		return false;
	}

	/**
	 * Get the meta type for this query.
	 *
	 * The explicit `$meta_type` property when set (the first-class source), else the
	 * (prefixed) item name as a legacy fallback - which is correct only when `item_name`
	 * happens to equal the WordPress object type. It is the single source of truth for the
	 * meta table name (`{type}meta`) and its object-id column (`{type}_id`), so a Query
	 * whose item name is namespaced (`wpct_post`) can set `$meta_type = 'post'` instead of
	 * overriding this method.
	 *
	 * @since 1.1.0
	 * @since 3.1.0 Prefers the explicit `$meta_type` property; the item-name derivation is
	 *              the fallback.
	 *
	 * @return string
	 */
	public function get_meta_type() {
		return ( '' !== $this->meta_type )
			? $this->meta_type
			: $this->apply_prefix( $this->item_name );
	}
}
