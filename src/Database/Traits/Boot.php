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
 *   __construct → boot → run() → start → configure → sunrise → parse_args → init → finish
 *
 * The run() wrapper guarantees finish() fires even if an exception is thrown
 * during construction.
 *
 * Hook contract — a class overrides these as needed, and should NOT invent its
 * own per-class lifecycle methods:
 *   - configure(): assign properties from an explicit $config definition. Runs
 *     first, so sunrise()/init() see the configured identity. Define-once
 *     (a no-op once is_configured()); boot() itself is define-once via
 *     is_booted().
 *   - sunrise(): pre-args setup. Rare — only when state must be ready before
 *     parse_args() runs (e.g. Query prepares its parsers before it queries).
 *   - init(): the normal home for post-args construction. Runs after configure()
 *     and parse_args(), so it sees subclass-declared, $args, and $config values
 *     alike. (configure() applies $config to properties via set_vars().)
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
	 * Whether this instance's definition has been settled.
	 *
	 * Set when configure() makes its decision — whether it applies a definition
	 * from the construct args, or defers to the class's own property defaults (a
	 * subclass that hardcodes its identity). Sealing configure() on this flag
	 * keeps the definition define-once. Set during construction, before $booted.
	 *
	 * @since 3.1.0
	 * @var   bool
	 */
	protected $configured = false;

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
	 * Accept configuration: assign config arguments to object properties.
	 *
	 * This is the universal construction channel — every Kern class configures
	 * itself here, before sunrise()/init() derive state from those properties.
	 * The default treats all $args as configuration and consumes them; a class
	 * (Query) may override to claim only some args as config and return the rest.
	 * No-op once configured: the definition is define-once (see is_configured()).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $args Construction arguments.
	 * @return array<string, mixed> Arguments NOT consumed as configuration.
	 */
	protected function configure( array $args = array() ): array {

		// Bail if already configured — the definition is sealed.
		if ( $this->is_configured() ) {
			return $args;
		}

		/*
		 * Seal: the definition is settled the moment configure() decides — applied
		 * from these args below, or left as the class's own property defaults when
		 * the args are not a definition (a subclass that hardcodes its identity).
		 */
		$this->configured = true;

		// Hand the args back unconsumed if they are not configuration.
		if ( ! $this->is_configuration( $args ) ) {
			return $args;
		}

		// Stash the arguments + property snapshot (for defaults & reset).
		$this->stash_args( $args );

		// Bail if no arguments; nothing was consumed, nothing is left over.
		if ( empty( $args ) ) {
			return array();
		}

		// Merge against the current property snapshot.
		$defaults = $this->args[ 'class' ];
		unset( $defaults[ 'args' ] );
		$r = wp_parse_args( $args, $defaults );

		// Force special-type args, set them, then validate & set.
		$r = $this->special_args( $r );
		$this->set_vars( $r );
		$this->set_vars( $this->validate_args( $r ) );

		// All arguments were configuration.
		return array();
	}

	/**
	 * Whether the given construct args are configuration (to assign as
	 * properties), or should be handed back to parse_args() for other handling.
	 *
	 * Default: yes — every class treats its construct args as configuration.
	 * Query overrides this to recognize a definition (schema-carrying args) and
	 * hand query vars back instead. Overriding THIS (rather than configure())
	 * is what lets a class opt out of configuration without re-implementing,
	 * or aliasing, the configure() pipeline.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $args Construction arguments.
	 * @return bool
	 */
	protected function is_configuration( array $args ): bool {
		return true;
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
		return $this->booted;
	}

	/**
	 * Whether this instance's definition has been settled.
	 *
	 * True once configure() has run — whether the definition came from the
	 * construct args or from the class's own property defaults (a subclass that
	 * hardcodes its identity). The definition is define-once: configure() is a
	 * no-op once this is true.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return $this->configured;
	}

	/** Argument Handlers *****************************************************/

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
