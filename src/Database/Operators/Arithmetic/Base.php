<?php
/**
 * Arithmetic Operator Base Class.
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
 * Base class for an infix ARITHMETIC operator ( + - * / ).
 *
 * Distinct from Operators\Comparisons\Base: a comparison operator builds a boolean
 * PREDICATE ( `{a} = {b}` ), an arithmetic operator an EXPRESSION that yields a value
 * ( `{a} + {b}` ). So an arithmetic operator carries none of the comparison machinery
 * ( value shaping, list/range/unary, polarity ) - just its infix symbol and the
 * placeholder its numeric result compares as. The Operands\Math operand holds one of
 * these and asks it to render + report its pattern; division owns "I yield a float".
 *
 * @since 3.1.0
 * @internal Operand collaborator; resolved by Operators\Arithmetic\Registry.
 */
abstract class Base {

	/**
	 * The infix SQL symbol ( '+', '-', '*', '/' ).
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $symbol = '';

	/**
	 * The wpdb::prepare() placeholder this operator's result compares as. Integer by
	 * default; Divide overrides to a float. A float MEMBER promotes it further, but
	 * that is the Math operand's concern ( it inspects the members ).
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $pattern = '%d';

	/**
	 * Return the infix SQL symbol.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_symbol(): string {
		return $this->symbol;
	}

	/**
	 * Return the placeholder this operator's result compares as.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_pattern(): string {
		return $this->pattern;
	}
}
