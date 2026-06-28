<?php
/**
 * WordPress meta-value Column Preset.
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
 * The value column of a WordPress-style key/value meta table: nullable longtext.
 *
 * The flag is namespaced `wp_meta_value` to match WpMetaKey and to avoid the bare
 * `meta_value` tripping WordPress.DB.SlowDBQuery. The produced column keeps the
 * conventional name `meta_value` for metadata compatibility. Pairs with WpMetaKey.
 *
 * @since 3.1.0
 */
final class WpMetaValue extends Base {

	/**
	 * Force a nullable longtext.
	 *
	 * @since 3.1.0
	 * @var   array<string,mixed>
	 */
	protected const SHAPE = array(
		'type'       => 'longtext',
		'allow_null' => true,
	);

	/**
	 * The preset key (and its declaration flag).
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function key(): string {
		return 'wp_meta_value';
	}

	/**
	 * Default name when none is supplied (the conventional WordPress column name).
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function default_name(): string {
		return 'meta_value';
	}
}
