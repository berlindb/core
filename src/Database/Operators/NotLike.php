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
 * NOT LIKE operator - negated partial string match.
 *
 * The value is trimmed, escaped with wpdb::esc_like(), and wrapped in `%`
 * wildcards before being prepared. Generates: `NOT LIKE '%value%'`.
 *
 * @since 3.0.0
 */
class NotLike extends Base {

	/**
	 * Human-readable name of this operator.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Not Like';

	/**
	 * SQL operator string used in comparisons (e.g. '=', 'IN', 'BETWEEN').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'NOT LIKE';

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
	protected $opposite_compare = 'LIKE';

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
	 * Generate the SQL value fragment for a NOT LIKE comparison.
	 *
	 * @since 3.0.0
	 *
	 * @param mixed          $value   The string to search for. Trimmed, esc_like()-escaped, and wrapped in % wildcards.
	 * @param '%s'|'%d'|'%f' $pattern Optional. A wpdb::prepare() placeholder. Default '%s'.
	 *
	 * @return string Prepared SQL fragment: `'%value%'`.
	 */
	public function get_value_sql( $value = null, $pattern = '%s' ) {

		// Bail if not scalar.
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		// Escape, trim, and wrap the value in wildcard characters.
		$value = '%' . $this->db()->esc_like( trim( (string) $value ) ) . '%';

		// Return prepared SQL fragment.
		return (string) $this->db()->prepare( $pattern, $value );
	}
}
