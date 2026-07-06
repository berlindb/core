<?php
/**
 * Subtract Arithmetic Operator.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operators\Arithmetic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The - arithmetic operator.
 *
 * @since 3.1.0
 */
class Subtract extends Base {

	/**
	 * @since 3.1.0
	 * @var string
	 */
	protected $symbol = '-';

	/**
	 * @since 3.1.0
	 * @var string
	 */
	protected $pattern = '%d';
}
