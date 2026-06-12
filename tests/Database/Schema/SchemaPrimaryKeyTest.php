<?php
/**
 * Schema primary-key reconciliation tests.
 *
 * A column flagged primary AND a primary index covering that column describe ONE
 * primary key — the flag is the semantic marker queries/parsers read; the index
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
}
