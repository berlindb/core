<?php
/**
 * Table schema-mutation (ALTER) trait.
 *
 * @package     BerlinDB\Database\Traits\Storage\Table
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Storage\Table;

use BerlinDB\Database\Diff\Grammar;
use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Index;
use BerlinDB\Database\Kern\Schema;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The in-place schema-mutation surface of a Table: adding/dropping/replacing
 * columns, indexes, and foreign keys, reseeding AUTO_INCREMENT, and switching the
 * storage engine - every ALTER TABLE verb.
 *
 * One of the Traits\Storage\Table\* collection - storage traits specific to a Table,
 * as opposed to the Traits\Storage\* traits (Registration, Versioning, Installation,
 * Multisite, Hooks) that every storage relation shares, Table and View alike. Alter
 * is Table-specific because a View has no columns or indexes to alter; a future
 * Traits\Storage\View\* would hold the View-side equivalents. Grouping it here keeps
 * the Table class focused (#237, the Traits\Query\* pattern). All DDL renders through
 * Diff\Grammar, the single ALTER renderer, so the verbs here and any Patch from
 * diff() never drift.
 *
 * @since 3.1.0
 */
trait Alter {

	/**
	 * Lazily-created SQL grammar (the single ALTER renderer).
	 *
	 * @since 3.1.0
	 * @var   Grammar|null
	 */
	private $grammar = null;

	/**
	 * Add an index to this database table.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed>|Index $args Index arguments or an Index object.
	 *
	 * @return bool
	 */
	public function add_index( $args = array() ) {

		// Create index object from arguments.
		$index = ( $args instanceof Index )
			? $args
			: new Index( $args );

		// Build the SQL through the grammar (the single ALTER renderer).
		$sql = $this->grammar()->add_index( $this->table_name, $index );

		// Bail if no valid SQL was generated.
		if ( empty( $sql ) ) {
			return false;
		}

		// Was the index added?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Drop an index from this database table.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name Index name.
	 *
	 * @return bool
	 */
	public function drop_index( $name = '' ) {

		// Sanitize the index name.
		$name = $this->sanitize_column_name( $name );

		// Bail if index name is invalid.
		if ( empty( $name ) ) {
			return false;
		}

		// Build the SQL through the grammar (handles DROP PRIMARY KEY).
		$sql = $this->grammar()->drop_index( $this->table_name, $name );

		// Was the index dropped?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Replace an index in place: drop $from and add $to in one atomic ALTER.
	 *
	 * A modified index (same identity, different definition) cannot be reconciled as
	 * a separate drop then add when it is the PRIMARY KEY over an AUTO_INCREMENT
	 * column - MySQL rejects the standalone DROP PRIMARY KEY. Combining both into one
	 * statement never leaves the column unindexed, so it works for the primary key
	 * and any other index.
	 *
	 * @since 3.1.0
	 *
	 * @param Index $from The index to drop.
	 * @param Index $to   The index to add in its place.
	 *
	 * @return bool
	 */
	public function replace_index( Index $from, Index $to ) {

		// Build the combined SQL through the grammar (the single ALTER renderer).
		$sql = $this->grammar()->replace_index( $this->table_name, $from, $to );

		// Bail if no valid SQL was generated.
		if ( empty( $sql ) ) {
			return false;
		}

		// Was the index replaced?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Add this schema's enforced foreign keys to the table, via ALTER TABLE.
	 *
	 * The deferred counterpart to emitting foreign keys inside CREATE TABLE (see
	 * Schema::get_create_table_string()): use this when the referenced tables were
	 * not guaranteed to exist at create time, or for two tables that reference each
	 * other - create both first, then add the keys. Only enforced (enforce => true)
	 * relationships emit anything; a schema with none is a no-op success. Each key
	 * is added independently; a referenced table that still does not exist fails
	 * that one key (MySQL rejects it) without affecting the others.
	 *
	 * @since 3.1.0
	 *
	 * @return bool True if every enforced key was added (or there were none), false
	 *              if any ADD failed or any enforced key could not be resolved.
	 */
	public function add_foreign_keys(): bool {

		// Nothing to add without a real declared schema.
		if ( ! ( $this->schema_object instanceof Schema ) ) {
			return false;
		}

		$success = true;

		/*
		 * An enforced key whose remote table cannot be resolved (its Table is not
		 * registered) is a failure, not a silent skip - report it and mark unsuccess.
		 */
		foreach ( $this->schema_object->get_unresolved_foreign_keys() as $remote_class ) {
			$this->log( 'warning', 'foreign_key', "Enforced foreign key to {$remote_class} not added to {$this->table_name}: remote table not registered." );
			$success = false;
		}

		// Add each resolved foreign key independently.
		foreach ( $this->schema_object->get_foreign_key_strings() as $fragment ) {
			$sql = $this->grammar()->add_foreign_key( $this->table_name, $fragment );

			if ( ( '' !== $sql ) && ! $this->is_success( $this->db()->query( $sql ) ) ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Add a column to this database table.
	 *
	 * Mirrors add_index(). The create string carries the column name, type, and
	 * all of its attributes, so it slots straight into ADD COLUMN.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed>|Column $args Column arguments or a Column object.
	 *
	 * @return bool
	 */
	public function add_column( $args = array() ) {

		// Create column object from arguments.
		$column = ( $args instanceof Column )
			? $args
			: new Column( $args );

		// Build the SQL through the grammar (the single ALTER renderer).
		$sql = $this->grammar()->add_column( $this->table_name, $column );

		// Bail if no valid SQL was generated.
		if ( empty( $sql ) ) {
			return false;
		}

		// Was the column added?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Modify an existing column on this database table in place.
	 *
	 * Runs ALTER TABLE ... MODIFY COLUMN, which keeps the column name (unlike
	 * CHANGE COLUMN) and redefines its type and attributes from the create string.
	 * Narrowing a type can truncate stored data - the caller decides when that is
	 * acceptable (e.g. the 'modify' operation of a Patch).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed>|Column $args Column arguments or a Column object.
	 *
	 * @return bool
	 */
	public function modify_column( $args = array() ) {

		// Create column object from arguments.
		$column = ( $args instanceof Column )
			? $args
			: new Column( $args );

		// Build the SQL through the grammar (the single ALTER renderer).
		$sql = $this->grammar()->modify_column( $this->table_name, $column );

		// Bail if no valid SQL was generated.
		if ( empty( $sql ) ) {
			return false;
		}

		// Was the column modified?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Drop a column from this database table.
	 *
	 * @since 3.1.0
	 *
	 * @param string $name Column name.
	 *
	 * @return bool
	 */
	public function drop_column( $name = '' ) {

		// Sanitize the column name.
		$name = $this->sanitize_column_name( $name );

		// Bail if column name is invalid.
		if ( empty( $name ) ) {
			return false;
		}

		// Build the SQL through the grammar (the single ALTER renderer).
		$sql = $this->grammar()->drop_column( $this->table_name, $name );

		// Was the column dropped?
		return $this->is_success( $this->db()->query( $sql ) );
	}

	/**
	 * Return the SQL grammar used to render this table's schema-change statements.
	 *
	 * The single place ALTER syntax is built; the DDL verbs above and any Patch
	 * from diff() render through it, so preview and execution never drift. Speaks
	 * MySQL / MariaDB today - the seam where a future engine would swap in.
	 *
	 * @since 3.1.0
	 *
	 * @return Grammar
	 */
	public function grammar(): Grammar {

		// Lazily create the grammar once.
		if ( ! ( $this->grammar instanceof Grammar ) ) {
			$this->grammar = new Grammar();
		}

		return $this->grammar;
	}

	/**
	 * Set the AUTO_INCREMENT counter for this table.
	 *
	 * Use this to seed the counter at a specific value - for example, to
	 * leave low IDs available for fixture or seed data, or to reseed after
	 * a TRUNCATE. Has no effect if the table has no AUTO_INCREMENT column.
	 *
	 * @since 3.1.0
	 *
	 * @param int $value The next AUTO_INCREMENT value to assign. Must be >= 1.
	 * @return bool
	 */
	public function auto_increment( int $value ): bool {

		// Bail if the value is not positive.
		if ( $value < 1 ) {
			return false;
		}

		// Query statement.
		$sql    = "ALTER TABLE {$this->table_name} AUTO_INCREMENT={$value}";
		$result = $this->db()->query( $sql );

		// Was the counter updated?
		return $this->is_success( $result );
	}

	/**
	 * Convert the storage engine for this table.
	 *
	 * Runs ALTER TABLE ... ENGINE=X. Returns false immediately for engine names
	 * that are not in the recognized set, without issuing a query.
	 *
	 * @since 3.1.0
	 *
	 * @param string $engine Target storage engine (e.g. 'InnoDB', 'MyISAM').
	 * @return bool
	 */
	public function engine( string $engine ): bool {

		/*
		 * Bail on a platform with no storage engines (e.g. SQLite); ENGINE= is a
		 * MySQL/MariaDB concept, so there is nothing to switch.
		 */
		if ( ! $this->platform()->has_storage_engines() ) {
			$this->log( 'warning', 'ddl', 'engine() is unsupported on this platform: storage engines are a MySQL/MariaDB concept.' );
			return false;
		}

		// Sanitize and validate the engine name.
		$engine = $this->sanitize_engine( $engine );

		// Bail if the engine name is not recognized.
		if ( empty( $engine ) ) {
			return false;
		}

		// Query statement.
		$sql    = "ALTER TABLE {$this->table_name} ENGINE={$engine}";
		$result = $this->db()->query( $sql );

		// Was the engine changed?
		return $this->is_success( $result );
	}
}
