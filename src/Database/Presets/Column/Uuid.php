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
	 * @param mixed   $value   Incoming value.
	 * @param Context $context The intercept context (method, column, provided).
	 * @return mixed
	 */
	public function intercept( $value, Context $context ) {
		if ( 'copy' === $context->method() ) {
			return $context->unset_value();
		}

		if ( ( 'insert' === $context->method() ) && empty( $value ) ) {
			return $this->generate_uuid();
		}

		return $value;
	}
}
