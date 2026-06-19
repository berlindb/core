<?php
/**
 * Column Operand.
 *
 * @package     Database
 * @subpackage  Operands
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operands;

use BerlinDB\Database\Kern\Column as ColumnObject;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A right-hand-side operand that references another column.
 *
 * Enables column-to-column comparisons such as `first_name = last_name`. The
 * referenced column is resolved and validated against the schema by the parser
 * before this object is built, so get_sql() simply emits the column's own quoted
 * (optionally cast) SQL reference via Column::get_name_sql().
 *
 * @since 3.1.0
 * @internal Parser collaborator; see Operands\Base.
 */
class Column extends Base {

	/**
	 * The referenced column (already schema-validated by the parser).
	 *
	 * @since 3.1.0
	 * @var ColumnObject
	 */
	private $column;

	/**
	 * Table alias used to qualify the column reference.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $alias;

	/**
	 * Normalized CAST target for the referenced column, or '' for no cast.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $cast;

	/**
	 * Build a column operand.
	 *
	 * @since 3.1.0
	 *
	 * @param ColumnObject $column The referenced column.
	 * @param string       $alias  Optional. Table alias to qualify the reference. Default empty.
	 * @param string       $cast   Optional. Normalized CAST target. Default empty (no cast).
	 */
	public function __construct( ColumnObject $column, string $alias = '', string $cast = '' ) {
		$this->column = $column;
		$this->alias  = $alias;
		$this->cast   = $cast;
	}

	/**
	 * Render the referenced column as its quoted, optionally cast, SQL reference.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_sql(): string {
		return $this->column->get_name_sql( $this->alias, $this->cast );
	}

	/**
	 * Return the referenced column's own prepare() placeholder, so a scalar
	 * compared against this column is prepared with the matching type.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_comparison_pattern(): string {
		return $this->column->pattern;
	}
}
