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
 * the primary key matched by type). v1 detects added and dropped items only;
 * "modified" detection (comparing two items present on both sides without the
 * dbDelta-style phantom diffs) lands in a later phase. See #224.
 *
 * @since 3.1.0
 */
class Comparator {

	/**
	 * Compare a source schema to a target, returning the transforming Patch.
	 *
	 * The Patch describes the changes that turn $from into $to: a column or index
	 * present in $to but not $from is "added"; one present in $from but not $to is
	 * "dropped". (So Table::diff() compares the live table to the declared schema:
	 * $from = actual, $to = desired.)
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
			array(
				'added_columns'   => $this->only_in( $to->get_columns(), $from->get_columns(), array( $this, 'column_key' ) ),
				'dropped_columns' => $this->only_in( $from->get_columns(), $to->get_columns(), array( $this, 'column_key' ) ),
				'added_indexes'   => $this->only_in( $to->get_indexes(), $from->get_indexes(), array( $this, 'index_key' ) ),
				'dropped_indexes' => $this->only_in( $from->get_indexes(), $to->get_indexes(), array( $this, 'index_key' ) ),
			)
		);
	}

	/**
	 * Return the items whose identity key is not present in the other collection.
	 *
	 * @since 3.1.0
	 *
	 * @template T of Column|Index
	 *
	 * @param T[]      $items  Items to filter.
	 * @param T[]      $others Items to match against.
	 * @param callable $key    Maps an item to its identity key.
	 *
	 * @return T[]
	 */
	private function only_in( array $items, array $others, callable $key ): array {
		$other_keys = array();

		foreach ( $others as $other ) {
			$other_keys[ $key( $other ) ] = true;
		}

		$result = array();

		foreach ( $items as $item ) {
			if ( ! isset( $other_keys[ $key( $item ) ] ) ) {
				$result[] = $item;
			}
		}

		return $result;
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
