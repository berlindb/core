<?php
/**
 * Not In Operator.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operators\Comparisons;

defined( 'ABSPATH' ) || exit;

/**
 * NOT IN operator - exclusion test against a set of values.
 *
 * Generates: `NOT IN (%s, %s, ...)`
 *
 * Accepts an array of values or a comma/space-separated string that is
 * split into individual elements before being prepared.
 *
 * @since 3.0.0
 */
class NotIn extends Base {

	/**
	 * Human-readable name of this operator.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Not In';

	/**
	 * SQL operator string used in comparisons (e.g. '=', 'IN', 'BETWEEN').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'NOT IN';

	/**
	 * Whether this is a positive (non-negating) operator.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = false;

	/**
	 * The $compare of this operator's logical opposite.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $opposite_compare = 'IN';

	/**
	 * Whether this operator takes a LIST of values, rendered `( a, b, c )` (IN /
	 * NOT IN). A Collection operand pairs with it.
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $list = true;

	/**
	 * Whether this operator is intended for numeric comparisons (>, <, BETWEEN).
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = false;

	/**
	 * Generate the SQL value fragment for a NOT IN comparison.
	 *
	 * @since 3.0.0
	 *
	 * @param array<int,mixed>|string $value   Array of values or a comma/space-delimited string.
	 * @param '%s'|'%d'|'%f'          $pattern Optional. A wpdb::prepare() placeholder. Default '%s'.
	 *
	 * @return string Prepared SQL fragment: `(v1, v2, ...)`.
	 */
	public function get_value_sql( $value = null, $pattern = '%s' ) {

		// Maybe split a comma- or space-delimited string into an array.
		$value = $this->split_value_list( $value );

		// Bail if empty - NOT IN () is invalid SQL.
		if ( empty( $value ) ) {
			return '';
		}

		// Build a parenthesised placeholder list for each value.
		$in = '(' . implode( ', ', array_fill( 0, count( $value ), $pattern ) ) . ')';

		// Return prepared SQL fragment.
		return (string) $this->db()->prepare( $in, $value );
	}
}
