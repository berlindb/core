<?php
/**
 * Not Between Operator.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operators;

defined( 'ABSPATH' ) || exit;

/**
 * NOT BETWEEN operator — negated inclusive range comparison.
 *
 * Generates: `%s AND %s`
 *
 * Accepts an array of two values or a comma/space-separated string that is
 * split into two elements. Only the first two elements are used.
 *
 * @since 3.0.0
 */
class NotBetween extends Base {

	/**
	 * Human-readable name of this operator.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Not Between';

	/**
	 * SQL operator string used in comparisons (e.g. '=', 'IN', 'BETWEEN').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'NOT BETWEEN';

	/**
	 * Whether this is a positive (non-negating) operator.
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
	protected $multi = true;

	/**
	 * Whether this operator is intended for numeric comparisons (>, <, BETWEEN).
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = true;

	/**
	 * Generate the SQL value fragment for a NOT BETWEEN comparison.
	 *
	 * @since 3.0.0
	 *
	 * @param array<int, mixed>|string $value   Two-element array or comma/space-delimited string. Only the first two elements are used.
	 * @param '%s'|'%d'|'%f'           $pattern Optional. A wpdb::prepare() placeholder. Default '%s'.
	 *
	 * @return string Prepared SQL fragment: `low AND high`.
	 */
	public function get_value_sql( $value = null, $pattern = '%s' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database.
		if ( empty( $db ) ) {
			return '';
		}

		// Maybe split a comma- or space-delimited string into an array.
		if ( is_scalar( $value ) ) {
			$value = preg_split( '/[,\s]+/', trim( $value ) );
			$value = false !== $value
				? $value
				: array();
		}

		// Use only the first two elements.
		$value = is_array( $value )
			? array_slice( $value, 0, 2 )
			: array();

		// Bail if fewer than two values — NOT BETWEEN requires both a low and high bound.
		if ( count( $value ) < 2 ) {
			return '';
		}

		// Setup the NOT BETWEEN fragment with two placeholders.
		$not_between = $pattern . ' AND ' . $pattern;

		// Return prepared SQL fragment.
		return (string) $db->prepare( $not_between, $value );
	}
}
