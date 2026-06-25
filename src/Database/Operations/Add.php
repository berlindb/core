<?php
/**
 * Add Operation.
 *
 * @package     Database
 * @subpackage  Operations
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operations;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Inserts a set of new items, one row at a time.
 *
 * The create sibling of Operations\Delete and Operations\Update. One mental model:
 * each element of the input is one new item's data, and each is inserted through
 * the existing Query::add_item() primitive. That is the whole Operation - it adds
 * no new insertion semantics. Looping add_item() preserves per-item default values,
 * primary-key/UUID generation, sanitization, meta handling, cache priming, and the
 * save actions; a single multi-row INSERT would skip all of it.
 *
 * Unlike Delete and Update, Add does not resolve its input to existing IDs (there
 * is nothing to select - the rows do not exist yet), so it is the one per-set verb
 * that does not use Base::resolve_ids(). The input is a plain list of data arrays.
 *
 * Because the value of a batch insert IS the new IDs, add() returns them (in input
 * order) rather than a count: each slot holds the new item ID, or false where that
 * one insert failed. An empty input inserts nothing and returns an empty array.
 *
 * @since 3.1.0
 * @internal Constructed by Query::add_items().
 */
final class Add extends Base {

	/**
	 * Insert each new item named by $rows.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int,array<string,mixed>> $rows List of data arrays, one per new item.
	 * @return array<int,int|string|false> New item IDs in input order; false in any slot whose insert failed.
	 */
	public function add( array $rows = array() ): array {

		// Collect each insert's result, preserving input order.
		$ids = array();

		// Insert each item through the per-item primitive.
		foreach ( $rows as $row ) {

			// A non-array element cannot be inserted; record the failure and continue.
			if ( ! is_array( $row ) ) {
				$ids[] = false;
				continue;
			}

			$ids[] = $this->query()->add_item( $row );
		}

		// Return the list of new IDs.
		return $ids;
	}
}
