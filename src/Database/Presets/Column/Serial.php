<?php
/**
 * SERIAL Column Preset.
 *
 * @package     Database
 * @subpackage  Presets
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Presets\Column;

use BerlinDB\Database\Kern\Column;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * MySQL's SERIAL / SERIAL DEFAULT VALUE "extra" shorthands.
 *
 * Triggered by the `extra` value (not a flag), so it overrides matches(). SERIAL is
 * MySQL shorthand for an unsigned bigint(20) auto-increment primary key; SERIAL
 * DEFAULT VALUE is the AUTO_INCREMENT-and-primary half WITHOUT forcing bigint, so a
 * caller's own integer type (int, smallint, ...) is preserved - which is why this is
 * distinct from Id rather than folded into it. Non-integer types are left untouched.
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/numeric-type-attributes.html
 * @since 3.1.0
 */
final class Serial extends Base {

	/*
	 * No SHAPE const: this preset's shape is conditional (SERIAL forces bigint while
	 * SERIAL DEFAULT VALUE preserves the caller's type, and either only promotes an
	 * integer), which a flat declarative const cannot express - so it overrides
	 * set_args() instead.
	 */

	/**
	 * The preset key.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function key(): string {
		return 'serial';
	}

	/**
	 * No dedicated boolean flag: this preset triggers off the (already-recognized)
	 * `extra` value, so there is nothing for Column to recognize or consume.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function flag(): string {
		return '';
	}

	/**
	 * Match a SERIAL or SERIAL DEFAULT VALUE "extra", case-insensitively.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Column args, or the column's own vars.
	 * @return bool
	 */
	public function matches( array $args ): bool {
		if ( empty( $args[ 'extra' ] ) ) {
			return false;
		}

		$extra = strtoupper( (string) $args[ 'extra' ] );

		return ( 'SERIAL' === $extra ) || ( 'SERIAL DEFAULT VALUE' === $extra );
	}

	/**
	 * Promote an integer column to an auto-increment primary key.
	 *
	 * SERIAL first forces the unsigned bigint(20) shape; both SERIAL and SERIAL DEFAULT
	 * VALUE then make any integer type an AUTO_INCREMENT primary cache key. The
	 * cache_key is baked in here (rather than relying on the Primary preset) because
	 * primary is only set in THIS pass, after Primary's slot in the precedence order.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args   Incoming column args.
	 * @param Column              $column The column being shaped.
	 * @return array<string,mixed>
	 */
	public function set_args( array $args, Column $column ): array {

		// SERIAL forces the bigint shape; SERIAL DEFAULT VALUE keeps the caller's type.
		if ( 'SERIAL' === strtoupper( (string) ( $args[ 'extra' ] ?? '' ) ) ) {
			$args[ 'type' ]     = 'bigint';
			$args[ 'length' ]   = '20';
			$args[ 'unsigned' ] = true;
		}

		// Both forms promote an integer type to an auto-increment primary key.
		if ( $column->is_int( $args[ 'type' ] ?? '' ) ) {
			$args[ 'allow_null' ] = false;
			$args[ 'default' ]    = false;
			$args[ 'primary' ]    = true;
			$args[ 'cache_key' ]  = true;
			$args[ 'pattern' ]    = '%d';
			$args[ 'extra' ]      = 'AUTO_INCREMENT';
		}

		return $args;
	}
}
