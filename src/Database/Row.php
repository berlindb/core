<?php
/**
 * Base Custom Database Table Row Class.
 *
 * @package     Database
 * @subpackage  Row
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */
namespace BerlinDB\Database;

// Exit if accessed directly
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
class Row {

	/**
	 * Use the following traits:
	 *
	 * @since 3.0.0
	 */
	use Traits\Base;
	use Traits\Boot;

	/** Methods ***************************************************************/

	/**
	 * Determines whether the current row exists.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function exists() {
		return ! empty( $this->id );
	}
}
