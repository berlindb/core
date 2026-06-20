<?php
/**
 * Exists Operator.
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
 * EXISTS operator - validates that a meta key exists.
 *
 * The identifier 'EXISTS' is used for query validation and operator lookup.
 * Because `{column} EXISTS {value}` is not valid MySQL syntax, $sql_compare
 * is set to '=' so parsers correctly assemble `{column} = {value}` instead.
 *
 * @since 3.0.0
 */
class Exists extends Base {

	/**
	 * Human-readable name of this operator.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Exists';

	/**
	 * SQL operator string used in comparisons (e.g. '=', 'IN', 'BETWEEN').
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $compare = 'EXISTS';

	/**
	 * Overrides the default to '=' because `{column} EXISTS {value}` is not
	 * valid MySQL syntax. Parsers use this value when assembling WHERE clauses.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	/**
	 * SQL operator string to use when assembling a WHERE clause.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $sql_compare = '=';

	/**
	 * Whether this is a positive (non-negating) operator.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $positive = true;

	/**
	 * The $compare of this operator's logical opposite.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $opposite_compare = 'NOT EXISTS';

	/**
	 * Whether this operator accepts multiple values (IN, BETWEEN).
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $multi = false;

	/**
	 * Whether this operator is intended for numeric comparisons (>, <, BETWEEN).
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $numeric = false;
}
