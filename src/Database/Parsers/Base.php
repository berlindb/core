<?php
/**
 * Base Parser Class.
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
 * Abstract base class for all query var parsers.
 *
 * Owns the Traits\Parser use so that concrete parsers only need to extend
 * this class. Traits\Parser provides a Column-aware default implementation of
 * get_sql_for_clause(); concrete parsers override it only when they require
 * specialised JOIN logic, type casting, or column handling.
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
	 * @var array<string, bool>
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
	 * Whether this parser contributes ORDER BY SQL via get_orderby_sql().
	 * Parsers that override get_orderby_sql() should set this to true so
	 * Query::get_parsers( array( 'sortable' => true ) ) can find them
	 * without iterating every registered parser.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	public $sortable = false;

	/** Methods ***************************************************************/

	/**
	 * Return the top-level query var key this parser consumes.
	 *
	 * Returns null for parsers that operate on per-column query vars (e.g. By).
	 *
	 * @since 3.0.0
	 * @api
	 *
	 * @return string|null
	 */
	public function get_query_var() {
		return $this->query_var;
	}

	/**
	 * Get the default operator class list.
	 *
	 * This is filterable so individual parser families can register custom
	 * operators without replacing the shared parser contract.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	protected function get_operator_classes() {

		// Default set of operator classes.
		$operators = array(
			'BerlinDB\\Database\\Operators\\Between',
			'BerlinDB\\Database\\Operators\\Equal',
			'BerlinDB\\Database\\Operators\\Exists',
			'BerlinDB\\Database\\Operators\\GreaterThan',
			'BerlinDB\\Database\\Operators\\GreaterThanOrEqual',
			'BerlinDB\\Database\\Operators\\In',
			'BerlinDB\\Database\\Operators\\LessThan',
			'BerlinDB\\Database\\Operators\\LessThanOrEqual',
			'BerlinDB\\Database\\Operators\\Like',
			'BerlinDB\\Database\\Operators\\NotBetween',
			'BerlinDB\\Database\\Operators\\NotEqual',
			'BerlinDB\\Database\\Operators\\NotExists',
			'BerlinDB\\Database\\Operators\\NotIn',
			'BerlinDB\\Database\\Operators\\NotLike',
			'BerlinDB\\Database\\Operators\\NotRegexp',
			'BerlinDB\\Database\\Operators\\Regexp',
			'BerlinDB\\Database\\Operators\\Rlike',
		);

		/**
		 * Filter the default operator class list.
		 *
		 * @since 3.0.0
		 * @param string[] $operators Array of fully-qualified Operator class names.
		 * @param Base     $parser    Current Parser instance.
		 */
		return (array) apply_filters_ref_array(
			'berlindb_database_operator_classes',
			array(
				$operators,
				&$this,
			)
		);
	}

	/**
	 * Populate $this->operators with one shared instance per Operator class.
	 *
	 * Defined here on the concrete base class (not in Traits\Parser) so that
	 * the static cache is scoped to this class definition and shared across
	 * all subclasses, giving a true per-process singleton. A static variable
	 * inside a trait method gets one copy per using class, which would cause
	 * all 17 operators to be re-instantiated for each of the 7 parser classes.
	 *
	 * @since 3.0.0
	 */
	protected function set_operators(): void {
		static $instances = array();

		$classes = $this->get_operator_classes();
		$key     = md5( maybe_serialize( $classes ) );

		if ( ! isset( $instances[ $key ] ) ) {
			$instances[ $key ] = array();

			foreach ( $classes as $class ) {
				if ( ! class_exists( $class ) ) {
					continue;
				}

				$instances[ $key ][] = new $class();
			}
		}

		// Set operators.
		$this->operators = $instances[ $key ];
	}
}
