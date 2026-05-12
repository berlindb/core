<?php
/**
 * Operator Trait.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database\Traits;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Trait providing shared state and default SQL-generation logic for comparison operators.
 *
 * Concrete operator classes (in the Operators/ directory) use this trait and
 * declare their descriptor properties. The default get_sql() handles all scalar
 * operators (=, !=, >, >=, <, <=, EXISTS, REGEXP, NOT REGEXP, RLIKE). Operator
 * classes with non-scalar behaviour (IN, BETWEEN, LIKE, NOT EXISTS, etc.)
 * override get_sql() directly.
 *
 * @since 3.0.0
 */
trait Operator {

	use \BerlinDB\Database\Traits\Base;

	/**
	 * Human-readable name of this operator.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = '';

	/**
	 * SQL operator string used in comparisons (e.g. '=', 'IN', 'BETWEEN').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = '';

	/**
	 * Whether this is a positive (non-negating) operator.
	 *
	 * False for NOT-prefixed operators such as '!=', 'NOT IN', 'NOT BETWEEN'.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = false;

	/**
	 * Whether this operator accepts multiple values (IN, BETWEEN).
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $multi = false;

	/**
	 * Whether this operator is intended for numeric comparisons (>, <, BETWEEN).
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = false;

	/**
	 * The SQL operator string to use when assembling a WHERE clause.
	 *
	 * Defaults to $compare. Override in operator classes where the SQL operator
	 * used during assembly differs from the identifier (e.g. EXISTS uses '=').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $sql_compare = '';

	/**
	 * Initialize the operator from an arguments array.
	 *
	 * @since 3.0.0
	 *
	 * @param array $args Key-value pairs matching operator properties.
	 */
	protected function init( $args = array() ) {
		$this->set_vars( $args );
	}

	/**
	 * Return the SQL operator string to use when assembling a WHERE clause.
	 *
	 * Falls back to $compare when $sql_compare is not explicitly set.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_sql_compare() {
		return ! empty( $this->sql_compare )
			? $this->sql_compare
			: $this->compare;
	}

	/**
	 * Generate the SQL value fragment for this operator.
	 *
	 * Default implementation for scalar operators. Returns only the value/operand
	 * side of the comparison — not the column name or the operator itself. The
	 * caller is responsible for assembling the full WHERE expression:
	 * "{column} {compare} {get_sql()}".
	 *
	 * @since 3.0.0
	 *
	 * @param mixed  $value   The value(s) to compare against.
	 * @param string $pattern Optional. A wpdb::prepare() placeholder. Default '%s'.
	 *
	 * @return string Prepared SQL value fragment.
	 */
	public function get_sql( $value = null, $pattern = '%s' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database.
		if ( empty( $db ) ) {
			return '';
		}

		// Trim scalar values before preparing.
		if ( is_scalar( $value ) ) {
			$value = trim( $value );
		}

		return $db->prepare( $pattern, $value );
	}
}
