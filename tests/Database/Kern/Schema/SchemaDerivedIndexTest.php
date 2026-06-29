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
	 * An explicit UNIQUE index on the column satisfies the unique flag (no derivation).
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_unique_index_satisfies_unique_flag() {
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
					'type'    => 'unique',
					'columns' => array( 'email' ),
				),
			)
		);

		$this->assertSame( array( 'email' ), $this->index_names( $schema ) );
		$this->assertSame( array(), $schema->get_validation_errors() );
	}

	/**
	 * An existing single-column index under a DIFFERENT name still satisfies the flag.
	 *
	 * @since 3.1.0
	 */
	public function test_differently_named_index_satisfies_flag() {
		$schema = $this->schema(
			array(
				array(
					'name'  => 'user_id',
					'type'  => 'bigint',
					'index' => true,
				),
			),
			array(
				array(
					'name'    => 'uid_idx',
					'columns' => array( 'user_id' ),
				),
			)
		);

		$this->assertSame( array( 'uid_idx' ), $this->index_names( $schema ) );
		$this->assertSame( array(), $schema->get_validation_errors() );
	}

	/**
	 * A plain index does NOT satisfy the unique flag: the derived UNIQUE index clashes
	 * by name with the plain one, surfacing as a validation error (not a silent drop).
	 *
	 * @since 3.1.0
	 */
	public function test_plain_index_does_not_satisfy_unique_flag() {
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

		$this->assertNotSame( array(), $schema->get_validation_errors() );
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

	// Primary-index derivation.

	/**
	 * A lone primary-flagged column with no primary index derives the PRIMARY KEY.
	 *
	 * @since 3.1.0
	 */
	public function test_lone_primary_flag_derives_primary_key() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
			)
		);

		$this->assertTrue( $schema->has_index( 'primary' ) );
		$this->assertStringContainsString( 'PRIMARY KEY', $schema->get_create_table_string() );
		$this->assertSame( array(), $schema->get_validation_errors() );
	}

	/**
	 * A derived PRIMARY satisfies a unique flag on the same column (no extra UNIQUE).
	 *
	 * @since 3.1.0
	 */
	public function test_lone_primary_with_unique_flag_derives_only_primary() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
					'unique'  => true,
				),
			)
		);

		$create = $schema->get_create_table_string();
		$this->assertStringContainsString( 'PRIMARY KEY', $create );
		$this->assertStringNotContainsString( 'UNIQUE KEY', $create );
	}

	/**
	 * A primary index that does not cover the flagged column stays a validation
	 * conflict - no second primary key is derived to mask it.
	 *
	 * @since 3.1.0
	 */
	public function test_non_covering_primary_index_stays_a_conflict() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name'   => 'code',
					'type'   => 'varchar',
					'length' => '20',
				),
			),
			array(
				array(
					'type'    => 'primary',
					'columns' => array( 'code' ),
				),
			)
		);

		$this->assertNotSame( array(), $schema->get_validation_errors() );
	}

	/**
	 * Two primary-flagged columns with no covering composite primary index derive
	 * nothing and remain a validation conflict (column order is the caller's to give).
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_primary_columns_without_index_is_a_conflict() {
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

		$this->assertFalse( $schema->has_index( 'primary' ) );
		$this->assertNotSame( array(), $schema->get_validation_errors() );
	}

	/**
	 * A composite PRIMARY does not make one column unique, so a unique flag on a
	 * column inside it still derives a UNIQUE index.
	 *
	 * @since 3.1.0
	 */
	public function test_composite_primary_does_not_satisfy_unique_flag() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'object_id',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name'    => 'term_id',
					'type'    => 'bigint',
					'primary' => true,
					'unique'  => true,
				),
			),
			array(
				array(
					'type'    => 'primary',
					'columns' => array( 'object_id', 'term_id' ),
				),
			)
		);

		$this->assertContains( 'term_id', $this->index_names( $schema ) );
		$this->assertStringContainsString( 'UNIQUE KEY `term_id`', $schema->get_create_table_string() );
		$this->assertSame( array(), $schema->get_validation_errors() );
	}

	// uuid -> plain lookup KEY.

	/**
	 * A uuid column derives a plain KEY (not UNIQUE - its value is generated only on
	 * the Query insert path, so a UNIQUE constraint would reject direct inserts).
	 *
	 * @since 3.1.0
	 */
	public function test_uuid_flag_derives_plain_key() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name' => 'guid',
					'uuid' => true,
				),
			)
		);

		$create = $schema->get_create_table_string();
		$this->assertContains( 'guid', $this->index_names( $schema ) );
		$this->assertStringContainsString( 'KEY `guid`', $create );
		$this->assertStringNotContainsString( 'UNIQUE KEY `guid`', $create );
		$this->assertSame( array(), $schema->get_validation_errors() );
	}

	// Foreign-key (belongs_to) -> lookup KEY.

	/**
	 * A column definition holding a single belongs_to relationship.
	 *
	 * Uses an existing class as the remote query (schema validation only checks the
	 * class exists; whether it is truly a Query is a Query-context concern).
	 *
	 * @since 3.1.0
	 *
	 * @param string $name   Column / foreign-key name.
	 * @param string $type   Column type. Default bigint.
	 * @param int    $length Optional length (for string FKs). Default 0 (none).
	 * @return array<string,mixed>
	 */
	private function belongs_to( string $name, string $type = 'bigint', int $length = 0 ): array {
		$column = array(
			'name'          => $name,
			'type'          => $type,
			'relationships' => array(
				array(
					'type'   => 'belongs_to',
					'query'  => Schema::class,
					'column' => 'id',
				),
			),
		);

		if ( $length > 0 ) {
			$column['length'] = (string) $length;
		}

		return $column;
	}

	/**
	 * A belongs_to foreign-key column derives a plain lookup KEY.
	 *
	 * @since 3.1.0
	 */
	public function test_belongs_to_derives_foreign_key_index() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
				$this->belongs_to( 'customer_id' ),
			)
		);

		$this->assertContains( 'customer_id', $this->index_names( $schema ) );
		$this->assertStringContainsString( 'KEY `customer_id`', $schema->get_create_table_string() );
	}

	/**
	 * An index with the FK as its LEFTMOST full-length column satisfies it (no derive).
	 *
	 * @since 3.1.0
	 */
	public function test_leading_composite_index_satisfies_foreign_key() {
		$schema = $this->schema(
			array(
				array(
					'name'   => 'status',
					'type'   => 'varchar',
					'length' => '20',
				),
				$this->belongs_to( 'customer_id' ),
			),
			array(
				array(
					'name'    => 'cust_status',
					'columns' => array( 'customer_id', 'status' ),
				),
			)
		);

		$this->assertNotContains( 'customer_id', $this->index_names( $schema ) );
	}

	/**
	 * A composite index that does NOT lead with the FK does not satisfy it (derive).
	 *
	 * @since 3.1.0
	 */
	public function test_non_leading_composite_does_not_satisfy_foreign_key() {
		$schema = $this->schema(
			array(
				array(
					'name'   => 'status',
					'type'   => 'varchar',
					'length' => '20',
				),
				$this->belongs_to( 'customer_id' ),
			),
			array(
				array(
					'name'    => 'status_cust',
					'columns' => array( 'status', 'customer_id' ),
				),
			)
		);

		$this->assertContains( 'customer_id', $this->index_names( $schema ) );
	}

	/**
	 * A prefixed leftmost column does not satisfy the FK (a prefix can't back it).
	 *
	 * @since 3.1.0
	 */
	public function test_prefixed_leftmost_does_not_satisfy_foreign_key() {
		$schema = $this->schema(
			array(
				$this->belongs_to( 'ref_key', 'varchar', 100 ),
			),
			array(
				array(
					'name'    => 'ref_pfx',
					'columns' => array( 'ref_key(10)' ),
				),
			)
		);

		$this->assertContains( 'ref_key', $this->index_names( $schema ) );
	}

	/**
	 * Multiple belongs_to entries on one column derive a single index.
	 *
	 * @since 3.1.0
	 */
	public function test_multiple_belongs_to_on_one_column_derives_one_index() {
		$schema = $this->schema(
			array(
				array(
					'name'          => 'object_id',
					'type'          => 'bigint',
					'relationships' => array(
						array(
							'type'   => 'belongs_to',
							'query'  => Schema::class,
							'column' => 'id',
						),
						array(
							'type'   => 'belongs_to',
							'query'  => Schema::class,
							'column' => 'id',
						),
					),
				),
			)
		);

		$count = count(
			array_filter(
				$this->index_names( $schema ),
				static function ( $name ) {
					return 'object_id' === $name;
				}
			)
		);
		$this->assertSame( 1, $count );
	}

	/**
	 * A foreign key too long to index in full is skipped (an explicit Index is needed).
	 *
	 * @since 3.1.0
	 */
	public function test_too_long_foreign_key_is_skipped() {
		$schema = $this->schema(
			array(
				$this->belongs_to( 'long_ref', 'varchar', 255 ),
			)
		);

		$this->assertNotContains( 'long_ref', $this->index_names( $schema ) );
	}

	/**
	 * A FULLTEXT index leading with the FK does not satisfy it (can't back a lookup).
	 *
	 * @since 3.1.0
	 */
	public function test_fulltext_index_does_not_satisfy_foreign_key() {
		$schema = $this->schema(
			array(
				$this->belongs_to( 'ref_key', 'varchar', 100 ),
			),
			array(
				array(
					'name'    => 'ref_ft',
					'type'    => 'fulltext',
					'columns' => array( 'ref_key' ),
				),
			)
		);

		$this->assertContains( 'ref_key', $this->index_names( $schema ) );
	}

	/**
	 * A bounded binary foreign key (e.g. VARBINARY(16)) is indexed in full.
	 *
	 * @since 3.1.0
	 */
	public function test_bounded_binary_foreign_key_is_indexed() {
		$schema = $this->schema(
			array(
				$this->belongs_to( 'bin_ref', 'varbinary', 16 ),
			)
		);

		$this->assertContains( 'bin_ref', $this->index_names( $schema ) );
	}

	/**
	 * A TEXT foreign key with a declared length is skipped (a plain key on TEXT is
	 * rejected by MySQL; only bounded char/varchar/binary/varbinary index in full).
	 *
	 * @since 3.1.0
	 */
	public function test_text_foreign_key_with_length_is_skipped() {
		$schema = $this->schema(
			array(
				$this->belongs_to( 'note_ref', 'text', 100 ),
			)
		);

		$this->assertNotContains( 'note_ref', $this->index_names( $schema ) );
	}

	// cache_key -> lookup KEY.

	/**
	 * A secondary cache_key column derives a plain lookup KEY (not UNIQUE).
	 *
	 * @since 3.1.0
	 */
	public function test_secondary_cache_key_derives_lookup_key() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name'      => 'email',
					'type'      => 'varchar',
					'length'    => '100',
					'cache_key' => true,
				),
			)
		);

		$create = $schema->get_create_table_string();
		$this->assertContains( 'email', $this->index_names( $schema ) );
		$this->assertStringContainsString( 'KEY `email`', $create );
		$this->assertStringNotContainsString( 'UNIQUE KEY `email`', $create );
	}

	/**
	 * The primary cache_key is satisfied by the primary index (no separate lookup key).
	 *
	 * @since 3.1.0
	 */
	public function test_primary_cache_key_is_satisfied_by_primary_index() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
			)
		);

		$create = $schema->get_create_table_string();
		$this->assertStringContainsString( 'PRIMARY KEY', $create );
		$this->assertStringNotContainsString( 'KEY `id`', $create );
	}

	/**
	 * An existing index on the cache_key column satisfies the lookup (no duplicate).
	 *
	 * @since 3.1.0
	 */
	public function test_cache_key_satisfied_by_existing_index() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name'      => 'email',
					'type'      => 'varchar',
					'length'    => '100',
					'cache_key' => true,
				),
			),
			array(
				array(
					'name'    => 'email_idx',
					'columns' => array( 'email' ),
				),
			)
		);

		$this->assertNotContains( 'email', $this->index_names( $schema ) );
		$this->assertContains( 'email_idx', $this->index_names( $schema ) );
	}

	/**
	 * A uuid column whose lookup is already covered by an index derives nothing.
	 *
	 * @since 3.1.0
	 */
	public function test_uuid_satisfied_by_existing_index() {
		$schema = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name' => 'guid',
					'uuid' => true,
				),
			),
			array(
				array(
					'name'    => 'guid',
					'columns' => array( 'guid' ),
				),
			)
		);

		$this->assertContains( 'guid', $this->index_names( $schema ) );
		$this->assertSame( array(), $schema->get_validation_errors() );
	}
}
