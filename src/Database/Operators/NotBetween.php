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
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Not Between';

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'NOT BETWEEN';

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = false;

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $multi = true;

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = true;

	/**
	 * Generate the SQL value fragment for a NOT BETWEEN comparison.
	 *
	 * @since 3.0.0
	 *
	 * @param array|string $value   Two-element array or comma/space-delimited string. Only the first two elements are used.
	 * @param string       $pattern Optional. A wpdb::prepare() placeholder. Default '%s'.
	 *
	 * @return string Prepared SQL fragment: `low AND high`.
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

		// Setup the NOT BETWEEN fragment with two placeholders.
		$not_between = "{$pattern} AND {$pattern}";

		// Use only the first two elements.
		$value = array_slice( $value, 0, 2 );

		// Return prepared SQL fragment.
		return $db->prepare( $not_between, $value );
	}
}
