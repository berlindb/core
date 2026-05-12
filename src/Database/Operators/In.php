<?php
/**
 * In Operator.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database\Operators;

defined( 'ABSPATH' ) || exit;

/**
 * IN operator — membership test against a set of values.
 *
 * Generates: `IN (%s, %s, ...)`
 *
 * Accepts an array of values or a comma/space-separated string that is
 * split into individual elements before being prepared.
 *
 * @since 3.0.0
 */
class In extends Base {

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'In';

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'IN';

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = true;

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $multi = true;

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = false;

	/**
	 * Generate the SQL value fragment for an IN comparison.
	 *
	 * @since 3.0.0
	 *
	 * @param array|string $value   Array of values or a comma/space-delimited string.
	 * @param string       $pattern Optional. A wpdb::prepare() placeholder. Default '%s'.
	 *
	 * @return string Prepared SQL fragment: `(v1, v2, ...)`.
	 */
	public function get_sql( $value = null, $pattern = '%s' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database.
		if ( empty( $db ) ) {
			return '';
		}

		// Maybe split a comma- or space-delimited string into an array.
		if ( is_scalar( $value ) ) {
			$value = preg_split( '/[,\s]+/', trim( $value ) );
		}

		// Build a parenthesised placeholder list for each value.
		$in = '(' . substr( str_repeat( ",{$pattern}", count( $value ) ), 1 ) . ')';

		// Return prepared SQL fragment.
		return $db->prepare( $in, $value );
	}
}
