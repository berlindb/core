<?php
/**
 * A single modified-index difference.
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

use BerlinDB\Database\Kern\Index;

/**
 * Pairs the two definitions of an index that exists on both sides of a diff but
 * is defined differently: the `from` (source / live) and the `to` (target /
 * declared) Index. MySQL cannot alter an index in place, so applying this is a
 * drop-then-add.
 *
 * @since 3.1.0
 */
class IndexDiff {

	/**
	 * The source-side index (what exists now).
	 *
	 * @since 3.1.0
	 * @var Index
	 */
	private $from;

	/**
	 * The target-side index (what is declared).
	 *
	 * @since 3.1.0
	 * @var Index
	 */
	private $to;

	/**
	 * Pair the two sides of a modified index.
	 *
	 * @since 3.1.0
	 *
	 * @param Index $from The source-side index.
	 * @param Index $to   The target-side index.
	 */
	public function __construct( Index $from, Index $to ) {
		$this->from = $from;
		$this->to   = $to;
	}

	/**
	 * The source-side index.
	 *
	 * @since 3.1.0
	 * @return Index
	 */
	public function from(): Index {
		return $this->from;
	}

	/**
	 * The target-side index.
	 *
	 * @since 3.1.0
	 * @return Index
	 */
	public function to(): Index {
		return $this->to;
	}

	/**
	 * The index name (the shared identity of both sides).
	 *
	 * The primary key has no name of its own, so this returns '' for it - use
	 * to()->get_index_name() to get the SQL name 'PRIMARY'.
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
