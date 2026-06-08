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
 * sunrise()/init() see the configured identity. A class tunes the behaviour by
 * overriding the hooks, NOT configure() itself:
 *   - is_configuration(): whether the args are a definition (default: yes).
 *   - get_config_callbacks(): per-key sanitization map for validate_args().
 *   - is_strict_config(): reject unknown config keys instead of passing them on.
 *   - special_args() / validate_args(): force/validate values before set_vars().
 *
 * Requires the host to provide set_vars() and log() (Traits\Base, which pulls
 * Log). Declared via @method (not abstract methods) so tooling sees the
 * dependency without colliding with the real methods when a class composes both.
 *
 * @since 3.1.0
 *
 * @method void set_vars( array<string, mixed> $args = [] )
 * @method void log( string $level, string $code, string $message, array<string, mixed> $context = [] )
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
	 * @var   array<string, mixed>
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
	 * itself here, before sunrise()/init() derive state from those properties.
	 * The default treats all $args as configuration and consumes them; a class
	 * (Query) may override is_configuration() to claim only some args as config
	 * and return the rest.
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

		/*
		 * In strict mode, set aside keys that match no object property. They are
		 * dropped now and logged AFTER set_vars() below: set_vars() re-applies the
		 * property snapshot (including the empty log), which would otherwise reset
		 * any entry logged here.
		 */
		$unknown = $this->is_strict_config()
			? array_diff_key( $args, $this->args[ 'class' ] )
			: array();
		if ( ! empty( $unknown ) ) {
			$args = array_intersect_key( $args, $this->args[ 'class' ] );
		}

		// Apply any recognized arguments.
		if ( ! empty( $args ) ) {

			// Merge against the current property snapshot.
			$defaults = $this->args[ 'class' ];
			unset( $defaults[ 'args' ] );
			$r = wp_parse_args( $args, $defaults );

			// Force special-type args, set them, then validate & set.
			$r = $this->special_args( $r );
			$this->set_vars( $r );
			$this->set_vars( $this->validate_args( $r ) );
		}

		// Log any unrecognized keys now that set_vars() is done (strict mode).
		$this->log_unknown_config_args( $unknown );

		// All arguments were configuration.
		return array();
	}

	/**
	 * Whether the given construct args are configuration (to assign as
	 * properties), or should be handed back to parse_args() for other handling.
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
	 * @param array<string, mixed> $args Construction arguments.
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
	 * @param array<string, mixed> $args Array of arguments.
	 * @return array<string, mixed>
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
	 * @param array<string, mixed> $args Array of arguments.
	 * @return array<string, mixed>
	 */
	protected function validate_args( $args = array() ) {

		// Per-class sanitization callbacks, keyed by config arg name.
		$callbacks = $this->get_config_callbacks();

		// Default return arguments.
		$r = array();

		// Bail if no args or callbacks; nothing to validate.
		if ( empty( $args ) || empty( $callbacks ) ) {
			return $r;
		}

		// Sanitize known keys via their callback; pass everything else through.
		foreach ( $args as $key => $value ) {
			$r[ $key ] = ( isset( $callbacks[ $key ] ) && is_callable( $callbacks[ $key ] ) )
				? call_user_func( $callbacks[ $key ], $value )
				: $value;
		}

		// Return the validated arguments.
		return $r;
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
	 * @return array<string, mixed> Map of arg key => sanitization callback.
	 */
	protected function get_config_callbacks(): array {
		return array();
	}

	/**
	 * Whether to reject configuration keys that match no object property.
	 *
	 * Opt-in (default false), so existing callers that pass extra keys keep
	 * working. Override to true to harden a class's configuration surface: an
	 * unknown key is logged and dropped instead of silently creating a junk
	 * dynamic property via set_vars().
	 *
	 * IMPORTANT: only enable this on classes with a fixed, declared property set
	 * (Query, Table, Column, Index, Relationship, Schema). Do NOT enable it on a
	 * #[\AllowDynamicProperties] class whose config legitimately sets undeclared
	 * properties — Row, whose data columns ARE dynamic properties — because every
	 * such key would be (wrongly) treated as unknown and dropped.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	protected function is_strict_config(): bool {
		return false;
	}

	/**
	 * Log configuration keys that matched no object property.
	 *
	 * Called from configure() in strict mode, AFTER set_vars() — the unknown keys
	 * have already been dropped; this only reports them. Logging here (not when
	 * they are dropped) keeps the entries from being reset by set_vars(), which
	 * re-applies the property snapshot.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $unknown Map of unrecognized key => value.
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
