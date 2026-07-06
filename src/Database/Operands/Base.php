<?php
/**
 * Operand Base Class.
 *
 * @package     Database
 * @subpackage  Operands
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operands;

use BerlinDB\Database\Operators\Comparisons;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Base class for the right-hand-side operand of a comparison.
 *
 * An operator emits `{column} {compare} {operand}`. The default operand is a
 * prepared scalar value, rendered by the operator itself; an Operand object is
 * the opt-in alternative for a non-scalar right-hand side - a column reference,
 * a function-wrapped expression, or a subquery.
 *
 * Operands are built by the parser layer, which resolves and validates them
 * against the schema and fails closed on anything unresolvable. By the time an
 * operand exists, it is already safe, so get_sql() is a pure renderer that needs
 * no further validation.
 *
 * @since 3.1.0
 * @internal Parser collaborator. Operands are constructed from operand specs by
 *           the parser and consumed by it; the public surface is the operand spec
 *           array (`array( 'operand' => 'column', ... )`), not these classes. The
 *           methods are public only because the parser (a separate class) calls
 *           them - PHP has no friend/package visibility.
 */
abstract class Base {

	/**
	 * The wpdb::prepare() placeholder a scalar compared against this operand uses.
	 *
	 * The base default is a string placeholder; a subclass that knows its type sets
	 * this in init() ( a column folds its cast, a function its return type, a cast
	 * its target ), so get_comparison_pattern() is a plain accessor - no per-subclass
	 * override.
	 *
	 * @since 3.1.0
	 * @var string A wpdb::prepare() placeholder ('%s', '%d', or '%f').
	 */
	protected $pattern = '%s';

	/**
	 * How many columns wide this operand is ( its arity ).
	 *
	 * A single expression is 1 ( the default ); a Tuple / Collection computes its
	 * own width in init(). A Predicate pairs two operands only when their widths match.
	 *
	 * @since 3.1.0
	 * @var int
	 */
	protected $width = 1;

	/**
	 * Whether this operand may be the LEFT subject of a comparison.
	 *
	 * Every operand can appear on the RIGHT, so only left-ness varies: a single
	 * expression and a Tuple ( a row value ) can be a left subject; a Collection ( an
	 * IN value-set ) and a Range ( BETWEEN bounds ) are value shapes valid only on the
	 * right, so they set this false. ( There is no `$right` because nothing is
	 * right-restricted; add one the day a left-only operand exists. )
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $left = true;

	/**
	 * Whether this operand is a single scalar expression ( vs a value shape ).
	 *
	 * A column / value / function / cast is scalar; a Collection, Range, and Tuple
	 * are not, so they set this false. Casting is only meaningful around a scalar
	 * ( `CAST( ( a, b ) AS ... )` is not valid SQL ), so a cast on a non-scalar
	 * operand fails closed.
	 *
	 * @since 3.1.0
	 * @var bool
	 */
	protected $scalar = true;

	/**
	 * Whether a value is a structured operand spec (vs a bare scalar or list).
	 *
	 * A spec is an associative array carrying an explicit 'operand' marker - e.g.
	 * `array( 'operand' => 'column', 'name' => 'x' )`. A bare scalar or a numeric-
	 * keyed list (an IN list) is NOT a spec, so classifying by KEY PRESENCE (not
	 * value) keeps existing queries on the ordinary value path; a present-but-invalid
	 * marker still counts as a spec, so it fails closed in resolution rather than
	 * slipping back to the scalar path.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value The value to classify.
	 * @return bool
	 */
	public static function is_spec( $value ): bool {
		return is_array( $value ) && array_key_exists( 'operand', $value );
	}

	/**
	 * Build an operand from a key-value argument array.
	 *
	 * Mirrors Operators\Base: the constructor delegates to init(), which each
	 * concrete operand implements to assign its own keys. Operands are dumb
	 * renderers, so they deliberately do NOT compose Traits\Base (no Magic,
	 * logging, or sanitization) - only this construction shape is shared, so the
	 * argument contract can outlive the current property layout.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Operand arguments; see each subclass init().
	 */
	public function __construct( array $args = array() ) {
		if ( ! empty( $args ) ) {
			$this->init( $args );
		}
	}

	/**
	 * Assign constructor arguments to properties.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Operand arguments.
	 * @return void
	 */
	abstract protected function init( array $args ): void;

	/**
	 * Render this operand as the SQL fragment for the right-hand side.
	 *
	 * @since 3.1.0
	 *
	 * @return string The SQL fragment, or '' when the operand renders nothing.
	 */
	abstract public function get_sql(): string;

	/**
	 * Return the wpdb::prepare() placeholder a scalar should use when compared
	 * against this operand on the OTHER side of a comparison.
	 *
	 * Lets a bare-scalar value derive its placeholder from the expression it is
	 * compared with (e.g. an integer column or an integer-returning function takes
	 * '%d'). The value is the $pattern property, set by each subclass in init().
	 *
	 * @since 3.1.0
	 *
	 * @return string A wpdb::prepare() placeholder ('%s', '%d', or '%f').
	 */
	public function get_comparison_pattern(): string {
		return $this->pattern;
	}

	/**
	 * Whether this operand pairs with the given operator.
	 *
	 * A Predicate consults this to fail closed on a shape mismatch. The default -
	 * a single-value operand (column / function / prepared value) - pairs with the
	 * scalar comparison operators. Collection and Range operands override this to
	 * pair with the list and range operators instead.
	 *
	 * @since 3.1.0
	 *
	 * @param Comparisons\Base $operator The operator being paired.
	 * @return bool
	 */
	public function pairs_with( Comparisons\Base $operator ): bool {
		return $operator->is_expression();
	}

	/**
	 * The arity of this operand - how many columns wide it is ( the $width property ).
	 *
	 * A single-value operand (column / function / prepared value) is width 1. A
	 * Tuple is as wide as its members; a Collection is as wide as each of its
	 * members. A Predicate pairs two operands only when their widths match, so a
	 * scalar compares to a scalar and a `( a, b )` tuple to a `( c, d )` tuple.
	 *
	 * @since 3.1.0
	 *
	 * @return int
	 */
	public function get_width(): int {
		return $this->width;
	}

	/**
	 * Whether this operand may be the LEFT subject of a comparison ( $can_be_left ).
	 *
	 * A Predicate rejects an invalid left rather than emit malformed SQL.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function can_be_left(): bool {
		return $this->left;
	}

	/**
	 * Whether this operand is a single scalar expression ( the $scalar property ).
	 *
	 * A cast is only meaningful around a scalar ( `CAST( ( a, b ) AS ... )` is not
	 * valid SQL ), so an opt-in cast on a non-scalar operand fails closed.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_scalar(): bool {
		return $this->scalar;
	}
}
