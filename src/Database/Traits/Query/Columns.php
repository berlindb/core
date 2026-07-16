<?php
/**
 * Query Columns Trait Class.
 *
 * @package     Database
 * @subpackage  Query
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Kern\Column;

/**
 * The column-accessor API for a Query.
 *
 * Composed onto Query, sharing its $this run-state. Reads the Schema's Column
 * objects and exposes them by name, field, or filter (get_columns,
 * get_column_by, get_column_names, get_primary_column_name, get_column_field,
 * get_columns_field_by), plus the identifier quoting/aliasing helpers used to
 * build SQL and get_in_sql (a column's prepared `IN ( ... )` fragment).
 * is_valid_column is the exact-match injection guard for raw column names.
 *
 * @since 3.1.0
 */
trait Columns {

	/**
	 * Is a column valid?
	 *
	 * @since 3.0.0
	 * @param string $column_name Column name.
	 * @return bool
	 */
	private function is_valid_column( $column_name = '' ): bool {

		// Bail if column name not valid string.
		if ( empty( $column_name ) || ! is_string( $column_name ) ) {
			return false;
		}

		/*
		 * Exact match on purpose: this gates a column name that flows into SQL
		 * downstream as given. Schema::has_column() normalizes its input (e.g.
		 * "id-- " sanitizes to "id"), which would validate a string that is then
		 * interpolated verbatim - so validation must match the raw name exactly.
		 */
		return ( $this->get_column_by( array( 'name' => $column_name ) ) instanceof Column );
	}

	/**
	 * Return array of column names.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Pass $args and $operator to filter names.
	 *              No longer calls array_flip().
	 *
	 * @param array<string,mixed> $args     Arguments to filter columns by.
	 * @param string               $operator Optional. The logical operation to perform.
	 * @return list<string>
	 */
	public function get_column_names( $args = array(), $operator = 'and' ) {
		return array_values( array_filter( $this->get_columns( $args, $operator, 'name' ), 'is_string' ) );
	}

	/**
	 * Return the primary database column name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Default "id", Primary column name if not empty
	 */
	public function get_primary_column_name() {

		// Prefer the schema's own answer when it exposes one.
		if ( is_callable( array( $this->schema_object, 'get_primary_column_name' ) ) ) {

			/** @var string $name */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$name = $this->schema_object->get_primary_column_name();

			return $name;
		}

		// Fall back to the primary-flagged column for a get_columns()-only schema.
		return $this->get_column_field( array( 'primary' => true ), 'name', 'id' );
	}

	/**
	 * Return this query's primary key column name(s), in schema order.
	 *
	 * The single authority for key discovery: every primary-flagged column, or - when
	 * no column carries the flag - the schema's designated single primary. One name
	 * for a normal single-column key; more than one for a composite key. Callers that
	 * need to address a row derive from here rather than re-resolving the fallback.
	 *
	 * @since 3.1.0
	 *
	 * @return string[] Ordered primary column name(s) (empty only when none resolve).
	 */
	public function get_primary_column_names(): array {

		// Prefer the primary-flagged columns, in schema order.
		$names = $this->get_column_names( array( 'primary' => true ) );

		// Fall back to the single designated primary when no column carries the flag.
		if ( empty( $names ) ) {
			$primary = $this->get_primary_column_name();

			if ( '' !== $primary ) {
				$names[] = $primary;
			}
		}

		return $names;
	}

	/**
	 * Get a column from an array of arguments.
	 *
	 * @since 1.0.0
	 *
	 * @template TDefault
	 * @param array<string,mixed> $args     Arguments to get a column by.
	 * @param string               $field    Field to get from a column.
	 * @param TDefault             $fallback Fallback to use if no field is set.
	 * @return mixed Value of the requested field, or $fallback if not found.
	 * @phpstan-return ($fallback is false ? mixed : TDefault)
	 */
	public function get_column_field( $args = array(), $field = '', $fallback = false ) {

		// Get the column.
		$column = $this->get_column_by( $args );

		// Return field, or fallback.
		return isset( $column->{$field} )
			? $column->{$field}
			: $fallback;
	}

	/**
	 * Get this query's schema object (the owner of its columns).
	 *
	 * @since 3.1.0
	 *
	 * @return \BerlinDB\Database\Kern\Schema|null The schema, or null if not yet built.
	 */
	public function get_schema(): ?\BerlinDB\Database\Kern\Schema {
		return ( $this->schema_object instanceof \BerlinDB\Database\Kern\Schema )
			? $this->schema_object
			: null;
	}

	/**
	 * Get a column from an array of arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $args Arguments to get a column by.
	 * @return \BerlinDB\Database\Kern\Column|false Column object, or false if not found.
	 */
	public function get_column_by( $args = array() ) {

		// Filter columns.
		$filter = $this->get_columns( $args );

		// Return column or false.
		$column = ! empty( $filter )
			? reset( $filter )
			: false;

		return ( $column instanceof Column )
			? $column
			: false;
	}

	/**
	 * Get columns from the schema, optionally filtered.
	 *
	 * Delegates to the schema object's get_columns(): $args and $operator filter the
	 * columns via wp_filter_object_list() (the schema normalizes a `type` arg to the
	 * stored uppercase), and $field plucks a property from each match.
	 *
	 * @since 1.0.0
	 * @since 3.0.0
	 * @since 3.1.0 Delegates to Schema::get_columns(); dropped the legacy inline-$columns source.
	 *
	 * @param array<string,mixed> $args     Arguments to filter columns by.
	 * @param string              $operator Optional. The logical operation to perform.
	 * @param bool|string         $field    Optional. A field from the object to place
	 *                                      instead of the entire object. Default false.
	 * @return Column[]|list<mixed> Array of Column objects, or field values if $field is set.
	 */
	public function get_columns( $args = array(), $operator = 'and', $field = false ): array {

		// Without a schema there are no columns to return.
		if ( ! is_callable( array( $this->schema_object, 'get_columns' ) ) ) {
			return array();
		}

		/** @var Column[]|list<mixed> $columns */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$columns = $this->schema_object->get_columns( $args, $operator, $field );

		return $columns;
	}

	/**
	 * Get a field from columns, by the intersection of key and values.
	 *
	 * This is used for retrieving an array of column fields by an array of
	 * other field values.
	 *
	 * Uses get_column_field() to allow passing of a default value.
	 *
	 * @since 3.0.0
	 * @template TDefault
	 * @param string              $key      Name of property to compare $values to.
	 * @param array<mixed>|string $values   Values to get a column by. Scalar values are wrapped in an array.
	 * @param string              $field    Field to get from a column.
	 * @param TDefault            $fallback Fallback to use if no field is set.
	 * @return list<mixed>
	 * @phpstan-return ($fallback is false ? list<mixed> : list<TDefault>)
	 */
	protected function get_columns_field_by( $key = '', $values = array(), $field = '', $fallback = false ) {

		// Bail if no values.
		if ( empty( $values ) ) {
			return array();
		}

		// Allow scalar values.
		if ( is_scalar( $values ) ) {
			$values = array( $values );
		}

		// Maybe fallback to $key.
		if ( empty( $field ) ) {
			$field = $key;
		}

		// Default return value.
		$retval = array();

		// Get the column fields.
		foreach ( $values as $value ) {
			$args     = array( $key => $value );
			$retval[] = $this->get_column_field( $args, $field, $fallback );
		}

		// Return fields of columns.
		return $retval;
	}

	/**
	 * Get a column name, possibly with the $table_alias append.
	 *
	 * @since 3.0.0
	 * @param string $column_name Column name.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @return string
	 */
	protected function get_column_name_aliased( $column_name = '', $alias = true ): string {

		// Default return value.
		$retval = $column_name;

		/*
		 * Maybe prepend the table alias.
		 *
		 * Also add a period as a separator.
		 */
		if ( true === $alias ) {
			$retval = $this->get_table_alias() . '.' . $column_name;
		}

		// Return SQL.
		return $retval;
	}

	/**
	 * Get the backtick-quoted alias.column_name string.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 An empty $column_name defaults to the primary column.
	 * @param string $column_name Column name. Defaults to the primary column.
	 * @param bool   $alias Whether to include the table alias prefix.
	 * @return string
	 */
	public function get_quoted_column_name_aliased( $column_name = '', $alias = true ): string {

		// Default to the primary column when no name is given.
		if ( '' === $column_name ) {
			$column_name = $this->get_primary_column_name();
		}

		// Delegate to the Column object when one exists in the schema.
		$column_object = $this->get_column_by( array( 'name' => $column_name ) );

		// Column object exists.
		if ( ! empty( $column_object ) ) {

			// Maybe get the table alias for the column name.
			$table_alias = ( true === $alias )
				? $this->get_table_alias()
				: '';

			// Return the column name, with alias if requested.
			return $column_object->get_name_sql( $table_alias );
		}

		// Fallback for non-schema identifiers (e.g. meta table columns).
		$retval = $this->quote_identifier( $column_name );

		// Maybe prepend the quoted table alias.
		if ( true === $alias ) {
			$retval = $this->quote_identifier( $this->get_table_alias() ) . '.' . $retval;
		}

		// Return SQL.
		return $retval;
	}

	/**
	 * Used internally to generate the SQL string for IN and NOT IN clauses.
	 *
	 * The $values being passed in should not be validated, and they will be
	 * escaped before they are concatenated together and returned as a string.
	 *
	 * @since 3.0.0
	 *
	 * @param string                          $column_name Column name.
	 * @param array<array-key,mixed>|string  $values      Value(s) to escape. Arrays are
	 *                                                     flattened into the prepared statement.
	 * @param bool                            $wrap        To wrap in parenthesis.
	 * @param string                          $pattern     Pattern to prepare with.
	 *
	 * @return string Escaped/prepared SQL, possibly wrapped in parenthesis.
	 */
	public function get_in_sql( $column_name = '', $values = array(), $wrap = true, $pattern = '' ): string {

		// Bail if no values or invalid column.
		if ( empty( $values ) || ! $this->is_valid_column( $column_name ) ) {
			return '';
		}

		// Fallback to column pattern.
		if ( empty( $pattern ) || ! is_string( $pattern ) ) {
			$pattern = $this->get_column_field( array( 'name' => $column_name ), 'pattern', '%s' );
		}

		// Fill an array of patterns to match the number of values.
		$values   = (array) $values;
		$count    = count( $values );
		$patterns = array_fill( 0, $count, $pattern );

		// Prepare.
		$sql    = implode( ', ', $patterns );
		$retval = $this->db()->prepare( $sql, ...$values );

		// Set return value to empty string if prepare() returns falsy.
		if ( empty( $retval ) ) {
			$retval = '';
		}

		// Wrap them in parenthesis.
		if ( true === $wrap ) {
			$retval = "({$retval})";
		}

		// Return in SQL.
		return $retval;
	}
}
