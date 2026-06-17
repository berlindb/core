<?php
/**
 * Cast Trait Class.
 *
 * @package     Database
 * @subpackage  Traits
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Cast Trait casts Row properties after they are read from the database.
 *
 * Classes using this trait gain a $casts property — a map of column name to
 * callable — that is applied during init(). Subclasses override $casts to
 * define their own casting behavior without touching the constructor.
 *
 * @since 3.0.0
 */
trait Cast {

	/**
	 * Map of column name to cast callable.
	 *
	 * Keys are property names on this Row. Values are any callable: a PHP
	 * function name, a static method reference, or a closure.
	 *
	 * Example:
	 *
	 *   protected $casts = array(
	 *       'count'  => 'intval',
	 *       'price'  => 'floatval',
	 *       'active' => 'boolval',
	 *       'meta'   => 'maybe_unserialize',
	 *   );
	 *
	 * @since 3.0.0
	 * @var   array<string,callable>
	 */
	protected $casts = array();

	/**
	 * Apply casts to Row properties.
	 *
	 * @since 3.0.0
	 */
	protected function apply_casts(): void {

		// Bail if no casts defined or casts is not an array.
		if ( empty( $this->casts ) || ! is_array( $this->casts ) ) {
			return;
		}

		// Loop through casts.
		foreach ( $this->casts as $prop => $callback ) {

			// Only apply if the property exists and the callback is callable.
			if ( property_exists( $this, $prop ) && is_callable( $callback ) ) {

				// Apply the cast and update the property value.
				$this->{$prop} = call_user_func( $callback, $this->{$prop} );
			}
		}
	}
}
