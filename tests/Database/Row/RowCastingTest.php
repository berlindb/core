<?php
/**
 * Row casting tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestRow;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for attribute casting behavior in Row objects.
 *
 * @since 3.0.0
 */
class RowCastingTest extends TestCase {

	/**
	 * Test row cast without schema casts.
	 *
	 * TestRow defines priority as 'int'. Without schema casts,
	 * the row-defined cast should apply.
	 *
	 * @since 3.0.0
	 */
	public function test_row_cast_applied_without_schema_casts() {

		$row = new TestRow( array( 'priority' => 123 ) );

		// Without schema casts, priority uses row override ('int')
		$this->assertSame( 123, $row->priority );
		$this->assertIsInt( $row->priority );
	}

	/**
	 * Test schema cast applied post-instantiation.
	 *
	 * A minimal row (no row-defined casts) loads from DB.
	 * After merge_schema_casts(), property should be cast.
	 *
	 * @since 3.0.0
	 */
	public function test_merge_schema_casts_transforms_property() {

		// Create a minimal row without any casts defined.
		$row = new \BerlinDB\Database\Row();
		$row->priority = 123;

		// Apply schema casts (simulating Query behavior).
		$row->merge_schema_casts( array( 'priority' => 'string' ) );

		// Property should now be cast as string.
		$this->assertSame( '123', $row->priority );
		$this->assertIsString( $row->priority );
	}

	/**
	 * Test row cast override takes precedence over schema.
	 *
	 * Schema says 'string', row says 'int'.
	 * Row override should win.
	 *
	 * @since 3.0.0
	 */
	public function test_row_cast_override_precedence() {

		$row = new TestRow( array( 'priority' => '123' ) );

		// merge_schema_casts() with 'string' cast.
		$row->merge_schema_casts( array( 'priority' => 'string' ) );

		// Row override ('int') should take precedence over schema ('string').
		$this->assertSame( 123, $row->priority );
		$this->assertIsInt( $row->priority );
	}

	/**
	 * Test multiple calls to merge_schema_casts updates cast map.
	 *
	 * Subsequent calls should update the cast map for fields without row overrides.
	 *
	 * @since 3.0.0
	 */
	public function test_merge_schema_casts_updates_map_on_repopulation() {

		// Minimal row with no casts defined.
		$row = new \BerlinDB\Database\Row();

		// First call: add data1 and data2 to schema casts.
		$row->merge_schema_casts( array( 'data1' => 'string', 'data2' => 'int' ) );
		$casts = $row->get_casts();
		$this->assertArrayHasKey( 'data1', $casts );
		$this->assertSame( 'string', $casts['data1'] );
		$this->assertArrayHasKey( 'data2', $casts );
		$this->assertSame( 'int', $casts['data2'] );

		// Second call: update data1 to int (should replace schema cast).
		$row->merge_schema_casts( array( 'data1' => 'int' ) );
		$casts = $row->get_casts();
		$this->assertArrayHasKey( 'data1', $casts );
		$this->assertSame( 'int', $casts['data1'] );
		$this->assertArrayHasKey( 'data2', $casts );
		$this->assertSame( 'int', $casts['data2'] );
	}

	/**
	 * Test row override remains immutable across schema calls.
	 *
	 * Row has explicit 'int' cast for priority (from get_casts()).
	 * Multiple schema calls should not change it.
	 *
	 * @since 3.0.0
	 */
	public function test_row_override_remains_immutable() {

		$row = new TestRow( array( 'priority' => '123' ) );

		// First schema call: try to cast as 'string'.
		$row->merge_schema_casts( array( 'priority' => 'string' ) );
		$this->assertSame( 123, $row->priority );

		// Second schema call: try to cast as 'float'.
		$row->merge_schema_casts( array( 'priority' => 'float' ) );

		// Row override ('int') should still be in effect.
		$this->assertSame( 123, $row->priority );
		$this->assertIsInt( $row->priority );
	}

	/**
	 * Test JSON array cast round-trip.
	 *
	 * Settings column has 'json' cast defined in schema.
	 * Row override changes to 'array'.
	 *
	 * @since 3.0.0
	 */
	public function test_json_array_cast_with_row_override() {

		$settings_data = array( 'color' => 'blue', 'size' => 'large' );
		$row = new TestRow( array(
			'settings' => wp_json_encode( $settings_data ),
		) );

		// Row override ('array') should apply during init_casts().
		$this->assertIsArray( $row->settings );
		$this->assertSame( $settings_data, $row->settings );
	}

	/**
	 * Test schema cast with missing property.
	 *
	 * Schema defines cast for field that doesn't exist.
	 * Should not cause error.
	 *
	 * @since 3.0.0
	 */
	public function test_schema_cast_with_missing_property_no_error() {

		$row = new TestRow();

		// Apply cast for non-existent field.
		$row->merge_schema_casts( array( 'nonexistent_field' => 'int' ) );

		// Should not throw error and should still be in cast map.
		$casts = $row->get_casts();
		$this->assertArrayHasKey( 'nonexistent_field', $casts );
	}

	/**
	 * Test declared casts remain distinct from the effective cast map.
	 *
	 * @since 3.0.0
	 */
	public function test_declared_casts_remain_distinct_from_effective_map() {

		$row = new TestRow( array( 'priority' => '123' ) );
		$row->merge_schema_casts( array(
			'priority' => 'string',
			'status'   => 'bool',
		) );

		$this->assertSame(
			array(
				'id'       => 'int',
				'priority' => 'int',
				'settings' => 'array',
			),
			$row->get_declared_casts()
		);

		$this->assertSame( 'int', $row->get_casts()['priority'] );
		$this->assertSame( 'bool', $row->get_casts()['status'] );
	}

	/**
	 * Test apply_attribute_casts with read context.
	 *
	 * Test the read ('get') context of apply_attribute_casts().
	 *
	 * @since 3.0.0
	 */
	public function test_apply_attribute_casts_read_context() {

		$row = new TestRow();

		$attributes = array(
			'id'       => '42',
			'priority' => '100',
			'settings' => '{"key":"value"}',
		);

		$casted = $row->apply_attribute_casts( $attributes, 'get' );

		// ID should be int (from row casts).
		$this->assertSame( 42, $casted['id'] );
		$this->assertIsInt( $casted['id'] );

		// Priority should be int (from row casts).
		$this->assertSame( 100, $casted['priority'] );
		$this->assertIsInt( $casted['priority'] );

		// Settings should be array (from row casts).
		$this->assertIsArray( $casted['settings'] );
		$this->assertSame( array( 'key' => 'value' ), $casted['settings'] );
	}

	/**
	 * Test apply_attribute_casts with write context.
	 *
	 * Test the write ('set') context of apply_attribute_casts().
	 *
	 * @since 3.0.0
	 */
	public function test_apply_attribute_casts_write_context() {

		$row = new TestRow();

		$attributes = array(
			'id'       => '42',
			'priority' => 100,
			'settings' => array( 'key' => 'value' ),
		);

		$casted = $row->apply_attribute_casts( $attributes, 'set' );

		// ID should be int in 'set' context.
		$this->assertSame( 42, $casted['id'] );
		$this->assertIsInt( $casted['id'] );

		// Priority should remain as int in 'set' context.
		$this->assertSame( 100, $casted['priority'] );
		$this->assertIsInt( $casted['priority'] );

		// Settings should be JSON string in 'set' context.
		$this->assertIsString( $casted['settings'] );
		$this->assertSame( '{"key":"value"}', $casted['settings'] );
	}

	/**
	 * Test custom UUID cast via cast_unknown filter.
	 *
	 * Uses an unknown cast name and handles it through:
     * - apply_prefix( apply_filters( 'cast_unknown' ) ).
     *
	 * Verifies default and custom-prefixed hook names.
	 *
	 * @since 3.0.0
	 */
	public function test_custom_uuid_cast_via_filter() {

		$default_row = new class() extends TestRow {
			public function get_cast_unknown_hook_name() {
				return $this->apply_prefix( 'cast_unknown' );
			}
		};

		$prefixed_row = new class() extends TestRow {
			protected $prefix = 'rowtest';

			public function get_cast_unknown_hook_name() {
				return $this->apply_prefix( 'cast_unknown' );
			}
		};

		$filter = function( $value, $name, $context, $field ) {

			if ( ( 'uuid_lower' !== $name ) || ( 'uuid' !== $field ) ) {
				return $value;
			}

			return strtolower( (string) $value );
		};

		$default_hook = $default_row->get_cast_unknown_hook_name();
		$this->assertSame( 'cast_unknown', $default_hook );

		add_filter( $default_hook, $filter, 10, 6 );

		try {

			$casted = $default_row->apply_attribute_casts(
				array( 'uuid' => 'A1B2-C3D4' ),
				'get',
				array( 'uuid' => 'uuid_lower' )
			);

			$this->assertSame( 'a1b2-c3d4', $casted['uuid'] );

			$default_row->uuid = 'F00D-BEEF';
			$default_row->merge_schema_casts( array( 'uuid' => 'uuid_lower' ) );
			$this->assertSame( 'f00d-beef', $default_row->uuid );

		} finally {
			remove_filter( $default_hook, $filter, 10 );
		}

		$prefixed_hook = $prefixed_row->get_cast_unknown_hook_name();
		$this->assertSame( 'rowtest_cast_unknown', $prefixed_hook );

		add_filter( $prefixed_hook, $filter, 10, 6 );

		try {

			$casted = $prefixed_row->apply_attribute_casts(
				array( 'uuid' => 'DEAD-BEEF' ),
				'get',
				array( 'uuid' => 'uuid_lower' )
			);

			$this->assertSame( 'dead-beef', $casted['uuid'] );

			$prefixed_row->uuid = 'CAFE-BABE';
			$prefixed_row->merge_schema_casts( array( 'uuid' => 'uuid_lower' ) );

			$this->assertSame( 'cafe-babe', $prefixed_row->uuid );

		} finally {
			remove_filter( $prefixed_hook, $filter, 10 );
		}
	}

	/**
	 * Test empty schema casts.
	 *
	 * Calling merge_schema_casts with empty array should be safe.
	 *
	 * @since 3.0.0
	 */
	public function test_empty_schema_casts() {

		$row = new TestRow( array( 'priority' => '123' ) );

		// Apply empty schema casts.
		$row->merge_schema_casts( array() );

		// Row should be unchanged.
		$this->assertSame( 123, $row->priority );
		$this->assertIsInt( $row->priority );
	}

	/**
	 * Test boolean cast.
	 *
	 * Boolean casting should handle various truthy/falsy values.
	 *
	 * @since 3.0.0
	 */
	public function test_boolean_cast() {

		$row = new TestRow();

		// Test various values cast as boolean.
		$test_cases = array(
			array( 'value' => 1,     'expected' => true ),
			array( 'value' => 0,     'expected' => false ),
			array( 'value' => '1',   'expected' => true ),
			array( 'value' => '0',   'expected' => false ),
			array( 'value' => 'true', 'expected' => true ),
			array( 'value' => 'false', 'expected' => false ),
			array( 'value' => 'on',  'expected' => true ),
			array( 'value' => 'off', 'expected' => false ),
			array( 'value' => 'yes', 'expected' => true ),
			array( 'value' => 'no',  'expected' => false ),
			array( 'value' => true,  'expected' => true ),
			array( 'value' => false, 'expected' => false ),
		);

		foreach ( $test_cases as $case ) {
			$casted = $row->apply_attribute_casts(
				array( 'is_enabled' => $case['value'] ),
				'get',
				array( 'is_enabled' => 'bool' )
			);

			$this->assertSame( $case['expected'], $casted['is_enabled'] );
			$this->assertIsBool( $casted['is_enabled'] );
		}
	}

	/**
	 * Test null value handling.
	 *
	 * Null values should be handled gracefully across cast types.
	 *
	 * @since 3.0.0
	 */
	public function test_null_value_handling() {

		$row = new TestRow();

		$attributes = array(
			'priority' => null,
			'settings' => null,
		);

		$casted = $row->apply_attribute_casts( $attributes, 'get' );

		// Null casted as int should be 0.
		$this->assertSame( 0, $casted['priority'] );

		// Null casted as array should be empty array.
		$this->assertSame( array(), $casted['settings'] );
	}

	/**
	 * Test get_casts returns sanitized map.
	 *
	 * get_casts() should filter and sanitize the cast definitions.
	 *
	 * @since 3.0.0
	 */
	public function test_get_casts_returns_sanitized_map() {

		$row = new TestRow();
		$casts = $row->get_casts();

		// Should be an array with string keys and scalar/callable values.
		$this->assertIsArray( $casts );

		foreach ( $casts as $field => $cast ) {
			$this->assertIsString( $field );
			$this->assertTrue( is_scalar( $cast ) || is_callable( $cast ) );
		}
	}

	/**
	 * Test effective cast storage is sanitized during initialization.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_storage_is_sanitized_during_initialization() {

		$row = new class() extends \BerlinDB\Database\Row {
			protected $declared_casts = array(
				'priority' => ' int ',
				123        => 'string',
				'invalid'  => '',
			);

			public $priority = 0;
		};

		$this->assertSame( array( 'priority' => 'int' ), $row->get_declared_casts() );
		$this->assertSame( array( 'priority' => 'int' ), $row->get_casts() );
		$this->assertSame( array( 'priority' => 'int' ), $row->casts );
	}
}
