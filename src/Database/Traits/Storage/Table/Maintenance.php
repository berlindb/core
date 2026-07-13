<?php
/**
 * Table maintenance trait.
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
 * Housekeeping operations on an existing table: emptying it (truncate/delete_all),
 * cloning it (duplicate/copy), renaming it, and the server maintenance verbs
 * (analyze/check/checksum/optimize/repair).
 *
 * One of the Traits\Storage\Table\* collection - storage traits specific to a Table,
 * as opposed to the Traits\Storage\* traits shared by every storage relation, Table
 * and View alike. Maintenance is Table-specific because these are base-table
 * operations (TRUNCATE, CREATE TABLE LIKE, ANALYZE/OPTIMIZE/REPAIR TABLE) that a
 * View - a stored SELECT with no rows or storage of its own - cannot undergo; a
 * future Traits\Storage\View\* would hold whatever the View equivalents are.
 * Grouping it here keeps the Table class focused (#237, the Traits\Query\* pattern).
 *
 * @since 3.1.0
 */
trait Maintenance {

	/**
	 * Truncate this database table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function truncate() {

		// Query statement.
		$sql    = "TRUNCATE TABLE {$this->table_name}";
		$result = $this->db()->query( $sql );

		// Did the table get truncated?
		return $this->is_success( $result );
	}

	/**
	 * Delete all items from this database table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function delete_all() {

		// Query statement.
		$sql    = "DELETE FROM {$this->table_name}";
		$result = $this->db()->query( $sql );

		// Return true as long as no SQL error occurred; 0 rows deleted is still a success.
		return false !== $result;
	}

	/**
	 * Duplicate this database table.
	 *
	 * Pair with copy().
	 *
	 * Both the WordPress table prefix and the BerlinDB plugin prefix are
	 * applied to the new table name automatically, matching how
	 * $this->table_name is built.
	 *
	 * @since 3.0.0
	 *
	 * @param string $new_table_name The name of the new table, without any prefix.
	 *
	 * @return bool
	 */
	public function duplicate( $new_table_name = '' ) {

		// Sanitize the new table name.
		$table_name = $this->sanitize_table_name( $new_table_name );

		// Bail if new table name is invalid.
		if ( empty( $table_name ) ) {
			return false;
		}

		// Query statement.
		$table  = $this->table_prefix . $this->apply_prefix( $table_name );
		$sql    = "CREATE TABLE {$table} LIKE {$this->table_name}";
		$result = $this->db()->query( $sql );

		// Did the table get duplicated?
		return $this->is_success( $result );
	}

	/**
	 * Copy the contents of this table to a new table.
	 *
	 * Pair with duplicate().
	 *
	 * Both the WordPress table prefix and the BerlinDB plugin prefix are
	 * applied to the new table name automatically, matching how
	 * $this->table_name is built.
	 *
	 * @since 1.1.0
	 *
	 * @param string $new_table_name The name of the destination table, without any prefix.
	 *
	 * @return bool
	 */
	public function copy( $new_table_name = '' ) {

		// Sanitize the new table name.
		$table_name = $this->sanitize_table_name( $new_table_name );

		// Bail if new table name is invalid.
		if ( empty( $table_name ) ) {
			return false;
		}

		// Query statement.
		$table  = $this->table_prefix . $this->apply_prefix( $table_name );
		$sql    = "INSERT INTO {$table} SELECT * FROM {$this->table_name}";
		$result = $this->db()->query( $sql );

		// Did the table get copied?
		return $this->is_success( $result );
	}

	/**
	 * Rename this database table.
	 *
	 * Both the WordPress table prefix and the BerlinDB plugin prefix are
	 * applied to the new table name automatically, matching how
	 * $this->table_name is built.
	 *
	 * After a successful rename, $this->table_name is not updated - callers
	 * are responsible for refreshing any references to the old name.
	 *
	 * @since 3.0.0
	 *
	 * @param string $new_table_name The new name for this table, without any prefix.
	 *
	 * @return bool
	 */
	public function rename( $new_table_name = '' ) {

		// Sanitize the new table name.
		$table_name = $this->sanitize_table_name( $new_table_name );

		// Bail if new table name is invalid.
		if ( empty( $table_name ) ) {
			return false;
		}

		// Query statement.
		$table  = $this->table_prefix . $this->apply_prefix( $table_name );
		$sql    = "ALTER TABLE {$this->table_name} RENAME TO {$table}";
		$result = $this->db()->query( $sql );

		// Did the table get renamed?
		return $this->is_success( $result );
	}

	/** Repair ****************************************************************/

	/**
	 * Analyze this database table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/analyze-table.html
	 *
	 * @since 3.0.0
	 *
	 * @return bool|string
	 */
	public function analyze() {

		// Query statement.
		$sql    = "ANALYZE TABLE {$this->table_name}";
		$query  = (array) $this->db()->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text ) ? $result->Msg_text : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Check this database table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/check-table.html
	 *
	 * @since 3.0.0
	 *
	 * @return bool|string
	 */
	public function check() {

		// Query statement.
		$sql    = "CHECK TABLE {$this->table_name}";
		$query  = (array) $this->db()->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text ) ? $result->Msg_text : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Get the Checksum of this database table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/checksum-table.html
	 *
	 * @since 3.0.0
	 *
	 * @return bool|string
	 */
	public function checksum() {

		// Query statement - CHECKSUM TABLE returns exactly one row per table.
		$sql    = "CHECKSUM TABLE {$this->table_name}";
		$result = $this->db()->get_row( $sql );

		// Return checksum.
		return ( is_object( $result ) && ! empty( $result->Checksum ) ) ? $result->Checksum : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Optimize this database table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/optimize-table.html
	 *
	 * @since 3.0.0
	 *
	 * @return bool|string
	 */
	public function optimize() {

		// Query statement.
		$sql    = "OPTIMIZE TABLE {$this->table_name}";
		$query  = (array) $this->db()->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text ) ? $result->Msg_text : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Repair this database table.
	 *
	 * See: https://dev.mysql.com/doc/refman/8.0/en/repair-table.html
	 * Note: Not supported by InnoDB, the default engine in MySQL 8 and higher.
	 *
	 * @since 3.0.0
	 *
	 * @return bool|string
	 */
	public function repair() {

		// Query statement.
		$sql    = "REPAIR TABLE {$this->table_name}";
		$query  = (array) $this->db()->get_results( $sql );
		$result = end( $query );

		// Return message text.
		return ! empty( $result->Msg_text ) ? $result->Msg_text : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
