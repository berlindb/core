<?php
/**
 * Conjunction Logical Operator.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operators\Logical;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The AND logical relation ( every fragment must match ).
 *
 * @since 3.1.0
 */
class Conjunction extends Base {

	/**
	 * @since 3.1.0
	 * @var string
	 */
	protected $symbol = 'AND';
}
