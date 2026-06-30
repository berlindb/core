<?php
/**
 * Drop-column schema operation.
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
 * Drops a column the live table has but the declared schema does not.
 *
 * Carries the source-side (live) column; only its name is needed to drop it.
 *
 * @since 3.1.0
 */
class DropColumn implements Operation {

	/**
	 * The source-side column to drop.
	 *
	 * @since 3.1.0
	 * @var Column
	 */
	private $column;

	/**
	 * @since 3.1.0
	 *
	 * @param Column $column The column to drop.
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
		return $grammar->drop_column( $table, (string) $this->column->name );
	}

	/**
	 * @since 3.1.0
	 *
	 * @param Table $table The table to alter.
	 *
	 * @return bool
	 */
	public function run( Table $table ): bool {
		return $table->drop_column( (string) $this->column->name );
	}
}
