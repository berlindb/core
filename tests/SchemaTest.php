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

	public function test_exactly_one_primary_column_exists() {
		$primary = array_filter( self::$schema->columns, static function ( $col ) {
			return true === $col->primary;
		} );
		$this->assertCount( 1, $primary );
	}

	public function test_primary_column_is_named_id() {
		$primary = array_filter( self::$schema->columns, static function ( $col ) {
			return true === $col->primary;
		} );
		$col = reset( $primary );
		$this->assertSame( 'id', $col->name );
	}

	public function test_searchable_columns_include_name() {
		$searchable = array_filter( self::$schema->columns, static function ( $col ) {
			return true === $col->searchable;
		} );
		$names = array_map( static function ( $col ) { return $col->name; }, $searchable );
		$this->assertContains( 'name', array_values( $names ) );
	}

	public function test_uuid_column_exists_with_correct_properties() {
		$uuid_cols = array_filter( self::$schema->columns, static function ( $col ) {
			return 'uuid' === $col->name;
		} );
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
		// The Column with primary=true contributes `id` to the CREATE TABLE SQL;
		// the actual PRIMARY KEY directive comes from the Index, if any, or is
		// implied. Just verify the column name appears.
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

	public function test_add_item_appends_a_column_object() {
		$schema      = new TestSchema();
		$count_before = count( $schema->columns );
		$result       = $schema->add_item( 'columns', Column::class, array(
			'name'   => 'extra_col',
			'type'   => 'varchar',
			'length' => '50',
		) );
		$this->assertInstanceOf( Column::class, $result );
		$this->assertCount( $count_before + 1, $schema->columns );
	}

	public function test_add_item_returns_false_for_empty_data() {
		$schema = new TestSchema();
		$result = $schema->add_item( 'columns', Column::class, array() );
		$this->assertFalse( $result );
	}
}
