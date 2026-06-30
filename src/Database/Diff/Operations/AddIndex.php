<?php
/**
 * Add-index schema operation.
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
 * Adds an index the declared schema has but the live table lacks.
 *
 * Also renders the new side of a modified index (a modification is a drop of the
 * old definition followed by an add of the new).
 *
 * @since 3.1.0
 */
class AddIndex implements Operation {

	/**
	 * The index to add.
	 *
	 * @since 3.1.0
	 * @var Index
	 */
	private $index;

	/**
	 * @since 3.1.0
	 *
	 * @param Index $index The index to add.
	 */
	public function __construct( Index $index ) {
		$this->index = $index;
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
		return $grammar->add_index( $table, $this->index );
	}

	/**
	 * @since 3.1.0
	 *
	 * @param Table $table The table to alter.
	 *
	 * @return bool
	 */
	public function run( Table $table ): bool {
		return $table->add_index( $this->index );
	}
}
