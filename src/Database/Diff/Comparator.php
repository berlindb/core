<?php
/**
 * Schema comparator.
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
use BerlinDB\Database\Kern\Schema;

/**
 * Compares two schemas and produces a Patch describing how to transform one into
 * the other.
 *
 * Pure and stateless: it depends only on the schema objects it is given - never
 * on a database connection, a Table, or WordPress - so the whole Diff subsystem
 * stays portable. Items are matched by identity (column name; index name, with
 * the primary key matched by type); an item present on only one side is added or
 * dropped, and one present on both but defined differently (per the equivalence
 * normalizers, which avoid phantom diffs) is a modification. See #224.
 *
 * @since 3.1.0
 */
class Comparator {

	/**
	 * Column equivalence normalizer.
	 *
	 * @since 3.1.0
	 * @var ColumnNormalizer
	 */
	private $columns;

	/**
	 * Index equivalence normalizer.
	 *
	 * @since 3.1.0
	 * @var IndexNormalizer
	 */
	private $indexes;

	/**
	 * Wire up the equivalence normalizers.
	 *
	 * @since 3.1.0
	 */
	public function __construct() {
		$this->columns = new ColumnNormalizer();
		$this->indexes = new IndexNormalizer();
	}

	/**
	 * Compare a source schema to a target, returning the transforming Patch.
	 *
	 * The Patch describes the changes that turn $from into $to: an item present in
	 * $to but not $from is "added"; one present in $from but not $to is "dropped";
	 * one present in both but not equivalent is "modified". (So Table::diff()
	 * compares the live table to the declared schema: $from = actual, $to = desired.)
	 *
	 * @since 3.1.0
	 *
	 * @param Schema $from The source schema.
	 * @param Schema $to   The target schema.
	 *
	 * @return Patch
	 */
	public function compare( Schema $from, Schema $to ): Patch {
		return new Patch(
			array_merge(
				$this->diff_columns( $from->get_columns(), $to->get_columns() ),
				$this->diff_indexes( $from->get_indexes(), $to->get_indexes() )
			)
		);
	}

	/**
	 * Three-way diff of two column collections.
	 *
	 * @since 3.1.0
	 *
	 * @param Column[] $from Source columns.
	 * @param Column[] $to   Target columns.
	 *
	 * @return array<string,mixed> The added/dropped/modified column collections.
	 */
	private function diff_columns( array $from, array $to ): array {
		$from_by = $this->by_column_key( $from );
		$to_by   = $this->by_column_key( $to );

		$added    = array();
		$dropped  = array();
		$modified = array();

		foreach ( $to_by as $key => $column ) {
			if ( ! isset( $from_by[ $key ] ) ) {
				$added[] = $column;
			} elseif ( ! $this->columns->matches( $from_by[ $key ], $column ) ) {
				$modified[] = new ColumnDiff( $from_by[ $key ], $column );
			}
		}

		foreach ( $from_by as $key => $column ) {
			if ( ! isset( $to_by[ $key ] ) ) {
				$dropped[] = $column;
			}
		}

		return array(
			'added_columns'    => $added,
			'dropped_columns'  => $dropped,
			'modified_columns' => $modified,
		);
	}

	/**
	 * Three-way diff of two index collections.
	 *
	 * @since 3.1.0
	 *
	 * @param Index[] $from Source indexes.
	 * @param Index[] $to   Target indexes.
	 *
	 * @return array<string,mixed> The added/dropped/modified index collections.
	 */
	private function diff_indexes( array $from, array $to ): array {
		$from_by = $this->by_index_key( $from );
		$to_by   = $this->by_index_key( $to );

		$added    = array();
		$dropped  = array();
		$modified = array();

		foreach ( $to_by as $key => $index ) {
			if ( ! isset( $from_by[ $key ] ) ) {
				$added[] = $index;
			} elseif ( ! $this->indexes->matches( $from_by[ $key ], $index ) ) {
				$modified[] = new IndexDiff( $from_by[ $key ], $index );
			}
		}

		foreach ( $from_by as $key => $index ) {
			if ( ! isset( $to_by[ $key ] ) ) {
				$dropped[] = $index;
			}
		}

		return array(
			'added_indexes'    => $added,
			'dropped_indexes'  => $dropped,
			'modified_indexes' => $modified,
		);
	}

	/**
	 * Index columns by their identity key (last wins on a duplicate).
	 *
	 * @since 3.1.0
	 *
	 * @param Column[] $columns The columns.
	 * @return array<string,Column>
	 */
	private function by_column_key( array $columns ): array {
		$out = array();

		foreach ( $columns as $column ) {
			$out[ $this->column_key( $column ) ] = $column;
		}

		return $out;
	}

	/**
	 * Index indexes by their identity key (last wins on a duplicate).
	 *
	 * @since 3.1.0
	 *
	 * @param Index[] $indexes The indexes.
	 * @return array<string,Index>
	 */
	private function by_index_key( array $indexes ): array {
		$out = array();

		foreach ( $indexes as $index ) {
			$out[ $this->index_key( $index ) ] = $index;
		}

		return $out;
	}

	/**
	 * Identity key for a column: its name, case-insensitively.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $column The column.
	 * @return string
	 */
	private function column_key( Column $column ): string {
		return strtolower( trim( (string) $column->name ) );
	}

	/**
	 * Identity key for an index: 'primary' for the primary key (which has no name
	 * of its own), otherwise its name, case-insensitively.
	 *
	 * @since 3.1.0
	 *
	 * @param Index $index The index.
	 * @return string
	 */
	private function index_key( Index $index ): string {
		return ( 'primary' === strtolower( (string) $index->type ) )
			? 'primary'
			: strtolower( trim( (string) $index->name ) );
	}
}
