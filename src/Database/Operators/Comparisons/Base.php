<?php
/**
 * Base Operator Class.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operators\Comparisons;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for all comparison operator classes.
 *
 * Provides shared descriptor properties and SQL-generation behavior via
 * Traits\Operator. Concrete operator subclasses only need to declare the
 * descriptor properties that differ from the trait defaults ($name, $compare,
 * $positive, $opposite_compare, $list, $range, $numeric, $unary, $regex).
 * get_value_sql() is inherited from the trait and handles scalar operators;
 * multi-value or non-standard operators override it. get_sql() assembles the
 * full WHERE expression and lives in the trait; concrete classes rarely need
 * to override it.
 *
 * @since 3.0.0
 */
abstract class Base {

	use \BerlinDB\Database\Traits\Operator;

	/**
	 * Constructor.
	 *
	 * Allows ad-hoc instantiation and optional population from a plain array,
	 * matching the shape of entries in Traits\Parser::$operators.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $args Optional. Key-value pairs to set on the instance. Default empty.
	 */
	public function __construct( $args = array() ) {
		if ( ! empty( $args ) ) {
			$this->init( $args );
		}
	}
}
