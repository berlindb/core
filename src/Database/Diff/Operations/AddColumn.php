<?php
/**
 * Add-column schema operation.
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
 * Adds a column that the declared schema has but the live table lacks.
 *
 * @since 3.1.0
 */
class AddColumn implements Operation {

	/**
	 * The column to add.
	 *
	 * @since 3.1.0
	 * @var Column
	 */
	private $column;

	/**
	 * @since 3.1.0
	 *
	 * @param Column $column The column to add.
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
		return $grammar->add_column( $table, $this->column );
	}

	/**
	 * @since 3.1.0
	 *
	 * @param Table $table The table to alter.
	 *
	 * @return bool
	 */
	public function run( Table $table ): bool {
		return $table->add_column( $this->column );
	}
}
