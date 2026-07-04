<?php
/**
 * Operator Registry.
 *
 * @package     Database
 * @subpackage  Operators
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Operators;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Holds a set of comparison Operators and looks them up by property.
 *
 * A collaborator that any class building comparison SQL holds and asks for an
 * operator - the parser (with its filtered set) and Query (with the default set,
 * to render a HAVING clause). One place owns the default operator list, the
 * instancing (shared per class list), and the lookups. Distinct from Operators\Base
 * (a single operator's own SQL behavior); this manages the set of them.
 *
 * @since 3.1.0
 */
class Registry {

	/**
	 * The default operator classes.
	 *
	 * The canonical set every registry starts from. A parser layers its
	 * berlindb_database_operator_classes filter on top (see the parser's
	 * get_operator_classes()); a plain consumer uses this set as-is.
	 *
	 * @since 3.1.0
	 * @var list<class-string<Base>>
	 */
	private const DEFAULT_CLASSES = array(
		Between::class,
		Equal::class,
		Exists::class,
		GreaterThan::class,
		GreaterThanOrEqual::class,
		In::class,
		IsNotNull::class,
		IsNull::class,
		LessThan::class,
		LessThanOrEqual::class,
		Like::class,
		NotBetween::class,
		NotEqual::class,
		NotExists::class,
		NotIn::class,
		NotLike::class,
		NotRegexp::class,
		Regexp::class,
		Rlike::class,
	);

	/**
	 * The registered operator instances.
	 *
	 * @since 3.1.0
	 * @var list<Base>
	 */
	private $operators = array();

	/**
	 * Build the registry from a list of operator classes.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string> $classes Optional. Fully-qualified Operator class names.
	 *                               Defaults to the canonical set.
	 */
	public function __construct( array $classes = array() ) {
		if ( empty( $classes ) ) {
			$classes = self::default_classes();
		}

		$this->operators = self::instances( $classes );
	}

	/**
	 * The canonical default operator class list.
	 *
	 * @since 3.1.0
	 *
	 * @return list<class-string<Base>>
	 */
	public static function default_classes(): array {
		return self::DEFAULT_CLASSES;
	}

	/**
	 * Build one shared instance per operator class, cached by class list.
	 *
	 * The instances are immutable value objects, so a single set is shared across
	 * every registry built from the same class list. A static keyed by the list
	 * lets a filtered parser set and the default set coexist without re-building
	 * either. A class that is missing or not an Operator is skipped.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string> $classes Fully-qualified Operator class names.
	 * @return list<Base> One shared instance per valid class.
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
	 * Get operators, possibly filtered & plucked.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $filter Optional. Key => value pairs to match against each
	 *                                    operator's properties. Default empty array.
	 * @param bool|string         $field  Optional. A property name to pluck from each operator
	 *                                    instead of returning the full object. Default 'compare'.
	 * @return array<string,mixed>
	 */
	public function get_operators( $filter = array(), $field = 'compare' ) {
		return wp_filter_object_list( $this->operators, $filter, 'and', $field );
	}

	/**
	 * Get a single operator instance by an array of property arguments.
	 *
	 * Passes $args into get_operators() with no field pluck so full objects are
	 * returned, then returns the first match.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $args Key => value pairs to match against operator properties.
	 * @return Base|false The first matching operator, or false.
	 */
	public function get_operator_by( $args = array() ) {
		$filter = $this->get_operators( $args, false );
		$first  = ! empty( $filter )
			? reset( $filter )
			: false;

		return ( $first instanceof Base )
			? $first
			: false;
	}

	/**
	 * Get a single operator instance by its compare string.
	 *
	 * @since 3.1.0
	 *
	 * @param string $compare The SQL operator string, e.g. '=', 'IN', 'NOT LIKE'.
	 * @return Base|false The matching operator, or false.
	 */
	public function get_operator( $compare = '' ) {
		return $this->get_operator_by( array( 'compare' => $compare ) );
	}

	/**
	 * Get every registered operator instance.
	 *
	 * @since 3.1.0
	 *
	 * @return list<Base>
	 */
	public function all(): array {
		return $this->operators;
	}
}
