<?php
/**
 * A single schema-change operation.
 *
 * @package     Database
 * @subpackage  Diff
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Diff\Operations;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Diff\Grammar;
use BerlinDB\Database\Kern\Table;

/**
 * One step of reconciling a table to its schema: add a column, drop an index, etc.
 *
 * An operation carries intent only (the column/index/name it acts on), not SQL.
 * Rendering is delegated to a Grammar (to_sql) and execution to the table's own
 * DDL verbs (run) - which themselves render through the same Grammar, so preview
 * and execution share one SQL definition. Replacing the engine means swapping the
 * Grammar, never touching these operations.
 *
 * @since 3.1.0
 */
interface Operation {

	/**
	 * Render this operation as a SQL statement via the given grammar.
	 *
	 * @since 3.1.0
	 *
	 * @param Grammar $grammar The SQL grammar to render with.
	 * @param string  $table   The full, prefixed table name.
	 *
	 * @return string The statement, or '' when it renders nothing.
	 */
	public function to_sql( Grammar $grammar, string $table ): string;

	/**
	 * Execute this operation against the given table (via its DDL verbs).
	 *
	 * @since 3.1.0
	 *
	 * @param Table $table The table to alter.
	 *
	 * @return bool True on success, false otherwise.
	 */
	public function run( Table $table ): bool;
}
