<?php
/**
 * A single modified-column difference.
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

/**
 * Pairs the two definitions of a column that exists on both sides of a diff but
 * is defined differently: the `from` (source / live) and the `to` (target /
 * declared) Column. The migration would alter `from` into `to`.
 *
 * @since 3.1.0
 */
class ColumnDiff {

	/**
	 * The source-side column (what exists now).
	 *
	 * @since 3.1.0
	 * @var Column
	 */
	private $from;

	/**
	 * The target-side column (what is declared).
	 *
	 * @since 3.1.0
	 * @var Column
	 */
	private $to;

	/**
	 * Pair the two sides of a modified column.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $from The source-side column.
	 * @param Column $to   The target-side column.
	 */
	public function __construct( Column $from, Column $to ) {
		$this->from = $from;
		$this->to   = $to;
	}

	/**
	 * The source-side column.
	 *
	 * @since 3.1.0
	 * @return Column
	 */
	public function from(): Column {
		return $this->from;
	}

	/**
	 * The target-side column.
	 *
	 * @since 3.1.0
	 * @return Column
	 */
	public function to(): Column {
		return $this->to;
	}

	/**
	 * The column name (the shared identity of both sides).
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function name(): string {
		return (string) $this->to->name;
	}

	/**
	 * The inverse difference: the from and to sides swapped.
	 *
	 * @since 3.1.0
	 * @return self
	 */
	public function reverse(): self {
		return new self( $this->to, $this->from );
	}
}
