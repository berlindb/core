<?php
/**
 * Table schema-reconciliation trait.
 *
 * @package     BerlinDB\Database\Traits\Storage\Table
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Storage\Table;

use BerlinDB\Database\Diff\Patch;
use BerlinDB\Database\Diff\Result;
use BerlinDB\Database\Diff\Snapshot;
use BerlinDB\Database\Kern\Schema;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Diffing the live table against its declared schema and applying the difference:
 * the diff() -> diverged() -> snapshot() -> reconcile() surface and the $reconcile
 * opt-in that upgrade() consults.
 *
 * One of the Traits\Storage\Table\* collection - storage traits specific to a Table,
 * as opposed to the Traits\Storage\* traits shared by every storage relation, Table
 * and View alike. Reconciliation is Table-specific because a View has no columns or
 * indexes to reconcile - its upgrade is a CREATE OR REPLACE of the SELECT, with no
 * structural drift to diff; a future Traits\Storage\View\* would hold the View-side
 * equivalents. Grouping it here keeps the Table class focused (#237, the
 * Traits\Query\* pattern). All reconciling ALTERs render through the Diff engine and
 * the Alter trait's verbs, so a diff and its applied change never drift.
 *
 * @since 3.1.0
 */
trait Reconciliation {

	/**
	 * Which operations upgrade() may reconcile against the declared schema.
	 *
	 * When a version bump runs upgrade() and there is no bespoke $upgrades callback
	 * for the pending version, this diffs the live table against the declared schema
	 * and applies the difference (see reconcile()), so a developer can evolve the
	 * schema by editing it rather than hand-writing ALTERs. The two compose across
	 * upgrade cycles: any bespoke callbacks run first (data migrations), then a later
	 * cycle reconciles the remaining structural drift.
	 *
	 * Defaults to ADDITIVE-ONLY, ON: a bumped version with no callback adds the
	 * columns/indexes the schema gained, which is non-destructive and is what a
	 * developer editing the schema almost always intends. The changes that can lose
	 * data are strictly opt-in.
	 *
	 * Accepts:
	 *  - array( 'add' ) - additive only, on (the default). Adds; never modifies or
	 *                     drops.
	 *  - true           - the moderate policy ('add', 'modify'). MODIFY COLUMN can
	 *                     truncate data on a type-narrowing, so it is opt-in.
	 *  - string[]       - an explicit operations list, e.g. array( 'add', 'modify',
	 *                     'drop' ) to also drop what the schema removed. Drops are
	 *                     never included unless named here.
	 *  - false / array() - off; reconcile does not run (bespoke $upgrades callbacks
	 *                     and the plain version bump still work as before).
	 *
	 * A reconcile only runs against a COMPLETE introspection (see Snapshot); an
	 * incomplete capture defers to the next maybe_upgrade(), and a failed ALTER is
	 * logged and the version advanced past (never re-run every request). A clean
	 * reconcile means every SUPPORTED change applied, not that the table is
	 * byte-identical to the declaration (the diff intentionally ignores defaults,
	 * charset/collation, comments, and the like).
	 *
	 * @since 3.1.0
	 * @var   bool|string[]
	 */
	protected $reconcile = array( 'add' );

	/**
	 * Compare the live table to its declared schema, returning the Patch.
	 *
	 * Introspects the current table structure (Schema::from_table) and diffs it
	 * against this table's declared schema. The returned Patch describes the
	 * changes needed to bring the live table up to the declared schema - columns
	 * and indexes to add, drop, or modify. Returns an empty Patch when there is no
	 * usable declared schema to compare against.
	 *
	 * Caveats:
	 *  - The introspected "actual" side may be INCOMPLETE (skipped unrepresentable
	 *    indexes, or a transient SHOW INDEX failure - see Schema::from_table()), so
	 *    a reported "added" index may already exist. diff() does not surface that;
	 *    snapshot() does (is_complete()), and reconcile() gates on it. Do not use a
	 *    bare diff() to authorize drops without confirming the capture was complete.
	 *  - Each call runs the introspection queries afresh; diverged() calls diff(),
	 *    so checking both re-introspects. Cache the Patch if you need it twice.
	 *
	 * The returned Patch is bound to this table, so its apply() / to_sql() can run
	 * (or render) the reconciling ALTERs. Apply is additive-and-modify by default;
	 * drops are opt-in (see Patch::apply()), which keeps an incomplete introspection
	 * from authorizing a destructive change.
	 *
	 * @since 3.1.0
	 *
	 * @return Patch
	 */
	public function diff(): Patch {

		// Actual (live) -> desired (declared): the migration direction.
		return $this->patch_against( Schema::from_table( $this->table_name ) );
	}

	/**
	 * Whether the live table differs from its declared schema.
	 *
	 * Sugar over diff(): true when the table needs changes to match the schema.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function diverged(): bool {
		return ! $this->diff()->is_empty();
	}

	/**
	 * Capture this live table's structure with its introspection-completeness signal.
	 *
	 * Sugar over Schema::snapshot() for this table. Unlike diff(), the Snapshot says
	 * whether the introspection was trustworthy (exists(), indexes_complete(),
	 * is_complete()) - which is what reconcile() gates on before acting.
	 *
	 * @since 3.1.0
	 *
	 * @return Snapshot
	 */
	public function snapshot(): Snapshot {
		return Schema::snapshot( $this->table_name );
	}

	/**
	 * Reconcile this live table to its declared schema by applying the difference.
	 *
	 * The declarative counterpart to a hand-written upgrade callback: it diffs the
	 * live table against the declared schema and runs the resulting ALTERs. Completes
	 * the diff() -> diverged() -> reconcile() lexicon.
	 *
	 * Safety:
	 *  - Captures a Snapshot first and DEFERS (returns false, changes nothing) if the
	 *    introspection is not complete - acting on a partial picture produces failed
	 *    ALTERs that never converge. Retry later against a complete capture.
	 *  - Additive by default ('add', 'modify'); drops only happen if named in
	 *    $operations. A no-op (already in sync) is a success.
	 *
	 * This applies only the changes the diff engine SUPPORTS - it does not reconcile
	 * column defaults, charset/collation, comments, scale, or ENUM/SET value lists
	 * (those are intentionally outside the diff), so a true return means "every
	 * supported change applied", not "table is byte-identical to the declaration".
	 *
	 * @since 3.1.0
	 *
	 * @param string[] $operations Operations to apply: 'add', 'modify', 'drop'.
	 *                             Defaults to the safe 'add' + 'modify'.
	 *
	 * @return Result Applied if the table is in sync afterward (or nothing to do);
	 *               deferred if the capture was incomplete (retry later); failed if
	 *               there is no declared schema or an ALTER failed.
	 */
	public function reconcile( array $operations = array( 'add', 'modify' ) ): Result {

		// Nothing to reconcile against without a real declared schema.
		if ( ! ( $this->schema_object instanceof Schema ) ) {
			return Result::failed( 'No declared schema to reconcile against.' );
		}

		// No operations means nothing to do - a no-op, and skip the introspection.
		if ( empty( $operations ) ) {
			return Result::applied( 0 );
		}

		// Capture the live table once, with its completeness signal.
		$snapshot = $this->snapshot();

		// Defer on an untrustworthy capture - never reconcile against a partial picture.
		if ( ! $snapshot->is_complete() ) {
			return Result::deferred();
		}

		// Diff the (complete) actual against the declared schema, then apply.
		return $this->patch_against( $snapshot->schema() )->apply( $operations );
	}

	/**
	 * Build a table-bound Patch diffing a captured "actual" schema against declared.
	 *
	 * Shared by diff() (actual from from_table()) and reconcile() (actual from a
	 * complete snapshot). Returns an empty bound Patch when there is no declared
	 * schema to compare against.
	 *
	 * @since 3.1.0
	 *
	 * @param Schema $actual The introspected (live) schema.
	 *
	 * @return Patch
	 */
	private function patch_against( Schema $actual ): Patch {

		// Nothing to compare against without a real declared schema.
		if ( ! ( $this->schema_object instanceof Schema ) ) {
			return ( new Patch() )->set_table( $this );
		}

		return $actual->diff( $this->schema_object )->set_table( $this );
	}

	/**
	 * Whether upgrade() should reconcile structural drift (the $reconcile opt-in).
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	private function wants_reconcile(): bool {
		return ( true === $this->reconcile )
			|| ( is_array( $this->reconcile ) && ! empty( $this->reconcile ) );
	}

	/**
	 * Resolve the $reconcile opt-in to a concrete operations list.
	 *
	 * An explicit array is used as-is; the bare `true` opt-in uses the safe default.
	 *
	 * @since 3.1.0
	 *
	 * @return string[]
	 */
	private function get_reconcile_operations(): array {
		return is_array( $this->reconcile )
			? $this->reconcile
			: array( 'add', 'modify' );
	}
}
