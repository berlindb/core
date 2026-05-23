<?php
/**
 * Schema class tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Column;
use BerlinDB\Database\Index;
use BerlinDB\Tests\Fixtures\TestSchema;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for BerlinDB\Database\Schema.
 *
 * Schema is a pure PHP object; no database connection required.
 *
 * @since 2.1.0
 */
class SchemaTest extends TestCase {

	/** @var TestSchema */
	private static $schema;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$schema = new TestSchema();
	}

	public function test_columns_are_converted_to_column_objects() {
		foreach ( self::$schema->columns as $column ) {
			$this->assertInstanceOf( Column::class, $column );
		}
	}

	public function test_column_count_matches_definition() {
		$this->assertCount( 7, self::$schema->columns );
	}

	public function test_primary_column_is_named_id() {
		$schema = new TestSchema();
		$schema->clear();

		$schema->add_item(
			'columns',
			array(
				'name'     => 'id',
				'type'     => 'bigint',
				'length'   => '20',
				'unsigned' => true,
				'primary'  => true,
			)
		);

		$schema->add_item(
			'columns',
			array(
				'name'   => 'name',
				'type'   => 'varchar',
				'length' => '200',
			)
		);

		$primary = array_filter(
			$schema->columns,
			static function ( $col ) {
				return true === $col->primary;
			}
		);

		$this->assertCount( 1, $primary );

		$col = reset( $primary );
		$this->assertSame( 'id', $col->name );
	}

	public function test_exactly_one_primary_index_exists() {
		$primary = array_filter(
			self::$schema->indexes,
			static function ( $index ) {
				return 'primary' === strtolower( (string) $index->type );
			}
		);
		$this->assertCount( 1, $primary );
	}

	public function test_primary_index_targets_id() {
		$primary = array_filter(
			self::$schema->indexes,
			static function ( $index ) {
				return 'primary' === strtolower( (string) $index->type );
			}
		);
		$index   = reset( $primary );
		$this->assertContains( 'id', (array) $index->columns );
	}

	public function test_searchable_columns_include_name() {
		$searchable = array_filter(
			self::$schema->columns,
			static function ( $col ) {
				return true === $col->searchable;
			}
		);
		$names      = array_map(
			static function ( $col ) {
				return $col->name;
			},
			$searchable
		);
		$this->assertContains( 'name', array_values( $names ) );
	}

	public function test_uuid_column_exists_with_correct_properties() {
		$uuid_cols = array_filter(
			self::$schema->columns,
			static function ( $col ) {
				return 'uuid' === $col->name;
			}
		);
		$this->assertCount( 1, $uuid_cols );
		$uuid = reset( $uuid_cols );
		$this->assertTrue( $uuid->uuid );
		$this->assertFalse( $uuid->searchable );
		$this->assertFalse( $uuid->sortable );
	}

	public function test_get_create_table_string_is_not_empty() {
		$sql = self::$schema->get_create_table_string();
		$this->assertNotEmpty( $sql );
	}

	public function test_get_create_table_string_contains_primary_key_directive() {
		$sql = self::$schema->get_create_table_string();
		/*
		 * The Column with primary=true contributes `id` to the CREATE TABLE SQL;
		 * the actual PRIMARY KEY directive comes from the Index, if any, or is
		 * implied. Just verify the column name appears.
		 */
		$this->assertStringContainsString( '`id`', $sql );
	}

	public function test_get_create_table_string_contains_id_column() {
		$this->assertStringContainsString( '`id`', self::$schema->get_create_table_string() );
	}

	public function test_get_create_table_string_contains_name_column() {
		$this->assertStringContainsString( '`name`', self::$schema->get_create_table_string() );
	}

	public function test_get_create_table_string_contains_status_column() {
		$this->assertStringContainsString( '`status`', self::$schema->get_create_table_string() );
	}

	public function test_get_create_table_string_contains_priority_column() {
		$this->assertStringContainsString( '`priority`', self::$schema->get_create_table_string() );
	}

	public function test_get_create_table_string_contains_date_created_column() {
		$this->assertStringContainsString( '`date_created`', self::$schema->get_create_table_string() );
	}

	public function test_get_create_table_string_contains_date_modified_column() {
		$this->assertStringContainsString( '`date_modified`', self::$schema->get_create_table_string() );
	}

	public function test_get_create_table_string_contains_uuid_column() {
		$this->assertStringContainsString( '`uuid`', self::$schema->get_create_table_string() );
	}

	public function test_clear_empties_columns_array() {
		$schema = new TestSchema();
		$schema->clear( 'columns' );
		$this->assertEmpty( $schema->columns );
	}

	public function test_clear_with_no_arg_empties_both_columns_and_indexes() {
		$schema = new TestSchema();
		$schema->clear();
		$this->assertEmpty( $schema->columns );
		$this->assertEmpty( $schema->indexes );
	}

	public function test_add_item_with_legacy_signature_appends_a_column_object() {
		$schema       = new TestSchema();
		$count_before = count( $schema->columns );
		$result       = $schema->add_item(
			'columns',
			Column::class,
			array(
				'name'   => 'extra_col',
				'type'   => 'varchar',
				'length' => '50',
			)
		);
		$this->assertInstanceOf( Column::class, $result );
		$this->assertCount( $count_before + 1, $schema->columns );
	}

	public function test_add_item_with_current_signature_appends_a_column_object() {
		$schema       = new TestSchema();
		$count_before = count( $schema->columns );
		$result       = $schema->add_item(
			'columns',
			array(
				'name'   => 'extra_col_two',
				'type'   => 'varchar',
				'length' => '50',
			)
		);
		$this->assertInstanceOf( Column::class, $result );
		$this->assertCount( $count_before + 1, $schema->columns );
	}

	public function test_add_item_returns_false_for_empty_data() {
		$schema = new TestSchema();
		$result = $schema->add_item( 'columns', array() );
		$this->assertFalse( $result );
	}

	public function test_add_item_returns_false_for_invalid_type() {
		$schema = new TestSchema();
		$this->assertFalse( $schema->add_item( 'invalid_type', array( 'name' => 'foo' ) ) );
	}

	// add_column() / add_index() convenience wrappers.

	public function test_add_column_appends_column_and_returns_instance() {
		$schema = new TestSchema();
		$count  = count( $schema->get_columns() );
		$result = $schema->add_column( array( 'name' => 'extra', 'type' => 'bigint' ) );
		$this->assertInstanceOf( Column::class, $result );
		$this->assertCount( $count + 1, $schema->get_columns() );
	}

	public function test_add_index_appends_index_and_returns_instance() {
		$schema = new TestSchema();
		$count  = count( $schema->get_indexes() );
		$result = $schema->add_index(
			array(
				'name'    => 'name',
				'type'    => 'key',
				'columns' => array( 'name' ),
			)
		);
		$this->assertInstanceOf( Index::class, $result );
		$this->assertCount( $count + 1, $schema->get_indexes() );
	}

	// get_column() / get_index().

	public function test_get_column_returns_column_object_by_name() {
		$column = self::$schema->get_column( 'name' );
		$this->assertInstanceOf( Column::class, $column );
		$this->assertSame( 'name', $column->name );
	}

	public function test_get_column_returns_false_for_nonexistent_name() {
		$this->assertFalse( self::$schema->get_column( 'nonexistent_xyz' ) );
	}

	public function test_get_index_returns_index_object_by_name() {
		$index = self::$schema->get_index( 'status' );
		$this->assertInstanceOf( Index::class, $index );
	}

	public function test_get_index_with_primary_alias_returns_primary_index() {
		$index = self::$schema->get_index( 'primary' );
		$this->assertInstanceOf( Index::class, $index );
		$this->assertSame( 'primary', strtolower( $index->type ) );
	}

	public function test_get_index_returns_false_for_nonexistent_name() {
		$this->assertFalse( self::$schema->get_index( 'nonexistent_xyz' ) );
	}

	// has_column() / has_index().

	public function test_has_column_returns_true_for_existing_column() {
		$this->assertTrue( self::$schema->has_column( 'name' ) );
	}

	public function test_has_column_returns_false_for_nonexistent_column() {
		$this->assertFalse( self::$schema->has_column( 'nonexistent_xyz' ) );
	}

	public function test_has_index_returns_true_for_existing_index() {
		$this->assertTrue( self::$schema->has_index( 'status' ) );
	}

	public function test_has_index_with_primary_alias_returns_true() {
		$this->assertTrue( self::$schema->has_index( 'primary' ) );
	}

	public function test_has_index_returns_false_for_nonexistent_index() {
		$this->assertFalse( self::$schema->has_index( 'nonexistent_xyz' ) );
	}

	// remove_column() / remove_index().

	public function test_remove_column_removes_column_and_returns_true() {
		$schema = new TestSchema();
		$this->assertTrue( $schema->remove_column( 'name' ) );
		$this->assertFalse( $schema->has_column( 'name' ) );
	}

	public function test_remove_column_returns_false_for_nonexistent_column() {
		$schema = new TestSchema();
		$this->assertFalse( $schema->remove_column( 'nonexistent_xyz' ) );
	}

	public function test_remove_index_removes_index_by_name_and_returns_true() {
		$schema = new TestSchema();
		$this->assertTrue( $schema->remove_index( 'status' ) );
		$this->assertFalse( $schema->has_index( 'status' ) );
	}

	public function test_remove_index_with_primary_alias_removes_primary_index() {
		$schema = new TestSchema();
		$this->assertTrue( $schema->remove_index( 'primary' ) );
		$this->assertFalse( $schema->has_index( 'primary' ) );
	}

	public function test_remove_index_returns_false_for_nonexistent_index() {
		$schema = new TestSchema();
		$this->assertFalse( $schema->remove_index( 'nonexistent_xyz' ) );
	}

	// set_columns() / set_indexes().

	public function test_set_columns_replaces_all_columns() {
		$schema = new TestSchema();
		$schema->set_columns(
			array(
				array( 'name' => 'foo', 'type' => 'bigint' ),
				array( 'name' => 'bar', 'type' => 'varchar', 'length' => '50' ),
			)
		);
		$this->assertCount( 2, $schema->get_columns() );
		$this->assertTrue( $schema->has_column( 'foo' ) );
		$this->assertFalse( $schema->has_column( 'id' ) );
	}

	public function test_set_indexes_replaces_all_indexes() {
		$schema = new TestSchema();
		$schema->set_indexes(
			array(
				array( 'type' => 'primary', 'columns' => array( 'id' ) ),
			)
		);
		$this->assertCount( 1, $schema->get_indexes() );
		$this->assertFalse( $schema->has_index( 'status' ) );
		$this->assertTrue( $schema->has_index( 'primary' ) );
	}

	// is_valid() / get_validation_errors().

	public function test_is_valid_returns_true_for_well_formed_schema() {
		$this->assertTrue( self::$schema->is_valid() );
	}

	public function test_get_validation_errors_returns_empty_array_for_valid_schema() {
		$this->assertSame( array(), self::$schema->get_validation_errors() );
	}

	public function test_validation_error_for_column_missing_name() {
		$schema = new TestSchema();
		$schema->clear();
		$schema->add_column( array( 'type' => 'bigint' ) );
		$this->assertNotEmpty( $schema->get_validation_errors() );
	}

	public function test_validation_error_for_duplicate_column_names() {
		$schema = new TestSchema();
		$schema->clear();
		$schema->add_column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$schema->add_column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$errors = $schema->get_validation_errors();
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Duplicate column name', $errors[0] );
	}

	public function test_validation_error_for_index_missing_name() {
		$schema = new TestSchema();
		$schema->clear();
		$schema->add_column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$schema->add_index( array( 'type' => 'key', 'columns' => array( 'id' ) ) );
		$errors = $schema->get_validation_errors();
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'missing a valid name', $errors[0] );
	}

	public function test_validation_error_for_duplicate_index_names() {
		$schema = new TestSchema();
		$schema->clear();
		$schema->add_column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$schema->add_index( array( 'name' => 'id_idx', 'type' => 'key', 'columns' => array( 'id' ) ) );
		$schema->add_index( array( 'name' => 'id_idx', 'type' => 'key', 'columns' => array( 'id' ) ) );
		$errors = $schema->get_validation_errors();
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Duplicate index name', $errors[0] );
	}

	public function test_validation_error_for_index_referencing_unknown_column() {
		$schema = new TestSchema();
		$schema->clear();
		$schema->add_column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$schema->add_index(
			array(
				'name'    => 'bad_idx',
				'type'    => 'key',
				'columns' => array( 'nonexistent' ),
			)
		);
		$errors = $schema->get_validation_errors();
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'unknown column', $errors[0] );
	}

	public function test_validation_error_for_index_with_no_columns() {
		$schema = new TestSchema();
		$schema->clear();
		$schema->add_column( array( 'name' => 'id', 'type' => 'bigint' ) );
		$schema->add_index( array( 'name' => 'empty_idx', 'type' => 'key', 'columns' => array() ) );
		$errors = $schema->get_validation_errors();
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'does not include any columns', $errors[0] );
	}

	public function test_validation_error_for_multiple_primary_keys() {
		$schema = new TestSchema();
		$schema->clear();
		$schema->add_column( array( 'name' => 'id',     'type' => 'bigint', 'primary' => true ) );
		$schema->add_column( array( 'name' => 'alt_id', 'type' => 'bigint', 'primary' => true ) );
		$errors = $schema->get_validation_errors();
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'multiple primary keys', $errors[ count( $errors ) - 1 ] );
	}

	public function test_get_create_table_string_returns_empty_for_invalid_schema() {
		$schema = new TestSchema();
		$schema->clear();
		$schema->add_column( array( 'type' => 'bigint' ) ); // no name — invalid
		$this->assertSame( '', $schema->get_create_table_string() );
	}
}
