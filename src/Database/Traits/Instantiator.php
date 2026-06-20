<?php
/**
 * Class Instantiator Helpers.
 *
 * @package     Database
 * @subpackage  Traits
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Shared helper for turning class names into objects.
 *
 * Composing classes are expected to also provide Log::log().
 *
 * @since 3.1.0
 */
trait Instantiator {

	/**
	 * Validate a class name and instantiate it, failing closed to null.
	 *
	 * The single home for the recurring "class_exists() then new" pattern: an
	 * empty or unloadable class always fails closed to null. Available on every
	 * class composing this trait (which also composes Log), so the same guarded
	 * instantiation - and the same structured failure log - is one call away
	 * across Kern, Presets, and Parsers.
	 *
	 * Construction exceptions are handled by mode: pass $log_code to emit a
	 * `warning` on failure under the caller's own code (preserving existing
	 * diagnostics) AND trap a construction throw as a fail-closed null; leave it
	 * '' for a silent attempt whose constructor exceptions surface to the caller
	 * unchanged.
	 *
	 * @internal Not consumer API.
	 * @since 3.1.0
	 *
	 * @param  string $class    Fully-qualified class name to instantiate.
	 * @param  string $log_code Log code to emit on failure ('' = silent; errors surface).
	 * @param  mixed  ...$args  Constructor arguments.
	 * @return object|null The new instance, or null when $class is empty/unloadable
	 *                     (or, with a log code, when construction throws).
	 */
	protected function instantiate_class( string $class, string $log_code = '', ...$args ): ?object {

		// Fail closed on an empty or unloadable class.
		if ( ( '' === $class ) || ! class_exists( $class ) ) {
			if ( '' !== $log_code ) {
				$this->log( 'warning', $log_code, 'Class is empty or could not be loaded.', array( 'class' => $class ) );
			}

			return null;
		}

		// Silent mode: let any construction error surface to the caller.
		if ( '' === $log_code ) {
			return new $class( ...$args );
		}

		// Logged mode: trap a construction failure as a fail-closed null.
		try {
			return new $class( ...$args );
		} catch ( \Throwable $exception ) {
			$this->log(
				'warning',
				$log_code,
				'Class threw during construction.',
				array(
					'class'     => $class,
					'exception' => get_class( $exception ),
					'message'   => $exception->getMessage(),
				)
			);

			return null;
		}
	}
}
