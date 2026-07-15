<?php
/**
 * Prefix Helpers.
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
 * Shared helpers for BerlinDB's plugin-level prefix strings.
 *
 * @since 3.1.0
 */
trait Prefix {

	/**
	 * Global prefix used for tables, cache-groups, and (by default) hooks.
	 *
	 * Hook/filter NAMES use $hook_prefix, which falls back to this value - so an
	 * object over an EXISTING table can keep $prefix empty (real table name) while
	 * still namespacing its hooks via $hook_prefix. See get_hook_prefix().
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $prefix = '';

	/**
	 * Prefix for the hook/filter NAMES this object generates (e.g. 'the_edd_orders').
	 *
	 * Applied ONLY to hook/filter names, never to table/meta/column resolution. When
	 * empty it falls back to $prefix (backward-compatible). Set it distinctly so a
	 * Query registered over an EXISTING table can keep $prefix empty (so `posts`
	 * resolves to the real `{$wpdb->prefix}posts`) yet still namespace its hooks - so
	 * it fires `the_acme_posts`, never WordPress core's own `the_posts`.
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	protected $hook_prefix = '';

	/**
	 * Prepend the plugin prefix ($this->prefix) to a string.
	 *
	 * Applies the plugin-level prefix only (e.g. 'edd_orders'). The
	 * WordPress table prefix ($wpdb->prefix) is a separate concern and is
	 * NOT added here. Already-prefixed strings are returned as-is to
	 * prevent double-prefixing.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Prevents double prefixing.
	 *
	 * @param string $value The string to prefix.
	 * @param string $sep   Separator placed between prefix and string. Default '_'.
	 * @return string The prefixed string, or the original string if $prefix is empty.
	 */
	protected function apply_prefix( $value = '', $sep = '_' ) {
		return $this->prefix_with( $this->prefix, $value, $sep );
	}

	/**
	 * Return the prefix applied to hook/filter NAMES.
	 *
	 * The $hook_prefix property when set, else $prefix - so existing objects (which
	 * set only $prefix) are unchanged, while an object over an existing table can set
	 * $hook_prefix distinctly to namespace its hooks without re-prefixing its table.
	 *
	 * Public sibling of get_prefix() so the two-prefix model is discoverable.
	 *
	 * @since 3.1.0
	 * @api
	 *
	 * @return string
	 */
	public function get_hook_prefix(): string {
		return ( '' !== $this->hook_prefix )
			? $this->hook_prefix
			: $this->prefix;
	}

	/**
	 * Prepend the hook prefix ({@see get_hook_prefix()}) to a hook/filter name.
	 *
	 * Used wherever a hook/filter NAME is built, so hook namespacing is independent
	 * of the table prefix. Identical to apply_prefix() when $hook_prefix is unset.
	 *
	 * @since 3.1.0
	 *
	 * @param string $value The hook/filter name to prefix.
	 * @param string $sep   Separator placed between prefix and name. Default '_'.
	 * @return string
	 */
	protected function apply_hook_prefix( $value = '', $sep = '_' ) {
		return $this->prefix_with( $this->get_hook_prefix(), $value, $sep );
	}

	/**
	 * Prepend a given prefix to a string (trimmed, empty-safe, no double-prefix).
	 *
	 * The shared core of apply_prefix() and apply_hook_prefix().
	 *
	 * @since 3.1.0
	 *
	 * @param string $prefix The prefix to prepend (empty returns the value as-is).
	 * @param string $value  The string to prefix.
	 * @param string $sep    Separator placed between prefix and string. Default '_'.
	 * @return string
	 */
	private function prefix_with( string $prefix, $value = '', $sep = '_' ): string {

		// Bail if not a string.
		if ( ! is_string( $value ) ) {
			return '';
		}

		// Trim spaces off the ends.
		$retval = trim( $value );

		// Bail if no prefix.
		if ( empty( $prefix ) ) {
			return $retval;
		}

		// Setup new prefix.
		$new_prefix = $prefix . $sep;

		// Bail if already prefixed.
		if ( 0 === strpos( $value, $new_prefix ) ) {
			return $retval;
		}

		// Return prefixed string.
		return $new_prefix . $retval;
	}

	/**
	 * Return the first letters of a string of words with a separator.
	 *
	 * Used primarily to guess at table aliases when none is manually set.
	 *
	 * Applies the following formatting to a string:
	 * - Trim whitespace
	 * - No accents
	 * - No trailing underscores
	 *
	 * @since 1.0.0
	 *
	 * @param string $value The string to abbreviate.
	 * @param string $sep   Default "_".
	 * @return string
	 */
	protected function first_letters( $value = '', $sep = '_' ) {

		// Bail if empty or not a string.
		if ( empty( $value ) || ! is_string( $value ) ) {
			return '';
		}

		// Default return value.
		$retval = '';

		// Trim spaces off the ends.
		$unspace = trim( $value );

		// Only non-accented table names (avoid truncation).
		$accents = remove_accents( $unspace );

		// Convert to lowercase.
		$lower = strtolower( $accents );

		// Ensure separator is a non-empty string.
		if ( ! is_string( $sep ) || '' === $sep ) {
			$sep = '_';
		}

		// Explode into parts.
		$parts = explode( $sep, $lower );

		// Loop through parts and concatenate the first letters together.
		foreach ( $parts as $part ) {
			$retval .= substr( $part, 0, 1 );
		}

		// Return the result.
		return $retval;
	}
}
