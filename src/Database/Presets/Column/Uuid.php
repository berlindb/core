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
 * A urn:uuid: identifier column: varchar(100), generated on insert.
 *
 * Triggered by `uuid => true`. Validation by format stays on Column (validate_uuid(),
 * keyed on the $uuid mirror flag); this preset only shapes and generates the value.
 *
 * @since 3.1.0
 */
final class Uuid extends Base {

	use Generator;

	/**
	 * Force a non-searchable, non-sortable varchar(100) string.
	 *
	 * @since 3.1.0
	 * @var   array<string,mixed>
	 */
	protected const SHAPE = array(
		'type'       => 'varchar',
		'length'     => '100',
		'pattern'    => '%s',
		'in'         => false,
		'not_in'     => false,
		'searchable' => false,
		'sortable'   => false,
	);

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
	 * Generate on insert when empty; unset on copy so a new row regenerates.
	 *
	 * @since 3.1.0
	 *
	 * @param string $method   insert|update|select|delete|copy.
	 * @param mixed  $value    Incoming value.
	 * @param Column $column   The column.
	 * @param bool   $provided Whether the caller supplied this column. Default true.
	 * @return mixed
	 */
	public function intercept( string $method, $value, Column $column, bool $provided = true ) {
		if ( 'copy' === $method ) {
			return $column->get_unset_sentinel();
		}

		if ( ( 'insert' === $method ) && empty( $value ) ) {
			return $this->generate_uuid();
		}

		return $value;
	}
}
