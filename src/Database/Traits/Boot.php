<?php
/**
 * Boot Trait Class.
 *
 * @package     Database
 * @subpackage  Base
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
namespace BerlinDB\Database\Traits;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * The Boot Trait includes methods that fire when classes are constructed.
 *
 * @since 3.0.0
 */
trait Boot {

	/**
	 * Construct the table.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args
	 */
	public function __construct( $args = array() ) {
		$this->boot( $args );
	}

	/**
	 * Initialize the table.
	 *
	 * @since 3.0.0
	 */
	protected function boot( $args = array() ) {

		// Early.
		$this->sunrise();

		// Parse arguments.
		$r = $this->parse_args( $args );

		// Maybe set variables from arguments.
		if ( ! empty( $r ) ) {
			$this->set_vars( $r );
		}

		// Initialize.
		$this->init();
	}

	/**
	 * Called early, before arguments are parsed.
	 *
	 * @since 3.0.0
	 */
	protected function sunrise() {

	}

	/** Argument Handlers *****************************************************/

	/**
	 * Parse arguments.
	 *
	 * @since 3.0.0 Arguments are stashed. Bails if $args is empty.
	 * @param array $args Default empty array.
	 * @return array
	 */
	protected function parse_args( $args = array() ) {

		// Stash the arguments
		$this->stash_args( $args );

		// Bail if no arguments
		if ( empty( $args ) ) {
			return array();
		}

		// Parse arguments
		$r = wp_parse_args( $args, $this->args['class'] );

		// Force some arguments for special column types
		$r = $this->special_args( $r );

		// Set the arguments before they are validated & sanitized
		$this->set_vars( $r );

		// Return array
		return $this->validate_args( $r );
	}

	/**
	 * Parse special arguments.
	 *
	 * @since 3.0.0
	 * @param array $args
	 * @return array
	 */
	protected function special_args( $args = array() ) {
		return $args;
	}

	/**
	 * Validate arguments.
	 *
	 * @since 3.0.0
	 * @param array $args
	 * @return array
	 */
	protected function validate_args( $args = array() ) {
		return $args;
	}

	/**
	 * Initialize.
	 *
	 * @since 3.0.0
	 */
	protected function init() {

	}
}
