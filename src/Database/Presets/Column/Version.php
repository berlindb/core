<?php
/**
 * Version Column Preset.
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
 * An optimistic-lock version column: unsigned bigint, NOT NULL, default 0.
 *
 * Declaration only. The token simply exists and validates as an int (no intercept,
 * no specialty validator). The increment-on-update and the WHERE-version guard that
 * turn it into a working optimistic lock are a later, opt-in issue (#218); a column
 * declared now reserves the schema slot so no migration is needed when that ships.
 *
 * @since 3.1.0
 */
final class Version extends Base {

	/**
	 * Force an unsigned bigint(20), NOT NULL, default 0 - the ID column's numeric
	 * shape minus auto_increment/primary.
	 *
	 * @since 3.1.0
	 * @var   array<string,mixed>
	 */
	protected const SHAPE = array(
		'type'       => 'bigint',
		'length'     => '20',
		'unsigned'   => true,
		'default'    => 0,
		'allow_null' => false,
		'pattern'    => '%d',
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
		return 'version';
	}

	/**
	 * Default name when none is supplied.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function default_name(): string {
		return 'version';
	}
}
