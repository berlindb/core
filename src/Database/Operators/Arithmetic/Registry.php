<?php
/**
 * Arithmetic Operator Registry.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operators\Arithmetic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Holds the allow-listed arithmetic operators and looks one up by symbol.
 *
 * The manager for the Operators\Arithmetic\* value-object family ( a class in its own
 * directory, like Operators\Comparisons\Registry ). The Operands\Math operand holds
 * this and resolves a spec's `operator` symbol ( '+', '-', '*', '/' ) into an operator
 * object; an unknown symbol returns null so the operand fails closed.
 *
 * @since 3.1.0
 * @internal Operand collaborator.
 */
class Registry {

	/**
	 * The operator instances, in registration order.
	 *
	 * @since 3.1.0
	 * @var list<Base>
	 */
	private $operators;

	/**
	 * Build the registry from a list of operator class names ( defaults to the
	 * allow-list ).
	 *
	 * @since 3.1.0
	 *
	 * @param list<class-string<Base>> $classes Operator class names. Default the allow-list.
	 */
	public function __construct( array $classes = array() ) {
		if ( empty( $classes ) ) {
			$classes = self::default_classes();
		}

		$this->operators = self::instances( $classes );
	}

	/**
	 * The default allow-list of arithmetic operator classes.
	 *
	 * @since 3.1.0
	 *
	 * @return list<class-string<Base>>
	 */
	public static function default_classes(): array {
		return array(
			Add::class,
			Subtract::class,
			Multiply::class,
			Divide::class,
		);
	}

	/**
	 * Instantiate the operator classes ( cached per class list ).
	 *
	 * @since 3.1.0
	 *
	 * @param list<class-string<Base>> $classes Operator class names.
	 * @return list<Base>
	 */
	private static function instances( array $classes ): array {
		static $cache = array();

		$key = md5( maybe_serialize( $classes ) );

		if ( ! isset( $cache[ $key ] ) ) {
			$instances = array();

			foreach ( $classes as $class ) {
				if ( is_string( $class ) && class_exists( $class ) && is_subclass_of( $class, Base::class ) ) {
					$instances[] = new $class();
				}
			}

			$cache[ $key ] = $instances;
		}

		return $cache[ $key ];
	}

	/**
	 * Return the operator for an infix symbol ( '+', '-', '*', '/' ), or null.
	 *
	 * @since 3.1.0
	 *
	 * @param string $symbol The infix symbol.
	 * @return Base|null
	 */
	public function get_operator( string $symbol ): ?Base {
		foreach ( $this->operators as $operator ) {
			if ( $operator->get_symbol() === $symbol ) {
				return $operator;
			}
		}

		return null;
	}

	/**
	 * Return all registered operators.
	 *
	 * @since 3.1.0
	 *
	 * @return list<Base>
	 */
	public function all(): array {
		return $this->operators;
	}
}
