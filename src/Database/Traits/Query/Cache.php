<?php
/**
 * Query Cache Trait Class.
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
 * Cache reading, writing, priming, and invalidation for a Query.
 *
 * Composed onto Query, sharing its $this run-state. Owns the result- and
 * item-cache keys and groups, the last_changed rotation, item + relationship
 * priming, and the wp_cache_* wrappers. The results-invariant cache-key
 * exclusion list is supplied by the host through get_results_invariant_vars().
 *
 * @since 3.1.0
 */
trait Cache {

	/**
	 * The query vars that change behavior but not which rows a query returns.
	 *
	 * Supplied by the composing class: the list is a fixed vocabulary best
	 * expressed as a class constant, but trait constants require PHP 8.2 and
	 * BerlinDB targets 8.1 - so the host owns the constant and exposes it here.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	abstract protected function get_results_invariant_vars(): array;

	/**
	 * Get cache key from $query_vars and $query_var_defaults.
	 *
	 * Performs the following operations to create a consistent cache-key:
	 * - Removes the "fields" query_var, because whole objects/items are cached
	 * - Removes unknown or unregistered query_var keys
	 * - Sorts query_vars by query_var_default keys
	 * - Removes query_vars with default values
	 * - Serializes and md5 hashes query_vars
	 * - Combines plural name, key, and last_changed for cache group
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Correctly removes unique query_var_default_value values
	 *
	 * @param string $group Cache group name.
	 * @return string
	 */
	private function get_cache_key( $group = '' ): string {

		// Default slice.
		$slice = array();

		// Slice query_vars by query_var_defaults keys, ordered by defaults.
		foreach ( $this->query_var_defaults as $key => $_default ) {

			// Skip results-invariant vars (a hint or priming flag returns the same rows).
			if ( in_array( $key, $this->get_results_invariant_vars(), true ) ) {
				continue;
			}

			// Skip if no query_var array key exists, allowing null values.
			if ( ! array_key_exists( $key, $this->query_vars ) ) {
				continue;
			}

			// Skip default random query_var values.
			if ( $this->query_vars[ $key ] === $this->query_var_default_value ) {
				continue;
			}

			// Add key & value to slice.
			$slice[ $key ] = $this->query_vars[ $key ];
		}

		// Hash the sliced query vars. serialize() is intentional and safe here.
		$hash = md5( serialize( $slice ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		// Return the namespaced, salted cache key.
		return "get_{$this->get_item_name_plural()}:{$hash}:" . $this->get_last_changed_cache( $group );
	}

	/**
	 * Build a last_changed-salted cache key for a secondary get_item_by() lookup.
	 *
	 * The cache group already identifies the lookup column, so the key only
	 * needs the looked-up value and table-wide generation salt. The cached value
	 * is the primary ID; the object itself lives in the canonical by-id cache.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed  $column_value Value being looked up.
	 * @param string $group        Secondary lookup group to salt from. Default empty.
	 * @return string
	 */
	private function get_item_cache_key( $column_value = '', $group = '' ): string {
		return md5( (string) $column_value ) . ':' . $this->get_last_changed_cache( $group );
	}

	/**
	 * Normalize a cache group name.
	 *
	 * Empty values and the primary column name both resolve to the table's
	 * canonical item cache group. Non-primary values are treated as explicit
	 * cache-group names, such as secondary "{cache_group}-by-{column}" groups.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Cache group name, or a column name.
	 * @return string
	 */
	private function get_cache_group( $group = '' ): string {

		// Get the primary column.
		$primary = $this->get_primary_column_name();

		// Default return value.
		$retval = $this->cache_group;

		// Treat the primary column name as an alias for the Query cache group.
		if ( ! empty( $group ) && ( $group !== $primary ) ) {
			$retval = $group;
		}

		// Return the group.
		return $retval;
	}

	/**
	 * Get array of which database columns have uniquely cached groups.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string>
	 */
	private function get_cache_groups(): array {

		// Default return value.
		$retval = array();

		// Get the cache groups.
		$groups = $this->get_column_names( array( 'cache_key' => true ) );

		// Bail if no cache groups.
		if ( empty( $groups ) ) {
			return $retval;
		}

		// Setup return values.
		foreach ( $groups as $name ) {
			$retval[ $name ] = $this->get_cache_group_for_column( $name );
		}

		// Return cache groups array.
		return $retval;
	}

	/**
	 * Get the cache group used by a cache-key column.
	 *
	 * The primary column uses the canonical item cache group. Secondary columns
	 * use their own lookup groups so value-to-ID entries do not share a bucket
	 * with by-id item objects.
	 *
	 * @since 3.1.0
	 *
	 * @param string $column_name Column name.
	 * @return string
	 */
	private function get_cache_group_for_column( $column_name = '' ): string {

		// Bail if no column name.
		if ( empty( $column_name ) ) {
			return '';
		}

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Resolve column to group, then normalize through the shared helper.
		$group = ( $primary === $column_name )
			? $column_name
			: "{$this->cache_group}-by-{$column_name}";

		return $this->get_cache_group( $group );
	}

	/**
	 * Maybe prime item & item-meta caches.
	 *
	 * Accepts a single ID, or an array of IDs.
	 *
	 * The reason this accepts only IDs is because it gets called immediately
	 * after an item is inserted in the database, but before items have been
	 * "shaped" into proper objects, so object properties may not be set yet.
	 *
	 * Queries the database 1 time for all non-cached item objects and 1 time
	 * for all non-cached item meta.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses get_meta_table_name() to
	 *
	 * @param list<int|string> $item_ids List of item IDs.
	 * @param bool             $force Whether to bypass caching.
	 *
	 * @return bool False if empty
	 */
	private function prime_item_caches( $item_ids = array(), $force = false ): bool {

		// Bail if no items to cache.
		if ( empty( $item_ids ) ) {
			return false;
		}

		// Accepts single values, so cast to array.
		$item_ids = (array) $item_ids;

		/*
		 * Update item caches.
		 *
		 * Uses get_non_cached_ids() to remove item IDs that already exist in
		 * in the cache, then performs direct database query for the remaining
		 * IDs, and caches them.
		 */
		if ( ! empty( $force ) || $this->get_query_var( 'update_item_cache' ) ) {

			// Look for non-cached IDs.
			$ids = $this->get_non_cached_ids( $item_ids );

			// Proceed if non-cached IDs exist.
			if ( ! empty( $ids ) ) {

				// Get query parts.
				$table   = $this->get_table_name();
				$primary = $this->get_primary_column_name();
				$ids     = $this->get_in_sql( $primary, $ids );

				// Query database.
				$query   = "SELECT * FROM {$table} WHERE {$primary} IN {$ids}";
				$results = $this->db()->get_results( $query );

				// Update item cache(s) - read path, do not bump last_changed.
				if ( ! empty( $results ) && is_array( $results ) ) {
					/** @var list<object> $results */
					$this->update_item_cache( $results, false );
				}
			}
		}

		/*
		 * Update meta data caches.
		 *
		 * Uses update_meta_cache() because it politely handles all of the
		 * non-cached ID logic. This allows us to use the original (and likely
		 * larger) $item_ids array instead of $ids, thus ensuring the everything
		 * is cached according to our expectations.
		 */
		if ( ! empty( $force ) || $this->get_query_var( 'update_meta_cache' ) ) {

			// Proceed if meta table exists.
			if ( $this->get_meta_table_name() ) {
				$meta_type = $this->get_meta_type();
				$int_ids   = array_values( array_filter( $item_ids, 'is_int' ) );
				if ( ! empty( $int_ids ) ) {
					update_meta_cache( $meta_type, $int_ids );
				}
			}
		}

		// Return true because something was cached.
		return true;
	}

	/**
	 * Prime this query's item caches for a known set of primary IDs.
	 *
	 * Public entry point so other Query instances (e.g. relationship cache
	 * priming) can warm this query's item cache in a single bulk read. Forces
	 * the prime regardless of the update_item_cache query var, because the
	 * calling query is the one expressing the intent.
	 *
	 * @since 3.1.0
	 *
	 * @param list<int|string> $ids Primary-key values to prime.
	 * @return void
	 */
	protected function prime_items( $ids = array() ) {
		$this->prime_item_caches( $ids, true );
	}

	/**
	 * Prime caches for related items referenced by the current result set.
	 *
	 * For each named relationship, warms caches so later access avoids N+1
	 * lookups: belongs_to warms the remote item caches for the parents this
	 * result set points at; has_many warms each item's child collection.
	 *
	 * Quiet by default: runs only when 'with' names one or more relationships.
	 * See berlindb/core #193.
	 *
	 * @since 3.1.0
	 */
	private function prime_relationship_caches(): void {

		// Relationship names requested for priming.
		$with = $this->get_query_var( 'with' );

		// Bail unless a non-empty list of names was requested.
		if ( ! is_array( $with ) || empty( $with ) ) {
			return;
		}

		// Bail unless we have an array of shaped items to read foreign keys from.
		if ( empty( $this->items ) || ! is_array( $this->items ) ) {
			return;
		}

		// Capture locally so the array type survives the method calls below.
		$items = $this->items;

		// Prime each named belongs_to relationship (warm the parents' caches).
		foreach ( $this->get_belongs_to_relationships() as $relationship ) {
			if ( in_array( $relationship->name, $with, true ) ) {
				$this->prime_belongs_to_relationship( $relationship, $items );
			}
		}

		// Prime each named has_many relationship (warm the child collections).
		foreach ( $this->get_has_many_relationships() as $relationship ) {
			if ( in_array( $relationship->name, $with, true ) ) {
				$this->prime_has_many_relationship( $relationship, $items );
			}
		}
	}

	/**
	 * Warm the remote query's item cache for a single belongs_to relationship.
	 *
	 * @since 3.1.0
	 *
	 * @param Relationship                 $relationship The relationship to prime.
	 * @param array<int|string,mixed>     $items        Shaped result items to read foreign keys from.
	 */
	private function prime_belongs_to_relationship( Relationship $relationship, array $items ): void {

		$columns    = $relationship->columns;
		$references = $relationship->references;

		/*
		 * Composite keys are not batch-primed - a deliberate fallback: get_related()
		 * still resolves them correctly ( each call hits the remote result cache, just
		 * one per item instead of a single bulk warm ). Composite-key priming is a
		 * follow-up ( #229, touches cache-key infra ).
		 */
		if ( ( count( $columns ) !== 1 ) || ( count( $references ) !== 1 ) ) {
			return;
		}

		// Resolve the remote query instance (guarded; null when unresolvable).
		$remote = $this->resolve_remote_query( $relationship );

		/*
		 * Priming warms the remote primary-key cache, so the relationship must
		 * resolve to a sibling Query that references the remote primary column.
		 */
		if ( ( null === $remote ) || ( $references[0] !== $remote->get_primary_column_name() ) ) {
			return;
		}

		// Warm the remote primary-key cache from this side's foreign-key values.
		$values = $this->get_local_relationship_key_values( $items, $columns[0] );

		if ( ! empty( $values ) ) {
			$remote->prime_items( $values );
		}
	}

	/**
	 * Warm the remote child collections for a single has_many relationship.
	 *
	 * Collects this side's key values (the values the remote foreign key points
	 * at) and asks the remote query to prime every matching child collection in
	 * one bulk read.
	 *
	 * @since 3.1.0
	 *
	 * @param Relationship             $relationship The relationship to prime.
	 * @param array<int|string,mixed> $items        Shaped result items to read keys from.
	 */
	private function prime_has_many_relationship( Relationship $relationship, array $items ): void {

		$columns    = $relationship->columns;
		$references = $relationship->references;

		/*
		 * Composite keys are not batch-primed - a deliberate fallback: get_related()
		 * still resolves them ( per-item, each cached; just not bulk-warmed ).
		 * Composite-key priming is a follow-up ( #229 ).
		 */
		if ( ( count( $columns ) !== 1 ) || ( count( $references ) !== 1 ) ) {
			return;
		}

		// Resolve the remote query instance (guarded; null when unresolvable).
		$remote = $this->resolve_remote_query( $relationship );

		if ( null === $remote ) {
			return;
		}

		// Warm the child collections from this side's key values.
		$values = $this->get_local_relationship_key_values( $items, $columns[0] );

		if ( ! empty( $values ) ) {
			$remote->prime_has_many( $references[0], $values );
		}
	}

	/**
	 * Return the distinct, non-empty local relationship-key values from items.
	 *
	 * "local" is the relationship side this query holds (vs the remote/related
	 * Query). Shared by the single-column relationship priming paths: reads $column
	 * off each item, skips items that do not expose it, drops empty relationship
	 * keys (see is_empty_relationship_key()), and de-duplicates by string value.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $items  Shaped result items to read keys from.
	 * @param string                  $column The local relationship key column to read.
	 * @return list<mixed> The distinct, non-empty local key values.
	 */
	private function get_local_relationship_key_values( array $items, string $column ): array {
		$values = array();

		foreach ( $items as $item ) {

			// Skip items that do not expose the local column.
			if ( ! is_object( $item ) || ! isset( $item->{$column} ) ) {
				continue;
			}

			$value = $item->{$column};

			// Skip empty keys (no relation), de-duplicate the rest by string value.
			if ( ! $this->is_empty_relationship_key( $value ) ) {
				$values[ (string) $value ] = $value;
			}
		}

		return array_values( $values );
	}

	/**
	 * Return the distinct, all-parts-present local relationship-key TUPLES from items.
	 *
	 * The composite (multi-column) analog of get_local_relationship_key_values():
	 * reads every $column off each item, keeps only items that expose ALL parts with
	 * none empty (is_empty_relationship_key(), matching get_related()'s all-parts-
	 * present rule), and de-duplicates complete tuples by a stable hash.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int|string,mixed> $items   Shaped result items to read keys from.
	 * @param list<string>            $columns The local relationship key columns, in order.
	 * @return list<array<string,mixed>> Distinct ordered tuples ( [ column => value ] ).
	 */
	private function get_local_relationship_key_tuples( array $items, array $columns ): array {
		$tuples = array();

		foreach ( $items as $item ) {

			// Skip non-objects.
			if ( ! is_object( $item ) ) {
				continue;
			}

			$tuple    = array();
			$complete = true;

			/*
			 * A composite key needs EVERY part present and non-empty; a partial key
			 * is no relation, exactly as get_related() treats it.
			 */
			foreach ( $columns as $column ) {
				if ( ! isset( $item->{$column} ) || $this->is_empty_relationship_key( $item->{$column} ) ) {
					$complete = false;
					break;
				}

				$tuple[ $column ] = $item->{$column};
			}

			// De-duplicate complete tuples by a stable hash.
			if ( true === $complete ) {
				$tuples[ $this->get_relationship_tuple_hash( $tuple ) ] = $tuple;
			}
		}

		return array_values( $tuples );
	}

	/**
	 * Return a stable de-duplication hash for a relationship-key tuple.
	 *
	 * Length-prefixes each part ("{len}:{value}") so distinct tuples never collide,
	 * even if a value itself contains the separator (e.g. [ '5|1', '7' ] vs
	 * [ '5', '1|7' ]) - a collision would serve the wrong cached relation. Values are
	 * string-cast (not serialized), so 1 and '1' hash alike, matching the (string)
	 * de-dup the single-column get_local_relationship_key_values() uses. The SAME
	 * hash keys both the requested tuples and the fetched rows, so a bulk-primed
	 * result groups back to the exact tuple that requested it.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $tuple Ordered [ column => value ] tuple.
	 * @return string
	 */
	private function get_relationship_tuple_hash( array $tuple ): string {
		$parts = array();

		foreach ( array_values( $tuple ) as $value ) {
			$value   = (string) $value;
			$parts[] = strlen( $value ) . ':' . $value;
		}

		return implode( '|', $parts );
	}

	/**
	 * Prime this query's native result cache for a set of foreign-key values.
	 *
	 * Public entry point used by has_many relationship priming. Performs one
	 * bulk read of every row whose $fk_column is in $values, warms the by-id
	 * item cache for those rows, then warms this query's own result-list cache
	 * for each per-value query - "{$fk_column} => value" - including empty
	 * results, so childless parents are a cache hit too.
	 *
	 * Because the result cache is reused (rather than a bespoke collection
	 * cache), a later get_related() / query() for one value resolves natively
	 * and inherits the standard last_changed invalidation.
	 *
	 * @since 3.1.0
	 *
	 * @param string                  $fk_column Foreign-key column on this table.
	 * @param list<int|string>|string $values    Foreign-key values to prime.
	 * @return void
	 */
	protected function prime_has_many( $fk_column = '', $values = array() ) {

		// Bail without a valid column or any values.
		if ( ! $this->is_valid_column( $fk_column ) || empty( $values ) ) {
			return;
		}

		// De-duplicate the values.
		$values = array_values( array_unique( (array) $values ) );

		// Build the escaped IN() clause for the foreign-key column.
		$in = $this->get_in_sql( $fk_column, $values );

		if ( '' === $in ) {
			return;
		}

		// One bulk read of every related row.
		$table   = $this->get_table_name();
		$results = $this->db()->get_results( "SELECT * FROM {$table} WHERE {$fk_column} IN {$in}" );

		// Normalize to an array of rows.
		$rows = ( ! empty( $results ) && is_array( $results ) )
			? $results
			: array();

		// Warm the by-id item cache for every related row.
		if ( ! empty( $rows ) ) {
			/** @var list<object> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$this->update_item_cache( $rows, false );
		}

		// Group related primary IDs by foreign-key value.
		$primary = $this->get_primary_column_name();
		$grouped = array();

		foreach ( $rows as $row ) {
			if ( is_object( $row ) && isset( $row->{$fk_column}, $row->{$primary} ) ) {
				$grouped[ (string) $row->{$fk_column} ][] = $row->{$primary};
			}
		}

		/*
		 * Warm each value's native result cache - including empties. 'number' => 0
		 * (no limit) must match get_related()'s has_many query exactly, so the
		 * primed key equals the key that lookup computes (the full child set).
		 */
		foreach ( $values as $value ) {
			$ids = $grouped[ (string) $value ] ?? array();
			$this->prime_query(
				array(
					$fk_column => $value,
					'number'   => 0,
				),
				$ids
			);
		}
	}

	/**
	 * Warm this query's result-list cache for a set of query vars.
	 *
	 * Runs the same parse + cache-key path as query() so the cached entry is
	 * keyed identically to a real query() call - but skips the database read,
	 * storing the supplied item IDs instead. A later query() with the same vars
	 * is then a cache hit.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $query_vars Query vars to cache under.
	 * @param list<int|string>     $item_ids   Item IDs the query resolves to.
	 * @return void
	 */
	protected function prime_query( $query_vars = array(), $item_ids = array() ) {
		$this->run(
			function () use ( $query_vars, $item_ids ) {

				// Parse vars exactly as query() does, so the cache key matches.
				$this->parse_query( $query_vars );

				// Respect the per-query caching flag.
				if ( true !== (bool) $this->get_query_var( 'cache_results' ) ) {
					return;
				}

				// Store the known IDs under the same key query() would compute.
				$ids = array_values( $item_ids );

				$this->cache_set(
					$this->get_cache_key(),
					array(
						'item_ids'    => $ids,
						'found_items' => count( $ids ),
					),
					$this->cache_group
				);
			}
		);
	}

	/**
	 * Update the cache for an item. Does not update item-meta cache.
	 *
	 * Accepts a single object, or an array of objects.
	 *
	 * The reason this does not accept ID's is because this gets called
	 * after an item is already updated in the database, so we want to avoid
	 * querying for it again. It's just safer this way.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses shape_item_id() if $items is scalar
	 *
	 * @param int|string|object|list<object> $items             Primary ID or key if scalar. Row if object. Array of objects if array.
	 * @param bool                           $bump_last_changed Whether to bump the last-changed cache value.
	 */
	private function update_item_cache( $items = array(), $bump_last_changed = true ): void {

		// Maybe query for single item.
		if ( is_scalar( $items ) ) {

			// Get the primary column name.
			$primary = $this->get_primary_column_name();

			// Shape the primary item ID.
			$item_id = $this->shape_item_id( $items );

			// Get item by ID (from database, not cache).
			$items = $this->get_item_raw( $primary, $item_id );
		}

		// Bail if no items to cache.
		if ( empty( $items ) ) {
			return;
		}

		// Make sure items are an array (without casting objects to arrays).
		if ( ! is_array( $items ) ) {
			$items = array( $items );
		}

		// Get the cache groups and the primary column name.
		$groups  = $this->get_cache_groups();
		$primary = $this->get_primary_column_name();

		/*
		 * Warm the primary by-id object cache only. Secondary cache_key lookups
		 * are salted and lazily populated by get_item_by(); proactively warming
		 * them here would write keys the salted reads never hit, and overwrite
		 * non-unique lookups with the last-written row.
		 */
		foreach ( $items as $item ) {

			// Skip if item is not an object.
			if ( ! is_object( $item ) ) {
				continue;
			}

			// Warm the primary by-id object cache.
			if ( isset( $groups[ $primary ], $item->{$primary} ) ) {
				$this->cache_set( $item->{$primary}, $item, $groups[ $primary ] );
			}
		}

		/*
		 * Only bump last_changed for mutations; read-path warming must not
		 * invalidate the list cache that was just stored.
		 */
		if ( true === $bump_last_changed ) {
			$this->update_primary_last_changed_cache();
		}
	}

	/**
	 * Clean the cache for an item. Does not clean item-meta.
	 *
	 * Accepts a single object, or an array of objects.
	 *
	 * The reason this does not accept ID's is because this gets called
	 * after an item is already deleted from the database, so it cannot be
	 * queried and may not exist in the cache. It's just safer this way.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $items Single object item, or Array of object items.
	 *
	 * @return bool
	 */
	private function clean_item_cache( $items = array() ): bool {

		// Bail if no items to clean.
		if ( empty( $items ) ) {
			return false;
		}

		// Make sure items are an array.
		if ( ! is_array( $items ) ) {
			$items = array( $items );
		}

		// Get the cache groups and the primary column name.
		$groups  = $this->get_cache_groups();
		$primary = $this->get_primary_column_name();

		// Delete the primary by-id object cache for each item.
		foreach ( $items as $item ) {

			// Skip if item is not an object.
			if ( ! is_object( $item ) ) {
				continue;
			}

			// Delete the primary by-id object cache.
			if ( isset( $groups[ $primary ], $item->{$primary} ) ) {
				$this->cache_delete( $item->{$primary}, $groups[ $primary ] );
			}
		}

		/*
		 * Rotate the Query (primary) group's salt, invalidating the result-list
		 * cache. Secondary lookup groups are rotated by the caller via
		 * update_secondary_last_changed_caches(), since only the caller knows the
		 * operation.
		 */
		$this->update_primary_last_changed_cache();

		return true;
	}

	/**
	 * Set the last_changed generation for a cache group to the current time.
	 *
	 * Low-level primitive. Prefer the semantic wrappers
	 * update_primary_last_changed_cache() and
	 * update_secondary_last_changed_caches() at write sites; this is also used
	 * by get_last_changed_cache() to lazily initialize a group.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Cache group. Defaults to $this->cache_group.
	 * @return string The new last_changed value.
	 */
	private function set_last_changed( $group = '' ): string {
		$last_changed = microtime();

		$this->cache_set( 'last_changed', $last_changed, $group );

		return $last_changed;
	}

	/**
	 * Rotate the Query (primary) group's last_changed salt.
	 *
	 * Invalidates the result-list cache. Called on every write, since any column
	 * change can affect query ordering or membership.
	 *
	 * @since 3.1.0
	 *
	 * @return string The new last_changed value.
	 */
	private function update_primary_last_changed_cache(): string {
		return $this->set_last_changed();
	}

	/**
	 * Rotate the last_changed salt for secondary cache_key lookup groups.
	 *
	 * Each secondary cache_key column has its own "{cache_group}-by-{column}"
	 * group with an independent last_changed generation, so a get_item_by()
	 * lookup for one column survives writes that cannot affect it. The Query
	 * (primary) group's salt is rotated separately by update_item_cache() and
	 * clean_item_cache() on every write.
	 *
	 * Pass the specific column names whose values changed (an update), or null
	 * to rotate every secondary group (an insert or delete, either of which can
	 * change which row is the first match for any value).
	 *
	 * @since 3.1.0
	 *
	 * @param list<string>|null $columns Column names to invalidate, or null for all.
	 */
	private function update_secondary_last_changed_caches( $columns = null ): void {

		// Get the cache groups and the primary column name.
		$groups  = $this->get_cache_groups();
		$primary = $this->get_primary_column_name();

		// Rotate the salt for each affected secondary group.
		foreach ( $groups as $name => $group ) {

			// The primary/Query group is rotated separately, on every write.
			if ( $name === $primary ) {
				continue;
			}

			// Rotate all secondary groups (null), or only the named ones.
			if ( ( null === $columns ) || in_array( $name, $columns, true ) ) {
				$this->set_last_changed( $group );
			}
		}
	}

	/**
	 * Get the last_changed key for a cache group.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Cache group. Defaults to $this->cache_group.
	 *
	 * @return string The last time a cache group was changed.
	 */
	private function get_last_changed_cache( $group = '' ): string {

		// Get the last changed cache value.
		$last_changed = $this->cache_get( 'last_changed', $group );

		// Maybe initialize the last changed value.
		if ( false === $last_changed ) {
			$last_changed = $this->set_last_changed( $group );
		}

		// Return the last changed value for the cache group.
		return (string) $last_changed;
	}

	/**
	 * Get array of non-cached item IDs.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 $item_ids expected to be shaped.
	 *
	 * @param list<int|string> $item_ids Array of shaped item IDs.
	 * @param string           $group    Cache group. Defaults to $this->cache_group.
	 *
	 * @return list<int|string>
	 */
	private function get_non_cached_ids( $item_ids = array(), $group = '' ): array {

		// Bail if no item IDs.
		if ( empty( $item_ids ) ) {
			return array();
		}

		// Default return value.
		$retval = array();

		// Loop through item IDs.
		foreach ( $item_ids as $id ) {
			if ( false === $this->cache_get( $id, $group ) ) {
				$retval[] = $id;
			}
		}

		// Return array of non-cached IDs.
		return $retval;
	}

	/**
	 * Add a cache value for a key and group.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed  $value  Cache value.
	 * @param string $group  Cache group. Defaults to $this->cache_group.
	 * @param int    $expire Expiration.
	 */
	private function cache_add( $key = '', $value = '', $group = '', $expire = 0 ): void {

		// Bail if cache invalidation is suspended.
		if ( wp_suspend_cache_addition() ) {
			return;
		}

		// Bail if no cache key. Allow 0 and '0' - both are valid cache keys.
		if ( false === $key || '' === $key ) {
			return;
		}

		// Get the cache group.
		$group = $this->get_cache_group( $group );

		// Add to the cache.
		wp_cache_add( $key, $value, $group, $expire );
	}

	/**
	 * Get a cache value for a key and group.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group. Defaults to $this->cache_group.
	 * @param bool       $force Whether to bypass caching.
	 * @return mixed
	 */
	private function cache_get( $key = '', $group = '', $force = false ) {

		/*
		 * Bail if no cache key. Return false (not null) so callers using
		 * strict false === checks correctly detect a cache miss.
		 */
		if ( false === $key || '' === $key ) {
			return false;
		}

		// Get the cache group.
		$group = $this->get_cache_group( $group );

		// Return from the cache.
		return wp_cache_get( $key, $group, $force );
	}

	/**
	 * Set a cache value for a key and group.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed  $value  Cache value.
	 * @param string $group  Cache group. Defaults to $this->cache_group.
	 * @param int    $expire Expiration.
	 */
	private function cache_set( $key = '', $value = '', $group = '', $expire = 0 ): void {

		// Bail if cache invalidation is suspended.
		if ( wp_suspend_cache_addition() ) {
			return;
		}

		// Bail if no cache key. Allow 0 and '0' - both are valid cache keys.
		if ( false === $key || '' === $key ) {
			return;
		}

		// Get the cache group.
		$group = $this->get_cache_group( $group );

		// Update the cache.
		wp_cache_set( $key, $value, $group, $expire );
	}

	/**
	 * Delete a cache key for a group.
	 *
	 * @since 1.0.0
	 *
	 * @global bool $_wp_suspend_cache_invalidation
	 *
	 * @param int|string $key   Cache key.
	 * @param string $group Cache group. Defaults to $this->cache_group.
	 */
	private function cache_delete( $key = '', $group = '' ): void {
		global $_wp_suspend_cache_invalidation;

		// Bail if cache invalidation is suspended.
		if ( ! empty( $_wp_suspend_cache_invalidation ) ) {
			return;
		}

		// Bail if no cache key. Allow 0 and '0' - both are valid cache keys.
		if ( false === $key || '' === $key ) {
			return;
		}

		// Get the cache group.
		$group = $this->get_cache_group( $group );

		// Delete the cache.
		wp_cache_delete( $key, $group );
	}
}
