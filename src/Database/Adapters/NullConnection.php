<?php
/**
 * Null database connection adapter.
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
 * No-op Connection used when no real database handle is available.
 *
 * Returned by Environment::get_db_global() before the database global is
 * populated (e.g. before WordPress bootstraps wpdb). Every method returns an
 * inert, type-safe value that makes the caller's logic fall through to its
 * natural empty / failure branch without a special-case null check at every
 * call site.
 *
 * @since 3.0.0
 */
class NullConnection implements Connection {

	/** Query methods *************************************************************/

	/**
	 * @inheritDoc
	 */
	public function prepare( string $query, mixed ...$args ): string|null {
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function query( string $query ): int|bool {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function get_var( string|null $query = null, int $column_offset = 0, int $row_offset = 0 ): string|null {
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function get_row( string|null $query = null, string $output = 'OBJECT', int $y = 0 ): array|object|null {
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function get_results( string|null $query = null, string $output = 'OBJECT' ): array|object|null {
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function get_col( string|null $query = null, int $column_offset = 0 ): array {
		return array();
	}

	/**
	 * @inheritDoc
	 */
	public function insert( string $table, array $data, array|string|null $format = null ): int|false {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function update( string $table, array $data, array $where, array|string|null $format = null, array|string|null $where_format = null ): int|false {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function delete( string $table, array $where, array|string|null $where_format = null ): int|false {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function esc_like( string $text ): string {
		return $text;
	}

	/**
	 * @inheritDoc
	 */
	public function suppress_errors( bool $suppress = true ): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function get_blog_prefix( int|null $blog_id = null ): string {
		return '';
	}

	/** Properties ****************************************************************/

	/**
	 * @inheritDoc
	 */
	public function get_insert_id(): int {
		return 0;
	}

	/**
	 * @inheritDoc
	 */
	public function get_charset(): string {
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function get_collation(): string {
		return '';
	}

	/** Table registry ************************************************************/

	/**
	 * @inheritDoc
	 */
	public function get_table_prefix( string $key ): string {
		return $key;
	}

	/**
	 * @inheritDoc
	 */
	public function set_table_prefix( string $key, string $value ): void {}

	/**
	 * @inheritDoc
	 */
	public function register_table( string $group, string $name ): void {}
}
