<?php
/**
 * WordPress wpdb adapter.
 *
 * @package     BerlinDB\Database\Adapters
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Adapters;

use BerlinDB\Database\Interfaces\Connection;
use BerlinDB\Database\Interfaces\PlatformProvider;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adapts \wpdb to the BerlinDB Connection interface.
 *
 * Thin delegation layer - every Connection method proxies directly to the
 * wrapped \wpdb instance.  Property-based access on wpdb (insert_id, charset,
 * collate, dynamic table names) is surfaced as typed methods so callers only
 * depend on the Connection interface.
 *
 * Also implements PlatformProvider (#232): it can identify the underlying product
 * (MySQL / MariaDB / SQLite) so BerlinDB can degrade unsupported constructs.
 *
 * @since 3.0.0
 */
class Wpdb implements Connection, PlatformProvider {

	/**
	 * The underlying WordPress database object.
	 *
	 * @since 3.0.0
	 *
	 * @var \wpdb
	 */
	protected \wpdb $db;

	/**
	 * @since 3.0.0
	 *
	 * @param \wpdb $db WordPress database object.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/** Query Methods *********************************************************/

	/**
	 * @inheritDoc
	 */
	public function prepare( string $query, mixed ...$args ): string|null {
		return $this->db->prepare( $query, ...$args ) ?? null;
	}

	/**
	 * @inheritDoc
	 */
	public function query( string $query ): int|bool {
		return $this->db->query( $query );
	}

	/**
	 * @inheritDoc
	 */
	public function get_var( string|null $query = null, int $column_offset = 0, int $row_offset = 0 ): string|null {
		return $this->db->get_var( $query, $column_offset, $row_offset );
	}

	/**
	 * @inheritDoc
	 */
	public function get_row( string|null $query = null, string $output = 'OBJECT', int $y = 0 ): array|object|null {
		return $this->db->get_row( $query, $output, $y );
	}

	/**
	 * @inheritDoc
	 */
	public function get_results( string|null $query = null, string $output = 'OBJECT' ): array|object|null {
		return $this->db->get_results( $query, $output );
	}

	/**
	 * @inheritDoc
	 */
	public function get_col( string|null $query = null, int $column_offset = 0 ): array {
		return $this->db->get_col( $query, $column_offset );
	}

	/**
	 * @inheritDoc
	 */
	public function insert( string $table, array $data, array|string|null $format = null ): int|false {
		return $this->db->insert( $table, $data, $format );
	}

	/**
	 * @inheritDoc
	 */
	public function update( string $table, array $data, array $where, array|string|null $format = null, array|string|null $where_format = null ): int|false {
		return $this->db->update( $table, $data, $where, $format, $where_format );
	}

	/**
	 * @inheritDoc
	 */
	public function delete( string $table, array $where, array|string|null $where_format = null ): int|false {
		return $this->db->delete( $table, $where, $where_format );
	}

	/**
	 * @inheritDoc
	 */
	public function esc_like( string $text ): string {
		return $this->db->esc_like( $text );
	}

	/**
	 * @inheritDoc
	 */
	public function suppress_errors( bool $suppress = true ): bool {
		return $this->db->suppress_errors( $suppress );
	}

	/**
	 * @inheritDoc
	 */
	public function get_blog_prefix( int|null $blog_id = null ): string {
		return $this->db->get_blog_prefix( $blog_id );
	}

	/** Properties ************************************************************/

	/**
	 * @inheritDoc
	 */
	public function get_insert_id(): int {
		return (int) $this->db->insert_id;
	}

	/**
	 * @inheritDoc
	 */
	public function get_charset(): string {
		return (string) $this->db->charset;
	}

	/**
	 * @inheritDoc
	 */
	public function get_collation(): string {
		return (string) $this->db->collate;
	}

	/** Platform **************************************************************/

	/**
	 * @inheritDoc
	 *
	 * Detected fresh each call (not memoized): the adapter is cached and shared by
	 * Environment::get_db_global(), so a stale memo would let a runtime override
	 * (the 'berlindb_platform' filter) leak across callers. Detection is cheap - a
	 * class check plus db_server_info() (no query) - so re-running it is fine.
	 */
	public function platform(): Platform {
		return $this->detect_platform();
	}

	/**
	 * Identify the underlying database product and version.
	 *
	 * SQLite (WordPress Playground's SQLite Database Integration) is detected by
	 * its wpdb subclass, WP_SQLite_DB, because that subclass deliberately reports
	 * a FAKE MySQL version from db_version() ('8.0') to satisfy WP core - so the
	 * version cannot tell it apart, but the class identity can. MariaDB vs MySQL
	 * then splits on db_server_info(), which carries 'MariaDB' on MariaDB. The
	 * 'berlindb_platform' filter is the escape hatch for any driver these signals
	 * miss (it must return a Platform to take effect).
	 *
	 * @since 3.1.0
	 *
	 * @return Platform
	 */
	private function detect_platform(): Platform {

		// Default return value: unknown (permissive - assumes MySQL-family).
		$platform = Platform::unknown();

		/*
		 * Read the server string up front, while $this->db is still typed \wpdb -
		 * both real MySQL/MariaDB and the SQLite drop-in expose it (a wpdb method
		 * since 4.2). Doing it here, not inside the is_a() branch, keeps the call
		 * off the narrowed subclass.
		 */
		$server = (string) $this->db->db_server_info();

		// SQLite: identified by the drop-in wpdb subclass, not the (faked) version.
		if ( is_a( $this->db, 'WP_SQLite_DB' ) ) {
			$platform = new Platform( Platform::SQLITE, $server );

			// MySQL family: split MariaDB from MySQL on the server-info string.
		} elseif ( '' !== $server ) {
			$product  = ( false !== stripos( $server, 'mariadb' ) )
				? Platform::MARIADB
				: Platform::MYSQL;
			$platform = new Platform( $product, (string) $this->db->db_version() );
		}

		// Let a host override detection for a driver the signals above miss.
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'berlindb_platform', $platform, $this->db );

			if ( $filtered instanceof Platform ) {
				$platform = $filtered;
			}
		}

		return $platform;
	}

	/** Table Registry ********************************************************/

	/**
	 * @inheritDoc
	 */
	public function get_table_prefix( string $key ): string {
		return (string) ( $this->db->{$key} ?? '' );
	}

	/**
	 * @inheritDoc
	 */
	public function set_table_prefix( string $key, string $value ): void {
		$this->db->{$key} = $value;
	}

	/**
	 * @inheritDoc
	 */
	public function register_table( string $group, string $name ): void {
		if ( ! isset( $this->db->{$group} ) ) {
			$this->db->{$group} = array();
		}

		if ( ! in_array( $name, (array) $this->db->{$group}, true ) ) {
			$this->db->{$group}[] = $name;
		}
	}
}
