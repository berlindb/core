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
class In {

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
		$ins        = (array) $this->caller( 'get_columns', array( 'in' => true ), 'and', 'name' );

		foreach ( $ins as $in ) {
			$first_keys[] = "{$in}__in";
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

			// Get pattern and aliased name
			$name    = str_replace( '__not_in', '', $column );
			$pattern = $this->caller( 'get_column_field', array( 'name' => $name ), 'pattern', '%s' );
			$aliased = $this->caller( 'get_column_name_aliased', $name );

			// Parse query var
			$values = $this->caller( 'parse_query_var', $clause, $column );

			// Parse item for an IN clause.
			if ( false !== $values ) {

				// Convert single item arrays to literal column comparisons
				if ( 1 === count( $values ) ) {
					$statement          = "{$aliased} = {$pattern}";
					$where_id           = $column;
					$column_value       = reset( $values );
					$where[ $where_id ] = $db->prepare( $statement, $column_value );

				// Implode
				} else {
					$in_values          = $this->caller( 'get_in_sql', $column, $values, true, $pattern );
					$where[ $where_id ] = "{$aliased} IN {$in_values}";
				}
			}
		}

		// Return join/where array.
		return array(
			'join'  => array(),
			'where' => $where
		);
	}

	/**
	 * Parse join/where subclauses for all columns.
	 *
	 * Used by parse_where_join().
	 *
	 * @since 3.0.0
	 * @return array
	 */
	private function parse_where_columns( $query_vars = array() ) {

		// Defaults
		$retval = array(
			'join'  => array(),
			'where' => array()
		);

		// Get the database interface
		$db = $this->get_db();

		// Bail if no database interface is available
		if ( empty( $db ) ) {
			return $retval;
		}

		// All columns
		$all_columns = $this->get_columns();

		// Bail if no columns
		if ( empty( $all_columns ) ) {
			return $retval;
		}

		// Default variable
		$where = array();

		// Loop through columns
		foreach ( $all_columns as $column ) {

			// Get column name, pattern, and aliased name
			$name    = $column->name;
			$pattern = $this->get_column_field( array( 'name' => $name ), 'pattern', '%s' );
			$aliased = $this->get_column_name_aliased( $name );

			// Literal column comparison
			if ( false !== $column->by ) {

				// Parse query variable
				$where_id = $name;
				$values   = $this->parse_query_var( $query_vars, $where_id );

				// Parse item for direct clause.
				if ( false !== $values ) {

					// Convert single item arrays to literal column comparisons
					if ( 1 === count( $values ) ) {
						$statement          = "{$aliased} = {$pattern}";
						$column_value       = reset( $values );
						$where[ $where_id ] = $db->prepare( $statement, $column_value );

					// Implode
					} else {
						$where_id           = "{$where_id}__in";
						$in_values          = $this->get_in_sql( $name, $values, true, $pattern );
						$where[ $where_id ] = "{$aliased} IN {$in_values}";
					}
				}
			}

			// __in
			if ( true === $column->in ) {

				// Parse query var
				$where_id = "{$name}__in";
				$values   = $this->parse_query_var( $query_vars, $where_id );

				// Parse item for an IN clause.
				if ( false !== $values ) {

					// Convert single item arrays to literal column comparisons
					if ( 1 === count( $values ) ) {
						$statement          = "{$aliased} = {$pattern}";
						$where_id           = $name;
						$column_value       = reset( $values );
						$where[ $where_id ] = $db->prepare( $statement, $column_value );

					// Implode
					} else {
						$in_values          = $this->get_in_sql( $name, $values, true, $pattern );
						$where[ $where_id ] = "{$aliased} IN {$in_values}";
					}
				}
			}

			// __not_in
			if ( true === $column->not_in ) {

				// Parse query var
				$where_id = "{$name}__not_in";
				$values   = $this->parse_query_var( $query_vars, $where_id );

				// Parse item for a NOT IN clause.
				if ( false !== $values ) {

					// Convert single item arrays to literal column comparisons
					if ( 1 === count( $values ) ) {
						$statement          = "{$aliased} != {$pattern}";
						$where_id           = $name;
						$column_value       = reset( $values );
						$where[ $where_id ] = $db->prepare( $statement, $column_value );

					// Implode
					} else {
						$in_values          = $this->get_in_sql( $name, $values, true, $pattern );
						$where[ $where_id ] = "{$aliased} NOT IN {$in_values}";
					}
				}
			}

			// date_query
			if ( true === $column->date_query ) {
				$where_id    = "{$name}_query";
				$column_date = $this->parse_query_var( $query_vars, $where_id );

				// Parse item
				if ( false !== $column_date ) {

					// Single
					if ( 1 === count( $column_date ) ) {
						$where['date_query'][] = array(
							'column'    => $aliased,
							'before'    => reset( $column_date ),
							'inclusive' => true
						);

					// Multi
					} else {

						// Auto-fill column if empty
						if ( empty( $column_date['column'] ) ) {
							$column_date['column'] = $aliased;
						}

						// Add clause to date query
						$where['date_query'][] = $column_date;
					}
				}
			}
		}

		// Return join/where subclauses
		return array(
			'join'  => array(),
			'where' => $where
		);
	}

}
