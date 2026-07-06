<?php
/**
 * Negation Logical Operator.
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
 * The NOT logical operator ( unary negation of a single fragment ).
 *
 * Unlike the AND/OR/XOR relations, NOT does not JOIN fragments - it WRAPS one in
 * `NOT ( ... )`. It is orthogonal to a group's relation ( a group has a relation and
 * may additionally be negated ), so it is a family member but is NOT registered in the
 * relation Registry. Clauses\BooleanGroup holds one when a group is negated.
 *
 * @since 3.1.0
 */
class Negation extends Base {

	/**
	 * @since 3.1.0
	 * @var string
	 */
	protected $symbol = 'NOT';

	/**
	 * @since 3.1.0
	 * @var bool
	 */
	protected $unary = true;
}
