<?php
/**
 * Meta Store interface.
 *
 * @package     BerlinDB\Database\Interfaces
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Interfaces;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Contract for anything that can store meta (key/value pairs) for an object.
 *
 * Method shapes and semantics mirror the WordPress metadata API
 * (add_metadata() etc.) so a store is a drop-in behavioral replacement:
 * multiple values per key, $unique/$single handling, maybe_serialize()-style
 * storage of non-scalars, and WP-compatible return values.
 *
 * Kern\Query routes its *_item_meta() methods to a store when its relationship
 * named 'meta' resolves to one - the accessor name picks WHICH relationship is
 * the canonical meta relationship; this interface proves the remote can
 * actually perform meta operations. Implementations: Presets\Meta\Query (the
 * custom sibling-table recipe); a WP-core-backed adapter is a candidate later.
 *
 * @since 3.1.0
 */
interface MetaStore {

	/**
	 * Add a meta entry for an object.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string $object_id  Object ID the meta belongs to.
	 * @param string     $meta_key   Meta key.
	 * @param mixed      $meta_value Meta value (serialized for storage if non-scalar).
	 * @param bool       $unique     Whether the key must be unique per object -
	 *                               when true and the key exists, nothing is added.
	 * @return int|false The new meta entry ID on success, false on failure.
	 */
	public function add_meta( int|string $object_id, string $meta_key, mixed $meta_value, bool $unique = false ): int|false;

	/**
	 * Get meta for an object.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string $object_id Object ID the meta belongs to.
	 * @param string     $meta_key  Meta key. Empty returns all meta for the object.
	 * @param bool       $single    Whether to return a single (first) value, or an
	 *                              array of all values for the key.
	 * @return mixed Single value when $single; array of values otherwise. An
	 *               empty string/array (respectively) when nothing matches.
	 */
	public function get_meta( int|string $object_id, string $meta_key = '', bool $single = false ): mixed;

	/**
	 * Update a meta entry for an object, adding it when absent.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string $object_id  Object ID the meta belongs to.
	 * @param string     $meta_key   Meta key.
	 * @param mixed      $meta_value New meta value.
	 * @param mixed      $prev_value Only update entries matching this previous
	 *                               value; empty updates all entries for the key.
	 * @return bool True when something was added or updated, false otherwise.
	 */
	public function update_meta( int|string $object_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): bool;

	/**
	 * Delete meta for an object.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string $object_id  Object ID the meta belongs to.
	 * @param string     $meta_key   Meta key.
	 * @param mixed      $meta_value Only delete entries matching this value;
	 *                               empty deletes all entries for the key.
	 * @param bool       $delete_all Whether to ignore $object_id and delete
	 *                               matching entries for ALL objects (the
	 *                               delete_metadata() semantic).
	 * @return bool True when something was deleted, false otherwise.
	 */
	public function delete_meta( int|string $object_id, string $meta_key, mixed $meta_value = '', bool $delete_all = false ): bool;

	/**
	 * Delete ALL meta for an object (every key), e.g. when the object is deleted.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string $object_id Object ID whose meta to purge.
	 * @return bool True when something was deleted, false otherwise.
	 */
	public function delete_all_meta( int|string $object_id ): bool;
}
