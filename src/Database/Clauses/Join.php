<?php
/**
 * Join Clause.
 *
 * @package     Database
 * @subpackage  Clauses
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Clauses;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Combines per-parser JOIN fragments into the final JOIN clause list.
 *
 * The sibling of Clauses\Where. Unlike WHERE, JOINs cannot be AND/OR-ed (a JOIN
 * pre-filters rows), so there is no boolean tree here - the fragments are simply
 * flattened in parser order. This is intentionally thin today; it is the honest
 * home for the join-combination logic currently scattered elsewhere (e.g. the
 * INNER JOIN -> LEFT JOIN promotion in Traits\Parser), to consolidate as it grows.
 *
 * @since 3.1.0
 * @internal Query collaborator; built from the per-parser fragments by the builder.
 */
class Join {

	/**
	 * Per-parser JOIN fragments, keyed by parser name.
	 *
	 * @since 3.1.0
	 * @var array<string,string>
	 */
	private $join = array();

	/**
	 * Build the clause from a key-value argument array.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args See init().
	 */
	public function __construct( array $args = array() ) {
		if ( ! empty( $args ) ) {
			$this->init( $args );
		}
	}

	/**
	 * Assign constructor arguments to properties.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type array<string,string> $join Per-parser JOIN fragments, keyed by parser name.
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$join = array();

		if ( isset( $args[ 'join' ] ) && is_array( $args[ 'join' ] ) ) {
			foreach ( $args[ 'join' ] as $key => $item ) {
				if ( is_string( $key ) && is_string( $item ) ) {
					$join[ $key ] = $item;
				}
			}
		}

		$this->join = $join;
	}

	/**
	 * Combine the per-parser JOIN fragments into the JOIN clause list.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string> The JOIN fragments, in parser order.
	 */
	public function get_clauses(): array {
		return array_values( $this->join );
	}
}
