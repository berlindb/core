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
	private $alias = '';

	/**
	 * Normalized CAST target for the referenced column, or '' for no cast.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $cast = '';

	/**
	 * Assign constructor arguments to properties.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args {
	 *     @type ColumnObject $column The referenced column (required).
	 *     @type string       $alias  Optional. Table alias to qualify the reference. Default ''.
	 *     @type string       $cast   Optional. Normalized CAST target. Default '' (no cast).
	 * }
	 * @return void
	 */
	protected function init( array $args ): void {
		$column = $args[ 'column' ] ?? null;

		if ( $column instanceof ColumnObject ) {
			$this->column = $column;
		}

		$this->alias = isset( $args[ 'alias' ] ) ? (string) $args[ 'alias' ] : '';
		$this->cast  = isset( $args[ 'cast' ] ) ? (string) $args[ 'cast' ] : '';

		/*
		 * The compared-scalar placeholder is the column's own, folding an inline cast
		 * ( a CHAR-cast column compares lexically as '%s', not the column's own '%d' ),
		 * matching how the Operands\Cast decorator derives a non-column expression's
		 * cast. Stored as the Base $pattern property, so get_comparison_pattern() needs
		 * no override.
		 */
		if ( $this->column instanceof ColumnObject ) {
			$this->pattern = $this->column->get_pattern( $this->cast );
		}
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
	 * Return this operand's EFFECTIVE type category ('date'/'time'/'year'/
	 * 'numeric'/'string'). Used to validate a column argument against a function's
	 * accepted categories (e.g. YEAR() rejects a numeric column).
	 *
	 * The category is owned by the column; this operand only contributes its
	 * (optional) cast, which the column folds in as an override.
	 *
	 * @since 3.1.0
	 *
	 * @return string A type category (see Column::get_type_category()).
	 */
	public function get_type_category(): string {
		return $this->column->get_type_category( $this->cast );
	}
}
