<?php
/**
 * Disjunction Logical Operator.
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
 * The OR logical relation ( at least one fragment must match ).
 *
 * @since 3.1.0
 */
class Disjunction extends Base {

	/**
	 * @since 3.1.0
	 * @var string
	 */
	protected $symbol = 'OR';
}
