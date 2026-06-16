<?php
/**
 * Search Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Search
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Parsers;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class for generating SQL clauses that filter a primary query according to
 * search and search_columns.
 *
 * @since 3.0.0
 */
class Search extends Base {

	/**
	 * Internal identifier for this parser.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'search';

	/**
	 * Top-level query var key this parser consumes, or null when operating per-column.
	 *
	 * @since 3.0.0
	 * @var string|null
	 */
	protected $query_var = 'search';

	/**
	 * Column filter passed to get_column_names() to select relevant columns.
	 *
	 * @since 3.0.0
	 * @var array<string,bool>
	 */
	protected $column_filter = array( 'searchable' => true );

	/**
	 * Suffix appended to each matching column name to form the per-column query var key.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $column_suffix = '_search';

	/**
	 * Default value for the query var. Null defers to Query::$query_var_default_value.
	 *
	 * @since 3.0.0
	 * @var mixed
	 */
	protected $default = '';

	/**
	 * Generate SQL WHERE clauses for a first-order query clause.
	 *
	 * "First-order" means that it's an array with a 'key' or 'value'.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $clause       Query clause (passed by reference).
	 * @param array<string,mixed> $parent_query Parent query array.
	 * @param string              $clause_key   Optional. The array key used to name the clause in the original
	 *                                          query parameters. If not provided, a key will be generated automatically.
	 * @return array{join: list<string>, where: list<string>} {
	 *     Array containing WHERE SQL clauses to append to a first-order query.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	protected function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {

		// Bail if no search.
		if ( empty( $this->first_keys ) || empty( $clause[ 'search' ] ) ) {
			return array(
				'join'  => array(),
				'where' => array(),
			);
		}

		// Default value.
		$where = array();

		// Default to all searchable columns.
		$search_columns = $this->first_keys;

		// Intersect against known searchable columns.
		if ( ! empty( $clause[ 'search_columns' ] ) ) {
			$search_columns = array_values(
				array_intersect(
					array_filter( (array) $clause[ 'search_columns' ], 'is_string' ),
					$this->first_keys
				)
			);
		}

		// Filter search columns.
		$search_columns = $this->filter_search_columns( $search_columns );

		// Strip the _search suffix and get the aliased SQL column names.
		$sql_columns = array();
		foreach ( $search_columns as $key ) {
			$name = $this->strip_column_suffix( $key );

			if ( false === $name ) {
				continue;
			}

			$aliased = $this->get_column_sql( $name );

			if ( '' === $aliased ) {
				continue;
			}

			$sql_columns[] = $aliased;
		}

		// Add search query clause.
		$where[ 'search' ] = $this->get_search_sql( $clause[ 'search' ], $sql_columns );

		// Return join/where.
		return array(
			'join'  => array(),
			'where' => array_values( $where ),
		);
	}

	/**
	 * Used internally to generate an SQL string for searching across multiple
	 * columns.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Bail early if parameters are empty.
	 *
	 * @param string       $search       Search term.
	 * @param list<string> $column_names Columns to search.
	 * @return string Search SQL.
	 */
	private function get_search_sql( $search = '', $column_names = array() ): string {

		// Bail if malformed search term.
		if ( empty( $search ) || ! is_scalar( $search ) ) {
			return '';
		}

		// Bail if malformed columns.
		if ( empty( $column_names ) || ! is_array( $column_names ) ) {
			return '';
		}

		$db = $this->db();

		// Array or String.
		$like = ( false !== strpos( $search, '*' ) )
			? '%' . implode( '%', array_map( array( $db, 'esc_like' ), explode( '*', $search ) ) ) . '%'
			: '%' . $db->esc_like( $search ) . '%';

		// Default array.
		$searches = array();

		// Build search SQL.
		foreach ( $column_names as $column ) {
			$searches[] = $db->prepare( "{$column} LIKE %s", $like );
		}

		// Concatenate.
		$values = implode( ' OR ', $searches );
		$retval = '(' . $values . ')';

		// Return the clause.
		return $retval;
	}

	/**
	 * Filters the columns to search by.
	 *
	 * @since 3.0.0
	 *
	 * @param list<string> $search_columns All of the columns to search.
	 * @return list<string>
	 */
	public function filter_search_columns( $search_columns = array() ) {

		// Bail if no caller to fire the filter through.
		if ( empty( $this->caller ) ) {
			return $search_columns;
		}

		// Generate filter name based on the plural item name, with prefix if set.
		$plural_name = $this->caller->get_item_name_plural();

		// Bail if filter name is empty.
		$filter_name = $this->apply_prefix( $plural_name . '_search_columns' );
		if ( '' === $filter_name ) {
			return $search_columns;
		}

		/**
		 * Filters the columns to search by.
		 *
		 * @since 1.0.0
		 * @since 3.0.0 Uses apply_filters_ref_array() instead of apply_filters()
		 *
		 * @param array $search_columns Array of column names to be searched.
		 * @param \BerlinDB\Database\Query $query Current query instance.
		 */
		$retval = (array) apply_filters_ref_array(
			$filter_name,
			array(
				$search_columns,
				&$this,
			)
		);

		// Return only string values.
		return array_values(
			array_filter( $retval, 'is_string' )
		);
	}
}
