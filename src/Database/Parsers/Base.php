<?php
/**
 * Base Parser Class.
 *
 * @package     Database
 * @subpackage  Parsers
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database\Parsers;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for all query var parsers.
 *
 * Owns the Traits\Parser use so that concrete parsers only need to extend
 * this class. Declares get_sql_for_clause() abstract to enforce that every
 * concrete parser provides its own SQL-building logic.
 *
 * @since 3.0.0
 */
abstract class Base {

	use \BerlinDB\Database\Traits\Parser;

	/**
	 * Generate SQL JOIN and WHERE clauses for a first-order query clause.
	 *
	 * "First-order" means that it's an array with a recognised first-order key
	 * (e.g. 'value', 'year', 'key') rather than a nested sub-query.
	 *
	 * @since 3.0.0
	 *
	 * @param array  $clause       Query clause (passed by reference).
	 * @param array  $parent_query Parent query array.
	 * @param string $clause_key   Optional. The array key used to name the clause
	 *                             in the original query parameters. If not provided,
	 *                             a key will be generated automatically.
	 *
	 * @return array {
	 *     Array containing JOIN and WHERE SQL clauses to append to the main query.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	abstract public function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' );
}
