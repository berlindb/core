<?php
/**
 * Base Custom Database Table Row Class.
 *
 * @package     Database
 * @subpackage  Row
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Kern;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base database row class.
 *
 * This class exists solely for other classes to extend (and to encapsulate
 * database schema changes for those objects) to help separate the needs of the
 * application layer from the requirements of the database layer.
 *
 * For example, if a database column is renamed or a return value needs to be
 * formatted differently, this class will make sure old values are still
 * supported and new values do not conflict.
 *
 * @since 1.0.0
 */
#[\AllowDynamicProperties]
class Row {

	/**
	 * Use the following traits:
	 *
	 * @since 3.0.0
	 */
	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;

	/** Properties ************************************************************/

	/**
	 * Name of the primary key column for this row.
	 *
	 * Override in subclasses when the primary key is not named 'id'.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $primary_column = 'id';

	/** Methods ***************************************************************/

	/**
	 * Determines whether the current row exists.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function exists() {
		return ! empty( $this->{$this->primary_column} );
	}
}
