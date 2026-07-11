<?php
/**
 * Query Crud Trait Class.
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

use BerlinDB\Database\Kern\Column;

/**
 * Create / read / update / delete operations on individual items for a Query.
 *
 * Composed onto Query, sharing its $this run-state. Reads a single item
 * (get_item / get_item_by / get_item_raw), writes one (add_item / copy_item /
 * update_item / delete_item), delegates the set verbs to Operations\Delete /
 * Update / Add, and owns the per-item write helpers (validate_item /
 * intercept_item / reduce_item / default_item / transition_item). The SELECT
 * read pipeline that returns many items lives in the Execution trait.
 *
 * @since 3.1.0
 */
trait Crud {

	/**
	 * Get a single database row by any column and value, skipping cache.
	 *
	 * The singular front door to get_items_raw(): a prepared "{column} = {value}"
	 * predicate with LIMIT 1, returning the one matching raw row (or false).
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses is_valid_column()
	 * @since 3.1.0 Reads through the shared get_items_raw() reader.
	 *
	 * @param string $column_name  Name of database column.
	 * @param mixed  $column_value Value to query for.
	 * @return object|false False if empty/error, Object if successful
	 */
	private function get_item_raw( $column_name = '', $column_value = '' ): object|false {

		/*
		 * Bail if value is non-scalar, boolean false, or empty string.
		 * Intentionally allows 0 and '0' - both are valid column values.
		 */
		if ( ! is_scalar( $column_value ) || false === $column_value || '' === $column_value ) {
			return false;
		}

		// Bail if invalid column.
		if ( ! $this->is_valid_column( $column_name ) ) {
			return false;
		}

		/*
		 * Prepare the "{column} = {value}" predicate for this column. The column is
		 * quoted (it is already schema-validated above) so a legal but RESERVED name
		 * (e.g. `order`, `key`) produces valid SQL rather than a syntax error.
		 */
		$pattern    = $this->get_column_field( array( 'name' => $column_name ), 'pattern', '%s' );
		$column_sql = $this->get_quoted_column_name_aliased( $column_name, false );
		$where      = $this->db()->prepare( "{$column_sql} = {$pattern}", $column_value );

		// Bail if the value could not be prepared.
		if ( null === $where ) {
			return false;
		}

		// Read the one matching raw (uncached) row via the shared reader.
		$rows = $this->get_items_raw( "{$where} LIMIT 1" );

		// Bail if no row matched.
		return isset( $rows[0] ) && is_object( $rows[0] )
			? $rows[0]
			: false;
	}

	/**
	 * Read the raw (unshaped, uncached) rows matching a WHERE fragment.
	 *
	 * The plural, by-WHERE counterpart to get_item_raw(): raw stdClass rows straight
	 * from the connection, before item_shape() turns them into Row objects and with no
	 * cache side effect - "raw" in both senses. It is the one SELECT * reader shared by
	 * get_item_raw() (a LIMIT 1 lookup), prime_item_caches() (primary IN), prime_has_many()
	 * (foreign key IN), and composite tuple priming (OR-of-ANDs). The caller builds and
	 * escapes the whole fragment - so it may carry a trailing LIMIT / ORDER BY - and, like
	 * get_item_by() does after get_item_raw(), owns any cache priming afterward.
	 *
	 * @since 3.1.0
	 *
	 * @param string $where Prepared SQL after "WHERE" (predicate plus any LIMIT / ORDER BY).
	 * @return list<object> The matching raw rows (empty when $where is '').
	 */
	protected function get_items_raw( string $where ): array {

		// Nothing to read without a WHERE.
		if ( '' === $where ) {
			return array();
		}

		// One bulk read of every matching row.
		$table   = $this->get_table_name();
		$results = $this->db()->get_results( "SELECT * FROM {$table} WHERE {$where}" );

		// Bail if nothing came back.
		if ( empty( $results ) || ! is_array( $results ) ) {
			return array();
		}

		/** @var list<object> $results */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		return $results;
	}

	/**
	 * Get a single database row by the primary column ID, possibly from cache.
	 *
	 * Accepts an integer, object, or array, and attempts to get the ID from it,
	 * then attempts to retrieve that item fresh from the database or cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string|array<string,mixed>|object $item_id The ID of the item.
	 * @return object|false False if empty/error, Object if successful.
	 */
	public function get_item( $item_id = 0 ) {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item to get by.
		if ( empty( $item_id ) ) {
			return false;
		}

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Get item by ID.
		return $this->get_item_by( $primary, $item_id );
	}

	/**
	 * Get a single database row by any column and value, possibly from cache.
	 *
	 * Take care to only use this method on columns with unique values,
	 * preferably with a cache group for that column.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $column_name  Name of database column.
	 * @param int|string $column_value Value to query for.
	 * @return object|false False if empty/error, Object if successful
	 */
	public function get_item_by( $column_name = '', $column_value = '' ) {

		// Default return value.
		$retval = false;

		/*
		 * Bail if value is non-scalar, boolean false, or empty string.
		 * Intentionally allows 0 and '0' - both are valid column values.
		 */
		if ( ! is_scalar( $column_value ) || false === $column_value || '' === $column_value ) {
			return $retval;
		}

		// Bail if column does not exist.
		if ( ! $this->is_valid_column( $column_name ) ) {
			return $retval;
		}

		// Resolve the cache group for this column (empty if not a cache_key column).
		$primary    = $this->get_primary_column_name();
		$is_primary = (bool) ( $column_name === $primary );
		$groups     = $this->get_cache_groups();
		$group      = isset( $groups[ $column_name ] ) ? $groups[ $column_name ] : '';

		/*
		 * Cache key. The primary column is stable and unique, so its value (the
		 * id) is used directly as a by-id object-cache key. Secondary cache_key
		 * columns use a dedicated "{cache_group}-by-{name}" group and a value
		 * hash salted with last_changed.
		 */
		$cache_key = $column_value;
		if ( ( false === $is_primary ) && ! empty( $group ) ) {
			$cache_key = $this->get_item_cache_key( $column_value, $group );
		}

		// Check cache.
		if ( ! empty( $group ) ) {
			$retval = $this->cache_get( $cache_key, $group );
		}

		// Secondary cache hits store the primary ID; resolve via by-id cache.
		if ( ( false === $is_primary ) && false !== $retval ) {
			return $this->get_item( $retval );
		}

		// Item not cached.
		if ( false === $retval ) {

			// Get item by column name & value (from database, not cache).
			$retval = $this->get_item_raw( $column_name, $column_value );

			// Bail on failure.
			if ( ! $this->is_success( $retval ) ) {
				return false;
			}

			// Cache the result - read path, do not bump last_changed.
			if ( is_object( $retval ) ) {

				// Always warm the canonical primary by-id object cache.
				$this->update_item_cache( $retval, false );

				// For secondary cache_key columns, store only the primary ID.
				if ( ( false === $is_primary ) && ! empty( $group ) && isset( $retval->{$primary} ) ) {
					$this->cache_set( $cache_key, $retval->{$primary}, $group );
				}
			}
		}

		// Reduce the item.
		if ( is_array( $retval ) || is_object( $retval ) ) {
			/** @var array<string,mixed>|object $reduce_target */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$reduce_target = $retval;
			$retval        = $this->reduce_item( 'select', $reduce_target );
		}

		// Return result.
		return $this->shape_item( $retval );
	}

	/**
	 * Add an item to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $data Item data.
	 * @return int|string|false New item ID if successful (the auto-increment value,
	 *                          or the supplied primary key for a string/UUID key),
	 *                          false if not.
	 */
	public function add_item( $data = array() ) {

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// If data includes primary column, check if item already exists.
		if ( ! empty( $data[ $primary ] ) ) {

			// Shape the primary item ID.
			$primary_val = $data[ $primary ];
			if ( is_object( $primary_val ) ) {
				$item_id = $this->shape_item_id( $primary_val );
			} elseif ( is_array( $primary_val ) ) {
				/** @var array<string,mixed> $primary_arr */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
				$primary_arr = $primary_val;
				$item_id     = $this->shape_item_id( $primary_arr );
			} elseif ( is_scalar( $primary_val ) ) {
				$item_id = $this->shape_item_id( $primary_val );
			} else {
				$item_id = 0;
			}

			// Get item by ID (from database, not cache).
			$item = $this->get_item_raw( $primary, $item_id );

			// Bail if item already exists.
			if ( ! empty( $item ) ) {
				return false;
			}

			// Set data primary ID to newly shaped ID.
			$data[ $primary ] = $item_id;
		}

		// Get default values for item (from columns).
		$item = $this->default_item();

		// Unset the primary key if not part of data array (auto-incremented).
		if ( empty( $data[ $primary ] ) ) {
			unset( $item[ $primary ] );
		}

		// Slice data that has columns, and cut out non-keys for meta.
		$columns = array_flip( $this->get_column_names() );

		/*
		 * Columns the caller supplied, captured BEFORE defaults are merged in, so
		 * interception can tell an omitted column from an explicit value.
		 */
		$provided = array_keys( array_intersect_key( $data, $columns ) );

		$data = array_merge( $item, $data );
		$meta = array_diff_key( $data, $columns );
		$save = array_intersect_key( $data, $columns );

		// Bail if nothing to save.
		if ( empty( $save ) && empty( $meta ) ) {
			return false;
		}

		// Reduce (caps), let columns intercept generated values, then validate.
		$reduce = $this->reduce_item( 'insert', $save );
		$save   = $this->intercept_item( 'insert', $reduce, $provided );
		$save   = $this->validate_item( $save );

		// Default return value.
		$retval = false;

		// Try to save.
		if ( ! empty( $save ) ) {
			$table       = $this->get_table_name();
			$names       = array_keys( $save );
			$save_format = $this->get_columns_field_by( 'name', $names, 'pattern', '%s' );
			$retval      = $this->db()->insert( $table, $save, $save_format );
		}

		// Bail on failure.
		if ( ! $this->is_success( $retval ) ) {
			return false;
		}

		/*
		 * Get the new item ID: the supplied primary key (e.g. a string/UUID) when
		 * one was given, otherwise the auto-increment value from the insert.
		 */
		$retval = ! empty( $save[ $primary ] )
			? $save[ $primary ]
			: $this->db()->get_insert_id();

		// Maybe save meta keys.
		if ( ! empty( $meta ) ) {
			$this->save_extra_item_meta( $retval, $meta );
		}

		/*
		 * Update item cache(s). A new row can become the first match for any
		 * value, so rotate every secondary lookup group.
		 */
		$this->update_item_cache( $retval );
		$this->update_secondary_last_changed_caches();

		// Transition item data.
		$this->transition_item( $retval, $save, array() );

		// Return.
		return $retval;
	}

	/**
	 * Copy an item in the database to a new item.
	 *
	 * @since 1.1.0
	 *
	 * @param int|string          $item_id Item ID.
	 * @param array<string,mixed> $data Item data.
	 * @return int|string|false New item ID if successful (int auto-increment or a
	 *                          string/UUID key), false if not.
	 */
	public function copy_item( $item_id = 0, $data = array() ) {

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Shape the primary item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Get item by ID (from database, not cache).
		$item = $this->get_item_raw( $primary, $item_id );

		// Bail if item does not exist.
		if ( empty( $item ) ) {
			return false;
		}

		// Cast object to array.
		$save = (array) $item;

		// Let columns intercept copied values before overrides are restored.
		$save = $this->intercept_item( 'copy', $save );

		// Maybe merge data with original item.
		if ( ! empty( $data ) && is_array( $data ) ) {
			$save = array_merge( $save, $data );
		}

		/*
		 * Drop the copied primary key when the column auto-increments (the DB
		 * regenerates it - a supplied override is ignored, preserving long-standing
		 * behavior) OR when the caller supplied no replacement. A manual key (e.g. a
		 * string/UUID) cannot be auto-generated, so a caller-supplied one is kept.
		 */
		$primary_column = $this->get_column_by( array( 'name' => $primary ) );
		$auto_increment = ( $primary_column instanceof Column ) && $primary_column->is_auto_increment();

		if ( $auto_increment || empty( $data[ $primary ] ) ) {
			unset( $save[ $primary ] );
		}

		// Return result of add_item().
		return $this->add_item( $save );
	}

	/**
	 * Update an item in the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string          $item_id Item ID.
	 * @param array<string,mixed> $data Item data.
	 * @return bool
	 */
	public function update_item( $item_id = 0, $data = array() ) {

		// Bail early if no data to update.
		if ( empty( $data ) ) {
			return false;
		}

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID.
		if ( empty( $item_id ) ) {
			return false;
		}

		/*
		 * A single ID cannot safely address one row of a composite-key table, so fail
		 * closed rather than write every row sharing the first primary column's value.
		 * update_items() funnels through here too, so composite-key writes are simply
		 * unsupported by the current CRUD surface (see #205 follow-ups).
		 */
		if ( $this->has_composite_primary_key() ) {
			$this->log( 'warning', 'crud', 'update_item() does not support a composite primary key: a single ID cannot address one composite-keyed row.' );
			return false;
		}

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Get item to update (from database, not cache).
		$item = $this->get_item_raw( $primary, $item_id );

		// Bail if item does not exist to update.
		if ( empty( $item ) ) {
			return false;
		}

		// Cast as an array for easier manipulation.
		$item = (array) $item;

		// Unset the primary key from item & data.
		unset(
			$data[ $primary ],
			$item[ $primary ]
		);

		// Slice data that has columns, and cut out non-keys for meta.
		$columns = array_flip( $this->get_column_names() );
		/** @var array<string,string> $data_cast */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$data_cast = array_map( 'strval', array_filter( $data, 'is_scalar' ) );
		/** @var array<string,string> $item_cast */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$item_cast = array_map( 'strval', array_filter( $item, 'is_scalar' ) );
		$diff_keys = array_keys( array_diff_assoc( $data_cast, $item_cast ) );
		foreach ( $data as $k => $v ) {
			if ( ! is_scalar( $v ) ) {
				$diff_keys[] = $k;
			}
		}
		$data = array_intersect_key( $data, array_flip( $diff_keys ) );
		$meta = array_diff_key( $data, $columns );
		$save = array_intersect_key( $data, $columns );

		// Maybe save meta keys.
		$meta_saved = ! empty( $meta )
			? $this->save_extra_item_meta( $item_id, $meta )
			: false;

		// Bail if no columns to save - but report a successful meta-only save.
		if ( empty( $save ) ) {
			return $meta_saved;
		}

		// Reduce (caps), let columns intercept generated values, then validate.
		$reduce = $this->reduce_item( 'update', $save );
		$save   = $this->intercept_item( 'update', $reduce );
		$save   = $this->validate_item( $save );

		// Default return value.
		$retval = false;

		// Try to update.
		if ( ! empty( $save ) ) {
			$table        = $this->get_table_name();
			$where        = array( $primary => $item_id );
			$names        = array_keys( $save );
			$save_format  = $this->get_columns_field_by( 'name', $names, 'pattern', '%s' );
			$where_format = $this->get_columns_field_by( 'name', $primary, 'pattern', '%s' );
			$retval       = $this->db()->update( $table, $save, $where, $save_format, $where_format );
		}

		/*
		 * Only a real DB error fails the update. A 0-row result means the row matched
		 * but no column value changed (e.g. an invalid value that validated back to the
		 * stored one) - NOT a failure: any meta saved above still needs its caches
		 * rotated and transitions fired, so fall through instead of returning false.
		 * is_success() treats 0 as a failure for the insert/delete paths and is left
		 * unchanged; this local check is update-specific.
		 */
		if ( false === $retval ) {
			return false;
		}

		// Refresh the primary by-id cache and rotate the Query group's salt.
		$this->update_item_cache( $item_id );

		/*
		 * Rotate the secondary lookup groups for the columns that changed. The
		 * helper ignores any names that are not cache_key columns, so the written
		 * column names can be passed as-is. Lookups by unchanged columns stay
		 * warm and still resolve fresh objects through the by-id cache above.
		 */
		$this->update_secondary_last_changed_caches( array_keys( $save ) );

		// Transition item data.
		$this->transition_item( $item_id, $save, $item );

		// The row matched and any writes applied (a 0-row column no-op is not failure).
		return true;
	}

	/**
	 * Delete an item from the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $item_id Item ID.
	 * @return bool
	 */
	public function delete_item( $item_id = 0 ) {

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID.
		if ( empty( $item_id ) ) {
			return false;
		}

		/*
		 * A single ID cannot safely address one row of a composite-key table, so fail
		 * closed rather than delete every row sharing the first primary column's value.
		 * delete_items() funnels through here too, so composite-key deletes are simply
		 * unsupported by the current CRUD surface (see #205 follow-ups).
		 */
		if ( $this->has_composite_primary_key() ) {
			$this->log( 'warning', 'crud', 'delete_item() does not support a composite primary key: a single ID cannot address one composite-keyed row.' );
			return false;
		}

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Get item by ID (from database, not cache).
		$item = $this->get_item_raw( $primary, $item_id );

		// Bail if item does not exist to delete.
		if ( empty( $item ) ) {
			return false;
		}

		/*
		 * Reduce to the columns the current user can delete; bail if none
		 * allowed. Keep the original object for cache cleanup - reduce_item
		 * returns an array, but clean_item_cache needs the object to look up
		 * cache keys by property.
		 */
		$reduced = $this->reduce_item( 'delete', $item );
		if ( empty( $reduced ) ) {
			return false;
		}

		// Try to delete.
		$table        = $this->get_table_name();
		$where        = array( $primary => $item_id );
		$where_format = $this->get_columns_field_by( 'name', $primary, 'pattern', '%s' );
		$retval       = $this->db()->delete( $table, $where, $where_format );

		// Bail on failure.
		if ( ! $this->is_success( $retval ) ) {
			return false;
		}

		/*
		 * Clean caches on successful delete. The removed row's value-to-ID
		 * mappings are now gone, so rotate every secondary lookup group.
		 */
		$this->delete_all_item_meta( $item_id );
		$this->clean_item_cache( $item );
		$this->update_secondary_last_changed_caches();

		// Get the action name with prefix and item name.
		$action_name = $this->apply_prefix( $this->get_item_name() . '_deleted' );

		/**
		 * Fires after an object has been deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param int|string $item_id The ID of the item that was deleted.
		 * @param bool       $result  Whether the item was successfully deleted.
		 */
		if ( '' !== $action_name ) {
			do_action(
				$action_name,
				$item_id,
				(bool) $retval
			);
		}

		// Return.
		return (bool) $retval;
	}

	/**
	 * Delete a set of items, named by ID(s) or by a query-var filter.
	 *
	 * The plural companion to delete_item(): the input resolves to a list of
	 * primary IDs and each is removed through delete_item(), so per-item capability
	 * reduction, meta cleanup, cache invalidation, and the {item}_deleted action
	 * all still fire. The input may be:
	 *
	 *  - a single ID            - delete_items( 5 )
	 *  - a list of IDs          - delete_items( array( 5, 6, 7 ) )
	 *  - a query-var filter     - delete_items( array( 'status__in' => array( 'spam' ) ) )
	 *
	 * An empty input, or a filter that compiles to no WHERE, deletes nothing - the
	 * empty set never widens to "delete everything".
	 *
	 * @since 3.1.0
	 *
	 * @param int|string|array<int|string,mixed> $query_vars A single ID, a list of IDs, or a query-var filter array.
	 * @return int|false Number of items deleted, or false when there was nothing to delete.
	 */
	public function delete_items( $query_vars = array() ) {
		return ( new \BerlinDB\Database\Operations\Delete( $this ) )->delete( $query_vars );
	}

	/**
	 * Update a set of items, named by ID(s) or by a query-var filter.
	 *
	 * The write companion to delete_items(): the input resolves to a list of
	 * primary IDs and the same $data is written to each through update_item(), so
	 * per-item validation, capability reduction, meta handling, cache invalidation,
	 * and the transition actions all still fire. The input may be:
	 *
	 *  - a single ID         - update_items( 5, $data )
	 *  - a list of IDs       - update_items( array( 5, 6, 7 ), $data )
	 *  - a query-var filter  - update_items( array( 'status' => 'draft' ), $data )
	 *
	 * Empty $data, an empty input, or a filter that compiles to no WHERE updates
	 * nothing - the empty set never widens to "update everything".
	 *
	 * @since 3.1.0
	 *
	 * @param int|string|array<int|string,mixed> $query_vars A single ID, a list of IDs, or a query-var filter array.
	 * @param array<string,mixed>                $data       Column => value pairs to write to each matched item.
	 * @return int|false Number of items updated, or false when there was nothing to update.
	 */
	public function update_items( $query_vars = array(), $data = array() ) {
		return ( new \BerlinDB\Database\Operations\Update( $this ) )->update( $query_vars, $data );
	}

	/**
	 * Add a set of new items, one per data array.
	 *
	 * The create companion to delete_items() and update_items(): each element of
	 * $rows is one new item's data and is inserted through add_item(), so per-item
	 * default values, primary-key/UUID generation, sanitization, meta handling, and
	 * cache priming all still happen. Unlike the other two, the input is not a set
	 * selector - the rows do not exist yet - so it is always a list of data arrays:
	 *
	 *  - add_items( array( array( 'name' => 'A' ), array( 'name' => 'B' ) ) )
	 *
	 * Because the new IDs are the point of a batch insert, this returns them in
	 * input order rather than a count: each slot holds the new item ID, or false
	 * where that one insert failed. An empty input inserts nothing and returns array().
	 *
	 * Like its siblings this loops the per-item primitive rather than emitting a
	 * single multi-row INSERT, trading raw throughput for per-row correctness.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int,array<string,mixed>> $rows List of data arrays, one per new item.
	 * @return array<int,int|string|false> New item IDs in input order; false in any slot whose insert failed.
	 */
	public function add_items( $rows = array() ): array {
		return ( new \BerlinDB\Database\Operations\Add( $this ) )->add( (array) $rows );
	}

	/**
	 * Whether this query's table has a COMPOSITE primary key (>1 primary column).
	 *
	 * By-id CRUD (update_item/delete_item) addresses a row by a single scalar primary
	 * value, so it cannot safely target one row of a composite-key table: a single
	 * value under-constrains the WHERE to only the first primary column and would
	 * write/delete EVERY row sharing that value. Callers with a composite key use the
	 * query-var form (update_items()/delete_items()) with the full key instead.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	private function has_composite_primary_key(): bool {
		return count( (array) $this->get_columns( array( 'primary' => true ) ) ) > 1;
	}

	/**
	 * Validate an item before it is updated in or added to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $item The item object or array.
	 * @return array<string,mixed> Validated item array.
	 */
	private function validate_item( $item = array() ) {

		// Bail if item is empty or not an array.
		if ( empty( $item ) || ! is_array( $item ) ) {
			return $item;
		}

		// Validate all item fields.
		foreach ( $item as $key => $value ) {
			$item[ $key ] = $this->validate_item_field( $value, $key );
		}

		// Return the validated item.
		return $this->filter_item( $item );
	}

	/**
	 * Let each column intercept its value for a save operation.
	 *
	 * Loops every column (not just the keys present in $item, so it can inject
	 * values the caller omitted - e.g. created/modified) and delegates to
	 * Column::intercept().
	 *
	 * Sits beside reduce_item() and validate_item() in the save pipeline:
	 * reduce (caps) -> intercept (generated values) -> validate (sanitize).
	 *
	 * $provided_keys names the columns the caller actually supplied, so a preset
	 * can tell an omission from an explicit value (including an explicit null). On
	 * insert the caller's keys must be captured BEFORE defaults are merged in (they
	 * would otherwise mark every column present); when null, presence falls back to
	 * the keys in $item (the post-diff set an update passes).
	 *
	 * @since 3.1.0
	 *
	 * @param string               $method        One of insert|update|select|delete|copy.
	 * @param array<string,mixed> $item          Item field key/value pairs.
	 * @param array<int,string>|null $provided_keys Columns the caller supplied, or null.
	 * @return array<string,mixed>
	 */
	private function intercept_item( $method = 'insert', $item = array(), $provided_keys = null ) {

		// Bail if item is empty or not an array.
		if ( empty( $item ) || ! is_array( $item ) ) {
			return $item;
		}

		// Presence map: caller-supplied keys, or the item's own keys when not given.
		$provided = ( null === $provided_keys )
			? $item
			: array_flip( $provided_keys );

		// Let each column intercept its value.
		foreach ( $this->get_columns() as $column ) {
			$name    = $column->name;
			$current = $item[ $name ] ?? null;
			$new     = $column->intercept( $method, $current, array_key_exists( $name, $provided ) );

			// The column's generated unset sentinel removes the column entirely.
			if ( $column->is_unset_sentinel( $new ) ) {
				unset( $item[ $name ] );
				continue;
			}

			// Only write back when interception changed the value.
			if ( $new !== $current ) {
				$item[ $name ] = $new;
			}
		}

		// Return the intercepted item.
		return $item;
	}

	/**
	 * Reduce an item down to the keys and values the current user has the
	 * appropriate capabilities to select|insert|update|delete.
	 *
	 * Always returns an array. Columns not present in the schema are also
	 * removed - no caps entry resolves to an empty capability string, which
	 * fails the current_user_can check.
	 *
	 * @since 1.0.0
	 *
	 * @param string                      $method select|insert|update|delete.
	 * @param object|array<string,mixed> $item   Object or array of keys/values to reduce.
	 *
	 * @return array<string,mixed> Item with capability-restricted keys removed.
	 */
	private function reduce_item( $method = 'update', $item = array() ): array {

		// Bail if item is empty.
		if ( empty( $item ) ) {
			return array();
		}

		// Normalize to an array for uniform processing.
		if ( is_object( $item ) ) {
			$work = (array) $item;
		} elseif ( is_array( $item ) ) {
			$work = $item;
		} else {
			return array();
		}

		// Loop through columns and remove any the current user cannot access.
		foreach ( array_keys( $work ) as $key ) {

			// Get the caps for this column.
			$caps = $this->get_column_field( array( 'name' => $key ), 'caps' );

			// Get the capability for this method, if it exists.
			$method_cap = ( is_array( $caps ) && isset( $caps[ $method ] ) && is_string( $caps[ $method ] ) )
				? $caps[ $method ]
				: '';

			// Remove any columns the current user cannot access.
			if ( empty( $method_cap ) || ! current_user_can( $method_cap ) ) {
				unset( $work[ $key ] );
			}
		}

		// Return the reduced item.
		return $work;
	}

	/**
	 * Return an item comprised of all Column names as keys and their defaults
	 * as values.
	 *
	 * This is used by `add_item()` to get an array of default item values that
	 * can be compared against, to determine if any values need to be saved into
	 * meta data instead.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses array_combine()
	 *
		 * @param array<string,mixed> $args Default empty array. Parsed & used to filter columns.
	 * @return array<string,mixed>
	 */
	private function default_item( $args = array() ): array {

		// Parse arguments.
		$r = $this->parse_args( $args );

		// Get the column names and their defaults.
		$names    = $this->get_column_names( $r );
		$defaults = $this->get_columns( $r, 'and', 'default' );

		// Combine them.
		$retval = array_combine( $names, $defaults );

		// Return.
		return ! empty( $retval )
			? $retval
			: array();
	}

	/**
	 * Transition an item when adding or updating.
	 *
	 * This method takes the data being saved, looks for any columns that are
	 * known to transition between values, and fires actions on them.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string           $item_id Item ID.
	 * @param array<string,mixed> $new_data New item data.
	 * @param array<string,mixed> $old_data Old item data.
	 */
	private function transition_item( $item_id = 0, $new_data = array(), $old_data = array() ): void {

		// Look for transition columns.
		$columns = $this->get_column_names( array( 'transition' => true ) );

		// Bail if no columns to transition.
		if ( empty( $columns ) ) {
			return;
		}

		// Shape the item ID.
		$item_id = $this->shape_item_id( $item_id );

		// Bail if no item ID.
		if ( empty( $item_id ) ) {
			return;
		}

		// If no old value(s), it's new.
		if ( empty( $old_data ) || ! is_array( $old_data ) ) {
			$old_data = $new_data;

			// Set all old values to "new".
			foreach ( $old_data as $key => $value ) {
				$value            = 'new';
				$old_data[ $key ] = $value;
			}
		}

		// Compare.
		$keys = array_flip( $columns );
		$new  = array_intersect_key( $new_data, $keys );
		$old  = array_intersect_key( $old_data, $keys );

		/*
		 * Diff each transition column KEY-WISE. Non-null values compare by string form
		 * (so 5 and '5' are equal, as the old array_diff did), but null is kept distinct
		 * from '' so a change TO or FROM null still fires its transition - the old
		 * is_scalar() filter dropped null and missed those.
		 */
		$diff = array();

		foreach ( $new as $key => $value ) {

			// Only a scalar or null column value can transition.
			if ( ! is_scalar( $value ) && ( null !== $value ) ) {
				continue;
			}

			$old_value = array_key_exists( $key, $old )
				? $old[ $key ]
				: null;

			$new_norm = ( null === $value )
				? null
				: (string) $value;
			$old_norm = is_scalar( $old_value )
				? (string) $old_value
				: null;

			if ( $new_norm !== $old_norm ) {
				$diff[ $key ] = $value;
			}
		}

		// Bail if nothing is changing.
		if ( empty( $diff ) ) {
			return;
		}

		// Get the item name for the key action name.
		$item_name = $this->get_item_name();

		// Do the actions.
		foreach ( $diff as $key => $value ) {
			$old_value  = $old_data[ $key ];
			$new_value  = $new_data[ $key ];
			$key_action = $this->apply_prefix( 'transition_' . $item_name . '_' . $key );

			/**
			 * Fires after an object value has transitioned.
			 *
			 * @since 1.0.0
			 *
			 * @param mixed      $old_value The value being transitioned FROM.
			 * @param mixed      $new_value The value being transitioned TO.
			 * @param int|string $item_id   The ID of the item that is transitioning.
			 */
			if ( '' !== $key_action ) {
				do_action( $key_action, $old_value, $new_value, $item_id );
			}
		}
	}
}
