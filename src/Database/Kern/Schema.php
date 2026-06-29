<?php
/**
 * Base Custom Database Table Schema Class.
 *
 * @package     Database
 * @subpackage  Schema
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Kern;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A base database table schema class, which houses the Column and Index
 * collections that define a database table's structure.
 *
 * This class is intended to be extended for each unique database table,
 * including global tables for multisite, and users tables.
 *
 * Subclasses may pre-populate the $columns and $indexes arrays as arrays of
 * Column/Index argument arrays (legacy style), or call add_column() and
 * add_index() explicitly. Both approaches are fully supported.
 *
 * Indexes implied by column flags (primary/unique/index) are DERIVED once at
 * construction from the columns present then - the same derive-at-construction
 * contract every Kern class follows (a Column parses its type once in init(), etc.).
 * A column added later via add_column() is fully registered, but any index it implies
 * must be added explicitly with add_index(); post-construction columns are not
 * re-scanned for derivation.
 *
 * Use get_create_table_string() to generate the SQL body for a CREATE TABLE
 * statement. Validation runs automatically before SQL is produced.
 *
 * @since 1.0.0
 * @since 3.0.0 Added Index support, validation, and item mutation methods.
 */
class Schema {

	/**
	 * Use these traits.
	 *
	 * @since 3.0.0
	 */
	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;

	/** Factories *************************************************************/

	/**
	 * Build a Schema by introspecting an existing database table.
	 *
	 * Queries SHOW COLUMNS FROM the given table and maps each row to a Column
	 * via Column::from_mysql(). Returns an empty Schema if the table does not
	 * exist or has no columns.
	 *
	 * The returned Schema can be passed directly to a Query or Table via their
	 * constructor: new Query( array( 'table_schema' => $schema ) ).
	 *
	 * @since 3.0.0
	 *
	 * @param string $table Fully-qualified table name (with prefix).
	 * @return self
	 */
	public static function from_table( string $table = '' ) {

		// Bail if no table name.
		if ( empty( $table ) ) {
			return new self();
		}

		// Resolve the database interface through the static wrapper.
		$db = self::get_db_global();

		/*
		 * Suppress wpdb errors so a nonexistent table silently returns an empty
		 * Schema rather than printing an HTML error block into the page output.
		 */
		$suppress = $db->suppress_errors( true );

		// Prepare the query.
		$prepared = $db->prepare( 'SHOW COLUMNS FROM %i', $table );

		// Fetch column metadata; null means prepare() failed.
		$rows = ! is_null( $prepared )
			? $db->get_results( $prepared, ARRAY_A )
			: null;

		// Restore the previous wpdb error-suppression state.
		$db->suppress_errors( $suppress );

		// Bail if the table does not exist or returned no columns.
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return new self();
		}

		// Map each row to a Column object.
		$columns = array_map( array( Column::class, 'from_mysql' ), $rows );

		return new self( array( 'columns' => $columns ) );
	}

	/** Types *****************************************************************/

	/**
	 * Schema Column class.
	 *
	 * Override in a subclass to use a custom Column implementation.
	 *
	 * @since 3.0.0
	 * @var   string
	 */
	protected $column = __NAMESPACE__ . '\\Column';

	/**
	 * Schema Index class.
	 *
	 * Override in a subclass to use a custom Index implementation.
	 *
	 * @since 3.0.0
	 * @var   string
	 */
	protected $index = __NAMESPACE__ . '\\Index';

	/** Item Objects **********************************************************/

	/**
	 * Array of database Column objects.
	 *
	 * May be pre-populated in a subclass as an array of Column argument arrays
	 * for legacy compatibility. init() will hydrate them into Column objects.
	 *
	 * @since 1.0.0
	 * @var   Column[]
	 */
	protected $columns = array();

	/**
	 * Array of database Index objects.
	 *
	 * May be pre-populated in a subclass as an array of Index argument arrays
	 * for legacy compatibility. init() will hydrate them into Index objects.
	 *
	 * @since 3.0.0
	 * @var   Index[]
	 */
	protected $indexes = array();

	/** Configuration *********************************************************/

	/**
	 * Sanitization callbacks for a Schema's configuration arguments.
	 *
	 * Applied by validate_args() (Traits\Configuration) during construction.
	 * Each definition list is coerced to an array; the entries are validated
	 * downstream when init() hydrates them into Column / Index objects.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed> Map of config key => sanitization callback.
	 */
	protected function get_config_callbacks(): array {
		return array(
			'columns' => array( $this, 'sanitize_definition_list' ),
			'indexes' => array( $this, 'sanitize_definition_list' ),
		);
	}

	/**
	 * Coerce a columns/indexes definition value to an array.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value A definition list (expected to be an array).
	 * @return array<int|string,mixed> The value if it is an array, else empty.
	 */
	protected function sanitize_definition_list( $value = array() ): array {
		return is_array( $value )
			? $value
			: array();
	}

	/** Lifecycle Methods *****************************************************/

	/**
	 * The construction hook (Traits\Boot), called after class properties are
	 * assigned. Initializes the schema's items in two passes: the DECLARED columns
	 * and indexes, then the items DERIVED from them.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Split into declared vs derived item initialization.
	 */
	protected function init(): void {
		$this->init_declared_items();
		$this->init_derived_items();
	}

	/**
	 * Hydrate the declared $columns and $indexes arrays into objects.
	 *
	 * Runs after set_vars(), so it sees both subclass-declared arrays and any passed
	 * as constructor args - the single point that turns the legacy pre-set arrays
	 * (the only way to register items before 3.0.0; that support will not be removed)
	 * into Column / Index objects.
	 *
	 * @since 3.1.0
	 */
	private function init_declared_items(): void {

		// Hydrate the declared $columns into Column objects.
		if ( ! empty( $this->columns ) && is_array( $this->columns ) ) {
			$this->setup_items( 'columns', $this->columns );
		}

		// Hydrate the declared $indexes into Index objects.
		if ( ! empty( $this->indexes ) && is_array( $this->indexes ) ) {
			$this->setup_items( 'indexes', $this->indexes );
		}
	}

	/**
	 * Initialize the items DERIVED from the declared ones, after they are hydrated.
	 *
	 * Currently just the indexes implied by column flags; the split leaves a clear
	 * path for other derived items (e.g. derived columns) without re-shaping init().
	 *
	 * @since 3.1.0
	 */
	private function init_derived_items(): void {
		$this->init_derived_indexes();
	}

	/**
	 * Add the indexes implied by columns: the primary key, then flag indexes, then the
	 * foreign-key and cache_key lookup indexes.
	 *
	 * Primary runs first so its index is present when later passes check satisfaction
	 * (a single-column primary satisfies a flag or a cache_key lookup; a composite
	 * primary does not). cache_key runs last, so it only adds an index for a lookup
	 * column nothing earlier already covers.
	 *
	 * @since 3.1.0
	 */
	private function init_derived_indexes(): void {
		$this->init_primary_index();
		$this->init_flag_indexes();
		$this->init_relationship_indexes();
		$this->init_cache_key_indexes();
	}

	/**
	 * Derive the PRIMARY KEY from a lone primary-flagged column.
	 *
	 * A `primary => true` column does not emit a PRIMARY KEY on its own (the flag is
	 * the marker; an Index emits the DDL), so derive one - but ONLY when exactly one
	 * column is primary AND the schema has no primary index at all. Multiple primary
	 * columns need an explicit composite primary index (column order is semantic), and
	 * a primary index that does not cover the flag is left as the specific validation
	 * conflict rather than masked by a second primary key.
	 *
	 * @since 3.1.0
	 */
	private function init_primary_index(): void {

		/*
		 * Bail if a primary index already exists - covering it, or conflicting with a
		 * flag, is validation's job, not ours to mask with a second primary key.
		 */
		if ( $this->has_primary_index() ) {
			return;
		}

		// Gather the primary-flagged columns.
		$primary = $this->get_columns( array( 'primary' => true ) );

		// Derive only for a single primary column; a composite PK must be explicit.
		if ( 1 !== count( $primary ) ) {
			return;
		}

		$this->add_index(
			array(
				'type'    => 'primary',
				'columns' => array( $primary[0]->name ),
			)
		);
	}

	/**
	 * Whether the schema already declares (or has derived) a primary index.
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	private function has_primary_index(): bool {
		return ! empty( $this->get_indexes( array( 'type' => 'primary' ) ) );
	}

	/**
	 * Add indexes implied by column flags that no existing index already satisfies.
	 *
	 * Derives a single-column index named after each `unique => true` (UNIQUE) or
	 * `index => true` / `uuid => true` (plain KEY) column - the flag is the semantic
	 * marker, this emits the DDL. `unique` wins (a UNIQUE index is also a plain one).
	 *
	 * A uuid gets a plain lookup KEY, not UNIQUE: its value is generated only on the
	 * Query insert path, so a UNIQUE constraint would reject any row inserted directly
	 * (raw $wpdb, bulk loads) without one. Declare an explicit UNIQUE to enforce it.
	 *
	 * Primary columns are NOT skipped wholesale: satisfaction-dedupe handles them, so
	 * a single-column PRIMARY satisfies a flag while a composite PRIMARY (which does
	 * not make one column unique) does not - a column inside a composite primary key
	 * still derives its own flagged index. A column an unrelated index merely shares a
	 * name with still derives, surfacing the clash as a duplicate-index-name validation
	 * error rather than silently dropping the constraint.
	 *
	 * Single-column only, indexed in full: a column too long to index whole (a long
	 * varchar/text) needs an explicit Index with a prefix length - the shorthand does
	 * not guess one, since a prefix would change a UNIQUE constraint's meaning.
	 *
	 * @since 3.1.0
	 */
	private function init_flag_indexes(): void {
		$flagged = $this->get_columns(
			array(
				'unique' => true,
				'index'  => true,
				'uuid'   => true,
			),
			'or'
		);

		foreach ( $flagged as $column ) {

			/*
			 * `unique` -> UNIQUE; `index` or `uuid` alone -> a plain lookup KEY. UNIQUE
			 * wins, since a unique index also serves as a plain one.
			 */
			$type = ! empty( $column->unique )
				? 'unique'
				: 'key';

			// Skip a column an existing index already satisfies.
			if ( $this->is_column_index_satisfied( $column->name, 'unique' === $type ) ) {
				continue;
			}

			$this->add_index(
				array(
					'name'    => $column->name,
					'type'    => $type,
					'columns' => array( $column->name ),
				)
			);
		}
	}

	/**
	 * Whether an existing index already satisfies a single-column flag on a column.
	 *
	 * Satisfaction is exact single-column coverage (a composite index does not count,
	 * even on its leading column - it promises less than a column's own index, and a
	 * composite UNIQUE does not make one column unique). The unique flag additionally
	 * requires the covering index to be UNIQUE (or PRIMARY).
	 *
	 * @since 3.1.0
	 *
	 * @param string $column         The column name.
	 * @param bool   $require_unique Whether the covering index must be unique.
	 * @return bool
	 */
	private function is_column_index_satisfied( string $column, bool $require_unique ): bool {
		foreach ( $this->get_indexes() as $index ) {

			// Must be an exact single-column index on this column (case-insensitive).
			if ( ( 1 !== count( $index->columns ) ) || ( 0 !== strcasecmp( $column, (string) $index->columns[0] ) ) ) {
				continue;
			}

			// A plain index satisfies `index`; `unique` needs a UNIQUE (or PRIMARY) index.
			if ( ! $require_unique || $this->is_unique_index( $index ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an index enforces uniqueness (a UNIQUE or PRIMARY index).
	 *
	 * Mirrors Index::get_create_string(), where either the $unique flag or a UNIQUE
	 * type emits a UNIQUE KEY; a PRIMARY KEY is unique by definition.
	 *
	 * @since 3.1.0
	 *
	 * @param Index $index The index to test.
	 * @return bool
	 */
	private function is_unique_index( Index $index ): bool {
		$type = strtoupper( (string) $index->type );

		return ! empty( $index->unique ) || ( 'UNIQUE' === $type ) || ( 'PRIMARY' === $type );
	}

	/**
	 * Add a lookup KEY for each foreign-key column (a column holding a belongs_to).
	 *
	 * An unindexed belongs_to foreign key is almost always a mistake (joins and
	 * get_related() filter on it), so derive a plain KEY - independent of whether the
	 * relationship enforces a real FOREIGN KEY (#205).
	 *
	 * @since 3.1.0
	 */
	private function init_relationship_indexes(): void {
		foreach ( $this->get_columns() as $column ) {

			// A column holding a belongs_to is the foreign key - index its lookups.
			if ( $this->is_foreign_key_column( $column ) ) {
				$this->add_lookup_key( $column, 'belongs_to foreign key', 'fk_index_not_indexable' );
			}
		}
	}

	/**
	 * Add a lookup KEY for each cache_key column (a get_item_by lookup column).
	 *
	 * A cache_key column is one items are retrieved by (Query::get_item_by); on a cache
	 * miss the query is an equality lookup, so a plain KEY serves it. The primary
	 * cache_key needs no handling here - the primary index already satisfies it. Not
	 * UNIQUE: a cache_key's identity is an application invariant, not a database
	 * constraint (and enforcing it would reject direct inserts, as for uuid).
	 *
	 * @since 3.1.0
	 */
	private function init_cache_key_indexes(): void {

		// A cache_key column is looked up by value - index it.
		foreach ( $this->get_columns( array( 'cache_key' => true ) ) as $column ) {
			$this->add_lookup_key( $column, 'cache_key column', 'cache_key_index_not_indexable' );
		}
	}

	/**
	 * Derive a plain lookup KEY for a column, unless an index already supports it.
	 *
	 * Shared by the foreign-key and cache_key sources: both want an equality-lookup KEY
	 * on a single column. Skips a column a leftmost-full index already supports, and one
	 * too long to index in full - logged (naming $source) rather than emitting a plain
	 * key MySQL rejects or DDL that exceeds the index-size limit (the safe length per
	 * engine/encoding awaits #222).
	 *
	 * @since 3.1.0
	 *
	 * @param Column $column   The column to index.
	 * @param string $source   Human-readable source label, for the skip log message.
	 * @param string $log_code Stable machine-readable code for the skip log (kept
	 *                         distinct per source so consumers can filter by it).
	 */
	private function add_lookup_key( Column $column, string $source, string $log_code ): void {

		// Skip a column a leftmost-column index already supports.
		if ( $this->is_lookup_index_satisfied( $column->name ) ) {
			return;
		}

		// Skip (and log) a column too long to index in full.
		if ( ! $this->is_full_indexable_column( $column ) ) {
			$this->log(
				'warning',
				$log_code,
				"A {$source} is too long to index in full; declare an explicit prefixed Index.",
				array( 'column' => $column->name )
			);

			return;
		}

		// Add the index.
		$this->add_index(
			array(
				'name'    => $column->name,
				'type'    => 'key',
				'columns' => array( $column->name ),
			)
		);
	}

	/**
	 * Whether a column holds a belongs_to relationship (making it a foreign key).
	 *
	 * @since 3.1.0
	 *
	 * @param Column $column The column to test.
	 * @return bool
	 */
	private function is_foreign_key_column( Column $column ): bool {

		// Bail if no relationships.
		if ( empty( $column->relationships ) || ! is_array( $column->relationships ) ) {
			return false;
		}

		// Loop through relationships and look for 'belongs_to'.
		foreach ( $column->relationships as $relationship ) {
			if ( isset( $relationship['type'] ) && ( 'belongs_to' === $relationship['type'] ) ) {
				return true;
			}
		}

		// Return false, because no FK was found.
		return false;
	}

	/**
	 * Whether an existing index already supports equality lookups on a column.
	 *
	 * A lookup (a foreign key, or a cache_key get_item_by) is supported by any index
	 * with the column as its LEFTMOST, full-length column - a leftmost composite column
	 * counts, a prefixed one does not, and a FULLTEXT or SPATIAL index never does (they
	 * cannot back an equality lookup).
	 *
	 * @since 3.1.0
	 *
	 * @param string $column The column name.
	 * @return bool
	 */
	private function is_lookup_index_satisfied( string $column ): bool {
		foreach ( $this->get_indexes() as $index ) {

			// FULLTEXT and SPATIAL indexes cannot back an equality lookup.
			$type = strtoupper( (string) $index->type );

			// Skip for FULLTEXT and SPATIAL types.
			if ( in_array( $type, array( 'FULLTEXT', 'SPATIAL' ), true ) ) {
				continue;
			}

			// The column must be the index's leftmost, indexed in full (no prefix).
			if ( empty( $index->columns ) || ( 0 !== strcasecmp( $column, (string) $index->columns[0] ) ) ) {
				continue;
			}

			if ( ! isset( $index->lengths[ $index->columns[0] ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a column can be indexed in full (not only via a prefix length).
	 *
	 * Numbers, dates, and booleans always can; a bounded string (char/varchar/binary/
	 * varbinary) only within the conservative limit; unbounded text/blob cannot - they
	 * need an explicit prefixed Index. The exact safe length per engine + encoding
	 * awaits #222; 191 mirrors the conservative utf8mb4 value WordPress uses.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $column The column to test.
	 * @return bool
	 */
	private function is_full_indexable_column( Column $column ): bool {

		// Numbers, dates, and booleans index in full.
		if ( $column->is_numeric() || $column->is_date_time() || $column->is_bool() ) {
			return true;
		}

		// A bounded string within the conservative limit; unbounded text/blob cannot.
		$length = (int) $column->length;

		return $column->is_bounded_string() && ( $length > 0 ) && ( $length <= 191 );
	}

	/** Public Item Core ******************************************************/

	/**
	 * Clear items from the schema.
	 *
	 * Pass a valid item type to clear only that collection. Pass an empty string
	 * (the default) to clear both columns and indexes at once.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Optional. Item collection type to clear. Accepts
	 *                     'columns', 'indexes', or their singular aliases.
	 *                     Default empty string clears everything.
	 */
	public function clear( $type = '' ): void {

		// Clearing a specific collection.
		if ( ! empty( $type ) ) {
			$type = $this->validate_item_type( $type );

			// Bail if type is not valid.
			if ( ! empty( $type ) ) {
				$this->{$type} = array();
			}

			// Clearing everything.
		} else {
			$this->columns = array();
			$this->indexes = array();
		}
	}

	/**
	 * Add an item to a specific collection.
	 *
	 * @since 3.0.0
	 *
	 * Supports both signatures:
	 * - add_item( $type, $data )
	 * - add_item( $type, $class, $data )
	 *
	 * @param string                                  $type          Item collection type. Accepts
	 *                                                               'columns' or 'indexes' (and
	 *                                                               their singular aliases).
	 * @param string|array<string,mixed>|Column|Index $class_or_data Class name (legacy signature)
	 *                                                               or item data (current signature).
	 * @param array<string,mixed>|Column|Index        $data          Optional item data when using
	 *                                                               the legacy signature.
	 *
	 * @return Column|Index|false The added item object, or false on failure.
	 */
	public function add_item( $type = 'columns', $class_or_data = array(), $data = array() ) {

		// Normalize and validate item type.
		$type = $this->validate_item_type( $type );

		// Bail if type is not valid.
		if ( empty( $type ) ) {
			return false;
		}

		// Default class by normalized type.
		$class = $this->get_item_class( $type );

		// Resolve arguments for current and legacy signatures.
		if ( is_string( $class_or_data ) && class_exists( $class_or_data ) ) {
			$class = $class_or_data;
		} else {
			$data = $class_or_data;
		}

		// Bail if class is not valid.
		if ( empty( $class ) || ! class_exists( $class ) ) {
			return false;
		}

		// Bail if $data is a string.
		if ( is_string( $data ) ) {
			return false;
		}

		// Instantiate from array/object data.
		$retval = $this->create_item( $class, $data );

		// Bail if no item to add.
		if ( empty( $retval ) ) {
			return false;
		}

		// Add item to array.
		$this->{$type}[] = $retval;

		// Return the item.
		return $retval;
	}

	/**
	 * Get a schema item collection by type, optionally filtered.
	 *
	 * With no $args, returns the whole collection. With $args, returns the items whose
	 * properties match (via WordPress's wp_filter_object_list()) - e.g.
	 * get_items( 'columns', array( 'primary' => true ) ). Mirrors Query::get_columns().
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Added $args / $operator filtering.
	 *
	 * @param string              $type     Item collection type. Accepts 'columns' or
	 *                                      'indexes' (and their singular aliases).
	 * @param array<string,mixed> $args     Optional. Property => value match args.
	 *                                      Default empty (the full collection).
	 * @param string              $operator Optional. Match logic: 'and' (default), 'or',
	 *                                      or 'not'.
	 *
	 * @return Column[]|Index[]
	 */
	public function get_items( $type = 'columns', $args = array(), $operator = 'and' ) {
		$type = $this->validate_item_type( $type );

		// Resolve the collection.
		if ( 'columns' === $type ) {
			$items = $this->columns;
		} elseif ( 'indexes' === $type ) {
			$items = $this->indexes;
		} else {
			return array();
		}

		// The whole collection when there is nothing to filter.
		if ( empty( $args ) ) {
			return $items;
		}

		/*
		 * Match each item type's stored case so a caller can pass either (Column
		 * $type is uppercase, Index $type is lowercase).
		 */
		if ( isset( $args['type'] ) && is_string( $args['type'] ) ) {
			$args['type'] = ( 'columns' === $type )
				? strtoupper( $args['type'] )
				: strtolower( $args['type'] );
		}

		// Filter to the matching item objects.
		$filtered = wp_filter_object_list( $items, $args, $operator );

		/** @var Column[]|Index[] $filtered */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		return array_values( $filtered );
	}

	/**
	 * Get a schema item by name.
	 *
	 * For the 'indexes' type, the reserved name 'primary' matches the first
	 * index whose type is 'primary', regardless of its $name property.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item collection type. Accepts 'columns' or 'indexes'
	 *                     (and their singular aliases).
	 * @param string $name Normalized item name to find. For indexes, also
	 *                     accepts 'primary' to match the primary key.
	 *
	 * @return Column|Index|false The matching item object, or false if not found.
	 */
	public function get_item( $type = 'columns', $name = '' ) {
		$type = $this->validate_item_type( $type );
		$name = $this->sanitize_index_name( $name );

		if ( empty( $type ) || empty( $name ) ) {
			return false;
		}

		foreach ( $this->get_items( $type ) as $item ) {

			// PRIMARY indexes are addressable by the "primary" name.
			if ( 'indexes' === $type && 'primary' === $name && $this->is_primary_index( $item ) ) {
				return $item;
			}

			$item_name = isset( $item->name )
				? $this->sanitize_index_name( $item->name )
				: false;

			if ( ! empty( $item_name ) && $name === $item_name ) {
				return $item;
			}
		}

		return false;
	}

	/**
	 * Check whether this schema has a specific item.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item collection type. Accepts 'columns' or 'indexes'
	 *                     (and their singular aliases).
	 * @param string $name Item name to check. Accepts 'primary' for indexes.
	 *
	 * @return bool True if the item exists, false if not.
	 */
	public function has_item( $type = 'columns', $name = '' ) {
		return ( false !== $this->get_item( $type, $name ) );
	}

	/**
	 * Remove an item from a collection by name.
	 *
	 * For the 'indexes' type, passing 'primary' removes the first index whose
	 * type is 'primary', regardless of its $name property.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item collection type. Accepts 'columns' or 'indexes'
	 *                     (and their singular aliases).
	 * @param string $name Normalized item name to remove. For indexes, also
	 *                     accepts 'primary' to target the primary key.
	 *
	 * @return bool True if one or more items were removed, false if not.
	 */
	public function remove_item( $type = 'columns', $name = '' ) {
		$type = $this->validate_item_type( $type );
		$name = $this->sanitize_index_name( $name );

		// Bail if type or name is not valid.
		if ( empty( $type ) || empty( $name ) || ! is_array( $this->{$type} ) ) {
			return false;
		}

		$removed = false;

		// Loop through items and remove any matching the target name.
		foreach ( $this->{$type} as $key => $item ) {

			// Only objects of the correct type can be removed.
			if ( ! ( $item instanceof Column ) && ! ( $item instanceof Index ) ) {
				continue;
			}

			// PRIMARY indexes are removable by the "primary" name.
			$is_primary = ( 'indexes' === $type ) && $this->is_primary_index( $item );

			// Match by name, or by primary type for indexes.
			$item_name = isset( $item->name )
				? $this->sanitize_index_name( $item->name )
				: false;

			// Remove the item if it's a primary index targeted by the "primary" name, or if its name matches the target name.
			if ( ( $is_primary && 'primary' === $name ) || ( ! empty( $item_name ) && ( $name === $item_name ) ) ) {
				unset( $this->{$type}[ $key ] );
				$removed = true;
			}
		}

		// Reindex the array if we removed any items, to prevent gaps in the keys.
		if ( true === $removed ) {
			$this->{$type} = array_values( $this->{$type} );
		}

		// Return whether we removed anything.
		return $removed;
	}

	/**
	 * Replace all items in a collection.
	 *
	 * Clears the existing collection and rebuilds it from the provided values.
	 *
	 * @since 3.0.0
	 *
	 * @param string                                     $type  Item collection type. Accepts 'columns'
	 *                                                          or 'indexes' (and their singular aliases).
	 * @param list<array<string,mixed>>|Column[]|Index[] $items Array of argument arrays or item objects.
	 *
	 * @return Column[]|Index[]
	 */
	public function set_items( $type = 'columns', $items = array() ) {
		return $this->setup_items( $type, $items );
	}

	/** Private Internals *****************************************************/

	/**
	 * Clear and rebuild a collection from raw data.
	 *
	 * This is the internal implementation for set_items(). It clears the target
	 * collection first, then instantiates each value through create_item().
	 *
	 * @since 3.0.0
	 *
	 * @param string                                     $type   Item collection type. Accepts 'columns'
	 *                                                           or 'indexes' (and their singular aliases).
	 * @param list<array<string,mixed>>|Column[]|Index[] $values Array of argument arrays or item objects.
	 *
	 * @return Column[]|Index[] The newly built collection.
	 */
	private function setup_items( $type = 'columns', $values = array() ): array {

		// Normalize and validate item type.
		$type = $this->validate_item_type( $type );

		// Bail if type is not valid.
		if ( empty( $type ) ) {
			return array();
		}

		// Default class by normalized type.
		$class = $this->get_item_class( $type );

		// Bail if no class.
		if ( empty( $class ) || ! class_exists( $class ) ) {
			return array();
		}

		// Clear items for type.
		$this->clear( $type );

		// Bail if no values.
		if ( empty( $values ) || ! is_array( $values ) ) {
			return array();
		}

		// Loop through values and create objects from them.
		foreach ( $values as $item ) {
			$object = $this->create_item( $class, $item );

			if ( false !== $object ) {
				$this->{$type}[] = $object;
			}
		}

		// Return the items.
		return $this->{$type};
	}

	/**
	 * Get the item class name for a given collection type.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item collection type. Accepts 'columns' or 'indexes'
	 *                     (and their singular aliases).
	 *
	 * @return string|false Fully-qualified class name string, or false on failure.
	 */
	private function get_item_class( $type = 'columns' ): string|false {

		// Validate the item type.
		$type = $this->validate_item_type( $type );

		// Bail if type is not valid.
		if ( empty( $type ) ) {
			return false;
		}

		return ( 'indexes' === $type )
			? $this->index
			: $this->column;
	}

	/**
	 * Create a schema item instance from array or existing object data.
	 *
	 * Accepts an argument array (passed to the class constructor) or an already
	 * instantiated object of the correct class (returned as-is).
	 *
	 * @since 3.0.0
	 *
	 * @param string                           $class_name Fully-qualified class name to instantiate.
	 * @param array<string,mixed>|Column|Index $data       Argument array or existing item object.
	 *
	 * @return Column|Index|false The item object, or false on failure.
	 */
	private function create_item( $class_name = '', $data = array() ): Column|Index|false {

		// Bail if class cannot be instantiated.
		if ( empty( $class_name ) || ! class_exists( $class_name ) ) {
			return false;
		}

		// Bail if there is no data to turn into an object.
		if ( empty( $data ) ) {
			return false;
		}

		// Array data is passed to the item constructor.
		if ( is_array( $data ) ) {
			$item = $this->instantiate_class( $class_name, '', $data );

			/** @var Column|Index|null $item */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			return $item ?? false;
		}

		// Already-instantiated object.
		if ( $data instanceof $class_name ) {
			/** @var Column|Index $data */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			return $data;
		}

		return false;
	}

	/**
	 * Build the SQL fragment for a collection, for use inside a CREATE TABLE
	 * statement.
	 *
	 * Calls get_create_string() on each item and joins non-empty results with
	 * commas and newlines, each indented by two spaces.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item collection type. Accepts 'columns' or 'indexes'
	 *                     (and their singular aliases).
	 *
	 * @return string Comma-and-newline-separated SQL clause fragments, or empty
	 *               string if the collection is empty or the type is invalid.
	 */
	private function get_items_create_string( $type = 'columns' ): string {

		// Normalize and validate item type.
		$type = $this->validate_item_type( $type );

		// Bail if type is not valid.
		if ( empty( $type ) ) {
			return '';
		}

		// Bail if no items to get strings from.
		if ( empty( $this->{$type} ) || ! is_array( $this->{$type} ) ) {
			return '';
		}

		// Two-space indent for readability inside CREATE TABLE.
		$indent = '  ';

		// Accumulate SQL fragments.
		$strings = array();

		// Build a SQL fragment for each item.
		foreach ( $this->{$type} as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_create_string' ) ) {
				$string = $item->get_create_string();

				if ( '' !== $string ) {
					$strings[] = $indent . $string;
				}
			}
		}

		// Return the SQL.
		return implode( ",\n", $strings );
	}

	/** Item Helpers **********************************************************/

	/**
	 * Add a column to this schema.
	 *
	 * Convenience wrapper around add_item() for columns.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed>|Column $data Argument array or existing Column object.
	 *
	 * @return Column|false The added Column object, or false on failure.
	 */
	public function add_column( $data = array() ) {
		$retval = $this->add_item( 'columns', $data );

		return ( $retval instanceof Column )
			? $retval
			: false;
	}

	/**
	 * Get all columns in this schema, optionally filtered.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Added $args / $operator filtering.
	 *
	 * @param array<string,mixed> $args     Optional. Property => value match args.
	 *                                      Default empty (all columns).
	 * @param string              $operator Optional. Match logic: 'and' (default), 'or',
	 *                                      or 'not'.
	 *
	 * @return Column[]
	 */
	public function get_columns( $args = array(), $operator = 'and' ) {
		$items = $this->get_items( 'columns', $args, $operator );

		/** @var Column[] $items */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		return $items;
	}

	/**
	 * Get a column in this schema by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Column name (case-insensitive).
	 *
	 * @return Column|false The matching Column object, or false if not found.
	 */
	public function get_column( $name = '' ) {
		$retval = $this->get_item( 'columns', $name );

		return ( $retval instanceof Column )
			? $retval
			: false;
	}

	/**
	 * Check whether this schema has a column by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Column name (case-insensitive).
	 *
	 * @return bool True if the column exists, false if not.
	 */
	public function has_column( $name = '' ) {
		return $this->has_item( 'columns', $name );
	}

	/**
	 * Replace all columns in this schema.
	 *
	 * @since 3.0.0
	 *
	 * @param list<array<string,mixed>>|Column[] $columns Array of argument arrays or Column objects.
	 *
	 * @return Column[]
	 */
	public function set_columns( $columns = array() ) {
		$items = $this->set_items( 'columns', $columns );

		/** @var Column[] $items */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		return $items;
	}

	/**
	 * Remove a column by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Column name (case-insensitive).
	 *
	 * @return bool True if the column was removed, false if not found.
	 */
	public function remove_column( $name = '' ) {
		return $this->remove_item( 'columns', $name );
	}

	/**
	 * Get every relationship declared in this schema as Relationship objects.
	 *
	 * Acts as the compiler for relationship declarations: it walks all columns,
	 * reads their per-column shorthand entries, and folds each one into a
	 * first-class Relationship object whose local side is the declaring column.
	 * Columns without relationships are skipped. See
	 * Column::sanitize_relationships() for the shorthand entry shape, and
	 * berlindb/core #193 for how the Query class uses these to prime related
	 * items.
	 *
	 * @since 3.1.0
	 *
	 * @return list<Relationship>
	 */
	public function get_relationships() {
		$retval = array();

		// Loop through columns and compile any declared relationships.
		foreach ( $this->get_columns() as $column ) {

			// Skip columns that declare no relationships.
			if ( empty( $column->relationships ) ) {
				continue;
			}

			// Each shorthand entry maps this column to one remote column.
			foreach ( $column->relationships as $relationship ) {
				$args = array(
					'type'       => $relationship['type'],
					'columns'    => array( $column->name ),
					'query'      => $relationship['query'],
					'references' => array( $relationship['column'] ),
				);

				/*
				 * Pass declared optional attributes through; the Relationship
				 * derives an accessor name from the local column otherwise, and
				 * validates the enforced-FK attributes itself.
				 */
				foreach ( array( 'name', 'enforce', 'on_delete', 'on_update', 'constraint' ) as $key ) {
					if ( isset( $relationship[ $key ] ) ) {
						$args[ $key ] = $relationship[ $key ];
					}
				}

				$retval[] = new Relationship( $args );
			}
		}

		return $retval;
	}

	/**
	 * Add an index to this schema.
	 *
	 * Convenience wrapper around add_item() for indexes.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed>|Index $data Argument array or existing Index object.
	 *
	 * @return Index|false The added Index object, or false on failure.
	 */
	public function add_index( $data = array() ) {
		$retval = $this->add_item( 'indexes', $data );

		return ( $retval instanceof Index )
			? $retval
			: false;
	}

	/**
	 * Get all indexes in this schema, optionally filtered.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Added $args / $operator filtering.
	 *
	 * @param array<string,mixed> $args     Optional. Property => value match args.
	 *                                      Default empty (all indexes).
	 * @param string              $operator Optional. Match logic: 'and' (default), 'or',
	 *                                      or 'not'.
	 *
	 * @return Index[]
	 */
	public function get_indexes( $args = array(), $operator = 'and' ) {
		$items = $this->get_items( 'indexes', $args, $operator );

		/** @var Index[] $items */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		return $items;
	}

	/**
	 * Get an index in this schema by name.
	 *
	 * Pass 'primary' to retrieve the primary key index.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Index name (case-insensitive), or 'primary'.
	 *
	 * @return Index|false The matching Index object, or false if not found.
	 */
	public function get_index( $name = '' ) {
		$retval = $this->get_item( 'indexes', $name );

		return ( $retval instanceof Index )
			? $retval
			: false;
	}

	/**
	 * Resolve an index reference to its canonical SQL name, or '' if not declared.
	 *
	 * Accepts 'primary' (any case) or a declared index name (matched case-insensitively)
	 * and returns the name as it appears in SQL - PRIMARY for the primary key, the
	 * declared name otherwise - so callers (e.g. index hints) can validate a reference
	 * and render it without re-deriving index identity. An unknown reference returns ''
	 * (a resolvable index always has a non-empty name), so callers need no type check.
	 *
	 * @since 3.1.0
	 *
	 * @param string $ref Index reference: 'primary', or a declared index name.
	 * @return string Canonical SQL index name, or '' if not declared.
	 */
	public function canonical_index_name( $ref = '' ): string {
		$index = $this->get_index( $ref );

		return ( $index instanceof Index )
			? $index->get_index_name()
			: '';
	}

	/**
	 * Check whether this schema has an index by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Index name (case-insensitive), or 'primary'.
	 *
	 * @return bool True if the index exists, false if not.
	 */
	public function has_index( $name = '' ) {
		return $this->has_item( 'indexes', $name );
	}

	/**
	 * Replace all indexes in this schema.
	 *
	 * @since 3.0.0
	 *
	 * @param list<array<string,mixed>>|Index[] $indexes Array of argument arrays or Index objects.
	 *
	 * @return Index[]
	 */
	public function set_indexes( $indexes = array() ) {
		$items = $this->set_items( 'indexes', $indexes );

		/** @var Index[] $items */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		return $items;
	}

	/**
	 * Remove an index by name.
	 *
	 * Pass 'primary' to remove the primary key index.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Index name (case-insensitive), or 'primary'.
	 *
	 * @return bool True if the index was removed, false if not found.
	 */
	public function remove_index( $name = '' ) {
		return $this->remove_item( 'indexes', $name );
	}

	/**
	 * Return the SQL body for a "CREATE TABLE" statement.
	 *
	 * Combines the column and index SQL fragments into a single string ready
	 * to be placed inside the parentheses of a CREATE TABLE query. Validation
	 * runs first; returns an empty string if the schema is not valid.
	 *
	 * NOTE: foreign-key DDL is intentionally NOT emitted here. Relationship
	 * metadata (type, columns, references, and the enforce / on_delete /
	 * on_update / constraint attributes) is declarable, and
	 * Relationship::get_create_string() can render a FOREIGN KEY fragment - but
	 * it is future-ready metadata, not wired into table creation. WordPress
	 * deliberately avoids real foreign keys (dbDelta doesn't support them, and
	 * enforced keys would require resolving and install-ordering the remote
	 * table), so relationships are enforced at the application layer for now.
	 * Only columns and indexes are emitted.
	 *
	 * @since 3.0.0
	 *
	 * @return string SQL body string, or empty string if invalid or empty.
	 */
	public function get_create_table_string() {

		// Bail if schema has validation errors.
		if ( ! $this->is_valid() ) {
			return '';
		}

		/*
		 * Columns and indexes only. Relationship/foreign-key DDL is declarable
		 * (see Relationship::get_create_string()) but intentionally not emitted -
		 * see this method's docblock.
		 */
		$strings = array(
			$this->get_items_create_string( 'columns' ),
			$this->get_items_create_string( 'indexes' ),
		);

		// Join non-empty fragments.
		$retval = implode( ",\n", array_filter( $strings ) );

		return $retval;
	}

	/** Validators ************************************************************/

	/**
	 * Return validation errors for this schema.
	 *
	 * Checks for:
	 * - Columns missing a name.
	 * - Duplicate column names.
	 * - Indexes missing a name (non-primary).
	 * - Duplicate index names.
	 * - Indexes referencing columns not present in the schema.
	 * - Indexes with no columns defined.
	 * - Conflicting primary keys: multiple primary indexes, multiple primary-
	 *   flagged columns with no covering composite primary index, or a primary-
	 *   flagged column the primary index does not cover. (A flagged column
	 *   covered by the primary index is ONE key - the flag is the semantic
	 *   marker; the index emits the DDL.)
	 * - Relationships: own-shape errors (Relationship::get_validation_errors()),
	 *   a missing/duplicate accessor name, a local column not present in this
	 *   schema, a named-but-missing remote query class, and composite (multi-
	 *   column) declarations that are not supported at runtime.
	 *
	 * Remote-side relationship checks (the class is truly a Query, the remote
	 * columns exist) need Query context and live in
	 * Query::get_relationship_errors(); they are intentionally not here.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Added relationship declaration checks (#193, #206).
	 *
	 * @return string[] Array of human-readable error strings. Empty if valid.
	 */
	public function get_validation_errors() {
		$errors  = array();
		$columns = $this->get_columns();
		$indexes = $this->get_indexes();

		$column_names          = array();
		$index_names           = array();
		$primary_columns       = array();
		$primary_index_columns = array();
		$primary_index_count   = 0;

		foreach ( $columns as $column ) {

			$column_name = ! empty( $column->name )
				? $this->sanitize_index_name( $column->name )
				: false;

			if ( empty( $column_name ) ) {
				$errors[] = 'Schema column is missing a valid name.';
				continue;
			}

			if ( isset( $column_names[ $column_name ] ) ) {
				$errors[] = "Duplicate column name found: {$column_name}.";
			}

			$column_names[ $column_name ] = true;

			if ( ! empty( $column->primary ) ) {
				$primary_columns[] = $column_name;
			}
		}

		foreach ( $indexes as $index ) {

			$is_primary = $this->is_primary_index( $index );

			$index_name = $is_primary
				? 'primary'
				: ( ! empty( $index->name ) ? $this->sanitize_index_name( $index->name ) : false );

			if ( empty( $index_name ) ) {
				$errors[] = 'Schema index is missing a valid name.';
				continue;
			}

			if ( isset( $index_names[ $index_name ] ) ) {
				$errors[] = "Duplicate index name found: {$index_name}.";
			}

			$index_names[ $index_name ] = true;

			$index_columns = ! empty( $index->columns )
				? (array) $index->columns
				: array();

			if ( true === $is_primary ) {
				++$primary_index_count;
				$primary_index_columns = array_map( array( $this, 'sanitize_index_name' ), $index_columns );
			}

			if ( empty( $index_columns ) ) {
				$errors[] = "Index {$index_name} does not include any columns.";
				continue;
			}

			foreach ( $index_columns as $index_column ) {
				$index_column = $this->sanitize_index_name( $index_column );

				if ( empty( $index_column ) || ! isset( $column_names[ $index_column ] ) ) {
					$errors[] = "Index {$index_name} references unknown column " . ( empty( $index_column )
						? '(invalid)'
						: $index_column
					) . '.';
				}
			}
		}

		/*
		 * Reconcile primary-key declarations. A column flagged primary AND a
		 * primary index covering that same column describe ONE primary key - the
		 * flag is the semantic marker queries and parsers read; the index emits
		 * the DDL - not a conflict. Real conflicts: multiple primary indexes,
		 * multiple flagged columns without a covering composite primary index, or
		 * a flagged column the primary index does not cover.
		 */
		if ( 1 < $primary_index_count ) {
			$errors[] = 'Schema defines multiple primary keys.';
		} elseif ( 1 === $primary_index_count ) {
			foreach ( $primary_columns as $primary_column ) {
				if ( ! in_array( $primary_column, $primary_index_columns, true ) ) {
					$errors[] = "Primary column {$primary_column} is not covered by the primary key index.";
				}
			}
		} elseif ( 1 < count( $primary_columns ) ) {
			$errors[] = 'Schema defines multiple primary keys.';
		}

		/*
		 * Relationship checks (#193, #206). Accessor names must be unique within
		 * the schema (they address related data and become Row accessors); the
		 * rest validate each declaration against this schema's own columns. Remote
		 * resolution (the class is truly a sibling Query, the remote columns
		 * exist) needs Query context and lives in Query::get_relationship_errors().
		 */
		$relationship_names = array();

		foreach ( $this->get_relationships() as $relationship ) {

			// Fold in the relationship's own shape errors (no schema context).
			$errors = array_merge( $errors, $relationship->get_validation_errors() );

			$relationship_name = ! empty( $relationship->name )
				? $relationship->name
				: false;

			if ( empty( $relationship_name ) ) {
				$errors[] = 'Schema relationship is missing a valid name.';
				continue;
			}

			if ( isset( $relationship_names[ $relationship_name ] ) ) {
				$errors[] = "Duplicate relationship name found: {$relationship_name}.";
			}

			$relationship_names[ $relationship_name ] = true;

			// Every local column the relationship names must exist in this schema.
			foreach ( $relationship->columns as $local_column ) {
				$local_column = $this->sanitize_index_name( $local_column );

				if ( empty( $local_column ) || ! isset( $column_names[ $local_column ] ) ) {
					$errors[] = "Relationship {$relationship_name} references unknown local column " . ( empty( $local_column )
						? '(invalid)'
						: $local_column
					) . '.';
				}
			}

			/*
			 * A named remote query class must at least exist. Whether it is truly
			 * a Query is checked in Query::get_relationship_errors() (which can
			 * instantiate it); the empty-class case is covered by the shape check.
			 */
			if ( ( '' !== $relationship->query ) && ! class_exists( $relationship->query ) ) {
				$errors[] = "Relationship {$relationship_name} names a missing remote query class: {$relationship->query}.";
			}

			/*
			 * Composite relationships (more than one local column) are not
			 * supported at runtime (single-column only). Flag rather than let them
			 * silently fail closed at query time.
			 */
			if ( 1 < count( $relationship->columns ) ) {
				$errors[] = "Relationship {$relationship_name} is composite (multiple local columns), which is not supported at runtime.";
			}
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Return whether this schema is valid.
	 *
	 * @since 3.0.0
	 *
	 * @return bool True if there are no validation errors, false otherwise.
	 */
	public function is_valid() {
		return empty( $this->get_validation_errors() );
	}

	/**
	 * Check whether a given item is a primary index.
	 *
	 * Centralizes the repeated inline logic of inspecting an item's $type
	 * property and comparing it to 'primary' (case-insensitively).
	 *
	 * @since 3.0.0
	 *
	 * @param Index|Column $item Index or Column item object.
	 *
	 * @return bool True if the item's type is 'primary', false otherwise.
	 */
	private function is_primary_index( $item ): bool {
		$type = strtolower( trim( $item->type ) );

		return ( 'primary' === $type );
	}

	/**
	 * Validate and normalize an item type string.
	 *
	 * Accepts both plural and singular forms. Returns the canonical plural form,
	 * or an empty string if the value is not recognized.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item type. Accepts 'columns', 'column', 'indexes', or
	 *                     'index' (case-insensitive).
	 *
	 * @return string Normalized type ('columns' or 'indexes'), or empty string.
	 */
	private function validate_item_type( $type = '' ): string {

		// Normalize into a lowercase string.
		$type = strtolower( trim( (string) $type ) );

		// Allowed aliases. Singular are for backwards compatibility only.
		$types = array(
			'column'  => 'columns',
			'columns' => 'columns',
			'index'   => 'indexes',
			'indexes' => 'indexes',
		);

		// Return normalized type if valid.
		return isset( $types[ $type ] )
			? $types[ $type ]
			: '';
	}

	/** Deprecated ************************************************************/

	/**
	 * Return the columns in string form.
	 *
	 * This method was deprecated in 3.0.0 because in previous versions it only
	 * included Columns and did not include Indexes.
	 *
	 * @since 1.0.0
	 * @deprecated 3.0.0 Use get_create_table_string() instead.
	 * @see get_create_table_string()
	 *
	 * @return string
	 */
	protected function to_string() {
		return $this->get_items_create_string( 'columns' );
	}
}
