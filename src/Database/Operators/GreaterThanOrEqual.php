<?php
/**
 * Greater Than Or Equal Operator.
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
 * Greater Than Or Equal operator (>=).
 *
 * Numeric comparison. Generates a value fragment prepared for use in
 * `{column} >= {value}` expressions.
 *
 * @since 3.0.0
 */
class GreaterThanOrEqual extends Base {

	/**
	 * Human-readable name of this operator.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Greater Than Or Equal';

	/**
	 * SQL operator string used in comparisons (e.g. '=', 'IN', 'BETWEEN').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = '>=';

	/**
	 * Whether this is a positive (non-negating) operator.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = true;

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
	protected $numeric = true;
}
