<?php
/**
 * Delete Operation.
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
 * Deletes a set of items, however the set is named.
 *
 * One mental model: the input resolves to a list of primary IDs, and each ID is
 * removed through the existing Query::delete_item() primitive. That is the whole
 * Operation - it adds no new deletion semantics. Looping delete_item() preserves
 * per-item capability/column reduction, meta cleanup, cache invalidation, and the
 * {item}_deleted action; a bulk SQL DELETE would skip all of it.
 *
 * The input is resolved three ways (see resolve_ids()):
 *  - a single scalar             -> that one ID
 *  - a list of scalars           -> those IDs
 *  - an associative query-vars array -> compiled to a WHERE and selected by it
 *
 * An empty input, or a filter that compiles to no WHERE, resolves to no IDs and
 * deletes nothing: the empty set NEVER widens to "delete everything".
 *
 * @since 3.1.0
 * @internal Constructed by Query::delete_items().
 */
final class Delete extends Base {

	/**
	 * Delete the set of items named by $input.
	 *
	 * @since 3.1.0
	 *
	 * @param int|string|array<int|string,mixed> $input A single ID, a list of IDs, or a query-vars filter array.
	 * @return int|false Number of items deleted, or false when there was nothing to delete.
	 */
	public function delete( $input = array() ) {

		// Resolve the input to the concrete IDs to remove.
		$ids = $this->resolve_ids( $input );

		// Bail if nothing resolved (empty input, no matches, or a refused filter).
		if ( empty( $ids ) ) {
			return false;
		}

		// Remove each item through the per-item primitive.
		$deleted = 0;

		foreach ( $ids as $id ) {
			if ( $this->query()->delete_item( $id ) ) {
				++$deleted;
			}
		}

		// Return the number deleted.
		return $deleted;
	}
}
