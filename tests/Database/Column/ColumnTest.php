<?php
/**
 * Column class tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Column;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for BerlinDB\Database\Column.
 *
 * Column is a pure data container; these tests do not require a database
 * connection, only a WordPress bootstrap for functions like wp_parse_args
 * and remove_accents.
 *
 * @since 2.1.0
 */
class ColumnTest extends TestCase {

	// Default property values.

	public function test_default_name_is_empty_string() {
		$column = new Column();
		$this->assertSame( '', $column->name );
	}

	public function test_default_type_is_empty_string() {
		$column = new Column();
		$this->assertSame( '', $column->type );
	}

	public function test_default_unsigned_is_true() {
		$column = new Column();
		$this->assertTrue( $column->unsigned );
	}

	public function test_default_allow_null_is_false() {
		$column = new Column();
		$this->assertFalse( $column->allow_null );
	}

	public function test_default_primary_is_false() {
		$column = new Column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$this->assertFalse( $column->primary );
	}

	// Type detection.

	public function test_is_numeric_returns_true_for_bigint() {
		$column = new Column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$this->assertTrue( $column->is_numeric() );
	}

	public function test_is_int_returns_true_for_bigint() {
		$column = new Column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$this->assertTrue( $column->is_int() );
	}

	public function test_is_text_returns_false_for_bigint() {
		$column = new Column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$this->assertFalse( $column->is_text() );
	}

	public function test_is_text_returns_true_for_varchar() {
		$column = new Column( array( 'name' => 'title', 'type' => 'varchar', 'length' => '255' ) );
		$this->assertTrue( $column->is_text() );
	}

	public function test_is_numeric_returns_false_for_varchar() {
		$column = new Column( array( 'name' => 'title', 'type' => 'varchar', 'length' => '255' ) );
		$this->assertFalse( $column->is_numeric() );
	}

	public function test_is_date_time_returns_true_for_datetime() {
		$column = new Column( array( 'name' => 'created', 'type' => 'datetime' ) );
		$this->assertTrue( $column->is_date_time() );
	}

	public function test_is_date_time_returns_false_for_varchar() {
		$column = new Column( array( 'name' => 'title', 'type' => 'varchar', 'length' => '255' ) );
		$this->assertFalse( $column->is_date_time() );
	}

	// special_args(): primary → cache_key.

	public function test_primary_true_forces_cache_key_true() {
		$column = new Column( array( 'name' => 'id', 'type' => 'bigint', 'primary' => true ) );
		$this->assertTrue( $column->primary );
		$this->assertTrue( $column->cache_key );
	}

	// special_args(): uuid.

	public function test_uuid_true_forces_name_to_uuid() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertSame( 'uuid', $column->name );
	}

	public function test_uuid_true_forces_type_to_varchar() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertSame( 'VARCHAR', $column->type );
	}

	public function test_uuid_true_forces_length_to_100() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertSame( 100, $column->length );
	}

	public function test_uuid_true_disables_in() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertFalse( $column->in );
	}

	public function test_uuid_true_disables_not_in() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertFalse( $column->not_in );
	}

	public function test_uuid_true_disables_searchable() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertFalse( $column->searchable );
	}

	public function test_uuid_true_disables_sortable() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertFalse( $column->sortable );
	}

	// special_args(): SERIAL extra.

	public function test_serial_extra_forces_bigint_type() {
		$column = new Column( array( 'extra' => 'SERIAL' ) );
		$this->assertSame( 'BIGINT', $column->type );
	}

	public function test_serial_extra_forces_primary_true() {
		$column = new Column( array( 'extra' => 'SERIAL' ) );
		$this->assertTrue( $column->primary );
	}

	public function test_serial_extra_forces_auto_increment() {
		$column = new Column( array( 'extra' => 'SERIAL' ) );
		$this->assertSame( 'AUTO_INCREMENT', $column->extra );
	}

	public function test_serial_extra_forces_unsigned_true() {
		$column = new Column( array( 'extra' => 'SERIAL' ) );
		$this->assertTrue( $column->unsigned );
	}

	// get_create_string().

	public function test_get_create_string_for_primary_column_contains_name() {
		$column = new Column( array(
			'name'    => 'id',
			'type'    => 'bigint',
			'length'  => '20',
			'primary' => true,
			'extra'   => 'auto_increment',
		) );
		$sql = $column->get_create_string();
		$this->assertStringContainsString( '`id`', $sql );
	}

	public function test_get_create_string_for_primary_column_contains_type() {
		$column = new Column( array(
			'name'    => 'id',
			'type'    => 'bigint',
			'length'  => '20',
			'primary' => true,
			'extra'   => 'auto_increment',
		) );
		$sql = $column->get_create_string();
		$this->assertStringContainsString( 'bigint(20)', $sql );
	}

	public function test_get_create_string_for_primary_column_contains_unsigned() {
		$column = new Column( array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'primary'  => true,
			'extra'    => 'auto_increment',
		) );
		$sql = $column->get_create_string();
		$this->assertStringContainsString( 'unsigned', $sql );
	}

	public function test_get_create_string_for_primary_column_contains_auto_increment() {
		$column = new Column( array(
			'name'   => 'id',
			'type'   => 'bigint',
			'length' => '20',
			'extra'  => 'auto_increment',
		) );
		$sql = $column->get_create_string();
		$this->assertStringContainsString( 'AUTO_INCREMENT', $sql );
	}

	public function test_get_create_string_for_varchar_column_contains_length() {
		$column = new Column( array(
			'name'    => 'title',
			'type'    => 'varchar',
			'length'  => '200',
			'default' => '',
		) );
		$sql = $column->get_create_string();
		$this->assertStringContainsString( 'varchar(200)', $sql );
	}

	public function test_get_create_string_for_varchar_column_contains_not_null() {
		$column = new Column( array(
			'name'       => 'title',
			'type'       => 'varchar',
			'length'     => '200',
			'allow_null' => false,
		) );
		$sql = $column->get_create_string();
		$this->assertStringContainsString( 'not null', $sql );
	}

	public function test_get_create_string_for_datetime_column_contains_type() {
		$column = new Column( array(
			'name' => 'created_at',
			'type' => 'datetime',
		) );
		$sql = $column->get_create_string();
		$this->assertStringContainsString( 'datetime', $sql );
	}

	// Validation helpers.

	public function test_validate_uuid_generates_urn_prefix_for_empty_value() {
		$column = new Column( array( 'uuid' => true ) );
		$result = $column->validate_uuid( '' );
		$this->assertStringStartsWith( 'urn:uuid:', $result );
	}

	public function test_validate_uuid_preserves_existing_urn_uuid() {
		$column   = new Column( array( 'uuid' => true ) );
		$existing = 'urn:uuid:550e8400-e29b-41d4-a716-446655440000';
		$result   = $column->validate_uuid( $existing );
		$this->assertSame( $existing, $result );
	}

	public function test_validate_int_coerces_string_to_int() {
		$column = new Column( array( 'name' => 'count', 'type' => 'bigint' ) );
		$result = $column->validate_int( '42' );
		$this->assertSame( 42, $result );
	}

	public function test_validate_datetime_returns_valid_datetime_string() {
		$column = new Column( array( 'name' => 'created', 'type' => 'datetime' ) );
		$result = $column->validate_datetime( '2024-01-15 10:30:00' );
		$this->assertSame( '2024-01-15 10:30:00', $result );
	}

	public function test_validate_datetime_returns_empty_string_for_empty_value() {
		/*
		 * validate_datetime() returns $this->default for empty values, so the
		 * column must have the zero-date default for this assertion to hold.
		 */
		$column = new Column( array( 'name' => 'created', 'type' => 'datetime' ) );
		$result = $column->validate_datetime( '' );
		$this->assertEmpty( $result );
	}

	// Base::__get() magic getter.

	public function test_magic_getter_accesses_protected_sortable_property() {
		$column = new Column( array( 'name' => 'title', 'type' => 'varchar', 'sortable' => true ) );
		$this->assertTrue( $column->sortable );
	}

	public function test_magic_getter_returns_null_for_nonexistent_property() {
		$column = new Column();
		$this->assertNull( $column->nonexistent_property_xyz );
	}

	// Capabilities.

	public function test_caps_defaults_contain_all_four_operations() {
		$column = new Column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$this->assertArrayHasKey( 'select', $column->caps );
		$this->assertArrayHasKey( 'insert', $column->caps );
		$this->assertArrayHasKey( 'update', $column->caps );
		$this->assertArrayHasKey( 'delete', $column->caps );
	}

	public function test_caps_default_to_exist_capability() {
		$column = new Column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$this->assertSame( 'exist', $column->caps['insert'] );
	}

	// to_array().

	public function test_to_array_includes_name_key() {
		$column = new Column( array( 'name' => 'status', 'type' => 'varchar' ) );
		$arr    = $column->to_array();
		$this->assertArrayHasKey( 'name', $arr );
		$this->assertSame( 'status', $arr['name'] );
	}

	public function test_to_array_includes_type_key() {
		$column = new Column( array( 'name' => 'status', 'type' => 'VARCHAR' ) );
		$arr    = $column->to_array();
		$this->assertArrayHasKey( 'type', $arr );
	}

	public function test_to_array_includes_primary_key() {
		$column = new Column( array( 'name' => 'id', 'type' => 'bigint', 'primary' => true ) );
		$arr    = $column->to_array();
		$this->assertArrayHasKey( 'primary', $arr );
		$this->assertTrue( $arr['primary'] );
	}
}
