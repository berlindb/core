<?php
/**
 * Not Equal Operator.
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
 * Not Equal operator (!=).
 *
 * Generates a value fragment prepared for use in `{column} != {value}`
 * expressions.
 *
 * @since 3.0.0
 */
class NotEqual extends Base {

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Not Equal';

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = '!=';

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
}
