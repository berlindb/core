<?php
/**
 * Meta preset: base Query for a key/value meta sibling table.
 *
 * @package     Database
 * @subpackage  Presets
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Presets\Meta;

use BerlinDB\Database\Interfaces\MetaStore;
use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Query as KernQuery;
use BerlinDB\Database\Kern\Row;
use BerlinDB\Database\Kern\Schema;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base Query for a WordPress-style key/value meta sibling table (#204 Phase A).
 *
 * Extend it with a one-line stub that names its primary object's Query:
 *
 *     class Order_Meta extends \BerlinDB\Database\Presets\Meta\Query {
 *         protected $primary_query_class = Order::class;
 *     }
 *
 * The primary declares the matching has_many in its own schema, on its primary
 * key column — an ordinary relationship like any other:
 *
 *     'relationships' => array(
 *         array(
 *             'query'  => Order_Meta::class,
 *             'column' => 'order_id',
 *             'type'   => 'has_many',
 *             'name'   => 'meta',
 *         ),
 *     ),
 *
 * From the primary, the base derives its table identity ({object}_meta) and
 * generates the EAV schema — meta_id, {object}_id (mirroring the primary key's
 * shape), meta_key, meta_value — plus a belongs_to back to the primary.
 *
 * Because both the primary and the meta sibling are real classes, the two
 * relationships resolve by class name through the ordinary relationship engine —
 * no instance binding, no registry, and no preset knowledge in any Kern class.
 * Meta is "just relationships."
 *
 * @since 3.1.0
 */
class Query extends KernQuery implements MetaStore {

	/**
	 * FQCN of the primary object's Query class.
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	protected $primary_query_class = '';

	/**
	 * Whether configure_from_primary() succeeded.
	 *
	 * A misconfigured stub logs a warning and constructs as an inert base Query;
	 * Meta-specific paths (table provisioning, CRUD routing) consult this and bail
	 * rather than operate on a default identity.
	 *
	 * @since 3.1.0
	 * @var   bool
	 */
	private $configured_from_primary = false;

	/**
	 * Name of the foreign-key column pointing at the primary (e.g. 'order_id').
	 *
	 * Derived during configure_from_primary(); the MetaStore methods address
	 * rows through it.
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	private $object_id_column_name = '';

	/**
	 * Derive identity and schema from the primary before normal setup.
	 *
	 * @since 3.1.0
	 */
	protected function init(): void {
		$this->configure_from_primary();

		parent::init();
	}

	/**
	 * Configure this meta query's identity and schema from its primary.
	 *
	 * Instantiating the primary is one-directional and terminates: the primary's
	 * own init() only adds a has_many naming THIS class (a class string, not an
	 * instance), so it never constructs a meta query back.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	private function configure_from_primary(): void {

		// Bail (loudly) unless a primary Query class is named, exists, and builds.
		$primary_query = $this->instantiate_class( $this->primary_query_class, 'meta_primary_missing' );
		if ( null === $primary_query ) {
			return;
		}

		// Bail (loudly) unless the primary is a sibling Query.
		if ( ! ( $primary_query instanceof KernQuery ) ) {
			$this->log(
				'warning',
				'meta_primary_not_a_query',
				'Meta query primary class is not a Query; not configured.',
				array( 'primary_query_class' => $this->primary_query_class )
			);

			return;
		}

		/*
		 * Bail (loudly) when the primary key is composite. This preset generates
		 * exactly one foreign-key column, so it requires exactly one key column —
		 * composite keys are unsupported (as they are in the relationship runtime).
		 */
		$primary_columns = array_values( $primary_query->get_columns( array( 'primary' => true ) ) );
		if ( 1 < count( $primary_columns ) ) {
			$this->log(
				'warning',
				'meta_primary_key_unsupported',
				'Meta query primary has a composite primary key; not configured.',
				array( 'primary_query_class' => $this->primary_query_class )
			);

			return;
		}

		/*
		 * Resolve the single key column: the primary-flagged column when there is
		 * exactly one; otherwise the column the Query engine itself keys items by
		 * (get_primary_column_name(), the canonical 'id' convention used by
		 * index-only schemas). The meta foreign key mirrors whatever column the
		 * engine treats as primary, so the two always agree.
		 */
		$primary_key_column = ! empty( $primary_columns )
			? $primary_columns[0]
			: $primary_query->get_column_by( array( 'name' => $primary_query->get_primary_column_name() ) );

		// Bail (loudly) unless the primary has a key column to relate to.
		if ( ! ( $primary_key_column instanceof Column ) ) {
			$this->log(
				'warning',
				'meta_primary_key_missing',
				'Meta query primary has no primary-key column; not configured.',
				array( 'primary_query_class' => $this->primary_query_class )
			);

			return;
		}

		// Derive identity from the primary's singular name (e.g. 'order' -> 'order_meta').
		$object_name     = $primary_query->get_item_name();
		$meta_table_name = "{$object_name}_meta";

		// Late static binding, so a stub may override build_schema() to customize.
		$this->prefix                = $primary_query->get_prefix();
		$this->table_name            = $meta_table_name;
		$this->item_name             = $meta_table_name;
		$this->item_name_plural      = $meta_table_name;
		$this->cache_group           = $meta_table_name;
		$this->object_id_column_name = self::sanitize_object_name( $object_name ) . '_id';
		$this->table_schema          = static::build_schema( $primary_key_column, $object_name, $this->primary_query_class );

		// Mark success; Meta-specific paths bail when this never happened.
		$this->configured_from_primary = true;
	}

	/**
	 * Return whether this meta query successfully configured from its primary.
	 *
	 * False means the stub is misconfigured (see the meta_primary_* warnings) and
	 * carries only inert default identity — callers building on the meta sibling
	 * (table provisioning, CRUD routing) should bail.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_configured_from_primary(): bool {
		return $this->configured_from_primary;
	}

	/**
	 * Return the generated meta Schema, or null when misconfigured.
	 *
	 * Presets\Meta\Table consumes this to install the sibling table from the
	 * exact same Schema instance this query runs against.
	 *
	 * @since 3.1.0
	 *
	 * @return Schema|null
	 */
	public function get_schema(): ?Schema {
		return ( $this->table_schema instanceof Schema )
			? $this->table_schema
			: null;
	}

	/** MetaStore *************************************************************/

	/**
	 * Add a meta entry for an object.
	 *
	 * Mirrors add_metadata(): non-scalars are stored serialized, and $unique
	 * refuses to add when the key already exists for the object.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string $object_id  Object ID the meta belongs to.
	 * @param string     $meta_key   Meta key.
	 * @param mixed      $meta_value Meta value.
	 * @param bool       $unique     Whether the key must be unique per object.
	 * @return int|false The new meta entry ID on success, false on failure.
	 */
	public function add_meta( int|string $object_id, string $meta_key, mixed $meta_value, bool $unique = false ): int|false {

		// Bail when misconfigured, or without an object and key to file under.
		if ( ! $this->configured_from_primary || empty( $object_id ) || ( '' === $meta_key ) ) {
			return false;
		}

		// Unique keys refuse to add when the key already exists for the object.
		if ( $unique && ( array() !== $this->get_meta_rows( $object_id, $meta_key ) ) ) {
			return false;
		}

		/*
		 * Insert through the normal item engine (validation, caching, hooks).
		 * meta_key/meta_value are this table's own first-class, indexed columns —
		 * not WP_Query meta vars — so the slow-query heuristic does not apply.
		 */
		$added = $this->add_item(
			array(
				$this->object_id_column_name => $object_id,
				'meta_key'                   => $meta_key,   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'                 => maybe_serialize( $meta_value ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return is_int( $added ) && ( $added > 0 )
			? $added
			: false;
	}

	/**
	 * Get meta for an object.
	 *
	 * Mirrors get_metadata(): values are unserialized on read; an empty key
	 * returns all of the object's meta grouped by key.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string $object_id Object ID the meta belongs to.
	 * @param string     $meta_key  Meta key. Empty returns all meta for the object.
	 * @param bool       $single    Whether to return the single (first) value.
	 * @return mixed Single value when $single; array of values otherwise.
	 */
	public function get_meta( int|string $object_id, string $meta_key = '', bool $single = false ): mixed {

		// Bail when misconfigured or without an object to look under.
		if ( ! $this->configured_from_primary || empty( $object_id ) ) {
			return $single ? '' : array();
		}

		$rows = $this->get_meta_rows( $object_id, $meta_key );

		// An empty key returns ALL meta for the object, grouped by key.
		if ( '' === $meta_key ) {
			$retval = array();

			foreach ( $rows as $row ) {
				$retval[ (string) $row->meta_key ][] = maybe_unserialize( (string) $row->meta_value );
			}

			return $retval;
		}

		// Unserialize each stored value.
		$values = array();
		foreach ( $rows as $row ) {
			$values[] = maybe_unserialize( (string) $row->meta_value );
		}

		return $single
			? ( $values[0] ?? '' )
			: $values;
	}

	/**
	 * Update a meta entry for an object, adding it when absent.
	 *
	 * Mirrors update_metadata(): adds when the key does not exist; with a
	 * $prev_value, only matching entries update; an identical single value is a
	 * no-op returning false.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string $object_id  Object ID the meta belongs to.
	 * @param string     $meta_key   Meta key.
	 * @param mixed      $meta_value New meta value.
	 * @param mixed      $prev_value Only update entries matching this value.
	 * @return bool True when something was added or updated.
	 */
	public function update_meta( int|string $object_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): bool {

		// Bail when misconfigured, or without an object and key to file under.
		if ( ! $this->configured_from_primary || empty( $object_id ) || ( '' === $meta_key ) ) {
			return false;
		}

		// Add when the key does not exist yet (update_metadata() parity).
		$rows = $this->get_meta_rows( $object_id, $meta_key );
		if ( array() === $rows ) {
			return (bool) $this->add_meta( $object_id, $meta_key, $meta_value );
		}

		$serialized = maybe_serialize( $meta_value );

		/*
		 * With a previous value, only update entries that match it. Core uses
		 * empty() semantics here: 0, '0', false, and array() are NOT previous-
		 * value filters (update_metadata() parity).
		 */
		if ( ! empty( $prev_value ) ) {
			$previous = maybe_serialize( $prev_value );
			$rows     = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $previous ) {
						return (string) $row->meta_value === $previous;
					}
				)
			);

			// An identical single value is a no-op (update_metadata() parity).
		} elseif ( ( 1 === count( $rows ) ) && ( (string) $rows[0]->meta_value === $serialized ) ) {
			return false;
		}

		/*
		 * Update each matching entry through the normal item engine. (meta_value
		 * is this table's own column, not a WP_Query meta var.)
		 */
		$retval = false;
		foreach ( $rows as $row ) {
			if ( $this->update_item( $row->meta_id, array( 'meta_value' => $serialized ) ) ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				$retval = true;
			}
		}

		return $retval;
	}

	/**
	 * Delete meta for an object.
	 *
	 * Mirrors delete_metadata(): a truthy $meta_value only deletes matching
	 * entries; $delete_all ignores $object_id and deletes matching entries for
	 * ALL objects.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string $object_id  Object ID the meta belongs to.
	 * @param string     $meta_key   Meta key.
	 * @param mixed      $meta_value Only delete entries matching this value.
	 * @param bool       $delete_all Whether to ignore $object_id (all objects).
	 * @return bool True when something was deleted.
	 */
	public function delete_meta( int|string $object_id, string $meta_key, mixed $meta_value = '', bool $delete_all = false ): bool {

		// Bail when misconfigured or without a key.
		if ( ! $this->configured_from_primary || ( '' === $meta_key ) ) {
			return false;
		}

		// Bail without an object unless deleting across all objects.
		if ( ! $delete_all && empty( $object_id ) ) {
			return false;
		}

		// All entries for the key — for this object, or every object.
		$rows = $this->get_meta_rows( $delete_all ? null : $object_id, $meta_key );

		/*
		 * A present value only deletes matching entries. Core treats only '',
		 * null, and false as "no value filter" — 0 and '0' are real filter
		 * values (delete_metadata() parity).
		 */
		$serialized = maybe_serialize( $meta_value );
		if ( ( '' !== $serialized ) && ( null !== $serialized ) && ( false !== $serialized ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $serialized ) {
						return (string) $row->meta_value === $serialized;
					}
				)
			);
		}

		// Bail if nothing matches.
		if ( array() === $rows ) {
			return false;
		}

		// Delete each matching entry through the normal item engine.
		$retval = false;
		foreach ( $rows as $row ) {
			if ( $this->delete_item( $row->meta_id ) ) {
				$retval = true;
			}
		}

		return $retval;
	}

	/**
	 * Delete ALL meta for an object (every key).
	 *
	 * @since 3.1.0
	 *
	 * @param int|string $object_id Object ID whose meta to purge.
	 * @return bool True when something was deleted.
	 */
	public function delete_all_meta( int|string $object_id ): bool {

		// Bail when misconfigured or without an object.
		if ( ! $this->configured_from_primary || empty( $object_id ) ) {
			return false;
		}

		// Every entry for the object, all keys.
		$rows = $this->get_meta_rows( $object_id, '' );

		// Bail if there is nothing to purge.
		if ( array() === $rows ) {
			return false;
		}

		// Delete each entry through the normal item engine.
		$retval = false;
		foreach ( $rows as $row ) {
			if ( $this->delete_item( $row->meta_id ) ) {
				$retval = true;
			}
		}

		return $retval;
	}

	/**
	 * Fetch meta rows for an object and/or key, ordered oldest-first.
	 *
	 * Uses the unambiguous `__in` query-var forms: this table has real columns
	 * named meta_key/meta_value, but the BARE `meta_key` query var belongs to
	 * the Meta parser's vocabulary (which would fail closed here). Value
	 * matching is done in PHP by the callers, for exact serialized comparison.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string|null $object_id Object ID, or null for all objects.
	 * @param string          $meta_key  Meta key, or '' for all keys.
	 * @return array<int, Row> Meta rows (possibly empty).
	 */
	private function get_meta_rows( int|string|null $object_id, string $meta_key ): array {

		// Unlimited, oldest-first (insertion order, like the WP meta API).
		$args = array(
			'number'  => 0,
			'orderby' => 'meta_id',
			'order'   => 'asc',
		);

		// Scope to one object unless querying across all of them.
		if ( null !== $object_id ) {
			$args[ $this->object_id_column_name . '__in' ] = array( $object_id );
		}

		// Scope to one key unless querying all of them.
		if ( '' !== $meta_key ) {
			$args[ 'meta_key__in' ] = array( $meta_key );
		}

		// Run the query; keep only the shaped Row items.
		$items  = $this->query( $args );
		$retval = array();

		foreach ( (array) $items as $item ) {
			if ( $item instanceof Row ) {
				$retval[] = $item;
			}
		}

		return $retval;
	}

	/**
	 * Build the meta EAV schema for a primary object.
	 *
	 * Standard WordPress-style key/value shape:
	 *   meta_id      bigint unsigned PK auto_increment
	 *   {object}_id  the foreign key, mirroring the primary key's column shape
	 *   meta_key     varchar(191)  (full-column index; under the utf8mb4 limit)
	 *   meta_value   longtext, nullable
	 * with named indexes on {object}_id and meta_key, and a belongs_to back to the
	 * primary declared on the foreign-key column (resolved by class name).
	 *
	 * The foreign key copies the primary key's storage shape (type/length/unsigned/
	 * etc.) so values and relationship comparisons line up for any key type — but
	 * never its primary/auto_increment identity.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $primary_key_column  The primary object's primary-key column.
	 * @param string $object_name         The primary object's singular name (e.g. 'order').
	 * @param string $primary_query_class FQCN of the primary object's Query class.
	 * @return Schema
	 */
	public static function build_schema( Column $primary_key_column, string $object_name, string $primary_query_class ): Schema {

		// Normalize defensively; the primary feeds an already-sanitized item name.
		$object_name = self::sanitize_object_name( $object_name );
		$fk_name     = "{$object_name}_id";

		// The foreign key: the primary key's shape + a belongs_to back to it.
		$foreign_key = array_merge(
			self::mirror_key_shape( $primary_key_column ),
			array(
				'name'          => $fk_name,
				'relationships' => array(
					array(
						'query'  => $primary_query_class,
						'column' => $primary_key_column->name,
						'type'   => 'belongs_to',
						'name'   => $object_name,
					),
				),
			)
		);

		return new Schema(
			array(
				'columns' => array(
					array(
						'name'     => 'meta_id',
						'type'     => 'bigint',
						'length'   => '20',
						'unsigned' => true,
						'primary'  => true,
						'extra'    => 'auto_increment',
						'sortable' => true,
					),
					$foreign_key,
					array(
						'name'   => 'meta_key',
						'type'   => 'varchar',
						'length' => '191',
					),
					array(
						'name'       => 'meta_value',
						'type'       => 'longtext',
						'allow_null' => true,
					),
				),
				'indexes' => array(
					array(
						'type'    => 'primary',
						'columns' => array( 'meta_id' ),
					),
					array(
						'name'    => $fk_name,
						'columns' => array( $fk_name ),
					),
					array(
						'name'    => 'meta_key',
						'columns' => array( 'meta_key' ),
					),
				),
			)
		);
	}

	/**
	 * Copy a column's storage shape (not its identity) for use as a foreign key.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $column The column whose shape to copy (the primary key).
	 * @return array<string, mixed>
	 */
	private static function mirror_key_shape( Column $column ): array {
		return array(
			'type'       => $column->type,
			'length'     => $column->length,
			'unsigned'   => $column->unsigned,
			'zerofill'   => $column->zerofill,
			'binary'     => $column->binary,
			'pattern'    => $column->pattern,
			'cast'       => $column->cast,
			'encoding'   => $column->encoding,
			'collation'  => $column->collation,
			'allow_null' => false,
		);
	}

	/**
	 * Sanitize an object name to a safe identifier fragment.
	 *
	 * @since 3.1.0
	 *
	 * @param string $name The object name.
	 * @return string
	 */
	private static function sanitize_object_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		$name = (string) preg_replace( '/[^a-z0-9_]/', '', $name );

		return ( '' !== $name )
			? $name
			: 'object';
	}
}
