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

	/**
	 * Return this operand's EFFECTIVE type category: 'date', 'numeric', or
	 * 'string'. Used to validate a column argument against a function's accepted
	 * categories (e.g. YEAR() rejects a numeric column).
	 *
	 * An explicit cast overrides the column's declared type — `CAST(name AS
	 * SIGNED)` is numeric, `CAST(ts AS DATETIME)` is date — so a casted argument is
	 * categorized by its cast target, matching the SQL get_sql() actually renders.
	 *
	 * Temporal types are split so a date-only / time-only / year column is not
	 * mistaken for a full date — `DATE(time_col)` and `DAYOFMONTH(year_col)` must
	 * fail closed. Date-bearing types (date/datetime/timestamp) report 'date';
	 * time-only reports 'time'; year reports 'year'.
	 *
	 * @since 3.1.0
	 *
	 * @return 'date'|'time'|'year'|'numeric'|'string'
	 */
	public function get_type_category(): string {

		// An explicit cast determines the effective type.
		if ( '' !== $this->cast ) {
			return $this->cast_category( $this->cast );
		}

		$type = strtolower( (string) $this->column->type );

		// Date-bearing temporal types.
		if ( in_array( $type, array( 'date', 'datetime', 'timestamp' ), true ) ) {
			return 'date';
		}

		// Time-only and year are their own categories (not date-bearing).
		if ( 'time' === $type ) {
			return 'time';
		}

		if ( 'year' === $type ) {
			return 'year';
		}

		if ( $this->column->is_numeric() ) {
			return 'numeric';
		}

		return 'string';
	}

	/**
	 * Map a normalized CAST target to a type category.
	 *
	 * @since 3.1.0
	 *
	 * @param string $cast A normalized CAST target (e.g. 'SIGNED', 'DATETIME', 'CHAR').
	 * @return 'date'|'time'|'numeric'|'string'
	 */
	private function cast_category( string $cast ): string {
		$upper = strtoupper( $cast );

		// SIGNED / UNSIGNED / DECIMAL → numeric.
		if ( str_starts_with( $upper, 'SIGNED' ) || str_starts_with( $upper, 'UNSIGNED' ) || str_starts_with( $upper, 'DECIMAL' ) ) {
			return 'numeric';
		}

		// DATE / DATETIME → date (date-bearing); TIME → time.
		if ( str_starts_with( $upper, 'DATE' ) ) {
			return 'date';
		}

		if ( str_starts_with( $upper, 'TIME' ) ) {
			return 'time';
		}

		// CHAR / BINARY → string.
		return 'string';
	}
}
