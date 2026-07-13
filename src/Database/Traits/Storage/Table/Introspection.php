<?php
/**
 * Table introspection trait.
 *
 * @package     BerlinDB\Database\Traits\Storage\Table
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Storage\Table;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reading a live table's structure and state back out of the database: its status
 * row, CREATE TABLE SQL, column and index lists, row count, and whether a named
 * column or index exists.
 *
 * One of the Traits\Storage\Table\* collection - storage traits specific to a Table,
 * as opposed to the Traits\Storage\* traits shared by every storage relation, Table
 * and View alike. Introspection is Table-specific because these run table-only
 * introspection (SHOW TABLE STATUS, SHOW CREATE TABLE, SHOW INDEXES FROM), and its
 * get_create_sql() (SHOW CREATE TABLE) would collide with View::get_create_sql()
 * (SHOW CREATE VIEW); a View introspects itself, so a future Traits\Storage\View\
 * Introspection would hold the View-side reads. Grouping it here keeps the Table
 * class focused (#237, the Traits\Query\* pattern).
 *
 * Read-only: nothing here mutates the table (that is Traits\Storage\Table\Alter). The
 * completeness-aware capture used by reconcile() lives in Traits\Storage\Table\
 * Reconciliation (snapshot()), which layers a trustworthiness signal over this.
 *
 * @since 3.1.0
 */
trait Introspection {

	/**
	 * Get status of table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/show-table-status.html
	 *
	 * @since 3.0.0
	 *
	 * @return object|false Table status object, or false if the database is
	 *                      unavailable or the table does not exist.
	 */
	public function status() {

		// Query statement - SHOW TABLE STATUS LIKE exact_name returns at most one row.
		$sql      = 'SHOW TABLE STATUS LIKE %s';
		$like     = $this->db()->esc_like( $this->table_name );
		$prepared = $this->db()->prepare( $sql, $like );
		$result   = $this->db()->get_row( $prepared );

		// Does the table exist?
		return is_object( $result )
			? $result
			: false;
	}

	/**
	 * Return the CREATE TABLE SQL for this table.
	 *
	 * Runs SHOW CREATE TABLE and returns the SQL string from the result.
	 * Useful for debugging schema drift and as input for rollback tooling.
	 * Returns false if the table does not exist or the query fails.
	 *
	 * @since 3.1.0
	 *
	 * @return string|false
	 */
	public function get_create_sql(): string|false {

		// Query statement - SHOW CREATE TABLE always returns exactly one row.
		$sql    = "SHOW CREATE TABLE {$this->table_name}";
		$result = $this->db()->get_row( $sql );

		// Return the CREATE TABLE definition, or false on failure.
		return ( is_object( $result ) && isset( $result->{'Create Table'} ) ) ? $result->{'Create Table'} : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Get columns from table.
	 *
	 * @since 1.2.0
	 *
	 * @return list<mixed>|false Array of column rows on success, false on failure.
	 */
	public function columns() {

		// Query statement.
		$sql    = "SHOW FULL COLUMNS FROM {$this->table_name}";
		$result = $this->db()->get_results( $sql );

		// Return the results.
		return ( $this->is_success( $result ) && is_array( $result ) )
			? array_values( $result )
			: false;
	}

	/**
	 * Get indexes from table.
	 *
	 * @since 3.0.0
	 *
	 * @return list<mixed>|false Array of index rows on success, false on failure.
	 */
	public function indexes() {

		// Query statement.
		$sql    = "SHOW INDEXES FROM {$this->table_name}";
		$result = $this->db()->get_results( $sql );

		// Return the results.
		return ( $this->is_success( $result ) && is_array( $result ) )
			? array_values( $result )
			: false;
	}

	/**
	 * Count the number of items in this database table.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function count() {

		// Query statement.
		$sql    = "SELECT COUNT(*) FROM {$this->table_name}";
		$result = $this->db()->get_var( $sql );

		// 0 on error/empty, number of rows on success.
		return intval( $result );
	}

	/**
	 * Check if column already exists.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses sanitize_column_name().
	 *
	 * @param string $name Column name to check.
	 *
	 * @return bool
	 */
	public function column_exists( $name = '' ) {

		// Query statement.
		$sql  = "SHOW COLUMNS FROM {$this->table_name} LIKE %s";
		$name = $this->sanitize_column_name( $name );

		if ( false === $name ) {
			return false;
		}

		$like     = $this->db()->esc_like( $name );
		$prepared = $this->db()->prepare( $sql, $like );
		$result   = ! empty( $prepared ) ? $this->db()->query( $prepared ) : false;

		// Does the column exist?
		return $this->is_success( $result );
	}

	/**
	 * Check if index already exists.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Uses sanitize_column_name().
	 *
	 * @param string $name   Index name to check.
	 * @param string $column Column name to compare.
	 *
	 * @return bool
	 */
	public function index_exists( $name = '', $column = 'Key_name' ) {

		// Limit $column to Key or Column name, until we can do better.
		if ( ! in_array( $column, array( 'Key_name', 'Column_name' ), true ) ) {
			$column = 'Key_name';
		}

		// Query statement.
		$sql  = "SHOW INDEXES FROM {$this->table_name} WHERE {$column} LIKE %s";
		$name = $this->sanitize_column_name( $name );

		if ( false === $name ) {
			return false;
		}

		$like     = $this->db()->esc_like( $name );
		$prepared = $this->db()->prepare( $sql, $like );
		$result   = ! empty( $prepared ) ? $this->db()->query( $prepared ) : false;

		// Does the index exist?
		return $this->is_success( $result );
	}
}
