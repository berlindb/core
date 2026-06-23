<?php
/**
 * Update Operation.
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
 * Applies one set of column values to a set of items, however the set is named.
 *
 * The write sibling of Operations\Delete. One mental model: the input resolves to
 * a list of primary IDs, and the same $data is written to each through the
 * existing Query::update_item() primitive. That is the whole Operation - it adds
 * no new update semantics. Looping update_item() preserves per-item validation,
 * capability/column reduction, meta handling, cache invalidation, and the
 * transition actions; a bulk SQL UPDATE would skip all of it.
 *
 * The input is resolved the same three ways as Delete (see Base::resolve_ids()):
 *  - a single scalar             -> that one ID
 *  - a list of scalars           -> those IDs
 *  - an associative query-vars array -> compiled to a WHERE and selected by it
 *
 * Empty $data updates nothing. An empty input, or a filter that compiles to no
 * WHERE, resolves to no IDs and updates nothing: the empty set NEVER widens to
 * "update everything".
 *
 * @since 3.1.0
 * @internal Constructed by Query::update_items().
 */
final class Update extends Base {

	/**
	 * Write $data to the set of items named by $input.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string|array<int|string,mixed> $input A single ID, a list of IDs, or a query-vars filter array.
	 * @param array<string,mixed>                $data  Column => value pairs to write to each matched item.
	 * @return int|false Number of items updated, or false when there was nothing to update.
	 */
	public function update( $input = array(), $data = array() ) {

		// Bail if there is nothing to write.
		if ( empty( $data ) ) {
			return false;
		}

		// Resolve the input to the concrete IDs to update.
		$ids = $this->resolve_ids( $input );

		// Bail if nothing resolved (empty input, no matches, or a refused filter).
		if ( empty( $ids ) ) {
			return false;
		}

		// Write $data to each item through the per-item primitive.
		$updated = 0;

		foreach ( $ids as $id ) {
			if ( $this->query()->update_item( $id, $data ) ) {
				++$updated;
			}
		}

		// Return the number updated.
		return $updated;
	}
}
