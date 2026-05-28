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

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adapts \wpdb to the BerlinDB Connection interface.
 *
 * Thin delegation layer — every Connection method proxies directly to the
 * wrapped \wpdb instance.  Property-based access on wpdb (insert_id, charset,
 * collate, dynamic table names) is surfaced as typed methods so callers only
 * depend on the Connection interface.
 *
 * @since 3.0.0
 */
class Wpdb implements Connection {

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
