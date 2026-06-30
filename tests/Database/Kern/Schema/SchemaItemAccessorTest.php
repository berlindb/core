<?php
/**
 * Schema item-accessor tests.
 *
 * The generic primitives (get_item/has_item/remove_item) match an already-normalized
 * name case-insensitively - the schema treats item identity case-insensitively, as its
 * validation does. The typed wrappers (get_column/get_index and friends) own the
 * type-specific rules: which sanitizer canonicalizes a raw name, and the index-only
 * 'primary' alias that resolves to the primary key regardless of the index's own name.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Index;
use BerlinDB\Database\Kern\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the typed item accessors over the generic primitives.
 *
 * @since 3.1.0
 */
class SchemaItemAccessorTest extends TestCase {

	/**
	 * Build a schema from columns + indexes definition arrays.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int, array<string, mixed>> $columns Column definitions.
	 * @param array<int, array<string, mixed>> $indexes Index definitions.
	 * @return Schema
	 */
	private function schema( array $columns, array $indexes = array() ): Schema {
		return new Schema(
			array(
				'columns' => $columns,
				'indexes' => $indexes,
			)
		);
	}

	/**
	 * get_column() / has_column() match case-insensitively (identity is case-folded).
	 *
	 * @since 3.1.0
	 */
	public function test_get_column_matches_case_insensitively() {
		$schema = $this->schema(
			array(
				array(
					'name' => 'Status',
					'type' => 'varchar',
				),
			)
		);

		$this->assertNotFalse( $schema->get_column( 'status' ) );
		$this->assertNotFalse( $schema->get_column( 'STATUS' ) );
		$this->assertSame( 'Status', $schema->get_column( 'status' )->name );
		$this->assertTrue( $schema->has_column( 'sTaTuS' ) );
	}

	/**
	 * A non-string column name is rejected by the sanitizer (returns false).
	 *
	 * @since 3.1.0
	 */
	public function test_get_column_rejects_non_string_name() {
		$schema = $this->schema(
			array(
				array(
					'name' => 'id',
					'type' => 'bigint',
				),
			)
		);

		$this->assertFalse( $schema->get_column( array( 'id' ) ) );
		$this->assertFalse( $schema->has_column( '' ) );
	}

	/**
	 * remove_column() is case-insensitive too.
	 *
	 * @since 3.1.0
	 */
	public function test_remove_column_is_case_insensitive() {
		$schema = $this->schema(
			array(
				array(
					'name' => 'Status',
					'type' => 'varchar',
				),
			)
		);

		$this->assertTrue( $schema->remove_column( 'status' ) );
		$this->assertFalse( $schema->has_column( 'Status' ) );
	}

	/**
	 * The 'primary' alias resolves the primary key even under a different index name.
	 *
	 * @since 3.1.0
	 */
	public function test_get_index_primary_alias_resolves_by_type() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
			),
			array(
				array(
					'name'    => 'pk',
					'type'    => 'primary',
					'columns' => array( 'id' ),
				),
			)
		);

		$by_alias = $schema->get_index( 'primary' );
		$by_name  = $schema->get_index( 'pk' );

		$this->assertInstanceOf( Index::class, $by_alias );
		$this->assertSame( 'primary', strtolower( $by_alias->type ) );
		$this->assertSame( $by_name, $by_alias );
	}

	/**
	 * The 'primary' alias reaches a derived (unnamed) primary index.
	 *
	 * @since 3.1.0
	 */
	public function test_get_index_primary_alias_reaches_unnamed_primary() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
			)
		);

		$primary = $schema->get_index( 'primary' );

		$this->assertInstanceOf( Index::class, $primary );
		$this->assertSame( 'primary', strtolower( $primary->type ) );
	}

	/**
	 * remove_index('primary') removes a derived (unnamed) primary index.
	 *
	 * @since 3.1.0
	 */
	public function test_remove_index_primary_removes_unnamed_primary() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
			)
		);

		$this->assertTrue( $schema->remove_index( 'primary' ) );
		$this->assertFalse( $schema->has_index( 'primary' ) );
	}

	/**
	 * The generic accessors reject a non-string name rather than casting it.
	 *
	 * @since 3.1.0
	 */
	public function test_generic_accessors_reject_non_string_name() {
		$schema = $this->schema(
			array(
				array(
					'name' => 'id',
					'type' => 'bigint',
				),
			)
		);

		$this->assertFalse( $schema->get_item( 'columns', array( 'id' ) ) );
		$this->assertFalse( $schema->has_item( 'columns', array( 'id' ) ) );
		$this->assertFalse( $schema->remove_item( 'columns', array( 'id' ) ) );
	}

	/**
	 * remove_index('primary') removes an ordinary index literally named 'primary'
	 * when the schema has no primary-key index (matching has_index/get_index).
	 *
	 * @since 3.1.0
	 */
	public function test_remove_index_primary_removes_literal_named_index() {
		$schema = $this->schema(
			array(
				array(
					'name'   => 'slug',
					'type'   => 'varchar',
					'length' => '100',
				),
			),
			array(
				array(
					'name'    => 'primary',
					'type'    => 'key',
					'columns' => array( 'slug' ),
				),
			)
		);

		$this->assertTrue( $schema->has_index( 'primary' ) );
		$this->assertTrue( $schema->remove_index( 'primary' ) );
		$this->assertFalse( $schema->has_index( 'primary' ) );
	}

	/**
	 * Distinct numeric-looking names are not conflated.
	 *
	 * @since 3.1.0
	 */
	public function test_numeric_looking_names_are_distinct() {
		$schema = $this->schema(
			array(
				array(
					'name' => '01',
					'type' => 'bigint',
				),
			)
		);

		$this->assertNotFalse( $schema->get_column( '01' ) );
		$this->assertFalse( $schema->get_column( '1' ) );
	}
}
