<?php
/**
 * WordPress meta-key Column Preset.
 *
 * @package     Database
 * @subpackage  Presets
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Presets\Column;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The key column of a WordPress-style key/value meta table: varchar(191).
 *
 * 191 is the utf8mb4 index-safe length WordPress uses for indexed meta keys - a
 * WordPress convention, so the flag is namespaced `wp_meta_key` (the bare `meta_key`
 * would also trip WordPress.DB.SlowDBQuery, which reads it as a meta_query key). The
 * produced column keeps the conventional name `meta_key` for metadata compatibility.
 * Pairs with WpMetaValue; the Meta recipe builds its EAV schema from both.
 *
 * @since 3.1.0
 */
final class WpMetaKey extends Base {

	/**
	 * Force an indexed-length varchar.
	 *
	 * @since 3.1.0
	 * @var   array<string,mixed>
	 */
	protected const SHAPE = array(
		'type'   => 'varchar',
		'length' => '191',
	);

	/**
	 * The preset key (and its declaration flag).
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function key(): string {
		return 'wp_meta_key';
	}

	/**
	 * Default name when none is supplied (the conventional WordPress column name).
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function default_name(): string {
		return 'meta_key';
	}
}
