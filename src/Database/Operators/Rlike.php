<?php
/**
 * Rlike Operator.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
declare( strict_types = 1 );

namespace BerlinDB\Database\Operators;

defined( 'ABSPATH' ) || exit;

/**
 * RLIKE operator — MySQL alias for REGEXP.
 *
 * Functionally identical to REGEXP. Generates a value fragment prepared for
 * use in `{column} RLIKE {pattern}` expressions.
 *
 * @since 3.0.0
 */
class Rlike extends Base {

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Rlike';

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'RLIKE';

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
	protected $numeric = false;
}
