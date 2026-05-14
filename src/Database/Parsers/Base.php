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
	 * Internal identifier for this parser.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = '';

	/**
	 * Top-level query var key this parser consumes, or null when the parser
	 * operates directly on per-column query vars (e.g. By).
	 *
	 * @since 3.0.0
	 * @var string|null
	 */
	protected $query_var = null;

	/**
	 * Column filter passed to get_column_names() to select relevant columns.
	 * An empty array means all columns are considered.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	protected $column_filter = array();

	/**
	 * Suffix appended to each matching column name to form the per-column
	 * query var key (e.g. '_search', '__in').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $column_suffix = '';

	/**
	 * Default value for the query var. Null defers to
	 * Query::$query_var_default_value.
	 *
	 * @since 3.0.0
	 * @var mixed
	 */
	protected $default = null;

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
