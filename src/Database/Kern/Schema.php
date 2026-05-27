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

		// Suppress wpdb errors so a nonexistent table silently returns an empty
		// Schema rather than printing an HTML error block into the page output.
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
	 * for legacy compatibility. setup() will hydrate them into Column objects.
	 *
	 * @since 1.0.0
	 * @var   Column[]
	 */
	protected $columns = array();

	/**
	 * Array of database Index objects.
	 *
	 * May be pre-populated in a subclass as an array of Index argument arrays
	 * for legacy compatibility. setup() will hydrate them into Index objects.
	 *
	 * @since 3.0.0
	 * @var   Index[]
	 */
	protected $indexes = array();

	/** Public Methods ********************************************************/

	/**
	 * Early lifecycle hook, called by Traits\Boot before class properties are
	 * assigned. Used to hydrate any $columns or $indexes pre-set by a subclass.
	 *
	 * @since 3.0.0
	 */
	protected function sunrise(): void {
		$this->setup();
	}

	/**
	 * Late lifecycle hook, called by Traits\Boot after class properties are
	 * assigned. Ensures $columns and $indexes are always hydrated into objects.
	 *
	 * @since 3.0.0
	 */
	protected function init(): void {
		$this->setup();
	}

	/**
	 * Setup the class variables.
	 *
	 * This method includes legacy support for Schema objects that predefined
	 * their array of Columns. This approach will not be removed, as it was the
	 * only way to register Columns in all versions before 3.0.0.
	 *
	 * @since 3.0.0
	 */
	public function setup(): void {

		// Legacy support for pre-set $columns array.
		if ( ! empty( $this->columns ) && is_array( $this->columns ) ) {
			$this->setup_items( 'columns', $this->columns );
		}

		// Legacy support for pre-set $indexes array.
		if ( ! empty( $this->indexes ) && is_array( $this->indexes ) ) {
			$this->setup_items( 'indexes', $this->indexes );
		}
	}

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
	 * @param string                                   $type          Item collection type. Accepts
	 *                                                                'columns' or 'indexes' (and
	 *                                                                their singular aliases).
	 * @param string|array<string, mixed>|Column|Index $class_or_data Class name (legacy signature)
	 *                                                                or item data (current signature).
	 * @param array<string, mixed>|Column|Index        $data          Optional item data when using
	 *                                                                the legacy signature.
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

	/** Public Item Core ******************************************************/

	/**
	 * Get a schema item collection by type.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item collection type. Accepts 'columns' or 'indexes'
	 *                     (and their singular aliases).
	 *
	 * @return Column[]|Index[]
	 */
	public function get_items( $type = 'columns' ) {
		$type = $this->validate_item_type( $type );

		if ( 'columns' === $type ) {
			return $this->columns;
		}

		if ( 'indexes' === $type ) {
			return $this->indexes;
		}

		return array();
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
	 * @param string                                      $type  Item collection type. Accepts 'columns'
	 *                                                           or 'indexes' (and their singular aliases).
	 * @param list<array<string, mixed>>|Column[]|Index[] $items Array of argument arrays or item objects.
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
	 * @param string                                      $type   Item collection type. Accepts 'columns'
	 *                                                            or 'indexes' (and their singular aliases).
	 * @param list<array<string, mixed>>|Column[]|Index[] $values Array of argument arrays or item objects.
	 *
	 * @return Column[]|Index[] The newly built collection.
	 */
	private function setup_items( $type = 'columns', $values = array() ) {

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
	private function get_item_class( $type = 'columns' ) {

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
	 * @param string                            $class_name Fully-qualified class name to instantiate.
	 * @param array<string, mixed>|Column|Index $data       Argument array or existing item object.
	 *
	 * @return Column|Index|false The item object, or false on failure.
	 */
	private function create_item( $class_name = '', $data = array() ) {

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
			$retval = new $class_name( $data );

			/** @var Column|Index $retval */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			return $retval;
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
	private function get_items_create_string( $type = 'columns' ) {

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
	 * @param array<string, mixed>|Column $data Argument array or existing Column object.
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
	 * Get all columns in this schema.
	 *
	 * @since 3.0.0
	 *
	 * @return Column[]
	 */
	public function get_columns() {
		$items = $this->get_items( 'columns' );

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
	 * @param list<array<string, mixed>>|Column[] $columns Array of argument arrays or Column objects.
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
	 * Add an index to this schema.
	 *
	 * Convenience wrapper around add_item() for indexes.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed>|Index $data Argument array or existing Index object.
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
	 * Get all indexes in this schema.
	 *
	 * @since 3.0.0
	 *
	 * @return Index[]
	 */
	public function get_indexes() {
		$items = $this->get_items( 'indexes' );

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
	 * @param list<array<string, mixed>>|Index[] $indexes Array of argument arrays or Index objects.
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
	 * @since 3.0.0
	 *
	 * @return string SQL body string, or empty string if invalid or empty.
	 */
	public function get_create_table_string() {

		// Bail if schema has validation errors.
		if ( ! $this->is_valid() ) {
			return '';
		}

		// Build SQL fragments for each collection.
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
	 * - More than one primary key defined (across columns and indexes).
	 *
	 * @since 3.0.0
	 *
	 * @return string[] Array of human-readable error strings. Empty if valid.
	 */
	public function get_validation_errors() {
		$errors  = array();
		$columns = $this->get_columns();
		$indexes = $this->get_indexes();

		$column_names  = array();
		$index_names   = array();
		$primary_count = 0;

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
				++$primary_count;
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

			if ( true === $is_primary ) {
				++$primary_count;
			}

			$index_columns = ! empty( $index->columns )
				? (array) $index->columns
				: array();

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

		if ( 1 < $primary_count ) {
			$errors[] = 'Schema defines multiple primary keys.';
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
	private function is_primary_index( $item ) {
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
	private function validate_item_type( $type = '' ) {

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
