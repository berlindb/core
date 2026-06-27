<?php
/**
 * UUID Column Preset.
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
use BerlinDB\Database\Traits\Generator;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A urn:uuid: identifier column: varchar(100), generated on insert, validated by format.
 *
 * Provides a specialty validate() (Column detects it the same way sanitize_validation
 * already detects callbacks - via is_callable - so no marker interface is needed).
 *
 * @since 3.1.0
 */
final class Uuid extends Base {

	use Generator;

	/**
	 * The preset key.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function key(): string {
		return 'uuid';
	}

	/**
	 * Default name when none is supplied.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function default_name(): string {
		return 'uuid';
	}

	/**
	 * Shape the column as a non-searchable, non-sortable varchar(100) string.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Incoming args.
	 * @return array<string,mixed>
	 */
	public function set_args( array $args ): array {
		$args[ 'type' ]       = 'varchar';
		$args[ 'length' ]     = '100';
		$args[ 'pattern' ]    = '%s';
		$args[ 'in' ]         = false;
		$args[ 'not_in' ]     = false;
		$args[ 'searchable' ] = false;
		$args[ 'sortable' ]   = false;

		return $args;
	}

	/**
	 * Validate by format: a urn:uuid: string passes; anything else falls to the default.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed  $value  Value to validate.
	 * @param Column $column The column (for its default).
	 * @return string
	 */
	public function validate( $value, Column $column ): string {
		return ( is_string( $value ) && ( 0 === strpos( $value, 'urn:uuid:' ) ) )
			? $value
			: (string) $column->default;
	}

	/**
	 * Generate on insert when empty; unset on copy so a new row regenerates.
	 *
	 * @since 3.1.0
	 *
	 * @param string $method insert|update|select|delete|copy.
	 * @param mixed  $value  Incoming value.
	 * @param Column $column The column.
	 * @return mixed
	 */
	public function intercept( string $method, $value, Column $column ) {
		if ( 'copy' === $method ) {
			return $column->get_unset_sentinel();
		}

		if ( ( 'insert' === $method ) && empty( $value ) ) {
			return $this->generate_uuid();
		}

		return $value;
	}
}
