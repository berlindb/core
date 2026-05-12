<?php
/**
 * Not Regexp Operator.
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
 * NOT REGEXP operator — negated regular expression match.
 *
 * Generates a value fragment prepared for use in `{column} NOT REGEXP {pattern}`
 * expressions. The value is passed as-is to wpdb::prepare().
 *
 * @since 3.0.0
 */
class NotRegexp extends Base {

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Not Regexp';

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'NOT REGEXP';

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
