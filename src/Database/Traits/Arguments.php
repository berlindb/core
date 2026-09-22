<?php
/**
 * Argument Helpers.
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
 * Shared helpers for object vars and constructor-style argument arrays.
 *
 * @since 3.1.0
 */
trait Arguments {

	/**
	 * Converts the object's public properties to an array.
	 *
	 * Only public properties are included. Protected and private properties
	 * are not visible to get_object_vars() when called from a public method.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return get_object_vars( $this );
	}

	/**
	 * Set class variables from arguments.
	 *
	 * @since 1.0.0
	 * @param array<string,mixed> $args Array of arguments.
	 */
	protected function set_vars( $args = array() ): void {

		// Bail if empty or not an array.
		if ( empty( $args ) ) {
			return;
		}

		// Cast to an array.
		if ( ! is_array( $args ) ) {
			$args = (array) $args;
		}

		// Set all properties.
		foreach ( $args as $key => $value ) {
			$this->{$key} = $value;
		}
	}

	/**
	 * Parse an arguments value using the released extension signature.
	 *
	 * Kept as a compatibility seam for subclasses that overrode the construction
	 * hook shipped in 3.0. Internal calls use parse_args_to_array() so a legacy
	 * override cannot alter the new construction pipeline.
	 *
	 * @since 3.0.0
	 * @since 3.1.0 No longer used as the construction hook.
	 * @deprecated 3.1.0 Use parse_args_to_array() to parse arguments, or
	 *                   consume_args() to handle construction arguments.
	 *
	 * @param array<string,mixed>|object|string $args Value to parse.
	 * @return array<string,mixed>
	 */
	protected function parse_args( $args = array() ) {
		return $this->parse_args_to_array( $args );
	}

	/**
	 * Parse an arguments value into an array.
	 *
	 * A dependency-free reimplementation of WordPress's wp_parse_args(): accepts
	 * an array, an object (read via get_object_vars()), or a URL-style query
	 * string (parsed with parse_str()), then merges the result over $defaults.
	 * The WordPress 'wp_parse_str' filter is intentionally not applied.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed>|object|string $args     Value to parse.
	 * @param array<string,mixed>               $defaults Defaults to merge under $args.
	 * @return array<string,mixed>
	 */
	protected function parse_args_to_array( $args = array(), $defaults = array() ): array {

		// Normalize $args to an array.
		if ( is_object( $args ) ) {
			$parsed = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$parsed = $args;
		} else {
			$parsed = array();
			parse_str( (string) $args, $parsed );
		}

		// Merge over the defaults when any are provided.
		return ! empty( $defaults )
			? array_merge( $defaults, $parsed )
			: $parsed;
	}
}
