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
 * EXISTS operator — validates that a meta key exists.
 *
 * The identifier 'EXISTS' is used for query validation and operator lookup.
 * Because `{column} EXISTS {value}` is not valid MySQL syntax, $sql_compare
 * is set to '=' so parsers correctly assemble `{column} = {value}` instead.
 *
 * @since 3.0.0
 */
class Exists extends Base {

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'Exists';

	/**
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
	protected $sql_compare = '=';

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
