<?php
/**
 * Base Custom Database Class.
 *
 * @package     Database
 * @subpackage  Base
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Base Trait provides shared utilities to all BerlinDB classes.
 *
 * This is intentionally an aggregator: classes compose Base once and receive
 * the shared environment, diagnostics, magic access, sanitization, argument,
 * prefix, generation, and class-instantiation helpers used across BerlinDB.
 * Magic __get() and __isset() behavior is delegated to the Magic trait.
 *
 * @since 3.0.0
 */
trait Base {

	/**
	 * Use these traits.
	 *
	 * @since 3.0.0
	 */
	use Arguments;
	use Environment;
	use Error;
	use Generator;
	use Instantiator;
	use Log;
	use Magic;
	use Prefix;
	use Sanitizer;
}
