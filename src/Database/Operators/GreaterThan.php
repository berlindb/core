<?php
/**
 * Greater Than Operator.
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
 * Greater Than operator (>).
 *
 * Numeric comparison. Generates a value fragment prepared for use in
 * `{column} > {value}` expressions.
 *
 * @since 3.0.0
 */
class GreaterThan extends Base {

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Greater Than';

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = '>';

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
