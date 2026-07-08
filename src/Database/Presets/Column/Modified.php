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
 * Defaults the column to datetime, but respects a caller's date-bearing type
 * (DATE/DATETIME/TIMESTAMP) - unlike Uuid or Version (one canonical type), that
 * choice belongs to the caller. Beyond the type floor it owns only the stamp.
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
	 * Default to datetime unless the caller chose a date-bearing type.
	 *
	 * A date stamp must land in a date column, but DATE/DATETIME/TIMESTAMP is the
	 * caller's choice (is_date()); only a missing or non-date type is forced.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args   Incoming column args.
	 * @param Column              $column The column being shaped.
	 * @return array<string,mixed>
	 */
	public function set_args( array $args, Column $column ): array {
		if ( empty( $args[ 'type' ] ) || ! $column->is_date( $args[ 'type' ] ) ) {
			$args[ 'type' ] = 'datetime';
		}

		return $args;
	}

	/**
	 * Stamp on every update, and on insert when empty or still the column default.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed   $value   Incoming value.
	 * @param Context $context The intercept context (method, column, provided).
	 * @return mixed
	 */
	public function intercept( $value, Context $context ) {
		if ( 'update' === $context->method() ) {
			return gmdate( 'Y-m-d H:i:s' );
		}

		$column = $context->column();

		if ( ( 'insert' === $context->method() ) && ( empty( $value ) || ( $value === $column->default ) ) ) {
			return gmdate( 'Y-m-d H:i:s' );
		}

		return $value;
	}
}
