<?php
/**
 * Schema primary-key reconciliation tests.
 *
 * A column flagged primary AND a primary index covering that column describe ONE
 * primary key - the flag is the semantic marker queries/parsers read; the index
 * emits the DDL. Real conflicts (multiple primary indexes, multiple flagged
 * columns without a covering composite index, a flagged column the primary index
 * does not cover) still fail validation.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for primary-key validation reconciliation.
 *
 * @since 3.1.0
 */
class SchemaPrimaryKeyTest extends TestCase {

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
	 * A primary-flagged column covered by the primary index is ONE key (valid).
	 *
	 * @since 3.1.0
	 */
	public function test_flag_plus_covering_index_is_valid() {
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
					'type'    => 'primary',
					'columns' => array( 'id' ),
				),
			)
		);

		$this->assertSame( array(), $schema->get_validation_errors() );
		$this->assertStringContainsString( 'PRIMARY KEY', $schema->get_create_table_string() );
	}

	/**
	 * A flagged column the primary index does not cover is a conflict.
	 *
	 * @since 3.1.0
	 */
	public function test_flag_outside_primary_index_is_conflict() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name' => 'other',
					'type' => 'bigint',
				),
			),
			array(
				array(
					'type'    => 'primary',
					'columns' => array( 'other' ),
				),
			)
		);

		$this->assertStringContainsString(
			'not covered by the primary key index',
			implode( ' ', $schema->get_validation_errors() )
		);
	}

	/**
	 * Multiple primary indexes are a conflict.
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_primary_indexes_is_conflict() {
		$schema = $this->schema(
			array(
				array(
					'name' => 'a',
					'type' => 'bigint',
				),
				array(
					'name' => 'b',
					'type' => 'bigint',
				),
			),
			array(
				array(
					'type'    => 'primary',
					'columns' => array( 'a' ),
				),
				array(
					'type'    => 'primary',
					'columns' => array( 'b' ),
				),
			)
		);

		$this->assertContains( 'Schema defines multiple primary keys.', $schema->get_validation_errors() );
	}

	/**
	 * Multiple flagged columns with no covering index are a conflict (unchanged).
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_flagged_columns_without_index_is_conflict() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'a',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name'    => 'b',
					'type'    => 'bigint',
					'primary' => true,
				),
			)
		);

		$this->assertContains( 'Schema defines multiple primary keys.', $schema->get_validation_errors() );
	}

	/**
	 * A composite primary index covering both flagged columns is valid.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_primary_index_covering_flags_is_valid() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'a',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name'    => 'b',
					'type'    => 'bigint',
					'primary' => true,
				),
			),
			array(
				array(
					'type'    => 'primary',
					'columns' => array( 'a', 'b' ),
				),
			)
		);

		$this->assertSame( array(), $schema->get_validation_errors() );
	}

	/**
	 * get_primary_column_name() returns the flagged column's name.
	 *
	 * @since 3.1.0
	 */
	public function test_get_primary_column_name_returns_flagged_column() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'event_id',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name' => 'title',
					'type' => 'varchar',
				),
			)
		);

		$this->assertSame( 'event_id', $schema->get_primary_column_name() );
	}

	/**
	 * get_primary_column_name() falls back to 'id' when no column is flagged.
	 *
	 * @since 3.1.0
	 */
	public function test_get_primary_column_name_defaults_to_id() {
		$schema = $this->schema(
			array(
				array(
					'name' => 'title',
					'type' => 'varchar',
				),
			)
		);

		$this->assertSame( 'id', $schema->get_primary_column_name() );
	}

	/**
	 * get_primary_column_name() returns the first flagged column for a composite key.
	 *
	 * @since 3.1.0
	 */
	public function test_get_primary_column_name_returns_first_of_composite() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'a',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name'    => 'b',
					'type'    => 'bigint',
					'primary' => true,
				),
			),
			array(
				array(
					'type'    => 'primary',
					'columns' => array( 'a', 'b' ),
				),
			)
		);

		$this->assertSame( 'a', $schema->get_primary_column_name() );
	}

	/**
	 * An ordinary index named 'primary' is a reserved-name validation error.
	 *
	 * @since 3.1.0
	 */
	public function test_ordinary_index_named_primary_is_reserved() {
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

		$this->assertContains( 'Reserved index name: primary.', $schema->get_validation_errors() );
	}

	/**
	 * A primary-key index is identified by type, so it is NOT a reserved-name error.
	 *
	 * @since 3.1.0
	 */
	public function test_primary_key_index_is_not_a_reserved_name_error() {
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
					'type'    => 'primary',
					'columns' => array( 'id' ),
				),
			)
		);

		$this->assertNotContains( 'Reserved index name: primary.', $schema->get_validation_errors() );
		$this->assertSame( array(), $schema->get_validation_errors() );
	}
}
