<?php
/**
 * Not In Query Var Parser Class.
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
 * Class used for generating SQL for NOT IN clauses.
 *
 * This class handles the `{column}__not_in` query vars, generating SQL NOT IN
 * clauses for columns where the `not_in` schema property is true.
 *
 * @since 3.0.0
 */
class NotIn extends Base {

	/**
	 * Internal identifier for this parser.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'not_in';

	/**
	 * Top-level query var key this parser consumes, or null when operating per-column.
	 *
	 * @since 3.0.0
	 * @var string|null
	 */
	protected $query_var = 'not_in_query';

	/**
	 * Column filter passed to get_column_names() to select relevant columns.
	 *
	 * @since 3.0.0
	 * @var array<string, bool>
	 */
	protected $column_filter = array( 'not_in' => true );

	/**
	 * Suffix appended to each matching column name to form the per-column query var key.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $column_suffix = '__not_in';

	/**
	 * Default value for the query var. Null defers to Query::$query_var_default_value.
	 *
	 * @since 3.0.0
	 * @var mixed
	 */
	protected $default = null;

	/**
	 * Generate SQL WHERE clauses for a first-order query clause.
	 *
	 * "First-order" means that it's an array with a 'key' or 'value'.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $clause       Query clause (passed by reference).
	 * @param array<string, mixed> $parent_query Parent query array.
	 * @param string               $clause_key   Optional. The array key used to name the clause in the original
	 *                                           query parameters. If not provided, a key will be generated automatically.
	 * @return array{join: list<string>, where: list<string>} {
	 *     Array containing WHERE SQL clauses to append to a first-order query.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	public function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {

		// Get __in's in clause.
		$ins = $this->get_first_order_clauses( $clause );

		// Bail if no first-order clauses.
		if ( empty( $ins ) ) {
			return array(
				'join'  => array(),
				'where' => array(),
			);
		}

		// Default where array.
		$where = array();

		// Loop through ins.
		foreach ( array_keys( $ins ) as $column ) {

			// Parse query var.
			$values = $this->caller( 'parse_query_var', $clause, $column );

			// Skip if parse fails.
			if ( false === $values ) {
				continue;
			}

			// Make sure $values is an array.
			$values = (array) $values;

			// Recover the column name; skip keys without this parser's suffix.
			$name = $this->strip_column_suffix( $column );

			if ( false === $name ) {
				continue;
			}

			// Get the pattern.
			$pattern = (string) $this->caller( 'get_column_field', array( 'name' => $name ), 'pattern', '%s' );

			// Get the aliased column name for SQL.
			$aliased = (string) $this->caller( 'get_quoted_column_name_aliased', $name );

			// Convert single item arrays to literal column comparisons.
			if ( 1 === count( $values ) ) {
				$statement      = "{$aliased} != {$pattern}";
				$column_value   = reset( $values );
				$where[ $name ] = (string) $this->db()->prepare( $statement, $column_value );

				// Implode.
			} else {
				$in_values        = (string) $this->caller( 'get_in_sql', $name, $values, true, $pattern );
				$where[ $column ] = "{$aliased} NOT IN {$in_values}";
			}
		}

		// Return join/where array.
		return array(
			'join'  => array(),
			'where' => array_values( $where ),
		);
	}
}
