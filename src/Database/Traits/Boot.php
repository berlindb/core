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
 *   __construct → boot → run() → start → configure → sunrise → parse_args → set_vars → init → finish
 *
 * The run() wrapper guarantees finish() fires even if an exception is thrown
 * during construction.
 *
 * Hook contract — a class overrides these as needed, and should NOT invent its
 * own per-class lifecycle methods:
 *   - configure(): assign properties from an explicit $config definition. Runs
 *     first, so sunrise()/init() see the configured identity. Define-once
 *     (a no-op once is_booted()).
 *   - sunrise(): pre-args setup. Rare — only when state must be ready before
 *     parse_args() runs (e.g. Query prepares its parsers before it queries).
 *   - init(): the normal home for post-args construction. Runs after set_vars(),
 *     so it sees subclass-declared, $args, and $config values alike.
 *
 * @since 3.0.0
 */
trait Boot {

	/**
	 * Use these traits.
	 *
	 * @since 3.0.0
	 */
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
	 * Whether construction has completed (the definition is sealed).
	 *
	 * @since 3.1.0
	 * @var   bool
	 */
	protected $booted = false;

	/**
	 * Construct the object.
	 *
	 * @since 1.0.0
	 * @since 3.1.0 The $config parameter was added to support explicit definition.
	 *
	 * @param array<string, mixed> $args   Array of arguments. (Query treats these as query vars.)
	 * @param array<string, mixed> $config Optional. Explicit definition properties, assigned
	 *                                     before any lifecycle step derives state from them.
	 */
	public function __construct( $args = array(), array $config = array() ) {
		$this->boot( $args, $config );
	}

	/**
	 * Initialize the object.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 The $config parameter was added to support explicit definition.
	 *
	 * @param array<string, mixed>|object $args   Array of arguments.
	 * @param array<string, mixed>        $config Optional. Explicit definition properties.
	 */
	protected function boot( $args = array(), array $config = array() ): void {

		// Row subclasses pass a raw stdClass from the database — normalize to array.
		if ( is_object( $args ) ) {
			$args = (array) $args;
		}

		// Run inside run() so finish() fires even if an exception is thrown.
		$this->run(
			function () use ( $args, $config ) {

				/*
				 * Configure properties from an explicit definition, before any
				 * lifecycle step (sunrise/init) derives state from them.
				 */
				$this->configure( $config );

				// Wake up.
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

		// Construction is complete; the definition is now sealed (define-once).
		$this->booted = true;
	}

	/**
	 * Configure object properties from an explicit definition array.
	 *
	 * The second, explicit construction channel — distinct from $args (which
	 * classes like Query treat as query vars). It runs before sunrise()/init(),
	 * so derived state always sees the configured identity. It is a no-op once
	 * the object is booted: identity is define-once (see is_booted()).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $config Definition properties to assign.
	 */
	protected function configure( array $config = array() ): void {

		// Bail if already booted (identity is sealed) or nothing to configure.
		if ( $this->is_booted() || empty( $config ) ) {
			return;
		}

		$this->set_vars( $config );
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

	/**
	 * Whether construction has completed.
	 *
	 * Once booted, the object's definition is sealed: configure() will not
	 * re-assign properties, so identity cannot be redefined out from under any
	 * state derived during construction.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

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

		// Parse arguments without restoring Boot's internal argument stash.
		$defaults = $this->args[ 'class' ];
		unset( $defaults[ 'args' ] );

		$r = wp_parse_args( $args, $defaults );

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
	 * @param array<string, mixed> $args Array of arguments.
	 * @return array<string, mixed>
	 */
	protected function special_args( $args = array() ) {
		return $args;
	}

	/**
	 * Validate arguments.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $args Array of arguments.
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
	 * @param array<string, mixed> $args Array of arguments.
	 * @return void
	 */
	protected function stash_args( $args = array() ) {
		$this->args = array(
			'param' => $args,
			'class' => get_object_vars( $this ),
		);
	}
}
