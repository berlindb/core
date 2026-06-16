<?php
/**
 * Configuration Trait Class.
 *
 * @package     Database
 * @subpackage  Base
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Configuration Trait turns construction arguments into object properties.
 *
 * This is the universal config channel: configure() takes the construct args,
 * assigns the ones that form a definition to properties (via set_vars()), and
 * hands back whatever it did not consume. It is define-once — sealed by
 * is_configured() — so an instance's identity cannot be redefined after it has
 * been settled.
 *
 * Boot drives this: it calls configure() first in the construction sequence, so
 * init() sees the configured identity. A class tunes the behaviour by
 * overriding the hooks, NOT configure() itself:
 *   - is_configuration(): whether the args are a definition (default: yes).
 *   - get_config_callbacks(): per-key sanitization map for validate_args().
 *   - is_strict_config(): reject unknown config keys instead of passing them on.
 *   - special_args() / validate_args(): force/validate values before set_vars().
 *
 * Requires the host to provide set_vars(), parse_args(), and log() (Traits\Base,
 * which pulls Log). Declared via @method (not abstract methods) so tooling sees
 * the dependency without colliding with the real methods when a class composes
 * both.
 *
 * @since 3.1.0
 *
 * @method void set_vars( array<string,mixed> $args = [] )
 * @method array<string,mixed> parse_args( array<string,mixed>|object|string $args = [], array<string,mixed> $defaults = [] )
 * @method void log( string $level, string $code, string $message, array<string,mixed> $context = [] )
 * @method list<string> get_boot_reserved_vars()
 * @method list<string> get_lifecycle_reserved_vars()
 * @method list<string> get_log_reserved_vars()
 */
trait Configuration {

	/**
	 * Stashed copy of constructor arguments and initial property values.
	 *
	 * Set by stash_args() during configuration. Keys:
	 *   'param' — the raw $args passed to __construct()
	 *   'class' — snapshot of all object properties at construction time
	 *
	 * @since 3.0.0
	 * @var   array<string,mixed>
	 */
	protected $args = array();

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
	 * Accept configuration: assign config arguments to object properties.
	 *
	 * This is the universal construction channel — every Kern class configures
	 * itself here, before init() derives state from those properties.
	 * The default treats all $args as configuration and consumes them; a class
	 * (Query) may override is_configuration() to claim only some args as config
	 * and return the rest.
	 * No-op once configured: the definition is define-once (see is_configured()).
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Construction arguments.
	 * @return array<string,mixed> Arguments NOT consumed as configuration.
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

		/*
		 * In strict mode, drop (and report) keys that are NOT a declared config
		 * key (get_config_callbacks()). Recognizing only the declared config
		 * surface — rather than every visible property — keeps framework-internal
		 * state (booted, configured, current, logs, …) from being set via config.
		 */
		if ( $this->is_strict_config() ) {
			$recognized = array_fill_keys( array_keys( $this->get_config_callbacks() ), null );
			$unknown    = array_diff_key( $args, $recognized );

			if ( ! empty( $unknown ) ) {
				$args = array_intersect_key( $args, $recognized );
				$this->log_unknown_config_args( $unknown );
			}
		}

		// Apply any recognized arguments.
		if ( ! empty( $args ) ) {

			/*
			 * Merge against the current property snapshot, minus the reserved
			 * construction-machinery vars (get_reserved_vars()). Excluding them
			 * keeps set_vars() below from re-applying internal state — most
			 * importantly the empty log — over anything a sanitizer in
			 * validate_args() emitted.
			 */
			$reserved = array_flip( $this->get_reserved_vars() );
			$defaults = array_diff_key( $this->args[ 'class' ], $reserved );
			$r        = $this->parse_args( $args, $defaults );

			// Force special-type args, set them, then validate & set.
			$r = $this->special_args( $r );
			$this->set_vars( $r );
			$this->set_vars( $this->validate_args( $r ) );
		}

		// All arguments were configuration.
		return array();
	}

	/**
	 * Whether the given construct args are configuration (to assign as
	 * properties), or should be handed back to consume_args() for other handling.
	 *
	 * Default: yes — every class treats its construct args as configuration.
	 *
	 * Query overrides this to recognize a definition (schema-carrying args) and
	 * hand query vars back instead. Overriding THIS (rather than configure())
	 * is what lets a class opt out of configuration without re-implementing,
	 * or aliasing, the configure() pipeline.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Construction arguments.
	 * @return bool
	 */
	protected function is_configuration( array $args ): bool {
		return true;
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
		return (bool) $this->configured;
	}

	/** Argument Handlers *****************************************************/

	/**
	 * Parse special arguments.
	 *
	 * @since 3.0.0
	 * @param array<string,mixed> $args Array of arguments.
	 * @return array<string,mixed>
	 */
	protected function special_args( $args = array() ) {
		return $args;
	}

	/**
	 * Validate arguments.
	 *
	 * Runs each arg through the matching callback from get_config_callbacks();
	 * args whose key has no callable callback pass through unchanged.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 Applies the shared get_config_callbacks() map.
	 *
	 * @param array<string,mixed> $args Array of arguments.
	 * @return array<string,mixed>
	 */
	protected function validate_args( $args = array() ) {

		// Per-class sanitization callbacks, keyed by config arg name.
		$callbacks = $this->get_config_callbacks();

		// Pass args through unchanged when there is nothing to validate.
		if ( empty( $args ) || empty( $callbacks ) ) {
			return $args;
		}

		// Sanitize each arg that has a callable callback; leave the rest as-is.
		foreach ( $args as $key => $value ) {
			if ( isset( $callbacks[ $key ] ) && is_callable( $callbacks[ $key ] ) ) {
				$args[ $key ] = call_user_func( $callbacks[ $key ], $value );
			}
		}

		// Return the sanitized arguments.
		return $args;
	}

	/**
	 * Sanitization callbacks for this class's configuration arguments.
	 *
	 * Returns a map of config-arg key => callback (a callable, or a callable
	 * name; '' or any non-callable means "pass through"). validate_args() runs
	 * each arg's value through its callback before set_vars(). The default is an
	 * empty map; each Kern class overrides this to declare the config keys it
	 * accepts and how to sanitize them.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string,mixed> Map of arg key => sanitization callback.
	 */
	protected function get_config_callbacks(): array {
		return array();
	}

	/**
	 * Whether to reject configuration keys that are not a declared config key
	 * (get_config_callbacks()).
	 *
	 * Default true (opt-out): a key outside the declared config surface is logged
	 * and dropped instead of reaching set_vars() — which both catches typos and
	 * keeps framework-internal state (args, booted, configured, current, logs)
	 * from being set via config. A class's declared config keys are unaffected.
	 *
	 * Override to false on a #[\AllowDynamicProperties] class whose config
	 * legitimately sets undeclared properties — Row, whose data columns ARE
	 * dynamic properties (and are not declared in a callback map) — otherwise
	 * every such key would be (wrongly) dropped.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	protected function is_strict_config(): bool {
		return true;
	}

	/**
	 * Property names reserved by the construction machinery.
	 *
	 * These are framework-internal properties (the config stash, the boot/config
	 * seals, per-run state, and the log store). configure() excludes them from the
	 * snapshot it merges over, so set_vars() cannot **clobber** internal state with
	 * snapshot defaults — most importantly resetting the empty log over anything a
	 * sanitizer emitted during validate_args(). This guards the snapshot-default
	 * path only; it does not stop an explicitly-supplied arg of the same name in a
	 * non-strict (#[AllowDynamicProperties]) class like Row from setting these (for
	 * strict classes such keys are already dropped as unknown).
	 *
	 * Each owning trait declares its own names (get_boot_reserved_vars() etc.);
	 * this unions them with Configuration's own, so no trait hard-codes another's
	 * internals. A trait or preset that adds internal state declares it alongside
	 * the property.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	protected function get_reserved_vars(): array {
		return array_merge(
			array( 'args', 'configured' ),
			$this->get_boot_reserved_vars(),
			$this->get_lifecycle_reserved_vars(),
			$this->get_log_reserved_vars()
		);
	}

	/**
	 * Log configuration keys that fall outside the declared config surface.
	 *
	 * Called from configure() in strict mode for keys that were dropped (not a
	 * declared config key); this only reports them. Reserved vars are excluded
	 * from the config merge (get_reserved_vars()), so these warnings survive
	 * set_vars() and can be logged where the keys are dropped.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $unknown Map of unrecognized key => value.
	 * @return void
	 */
	protected function log_unknown_config_args( array $unknown ): void {

		// Bail if there is nothing to report.
		if ( empty( $unknown ) ) {
			return;
		}

		// Log each unrecognized key.
		foreach ( array_keys( $unknown ) as $key ) {
			$this->log(
				'warning',
				'config_unknown_arg',
				'Unrecognized configuration argument; ignored.',
				array( 'key' => $key )
			);
		}
	}

	/**
	 * Stash arguments and class variables.
	 *
	 * Captures a snapshot of the constructor arguments and the object's
	 * current property values so configure() can merge against them and
	 * callers can compare, reuse, or reset to a prior state.
	 *
	 * get_object_vars() is called from within the trait, so it captures all
	 * properties visible in this scope — including protected ones — not just
	 * public properties as it would from an external caller.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $args Array of arguments.
	 * @return void
	 */
	protected function stash_args( $args = array() ) {
		$this->args = array(
			'param' => $args,
			'class' => get_object_vars( $this ),
		);
	}
}
