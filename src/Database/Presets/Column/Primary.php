<?php
/**
 * Primary Column Preset.
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
 * A primary-key column is always a cache key.
 *
 * Triggered by `primary => true`. This is the smallest preset: it forces nothing but
 * cache_key, re-homing the lone `if ( primary ) cache_key = true` branch that used to
 * open Column::special_args(). It composes with Id (which sets primary => true) and is
 * independent of the column's type, so a primary on any column shape gets cached.
 *
 * @since 3.1.0
 */
final class Primary extends Base {

	/**
	 * Primary columns are expected (by Query) to always be cache keys.
	 *
	 * @since 3.1.0
	 * @var   array<string,mixed>
	 */
	protected const SHAPE = array(
		'cache_key' => true,
	);

	/**
	 * The preset key.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function key(): string {
		return 'primary';
	}
}
