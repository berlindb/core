<?php
/**
 * Storage relation installation lifecycle trait.
 *
 * @package     BerlinDB\Database\Traits\Storage
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits\Storage;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The install / upgrade / uninstall lifecycle every storage relation (a table or
 * a view) shares.
 *
 * This trait owns the ORCHESTRATION - when to (re)install, when to upgrade, the
 * lock, and the uninstall tombstone. The relation-specific SQL it drives -
 * create(), drop(), exists(), upgrade() - is provided by the composing class via
 * the Interfaces\Installable contract, so a table and a view share this lifecycle
 * while each emits its own DDL. Extracted from Table (#237).
 *
 * A non-persistent relation (persists_relation_version() false, i.e. a TEMPORARY
 * table) is not auto-upgraded and writes no tombstone - it is (re)created fresh
 * within a session, never migrated across them. That hook lives in
 * Traits\Storage\Versioning, always composed alongside this trait.
 *
 * @since 3.1.0
 */
trait Installation {

	/**
	 * Install this relation: create it and record its version.
	 *
	 * Clears any uninstall tombstone so that automatic upgrades resume normally.
	 *
	 * @since 1.0.0
	 */
	public function install(): void {

		// Try to create the relation.
		$created = $this->create();

		/*
		 * Record the version if create succeeded (a non-persistent relation stores
		 * none - set_db_version() no-ops for it).
		 */
		if ( true === $created ) {
			$this->set_db_version();
			$this->delete_uninstalled();
		}
	}

	/**
	 * Uninstall this relation: drop it and clear its version.
	 *
	 * If the relation does not exist, the version is still deleted. Writes an
	 * uninstall tombstone so maybe_upgrade() does not recreate it automatically.
	 *
	 * @since 1.0.0
	 */
	public function uninstall(): void {

		// Try to drop the relation.
		$dropped = $this->drop();

		/*
		 * Clear the version if drop succeeded or the relation does not exist (a
		 * non-persistent relation persisted nothing - these no-op for it).
		 */
		if ( ( true === $dropped ) || ! $this->exists() ) {
			$this->delete_db_version();
			$this->set_uninstalled();
		}
	}

	/**
	 * Install or upgrade this relation if it needs it.
	 *
	 * Handles locking, creation, and version changes. Hooked to `admin_init`.
	 *
	 * @since 1.0.0
	 */
	public function maybe_upgrade(): void {

		/*
		 * A non-persistent relation (a TEMPORARY table) is (re)created fresh within
		 * a session at the current schema, never migrated across sessions - so there
		 * is nothing to compare or upgrade.
		 */
		if ( ! $this->persists_relation_version() ) {
			return;
		}

		// Bail if not upgradeable.
		if ( ! $this->is_upgradeable() ) {
			return;
		}

		// Bail if upgrade not needed.
		if ( ! $this->needs_upgrade() ) {
			return;
		}

		// Bail if locked.
		if ( ! $this->lock_upgrades() ) {
			return;
		}

		// Upgrade or install, always release the lock afterward.
		try {

			// Upgrade.
			if ( $this->exists() ) {
				$this->upgrade();

				// Install.
			} else {
				$this->install();
			}
		} finally {

			// Always release the lock, even if an exception occurred.
			$this->unlock_upgrades();
		}
	}

	/**
	 * Return whether this relation needs an upgrade.
	 *
	 * @since 1.0.0
	 *
	 * @param string $version Database version to check if upgrade is needed.
	 *
	 * @return bool True if the relation needs upgrading. False if not.
	 */
	public function needs_upgrade( $version = '' ) {

		// Use the current relation version if none was passed.
		if ( empty( $version ) ) {
			$version = $this->version;
		}

		// Get the current database version.
		$this->get_db_version();

		// Is this relation up to date?
		$is_current = version_compare( (string) $this->db_version, (string) $version, '>=' );

		// Return false if current, true if out of date.
		return ( true === $is_current )
			? false
			: true;
	}

	/**
	 * Return whether this relation can be upgraded.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the relation can be upgraded. False if not.
	 */
	public function is_upgradeable() {

		// Bail if global and upgrading global tables is not allowed.
		if ( $this->is_global() && ! wp_should_upgrade_global_tables() ) {
			return false;
		}

		// Bail if the relation was intentionally uninstalled.
		if ( $this->is_uninstalled() ) {
			return false;
		}

		// Kinda weird, but assume it is.
		return true;
	}

	/**
	 * Lock upgrades.
	 *
	 * Prevents multiple upgrade processes from running simultaneously on the
	 * same relation. Uses a transient with a 15-minute expiration to ensure the
	 * lock is automatically released even if the upgrade process fails.
	 *
	 * @since 3.0.0
	 *
	 * @return bool True if the lock was created, false if a lock already exists.
	 */
	protected function lock_upgrades(): bool {

		// Generate a unique lock key for this relation.
		$lock_key = $this->db_version_key . '_upgrade_lock';

		// Check if a lock already exists.
		$lock_exists = $this->is_global()
			? get_site_transient( $lock_key )
			: get_transient( $lock_key );

		// If a lock already exists, return false.
		if ( false !== $lock_exists ) {
			return false;
		}

		// Create the lock transient.
		$lock_set = $this->is_global()
			? set_site_transient( $lock_key, time(), 900 )
			: set_transient( $lock_key, time(), 900 );

		// Return whether the lock was successfully created.
		return (bool) $lock_set;
	}

	/**
	 * Unlock upgrades.
	 *
	 * Removes the transient that was set by lock_upgrades(), allowing other
	 * upgrade processes to proceed.
	 *
	 * @since 3.0.0
	 *
	 * @return bool True if the lock was released, false otherwise.
	 */
	protected function unlock_upgrades(): bool {

		// Generate the same lock key used in lock_upgrades().
		$lock_key = $this->db_version_key . '_upgrade_lock';

		// Delete the lock transient.
		$deleted = $this->is_global()
			? delete_site_transient( $lock_key )
			: delete_transient( $lock_key );

		// Return whether the lock was successfully released.
		return (bool) $deleted;
	}

	/**
	 * Return whether this relation was intentionally uninstalled.
	 *
	 * Reads the uninstall tombstone from persistent storage. When true,
	 * maybe_upgrade() will not automatically recreate the relation.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	protected function is_uninstalled(): bool {
		$value = $this->is_global()
			? get_network_option( get_main_network_id(), $this->db_version_key . '_uninstalled', false )
			: get_option( $this->db_version_key . '_uninstalled', false );

		return ! empty( $value );
	}

	/**
	 * Write the uninstall tombstone to persistent storage.
	 *
	 * @since 3.1.0
	 */
	protected function set_uninstalled(): void {

		/*
		 * A non-persistent relation never auto-installs, so a tombstone would be a
		 * meaningless orphan option; it persists nothing to gate.
		 */
		if ( ! $this->persists_relation_version() ) {
			return;
		}

		$this->is_global()
			? update_network_option( get_main_network_id(), $this->db_version_key . '_uninstalled', '1' )
			: update_option( $this->db_version_key . '_uninstalled', '1', true );
	}

	/**
	 * Remove the uninstall tombstone from persistent storage.
	 *
	 * @since 3.1.0
	 */
	protected function delete_uninstalled(): void {
		$this->is_global()
			? delete_network_option( get_main_network_id(), $this->db_version_key . '_uninstalled' )
			: delete_option( $this->db_version_key . '_uninstalled' );
	}

	/**
	 * Try to get a callable upgrade, with some magic to avoid needing to
	 * do this dance repeatedly inside subclasses.
	 *
	 * @since 1.0.0
	 *
	 * @param string $callback Callback function name or callable.
	 *
	 * @return callable|false Resolved callable, or false if not callable.
	 */
	protected function get_callable( $callback = '' ): callable|false {

		// Default return value.
		$callable = $callback;

		// Look for global function.
		if ( ! is_callable( $callable ) ) {

			// Fallback to local class method.
			$callable = array( $this, $callback );
			if ( ! is_callable( $callable ) ) {

				// Fallback to class method prefixed with "__".
				$callable = array( $this, "__{$callback}" );
				if ( ! is_callable( $callable ) ) {
					$callable = false;
				}
			}
		}

		// Return callable string, or false if not callable.
		return $callable;
	}
}
