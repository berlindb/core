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
use PHPUnit\Framework\TestCase;

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

	/**
	 * Test that the default name property is an empty string.
	 *
	 * @since 2.1.0
	 */
	public function test_default_name_is_empty_string() {
		$column = new Column();
		$this->assertSame( '', $column->name );
	}

	/**
	 * Test that the default type property is an empty string.
	 *
	 * @since 2.1.0
	 */
	public function test_default_type_is_empty_string() {
		$column = new Column();
		$this->assertSame( '', $column->type );
	}

	/**
	 * Test that the default unsigned property is true.
	 *
	 * @since 2.1.0
	 */
	public function test_default_unsigned_is_true() {
		$column = new Column();
		$this->assertTrue( $column->unsigned );
	}

	/**
	 * Test that the default allow_null property is false.
	 *
	 * @since 2.1.0
	 */
	public function test_default_allow_null_is_false() {
		$column = new Column();
		$this->assertFalse( $column->allow_null );
	}

	/**
	 * Test that the default primary property is false when not explicitly set.
	 *
	 * @since 2.1.0
	 */
	public function test_default_primary_is_false() {
		$column = new Column(
			array(
				'name' => 'id',
				'type' => 'bigint',
			)
		);
		$this->assertFalse( $column->primary );
	}

	// Type detection.

	/**
	 * Test that is_numeric returns true for a bigint column.
	 *
	 * @since 2.1.0
	 */
	public function test_is_numeric_returns_true_for_bigint() {
		$column = new Column(
			array(
				'name' => 'id',
				'type' => 'bigint',
			)
		);
		$this->assertTrue( $column->is_numeric() );
	}

	/**
	 * Test that is_int returns true for a bigint column.
	 *
	 * @since 2.1.0
	 */
	public function test_is_int_returns_true_for_bigint() {
		$column = new Column(
			array(
				'name' => 'id',
				'type' => 'bigint',
			)
		);
		$this->assertTrue( $column->is_int() );
	}

	/**
	 * Test that is_text returns false for a bigint column.
	 *
	 * @since 2.1.0
	 */
	public function test_is_text_returns_false_for_bigint() {
		$column = new Column(
			array(
				'name' => 'id',
				'type' => 'bigint',
			)
		);
		$this->assertFalse( $column->is_text() );
	}

	/**
	 * Test that is_text returns true for a varchar column.
	 *
	 * @since 2.1.0
	 */
	public function test_is_text_returns_true_for_varchar() {
		$column = new Column(
			array(
				'name'   => 'title',
				'type'   => 'varchar',
				'length' => '255',
			)
		);
		$this->assertTrue( $column->is_text() );
	}

	/**
	 * Test that is_numeric returns false for a varchar column.
	 *
	 * @since 2.1.0
	 */
	public function test_is_numeric_returns_false_for_varchar() {
		$column = new Column(
			array(
				'name'   => 'title',
				'type'   => 'varchar',
				'length' => '255',
			)
		);
		$this->assertFalse( $column->is_numeric() );
	}

	/**
	 * Test that is_date_time returns true for a datetime column.
	 *
	 * @since 2.1.0
	 */
	public function test_is_date_time_returns_true_for_datetime() {
		$column = new Column(
			array(
				'name' => 'created',
				'type' => 'datetime',
			)
		);
		$this->assertTrue( $column->is_date_time() );
	}

	/**
	 * Test that is_date_time returns false for a varchar column.
	 *
	 * @since 2.1.0
	 */
	public function test_is_date_time_returns_false_for_varchar() {
		$column = new Column(
			array(
				'name'   => 'title',
				'type'   => 'varchar',
				'length' => '255',
			)
		);
		$this->assertFalse( $column->is_date_time() );
	}

	// special_args(): primary -> cache_key.

	/**
	 * Test that setting primary to true also forces cache_key to true.
	 *
	 * @since 2.1.0
	 */
	public function test_primary_true_forces_cache_key_true() {
		$column = new Column(
			array(
				'name'    => 'id',
				'type'    => 'bigint',
				'primary' => true,
			)
		);
		$this->assertTrue( $column->primary );
		$this->assertTrue( $column->cache_key );
	}

	// special_args(): uuid.

	/**
	 * Test that setting uuid to true forces the column name to "uuid".
	 *
	 * @since 2.1.0
	 */
	public function test_uuid_true_forces_name_to_uuid() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertSame( 'uuid', $column->name );
	}

	/**
	 * Test that setting uuid to true forces the column type to VARCHAR.
	 *
	 * @since 2.1.0
	 */
	public function test_uuid_true_forces_type_to_varchar() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertSame( 'VARCHAR', $column->type );
	}

	/**
	 * Test that setting uuid to true forces the column length to 100.
	 *
	 * @since 2.1.0
	 */
	public function test_uuid_true_forces_length_to_100() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertSame( 100, $column->length );
	}

	/**
	 * Test that setting uuid to true disables the in filter.
	 *
	 * @since 2.1.0
	 */
	public function test_uuid_true_disables_in() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertFalse( $column->in );
	}

	/**
	 * Test that setting uuid to true disables the not_in filter.
	 *
	 * @since 2.1.0
	 */
	public function test_uuid_true_disables_not_in() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertFalse( $column->not_in );
	}

	/**
	 * Test that setting uuid to true disables the searchable flag.
	 *
	 * @since 2.1.0
	 */
	public function test_uuid_true_disables_searchable() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertFalse( $column->searchable );
	}

	/**
	 * Test that setting uuid to true disables the sortable flag.
	 *
	 * @since 2.1.0
	 */
	public function test_uuid_true_disables_sortable() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertFalse( $column->sortable );
	}

	// special_args(): SERIAL extra.

	/**
	 * Test that a SERIAL extra value forces the column type to BIGINT.
	 *
	 * @since 2.1.0
	 */
	public function test_serial_extra_forces_bigint_type() {
		$column = new Column( array( 'extra' => 'SERIAL' ) );
		$this->assertSame( 'BIGINT', $column->type );
	}

	/**
	 * Test that a SERIAL extra value forces the primary flag to true.
	 *
	 * @since 2.1.0
	 */
	public function test_serial_extra_forces_primary_true() {
		$column = new Column( array( 'extra' => 'SERIAL' ) );
		$this->assertTrue( $column->primary );
	}

	/**
	 * Test that a SERIAL extra value forces the extra field to AUTO_INCREMENT.
	 *
	 * @since 2.1.0
	 */
	public function test_serial_extra_forces_auto_increment() {
		$column = new Column( array( 'extra' => 'SERIAL' ) );
		$this->assertSame( 'AUTO_INCREMENT', $column->extra );
	}

	/**
	 * Test that a SERIAL extra value forces the unsigned flag to true.
	 *
	 * @since 2.1.0
	 */
	public function test_serial_extra_forces_unsigned_true() {
		$column = new Column( array( 'extra' => 'SERIAL' ) );
		$this->assertTrue( $column->unsigned );
	}

	// get_create_string().

	/**
	 * Test that the create string for a primary column contains the column name.
	 *
	 * @since 2.1.0
	 */
	public function test_get_create_string_for_primary_column_contains_name() {
		$column = new Column(
			array(
				'name'    => 'id',
				'type'    => 'bigint',
				'length'  => '20',
				'primary' => true,
				'extra'   => 'auto_increment',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( '`id`', $sql );
	}

	/**
	 * Test that the create string for a primary column contains the column type.
	 *
	 * @since 2.1.0
	 */
	public function test_get_create_string_for_primary_column_contains_type() {
		$column = new Column(
			array(
				'name'    => 'id',
				'type'    => 'bigint',
				'length'  => '20',
				'primary' => true,
				'extra'   => 'auto_increment',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'bigint(20)', $sql );
	}

	/**
	 * Test that the create string for a primary column contains the unsigned keyword.
	 *
	 * @since 2.1.0
	 */
	public function test_get_create_string_for_primary_column_contains_unsigned() {
		$column = new Column(
			array(
				'name'     => 'id',
				'type'     => 'bigint',
				'length'   => '20',
				'unsigned' => true,
				'primary'  => true,
				'extra'    => 'auto_increment',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'unsigned', $sql );
	}

	/**
	 * Test that the create string for a primary column contains AUTO_INCREMENT.
	 *
	 * @since 2.1.0
	 */
	public function test_get_create_string_for_primary_column_contains_auto_increment() {
		$column = new Column(
			array(
				'name'   => 'id',
				'type'   => 'bigint',
				'length' => '20',
				'extra'  => 'auto_increment',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'AUTO_INCREMENT', $sql );
	}

	/**
	 * Test that the create string for a varchar column contains the specified length.
	 *
	 * @since 2.1.0
	 */
	public function test_get_create_string_for_varchar_column_contains_length() {
		$column = new Column(
			array(
				'name'    => 'title',
				'type'    => 'varchar',
				'length'  => '200',
				'default' => '',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'varchar(200)', $sql );
	}

	/**
	 * Test that the create string for a non-nullable varchar column contains NOT NULL.
	 *
	 * @since 2.1.0
	 */
	public function test_get_create_string_for_varchar_column_contains_not_null() {
		$column = new Column(
			array(
				'name'       => 'title',
				'type'       => 'varchar',
				'length'     => '200',
				'allow_null' => false,
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'not null', $sql );
	}

	/**
	 * Test that the create string for a datetime column contains the datetime type.
	 *
	 * @since 2.1.0
	 */
	public function test_get_create_string_for_datetime_column_contains_type() {
		$column = new Column(
			array(
				'name' => 'created_at',
				'type' => 'datetime',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'datetime', $sql );
	}

	// Validation helpers.

	/**
	 * validate_uuid() returns the column default for an empty value.
	 *
	 * Generation is now the responsibility of Column::intercept(); validate_uuid()
	 * is a pure format check that degrades to $this->default on invalid input.
	 *
	 * @since 2.1.0
	 * @since 3.1.0 Updated: validate_uuid() no longer generates; expects default.
	 */
	public function test_validate_uuid_returns_default_for_empty_value() {
		$column = new Column( array( 'uuid' => true ) );
		$this->assertSame( $column->default, $column->validate( '' ) );
	}

	/**
	 * Test that validate_uuid preserves an existing valid urn:uuid: value unchanged.
	 *
	 * @since 2.1.0
	 */
	public function test_validate_uuid_preserves_existing_urn_uuid() {
		$column   = new Column( array( 'uuid' => true ) );
		$existing = 'urn:uuid:550e8400-e29b-41d4-a716-446655440000';
		$result   = $column->validate( $existing );
		$this->assertSame( $existing, $result );
	}

	/**
	 * Test that validate_int coerces a numeric string to an integer.
	 *
	 * @since 2.1.0
	 */
	public function test_validate_int_coerces_string_to_int() {
		$column = new Column(
			array(
				'name' => 'count',
				'type' => 'bigint',
			)
		);
		$result = $column->validate( '42' );
		$this->assertSame( 42, $result );
	}

	/**
	 * Test that validate_datetime returns a well-formed datetime string unchanged.
	 *
	 * @since 2.1.0
	 */
	public function test_validate_datetime_returns_valid_datetime_string() {
		$column = new Column(
			array(
				'name' => 'created',
				'type' => 'datetime',
			)
		);
		$result = $column->validate( '2024-01-15 10:30:00' );
		$this->assertSame( '2024-01-15 10:30:00', $result );
	}

	/**
	 * Test that validate_datetime returns an empty value when given an empty input.
	 *
	 * @since 2.1.0
	 */
	public function test_validate_datetime_returns_empty_string_for_empty_value() {
		/*
		 * validate_datetime() returns $this->default for empty values, so the
		 * column must have the zero-date default for this assertion to hold.
		 */
		$column = new Column(
			array(
				'name' => 'created',
				'type' => 'datetime',
			)
		);
		$result = $column->validate( '' );
		$this->assertEmpty( $result );
	}

	// Base::__get() magic getter.

	/**
	 * Test that the magic getter accesses a protected sortable property correctly.
	 *
	 * @since 2.1.0
	 */
	public function test_magic_getter_accesses_protected_sortable_property() {
		$column = new Column(
			array(
				'name'     => 'title',
				'type'     => 'varchar',
				'sortable' => true,
			)
		);
		$this->assertTrue( $column->sortable );
	}

	/**
	 * Test that the magic getter returns null for a nonexistent property.
	 *
	 * @since 2.1.0
	 */
	public function test_magic_getter_returns_null_for_nonexistent_property() {
		$column = new Column();
		$this->assertNull( $column->nonexistent_property_xyz );
	}

	// Capabilities.

	/**
	 * Test that the default caps array contains all four CRUD operation keys.
	 *
	 * @since 2.1.0
	 */
	public function test_caps_defaults_contain_all_four_operations() {
		$column = new Column(
			array(
				'name' => 'id',
				'type' => 'bigint',
			)
		);
		$this->assertArrayHasKey( 'select', $column->caps );
		$this->assertArrayHasKey( 'insert', $column->caps );
		$this->assertArrayHasKey( 'update', $column->caps );
		$this->assertArrayHasKey( 'delete', $column->caps );
	}

	/**
	 * Test that caps default to the "exist" capability for each operation.
	 *
	 * @since 2.1.0
	 */
	public function test_caps_default_to_exist_capability() {
		$column = new Column(
			array(
				'name' => 'id',
				'type' => 'bigint',
			)
		);
		$this->assertSame( 'exist', $column->caps['insert'] );
	}

	// to_array().

	/**
	 * Test that to_array includes the name key with its value.
	 *
	 * @since 2.1.0
	 */
	public function test_to_array_includes_name_key() {
		$column = new Column(
			array(
				'name' => 'status',
				'type' => 'varchar',
			)
		);
		$arr    = $column->to_array();
		$this->assertArrayHasKey( 'name', $arr );
		$this->assertSame( 'status', $arr['name'] );
	}

	/**
	 * Test that to_array includes the type key.
	 *
	 * @since 2.1.0
	 */
	public function test_to_array_includes_type_key() {
		$column = new Column(
			array(
				'name' => 'status',
				'type' => 'VARCHAR',
			)
		);
		$arr    = $column->to_array();
		$this->assertArrayHasKey( 'type', $arr );
	}

	/**
	 * Test that to_array includes the primary key with a true value when set.
	 *
	 * @since 2.1.0
	 */
	public function test_to_array_includes_primary_key() {
		$column = new Column(
			array(
				'name'    => 'id',
				'type'    => 'bigint',
				'primary' => true,
			)
		);
		$arr    = $column->to_array();
		$this->assertArrayHasKey( 'primary', $arr );
		$this->assertTrue( $arr['primary'] );
	}

	// get_create_string() - type SQL branches.

	/**
	 * Test that the create string omits any type clause when no type is set.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_without_type_omits_type_clause() {
		$column = new Column( array( 'name' => 'x' ) );
		$sql    = $column->get_create_string();
		$this->assertStringNotContainsString( 'bigint', $sql );
		$this->assertStringNotContainsString( 'varchar', $sql );
	}

	/**
	 * Test that the create string includes a CHARACTER SET clause when encoding is specified.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_with_encoding_includes_character_set() {
		$column = new Column(
			array(
				'name'     => 'body',
				'type'     => 'text',
				'encoding' => 'utf8mb4',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'CHARACTER SET utf8mb4', $sql );
	}

	/**
	 * Test that the create string includes a COLLATE clause when collation is specified.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_with_collation_includes_collate() {
		$column = new Column(
			array(
				'name'      => 'body',
				'type'      => 'text',
				'collation' => 'utf8mb4_unicode_ci',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'COLLATE utf8mb4_unicode_ci', $sql );
	}

	/**
	 * Test that the create string for a binary type uses binary charset and collation.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_binary_type_uses_binary_charset_and_collation() {
		$column = new Column(
			array(
				'name'   => 'hash',
				'type'   => 'varbinary',
				'length' => '32',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'CHARACTER SET binary', $sql );
		$this->assertStringContainsString( 'COLLATE binary', $sql );
	}

	/**
	 * Test that the binary flag on a text column appends a _bin collation suffix.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_binary_flag_on_text_uses_bin_collation() {
		$column = new Column(
			array(
				'name'      => 'slug',
				'type'      => 'varchar',
				'length'    => '200',
				'binary'    => true,
				'collation' => 'utf8mb4',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'COLLATE utf8mb4_bin', $sql );
	}

	// get_create_string() - default SQL branches.

	/**
	 * Test that an allow_null column with a null default produces a "default null" clause.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_allow_null_with_null_default_uses_default_null() {
		$column = new Column(
			array(
				'name'       => 'note',
				'type'       => 'text',
				'allow_null' => true,
				'default'    => null,
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'default null', $sql );
	}

	/**
	 * Test that a text column without an explicit default outputs an empty string default.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_text_column_without_default_outputs_empty_default() {
		// Text columns with no explicit default (i.e. default = '') produce "default ''".
		$column = new Column(
			array(
				'name' => 'note',
				'type' => 'text',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( "default ''", $sql );
	}

	/**
	 * Test that a bigint column without an explicit default outputs a zero default.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_bigint_column_defaults_to_zero() {
		$column = new Column(
			array(
				'name' => 'count',
				'type' => 'bigint',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( "default '0'", $sql );
	}

	/**
	 * Test that an AUTO_INCREMENT column omits the default value clause.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_auto_increment_column_omits_default() {
		$column = new Column(
			array(
				'name'  => 'id',
				'type'  => 'bigint',
				'extra' => 'auto_increment',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringNotContainsString( "default '0'", $sql );
	}

	/**
	 * Test that a datetime column uses the zero-date string as its default value.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_datetime_column_uses_zero_date_default() {
		$column = new Column(
			array(
				'name' => 'created_at',
				'type' => 'datetime',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( "default '0000-00-00 00:00:00'", $sql );
	}

	/**
	 * Test that a custom string default value appears in the create string output.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_custom_string_default_appears_in_output() {
		// 'validate' => 'strval' preserves the string through sanitize_default().
		$column = new Column(
			array(
				'name'     => 'status',
				'type'     => 'varchar',
				'length'   => '20',
				'default'  => 'active',
				'validate' => 'strval',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( "default 'active'", $sql );
	}

	/**
	 * Test that a timestamp column with an ON UPDATE extra produces the correct SQL clause.
	 *
	 * @since 3.0.0
	 */
	public function test_get_create_string_timestamp_with_on_update_extra() {
		$column = new Column(
			array(
				'name'  => 'modified_at',
				'type'  => 'timestamp',
				'extra' => 'ON UPDATE CURRENT_TIMESTAMP',
			)
		);
		$sql    = $column->get_create_string();
		$this->assertStringContainsString( 'ON UPDATE current_timestamp()', $sql );
	}

	// Cast attribute - auto-detection.

	/**
	 * Test that a column with args but no type has a null cast after construction.
	 *
	 * When no args are passed at all, configure() bails early and validate_args()
	 * never runs, so $cast stays as ''. With args present, sanitize_cast fires and
	 * returns null when no type can be matched.
	 *
	 * @since 3.0.0
	 */
	public function test_default_cast_is_null_for_typeless_column() {
		$column = new Column( array( 'name' => 'x' ) );
		$this->assertNull( $column->cast );
	}

	/**
	 * Test that a bigint column auto-detects intval as its cast.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_auto_detects_intval_for_bigint() {
		$column = new Column(
			array(
				'name' => 'count',
				'type' => 'bigint',
			)
		);
		$this->assertSame( 'intval', $column->cast );
	}

	/**
	 * Test that a float column auto-detects floatval as its cast.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_auto_detects_floatval_for_float() {
		$column = new Column(
			array(
				'name' => 'price',
				'type' => 'float',
			)
		);
		$this->assertSame( 'floatval', $column->cast );
	}

	/**
	 * Test that a decimal column auto-detects floatval as its cast.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_auto_detects_floatval_for_decimal() {
		$column = new Column(
			array(
				'name' => 'amount',
				'type' => 'decimal',
			)
		);
		$this->assertSame( 'floatval', $column->cast );
	}

	/**
	 * Test that a bool column auto-detects cast_bool as its cast.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_auto_detects_cast_bool_for_bool() {
		$column = new Column(
			array(
				'name' => 'active',
				'type' => 'bool',
			)
		);
		$this->assertIsCallable( $column->cast );
		$this->assertSame( array( $column, 'cast_bool' ), $column->cast );
	}

	/**
	 * Test that a varchar column auto-detects strval as its cast.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_auto_detects_strval_for_varchar() {
		$column = new Column(
			array(
				'name'   => 'title',
				'type'   => 'varchar',
				'length' => '255',
			)
		);
		$this->assertSame( 'strval', $column->cast );
	}

	/**
	 * Test that a text column auto-detects strval as its cast.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_auto_detects_strval_for_text() {
		$column = new Column(
			array(
				'name' => 'body',
				'type' => 'text',
			)
		);
		$this->assertSame( 'strval', $column->cast );
	}

	/**
	 * Test that a datetime column has a null cast after construction.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_is_null_for_datetime() {
		$column = new Column(
			array(
				'name' => 'created_at',
				'type' => 'datetime',
			)
		);
		$this->assertNull( $column->cast );
	}

	/**
	 * Test that a varbinary column has a null cast after construction.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_is_null_for_binary() {
		$column = new Column(
			array(
				'name'   => 'hash',
				'type'   => 'varbinary',
				'length' => '32',
			)
		);
		$this->assertNull( $column->cast );
	}

	/**
	 * Test that an explicit callable cast overrides auto-detection.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_accepts_explicit_callable() {
		$column = new Column(
			array(
				'name' => 'meta',
				'type' => 'longtext',
				'cast' => 'maybe_unserialize',
			)
		);
		$this->assertSame( 'maybe_unserialize', $column->cast );
	}

	/**
	 * Test that an invalid cast value falls back to auto-detection.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_invalid_value_falls_back_to_auto_detection() {
		$column = new Column(
			array(
				'name' => 'count',
				'type' => 'bigint',
				'cast' => 'not_a_real_function_xyz',
			)
		);
		$this->assertSame( 'intval', $column->cast );
	}

	// cast() method.

	/**
	 * Test that cast() coerces a numeric string to an integer for a bigint column.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_method_coerces_string_to_int_for_bigint() {
		$column = new Column(
			array(
				'name' => 'count',
				'type' => 'bigint',
			)
		);
		$this->assertSame( 42, $column->cast( '42' ) );
	}

	/**
	 * Test that cast() coerces a numeric string to a float for a float column.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_method_coerces_string_to_float_for_float() {
		$column = new Column(
			array(
				'name' => 'price',
				'type' => 'float',
			)
		);
		$this->assertSame( 3.14, $column->cast( '3.14' ) );
	}

	/**
	 * Test that cast() coerces a truthy string to true for a bool column.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_method_coerces_truthy_string_to_true_for_bool() {
		$column = new Column(
			array(
				'name' => 'active',
				'type' => 'bool',
			)
		);
		$this->assertTrue( $column->cast( '1' ) );
	}

	/**
	 * Test that cast() coerces a falsy string to false for a bool column.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_method_coerces_falsy_string_to_false_for_bool() {
		$column = new Column(
			array(
				'name' => 'active',
				'type' => 'bool',
			)
		);
		$this->assertFalse( $column->cast( '0' ) );
	}

	/**
	 * Test that cast_bool correctly handles yes/no string values.
	 *
	 * boolval('no') returns true (non-empty string); cast_bool('no') returns false.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_bool_handles_yes_no_strings() {
		$column = new Column(
			array(
				'name' => 'active',
				'type' => 'bool',
			)
		);
		$this->assertTrue( $column->cast( 'yes' ) );
		$this->assertFalse( $column->cast( 'no' ) );
	}

	/**
	 * Test that cast_bool correctly handles on/off string values.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_bool_handles_on_off_strings() {
		$column = new Column(
			array(
				'name' => 'active',
				'type' => 'bool',
			)
		);
		$this->assertTrue( $column->cast( 'on' ) );
		$this->assertFalse( $column->cast( 'off' ) );
	}

	/**
	 * Test that cast() is a passthrough for a datetime column.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_method_is_passthrough_for_datetime() {
		$column = new Column(
			array(
				'name' => 'created_at',
				'type' => 'datetime',
			)
		);
		$value  = '2024-01-15 10:30:00';
		$this->assertSame( $value, $column->cast( $value ) );
	}

	/**
	 * Test that cast() applies a custom callable.
	 *
	 * @since 3.0.0
	 */
	public function test_cast_method_applies_custom_callable() {
		$serialized = serialize( array( 'foo' => 'bar' ) );
		$column     = new Column(
			array(
				'name' => 'meta',
				'type' => 'longtext',
				'cast' => 'maybe_unserialize',
			)
		);
		$result     = $column->cast( $serialized );
		$this->assertIsArray( $result );
		$this->assertSame( 'bar', $result['foo'] );
	}

	// Relationships (berlindb/core #193).

	/**
	 * Test that the default relationships property is an empty array.
	 *
	 * @since 3.1.0
	 */
	public function test_default_relationships_is_empty_array() {
		$column = new Column();
		$this->assertSame( array(), $column->relationships );
	}

	/**
	 * Test that a fully specified relationship is preserved.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_full_entry_is_preserved() {
		$column = new Column(
			array(
				'name'          => 'order_id',
				'type'          => 'bigint',
				'relationships' => array(
					array(
						'query'  => 'EDD\\Database\\Queries\\Order',
						'column' => 'id',
						'type'   => 'belongs_to',
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'query'  => 'EDD\\Database\\Queries\\Order',
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
			$column->relationships
		);
	}

	/**
	 * Test that an omitted type defaults to belongs_to.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_type_defaults_to_belongs_to() {
		$column = new Column(
			array(
				'relationships' => array(
					array(
						'query'  => 'EDD\\Database\\Queries\\Order',
						'column' => 'id',
					),
				),
			)
		);

		$this->assertSame( 'belongs_to', $column->relationships[0]['type'] );
	}

	/**
	 * Test that a PRESENT but unrecognized type drops the whole relationship
	 * (reject-not-mutate), rather than silently coercing it to belongs_to and
	 * running the wrong direction. An OMITTED type still defaults to belongs_to.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_invalid_type_is_dropped() {
		$column = new Column(
			array(
				'relationships' => array(
					array(
						'query'  => 'EDD\\Database\\Queries\\Order',
						'column' => 'id',
						'type'   => 'nonsense',
					),
				),
			)
		);

		$this->assertSame( array(), $column->relationships );
	}

	/**
	 * Test that a has_many type is preserved.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_has_many_type_is_preserved() {
		$column = new Column(
			array(
				'relationships' => array(
					array(
						'query'  => 'EDD\\Database\\Queries\\OrderItem',
						'column' => 'order_id',
						'type'   => 'has_many',
					),
				),
			)
		);

		$this->assertSame( 'has_many', $column->relationships[0]['type'] );
	}

	/**
	 * Test that entries missing a required key are dropped.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_drops_entries_missing_required_keys() {
		$column = new Column(
			array(
				'relationships' => array(
					array( 'column' => 'id' ),                            // No table.
					array( 'query' => 'EDD\\Database\\Queries\\Order' ),  // No column.
					array(
						'query'  => 'EDD\\Database\\Queries\\Order',
						'column' => 'id',
					),
				),
			)
		);

		$this->assertCount( 1, $column->relationships );
		$this->assertSame( 'EDD\\Database\\Queries\\Order', $column->relationships[0]['query'] );
	}

	/**
	 * Test that non-array entries are dropped and the result is re-keyed as a list.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_drops_non_array_entries_and_rekeys() {
		$column = new Column(
			array(
				'relationships' => array(
					'not-an-array',
					array(
						'query'  => 'EDD\\Database\\Queries\\Order',
						'column' => 'id',
					),
				),
			)
		);

		$this->assertCount( 1, $column->relationships );
		$this->assertArrayHasKey( 0, $column->relationships );
	}

	/**
	 * Test that the column name is sanitized.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_column_name_is_sanitized() {
		$column = new Column(
			array(
				'relationships' => array(
					array(
						'query'  => 'EDD\\Database\\Queries\\Order',
						'column' => 'order-id',
					),
				),
			)
		);

		$this->assertSame( 'order_id', $column->relationships[0]['column'] );
	}

	/**
	 * Test that a clean fully-qualified Query class name is accepted unchanged
	 * (backslashes preserved).
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_query_name_valid_is_accepted() {
		$column = new Column(
			array(
				'relationships' => array(
					array(
						'query'  => 'EDD\\Database\\Queries\\Order',
						'column' => 'id',
					),
				),
			)
		);

		$this->assertCount( 1, $column->relationships );
		$this->assertSame( 'EDD\\Database\\Queries\\Order', $column->relationships[0]['query'] );
	}

	/**
	 * Test that a Query class name carrying invalid characters is REJECTED (the
	 * whole relationship is dropped) rather than silently mutated into a
	 * different, possibly real, class name.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_query_name_invalid_is_rejected() {
		$column = new Column(
			array(
				'relationships' => array(
					array(
						'query'  => 'EDD\\Database\\Queries\\Order; DROP TABLE',
						'column' => 'id',
					),
				),
			)
		);

		// The malformed entry is dropped, not coerced to 'OrderDROPTABLE'.
		$this->assertSame( array(), $column->relationships );
	}

	/**
	 * Test that an optional relationship name is passed through and sanitized.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_name_passthrough() {
		$column = new Column(
			array(
				'relationships' => array(
					array(
						'name'   => 'creator',
						'query'  => 'EDD\\Database\\Queries\\User',
						'column' => 'id',
					),
				),
			)
		);

		$this->assertSame( 'creator', $column->relationships[0]['name'] );
	}

	/**
	 * Test that a relationship without a name omits the key (derived later).
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_without_name_omits_key() {
		$column = new Column(
			array(
				'relationships' => array(
					array(
						'query'  => 'EDD\\Database\\Queries\\Order',
						'column' => 'id',
					),
				),
			)
		);

		$this->assertArrayNotHasKey( 'name', $column->relationships[0] );
	}

	/**
	 * Test that optional enforced-FK attributes pass through the shorthand.
	 *
	 * @since 3.1.0
	 */
	public function test_relationship_enforced_attributes_passthrough() {
		$column = new Column(
			array(
				'relationships' => array(
					array(
						'query'      => 'Acme\\Queries\\Order',
						'column'     => 'id',
						'enforce'    => true,
						'on_delete'  => 'cascade',
						'constraint' => 'fk_order',
					),
				),
			)
		);

		$entry = $column->relationships[0];

		$this->assertTrue( $entry['enforce'] );
		$this->assertSame( 'cascade', $entry['on_delete'] );
		$this->assertSame( 'fk_order', $entry['constraint'] );
	}

	// SQL CAST helpers.

	/**
	 * Test that get_sql_cast_type derives the right SQL CAST target by type.
	 *
	 * @since 3.1.0
	 */
	public function test_get_sql_cast_type_by_column_type() {
		$signed = new Column(
			array(
				'name'     => 'count',
				'type'     => 'bigint',
				'unsigned' => false,
			)
		);
		$this->assertSame( 'SIGNED', $signed->get_sql_cast_type() );

		$unsigned = new Column(
			array(
				'name'     => 'count',
				'type'     => 'bigint',
				'unsigned' => true,
			)
		);
		$this->assertSame( 'UNSIGNED', $unsigned->get_sql_cast_type() );

		$decimal = new Column(
			array(
				'name' => 'total',
				'type' => 'decimal',
			)
		);
		$this->assertSame( 'DECIMAL', $decimal->get_sql_cast_type() );

		$date = new Column(
			array(
				'name' => 'created',
				'type' => 'date',
			)
		);
		$this->assertSame( 'DATE', $date->get_sql_cast_type() );

		$datetime = new Column(
			array(
				'name' => 'created',
				'type' => 'datetime',
			)
		);
		$this->assertSame( 'DATETIME', $datetime->get_sql_cast_type() );

		$time = new Column(
			array(
				'name' => 'elapsed',
				'type' => 'time',
			)
		);
		$this->assertSame( 'TIME', $time->get_sql_cast_type() );
	}

	/**
	 * Test that get_sql_cast_type returns '' (no cast) for native string types.
	 *
	 * @since 3.1.0
	 */
	public function test_get_sql_cast_type_is_empty_for_text() {
		$column = new Column(
			array(
				'name' => 'label',
				'type' => 'varchar',
			)
		);
		$this->assertSame( '', $column->get_sql_cast_type() );
	}

	/**
	 * Test get_type_category() across types, including the temporal split and the
	 * optional cast override.
	 *
	 * @since 3.1.0
	 */
	public function test_get_type_category() {

		$cases = array(
			'datetime' => 'date',
			'date'     => 'date',
			'time'     => 'time',
			'year'     => 'year',
			'bigint'   => 'numeric',
			'decimal'  => 'numeric',
			'varchar'  => 'string',
		);

		foreach ( $cases as $type => $category ) {
			$column = new Column(
				array(
					'name' => 'c',
					'type' => $type,
				)
			);
			$this->assertSame( $category, $column->get_type_category(), "type {$type}" );
		}

		// A cast overrides the declared type.
		$varchar = new Column(
			array(
				'name' => 'c',
				'type' => 'varchar',
			)
		);
		$this->assertSame( 'numeric', $varchar->get_type_category( 'SIGNED' ) );
		$this->assertSame( 'date', $varchar->get_type_category( 'DATETIME' ) );
		$this->assertSame( 'time', $varchar->get_type_category( 'TIME' ) );

		/*
		 * The cast is normalized like get_name_sql(): a sloppy-but-valid cast is
		 * honored, and an invalid cast is ignored (the declared type decides).
		 */
		$this->assertSame( 'numeric', $varchar->get_type_category( ' signed ' ) );
		$this->assertSame( 'string', $varchar->get_type_category( 'SIGNEDFOO' ) );
	}

	/**
	 * Test that an explicit type_category overrides the inferred one (settable,
	 * WordPress-style, even when it does not match the declared type), and that an
	 * unrecognized value is ignored in favor of inference.
	 *
	 * @since 3.1.0
	 */
	public function test_type_category_property_override() {

		// Explicit, recognized category wins over the type's inference.
		$override = new Column(
			array(
				'name'          => 'c',
				'type'          => 'varchar',
				'type_category' => 'date',
			)
		);
		$this->assertSame( 'date', $override->type_category );
		$this->assertSame( 'date', $override->get_type_category() );

		// An unrecognized category is ignored - inference from the type stands.
		$bogus = new Column(
			array(
				'name'          => 'c',
				'type'          => 'bigint',
				'type_category' => 'not-a-category',
			)
		);
		$this->assertSame( 'numeric', $bogus->get_type_category() );
	}

	/**
	 * Test the granular temporal predicates that back category inference.
	 *
	 * @since 3.1.0
	 */
	public function test_temporal_type_predicates() {

		$datetime = new Column(
			array(
				'name' => 'c',
				'type' => 'datetime',
			)
		);
		$this->assertTrue( $datetime->is_date() );
		$this->assertFalse( $datetime->is_time() );
		$this->assertFalse( $datetime->is_year() );

		$time = new Column(
			array(
				'name' => 'c',
				'type' => 'time',
			)
		);
		$this->assertTrue( $time->is_time() );
		$this->assertFalse( $time->is_date() );

		$year = new Column(
			array(
				'name' => 'c',
				'type' => 'year',
			)
		);
		$this->assertTrue( $year->is_year() );
		$this->assertFalse( $year->is_date() );
	}

	/**
	 * Test that get_name_sql wraps the reference in CAST only when a cast is given.
	 *
	 * @since 3.1.0
	 */
	public function test_get_name_sql_optionally_casts() {
		$column = new Column(
			array(
				'name' => 'total',
				'type' => 'varchar',
			)
		);

		// No cast: unchanged behavior.
		$this->assertSame( '`total`', $column->get_name_sql() );
		$this->assertSame( '`a`.`total`', $column->get_name_sql( 'a' ) );

		// With a cast: wrapped.
		$this->assertSame( 'CAST(`a`.`total` AS SIGNED)', $column->get_name_sql( 'a', 'SIGNED' ) );

		// CHAR is a real cast target (string-semantics comparison / LIKE).
		$this->assertSame( 'CAST(`a`.`total` AS CHAR)', $column->get_name_sql( 'a', 'CHAR' ) );

		// Invalid cast is sanitized away at this public boundary (no cast).
		$this->assertSame( '`a`.`total`', $column->get_name_sql( 'a', 'nonsense' ) );
	}
}
