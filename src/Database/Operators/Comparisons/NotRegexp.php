<?php
/**
 * Not Regexp Operator.
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
 * NOT REGEXP operator - negated regular expression match.
 *
 * Generates a value fragment prepared for use in `{column} NOT REGEXP {pattern}`
 * expressions. The value is passed as-is to wpdb::prepare().
 *
 * @since 3.0.0
 */
class NotRegexp extends Base {

	/**
	 * Human-readable name of this operator.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Not Regexp';

	/**
	 * SQL operator string used in comparisons (e.g. '=', 'IN', 'BETWEEN').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'NOT REGEXP';

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
	protected $opposite_compare = 'REGEXP';

	/**
	 * Whether this operator is intended for numeric comparisons (>, <, BETWEEN).
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = false;

	/**
	 * Whether this operator is a regular-expression match (REGEXP / RLIKE).
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $regex = true;
}
