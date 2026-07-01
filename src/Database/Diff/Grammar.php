<?php
/**
 * SQL grammar for schema-change statements.
 *
 * @package     Database
 * @subpackage  Diff
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Diff;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Index;

/**
 * Renders schema-change operations as ALTER TABLE statements.
 *
 * This is the single place ALTER syntax is built - Operations carry intent (what
 * to change), this Grammar turns that intent into SQL (how to say it). The Table
 * DDL verbs, Patch::to_sql() (preview), and Patch::apply() (execution) all route
 * here, so the rendered SQL has exactly one definition and cannot drift.
 *
 * It speaks MySQL / MariaDB - the only engine BerlinDB targets today, like the
 * rest of the schema layer (Column / Index create strings, SHOW-based
 * introspection, CREATE TABLE). It is the seam for that assumption: a second
 * engine would add a sibling grammar (extract an interface, add PostgresGrammar)
 * that consumes the SAME Operations, never per-operation to_postgres() methods.
 *
 * Column and index bodies are delegated to Column::get_create_string() /
 * Index::get_create_string() so they match what CREATE TABLE emits; this Grammar
 * only owns the surrounding ALTER clause.
 *
 * @since 3.1.0
 */
class Grammar {

	/**
	 * Render an ADD COLUMN statement.
	 *
	 * @since 3.1.0
	 *
	 * @param string $table  The full, prefixed table name.
	 * @param Column $column The column to add.
	 *
	 * @return string The statement, or '' when the column renders no body.
	 */
	public function add_column( string $table, Column $column ): string {
		$body = (string) $column->get_create_string();

		return ( '' === $body )
			? ''
			: "ALTER TABLE {$table} ADD COLUMN {$body}";
	}

	/**
	 * Render a MODIFY COLUMN statement (redefine in place, keeping the name).
	 *
	 * @since 3.1.0
	 *
	 * @param string $table  The full, prefixed table name.
	 * @param Column $column The target-side column definition.
	 *
	 * @return string The statement, or '' when the column renders no body.
	 */
	public function modify_column( string $table, Column $column ): string {
		$body = (string) $column->get_create_string();

		return ( '' === $body )
			? ''
			: "ALTER TABLE {$table} MODIFY COLUMN {$body}";
	}

	/**
	 * Render a DROP COLUMN statement.
	 *
	 * @since 3.1.0
	 *
	 * @param string $table The full, prefixed table name.
	 * @param string $name  The column name (already sanitized by the caller).
	 *
	 * @return string The statement, or '' for an empty name.
	 */
	public function drop_column( string $table, string $name ): string {
		return ( '' === $name )
			? ''
			: "ALTER TABLE {$table} DROP COLUMN `{$name}`";
	}

	/**
	 * Render an ADD index statement (KEY / UNIQUE KEY / PRIMARY KEY / FULLTEXT).
	 *
	 * @since 3.1.0
	 *
	 * @param string $table The full, prefixed table name.
	 * @param Index  $index The index to add.
	 *
	 * @return string The statement, or '' when the index renders no body.
	 */
	public function add_index( string $table, Index $index ): string {
		$body = (string) $index->get_create_string();

		return ( '' === $body )
			? ''
			: "ALTER TABLE {$table} ADD {$body}";
	}

	/**
	 * Render a DROP index statement (DROP PRIMARY KEY for the primary key).
	 *
	 * @since 3.1.0
	 *
	 * @param string $table The full, prefixed table name.
	 * @param string $name  The index name (already sanitized by the caller); the
	 *                      primary key is named 'PRIMARY'.
	 *
	 * @return string The statement, or '' for an empty name.
	 */
	public function drop_index( string $table, string $name ): string {
		$clause = $this->drop_index_clause( $name );

		return ( '' === $clause )
			? ''
			: "ALTER TABLE {$table} {$clause}";
	}

	/**
	 * Render a combined DROP-then-ADD to replace a modified index in one statement.
	 *
	 * A modified index cannot be reconciled as a separate DROP then ADD when it is
	 * the PRIMARY KEY over an AUTO_INCREMENT column - MySQL rejects the standalone
	 * DROP PRIMARY KEY because that column must stay indexed. A single ALTER that
	 * drops and re-adds atomically never leaves the column unindexed, so it works
	 * for the primary key and every other index alike.
	 *
	 * @since 3.1.0
	 *
	 * @param string $table The full, prefixed table name.
	 * @param Index  $from  The index to drop (the live/source side).
	 * @param Index  $to    The index to add in its place (the declared/target side).
	 *
	 * @return string The statement, or '' when either side renders nothing.
	 */
	public function replace_index( string $table, Index $from, Index $to ): string {
		$drop = $this->drop_index_clause( $from->get_index_name() );
		$add  = (string) $to->get_create_string();

		return ( ( '' === $drop ) || ( '' === $add ) )
			? ''
			: "ALTER TABLE {$table} {$drop}, ADD {$add}";
	}

	/**
	 * Render the DROP clause for an index (DROP PRIMARY KEY, or DROP INDEX `name`).
	 *
	 * @since 3.1.0
	 *
	 * @param string $name The index name; the primary key is named 'PRIMARY'.
	 *
	 * @return string The clause, or '' for an empty name.
	 */
	private function drop_index_clause( string $name ): string {
		if ( '' === $name ) {
			return '';
		}

		return ( 'primary' === strtolower( $name ) )
			? 'DROP PRIMARY KEY'
			: "DROP INDEX `{$name}`";
	}
}
