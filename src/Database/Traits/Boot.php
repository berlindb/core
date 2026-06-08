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
 * The Boot Trait is the construction lifecycle: it sequences a class's
 * construction once, and seals it.
 *
 * It composes two traits:
 *   - Lifecycle — supplies run()/start()/finish()/$current; boot() wraps the
 *     construction sequence in run() so finish() fires even on exception.
 *   - Configuration — supplies configure() (args → properties), which boot()
 *     calls first so sunrise()/init() see the configured identity.
 *
 * The full lifecycle is:
 *
 *   __construct → boot → run() → start → configure → sunrise → parse_args → init → finish
 *
 * Construction is define-once: boot() is a no-op once is_booted(); the
 * definition itself is sealed separately by Configuration's is_configured().
 *
 * Hook contract — a class overrides these as needed, and should NOT invent its
 * own per-class lifecycle methods:
 *   - configure() / is_configuration(): see Traits\Configuration.
 *   - sunrise(): pre-args setup. Rare — only when state must be ready before
 *     parse_args() runs (e.g. Query prepares its parsers before it queries).
 *   - parse_args(): handle args not consumed as configuration (Query: query
 *     vars + run). No-op default.
 *   - init(): the normal home for post-args construction. Runs after configure()
 *     and parse_args(), so it sees subclass-declared, $args, and $config values
 *     alike.
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
	use Configuration;

	/**
	 * Whether construction has completed (the lifecycle is sealed).
	 *
	 * @since 3.1.0
	 * @var   bool
	 */
	protected $booted = false;

	/**
	 * Construct the object.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Array of arguments. Configuration for most classes;
	 *                                   query vars (or configuration) for Query.
	 */
	public function __construct( $args = array() ) {
		$this->boot( $args );
	}

	/**
	 * Initialize the object.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed>|object $args Array of arguments.
	 */
	protected function boot( $args = array() ): void {

		// Bail if already booted — construction is define-once (whole lifecycle).
		if ( $this->is_booted() ) {
			return;
		}

		// Row subclasses pass a raw stdClass from the database — normalize to array.
		if ( is_object( $args ) ) {
			$args = (array) $args;
		}

		// Run inside run() so finish() fires even if an exception is thrown.
		$this->run(
			function () use ( $args ) {

				/*
				 * Accept configuration: turn config args into properties before
				 * any lifecycle step (sunrise/init) derives state from them.
				 * Returns any args that were NOT configuration (Query's query vars).
				 */
				$remaining = $this->configure( $args );

				// Wake up.
				$this->sunrise();

				// Parse any remaining args (Query-only: query vars + run).
				$this->parse_args( $remaining );

				// Initialize.
				$this->init();
			}
		);

		// Construction is complete; the definition is now sealed (define-once).
		$this->booted = true;
	}

	/**
	 * Called early, before arguments are parsed.
	 *
	 * @since 3.0.0
	 */
	protected function sunrise(): void {}

	/**
	 * Parse any arguments not consumed as configuration.
	 *
	 * Default is a no-op — for most classes configure() consumes everything.
	 * Query overrides this to parse its query vars and run the query.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $args Arguments left over from configure().
	 */
	protected function parse_args( array $args = array() ): void {}

	/**
	 * Initialize.
	 *
	 * @since 3.0.0
	 */
	protected function init(): void {}

	/**
	 * Whether construction has completed.
	 *
	 * Once booted, boot() is a no-op (the lifecycle is define-once). Sealing of
	 * the definition itself — so configure() will not re-assign properties — is
	 * tracked separately by is_configured().
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return (bool) $this->booted;
	}
}
