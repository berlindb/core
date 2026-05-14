<?php
/**
 * Base Custom Database Table Schema Class.
 *
 * @package     Database
 * @subpackage  Schema
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */
namespace BerlinDB\Database;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * A base database table schema class, which houses the collection of columns
 * that a table is made out of.
 *
 * This class is intended to be extended for each unique database table,
 * including global tables for multisite, and users tables.
 *
 * @since 1.0.0
 * @since 3.0.0 Added variables for Column & Index
 */
class Schema {

	/**
	 * Use the following traits:
	 *
	 * @since 3.0.0
	 */
	use Traits\Base;
	use Traits\Boot;

	/** Types *****************************************************************/

	/**
	 * Schema Column class.
	 *
	 * @since 3.0.0
	 * @var   string
	 */
	protected $column = __NAMESPACE__ . '\\Column';

	/**
	 * Schema Index class.
	 *
	 * @since 3.0.0
	 * @var   string
	 */
	protected $index = __NAMESPACE__ . '\\Index';

	/** Item Objects **********************************************************/

	/**
	 * Array of database Column objects.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	protected $columns = array();

	/**
	 * Array of database Index objects.
	 *
	 * @since 3.0.0
	 * @var   array
	 */
	protected $indexes = array();

	/** Public Methods ********************************************************/

	/**
	 * Early setup for Legacy $columns support.
	 *
	 * @since 3.0.0
	 */
	protected function sunrise() {
		$this->setup();
	}

	/**
	 * Late setup for modern $columns & $index support.
	 *
	 * @since 3.0.0
	 */
	protected function init() {
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
	public function setup() {

		// Legacy support for pre-set $columns array
		if ( ! empty( $this->columns ) && is_array( $this->columns ) ) {
			$this->setup_items( 'columns', $this->columns );
		}

		// Legacy support for pre-set $indexes array
		if ( ! empty( $this->indexes ) && is_array( $this->indexes ) ) {
			$this->setup_items( 'indexes', $this->indexes );
		}
	}

	/**
	 * Clear some part of the schema.
	 *
	 * Will clear all items if nothing is passed.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type The type of items to clear.
	 */
	public function clear( $type = '' ) {

		// Clearing specific
		if ( ! empty( $type ) ) {
			$type = $this->validate_item_type( $type );

			// Bail if type is not valid.
			if ( ! empty( $type ) ) {
				$this->{$type} = array();
			}

		// Clearing everything
		} else {
			$this->columns = array();
			$this->indexes = array();
		}
	}

	/**
	 * Add an item to a specific items array.
	 *
	 * @since 3.0.0
	 *
	 * @param string       $type Item type to add.
	 * @param array|object $data Data to pass into class constructor.
	 *
	 * @return object|false
	 */
	public function add_item( $type = 'columns', $data = array() ) {

		// Normalize and validate item type.
		$type = $this->validate_item_type( $type );

		// Bail if type is not valid.
		if ( empty( $type ) ) {
			return false;
		}

		// Default class by normalized type.
		$class = $this->get_item_class( $type );

		// Bail if class is not valid.
		if ( empty( $class ) || ! class_exists( $class ) ) {
			return false;
		}

		// Instantiate from array/object data.
		$retval = $this->create_item( $class, $data );

		// Bail if no item to add
		if ( empty( $retval ) ) {
			return false;
		}

		// Add item to array
		$this->{$type}[] = $retval;

		// Return the item
		return $retval;
	}

	/** Public Item Core ******************************************************/

	/**
	 * Get a schema item collection by type.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item collection type. Accepts 'columns' or 'indexes'.
	 *
	 * @return array
	 */
	public function get_items( $type = 'columns' ) {
		$type = $this->validate_item_type( $type );

		// Limit to known item collections.
		if ( empty( $type ) ) {
			return array();
		}

		// Return the requested item collection.
		return is_array( $this->{$type} )
			? $this->{$type}
			: array();
	}

	/**
	 * Get a schema item by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item collection type. Accepts 'columns' or 'indexes'.
	 * @param string $name Item name to find.
	 *
	 * @return object|false
	 */
	public function get_item( $type = 'columns', $name = '' ) {
		$type = $this->validate_item_type( $type );
		$name = $this->normalize_item_name( $name );

		if ( empty( $type ) || empty( $name ) ) {
			return false;
		}

		foreach ( $this->get_items( $type ) as $item ) {

			// Handle primary indexes that do not require a name.
			if ( 'indexes' === $type ) {
				$item_type = isset( $item->type )
					? strtolower( trim( (string) $item->type ) )
					: '';

				if ( 'primary' === $item_type && 'primary' === $name ) {
					return $item;
				}
			}

			$item_name = isset( $item->name )
				? $this->normalize_item_name( $item->name )
				: '';

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
	 * @param string $type Item collection type. Accepts 'columns' or 'indexes'.
	 * @param string $name Item name to check.
	 *
	 * @return bool
	 */
	public function has_item( $type = 'columns', $name = '' ) {
		return ( false !== $this->get_item( $type, $name ) );
	}

	/**
	 * Remove an item from a collection by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item collection type. Accepts 'columns' or 'indexes'.
	 * @param string $name Item name.
	 *
	 * @return bool True if an item was removed, false if not.
	 */
	public function remove_item( $type = 'columns', $name = '' ) {
		$type = $this->validate_item_type( $type );
		$name = $this->normalize_item_name( $name );

		if ( empty( $type ) || empty( $name ) || ! is_array( $this->{$type} ) ) {
			return false;
		}

		$removed = false;

		foreach ( $this->{$type} as $key => $item ) {

			$is_primary = ( 'indexes' === $type )
				&& isset( $item->type )
				&& ( 'primary' === strtolower( trim( (string) $item->type ) ) );

			$item_name = isset( $item->name )
				? $this->normalize_item_name( $item->name )
				: '';

			if ( ( $is_primary && 'primary' === $name ) || ( ! empty( $item_name ) && $name === $item_name ) ) {
				unset( $this->{$type}[ $key ] );
				$removed = true;
			}
		}

		if ( true === $removed ) {
			$this->{$type} = array_values( $this->{$type} );
		}

		return $removed;
	}

	/**
	 * Set all items in a collection.
	 *
	 * Replaces any existing collection values.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type  Item collection type. Accepts 'columns' or 'indexes'.
	 * @param array  $items Item values or objects.
	 *
	 * @return array
	 */
	public function set_items( $type = 'columns', $items = array() ) {
		return $this->setup_items( $type, $items );
	}

	/** Private Internals *****************************************************/

	/**
	 * Setup an array of items.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type   Type of items to setup.
	 * @param array  $values Array of values to convert to objects.
	 *
	 * @return array Array of items that were setup.
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

		// Bail if no values
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

		// Return the items
		return $this->{$type};
	}

	/**
	 * Get item class name from item type.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item type.
	 *
	 * @return string|false Class object, or false if type is not valid.
	 */
	private function get_item_class( $type = 'columns' ) {

		// Validate the item type and fallback to columns.
		$type = $this->validate_item_type( $type );

		// Default to columns if type is not valid.
		if ( empty( $type ) ) {
			return false;
		}

		return ( 'indexes' === $type )
			? $this->index
			: $this->column;
	}

	/**
	 * Create a schema item instance from array/object data.
	 *
	 * @since 3.0.0
	 *
	 * @param string       $class Item class name.
	 * @param array|object $data  Item data.
	 *
	 * @return object|false
	 */
	private function create_item( $class = '', $data = array() ) {

		// Bail if class cannot be instantiated.
		if ( empty( $class ) || ! class_exists( $class ) ) {
			return false;
		}

		// Bail if there is no data to turn into an object.
		if ( empty( $data ) ) {
			return false;
		}

		// Array data is passed to the item constructor.
		if ( is_array( $data ) ) {
			return new $class( $data );
		}

		// Already-instantiated object.
		if ( $data instanceof $class ) {
			return $data;
		}

		return false;
	}

	/**
	 * Return the SQL for an item type used in a "CREATE TABLE" query.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Type of item.
	 *
	 * @return string Calls get_create_string() on every item.
	 */
	private function get_items_create_string( $type = 'columns' ) {

		// Normalize and validate item type.
		$type = $this->validate_item_type( $type );

		// Bail if type is not valid.
		if ( empty( $type ) ) {
			return '';
		}

		// Bail if no items to get strings from
		if ( empty( $this->{$type} ) || ! is_array( $this->{$type} ) ) {
			return '';
		}

		// Improve readability
		$indent  = '  ';

		// Default strings
		$strings = array();

		// Loop through items...
		foreach ( $this->{$type} as $item ) {
			if ( method_exists( $item, 'get_create_string' ) ) {
				$string = $item->get_create_string();

				if ( '' !== $string ) {
					$strings[] = $indent . $string;
				}
			}
		}

		// Return the SQL
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
	 * @param array|object $data Data to pass into the column class constructor.
	 *
	 * @return object|false
	 */
	public function add_column( $data = array() ) {
		return $this->add_item( 'columns', $data );
	}

	/**
	 * Get columns in this schema.
	 *
	 * @since 3.0.0
	 *
	 * @return array
	 */
	public function get_columns() {
		return $this->get_items( 'columns' );
	}

	/**
	 * Get a column in this schema by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Column name.
	 *
	 * @return object|false
	 */
	public function get_column( $name = '' ) {
		return $this->get_item( 'columns', $name );
	}

	/**
	 * Check whether this schema has a column by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Column name.
	 *
	 * @return bool
	 */
	public function has_column( $name = '' ) {
		return $this->has_item( 'columns', $name );
	}

	/**
	 * Replace all columns in this schema.
	 *
	 * @since 3.0.0
	 *
	 * @param array $columns Column values or objects.
	 *
	 * @return array
	 */
	public function set_columns( $columns = array() ) {
		return $this->set_items( 'columns', $columns );
	}

	/**
	 * Remove a column by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Column name.
	 *
	 * @return bool
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
	 * @param array|object $data Data to pass into the index class constructor.
	 *
	 * @return object|false
	 */
	public function add_index( $data = array() ) {
		return $this->add_item( 'indexes', $data );
	}

	/**
	 * Get indexes in this schema.
	 *
	 * @since 3.0.0
	 *
	 * @return array
	 */
	public function get_indexes() {
		return $this->get_items( 'indexes' );
	}

	/**
	 * Get an index in this schema by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Index name.
	 *
	 * @return object|false
	 */
	public function get_index( $name = '' ) {
		return $this->get_item( 'indexes', $name );
	}

	/**
	 * Check whether this schema has an index by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Index name.
	 *
	 * @return bool
	 */
	public function has_index( $name = '' ) {
		return $this->has_item( 'indexes', $name );
	}

	/**
	 * Replace all indexes in this schema.
	 *
	 * @since 3.0.0
	 *
	 * @param array $indexes Index values or objects.
	 *
	 * @return array
	 */
	public function set_indexes( $indexes = array() ) {
		return $this->set_items( 'indexes', $indexes );
	}

	/**
	 * Remove an index by name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Index name.
	 *
	 * @return bool
	 */
	public function remove_index( $name = '' ) {
		return $this->remove_item( 'indexes', $name );
	}

	/**
	 * Return the SQL used for all items in a "CREATE TABLE" query.
	 *
	 * This does not include the "CREATE TABLE" directive itself, and is only
	 * used to generate the SQL inside of that kind of query.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_create_table_string() {

		// Bail if schema has validation errors.
		if ( ! $this->is_valid() ) {
			return '';
		}

		// Get strings
		$strings = array(
			$this->get_items_create_string( 'columns' ),
			$this->get_items_create_string( 'indexes' )
		);

		// Format
		$retval = implode( ",\n", array_filter( $strings ) );

		// Return
		return $retval;
	}

	/** Validators ************************************************************/

	/**
	 * Return validation errors for this schema.
	 *
	 * @since 3.0.0
	 *
	 * @return array
	 */
	public function get_validation_errors() {
		$errors = array();
		$columns = $this->get_columns();
		$indexes = $this->get_indexes();

		$column_names   = array();
		$index_names    = array();
		$primary_count  = 0;

		foreach ( $columns as $column ) {

			$column_name = isset( $column->name )
				? $this->normalize_item_name( $column->name )
				: '';

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

			$index_type = isset( $index->type )
				? strtolower( trim( (string) $index->type ) )
				: '';

			$is_primary = ( 'primary' === $index_type );

			$index_name = $is_primary
				? 'primary'
				: ( isset( $index->name ) ? $this->normalize_item_name( $index->name ) : '' );

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

			$index_columns = isset( $index->columns )
				? (array) $index->columns
				: array();

			if ( empty( $index_columns ) ) {
				$errors[] = "Index {$index_name} does not include any columns.";
				continue;
			}

			foreach ( $index_columns as $index_column ) {
				$index_column = $this->normalize_item_name( $index_column );

				if ( empty( $index_column ) || ! isset( $column_names[ $index_column ] ) ) {
					$errors[] = "Index {$index_name} references unknown column {$index_column}.";
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
	 * @return bool
	 */
	public function is_valid() {
		return empty( $this->get_validation_errors() );
	}

	/**
	 * Validate and normalize item type names.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item type to validate.
	 *
	 * @return string Normalized type or empty string.
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

	/**
	 * Normalize an item name for comparisons.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Name to normalize.
	 *
	 * @return string
	 */
	private function normalize_item_name( $name = '' ) {
		$name = strtolower( trim( (string) $name ) );

		return preg_replace( '/[^a-z0-9_]+/', '_', $name );
	}

	/** Deprecated ************************************************************/

	/**
	 * Return the columns in string form.
	 *
	 * This method was deprecated in 3.0.0 because in previous versions it only
	 * included Columns and did not include Indexes.
	 *
	 * @since 1.0.0
	 * @deprecated 3.0.0
	 *
	 * @return string
	 */
	protected function to_string() {
		return $this->get_items_create_string( 'columns' );
	}
}
