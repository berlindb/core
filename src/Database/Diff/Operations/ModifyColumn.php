<?php
/**
 * Modify-column schema operation.
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
use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Kern\Table;

/**
 * Redefines a column in place to match the declared schema (MODIFY COLUMN).
 *
 * Carries the target-side (declared) column definition.
 *
 * @since 3.1.0
 */
class ModifyColumn implements Operation {

	/**
	 * The target-side column definition.
	 *
	 * @since 3.1.0
	 * @var Column
	 */
	private $column;

	/**
	 * @since 3.1.0
	 *
	 * @param Column $column The target-side column definition.
	 */
	public function __construct( Column $column ) {
		$this->column = $column;
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
		return $grammar->modify_column( $table, $this->column );
	}

	/**
	 * @since 3.1.0
	 *
	 * @param Table $table The table to alter.
	 *
	 * @return bool
	 */
	public function run( Table $table ): bool {
		return $table->modify_column( $this->column );
	}
}
