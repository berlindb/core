<?php
/**
 * Search Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Search
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database\Parsers;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Class for generating SQL clauses that filter a primary query according to
 * search and search_columns.
 *
 * @since 3.0.0
 */
class Search {

	use \BerlinDB\Database\Traits\Parser;

	/**
	 * Determines and validates what first-order keys to use.
	 *
	 * Use first $first_keys if passed and valid.
	 *
	 * @since 3.0.0
	 *
	 * @param array $first_keys Array of first-order keys.
	 *
	 * @return array The first-order keys.
	 */
	protected function get_first_keys( $first_keys = array() ) {
		$first_keys = array();
		$not_ins    = (array) $this->caller( 'get_columns', array( array( 'searchable' => true ), 'and', 'name' ) );

		foreach ( $not_ins as $not_in ) {
			$first_keys[] = "{$not_in}_search";
		}

		return $first_keys;
	}

	/**
	 * Generate SQL WHERE clauses for a first-order query clause.
	 *
	 * "First-order" means that it's an array with a 'key' or 'value'.
	 *
	 * @since 3.0.0
	 *
	 * @param array  $clause       Query clause (passed by reference).
	 * @param array  $parent_query Parent query array.
	 * @param string $clause_key   Optional. The array key used to name the clause in the original `$meta_query`
	 *                             parameters. If not provided, a key will be generated automatically.
	 * @return array {
	 *     Array containing WHERE SQL clauses to append to a first-order query.
	 *
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	public function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {

		// Bail if no search
		if ( empty( $this->first_keys ) || empty( $clause['search'] ) ) {
			return array(
				'join'  => array(),
				'where' => array()
			);
		}

		// Default value
		$where = array();

		// Default to all searchable columns
		$search_columns = $this->first_keys;

		// Intersect against known searchable columns
		if ( ! empty( $clause['search_columns'] ) ) {
			$search_columns = array_intersect(
				$clause['search_columns'],
				$this->first_keys
			);
		}

		// Filter search columns
		$search_columns = $this->filter_search_columns( $search_columns );

		// Add search query clause
		$where['search'] = $this->get_search_sql( $clause['search'], $search_columns );

		// Return join/where
		return array(
			'join'  => array(),
			'where' => $where
		);
	}

	/**
	 * Used internally to generate an SQL string for searching across multiple
	 * columns.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Bail early if parameters are empty.
	 *
	 * @param string $string       Search string.
	 * @param array  $column_names Columns to search.
	 * @return string Search SQL.
	 */
	private function get_search_sql( $string = '', $column_names = array() ) {

		// Bail if malformed string
		if ( empty( $string ) || ! is_scalar( $string ) ) {
			return '';
		}

		// Bail if malformed columns
		if ( empty( $column_names ) || ! is_array( $column_names ) ) {
			return '';
		}

		// Get the database interface
		$db = $this->get_db();

		// Bail if no database interface is available
		if ( empty( $db ) ) {
			return '';
		}

		// Array or String
		$like = ( false !== strpos( $string, '*' ) )
			? '%' . implode( '%', array_map( array( $db, 'esc_like' ), explode( '*', $string ) ) ) . '%'
			: '%' . $db->esc_like( $string ) . '%';

		// Default array
		$searches = array();

		// Build search SQL
		foreach ( $column_names as $column ) {
			$searches[] = $db->prepare( "{$column} LIKE %s", $like );
		}

		// Concatinate
		$values = implode( ' OR ', $searches );
		$retval = '(' . $values . ')';

		// Return the clause
		return $retval;
	}

	/**
	 * Filters the columns to search by.
	 *
	 * @since 3.0.0
	 *
	 * @param array $search_columns All of the columns to search.
	 * @return array
	 */
	public function filter_search_columns( $search_columns = array() ) {

		/**
		 * Filters the columns to search by.
		 *
		 * @since 1.0.0
		 * @since 3.0.0 Uses apply_filters_ref_array() instead of apply_filters()
		 *
		 * @param array $search_columns Array of column names to be searched.
		 * @param Query &$this          Current instance passed by reference.
		 */
		return (array) apply_filters_ref_array(
			$this->apply_prefix( "{$this->caller->item_name_plural}_search_columns" ),
			array(
				$search_columns,
				&$this
			)
		);
	}
}
