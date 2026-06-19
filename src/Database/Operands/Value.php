<?php
/**
 * Value Operand.
 *
 * @package     Database
 * @subpackage  Operands
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operands;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A right-hand-side operand that is a prepared literal value.
 *
 * The explicit form of the default scalar operand. A bare scalar clause value
 * still takes the ordinary value path; this object exists so a literal can also
 * appear nested inside a function operand (e.g. `LOWER('term')`), where it is
 * one argument among others.
 *
 * The value is prepared (via wpdb::prepare) by the parser before this object is
 * built, so get_sql() simply returns the already-prepared, already-safe fragment.
 *
 * @since 3.1.0
 */
class Value extends Base {

	/**
	 * The already-prepared SQL literal fragment.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $sql;

	/**
	 * Build a value operand from an already-prepared SQL fragment.
	 *
	 * @since 3.1.0
	 *
	 * @param string $sql The prepared SQL literal (e.g. the output of wpdb::prepare).
	 */
	public function __construct( string $sql ) {
		$this->sql = $sql;
	}

	/**
	 * Return the prepared SQL literal.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_sql(): string {
		return $this->sql;
	}
}
