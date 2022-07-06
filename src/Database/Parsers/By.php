<?php
/**
 * In Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Compare
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database\Parsers;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Class used for generating SQL for NOT IN clauses.
 *
 * This class is used to generate the SQL when a `compare` argument is passed to
 * the `Base` query class. It extends `Meta` so the `compare` key accepts
 * the same parameters as the ones passed to `Meta`.
 *
 * @since 3.0.0
 */
class By {

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
		$ins        = (array) $this->caller( 'get_columns', array(), 'and', 'name' );

		foreach ( $ins as $in ) {
			$first_keys[] = $in;
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

		// Get the database interface.
		$db  = $this->get_db();

		// Get __in's in clause.
		$ins = $this->get_first_order_clauses( $clause );

		// Bail if no database or first-order clauses.
		if ( empty( $db ) || empty( $ins ) ) {
			return array(
				'join'  => array(),
				'where' => array()
			);
		}

		// Default where array.
		$where = array();

		// Loop through ins.
		foreach ( $ins as $column => $query_var ) {

			// Parse query var
			$values = $this->caller( 'parse_query_var', $clause, $column );

			// Parse item for an IN clause.
			if ( false === $values ) {
				continue;
			}

			// Get pattern and aliased name
			$pattern = $this->caller( 'get_column_field', array( 'name' => $column ), 'pattern', '%s' );
			$aliased = $this->caller( 'get_column_name_aliased', $column );

			// Convert single item arrays to literal column comparisons
			if ( 1 === count( $values ) ) {
				$statement        = "{$aliased} = {$pattern}";
				$column_value     = reset( $values );
				$where[ $column ] = $db->prepare( $statement, $column_value );

			// Implode
			} else {
				$in_values                = $this->caller( 'get_in_sql', $column, $values, true, $pattern );
				$where[ "{$column}__in" ] = "{$aliased} IN {$in_values}";
			}
		}

		// Return join/where array.
		return array(
			'join'  => array(),
			'where' => $where
		);
	}
}
