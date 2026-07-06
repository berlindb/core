<?php
/**
 * Between Operator.
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
 * BETWEEN operator - inclusive range comparison.
 *
 * Generates: `%s AND %s`
 *
 * Accepts an array of two values or a comma/space-separated string that is
 * split into two elements. Only the first two elements are used.
 *
 * @since 3.0.0
 */
class Between extends Base {

	/**
	 * Human-readable name of this operator.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Between';

	/**
	 * SQL operator string used in comparisons (e.g. '=', 'IN', 'BETWEEN').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'BETWEEN';

	/**
	 * Whether this is a positive (non-negating) operator.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = true;

	/**
	 * The $compare of this operator's logical opposite.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $opposite_compare = 'NOT BETWEEN';

	/**
	 * Whether this operator takes a two-bound RANGE, rendered `a AND b` (BETWEEN /
	 * NOT BETWEEN). A Range operand pairs with it.
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $range = true;

	/**
	 * Whether this operator is intended for numeric comparisons (>, <, BETWEEN).
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = true;

	/**
	 * Generate the SQL value fragment for a BETWEEN comparison.
	 *
	 * @since 3.0.0
	 *
	 * @param array<int,mixed>|string $value   Two-element array or comma/space-delimited string. Only the first two elements are used.
	 * @param '%s'|'%d'|'%f'          $pattern Optional. A wpdb::prepare() placeholder. Default '%s'.
	 *
	 * @return string Prepared SQL fragment: `low AND high`.
	 */
	public function get_value_sql( $value = null, $pattern = '%s' ) {

		// Maybe split a comma- or space-delimited string into an array.
		$value = $this->split_value_list( $value );

		// Use only the first two elements.
		$value = is_array( $value )
			? array_slice( $value, 0, 2 )
			: array();

		// Bail if fewer than two values - BETWEEN requires both a low and high bound.
		if ( count( $value ) < 2 ) {
			return '';
		}

		// Setup the SQL fragment.
		$between = $pattern . ' AND ' . $pattern;

		// Return prepared SQL fragment.
		return (string) $this->db()->prepare( $between, $value );
	}
}
