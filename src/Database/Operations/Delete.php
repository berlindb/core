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
 * One mental model: the input resolves to a list of primary KEYS, and each key is
 * removed through the existing (composite-aware) Query::delete_item() primitive. That
 * is the whole Operation - it adds no new deletion semantics. Looping delete_item()
 * preserves per-item capability/column reduction, meta cleanup, cache invalidation,
 * and the {item}_deleted action; a bulk SQL DELETE would skip all of it.
 *
 * The input is resolved three ways (see resolve_primary_keys()):
 *  - a single scalar                 -> that one id (a single-column key)
 *  - an integer-keyed list           -> those ids, or `column => value` key maps
 *  - an associative query-vars array -> compiled to a WHERE; each matched row's FULL
 *                                       primary key is selected (composite-key safe)
 *
 * An empty input, or a filter that compiles to no WHERE, resolves to no keys and
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
	 * @param int|string|array<int|string,mixed> $input A single id, a list of ids/keys, or a query-vars filter array.
	 * @return int|false Number of items deleted, or false when there was nothing to delete.
	 */
	public function delete( $input = array() ) {

		// Resolve the input to the concrete primary keys to remove.
		$keys = $this->resolve_primary_keys( $input );

		// Bail if nothing resolved (empty input, no matches, or a refused filter).
		if ( empty( $keys ) ) {
			return false;
		}

		// Remove each item through the (composite-aware) per-item primitive.
		$deleted = 0;

		foreach ( $keys as $key ) {
			if ( $this->query()->delete_item( $key ) ) {
				++$deleted;
			}
		}

		// Return the number deleted.
		return $deleted;
	}
}
