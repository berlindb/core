<?php
/**
 * Operator Trait.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Kern\Column;

/**
 * Trait providing shared state and default SQL-generation logic for comparison operators.
 *
 * Concrete operator classes (in the Operators/ directory) use this trait and
 * declare their descriptor properties. The default get_value_sql() handles all
 * scalar operators (=, !=, >, >=, <, <=, EXISTS, REGEXP, NOT REGEXP, RLIKE).
 * Operator classes with non-scalar behaviour (IN, BETWEEN, LIKE, NOT EXISTS,
 * etc.) override get_value_sql() directly.
 *
 * get_sql() assembles the full WHERE expression ({column} {compare} {value})
 * using get_sql_compare() and get_value_sql(). It lives here so concrete
 * classes rarely need to override it.
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
	 * The $compare of this operator's logical opposite, or '' when it has none.
	 *
	 * Pairs each operator with its complement so callers can flip polarity
	 * without a hardcoded map: '=' <-> '!=', 'IN' <-> 'NOT IN', '>' <-> '<=',
	 * 'REGEXP' <-> 'NOT REGEXP', etc. Resolve the opposite instance by passing
	 * this value back to get_operator(). 'RLIKE' has no distinct negation class
	 * (it is a 'REGEXP' synonym), so it carries no opposite.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $opposite_compare = '';

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
	 * @param array<string,mixed> $args Key-value pairs matching operator properties.
	 */
	protected function init( array $args = array() ): void {
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
	 * Whether this is a positive (non-negating) operator.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_positive(): bool {
		return (bool) $this->positive;
	}

	/**
	 * Get the $compare of this operator's logical opposite, or '' when it has none.
	 *
	 * Pass the result back to get_operator() to resolve the opposite instance.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_opposite_compare(): string {
		return (string) $this->opposite_compare;
	}

	/**
	 * Generate the SQL value fragment for this operator.
	 *
	 * Default implementation for scalar operators. Returns only the value/operand
	 * side of the comparison — not the column name or the operator itself.
	 * Multi-value and non-standard operators (IN, BETWEEN, LIKE, NOT EXISTS, etc.)
	 * override this method in their concrete class.
	 *
	 * @since 3.0.0
	 *
	 * @param mixed          $value   The value(s) to compare against.
	 * @param '%s'|'%d'|'%f' $pattern Optional. A wpdb::prepare() placeholder. Default '%s'.
	 *
	 * @return string Prepared SQL value fragment, or empty string on failure.
	 */
	public function get_value_sql( $value = null, $pattern = '%s' ) {

		// Trim string values before preparing.
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		// Return prepared SQL fragment, or empty string if prepare() returns falsy.
		return (string) $this->db()->prepare( $pattern, $value );
	}

	/**
	 * Normalize a value into a list for multi-value operators.
	 *
	 * A scalar is split on commas and/or whitespace (e.g. "1, 2 3" => [1,2,3]);
	 * arrays pass through unchanged. Shared by the multi-value operators (IN,
	 * NOT IN, BETWEEN, NOT BETWEEN) so the split lives in one place.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value Array of values, or a comma/space-delimited string.
	 *
	 * @return mixed A list of values when $value was scalar; otherwise $value as-is.
	 */
	protected function split_value_list( $value = null ) {

		// Arrays (and other non-scalars) pass through untouched.
		if ( ! is_scalar( $value ) ) {
			return $value;
		}

		// Split a comma- or space-delimited string into a list.
		$parts = preg_split( '/[,\s]+/', trim( (string) $value ) );

		return is_array( $parts )
			? $parts
			: array();
	}

	/**
	 * Generate the full SQL WHERE expression for this operator.
	 *
	 * Assembles "{column} {compare} {value}" using the Column's own SQL
	 * representation, get_sql_compare(), and get_value_sql(). The pattern is
	 * derived from $col->pattern so callers do not need to supply it separately.
	 * Returns an empty string when get_value_sql() returns '' (e.g. NOT EXISTS).
	 *
	 * @since 3.0.0
	 *
	 * @param Column $col   The schema column providing its name, alias, and pattern.
	 * @param string $alias Optional. Table alias to prefix the column reference. Default empty.
	 * @param mixed  $value The value(s) to compare against.
	 * @param string $cast  Optional. Normalized CAST target for the column side. Default empty (no cast).
	 *
	 * @return string Full SQL expression, or empty string when not applicable.
	 */
	public function get_sql( Column $col, string $alias = '', $value = null, string $cast = '' ): string {

		// Get the prepared value fragment, deriving the pattern from the column.
		$value_sql = $this->get_value_sql( $value, $col->pattern );

		// Bail if no value fragment — operator has no value side (e.g. NOT EXISTS).
		if ( '' === $value_sql ) {
			return '';
		}

		// Assemble and return the full expression, optionally casting the column.
		return $col->get_name_sql( $alias, $cast ) . ' ' . $this->get_sql_compare() . ' ' . $value_sql;
	}
}
