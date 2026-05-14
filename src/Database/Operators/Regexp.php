<?php
/**
 * Regexp Operator.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database\Operators;

defined( 'ABSPATH' ) || exit;

/**
 * REGEXP operator — regular expression match.
 *
 * Generates a value fragment prepared for use in `{column} REGEXP {pattern}`
 * expressions. The value is passed as-is to wpdb::prepare().
 *
 * @since 3.0.0
 */
class Regexp extends Base {

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Regexp';

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'REGEXP';

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
