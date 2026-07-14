<?php
/**
 * Schema diff (Comparator / Patch) tests.
 *
 * The Comparator compares two schemas and returns a Patch describing the changes
 * that transform one into the other. v1 detects added/dropped columns and indexes
 * (matched by identity - column name; index name, primary by type); "modified"
 * detection arrives in a later phase. Schema::diff() is the pure entry point.
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
 * Tests for the Diff subsystem and Schema::diff().
 *
 * @since 3.1.0
 */
class DiffTest extends TestCase {

	/**
	 * Build a schema from columns + indexes definition arrays.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int,array<string,mixed>> $columns Column definitions.
	 * @param array<int,array<string,mixed>> $indexes Index definitions.
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
	 * Map a list of Column/Index objects to their names.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int,object> $items The items.
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
	 * A column present only in the target is reported as added.
	 *
	 * @since 3.1.0
	 */
	public function test_added_column_detected() {
		$from = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '100',
				),
			)
		);

		$patch = $from->diff( $to );

		$this->assertSame( array( 'email' ), $this->names( $patch->added_columns() ) );
		$this->assertSame( array(), $patch->dropped_columns() );
		$this->assertFalse( $patch->is_empty() );
	}

	/**
	 * A column present only in the source is reported as dropped.
	 *
	 * @since 3.1.0
	 */
	public function test_dropped_column_detected() {
		$from = $this->schema(
			array(
				array(
					'name' => 'id',
					'type' => 'bigint',
				),
				array(
					'name'   => 'legacy',
					'type'   => 'varchar',
					'length' => '20',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name' => 'id',
					'type' => 'bigint',
				),
			)
		);

		$patch = $from->diff( $to );

		$this->assertSame( array( 'legacy' ), $this->names( $patch->dropped_columns() ) );
		$this->assertSame( array(), $patch->added_columns() );
	}

	/**
	 * Identical schemas produce an empty patch.
	 *
	 * @since 3.1.0
	 */
	public function test_identical_schemas_yield_empty_patch() {
		$columns = array(
			array(
				'name'    => 'id',
				'type'    => 'bigint',
				'primary' => true,
			),
			array(
				'name'   => 'name',
				'type'   => 'varchar',
				'length' => '191',
			),
		);

		$patch = $this->schema( $columns )->diff( $this->schema( $columns ) );

		$this->assertTrue( $patch->is_empty() );
	}

	/**
	 * Added and dropped indexes are detected by name.
	 *
	 * @since 3.1.0
	 */
	public function test_added_and_dropped_indexes() {
		$from = $this->schema(
			array(
				array(
					'name'   => 'slug',
					'type'   => 'varchar',
					'length' => '100',
					'index'  => true,
				),
			)
		);

		// Same 'slug' key plus a distinct 'token' key, so 'token' is the only diff.
		$to = $this->schema(
			array(
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
					'index'  => true,
				),
			)
		);

		$patch = $from->diff( $to );

		$this->assertSame( array( 'token' ), $this->names( $patch->added_indexes() ) );
		$this->assertSame( array(), $patch->dropped_indexes() );

		// And the reverse drops it.
		$this->assertSame( array( 'token' ), $this->names( $to->diff( $from )->dropped_indexes() ) );
	}

	/**
	 * The primary key is matched by type, not by its (empty) name.
	 *
	 * @since 3.1.0
	 */
	public function test_primary_index_matched_by_type() {
		$from = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
			)
		);

		$patch = $from->diff( $to );

		$this->assertSame( array(), $patch->added_indexes() );
		$this->assertSame( array(), $patch->dropped_indexes() );
	}

	/**
	 * revert() swaps added and dropped.
	 *
	 * @since 3.1.0
	 */
	public function test_revert_swaps_adds_and_drops() {
		$from = $this->schema(
			array(
				array(
					'name' => 'id',
					'type' => 'bigint',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name' => 'id',
					'type' => 'bigint',
				),
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '100',
				),
			)
		);

		$revert = $from->diff( $to )->revert();

		$this->assertSame( array( 'email' ), $this->names( $revert->dropped_columns() ) );
		$this->assertSame( array(), $revert->added_columns() );
	}

	/**
	 * A same-named column with a changed length is reported as modified.
	 *
	 * @since 3.1.0
	 */
	public function test_detects_modified_column_length() {
		$from = $this->schema(
			array(
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '100',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '255',
				),
			)
		);

		$patch = $from->diff( $to );

		$this->assertSame( array(), $patch->added_columns() );
		$this->assertSame( array(), $patch->dropped_columns() );
		$this->assertCount( 1, $patch->modified_columns() );
		$this->assertFalse( $patch->is_empty() );

		$modified = $patch->modified_columns()[0];
		$this->assertSame( 'email', $modified->name() );
		$this->assertSame( '100', (string) $modified->from()->length );
		$this->assertSame( '255', (string) $modified->to()->length );
	}

	/**
	 * An integer's display width (int(11) vs int) does NOT phantom-diff.
	 *
	 * MySQL 8 drops the display width, so an introspected column may report a
	 * different width (or none) than the declaration - that must not read as a
	 * modification.
	 *
	 * @since 3.1.0
	 */
	public function test_integer_display_width_is_not_a_modification() {
		$from = $this->schema(
			array(
				array(
					'name'   => 'views',
					'type'   => 'int',
					'length' => '11',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name' => 'views',
					'type' => 'int',
				),
			)
		);

		$this->assertTrue( $from->diff( $to )->is_empty() );
	}

	/**
	 * A DECIMAL precision change IS a modification (unlike integer display width).
	 *
	 * @since 3.1.0
	 */
	public function test_detects_modified_decimal_precision() {
		$from = $this->schema(
			array(
				array(
					'name'   => 'amount',
					'type'   => 'decimal',
					'length' => '10',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'   => 'amount',
					'type'   => 'decimal',
					'length' => '12',
				),
			)
		);

		$this->assertCount( 1, $from->diff( $to )->modified_columns() );
	}

	/**
	 * A DECIMAL scale change IS a modification (from_mysql now captures the scale).
	 *
	 * @since 3.1.0
	 */
	public function test_detects_modified_decimal_scale() {
		$from = $this->schema(
			array(
				array(
					'name'   => 'amount',
					'type'   => 'decimal',
					'length' => '18',
					'scale'  => '2',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'   => 'amount',
					'type'   => 'decimal',
					'length' => '18',
					'scale'  => '9',
				),
			)
		);

		$this->assertCount( 1, $from->diff( $to )->modified_columns() );
	}

	/**
	 * Identical DECIMAL scale does not phantom-diff.
	 *
	 * @since 3.1.0
	 */
	public function test_identical_decimal_scale_is_not_a_modification() {
		$cols = array(
			array(
				'name'   => 'amount',
				'type'   => 'decimal',
				'length' => '18',
				'scale'  => '9',
			),
		);

		$this->assertCount( 0, $this->schema( $cols )->diff( $this->schema( $cols ) )->modified_columns() );
	}

	/**
	 * Type synonyms fold to their canonical name, so they do not phantom-diff.
	 *
	 * MySQL stores 'integer' as 'int' etc., so a declared synonym must compare
	 * equal to the introspected canonical type. (Explicit unsigned isolates the
	 * type-name fold from the unsigned default.)
	 *
	 * @since 3.1.0
	 */
	public function test_type_synonyms_fold_to_canonical() {
		$synonyms  = $this->schema(
			array(
				array(
					'name'     => 'a',
					'type'     => 'integer',
					'unsigned' => false,
				),
				array(
					'name'     => 'b',
					'type'     => 'numeric',
					'length'   => '10',
					'unsigned' => false,
				),
			)
		);
		$canonical = $this->schema(
			array(
				array(
					'name'     => 'a',
					'type'     => 'int',
					'unsigned' => false,
				),
				array(
					'name'     => 'b',
					'type'     => 'decimal',
					'length'   => '10',
					'unsigned' => false,
				),
			)
		);

		$this->assertTrue( $synonyms->diff( $canonical )->is_empty() );
	}

	/**
	 * A changed nullability is reported as modified.
	 *
	 * @since 3.1.0
	 */
	public function test_detects_modified_column_nullability() {
		$from = $this->schema(
			array(
				array(
					'name'       => 'note',
					'type'       => 'text',
					'allow_null' => true,
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'       => 'note',
					'type'       => 'text',
					'allow_null' => false,
				),
			)
		);

		$this->assertCount( 1, $from->diff( $to )->modified_columns() );
	}

	/**
	 * A changed index kind (KEY -> UNIQUE) on the same name is reported as modified.
	 *
	 * @since 3.1.0
	 */
	public function test_detects_modified_index_kind() {
		$from = $this->schema(
			array(
				array(
					'name'   => 'slug',
					'type'   => 'varchar',
					'length' => '100',
					'index'  => true,
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'   => 'slug',
					'type'   => 'varchar',
					'length' => '100',
					'unique' => true,
				),
			)
		);

		$patch = $from->diff( $to );

		$this->assertSame( array(), $patch->added_indexes() );
		$this->assertSame( array(), $patch->dropped_indexes() );
		$this->assertCount( 1, $patch->modified_indexes() );
		$this->assertSame( 'slug', $patch->modified_indexes()[0]->name() );
	}

	/**
	 * revert() swaps the from/to sides of a modification.
	 *
	 * @since 3.1.0
	 */
	public function test_revert_swaps_modified_sides() {
		$from = $this->schema(
			array(
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '100',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '255',
				),
			)
		);

		$reverted = $from->diff( $to )->revert()->modified_columns()[0];

		$this->assertSame( '255', (string) $reverted->from()->length );
		$this->assertSame( '100', (string) $reverted->to()->length );
	}

	/**
	 * Column identity is case-insensitive.
	 *
	 * @since 3.1.0
	 */
	public function test_column_identity_is_case_insensitive() {
		$from = $this->schema(
			array(
				array(
					'name'   => 'Email',
					'type'   => 'varchar',
					'length' => '100',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '100',
				),
			)
		);

		$this->assertTrue( $from->diff( $to )->is_empty() );
	}

	/**
	 * An empty source schema marks every target item as added.
	 *
	 * This is the "table does not exist yet" case: Schema::from_table() returns an
	 * empty Schema (never false/null), so diffing it against the declared schema
	 * reports the whole schema as additions - no guard or fatal needed.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_source_marks_everything_added() {
		$empty = $this->schema( array() );
		$full  = $this->schema(
			array(
				array(
					'name'    => 'id',
					'type'    => 'bigint',
					'primary' => true,
				),
				array(
					'name'   => 'email',
					'type'   => 'varchar',
					'length' => '100',
				),
			)
		);

		$patch = $empty->diff( $full );

		$this->assertSame( array( 'id', 'email' ), $this->names( $patch->added_columns() ) );
		$this->assertSame( array(), $patch->dropped_columns() );
		$this->assertNotEmpty( $patch->added_indexes() );
		$this->assertFalse( $patch->is_empty() );
	}

	/**
	 * An unbound patch (pure Schema::diff) has no table, so it cannot run or render.
	 *
	 * @since 3.1.0
	 */
	public function test_unbound_patch_does_not_apply_or_render() {
		$patch = $this->schema( array() )->diff(
			$this->schema(
				array(
					array(
						'name' => 'id',
						'type' => 'bigint',
					),
				)
			)
		);

		// The change set is real...
		$this->assertFalse( $patch->is_empty() );
		$this->assertNotEmpty( $patch->added_columns() );

		// ...but with no bound table there is nothing to alter.
		$this->assertTrue( $patch->apply()->is_failed() );
		$this->assertSame( array(), $patch->to_sql() );
		$this->assertSame( array(), $patch->to_sql( array( 'add', 'modify', 'drop' ) ) );
	}

	/**
	 * A synonym with an integer display width does not phantom-diff (INTEGER(11) vs INT).
	 *
	 * @since 3.1.0
	 */
	public function test_synonym_integer_width_is_not_a_modification() {
		$from = $this->schema(
			array(
				array(
					'name'     => 'n',
					'type'     => 'integer',
					'length'   => '11',
					'unsigned' => false,
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'     => 'n',
					'type'     => 'int',
					'unsigned' => false,
				),
			)
		);

		$this->assertTrue( $from->diff( $to )->is_empty() );
	}

	/**
	 * A signed/unsigned change on a numeric column is a modification.
	 *
	 * @since 3.1.0
	 */
	public function test_detects_modified_unsigned() {
		$from = $this->schema(
			array(
				array(
					'name'     => 'n',
					'type'     => 'bigint',
					'unsigned' => true,
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'     => 'n',
					'type'     => 'bigint',
					'unsigned' => false,
				),
			)
		);

		$this->assertCount( 1, $from->diff( $to )->modified_columns() );
	}

	/**
	 * A complete type change (int -> varchar) is a modification.
	 *
	 * @since 3.1.0
	 */
	public function test_detects_modified_type() {
		$from = $this->schema(
			array(
				array(
					'name' => 'n',
					'type' => 'int',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'   => 'n',
					'type'   => 'varchar',
					'length' => '50',
				),
			)
		);

		$this->assertCount( 1, $from->diff( $to )->modified_columns() );
	}

	/**
	 * A column whose only change is its default is NOT a modification (default excluded).
	 *
	 * @since 3.1.0
	 */
	public function test_excluded_default_change_is_not_a_modification() {
		$from = $this->schema(
			array(
				array(
					'name'    => 'n',
					'type'    => 'varchar',
					'length'  => '10',
					'default' => 'a',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'    => 'n',
					'type'    => 'varchar',
					'length'  => '10',
					'default' => 'b',
				),
			)
		);

		$this->assertTrue( $from->diff( $to )->is_empty() );
	}

	/**
	 * A reordered multi-column index is a modification.
	 *
	 * @since 3.1.0
	 */
	public function test_detects_modified_index_column_order() {
		$columns = array(
			array(
				'name' => 'a',
				'type' => 'bigint',
			),
			array(
				'name' => 'b',
				'type' => 'bigint',
			),
		);

		$from = $this->schema(
			$columns,
			array(
				array(
					'name'    => 'ab',
					'columns' => array( 'a', 'b' ),
				),
			)
		);
		$to   = $this->schema(
			$columns,
			array(
				array(
					'name'    => 'ab',
					'columns' => array( 'b', 'a' ),
				),
			)
		);

		$this->assertCount( 1, $from->diff( $to )->modified_indexes() );
	}

	/**
	 * An index prefix-length change is a modification.
	 *
	 * @since 3.1.0
	 */
	public function test_detects_modified_index_prefix_length() {
		$columns = array(
			array(
				'name'   => 'title',
				'type'   => 'varchar',
				'length' => '255',
			),
		);

		$from = $this->schema(
			$columns,
			array(
				array(
					'name'    => 'ti',
					'columns' => array( 'title(100)' ),
				),
			)
		);
		$to   = $this->schema(
			$columns,
			array(
				array(
					'name'    => 'ti',
					'columns' => array( 'title(191)' ),
				),
			)
		);

		$this->assertCount( 1, $from->diff( $to )->modified_indexes() );
	}

	/**
	 * An index column direction (DESC) change is a modification.
	 *
	 * @since 3.1.0
	 */
	public function test_detects_modified_index_direction() {
		$columns = array(
			array(
				'name' => 'priority',
				'type' => 'int',
			),
		);

		$from = $this->schema(
			$columns,
			array(
				array(
					'name'    => 'pr',
					'columns' => array( 'priority' ),
				),
			)
		);
		$to   = $this->schema(
			$columns,
			array(
				array(
					'name'    => 'pr',
					'columns' => array( 'priority DESC' ),
				),
			)
		);

		$this->assertCount( 1, $from->diff( $to )->modified_indexes() );
	}

	/**
	 * revert() swaps the from/to sides of a modified index.
	 *
	 * @since 3.1.0
	 */
	public function test_revert_swaps_modified_index_sides() {
		$columns = array(
			array(
				'name'   => 'slug',
				'type'   => 'varchar',
				'length' => '100',
			),
		);

		$from = $this->schema(
			$columns,
			array(
				array(
					'name'    => 'sl',
					'type'    => 'key',
					'columns' => array( 'slug' ),
				),
			)
		);
		$to   = $this->schema(
			$columns,
			array(
				array(
					'name'    => 'sl',
					'type'    => 'unique',
					'columns' => array( 'slug' ),
				),
			)
		);

		$modified = $from->diff( $to )->revert()->modified_indexes()[0];

		$this->assertSame( 'unique', $modified->from()->type );
		$this->assertSame( 'key', $modified->to()->type );
	}

	/**
	 * Index identity is case-insensitive (a name-case change is not a diff).
	 *
	 * @since 3.1.0
	 */
	public function test_index_identity_is_case_insensitive() {
		$columns = array(
			array(
				'name'   => 'slug',
				'type'   => 'varchar',
				'length' => '100',
			),
		);

		$from = $this->schema(
			$columns,
			array(
				array(
					'name'    => 'MyIdx',
					'columns' => array( 'slug' ),
				),
			)
		);
		$to   = $this->schema(
			$columns,
			array(
				array(
					'name'    => 'myidx',
					'columns' => array( 'slug' ),
				),
			)
		);

		$this->assertTrue( $from->diff( $to )->is_empty() );
	}

	/**
	 * A single patch can carry additions, drops, and modifications together.
	 *
	 * @since 3.1.0
	 */
	public function test_combined_patch_has_adds_drops_and_modifications() {
		$from = $this->schema(
			array(
				array(
					'name'   => 'keep',
					'type'   => 'varchar',
					'length' => '50',
				),
				array(
					'name' => 'gone',
					'type' => 'bigint',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'   => 'keep',
					'type'   => 'varchar',
					'length' => '100',
				),
				array(
					'name' => 'fresh',
					'type' => 'bigint',
				),
			)
		);

		$patch = $from->diff( $to );

		$this->assertSame( array( 'fresh' ), $this->names( $patch->added_columns() ) );
		$this->assertSame( array( 'gone' ), $this->names( $patch->dropped_columns() ) );
		$this->assertCount( 1, $patch->modified_columns() );
		$this->assertSame( 'keep', $patch->modified_columns()[0]->name() );
	}

	/**
	 * A ColumnDiff's reverse() swaps its from and to sides.
	 *
	 * @since 3.1.0
	 */
	public function test_column_diff_reverse_swaps_sides() {
		$from = $this->schema(
			array(
				array(
					'name' => 'n',
					'type' => 'int',
				),
			)
		);
		$to   = $this->schema(
			array(
				array(
					'name'   => 'n',
					'type'   => 'varchar',
					'length' => '50',
				),
			)
		);

		$diff     = $from->diff( $to )->modified_columns()[0];
		$reversed = $diff->reverse();

		// Original: int -> varchar. Reversed: varchar -> int. (Types stored uppercase.)
		$this->assertSame( 'VARCHAR', $diff->to()->type );
		$this->assertSame( 'VARCHAR', $reversed->from()->type );
		$this->assertSame( 'INT', $reversed->to()->type );
	}
}
