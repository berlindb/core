<?php
/**
 * Modified-date Column Preset.
 *
 * @package     Database
 * @subpackage  Presets
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Presets\Column;

use BerlinDB\Database\Kern\Column;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A modified-date column: stamps the current UTC time on every update (and on insert).
 *
 * @since 3.1.0
 */
final class Modified extends Base {

	/**
	 * The preset key.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function key(): string {
		return 'modified';
	}

	/**
	 * Default name when none is supplied.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function default_name(): string {
		return 'date_modified';
	}

	/**
	 * Stamp on every update, and on insert when empty or still the column default.
	 *
	 * @since 3.1.0
	 *
	 * @param string $method insert|update|select|delete|copy.
	 * @param mixed  $value  Incoming value.
	 * @param Column $column The column.
	 * @return mixed
	 */
	public function intercept( string $method, $value, Column $column ) {
		if ( 'update' === $method ) {
			return gmdate( 'Y-m-d H:i:s' );
		}

		if ( ( 'insert' === $method ) && ( empty( $value ) || ( $value === $column->default ) ) ) {
			return gmdate( 'Y-m-d H:i:s' );
		}

		return $value;
	}
}
