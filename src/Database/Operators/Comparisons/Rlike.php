<?php
/**
 * Rlike Operator.
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
 * RLIKE operator - MySQL alias for REGEXP.
 *
 * Functionally identical to REGEXP. Generates a value fragment prepared for
 * use in `{column} RLIKE {pattern}` expressions.
 *
 * @since 3.0.0
 */
class Rlike extends Base {

	/**
	 * Human-readable name of this operator.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Rlike';

	/**
	 * SQL operator string used in comparisons (e.g. '=', 'IN', 'BETWEEN').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'RLIKE';

	/**
	 * Whether this is a positive (non-negating) operator.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = true;

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
