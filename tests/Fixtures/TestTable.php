<?php
/**
 * Test Table fixture for BerlinDB integration tests.
 *
 * @package     BerlinDB\Tests\Fixtures
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests\Fixtures;

use BerlinDB\Database\Table;

/**
 * Concrete Table implementation for test_widgets table.
 *
 * Using a raw SQL string in set_schema() is the standard convention for Table.
 * The Schema object is used by the Query, not the Table.
 *
 * The $upgrades array registers a v2 upgrade callback that adds a
 * 'notes' column, allowing tests to verify the upgrade flow.
 *
 * @since 2.1.0
 */
final class TestTable extends Table {

	/**
	 * Table name (without wpdb prefix).
	 *
	 * @since 2.1.0
	 * @var string
	 */
	protected $name = 'berlindb_test_widgets';

	/**
	 * Current table version.
	 *
	 * Uses EDD-style date-based version numbers (YYYYMMDDN as string).
	 * Must be a string because Table.php declares strict_types=1 and
	 * version_compare() requires string arguments.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	protected $version = '202604230';

	/**
	 * Upgrade callbacks keyed by the version they upgrade to.
	 *
	 * The dot suffix prevents PHP from coercing the key to int. Pure-numeric
	 * string keys (e.g. '202604231') become int at runtime, and Table.php's
	 * declare(strict_types=1) then causes a TypeError in version_compare().
	 *
	 * @since 2.1.0
	 * @var array
	 */
	protected $upgrades = array(
		'202604231' => '__202604231',
	);

	/**
	 * Set the table schema as a raw SQL string.
	 *
	 * @since 2.1.0
	 */
	protected function set_schema() {
		$this->schema =
			'id bigint(20) unsigned NOT NULL auto_increment,' .
			"name varchar(200) NOT NULL default ''," .
			"status varchar(20) NOT NULL default 'active'," .
			'priority bigint(20) unsigned NOT NULL default 0,' .
			'date_created datetime NOT NULL default CURRENT_TIMESTAMP,' .
			'date_modified datetime NOT NULL default CURRENT_TIMESTAMP,' .
			"uuid varchar(100) NOT NULL default ''," .
			'PRIMARY KEY (id),' .
			'KEY status (status)';
	}

	/**
	 * Upgrade to version 2: add a notes column.
	 *
	 * Used by test_upgrade_runs_callback_and_adds_column().
	 *
	 * @since 2.1.0
	 * @return bool
	 */
	protected function __202604231() {
		if ( ! $this->column_exists( 'notes' ) ) {
			$result = $this->get_db()->query(
				"ALTER TABLE {$this->table_name} ADD COLUMN notes longtext NOT NULL default ''"
			);
			return $this->is_success( $result );
		}
		return true;
	}

	/**
	 * Expose the db_version_key for test manipulation.
	 *
	 * @since 2.1.0
	 * @return string
	 */
	public function get_db_version_key() {
		return $this->db_version_key;
	}

	/**
	 * Expose the schema version constant for test assertions.
	 *
	 * @since 2.1.0
	 * @return string
	 */
	public function get_schema_version(): string {
		return $this->version;
	}
}
