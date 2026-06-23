<?php
/**
 * Base Operation.
 *
 * @package     Database
 * @subpackage  Operations
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operations;

use BerlinDB\Database\Kern\Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base class for query Operations - the orchestration layer above a Query.
 *
 * An Operation drives a single high-level verb (delete a set, update a set, and
 * eventually select a set) by composing Query's existing support seams: it runs
 * the parsers + Clauses\Builder to construct JOIN/WHERE, resolves the rows it
 * acts on, and renders/executes its own verb. The Query stays the table and
 * vocabulary facade; the Operation owns the control flow.
 *
 * For now an Operation holds a concrete Query, because the parsers are
 * Query-coupled (each is instantiated with a back-reference to the Query for
 * caller()/callback resolution), so Query is currently the only valid parser and
 * callback context. When the Query God class is decomposed (#217), Base can be
 * narrowed to depend on an OperationContext interface instead, with no change to
 * the Operation call sites. See skills/berlindb/references/architecture.md.
 *
 * @since 3.1.0
 * @internal Constructed by Query (its facade methods delegate here).
 */
abstract class Base {

	/**
	 * The Query this Operation drives.
	 *
	 * @since 3.1.0
	 * @var   Query
	 */
	protected $query;

	/**
	 * Construct the Operation around a Query.
	 *
	 * @since 3.1.0
	 *
	 * @param Query $query The Query that owns the schema, parsers, and connection.
	 */
	public function __construct( Query $query ) {
		$this->query = $query;
	}

	/**
	 * Get the Query this Operation drives.
	 *
	 * @since 3.1.0
	 *
	 * @return Query
	 */
	protected function query(): Query {
		return $this->query;
	}

	/**
	 * Resolve an operation's input into a concrete list of primary IDs.
	 *
	 * The shared "which rows" resolution for the per-set verbs (Delete, Update). A
	 * scalar is one ID. An array with any string key is query-var filters and is
	 * compiled to a WHERE before the matching IDs are selected. An array with only
	 * integer keys is a list of IDs - including non-sequential ones from
	 * array_filter()/wp_list_pluck() - and is taken as-is. An empty array is an
	 * empty list of IDs (not an unfiltered "everything"), so it resolves to nothing.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $input A single ID, a list of IDs, or a query-vars filter array.
	 * @return array<int,mixed> Candidate primary IDs (each re-shaped by the per-item verb), possibly empty.
	 */
	protected function resolve_ids( $input ): array {

		// A single scalar ID.
		if ( is_scalar( $input ) ) {
			return array( $input );
		}

		// Anything that is not an array resolves to nothing.
		if ( ! is_array( $input ) ) {
			return array();
		}

		// Any string key means these are query-var filters -> compile and select IDs.
		foreach ( array_keys( $input ) as $key ) {
			if ( is_string( $key ) ) {
				return $this->query()->select_ids( $input );
			}
		}

		// Otherwise an all-integer-keyed array is a list of IDs (gaps tolerated).
		return array_values( $input );
	}
}
