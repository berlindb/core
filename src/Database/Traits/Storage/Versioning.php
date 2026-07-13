<?php
/**
 * Storage relation versioning trait.
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
 * Tracks the installed version of a storage relation (a table or a view).
 *
 * The declared $version is compared against the $db_version stored in options
 * under $db_version_key to decide whether the relation needs (re)installing or
 * upgrading. Extracted from Table (#237) so Table and View both compose it.
 *
 * A relation that does not persist across the session (a TEMPORARY table)
 * overrides persists_relation_version() to false, so set_db_version() no-ops -
 * a stored version would outlive the relation and mislead the install logic.
 *
 * Requires the composing class to provide sanitize_key(), is_global(), and the
 * $db_global / $prefixed_name identity (Environment + Storage\Registration).
 *
 * @since 3.1.0
 */
trait Versioning {

	/**
	 * Declared (target) version of this relation's definition.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $version = '';

	/**
	 * Option key the installed version is stored under (in _options or _sitemeta).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $db_version_key = '';

	/**
	 * Currently installed version, read from storage.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $db_version = '';

	/**
	 * Return the currently installed version from the database.
	 *
	 * This is a public method for accessing a protected variable so that it
	 * cannot be externally modified.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_version(): string {
		$this->get_db_version();

		return (string) $this->db_version;
	}

	/**
	 * Return whether this relation has been installed.
	 *
	 * Checks for a stored database version, which is written by install() and
	 * cleared by uninstall(). This is always a cache hit - the version option
	 * is autoloaded and served from WordPress's in-memory options cache.
	 *
	 * Compares against the empty string rather than using empty(), so a relation
	 * whose version is the string '0' is still correctly reported as installed.
	 *
	 * @since 3.1.0
	 *
	 * @return bool True if a version is stored, false if not.
	 */
	public function is_installed(): bool {
		return ( $this->get_version() !== '' );
	}

	/**
	 * Whether this relation persists an installed version across the session.
	 *
	 * The default is true; a relation that does not survive its session (a
	 * TEMPORARY table) overrides this to false so set_db_version() no-ops.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	protected function persists_relation_version(): bool {
		return true;
	}

	/**
	 * Build the database version key, unless one was already provided.
	 *
	 * @since 3.1.0
	 */
	private function set_db_version_key(): void {

		// Bail if a version key was explicitly set.
		if ( ! empty( $this->db_version_key ) ) {
			return;
		}

		$this->db_version_key = implode(
			'_',
			array(
				$this->sanitize_key( $this->db_global ),
				$this->prefixed_name,
				'version',
			)
		);
	}

	/**
	 * Set the installed version in the database.
	 *
	 * @since 1.0.0
	 *
	 * @param string $version Database version to set when upgrading/creating.
	 */
	private function set_db_version( $version = '' ): void {

		/*
		 * A relation that does not persist a version never stores one: it would
		 * outlive the relation and mislead the install logic. Enforced here so
		 * EVERY caller (install, upgrade, upgrade_to) is covered.
		 */
		if ( ! $this->persists_relation_version() ) {
			return;
		}

		// If no version is passed during an upgrade, use the current version.
		if ( empty( $version ) ) {
			$version = $this->version;
		}

		/*
		 * Update the DB version. Autoload is explicit so the option is always
		 * served from WordPress's in-memory options cache rather than a live query.
		 */
		$this->is_global()
			? update_network_option( get_main_network_id(), $this->db_version_key, $version )
			: update_option( $this->db_version_key, $version, true );

		// Set the DB version.
		$this->db_version = $version;
	}

	/**
	 * Read the installed version from the database into $db_version.
	 *
	 * @since 1.0.0
	 */
	private function get_db_version(): void {

		// Get the DB version.
		$db_version = $this->is_global()
			? get_network_option( get_main_network_id(), $this->db_version_key, '' )
			: get_option( $this->db_version_key, '' );

		// Set the DB version.
		$this->db_version = (string) $db_version;
	}

	/**
	 * Delete the installed version from the database.
	 *
	 * @since 1.0.0
	 */
	private function delete_db_version(): void {
		$this->is_global()
			? delete_network_option( get_main_network_id(), $this->db_version_key )
			: delete_option( $this->db_version_key );

		$this->db_version = '';
	}
}
