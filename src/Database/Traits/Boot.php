<?php
/**
 * Boot Trait Class.
 *
 * @package     Database
 * @subpackage  Base
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Boot Trait includes methods that fire when classes are constructed.
 *
 * It uses the Lifecycle Trait, making start(), finish(), run(), and $current
 * available to every class that uses Boot. During construction, boot() wraps
 * the construction sequence inside run() so that the full lifecycle is:
 *
 *   __construct → boot → run() → start → sunrise → parse_args → set_vars → init → finish
 *
 * The run() wrapper guarantees finish() fires even if an exception is thrown
 * during construction.
 *
 * @since 3.0.0
 */
trait Boot {

	use Lifecycle;

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
		$this->run( function() use ( $args ) {

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
		} );
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

		// Stash the arguments.
		$this->stash_args( $args );

		// Bail if no arguments.
		if ( empty( $args ) ) {
			return array();
		}

		// Parse arguments.
		$r = wp_parse_args( $args, $this->args['class'] );

		// Force some arguments for special column types.
		$r = $this->special_args( $r );

		// Set the arguments before they are validated & sanitized.
		$this->set_vars( $r );

		// Return array.
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
