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
 *   - Lifecycle - supplies run()/start()/finish()/$current; boot() wraps the
 *     construction sequence in run() so finish() fires even on exception.
 *   - Configuration - supplies configure() (args -> properties), which boot()
 *     calls after sunrise() so init() sees the configured identity.
 *
 * The full lifecycle is:
 *
 *   __construct -> boot -> run() -> start -> sunrise -> configure -> init -> consume_args -> sunset -> finish
 *
 * Construction is define-once: boot() is a no-op once is_booted(); the
 * definition itself is sealed separately by Configuration's is_configured().
 *
 * Hook contract - a class overrides these as needed, and should NOT invent its
 * own per-class lifecycle methods:
 *   - sunrise(): runs first, before configuration is applied (the dawn bookend
 *     with sunset()). Rare - for any setup that must precede config.
 *   - configure() / is_configuration(): see Traits\Configuration.
 *   - init(): the construction hook - build state from the just-applied config,
 *     before consume_args() runs (e.g. Query builds its schema and parsers here).
 *   - consume_args(): handle args not consumed as configuration (Query: query
 *     vars + run). No-op default.
 *   - sunset(): runs last, after consume_args() (the dusk bookend with sunrise()).
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
	 * @param array<string,mixed> $args Array of arguments. Configuration for most classes;
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
	 * @param array<string,mixed>|object $args Array of arguments.
	 */
	protected function boot( $args = array() ): void {

		// Bail if already booted - construction is define-once (whole lifecycle).
		if ( $this->is_booted() ) {
			return;
		}

		// Row subclasses pass a raw stdClass from the database - normalize to array.
		if ( is_object( $args ) ) {
			$args = (array) $args;
		}

		// Run inside run() so finish() fires even if an exception is thrown.
		$this->run(
			function () use ( $args ) {

				// Wake up (before any configuration is applied).
				$this->sunrise();

				/*
				 * Accept configuration: turn config args into properties before
				 * init() derives state from them. Returns any args that were NOT
				 * configuration (Query's query vars).
				 */
				$remaining = $this->configure( $args );

				// Build state derived from the applied configuration.
				$this->init();

				// Consume any remaining args (Query-only: query vars + run).
				$this->consume_args( $remaining );

				// All done.
				$this->sunset();
			}
		);

		// Construction is complete; the definition is now sealed (define-once).
		$this->booted = true;
	}

	/**
	 * Wake up: the first lifecycle step, before configuration is applied.
	 *
	 * Empty by default - override only for setup that must precede config. The
	 * dawn bookend, paired with sunset().
	 *
	 * @since 3.0.0
	 */
	protected function sunrise(): void {}

	/**
	 * Consume any arguments not claimed as configuration.
	 *
	 * Default is a no-op - for most classes configure() consumes everything.
	 * Query overrides this to parse its query vars and run the query.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Renamed from parse_args().
	 *
	 * @param array<string,mixed> $args Arguments left over from configure().
	 */
	protected function consume_args( array $args = array() ): void {}

	/**
	 * Build the object's state from the applied configuration.
	 *
	 * The construction hook: runs after configure() (so it sees the configured
	 * identity) and before consume_args(), so anything the work needs is ready -
	 * e.g. Query builds its schema and query-var parsers here. Decompose into
	 * named set_*() helpers, as Query/Table do.
	 *
	 * @since 3.0.0
	 */
	protected function init(): void {}

	/**
	 * Wind down: the last lifecycle step, after init().
	 *
	 * Empty by default. The dusk bookend, paired with sunrise().
	 *
	 * @since 3.1.0
	 */
	protected function sunset(): void {}

	/**
	 * Whether construction has completed.
	 *
	 * Once booted, boot() is a no-op (the lifecycle is define-once). Sealing of
	 * the definition itself - so configure() will not re-assign properties - is
	 * tracked separately by is_configured().
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return (bool) $this->booted;
	}

	/**
	 * Reserved property names owned by the Boot trait.
	 *
	 * Construction-machinery state that configuration must never set or have
	 * clobbered. Unioned by Configuration::get_reserved_vars(), so each trait owns
	 * its own internal-state declaration.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	protected function get_boot_reserved_vars(): array {
		return array( 'booted' );
	}
}
