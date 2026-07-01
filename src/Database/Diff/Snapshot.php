<?php
/**
 * The captured structure of a live database table.
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

use BerlinDB\Database\Kern\Schema;

/**
 * A point-in-time capture of a live table's structure, plus how trustworthy that
 * capture is.
 *
 * Schema::from_table() introspects a table into a Schema, but a Schema alone
 * cannot say whether the introspection was COMPLETE - a missing table, a failed
 * SHOW query, or an index the engine could not faithfully represent all collapse
 * into the same "looks empty" Schema. This wraps the introspected Schema with the
 * provenance that distinguishes them, so a consumer (a schema diff / auto-upgrade)
 * can decide whether the capture is solid enough to act on.
 *
 * The decision rule is is_complete(): only a snapshot of a table that exists AND
 * whose indexes were fully and faithfully introspected is safe to treat as the
 * authoritative "actual" schema. Anything less means "do not act on this" - re-read
 * later rather than reconcile against a partial picture.
 *
 * Why only two signals: SHOW COLUMNS is all-or-nothing (it returns every column or
 * errors), so exists() already implies the columns are complete. The incompleteness
 * that hides lives in indexes - a skipped unrepresentable index (SPATIAL,
 * functional, invisible, FULLTEXT WITH PARSER) or a failed SHOW INDEX - which is
 * what indexes_complete() reports.
 *
 * @since 3.1.0
 */
class Snapshot {

	/**
	 * The introspected schema (empty when the table was not found).
	 *
	 * @since 3.1.0
	 * @var Schema
	 */
	private $schema;

	/**
	 * Whether the table was found (SHOW COLUMNS returned its columns).
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	private $exists;

	/**
	 * Whether every index was introspected faithfully.
	 *
	 * False when SHOW INDEX failed, or when an index could not be represented and
	 * was skipped (see Index::from_mysql()).
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	private $indexes_complete;

	/**
	 * @since 3.1.0
	 *
	 * @param Schema $schema           The introspected schema.
	 * @param bool   $exists           Whether the table was found.
	 * @param bool   $indexes_complete Whether all indexes were faithfully introspected.
	 */
	public function __construct( Schema $schema, bool $exists, bool $indexes_complete ) {
		$this->schema           = $schema;
		$this->exists           = $exists;
		$this->indexes_complete = $indexes_complete;
	}

	/**
	 * A not-found snapshot: the table was not found (or could not be introspected).
	 *
	 * An empty schema, exists() and is_complete() both false.
	 *
	 * @since 3.1.0
	 *
	 * @return self
	 */
	public static function missing(): self {
		return new self( new Schema(), false, false );
	}

	/**
	 * The introspected schema.
	 *
	 * @since 3.1.0
	 * @return Schema
	 */
	public function schema(): Schema {
		return $this->schema;
	}

	/**
	 * Whether the table was found.
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	public function exists(): bool {
		return $this->exists;
	}

	/**
	 * Whether all indexes were introspected faithfully (none failed or skipped).
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	public function indexes_complete(): bool {
		return $this->indexes_complete;
	}

	/**
	 * Whether this capture is trustworthy enough to act on.
	 *
	 * True only for a table that exists and whose indexes were fully and faithfully
	 * introspected. A consumer should not reconcile a table (or bump a stored
	 * version) against a snapshot that is not complete - re-read it later instead.
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	public function is_complete(): bool {
		return $this->exists && $this->indexes_complete;
	}
}
