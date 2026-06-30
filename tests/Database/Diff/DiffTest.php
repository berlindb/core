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
	 * v1 does not yet detect a same-named column whose definition changed.
	 *
	 * Documents the boundary: a column present on both sides (matched by name) is
	 * neither added nor dropped, and modification detection is deferred, so the
	 * patch is currently empty. This expectation flips when "modified" lands.
	 *
	 * @since 3.1.0
	 */
	public function test_v1_does_not_detect_modified_column() {
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
		$this->assertSame( array(), $patch->modified_columns() );
		$this->assertTrue( $patch->is_empty() );
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
	 * apply() and to_sql() are Phase 3 stubs for now.
	 *
	 * @since 3.1.0
	 */
	public function test_apply_and_to_sql_are_stubs() {
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

		$this->assertFalse( $patch->is_empty() );
		$this->assertFalse( $patch->apply() );
		$this->assertSame( array(), $patch->to_sql() );
	}
}
