<?php
/**
 * Compare Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Parsers
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */
namespace BerlinDB\Database\Parsers;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Class used for generating SQL for arbitrary column comparison clauses.
 *
 * This class generates SQL when `key` and `value` arguments are passed,
 * supporting all standard comparison operators via the `compare` key.
 * It extends `Meta` to reuse its JOIN and value-building infrastructure.
 *
 * @since 3.0.0
 */
class Compare extends Meta {

	/**
	 * Determines and validates what first-order keys to use.
	 *
	 * @since 3.0.0
	 *
	 * @param array $first_keys Array of first-order keys.
	 *
	 * @return array The first-order keys.
	 */
	protected function get_first_keys( $first_keys = array() ) {
		return array( 'key', 'value' );
	}

	/**
	 * Generate SQL WHERE clauses for a first-order query clause.
	 *
	 * "First-order" means that it's an array with a 'key' or 'value'.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $clause       Query clause (passed by reference).
	 * @param array  $parent_query Parent query array.
	 * @param string $clause_key   Optional. The array key used to name the clause in the original
	 *                             query parameters. If not provided, a key will be generated automatically.
	 * @return array {
	 *     Array containing WHERE SQL clauses to append to a first-order query.
	 *
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	public function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {

		// Default return value.
		$retval = array(
			'where' => array(),
			'join'  => array(),
		);

		// Maybe format compare clause.
		if ( isset( $clause['compare'] ) ) {
			$clause['compare'] = strtoupper( $clause['compare'] );

		// Or set compare clause based on value.
		} else {
			$clause['compare'] = isset( $clause['value'] ) && is_array( $clause['value'] )
				? 'IN'
				: '=';
		}

		// Get all comparison operators.
		$all_compares = $this->get_operators();

		// Fallback to equals
		if ( ! in_array( $clause['compare'], $all_compares, true ) ) {
			$clause['compare'] = '=';
		}

		// Uppercase or equals
		if ( isset( $clause['compare_key'] ) && ( 'LIKE' === strtoupper( $clause['compare_key'] ) ) ) {
			$clause['compare_key'] = strtoupper( $clause['compare_key'] );
		} else {
			$clause['compare_key'] = '=';
		}

		// Get comparison from clause
		$compare = $clause['compare'];

		/** Build the WHERE clause ********************************************/

		// Column name (sanitised) and value.
		if ( array_key_exists( 'key', $clause ) && array_key_exists( 'value', $clause ) ) {
			$name   = $this->sanitize_column_name( $clause['key'] );
			$column = $this->caller( 'get_column_name_aliased', $name ) ?? $name;
			$where  = $this->build_value( $compare, $clause['value'], '%s' );

			// Maybe add column, compare, & where to return value.
			if ( ! empty( $where ) ) {
				$retval['where'][] = "{$column} {$compare} {$where}";
			}
		}

		/*
		 * Multiple WHERE clauses should be joined in parentheses.
		 */
		if ( 1 < count( $retval['where'] ) ) {
			$retval['where'] = array( '( ' . implode( ' AND ', $retval['where'] ) . ' )' );
		}

		// Return join/where array.
		return $retval;
	}
}
