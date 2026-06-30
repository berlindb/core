<?php
/**
 * Column equivalence normalizer.
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
 * Decides whether two columns are equivalent for migration purposes, reducing
 * each to a canonical signature and comparing those.
 *
 * The point is to AVOID phantom diffs - the dbDelta failure mode where a
 * hand-authored column and the same column introspected from a live table look
 * different for trivial, non-semantic reasons. The bias is conservative: a
 * missed change is far safer than a false "modified" that churns (or, once
 * applied, destroys) a column. So the signature compares only the properties
 * that are unambiguous and stable across MySQL/MariaDB versions:
 *
 *  - type (case-folded)
 *  - length, except for INTEGER types - an integer's display width like int(11)
 *    is cosmetic and dropped by MySQL 8, so comparing it phantom-diffs. Length is
 *    still compared for strings/binary (significant) and for DECIMAL/FLOAT/BIT
 *    (precision is significant)
 *  - nullability
 *  - unsigned / zerofill, but only for numeric types (they are meaningless and
 *    inconsistently defaulted elsewhere)
 *
 * Deliberately EXCLUDED for now (each needs context or normalization this layer
 * does not yet have, and each is a classic phantom-diff source); a change confined
 * to one of these is NOT yet reported as a modification:
 *  - the default value
 *  - character set / collation (an omitted charset inherits the table default)
 *  - the `extra` clause (MySQL 8 prefixes ON UPDATE with DEFAULT_GENERATED and
 *    varies AUTO_INCREMENT casing)
 *  - the comment
 *  - a DECIMAL/NUMERIC column's SCALE (Column::from_mysql captures only the
 *    precision, so comparing scale would phantom-diff)
 *  - an ENUM/SET value list (introspection does not preserve it as a length, so it
 *    cannot be compared here)
 *
 * @since 3.1.0
 */
class ColumnNormalizer {

	/**
	 * MySQL type synonyms folded to their canonical name. MySQL stores (and SHOW
	 * COLUMNS reports) the canonical form, so a hand-authored synonym must fold to
	 * the same name or it would phantom-diff against the introspected column.
	 *
	 * @since 3.1.0
	 * @var array<string,string>
	 */
	private const TYPE_SYNONYMS = array(
		'integer' => 'int',
		'bool'    => 'tinyint',
		'boolean' => 'tinyint',
		'dec'     => 'decimal',
		'fixed'   => 'decimal',
		'numeric' => 'decimal',
		'real'    => 'double',
	);

	/**
	 * Whether two columns are equivalent for migration purposes.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $a One column.
	 * @param Column $b The other column.
	 *
	 * @return bool True if equivalent (no migration needed), false if different.
	 */
	public function matches( Column $a, Column $b ): bool {
		return $this->signature( $a ) === $this->signature( $b );
	}

	/**
	 * Build the canonical comparison signature for a column.
	 *
	 * @since 3.1.0
	 *
	 * @param Column $column The column.
	 *
	 * @return array<string,mixed>
	 */
	private function signature( Column $column ): array {
		$type      = strtolower( trim( (string) $column->type ) );
		$canonical = self::TYPE_SYNONYMS[ $type ] ?? $type;

		/*
		 * Integer display width (int(11)) is cosmetic, so length is dropped for
		 * integer types - checked against the CANONICAL type so a synonym like
		 * 'integer' gets the same suppression as 'int'. unsigned/zerofill, by
		 * contrast, mirror get_create_string(), which decides emission from the
		 * column's OWN type category - so the signature matches the actual table.
		 */
		$is_numeric = $column->is_numeric();

		return array(
			'type'     => $canonical,
			'length'   => $column->is_int( $canonical ) ? 0 : (int) $column->length,
			'nullable' => ! empty( $column->allow_null ),
			'unsigned' => $is_numeric && ! empty( $column->unsigned ),
			'zerofill' => $is_numeric && ! empty( $column->zerofill ),
		);
	}
}
