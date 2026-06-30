<?php
/**
 * Index equivalence normalizer.
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
 * Decides whether two indexes are equivalent for migration purposes.
 *
 * Indexes are introspected faithfully (SHOW INDEX -> Index::from_mysql captures
 * the columns, prefix lengths, directions, uniqueness, and type), so the
 * signature can compare them directly with little phantom-diff risk:
 *
 *  - kind: primary / unique / fulltext / plain key
 *  - the ordered column list, each with its prefix length and DESC direction
 *    (column order and prefix lengths are semantic)
 *
 * Deliberately NOT compared (a change confined to one of these is not yet a
 * modification): the storage method (BTREE/HASH via USING - rarely changes, not
 * always reported consistently), and the index comment.
 *
 * @since 3.1.0
 */
class IndexNormalizer {

	/**
	 * Whether two indexes are equivalent for migration purposes.
	 *
	 * @since 3.1.0
	 *
	 * @param Index $a One index.
	 * @param Index $b The other index.
	 *
	 * @return bool True if equivalent (no migration needed), false if different.
	 */
	public function matches( Index $a, Index $b ): bool {
		return $this->signature( $a ) === $this->signature( $b );
	}

	/**
	 * Build the canonical comparison signature for an index.
	 *
	 * @since 3.1.0
	 *
	 * @param Index $index The index.
	 *
	 * @return array<string,mixed>
	 */
	private function signature( Index $index ): array {
		return array(
			'kind'    => $this->kind( $index ),
			'columns' => $this->columns( $index ),
		);
	}

	/**
	 * The index kind: primary, unique, fulltext, or key.
	 *
	 * @since 3.1.0
	 *
	 * @param Index $index The index.
	 * @return string
	 */
	private function kind( Index $index ): string {
		$type = strtolower( trim( (string) $index->type ) );

		if ( 'primary' === $type ) {
			return 'primary';
		}

		if ( 'fulltext' === $type ) {
			return 'fulltext';
		}

		if ( ( 'unique' === $type ) || ! empty( $index->unique ) ) {
			return 'unique';
		}

		return 'key';
	}

	/**
	 * The ordered column list, each with its prefix length and DESC direction.
	 *
	 * @since 3.1.0
	 *
	 * @param Index $index The index.
	 * @return list<string>
	 */
	private function columns( Index $index ): array {
		$out = array();

		foreach ( $index->columns as $name ) {
			$entry = strtolower( (string) $name );

			if ( isset( $index->lengths[ $name ] ) ) {
				$entry .= '(' . (int) $index->lengths[ $name ] . ')';
			}

			if ( isset( $index->directions[ $name ] ) && ( 'DESC' === $index->directions[ $name ] ) ) {
				$entry .= ' desc';
			}

			$out[] = $entry;
		}

		return $out;
	}
}
