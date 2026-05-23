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
	 * Stashed copy of constructor arguments and initial property values.
	 *
	 * Set by stash_args() during construction. Keys:
	 *   'param' — the raw $args passed to __construct()
	 *   'class' — snapshot of all object properties at construction time
	 *
	 * @since 3.0.0
	 * @var   array<string, mixed>
	 */
	protected $args = array();

	/**
	 * Construct the table.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args
	 */
	public function __construct( $args = array() ) {
		$this->boot( $args );
	}

	/**
	 * Initialize the table.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed>|object $args
	 */
	protected function boot( $args = array() ): void {

		// Row subclasses pass a raw stdClass from the database — normalize to array.
		if ( is_object( $args ) ) {
			$args = (array) $args;
		}

		$this->run(
			function () use ( $args ) {

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
		);
	}

	/**
	 * Called early, before arguments are parsed.
	 *
	 * @since 3.0.0
	 */
	protected function sunrise(): void {}

	/**
	 * Initialize.
	 *
	 * @since 3.0.0
	 */
	protected function init(): void {}

	/** Argument Handlers *****************************************************/

	/**
	 * Parse arguments.
	 *
	 * @since 3.0.0 Arguments are stashed. Bails if $args is empty.
	 * @param array<string, mixed> $args Default empty array.
	 * @return array<string, mixed>
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
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	protected function special_args( $args = array() ) {
		return $args;
	}

	/**
	 * Validate arguments.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	protected function validate_args( $args = array() ) {
		return $args;
	}

	/**
	 * Stash arguments and class variables.
	 *
	 * Captures a snapshot of the constructor arguments and the object's
	 * current property values so parse_args() can merge against them and
	 * callers can compare, reuse, or reset to a prior state.
	 *
	 * get_object_vars() is called from within the trait, so it captures all
	 * properties visible in this scope — including protected ones — not just
	 * public properties as it would from an external caller.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $args
	 * @return void
	 */
	protected function stash_args( $args = array() ) {
		$this->args = array(
			'param' => $args,
			'class' => get_object_vars( $this ),
		);
	}
}
