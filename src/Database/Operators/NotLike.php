<?php
/**
 * Not Like Operator.
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
 * NOT LIKE operator — negated partial string match.
 *
 * The value is trimmed, escaped with wpdb::esc_like(), and wrapped in `%`
 * wildcards before being prepared. Generates: `NOT LIKE '%value%'`.
 *
 * @since 3.0.0
 */
class NotLike extends Base {

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Not Like';

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'NOT LIKE';

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = false;

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $multi = false;

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = false;

	/**
	 * Generate the SQL value fragment for a NOT LIKE comparison.
	 *
	 * @since 3.0.0
	 *
	 * @param mixed  $value   The string to search for. Trimmed, esc_like()-escaped, and wrapped in % wildcards.
	 * @param string $pattern Optional. A wpdb::prepare() placeholder. Default '%s'.
	 *
	 * @return string Prepared SQL fragment: `'%value%'`.
	 */
	public function get_value_sql( $value = null, $pattern = '%s' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database.
		if ( empty( $db ) ) {
			return '';
		}

		// Escape, trim, and wrap the value in wildcard characters.
		$value = '%' . $db->esc_like( trim( (string) $value ) ) . '%';

		// Return prepared SQL fragment.
		return (string) $db->prepare( $pattern, $value );
	}
}
