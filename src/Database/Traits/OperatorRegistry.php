<?php
/**
 * Operator Registry Trait.
 *
 * @package     Database
 * @subpackage  Trait
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Holds a set of comparison Operators and looks them up by property.
 *
 * The registry of Operator value objects, distinct from Traits\Operator (a single
 * operator's own SQL behavior). A parser primes it with its (filterable) operator
 * set; Query primes it from the default set to render HAVING. Both then look an
 * operator up by its compare string or any other property. Kept separate from the
 * parser so any class that needs to render a comparison can reuse the one registry.
 *
 * @since 3.1.0
 */
trait OperatorRegistry {

	/**
	 * The registered operator instances.
	 *
	 * @since 3.0.0
	 * @var   list<\BerlinDB\Database\Operators\Base>
	 */
	public $operators = array();

	/**
	 * The default operator class list.
	 *
	 * The canonical set every registry starts from. A parser layers its
	 * berlindb_database_operator_classes filter on top (see the parser's
	 * get_operator_classes()); a plain consumer uses this set as-is.
	 *
	 * @since 3.1.0
	 *
	 * @return list<class-string> Fully-qualified Operator class names.
	 */
	protected function default_operator_classes(): array {
		return array(
			'BerlinDB\\Database\\Operators\\Between',
			'BerlinDB\\Database\\Operators\\Equal',
			'BerlinDB\\Database\\Operators\\Exists',
			'BerlinDB\\Database\\Operators\\GreaterThan',
			'BerlinDB\\Database\\Operators\\GreaterThanOrEqual',
			'BerlinDB\\Database\\Operators\\In',
			'BerlinDB\\Database\\Operators\\IsNotNull',
			'BerlinDB\\Database\\Operators\\IsNull',
			'BerlinDB\\Database\\Operators\\LessThan',
			'BerlinDB\\Database\\Operators\\LessThanOrEqual',
			'BerlinDB\\Database\\Operators\\Like',
			'BerlinDB\\Database\\Operators\\NotBetween',
			'BerlinDB\\Database\\Operators\\NotEqual',
			'BerlinDB\\Database\\Operators\\NotExists',
			'BerlinDB\\Database\\Operators\\NotIn',
			'BerlinDB\\Database\\Operators\\NotLike',
			'BerlinDB\\Database\\Operators\\NotRegexp',
			'BerlinDB\\Database\\Operators\\Regexp',
			'BerlinDB\\Database\\Operators\\Rlike',
		);
	}

	/**
	 * Build one shared instance per operator class, process-cached by class list.
	 *
	 * The instances are immutable value objects, so a single set is shared across
	 * every registry that asks for the same class list. A static keyed by the list
	 * makes a filtered parser set and the default set coexist without re-building
	 * either. Returns the instances for the caller to assign to $this->operators.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string> $classes Fully-qualified Operator class names.
	 * @return list<\BerlinDB\Database\Operators\Base> One shared instance per class.
	 */
	protected function build_operators( array $classes ): array {
		static $instances = array();

		$key = md5( maybe_serialize( $classes ) );

		if ( ! isset( $instances[ $key ] ) ) {
			$instances[ $key ] = array();

			foreach ( $classes as $class ) {
				$operator = $this->instantiate_class( $class );

				if ( $operator instanceof \BerlinDB\Database\Operators\Base ) {
					$instances[ $key ][] = $operator;
				}
			}
		}

		return $instances[ $key ];
	}

	/**
	 * Get operators, possibly filtered & plucked.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $filter Optional. Key => value pairs to match against each
	 *                                    operator's properties. Default empty array.
	 * @param bool|string         $field  Optional. A property name to pluck from each operator
	 *                                    instead of returning the full object. Default 'compare'.
	 * @return array<string,mixed>
	 */
	protected function get_operators( $filter = array(), $field = 'compare' ) {
		return wp_filter_object_list( $this->operators, $filter, 'and', $field );
	}

	/**
	 * Get a single operator instance by an array of property arguments.
	 *
	 * Mirrors Query::get_column_by(). Passes $args into get_operators() with
	 * no field pluck so full objects are returned, then returns the first match.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $args Key => value pairs to match against operator properties.
	 *
	 * @return \BerlinDB\Database\Operators\Base|false The first matching operator, or false.
	 */
	protected function get_operator_by( $args = array() ) {

		// Get operators matching the filter arguments.
		$filter = $this->get_operators( $args, false );
		$first  = ! empty( $filter )
			? reset( $filter )
			: false;

		// Return the first match if it's an operator, otherwise false.
		return ( $first instanceof \BerlinDB\Database\Operators\Base )
			? $first
			: false;
	}

	/**
	 * Get a single operator instance by its compare string.
	 *
	 * @since 3.0.0
	 *
	 * @param string $compare The SQL operator string, e.g. '=', 'IN', 'NOT LIKE'.
	 *
	 * @return \BerlinDB\Database\Operators\Base|false The matching operator, or false.
	 */
	protected function get_operator( $compare = '' ) {
		return $this->get_operator_by( array( 'compare' => $compare ) );
	}
}
