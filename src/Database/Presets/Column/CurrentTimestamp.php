<?php
/**
 * CURRENT_TIMESTAMP Column Preset.
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
 * A MySQL-managed temporal column: defers its value to CURRENT_TIMESTAMP.
 *
 * Triggered by the `default` / `extra` value (not a flag), so it overrides
 * matches(): it activates for a datetime / timestamp column declared
 * DEFAULT CURRENT_TIMESTAMP and / or ON UPDATE CURRENT_TIMESTAMP. On save it drops
 * the field (returns the unset sentinel) so MySQL populates it from its own clause
 * - the DEFAULT on insert, the ON UPDATE on update. An explicit, valid datetime
 * value (or one a prior preset such as Created / Modified set) still wins; an
 * empty, keyword, or unparseable value defers to MySQL.
 *
 * Activating off the type keeps this OUT of every non-temporal column's save path -
 * the deferral runs only where a CURRENT_TIMESTAMP clause actually exists. The DDL
 * side (emitting DEFAULT CURRENT_TIMESTAMP unquoted) lives in
 * Column::get_default_sql(); this preset owns only the save-time deferral.
 *
 * Limitation: the intercept contract treats null as "field not supplied" (see
 * Base::intercept()), so an explicit null cannot be distinguished from an omitted
 * field - both defer to MySQL rather than storing SQL NULL. For a DB-managed
 * timestamp that is the intended resolution.
 *
 * @since 3.1.0
 */
final class CurrentTimestamp extends Base {

	/**
	 * The preset key.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function key(): string {
		return 'current_timestamp';
	}

	/**
	 * No dedicated boolean flag: this preset triggers off the (already-recognized)
	 * `default` / `extra` values, so there is nothing for Column to recognize or
	 * consume.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function flag(): string {
		return '';
	}

	/**
	 * Match a datetime / timestamp column managed by CURRENT_TIMESTAMP.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Column args, or the column's own vars.
	 * @return bool
	 */
	public function matches( array $args ): bool {
		$type = strtolower( (string) ( $args[ 'type' ] ?? '' ) );

		if ( ! in_array( $type, array( 'datetime', 'timestamp' ), true ) ) {
			return false;
		}

		return $this->is_current_timestamp( $args[ 'default' ] ?? '' )
			|| $this->is_on_update( $args[ 'extra' ] ?? '' );
	}

	/**
	 * Drop the field so MySQL populates it from DEFAULT / ON UPDATE.
	 *
	 * @since 3.1.0
	 *
	 * @param string $method   insert|update|select|delete|copy.
	 * @param mixed  $value    Incoming value.
	 * @param Column $column   The column.
	 * @param bool   $provided Whether the caller supplied this column. Default true.
	 * @return mixed
	 */
	public function intercept( string $method, $value, Column $column, bool $provided = true ) {

		// An explicit, valid datetime value (or one a prior preset set) wins.
		if ( $this->is_explicit_datetime( $value ) ) {
			return $value;
		}

		// Defer to MySQL: DEFAULT on insert, ON UPDATE on update.
		$defers =
			( ( 'insert' === $method ) && $this->is_current_timestamp( $column->default ) )
			|| ( ( 'update' === $method ) && $this->is_on_update( $column->extra ) );

		return $defers
			? $column->get_unset_sentinel()
			: $value;
	}

	/**
	 * Whether a value is an explicit, parseable datetime the caller wants stored.
	 *
	 * Empty, the CURRENT_TIMESTAMP keyword, and unparseable values are NOT explicit
	 * - they defer to MySQL. Checking parseability here (the same strtotime() gate
	 * validate_datetime() uses) is what stops an invalid value from later falling
	 * back to the column's CURRENT_TIMESTAMP default and being bound as a literal.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value The value to test.
	 * @return bool
	 */
	private function is_explicit_datetime( $value ): bool {
		return is_scalar( $value )
			&& ( '' !== (string) $value )
			&& ! $this->is_current_timestamp( $value )
			&& ( false !== strtotime( (string) $value ) );
	}

	/**
	 * Whether a value is the CURRENT_TIMESTAMP keyword.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value The value to test.
	 * @return bool
	 */
	private function is_current_timestamp( $value ): bool {
		return is_scalar( $value )
			&& ( 'CURRENT_TIMESTAMP' === strtoupper( (string) $value ) );
	}

	/**
	 * Whether an extra is the ON UPDATE CURRENT_TIMESTAMP clause.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $extra The extra to test.
	 * @return bool
	 */
	private function is_on_update( $extra ): bool {
		return is_scalar( $extra )
			&& ( 'ON UPDATE CURRENT_TIMESTAMP' === strtoupper( (string) $extra ) );
	}
}
