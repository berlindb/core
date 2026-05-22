<?php
/**
 * Less Than Operator.
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
 * Less Than operator (<).
 *
 * Numeric comparison. Generates a value fragment prepared for use in
 * `{column} < {value}` expressions.
 *
 * @since 3.0.0
 */
class LessThan extends Base {

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Less Than';

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = '<';

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = true;

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $multi = false;

	/**
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = true;
}
