<?php
/**
 * Is Not Null Operator.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operators;

use BerlinDB\Database\Kern\Column;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * IS NOT NULL operator - matches rows whose column holds a non-NULL value.
 *
 * A unary postfix predicate: it takes no right-hand value, so it emits
 * `{column} IS NOT NULL` directly rather than the trait's
 * `{column} OP {value}`. The logical opposite of IsNull (via
 * $opposite_compare), but still a positive, direct predicate.
 *
 * @since 3.1.0
 */
class IsNotNull extends Base {

	/**
	 * Human-readable name of this operator.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $name = 'IsNotNull';

	/**
	 * SQL operator string used in comparisons (e.g. '=', 'IN', 'BETWEEN').
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $compare = 'IS NOT NULL';

	/**
	 * Whether this is a positive (non-negating) operator.
	 *
	 * True: `IS NOT NULL` is a direct predicate, not an anti-join. Unlike
	 * NOT IN / NOT LIKE / NOT EXISTS it needs no NOT EXISTS wrapping, so the
	 * negation-planning that keys off $positive must not treat it as negative.
	 * Its relationship to IS NULL is the separate $opposite_compare axis.
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $positive = true;

	/**
	 * The $compare of this operator's logical opposite.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $opposite_compare = 'IS NULL';

	/**
	 * Whether this operator is intended for numeric comparisons (>, <, BETWEEN).
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $numeric = false;

	/**
	 * Whether this operator is unary (takes no right-hand value).
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $unary = true;

	/**
	 * Assemble the unary predicate: `{column} IS NOT NULL`.
	 *
	 * Overrides the trait's value-driven get_sql() because a unary operator has
	 * no value operand; $value and $cast are intentionally ignored.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $col   The column being compared.
	 * @param string $alias Optional. Table alias for the column reference.
	 * @param mixed  $value Unused. Unary operators take no value.
	 * @param string $cast  Unused. A NULL test never casts.
	 *
	 * @return string The `{column} IS NOT NULL` expression.
	 */
	public function get_sql( Column $col, string $alias = '', $value = null, string $cast = '' ): string {
		return $col->get_name_sql( $alias ) . ' ' . $this->get_sql_compare();
	}

	/**
	 * Returns an empty string - a unary operator has no value fragment.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed  $value   Unused.
	 * @param string $pattern Unused.
	 *
	 * @return string Always empty string.
	 */
	public function get_value_sql( $value = null, $pattern = '%s' ) {
		return '';
	}
}
