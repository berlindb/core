<?php
/**
 * Primary-ID Column Preset.
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
 * The conventional auto-increment primary key: unsigned bigint(20), AUTO_INCREMENT.
 *
 * Triggered by `id => true`, a one-flag shorthand for the full primary-id shape. It
 * SETS primary => true (so the Primary preset's cache_key follows) but does not
 * replace the `primary` property: $column->primary stays the single authority Query
 * reasons about. Distinct from Serial, which preserves a caller's own integer type.
 *
 * @since 3.1.0
 */
final class Id extends Base {

	/**
	 * Force the conventional auto-increment primary key shape.
	 *
	 * @since 3.1.0
	 * @var   array<string,mixed>
	 */
	protected const SHAPE = array(
		'type'       => 'bigint',
		'length'     => '20',
		'unsigned'   => true,
		'primary'    => true,
		'extra'      => 'AUTO_INCREMENT',
		'cache_key'  => true,
		'allow_null' => false,
		'default'    => false,
		'pattern'    => '%d',
		'sortable'   => true,
	);

	/**
	 * The preset key.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function key(): string {
		return 'id';
	}

	/**
	 * Default name when none is supplied.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function default_name(): string {
		return 'id';
	}
}
