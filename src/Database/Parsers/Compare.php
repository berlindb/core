<?php
/**
 * Compare Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Parsers
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
declare( strict_types = 1 );

namespace BerlinDB\Database\Parsers;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class used for generating SQL for arbitrary column comparison clauses.
 *
 * This class generates SQL when `key` and `value` arguments are passed,
 * supporting all standard comparison operators via the `compare` key.
 * All required methods are provided by the Traits\Parser trait via Base.
 *
 * @since 3.0.0
 */
class Compare extends Base {

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'compare';

	/**
	 * @since 3.0.0
	 * @var string|null
	 */
	protected $query_var = 'compare_query';

	/**
	 * @since 3.0.0
	 * @var array<string, bool>
	 */
	protected $column_filter = array( 'primary' => true );

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $column_suffix = '_compare';

	/**
	 * @since 3.0.0
	 * @var mixed
	 */
	protected $default = null;

	/**
	 * Determines and validates what first-order keys to use.
	 *
	 * @since 3.0.0
	 *
	 * @param list<string> $first_keys Array of first-order keys.
	 *
	 * @return list<string> The first-order keys.
	 */
	protected function get_first_keys( $first_keys = array() ) {
		return array( 'key', 'value' );
	}
}
