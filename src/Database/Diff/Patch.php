<?php
/**
 * Schema comparison result.
 *
 * @package     Database
 * @subpackage  Diff
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Diff;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Index;
use BerlinDB\Database\Kern\Table;

/**
 * The structured result of comparing two schemas: the set of changes that
 * transforms one schema into another.
 *
 * Modeled on a Git patch - you apply() it to a table or revert() it (the
 * inverse). It carries the real Column / Index objects (not just names), so the
 * change set can be rendered and applied directly without re-resolving anything.
 *
 * Reports added and dropped columns/indexes, plus "modified" ones - a same-named
 * column/index defined differently on the two sides, as a ColumnDiff/IndexDiff.
 *
 * to_sql() renders the reconciling ALTER statements and apply() runs them, both
 * filtered by an operations list ('add', 'modify', 'drop') that defaults to the
 * safe 'add' + 'modify' (dbDelta never drops either). Those two need a table to
 * target, so they only do anything once the Patch is bound (Table::diff() returns
 * a bound Patch; a pure Schema::diff() Patch is unbound - to_sql() returns no
 * statements and apply() is a no-op false).
 *
 * @since 3.1.0
 */
class Patch {

	/**
	 * The operations apply() / to_sql() understand, in canonical form.
	 *
	 * @since 3.1.0
	 * @var string[]
	 */
	private const OPERATIONS = array( 'add', 'modify', 'drop' );

	/**
	 * The table this patch reconciles, when bound (Table::diff()).
	 *
	 * Null for a pure Schema::diff() patch, which has no table to alter.
	 *
	 * @since 3.1.0
	 * @var Table|null
	 */
	private $table = null;

	/**
	 * Columns present in the target but not the source (to be added).
	 *
	 * @since 3.1.0
	 * @var Column[]
	 */
	private $added_columns;

	/**
	 * Columns present in the source but not the target (to be dropped).
	 *
	 * @since 3.1.0
	 * @var Column[]
	 */
	private $dropped_columns;

	/**
	 * Columns present in both but defined differently.
	 *
	 * @since 3.1.0
	 * @var ColumnDiff[]
	 */
	private $modified_columns;

	/**
	 * Indexes present in the target but not the source (to be added).
	 *
	 * @since 3.1.0
	 * @var Index[]
	 */
	private $added_indexes;

	/**
	 * Indexes present in the source but not the target (to be dropped).
	 *
	 * @since 3.1.0
	 * @var Index[]
	 */
	private $dropped_indexes;

	/**
	 * Indexes present in both but defined differently.
	 *
	 * @since 3.1.0
	 * @var IndexDiff[]
	 */
	private $modified_indexes;

	/**
	 * Build a Patch from a keyed set of change collections.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $changes {
	 *     Optional. Change collections, each defaulting to empty.
	 *
	 *     @type Column[]     $added_columns    Columns to add.
	 *     @type Column[]     $dropped_columns  Columns to drop.
	 *     @type ColumnDiff[] $modified_columns Columns changed in place.
	 *     @type Index[]      $added_indexes    Indexes to add.
	 *     @type Index[]      $dropped_indexes  Indexes to drop.
	 *     @type IndexDiff[]  $modified_indexes Indexes changed in place.
	 * }
	 */
	public function __construct( array $changes = array() ) {
		$this->added_columns    = self::objects( $changes, 'added_columns', Column::class );
		$this->dropped_columns  = self::objects( $changes, 'dropped_columns', Column::class );
		$this->added_indexes    = self::objects( $changes, 'added_indexes', Index::class );
		$this->dropped_indexes  = self::objects( $changes, 'dropped_indexes', Index::class );
		$this->modified_columns = self::objects( $changes, 'modified_columns', ColumnDiff::class );
		$this->modified_indexes = self::objects( $changes, 'modified_indexes', IndexDiff::class );
	}

	/**
	 * Bind this patch to the table it reconciles, so apply() / to_sql() can run.
	 *
	 * Called by Table::diff(). The change set itself is unchanged - binding only
	 * gives the patch a target to emit ALTERs against.
	 *
	 * @since 3.1.0
	 *
	 * @param Table $table The table this patch reconciles.
	 *
	 * @return self
	 */
	public function bind_table( Table $table ): self {
		$this->table = $table;

		return $this;
	}

	/**
	 * Whether this patch contains no changes (the schemas are equivalent).
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return empty( $this->added_columns )
			&& empty( $this->dropped_columns )
			&& empty( $this->modified_columns )
			&& empty( $this->added_indexes )
			&& empty( $this->dropped_indexes )
			&& empty( $this->modified_indexes );
	}

	/**
	 * Columns to add.
	 *
	 * @since 3.1.0
	 * @return Column[]
	 */
	public function added_columns(): array {
		return $this->added_columns;
	}

	/**
	 * Columns to drop.
	 *
	 * @since 3.1.0
	 * @return Column[]
	 */
	public function dropped_columns(): array {
		return $this->dropped_columns;
	}

	/**
	 * Columns defined differently in both schemas.
	 *
	 * @since 3.1.0
	 * @return ColumnDiff[]
	 */
	public function modified_columns(): array {
		return $this->modified_columns;
	}

	/**
	 * Indexes to add.
	 *
	 * @since 3.1.0
	 * @return Index[]
	 */
	public function added_indexes(): array {
		return $this->added_indexes;
	}

	/**
	 * Indexes to drop.
	 *
	 * @since 3.1.0
	 * @return Index[]
	 */
	public function dropped_indexes(): array {
		return $this->dropped_indexes;
	}

	/**
	 * Indexes defined differently in both schemas.
	 *
	 * @since 3.1.0
	 * @return IndexDiff[]
	 */
	public function modified_indexes(): array {
		return $this->modified_indexes;
	}

	/**
	 * Return the inverse patch - the changes that undo this one.
	 *
	 * An added column/index becomes dropped (and vice versa), and each modification
	 * has its from/to sides swapped.
	 *
	 * @since 3.1.0
	 *
	 * @return self
	 */
	public function revert(): self {
		return new self(
			array(
				'added_columns'    => $this->dropped_columns,
				'dropped_columns'  => $this->added_columns,
				'added_indexes'    => $this->dropped_indexes,
				'dropped_indexes'  => $this->added_indexes,
				'modified_columns' => array_map(
					static function ( ColumnDiff $diff ) {
						return new ColumnDiff( $diff->to(), $diff->from() );
					},
					$this->modified_columns
				),
				'modified_indexes' => array_map(
					static function ( IndexDiff $diff ) {
						return new IndexDiff( $diff->to(), $diff->from() );
					},
					$this->modified_indexes
				),
			)
		);
	}

	/**
	 * Render this patch as the list of ALTER TABLE statements that apply() would run.
	 *
	 * A dry-run preview: same ordered plan apply() executes, rendered to SQL instead
	 * of run. Returns no statements for an unbound patch (no table to target) or one
	 * whose enabled operations produce no changes.
	 *
	 * @since 3.1.0
	 *
	 * @param string[] $operations Operations to include: 'add', 'modify', 'drop'.
	 *                             Defaults to the safe 'add' + 'modify' (no drops).
	 *
	 * @return list<string>
	 */
	public function to_sql( array $operations = array( 'add', 'modify' ) ): array {

		// An unbound patch has no table to alter.
		if ( ! ( $this->table instanceof Table ) ) {
			return array();
		}

		// A patch needs a table name to target.
		$name = $this->table->get_table_name();

		if ( '' === $name ) {
			return array();
		}

		$out = array();

		foreach ( $this->plan( $operations ) as $step ) {
			$sql = $this->render( $name, $step );

			if ( '' !== $sql ) {
				$out[] = $sql;
			}
		}

		return $out;
	}

	/**
	 * Apply this patch to its bound table by running the reconciling ALTERs.
	 *
	 * Executes the ordered plan through the table's own DDL verbs (add_column,
	 * modify_column, drop_column, add_index, drop_index). Stops at the first failed
	 * step and returns false; an unbound patch is a no-op false.
	 *
	 * Defaults to 'add' + 'modify' only - drops are opt-in. That mirrors dbDelta
	 * (never drops) and keeps a possibly-incomplete introspection (see
	 * Schema::from_table()) from authorizing a destructive change by default.
	 *
	 * Known limitation: a MODIFIED primary key is reconciled as a separate DROP then
	 * ADD, which MySQL rejects when the key covers an AUTO_INCREMENT column (that
	 * column must stay indexed). The standalone DROP PRIMARY KEY fails atomically, so
	 * apply() stops and returns false with the table unchanged (fail-safe, no partial
	 * write) - it does not silently corrupt. Reconciling that case needs a single
	 * combined ALTER (DROP + ADD in one statement); that is future work.
	 *
	 * The operations list is a coarse safety control, not a dependency solver: the
	 * default ('add','modify') and the full set ('add','modify','drop') order their
	 * steps to satisfy column/index dependencies, but a hand-picked partial set (e.g.
	 * 'drop' alone, dropping a column an as-yet-untouched index still references) can
	 * leave a dependency unmet. MySQL rejects that step atomically, so apply() again
	 * returns false with no partial write. Prefer the default or the full set.
	 *
	 * @since 3.1.0
	 *
	 * @param string[] $operations Operations to run: 'add', 'modify', 'drop'.
	 *                             Defaults to the safe 'add' + 'modify' (no drops).
	 *
	 * @return bool True if every step succeeded, false on the first failure or when
	 *              the patch is unbound.
	 */
	public function apply( array $operations = array( 'add', 'modify' ) ): bool {

		// An unbound patch has no table to alter.
		if ( ! ( $this->table instanceof Table ) ) {
			return false;
		}

		// Run each planned step in order, bailing on the first failure.
		foreach ( $this->plan( $operations ) as $step ) {
			if ( ! $this->execute( $this->table, $step ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build the ordered list of operations for the given operation filter.
	 *
	 * Order matters for correctness: indexes are dropped before the columns they
	 * cover, columns are added before the indexes that reference them, and a
	 * modified index is dropped (old) then re-added (new). Each step always carries
	 * both a column and an index slot (one is null) so the shape is predictable.
	 *
	 * @since 3.1.0
	 *
	 * @param string[] $operations The requested operations.
	 *
	 * @return array<int,array{kind:string,column:Column|null,index:Index|null}>
	 */
	private function plan( array $operations ): array {
		$enabled = $this->filter_operations( $operations );
		$add     = in_array( 'add', $enabled, true );
		$modify  = in_array( 'modify', $enabled, true );
		$drop    = in_array( 'drop', $enabled, true );
		$plan    = array();

		// 1. Drop the old side of every modified index, before touching columns.
		if ( true === $modify ) {
			foreach ( $this->modified_indexes as $diff ) {
				$plan[] = $this->step( 'drop_index', null, $diff->from() );
			}
		}

		// 2. Drop removed indexes, then removed columns.
		if ( true === $drop ) {
			foreach ( $this->dropped_indexes as $index ) {
				$plan[] = $this->step( 'drop_index', null, $index );
			}

			foreach ( $this->dropped_columns as $column ) {
				$plan[] = $this->step( 'drop_column', $column, null );
			}
		}

		// 3. Add new columns.
		if ( true === $add ) {
			foreach ( $this->added_columns as $column ) {
				$plan[] = $this->step( 'add_column', $column, null );
			}
		}

		// 4. Modify changed columns in place (using the target-side definition).
		if ( true === $modify ) {
			foreach ( $this->modified_columns as $diff ) {
				$plan[] = $this->step( 'modify_column', $diff->to(), null );
			}
		}

		// 5. Add new indexes, then the new side of every modified index.
		if ( true === $add ) {
			foreach ( $this->added_indexes as $index ) {
				$plan[] = $this->step( 'add_index', null, $index );
			}
		}

		if ( true === $modify ) {
			foreach ( $this->modified_indexes as $diff ) {
				$plan[] = $this->step( 'add_index', null, $diff->to() );
			}
		}

		return $plan;
	}

	/**
	 * Build a single plan step with both subject slots always present.
	 *
	 * @since 3.1.0
	 *
	 * @param string      $kind   The step kind.
	 * @param Column|null $column The column subject, or null.
	 * @param Index|null  $index  The index subject, or null.
	 *
	 * @return array{kind:string,column:Column|null,index:Index|null}
	 */
	private function step( string $kind, ?Column $column, ?Index $index ): array {
		return array(
			'kind'   => $kind,
			'column' => $column,
			'index'  => $index,
		);
	}

	/**
	 * Render a single plan step as an ALTER TABLE statement.
	 *
	 * @since 3.1.0
	 *
	 * @param string                                                  $name The target table name.
	 * @param array{kind:string,column:Column|null,index:Index|null} $step The plan step.
	 *
	 * @return string The statement, or '' when the step has no usable subject.
	 */
	private function render( string $name, array $step ): string {
		$column = $step['column'];
		$index  = $step['index'];

		// Column operations.
		if ( $column instanceof Column ) {
			if ( 'add_column' === $step['kind'] ) {
				return "ALTER TABLE {$name} ADD COLUMN " . $column->get_create_string();
			}

			if ( 'modify_column' === $step['kind'] ) {
				return "ALTER TABLE {$name} MODIFY COLUMN " . $column->get_create_string();
			}

			if ( 'drop_column' === $step['kind'] ) {
				return "ALTER TABLE {$name} DROP COLUMN `" . $column->name . '`';
			}
		}

		// Index operations.
		if ( $index instanceof Index ) {
			if ( 'add_index' === $step['kind'] ) {
				return "ALTER TABLE {$name} ADD " . $index->get_create_string();
			}

			if ( 'drop_index' === $step['kind'] ) {
				$index_name = $index->get_index_name();

				return ( 'PRIMARY' === strtoupper( $index_name ) )
					? "ALTER TABLE {$name} DROP PRIMARY KEY"
					: "ALTER TABLE {$name} DROP INDEX `{$index_name}`";
			}
		}

		return '';
	}

	/**
	 * Execute a single plan step through the table's own DDL verbs.
	 *
	 * @since 3.1.0
	 *
	 * @param Table                                                   $table The bound table.
	 * @param array{kind:string,column:Column|null,index:Index|null} $step  The plan step.
	 *
	 * @return bool
	 */
	private function execute( Table $table, array $step ): bool {
		$column = $step['column'];
		$index  = $step['index'];

		// Column operations.
		if ( $column instanceof Column ) {
			if ( 'add_column' === $step['kind'] ) {
				return $table->add_column( $column );
			}

			if ( 'modify_column' === $step['kind'] ) {
				return $table->modify_column( $column );
			}

			if ( 'drop_column' === $step['kind'] ) {
				return $table->drop_column( $column->name );
			}
		}

		// Index operations.
		if ( $index instanceof Index ) {
			if ( 'add_index' === $step['kind'] ) {
				return $table->add_index( $index );
			}

			if ( 'drop_index' === $step['kind'] ) {
				return $table->drop_index( $index->get_index_name() );
			}
		}

		return false;
	}

	/**
	 * Reduce a requested operations list to the recognized, de-duplicated set.
	 *
	 * @since 3.1.0
	 *
	 * @param string[] $operations The requested operations.
	 *
	 * @return string[]
	 */
	private function filter_operations( array $operations ): array {
		$clean = array();

		foreach ( $operations as $operation ) {
			$operation = strtolower( trim( (string) $operation ) );

			if ( in_array( $operation, self::OPERATIONS, true ) && ! in_array( $operation, $clean, true ) ) {
				$clean[] = $operation;
			}
		}

		return $clean;
	}

	/**
	 * Extract a typed list of objects of a given class from a changes array.
	 *
	 * @since 3.1.0
	 *
	 * @template T of object
	 *
	 * @param array<string,mixed> $changes The changes array.
	 * @param string              $key     The collection key.
	 * @param class-string<T>     $class   The class each item must be.
	 *
	 * @return T[]
	 */
	private static function objects( array $changes, string $key, string $class ): array {
		$items = $changes[ $key ] ?? array();

		if ( ! is_array( $items ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $class ) {
					return $item instanceof $class;
				}
			)
		);
	}
}
