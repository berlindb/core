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
}
