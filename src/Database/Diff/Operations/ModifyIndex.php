<?php
/**
 * Modify-index schema operation.
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
use BerlinDB\Database\Kern\Index;
use BerlinDB\Database\Kern\Table;

/**
 * Replaces an index whose definition changed, in one atomic DROP-then-ADD.
 *
 * MySQL cannot alter an index in place, and reconciling it as a SEPARATE drop then
 * add fails for the PRIMARY KEY over an AUTO_INCREMENT column (the standalone DROP
 * PRIMARY KEY leaves that column unindexed). Carrying both sides and rendering a
 * single combined ALTER (see Grammar::replace_index()) handles the primary key and
 * every other index alike.
 *
 * @since 3.1.0
 */
class ModifyIndex implements Operation {

	/**
	 * The source-side index to drop.
	 *
	 * @since 3.1.0
	 * @var Index
	 */
	private $from;

	/**
	 * The target-side index to add in its place.
	 *
	 * @since 3.1.0
	 * @var Index
	 */
	private $to;

	/**
	 * @since 3.1.0
	 *
	 * @param Index $from The index to drop.
	 * @param Index $to   The index to add in its place.
	 */
	public function __construct( Index $from, Index $to ) {
		$this->from = $from;
		$this->to   = $to;
	}

	/**
	 * @since 3.1.0
	 *
	 * @param Grammar $grammar The SQL grammar to render with.
	 * @param string  $table   The full, prefixed table name.
	 *
	 * @return string
	 */
	public function to_sql( Grammar $grammar, string $table ): string {
		return $grammar->replace_index( $table, $this->from, $this->to );
	}

	/**
	 * @since 3.1.0
	 *
	 * @param Table $table The table to alter.
	 *
	 * @return bool
	 */
	public function run( Table $table ): bool {
		return $table->replace_index( $this->from, $this->to );
	}
}
