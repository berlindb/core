<?php
/**
 * Compare Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Parsers
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Parsers;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class used for generating SQL for arbitrary column comparison clauses.
 *
 * This class generates SQL when `key` and `value` arguments are passed,
 * supporting all standard comparison operators via the `compare` key.
 * All required methods are provided by the Traits\Parser trait via Base.
 *
 * @since 3.0.0
 */
class Compare extends Base {

	/**
	 * Internal identifier for this parser.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'compare';

	/**
	 * Top-level query var key this parser consumes, or null when operating per-column.
	 *
	 * @since 3.0.0
	 * @var string|null
	 */
	protected $query_var = 'compare_query';

	/**
	 * Column filter passed to get_column_names() to select relevant columns.
	 *
	 * @since 3.0.0
	 * @var array<string, bool>
	 */
	protected $column_filter = array( 'primary' => true );

	/**
	 * Suffix appended to each matching column name to form the per-column query var key.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $column_suffix = '_compare';

	/**
	 * Default value for the query var. Null defers to Query::$query_var_default_value.
	 *
	 * @since 3.0.0
	 * @var mixed
	 */
	protected $default = null;

	/**
	 * Determines and validates what first-order keys to use.
	 *
	 * @since 3.0.0
	 *
	 * @param list<string> $first_keys Array of first-order keys.
	 *
	 * @return list<string> The first-order keys.
	 */
	protected function get_first_keys( $first_keys = array() ) {
		return array( 'key', 'value' );
	}

	/**
	 * Scope this parser to its own 'compare_query' sub-array.
	 *
	 * Mirrors Meta::parse_query_vars(): a parser whose own query var is unset
	 * is handed the ENTIRE query_vars array by
	 * Query::parse_join_where_parsers(). Because Compare's first-order keys
	 * ('key', 'value') are identical to the Meta parser's, an unscoped Compare
	 * would walk a sibling parser's clauses — e.g. a meta_query whose key
	 * collides with a real column name — and emit a spurious comparison against
	 * the main table, narrowing results incorrectly. Reading only the
	 * compare_query clauses keeps each parser to its own input.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $qv The raw query vars.
	 *
	 * @return array<string, mixed> The compare_query clause array, or empty.
	 */
	protected function parse_query_vars( $qv = array() ) {

		// Passthrough non-arrays untouched.
		if ( ! is_array( $qv ) ) {
			return $qv;
		}

		/*
		 * When the full query_vars are passed (the 'compare_query' key is still
		 * present), operate ONLY on its sub-array — never the sibling vars. When
		 * the caller already narrowed to the compare_query clauses, that key is
		 * absent, so pass the clauses through unchanged.
		 */
		if ( array_key_exists( 'compare_query', $qv ) ) {
			return is_array( $qv[ 'compare_query' ] )
				? $qv[ 'compare_query' ]
				: array();
		}

		return $qv;
	}
}
