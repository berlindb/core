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

/**
 * The structured result of comparing two schemas: the set of changes that
 * transforms one schema into another.
 *
 * Modeled on a Git patch - you apply() it to a table or revert() it (the
 * inverse). It carries the real Column / Index objects (not just names), so the
 * change set can be rendered and applied directly without re-resolving anything.
 *
 * v1 populates only added/dropped columns and indexes; "modified" detection
 * (the normalization-heavy part) lands in a later phase. The modified_*()
 * accessors exist now so the result shape is stable - see #224.
 *
 * @since 3.1.0
 */
class Patch {

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
	 * Render this patch as a list of ALTER TABLE fragments.
	 *
	 * Phase 3 (#224): not yet implemented. Returns an empty list for now.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	public function to_sql(): array {
		return array();
	}

	/**
	 * Apply this patch to its bound table (run the ALTERs).
	 *
	 * Phase 3 (#224): not yet implemented. Returns false (a no-op) until then;
	 * Table::diff() will hand back a connection-bound Patch that can execute.
	 *
	 * @since 3.1.0
	 *
	 * @return bool True on success, false otherwise.
	 */
	public function apply(): bool {
		return false;
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
