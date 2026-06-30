<?php
/**
 * Schema filtered-accessor tests.
 *
 * Schema::get_items() (and the get_columns() / get_indexes() that thin over it) accept
 * wp_filter_object_list() match args: a property => value array plus an 'and' / 'or' /
 * 'not' operator. The `type` arg is normalized to each collection's stored case (Column
 * types are uppercase, Index types lowercase) so a caller may pass either.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Column;
use BerlinDB\Database\Index;
use BerlinDB\Database\Kern\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the filtered get_items() / get_columns() / get_indexes() accessors.
 *
 * @since 3.1.0
 */
class SchemaFilteredAccessorTest extends TestCase {

	/**
	 * A schema exercising every filterable column flag and a few types.
	 *
	 * id (bigint, primary), email (varchar, unique), slug (varchar, index),
	 * token (varchar, uuid). Derivation gives it a primary, unique, and two plain
	 * key indexes - one per flagged column.
	 *
	 * @since 3.1.0
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		return new Schema(
			array(
				'columns' => array(
					array(
						'name'    => 'id',
						'type'    => 'bigint',
						'primary' => true,
					),
					array(
						'name'   => 'email',
						'type'   => 'varchar',
						'length' => '100',
						'unique' => true,
					),
					array(
						'name'   => 'slug',
						'type'   => 'varchar',
						'length' => '100',
						'index'  => true,
					),
					array(
						'name'   => 'token',
						'type'   => 'varchar',
						'length' => '36',
						'uuid'   => true,
					),
				),
			)
		);
	}

	/**
	 * Map a list of Column or Index objects to their names.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int, Column|Index> $items The items.
	 * @return string[]
	 */
	private function names( array $items ): array {
		return array_map(
			static function ( $item ) {
				return $item->name;
			},
			$items
		);
	}

	/**
	 * get_columns() with no args returns every column.
	 *
	 * @since 3.1.0
	 */
	public function test_get_columns_no_args_returns_all() {
		$columns = $this->schema()->get_columns();

		$this->assertCount( 4, $columns );
		$this->assertContainsOnlyInstancesOf( Column::class, $columns );
	}

	/**
	 * A boolean-flag arg returns only the columns carrying that flag.
	 *
	 * @since 3.1.0
	 */
	public function test_get_columns_filters_by_boolean_flag() {
		$columns = $this->schema()->get_columns( array( 'primary' => true ) );

		$this->assertSame( array( 'id' ), $this->names( $columns ) );
	}

	/**
	 * The 'or' operator matches a column carrying any one of the args.
	 *
	 * @since 3.1.0
	 */
	public function test_get_columns_or_operator_matches_any_flag() {
		$columns = $this->schema()->get_columns(
			array(
				'unique' => true,
				'index'  => true,
			),
			'or'
		);

		$this->assertSame( array( 'email', 'slug' ), $this->names( $columns ) );
	}

	/**
	 * The 'not' operator returns columns carrying none of the args.
	 *
	 * @since 3.1.0
	 */
	public function test_get_columns_not_operator_excludes_matches() {
		$columns = $this->schema()->get_columns(
			array(
				'unique' => true,
				'index'  => true,
				'uuid'   => true,
			),
			'not'
		);

		$this->assertSame( array( 'id' ), $this->names( $columns ) );
	}

	/**
	 * A `type` arg is matched case-insensitively against the uppercase Column type.
	 *
	 * @since 3.1.0
	 */
	public function test_get_columns_type_arg_is_case_normalized() {
		$columns = $this->schema()->get_columns( array( 'type' => 'varchar' ) );

		$this->assertSame( array( 'email', 'slug', 'token' ), $this->names( $columns ) );
	}

	/**
	 * A filter that matches nothing returns an empty (reindexed) array.
	 *
	 * @since 3.1.0
	 */
	public function test_get_columns_no_match_returns_empty_array() {
		$columns = $this->schema()->get_columns( array( 'name' => 'nonexistent' ) );

		$this->assertSame( array(), $columns );
	}

	/**
	 * A filtered result is reindexed as a sequential list.
	 *
	 * @since 3.1.0
	 */
	public function test_get_columns_filtered_result_is_a_list() {
		$columns = $this->schema()->get_columns( array( 'type' => 'varchar' ) );

		$this->assertSame( array( 0, 1, 2 ), array_keys( $columns ) );
	}

	/**
	 * get_indexes() filters by index type.
	 *
	 * @since 3.1.0
	 */
	public function test_get_indexes_filters_by_type() {
		$indexes = $this->schema()->get_indexes( array( 'type' => 'unique' ) );

		$this->assertSame( array( 'email' ), $this->names( $indexes ) );
		$this->assertContainsOnlyInstancesOf( Index::class, $indexes );
	}

	/**
	 * An uppercase `type` arg matches the lowercase-stored Index type.
	 *
	 * @since 3.1.0
	 */
	public function test_get_indexes_type_arg_is_case_normalized() {
		$indexes = $this->schema()->get_indexes( array( 'type' => 'PRIMARY' ) );

		$this->assertCount( 1, $indexes );
		$this->assertSame( 'primary', strtolower( $indexes[0]->type ) );
	}

	/**
	 * get_items() applies the same filtering for the columns collection.
	 *
	 * @since 3.1.0
	 */
	public function test_get_items_columns_filters() {
		$items = $this->schema()->get_items( 'columns', array( 'primary' => true ) );

		$this->assertSame( array( 'id' ), $this->names( $items ) );
	}

	/**
	 * get_items() accepts the singular 'column' alias with filter args.
	 *
	 * @since 3.1.0
	 */
	public function test_get_items_singular_alias_filters() {
		$items = $this->schema()->get_items( 'column', array( 'unique' => true ) );

		$this->assertSame( array( 'email' ), $this->names( $items ) );
	}

	/**
	 * An unknown item type returns an empty array, even with filter args.
	 *
	 * @since 3.1.0
	 */
	public function test_get_items_unknown_type_returns_empty_with_args() {
		$items = $this->schema()->get_items( 'bogus', array( 'primary' => true ) );

		$this->assertSame( array(), $items );
	}

	/**
	 * A $field with no filter plucks that property from every column.
	 *
	 * @since 3.1.0
	 */
	public function test_get_columns_field_plucks_property_from_all() {
		$names = $this->schema()->get_columns( array(), 'and', 'name' );

		$this->assertSame( array( 'id', 'email', 'slug', 'token' ), $names );
	}

	/**
	 * A $field combines with filter args: pluck from the matches only.
	 *
	 * @since 3.1.0
	 */
	public function test_get_columns_field_plucks_from_filtered_matches() {
		$names = $this->schema()->get_columns( array( 'type' => 'varchar' ), 'and', 'name' );

		$this->assertSame( array( 'email', 'slug', 'token' ), $names );
	}

	/**
	 * get_indexes() plucks a field the same way.
	 *
	 * @since 3.1.0
	 */
	public function test_get_indexes_field_plucks_property() {
		$names = $this->schema()->get_indexes( array( 'type' => 'unique' ), 'and', 'name' );

		$this->assertSame( array( 'email' ), $names );
	}

	/**
	 * get_items() plucks a field for the resolved collection.
	 *
	 * @since 3.1.0
	 */
	public function test_get_items_field_plucks_property() {
		$names = $this->schema()->get_items( 'columns', array( 'primary' => true ), 'and', 'name' );

		$this->assertSame( array( 'id' ), $names );
	}
}
