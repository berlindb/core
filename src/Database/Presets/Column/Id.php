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
 * This preset only PRODUCES the standard primary-id args (it SETS primary => true);
 * it does not replace the `primary` property or change how Query reasons about the
 * primary column - $column->primary stays the single authority. It consolidates the
 * id shape that was split across the SERIAL/AUTO_INCREMENT alias switch and the
 * primary branch of Column::special_args().
 *
 * @since 3.1.0
 */
final class Id extends Base {

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

	/**
	 * Set the shape onto the conventional auto-increment primary key.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Incoming args.
	 * @return array<string,mixed>
	 */
	public function set_args( array $args ): array {
		$args[ 'type' ]       = 'bigint';
		$args[ 'length' ]     = '20';
		$args[ 'unsigned' ]   = true;
		$args[ 'primary' ]    = true;
		$args[ 'extra' ]      = 'AUTO_INCREMENT';
		$args[ 'cache_key' ]  = true;
		$args[ 'allow_null' ] = false;
		$args[ 'default' ]    = false;
		$args[ 'pattern' ]    = '%d';
		$args[ 'sortable' ]   = true;

		return $args;
	}
}
