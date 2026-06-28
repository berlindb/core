<?php
/**
 * Schema derived-index tests.
 *
 * A column flagged `unique => true` (UNIQUE) or `index => true` (plain KEY) makes the
 * Schema derive a single-column index named after the column - the flag is the
 * semantic marker, the derived index emits the DDL - unless the column is primary
 * (already indexed) or an index of that name already exists. `unique` wins over
 * `index`.
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
 * Tests for index derivation from the `unique` and `index` column flags.
 *
 * @since 3.1.0
 */
class SchemaDerivedIndexTest extends TestCase {

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
	 * The index names declared on a schema.
	 *
	 * @since 3.1.0
	 *
	 * @param Schema $schema The schema.
	 * @return string[]
	 */
	private function index_names( Schema $schema ): array {
		return array_map(
			static function ( $index ) {
				return $index->name;
			},
			$schema->get_indexes()
		);
	}

	/**
	 * A unique-flagged column derives a UNIQUE index named after the column.
	 *
	 * @since 3.1.0
	 */
	public function test_unique_flag_derives_unique_index() {
		$schema = $this->schema(
			array(
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '100',
					'unique' => true,
				),
			)
		);

		$this->assertContains( 'email', $this->index_names( $schema ) );
		$this->assertStringContainsString( 'UNIQUE KEY `email`', $schema->get_create_table_string() );
		$this->assertSame( array(), $schema->get_validation_errors() );
	}

	/**
	 * A column without the flag derives no index.
	 *
	 * @since 3.1.0
	 */
	public function test_no_flag_derives_no_index() {
		$schema = $this->schema(
			array(
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '100',
				),
			)
		);

		$this->assertSame( array(), $this->index_names( $schema ) );
	}

	/**
	 * An explicit index of the same name suppresses the derived one (no duplicate).
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_same_name_index_suppresses_derivation() {
		$schema = $this->schema(
			array(
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '100',
					'unique' => true,
				),
			),
			array(
				array(
					'name'    => 'email',
					'columns' => array( 'email' ),
				),
			)
		);

		$this->assertSame( array( 'email' ), $this->index_names( $schema ) );
		$this->assertSame( array(), $schema->get_validation_errors() );
	}

	/**
	 * A primary column is already unique, so no redundant unique index is derived.
	 *
	 * @since 3.1.0
	 */
	public function test_primary_column_derives_no_unique_index() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
					'unique'  => true,
				),
			),
			array(
				array(
					'type'    => 'primary',
					'columns' => array( 'id' ),
				),
			)
		);

		$this->assertNotContains( 'id', $this->index_names( $schema ) );
		$this->assertStringNotContainsString( 'UNIQUE KEY', $schema->get_create_table_string() );
	}

	/**
	 * An index-flagged column derives a plain KEY (not UNIQUE) named after the column.
	 *
	 * @since 3.1.0
	 */
	public function test_index_flag_derives_plain_key() {
		$schema = $this->schema(
			array(
				array(
					'name'  => 'user_id',
					'type'  => 'bigint',
					'index' => true,
				),
			)
		);

		$create = $schema->get_create_table_string();
		$this->assertContains( 'user_id', $this->index_names( $schema ) );
		$this->assertStringContainsString( 'KEY `user_id`', $create );
		$this->assertStringNotContainsString( 'UNIQUE KEY', $create );
		$this->assertSame( array(), $schema->get_validation_errors() );
	}

	/**
	 * unique wins over index: a column flagged both derives a single UNIQUE index.
	 *
	 * @since 3.1.0
	 */
	public function test_unique_wins_over_index() {
		$schema = $this->schema(
			array(
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '100',
					'index'  => true,
					'unique' => true,
				),
			)
		);

		$this->assertSame( array( 'email' ), $this->index_names( $schema ) );
		$this->assertStringContainsString( 'UNIQUE KEY `email`', $schema->get_create_table_string() );
	}
}
