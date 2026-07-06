<?php
/**
 * Logical Operator Registry.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operators\Logical;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Holds the allow-listed logical RELATIONS and looks one up by keyword.
 *
 * The manager for the n-ary members of the Operators\Logical\* family - Conjunction
 * ( AND ), Disjunction ( OR ), ExclusiveDisjunction ( XOR ) - the relations a caller
 * picks ONE of to join a set of boolean fragments. The canonical allow-list: the
 * clause layer resolves a relation keyword through here rather than hand-coercing
 * strings, so an unknown keyword returns null and the caller falls back deliberately.
 *
 * Negation ( NOT ) is unary and orthogonal to the relation, so it is NOT registered
 * here - Clauses\BooleanGroup instantiates it directly when a group is negated.
 *
 * @since 3.1.0
 * @internal Clause collaborator.
 */
class Registry {

	/**
	 * The relation instances, in registration order.
	 *
	 * @since 3.1.0
	 * @var list<Base>
	 */
	private $operators;

	/**
	 * Build the registry from a list of relation class names ( defaults to the
	 * allow-list ).
	 *
	 * @since 3.1.0
	 *
	 * @param list<class-string<Base>> $classes Relation class names. Default the allow-list.
	 */
	public function __construct( array $classes = array() ) {
		if ( empty( $classes ) ) {
			$classes = self::default_classes();
		}

		$this->operators = self::instances( $classes );
	}

	/**
	 * The default allow-list of logical relation classes.
	 *
	 * @since 3.1.0
	 *
	 * @return list<class-string<Base>>
	 */
	public static function default_classes(): array {
		return array(
			Conjunction::class,
			Disjunction::class,
			ExclusiveDisjunction::class,
		);
	}

	/**
	 * Instantiate the relation classes ( cached per class list ).
	 *
	 * @since 3.1.0
	 *
	 * @param list<class-string<Base>> $classes Relation class names.
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
	 * Return the relation for a keyword ( 'AND', 'OR', 'XOR' ), or null.
	 *
	 * @since 3.1.0
	 *
	 * @param string $symbol The relation keyword ( case-sensitive; callers pass uppercase ).
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
	 * Return all registered relations.
	 *
	 * @since 3.1.0
	 *
	 * @return list<Base>
	 */
	public function all(): array {
		return $this->operators;
	}
}
