<?php
/**
 * Row casting Trait.
 *
 * @package     Database
 * @subpackage  Casts
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Provides lightweight attribute casting for Row objects.
 *
 * @since 3.0.0
 */
trait Casts {

	/**
	 * Effective cast map for this row instance.
	 *
	 * This is the runtime cast map after sanitization and any schema-level
	 * merges have been applied.
	 *
	 * @since 3.0.0
	 * @var   array
	 */
	protected $casts = array();

	/**
	 * Row-defined cast definitions from the class definition.
	 *
	 * Row subclasses should override this property to declare their cast map.
	 *
	 * @since 3.0.0
	 * @var   array
	 */
	protected $declared_casts = array();

	/**
	 * Initialize casting and apply inbound casts to row properties.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	protected function init_casts() {
		$declared_casts = $this->sanitize_cast_map( $this->declared_casts );

		// Store row-defined casts (immutable from class definition).
		$this->declared_casts = $declared_casts;

		// Cache the effective cast map on the row instance.
		$this->set_casts( $declared_casts );

		// Bail if no casts.
		if ( empty( $declared_casts ) ) {
			return;
		}

		$this->apply_casts_to_properties( $declared_casts );
	}

	/**
	 * Return the effective cast map for this row.
	 *
	 * @since 3.0.0
	 * @return array
	 */
	public function get_casts() {
		return $this->casts;
	}

	/**
	 * Return the row-defined cast map from the class definition.
	 *
	 * @since 3.0.0
	 * @return array
	 */
	public function get_declared_casts() {
		return $this->declared_casts;
	}

	/**
	 * Apply casts to an attribute array for read/write contexts.
	 *
	 * Used during read (hydration) and write (persistence) operations.
	 * Applies casts from both row-defined and schema-provided sources.
	 * When a field exists in both maps, the row-defined cast takes precedence.
	 *
	 * @since 3.0.0
	 * @param array  $attributes   Attributes to cast.
	 * @param string $context      Context: 'get' (hydrate) or 'set' (persist).
	 * @param array  $schema_casts Optional schema-provided defaults (mainly for write context).
	 * @return array
	 */
	public function apply_attribute_casts( $attributes = array(), $context = 'get', $schema_casts = array() ) {

		// Bail if malformed.
		if ( empty( $attributes ) || ! is_array( $attributes ) ) {
			return $attributes;
		}

		$context = ( 'set' === strtolower( (string) $context ) )
			? 'set'
			: 'get';

		$casts = array_merge(
			$this->sanitize_cast_map( $schema_casts ),
			$this->get_casts()
		);

		// Bail if no casts.
		if ( empty( $casts ) ) {
			return $attributes;
		}

		// Apply known casts to matching keys.
		foreach ( $attributes as $key => $value ) {
			if ( isset( $casts[ $key ] ) ) {
				$attributes[ $key ] = $this->cast_attribute_value( $value, $casts[ $key ], $context, $key );
			}
		}

		return $attributes;
	}

	/**
	 * Merge schema-defined casts into the row's effective cast map.
	 *
	 * Called explicitly by Query after row instantiation to apply schema defaults.
	 * Row-level casts take permanent precedence over schema casts.
	 * Can be called multiple times; only fields without
	 * row-level cast overrides will be modified on subsequent calls.
	 *
	 * @since 3.0.0
	 * @param array $schema_casts Schema-provided cast definitions.
	 * @return void
	 */
	public function merge_schema_casts( $schema_casts = array() ) {

		$schema_casts = $this->sanitize_cast_map( $schema_casts );
		$declared_casts = $this->get_declared_casts();

		// Bail if no schema casts.
		if ( empty( $schema_casts ) ) {
			return;
		}

		$existing_casts = $this->get_casts();

		// Merge existing casts, new schema casts, then row-defined overrides.
		$this->set_casts( array_merge( $existing_casts, $schema_casts, $declared_casts ) );

		$this->apply_casts_to_properties( $schema_casts, 'get', array_keys( $declared_casts ) );
	}

	/**
	 * Apply a cast map to matching row properties.
	 *
	 * @since 3.0.0
	 * @param array  $casts       Cast definitions to apply.
	 * @param string $context     Context: 'get' or 'set'.
	 * @param array  $skip_fields Fields that should not be cast.
	 * @return void
	 */
	private function apply_casts_to_properties( $casts = array(), $context = 'get', $skip_fields = array() ) {

		$casts       = $this->sanitize_cast_map( $casts );
		$skip_fields = array_filter( (array) $skip_fields, 'is_string' );

		// Bail if no casts.
		if ( empty( $casts ) ) {
			return;
		}

		foreach ( $casts as $field => $cast ) {

			// Skip fields with row-level overrides.
			if ( in_array( $field, $skip_fields, true ) ) {
				continue;
			}

			if ( property_exists( $this, $field ) || isset( $this->{$field} ) ) {
				$this->{$field} = $this->cast_attribute_value( $this->{$field}, $cast, $context, $field );
			}
		}
	}

	/**
	 * Sanitize a cast map to string keys and scalar/callable values.
	 *
	 * @since 3.0.0
	 * @param array $casts
	 * @return array
	 */
	private function sanitize_cast_map( $casts = array() ) {

		// Bail if malformed.
		if ( empty( $casts ) || ! is_array( $casts ) ) {
			return array();
		}

		$retval = array();

		foreach ( $casts as $key => $cast ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( is_callable( $cast ) ) {
				$retval[ $key ] = $cast;
				continue;
			}

			if ( is_scalar( $cast ) ) {
				$cast = trim( (string) $cast );
				if ( '' !== $cast ) {
					$retval[ $key ] = $cast;
				}
			}
		}

		return $retval;
	}

	/**
	 * Store a sanitized cast map on the row instance.
	 *
	 * @since 3.0.0
	 * @param array $casts Cast definitions to store.
	 * @return array
	 */
	private function set_casts( $casts = array() ) {
		$this->casts = $this->sanitize_cast_map( $casts );

		return $this->casts;
	}

	/**
	 * Cast a single attribute value.
	 *
	 * @since 3.0.0
	 * @param mixed  $value
	 * @param mixed  $cast
	 * @param string $context
	 * @param string $field
	 * @return mixed
	 */
	private function cast_attribute_value( $value = null, $cast = '', $context = 'get', $field = '' ) {

		// Allow callables as custom casts.
		if ( is_callable( $cast ) ) {
			return call_user_func( $cast, $value, $context, $field, $this );
		}

		$parsed = $this->parse_cast( $cast );
		$name   = $parsed['name'];

		switch ( $name ) {
			case 'int':
			case 'integer':
				return (int) $value;

			case 'float':
			case 'double':
			case 'real':
				return (float) $value;

			case 'bool':
			case 'boolean':
				return $this->normalize_boolean( $value );

			case 'string':
				if ( is_scalar( $value ) || is_null( $value ) ) {
					return (string) $value;
				}

				$json = wp_json_encode( $value );

				return ( false !== $json )
					? $json
					: '';

			case 'array':
				return ( 'set' === $context )
					? $this->encode_json( $value )
					: $this->decode_json_array( $value );

			case 'object':
				return ( 'set' === $context )
					? $this->encode_json( $value )
					: $this->decode_json_object( $value );

			case 'json':
				return ( 'set' === $context )
					? $this->encode_json( $value )
					: $this->decode_json_array( $value );

			default:
				return $this->filter_unknown_cast( $value, $name, $context, $field, $parsed['args'] );
		}
	}

	/**
	 * Normalize a value to a boolean.
	 *
	 * Accepts common textual boolean values like yes/no and on/off before
	 * falling back to WordPress's boolean validation behavior.
	 *
	 * @since 3.0.0
	 * @param mixed $value
	 * @return bool
	 */
	private function normalize_boolean( $value = null ) {

		if ( is_bool( $value ) ) {
			return $value;
		}

		$validated = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		return ( null !== $validated )
			? $validated
			: wp_validate_boolean( $value );
	}

	/**
	 * Parse a cast definition like "int" or "datetime:Y-m-d".
	 *
	 * @since 3.0.0
	 * @param mixed $cast
	 * @return array
	 */
	private function parse_cast( $cast = '' ) {

		// Default return value.
		$retval = array(
			'name' => '',
			'args' => array(),
		);

		// Bail if malformed.
		if ( ! is_scalar( $cast ) ) {
			return $retval;
		}

		$cast  = trim( (string) $cast );
		$parts = explode( ':', $cast, 2 );
		$name  = strtolower( trim( $parts[0] ) );

		if ( '' === $name ) {
			return $retval;
		}

		$args = array();
		if ( isset( $parts[1] ) && '' !== trim( $parts[1] ) ) {
			$args = array_map( 'trim', explode( ',', $parts[1] ) );
		}

		$retval['name'] = $name;
		$retval['args'] = $args;

		return $retval;
	}

	/**
	 * Encode a value to JSON if needed.
	 *
	 * @since 3.0.0
	 * @param mixed $value
	 * @return string
	 */
	private function encode_json( $value = null ) {

		if ( is_string( $value ) ) {
			return $value;
		}

		$json = wp_json_encode( $value );

		return ( false !== $json )
			? $json
			: '';
	}

	/**
	 * Decode JSON to an array.
	 *
	 * @since 3.0.0
	 * @param mixed $value
	 * @return array
	 */
	private function decode_json_array( $value = null ) {

		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return (array) $value;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		return ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) )
			? $decoded
			: array();
	}

	/**
	 * Decode JSON to an object.
	 *
	 * @since 3.0.0
	 * @param mixed $value
	 * @return object
	 */
	private function decode_json_object( $value = null ) {

		if ( is_object( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			return (object) $value;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return (object) array();
		}

		$decoded = json_decode( $value );

		return ( JSON_ERROR_NONE === json_last_error() && is_object( $decoded ) )
			? $decoded
			: (object) array();
	}

	/**
	 * Filter the value for an unknown cast type.
	 *
	 * Hook name is derived via apply_prefix( 'cast_unknown' ).
	 * If the row prefix is empty, the hook name is simply 'cast_unknown'.
	 *
	 * @since 3.0.0
	 * @param mixed  $value   The value to cast.
	 * @param string $name    The attribute name.
	 * @param string $context The cast context ('get' or 'set').
	 * @param string $field   The column field name.
	 * @param array  $args    Additional arguments from cast definition.
	 * @return mixed
	 */
	private function filter_unknown_cast( $value = null, $name = '', $context = 'get', $field = '', $args = array() ) {

		/**
		 * Filters the value for an unknown cast type.
		 *
		 * @since 3.0.0
		 * @param mixed                  $value   The value to cast.
		 * @param string                 $name    The attribute name.
		 * @param string                 $context The cast context ('get' or 'set').
		 * @param string                 $field   The column field name.
		 * @param array                  $args    Additional arguments from cast definition.
		 * @param \BerlinDB\Database\Row $this    The row instance.
		 */
		return apply_filters(
			$this->apply_prefix( 'cast_unknown' ),
			$value,
			$name,
			$context,
			$field,
			$args,
			$this
		);
	}
}
