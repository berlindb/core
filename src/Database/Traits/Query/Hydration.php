<?php
/**
 * Query Hydration Trait Class.
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

/**
 * Hydration: turning raw database rows into shaped item objects for a Query.
 *
 * Composed onto Query, sharing its $this run-state. Maps result rows through
 * the item_shape (a Row subclass), normalizes item IDs, validates field values
 * against their column types, and projects requested fields. The found-rows
 * count and CRUD live elsewhere; this trait is purely row -> object.
 *
 * @since 3.1.0
 */
trait Hydration {

	/**
	 * Hydrate items by mapping their IDs through the single-item shaper.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Moved 'count' logic back into get_items().
	 * @since 3.1.0 Renamed from set_items() to name the hydration boundary.
	 * @param list<int|string> $item_ids List of item IDs.
	 */
	private function hydrate_items( $item_ids = array() ): void {

		// Validate primary column values.
		$callback = array( $this, 'shape_item_id' );
		$item_ids = array_map( $callback, $item_ids );

		// Prime item caches.
		$this->prime_item_caches( $item_ids );

		// Shape the items.
		$this->items = $this->shape_items( $item_ids );

		// Prime caches for declared relationships (quiet unless requested).
		$this->prime_relationship_caches();
	}

	/**
	 * Shape an item from the database into the type of object it always wanted
	 * to be when it grew up.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $item ID of item, or row from database.
	 * @return object Shaped item object.
	 */
	private function shape_item( $item = 0 ): object {

		/*
		 * Fetch the row when given an ID (int or string/UUID key); rows already
		 * arrive from the database as an object or array.
		 */
		if ( ! is_object( $item ) && ! is_array( $item ) ) {
			$item = $this->get_item( $item );
		}

		/*
		 * Decode JSON columns before any early-return or wrapping.
		 *
		 * The database returns raw rows as stdClass objects (via get_row()), so
		 * we must handle both array and object forms here. cast_json() is
		 * idempotent - calling it on an already-decoded array is a no-op.
		 */
		$json_columns = $this->get_columns( array( 'type' => 'json' ) );

		if ( ! empty( $json_columns ) ) {
			if ( is_array( $item ) ) {
				foreach ( $json_columns as $column ) {
					if ( isset( $item[ $column->name ] ) ) {
						$item[ $column->name ] = $column->cast( $item[ $column->name ] );
					}
				}
			} elseif ( is_object( $item ) ) {
				foreach ( $json_columns as $column ) {
					if ( isset( $item->{$column->name} ) ) {
						$item->{$column->name} = $column->cast( $item->{$column->name} );
					}
				}
			}
		}

		// Return the item if it's already shaped.
		$item_shape = $this->get_current_string( 'item_shape' );
		if ( ! empty( $item_shape ) && $item instanceof $item_shape ) {
			return $item;
		}

		// stdClass does not hydrate constructor arguments into properties.
		if ( 'stdClass' === $item_shape ) {
			return (object) $item;
		}

		// Shape the item as needed.
		$shaped = ! empty( $item_shape )
			? $this->instantiate_class( $item_shape, '', $item )
			: null;
		$item   = ( null !== $shaped )
			? $shaped
			: (object) $item;

		// Return the item object.
		return $item;
	}

	/**
	 * Shape items into their most relevant objects.
	 *
	 * This will try to use item_shape, but will fallback to a private
	 * method for querying and caching items.
	 *
	 * If using the "fields" query_var, results will be an array of stdClass
	 * objects with keys based on fields.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Added $fields parameter.
	 *
	 * @param list<int|string> $items  Array of item IDs to shape.
	 * @param list<string>     $fields Fields to get from items.
	 * @return array<int|string,mixed>
	 */
	private function shape_items( $items = array(), $fields = array() ): array {

		// Maybe fallback to $query_vars.
		if ( empty( $fields ) ) {
			$fields = $this->get_query_var( 'fields' );
		}

		// Force to stdClass if querying for fields.
		$item_shape = ! empty( $fields ) ? 'stdClass' : $this->item_shape;
		$this->set_current( 'item_shape', $item_shape );

		// Default return value.
		$retval = array();

		// Loop through items and get each item individually.
		if ( ! empty( $items ) ) {
			foreach ( $items as $item ) {
				$shaped = $this->get_item( $item );
				if ( false !== $shaped ) {
					$retval[] = $shaped;
				}
			}
		}

		// Filter the items.
		$retval = $this->filter_items( $retval );

		// Maybe return specific fields.
		if ( ! empty( $fields ) ) {
			if ( is_array( $fields ) ) {
				$fields_list = array_values( array_filter( $fields, 'is_string' ) );
			} elseif ( is_string( $fields ) ) {
				$fields_list = array( $fields );
			} else {
				$fields_list = array();
			}
			$retval = $this->get_item_fields( $retval, $fields_list );
		}

		// Return shaped items.
		return $retval;
	}

	/**
	 * Validate the primary column value of an item.
	 *
	 * Accepts an object, array, or numeric value.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses validate_item_field()
	 *
	 * @param  array<string,mixed>|object|scalar $item The item object or array.
	 * @return int|string
	 */
	private function shape_item_id( $item = 0 ): int|string {

		// Default return value.
		$retval = $item;

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// Object item.
		if ( is_object( $item ) && isset( $item->{$primary} ) ) {
			$retval = $item->{$primary};

			// Array item.
		} elseif ( is_array( $item ) && isset( $item[ $primary ] ) ) {
			$retval = $item[ $primary ];
		}

		/*
		 * Return the validated item ID: an int/string passes through, another scalar
		 * is stringified, and anything else falls back to 0.
		 */
		$validated = $this->validate_item_field( $retval, $primary );

		if ( is_int( $validated ) || is_string( $validated ) ) {
			return $validated;
		}

		if ( is_scalar( $validated ) ) {
			return (string) $validated;
		}

		return 0;
	}

	/**
	 * Validate a single field of an item.
	 *
	 * Calls Column::validate() on the column.
	 *
	 * @since 3.0.0
	 * @param mixed  $value       Value to validate.
	 * @param string $column_name Name of column.
	 * @return mixed A validated value
	 */
	private function validate_item_field( $value = '', $column_name = '' ) {

		// Get the column.
		$column = $this->get_column_by( array( 'name' => $column_name ) );

		// Bail if no column found.
		if ( empty( $column ) ) {
			return false;
		}

		// Validate.
		return $column->validate( $value );
	}

	/**
	 * Get specific fields from an array of items.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Bails early if empty $fields.
	 *
	 * @param list<object> $items  Array of items to get fields from.
	 * @param list<string> $fields Fields to get from items.
	 * @return list<object>|array<string|int,object>
	 */
	private function get_item_fields( $items = array(), $fields = array() ): array {

		// Maybe fallback to $query_vars.
		if ( empty( $fields ) ) {
			$fields = $this->get_query_var( 'fields' );
		}

		// Bail if no fields to get.
		if ( empty( $fields ) ) {
			return $items;
		}

		// Maybe cast to array.
		if ( ! is_array( $fields ) ) {
			$fields = (array) $fields;
		}

		// Default return value.
		$retval = $items;

		// Get the primary column name.
		$primary = $this->get_primary_column_name();

		// 'ids' is numerically keyed.
		if ( ( 1 === count( $fields ) ) && ( 'ids' === $fields[0] ) ) {
			$retval = wp_list_pluck( $items, $primary );

			// Get fields from items.
		} else {
			$retval         = array();
			$fields_to_flip = array_values(
				array_filter(
					$fields,
					function ( $v ) {
						return is_int( $v ) || is_string( $v );
					}
				)
			);
			/** @var array<int|string> $fields_to_flip */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$fields = array_flip( $fields_to_flip );

			// Loop through items and pluck out the fields.
			foreach ( $items as $item ) {
				$retval[ $item->{$primary} ] = (object) array_intersect_key( (array) $item, $fields );
			}
		}

		// Return the item fields.
		return $retval;
	}
}
