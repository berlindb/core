<?php
/**
 * Operator class tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Column;
use BerlinDB\Database\Operators\Between;
use BerlinDB\Database\Operators\Equal;
use BerlinDB\Database\Operators\Exists;
use BerlinDB\Database\Operators\GreaterThan;
use BerlinDB\Database\Operators\GreaterThanOrEqual;
use BerlinDB\Database\Operators\In;
use BerlinDB\Database\Operators\IsNotNull;
use BerlinDB\Database\Operators\IsNull;
use BerlinDB\Database\Operators\LessThan;
use BerlinDB\Database\Operators\LessThanOrEqual;
use BerlinDB\Database\Operators\Like;
use BerlinDB\Database\Operators\NotBetween;
use BerlinDB\Database\Operators\NotEqual;
use BerlinDB\Database\Operators\NotExists;
use BerlinDB\Database\Operators\NotIn;
use BerlinDB\Database\Operators\NotLike;
use BerlinDB\Database\Operators\NotRegexp;
use BerlinDB\Database\Operators\Regexp;
use BerlinDB\Database\Operators\Rlike;
use PHPUnit\Framework\TestCase;

/**
 * Tests for all BerlinDB operator classes.
 *
 * Covers descriptor properties ($compare, $positive, $opposite_compare,
 * $multi, $numeric), the opposite linkage, get_value_sql() output for each
 * operator, and the full-expression get_sql() method on the trait.
 *
 * @since 3.0.0
 */
class OperatorsTest extends TestCase {

	// -------------------------------------------------------------------------
	// Descriptor properties.
	// -------------------------------------------------------------------------

	/**
	 * Test Equal descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_equal_descriptor_properties() {
		$op = new Equal();
		$this->assertSame( '=', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test NotEqual descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_not_equal_descriptor_properties() {
		$op = new NotEqual();
		$this->assertSame( '!=', $op->compare );
		$this->assertFalse( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test GreaterThan descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_greater_than_descriptor_properties() {
		$op = new GreaterThan();
		$this->assertSame( '>', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertTrue( $op->numeric );
	}

	/**
	 * Test GreaterThanOrEqual descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_greater_than_or_equal_descriptor_properties() {
		$op = new GreaterThanOrEqual();
		$this->assertSame( '>=', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertTrue( $op->numeric );
	}

	/**
	 * Test LessThan descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_less_than_descriptor_properties() {
		$op = new LessThan();
		$this->assertSame( '<', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertTrue( $op->numeric );
	}

	/**
	 * Test LessThanOrEqual descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_less_than_or_equal_descriptor_properties() {
		$op = new LessThanOrEqual();
		$this->assertSame( '<=', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertTrue( $op->numeric );
	}

	/**
	 * Test In descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_in_descriptor_properties() {
		$op = new In();
		$this->assertSame( 'IN', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertTrue( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test NotIn descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_not_in_descriptor_properties() {
		$op = new NotIn();
		$this->assertSame( 'NOT IN', $op->compare );
		$this->assertFalse( $op->positive );
		$this->assertTrue( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test Between descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_between_descriptor_properties() {
		$op = new Between();
		$this->assertSame( 'BETWEEN', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertTrue( $op->multi );
		$this->assertTrue( $op->numeric );
	}

	/**
	 * Test NotBetween descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_not_between_descriptor_properties() {
		$op = new NotBetween();
		$this->assertSame( 'NOT BETWEEN', $op->compare );
		$this->assertFalse( $op->positive );
		$this->assertTrue( $op->multi );
		$this->assertTrue( $op->numeric );
	}

	/**
	 * Test Like descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_like_descriptor_properties() {
		$op = new Like();
		$this->assertSame( 'LIKE', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test NotLike descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_not_like_descriptor_properties() {
		$op = new NotLike();
		$this->assertSame( 'NOT LIKE', $op->compare );
		$this->assertFalse( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test Exists descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_exists_descriptor_properties() {
		$op = new Exists();
		$this->assertSame( 'EXISTS', $op->compare );
		$this->assertSame( '=', $op->sql_compare );
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test NotExists descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_not_exists_descriptor_properties() {
		$op = new NotExists();
		$this->assertSame( 'NOT EXISTS', $op->compare );
		$this->assertFalse( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test Regexp descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_regexp_descriptor_properties() {
		$op = new Regexp();
		$this->assertSame( 'REGEXP', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test NotRegexp descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_not_regexp_descriptor_properties() {
		$op = new NotRegexp();
		$this->assertSame( 'NOT REGEXP', $op->compare );
		$this->assertFalse( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test Rlike descriptor properties.
	 *
	 * @since 3.0.0
	 */
	public function test_rlike_descriptor_properties() {
		$op = new Rlike();
		$this->assertSame( 'RLIKE', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
	}

	/**
	 * Test IsNull descriptor properties.
	 *
	 * @since 3.1.0
	 */
	public function test_is_null_descriptor_properties() {
		$op = new IsNull();
		$this->assertSame( 'IS NULL', $op->compare );
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
		$this->assertTrue( $op->is_unary() );
	}

	/**
	 * Test IsNotNull descriptor properties.
	 *
	 * @since 3.1.0
	 */
	public function test_is_not_null_descriptor_properties() {
		$op = new IsNotNull();
		$this->assertSame( 'IS NOT NULL', $op->compare );
		// Positive: IS NOT NULL is a direct predicate, not an anti-join.
		$this->assertTrue( $op->positive );
		$this->assertFalse( $op->multi );
		$this->assertFalse( $op->numeric );
		$this->assertTrue( $op->is_unary() );
	}

	// -------------------------------------------------------------------------
	// Unary operators (IS NULL / IS NOT NULL).
	// -------------------------------------------------------------------------

	/**
	 * Test that IsNull::get_sql emits a value-less `IS NULL` predicate.
	 *
	 * @since 3.1.0
	 */
	public function test_is_null_get_sql_emits_value_less_predicate() {
		$col = new Column(
			array(
				'name' => 'deleted_at',
				'type' => 'datetime',
			)
		);
		$sql = ( new IsNull() )->get_sql( $col, 't' );
		$this->assertSame( '`t`.`deleted_at` IS NULL', $sql );
	}

	/**
	 * Test that IsNotNull::get_sql emits a value-less `IS NOT NULL` predicate.
	 *
	 * @since 3.1.0
	 */
	public function test_is_not_null_get_sql_emits_value_less_predicate() {
		$col = new Column(
			array(
				'name' => 'deleted_at',
				'type' => 'datetime',
			)
		);
		$sql = ( new IsNotNull() )->get_sql( $col, 't' );
		$this->assertSame( '`t`.`deleted_at` IS NOT NULL', $sql );
	}

	/**
	 * Test that a passed value is ignored by the unary operators.
	 *
	 * @since 3.1.0
	 */
	public function test_is_null_ignores_any_passed_value() {
		$col = new Column(
			array(
				'name' => 'deleted_at',
				'type' => 'datetime',
			)
		);
		$this->assertSame(
			'`deleted_at` IS NULL',
			( new IsNull() )->get_sql( $col, '', 'whatever' )
		);
	}

	/**
	 * Test that unary operators return no value fragment, so value-driven
	 * builders skip them rather than emitting a spurious operand.
	 *
	 * @since 3.1.0
	 */
	public function test_unary_get_value_sql_is_empty() {
		$this->assertSame( '', ( new IsNull() )->get_value_sql( 'x' ) );
		$this->assertSame( '', ( new IsNotNull() )->get_value_sql( 'x' ) );
	}

	// -------------------------------------------------------------------------
	// get_sql_compare.
	// -------------------------------------------------------------------------

	/**
	 * Test that Exists::get_sql_compare returns '=' rather than 'EXISTS'.
	 *
	 * @since 3.0.0
	 */
	public function test_exists_get_sql_compare_returns_equals_sign() {
		$this->assertSame( '=', ( new Exists() )->get_sql_compare() );
	}

	/**
	 * Test that get_sql_compare falls back to $compare when $sql_compare is unset.
	 *
	 * @since 3.0.0
	 */
	public function test_get_sql_compare_falls_back_to_compare() {
		$this->assertSame( '=', ( new Equal() )->get_sql_compare() );
		$this->assertSame( 'NOT IN', ( new NotIn() )->get_sql_compare() );
	}

	// -------------------------------------------------------------------------
	// Descriptor accessors (is_regex / is_numeric / is_multi).
	// -------------------------------------------------------------------------

	/**
	 * Test that is_regex() is true only for regular-expression operators.
	 *
	 * @since 3.1.0
	 */
	public function test_is_regex_marks_regex_operators() {
		$this->assertTrue( ( new Regexp() )->is_regex() );
		$this->assertTrue( ( new Rlike() )->is_regex() );
		// Negative regex is still a regex operator (polarity is a separate axis).
		$this->assertTrue( ( new NotRegexp() )->is_regex() );

		$this->assertFalse( ( new Like() )->is_regex() );
		$this->assertFalse( ( new Equal() )->is_regex() );
		$this->assertFalse( ( new In() )->is_regex() );
	}

	/**
	 * Test the is_numeric() and is_multi() descriptor accessors.
	 *
	 * @since 3.1.0
	 */
	public function test_numeric_and_multi_accessors() {
		$this->assertTrue( ( new GreaterThan() )->is_numeric() );
		$this->assertFalse( ( new Equal() )->is_numeric() );

		$this->assertTrue( ( new In() )->is_multi() );
		$this->assertTrue( ( new Between() )->is_multi() );
		$this->assertFalse( ( new Equal() )->is_multi() );
	}

	// -------------------------------------------------------------------------
	// Scalar get_value_sql (trait default).
	// -------------------------------------------------------------------------

	/**
	 * Test that the default get_value_sql prepares a scalar string value.
	 *
	 * @since 3.0.0
	 */
	public function test_scalar_get_value_sql_prepares_string_value() {
		$sql = ( new Equal() )->get_value_sql( 'active' );
		$this->assertStringContainsString( 'active', $sql );
	}

	/**
	 * Test that the default get_value_sql trims leading and trailing whitespace.
	 *
	 * @since 3.0.0
	 */
	public function test_scalar_get_value_sql_trims_whitespace() {
		$trimmed = ( new Equal() )->get_value_sql( 'active' );
		$padded  = ( new Equal() )->get_value_sql( '  active  ' );
		$this->assertSame( $trimmed, $padded );
	}

	// -------------------------------------------------------------------------
	// In / NotIn.
	// -------------------------------------------------------------------------

	/**
	 * Test that In::get_value_sql produces a parenthesised list from an array.
	 *
	 * @since 3.0.0
	 */
	public function test_in_get_value_sql_with_array_produces_parenthesised_list() {
		$sql = ( new In() )->get_value_sql( array( 'active', 'inactive' ) );
		$this->assertStringStartsWith( '(', $sql );
		$this->assertStringEndsWith( ')', $sql );
		$this->assertStringContainsString( 'active', $sql );
		$this->assertStringContainsString( 'inactive', $sql );
	}

	/**
	 * Test that In::get_value_sql splits a comma-delimited string into values.
	 *
	 * @since 3.0.0
	 */
	public function test_in_get_value_sql_splits_delimited_string() {
		$from_array  = ( new In() )->get_value_sql( array( 'a', 'b', 'c' ) );
		$from_string = ( new In() )->get_value_sql( 'a, b, c' );
		$this->assertSame( $from_array, $from_string );
	}

	/**
	 * Test that NotIn::get_value_sql produces the same parenthesised fragment as In.
	 *
	 * @since 3.0.0
	 */
	public function test_not_in_get_value_sql_produces_same_fragment_as_in() {
		$in     = ( new In() )->get_value_sql( array( 'active', 'pending' ) );
		$not_in = ( new NotIn() )->get_value_sql( array( 'active', 'pending' ) );
		$this->assertSame( $in, $not_in );
	}

	/**
	 * Test that In::get_value_sql returns an empty string for an empty value list.
	 *
	 * IN () is invalid SQL; the guard prevents a malformed query.
	 *
	 * @since 3.0.0
	 */
	public function test_in_get_value_sql_returns_empty_for_empty_array() {
		$this->assertSame( '', ( new In() )->get_value_sql( array() ) );
	}

	/**
	 * Test that NotIn::get_value_sql returns an empty string for an empty value list.
	 *
	 * @since 3.0.0
	 */
	public function test_not_in_get_value_sql_returns_empty_for_empty_array() {
		$this->assertSame( '', ( new NotIn() )->get_value_sql( array() ) );
	}

	// -------------------------------------------------------------------------
	// Between / NotBetween.
	// -------------------------------------------------------------------------

	/**
	 * Test that Between::get_value_sql produces a low AND high fragment from an array.
	 *
	 * @since 3.0.0
	 */
	public function test_between_get_value_sql_with_array_produces_and_fragment() {
		$sql = ( new Between() )->get_value_sql( array( 1, 10 ) );
		$this->assertStringContainsString( ' AND ', $sql );
		$this->assertStringContainsString( '1', $sql );
		$this->assertStringContainsString( '10', $sql );
	}

	/**
	 * Test that Between::get_value_sql splits a space-delimited string.
	 *
	 * @since 3.0.0
	 */
	public function test_between_get_value_sql_splits_delimited_string() {
		$from_array  = ( new Between() )->get_value_sql( array( 1, 10 ) );
		$from_string = ( new Between() )->get_value_sql( '1 10' );
		$this->assertSame( $from_array, $from_string );
	}

	/**
	 * Test that Between::get_value_sql only uses the first two values from the array.
	 *
	 * @since 3.0.0
	 */
	public function test_between_get_value_sql_uses_only_first_two_values() {
		$two   = ( new Between() )->get_value_sql( array( 1, 10 ) );
		$three = ( new Between() )->get_value_sql( array( 1, 10, 99 ) );
		$this->assertSame( $two, $three );
	}

	/**
	 * Test that NotBetween::get_value_sql produces the same fragment as Between.
	 *
	 * @since 3.0.0
	 */
	public function test_not_between_get_value_sql_produces_same_fragment_as_between() {
		$between     = ( new Between() )->get_value_sql( array( 1, 10 ) );
		$not_between = ( new NotBetween() )->get_value_sql( array( 1, 10 ) );
		$this->assertSame( $between, $not_between );
	}

	/**
	 * Test that Between::get_value_sql returns an empty string when fewer than two values are given.
	 *
	 * BETWEEN requires both a low and high bound; a single value would produce
	 * a mismatched placeholder arity in wpdb::prepare().
	 *
	 * @since 3.0.0
	 */
	public function test_between_get_value_sql_returns_empty_for_single_value() {
		$this->assertSame( '', ( new Between() )->get_value_sql( array( 5 ) ) );
	}

	/**
	 * Test that NotBetween::get_value_sql returns an empty string when fewer than two values are given.
	 *
	 * @since 3.0.0
	 */
	public function test_not_between_get_value_sql_returns_empty_for_single_value() {
		$this->assertSame( '', ( new NotBetween() )->get_value_sql( array( 5 ) ) );
	}

	// -------------------------------------------------------------------------
	// Like / NotLike.
	// -------------------------------------------------------------------------

	/**
	 * Test that Like::get_value_sql wraps the value in % wildcards.
	 *
	 * @since 3.0.0
	 */
	public function test_like_get_value_sql_wraps_value_in_wildcards() {
		$sql = $GLOBALS['wpdb']->remove_placeholder_escape( ( new Like() )->get_value_sql( 'hello' ) );
		$this->assertStringContainsString( '%hello%', $sql );
	}

	/**
	 * Test that Like::get_value_sql escapes LIKE special characters with esc_like.
	 *
	 * @since 3.0.0
	 */
	public function test_like_get_value_sql_escapes_like_special_chars() {
		$sql = $GLOBALS['wpdb']->remove_placeholder_escape( ( new Like() )->get_value_sql( '50% off' ) );
		$this->assertStringContainsString( '\%', $sql );
	}

	/**
	 * Test that Like::get_value_sql escapes literal underscores with esc_like.
	 *
	 * @since 3.0.0
	 */
	public function test_like_get_value_sql_escapes_literal_underscore() {
		$sql = $GLOBALS['wpdb']->remove_placeholder_escape( ( new Like() )->get_value_sql( 'code_1' ) );
		$this->assertStringContainsString( '\_', $sql );
	}

	/**
	 * Test that NotLike::get_value_sql produces the same fragment as Like.
	 *
	 * @since 3.0.0
	 */
	public function test_not_like_get_value_sql_produces_same_fragment_as_like() {
		$like     = ( new Like() )->get_value_sql( 'hello' );
		$not_like = ( new NotLike() )->get_value_sql( 'hello' );
		$this->assertSame( $like, $not_like );
	}

	// -------------------------------------------------------------------------
	// Exists / NotExists.
	// -------------------------------------------------------------------------

	/**
	 * Test that Exists::get_value_sql prepares a scalar value normally.
	 *
	 * @since 3.0.0
	 */
	public function test_exists_get_value_sql_prepares_value() {
		$sql = ( new Exists() )->get_value_sql( 'my_meta_key' );
		$this->assertStringContainsString( 'my_meta_key', $sql );
	}

	/**
	 * Test that NotExists::get_value_sql always returns an empty string.
	 *
	 * @since 3.0.0
	 */
	public function test_not_exists_get_value_sql_always_returns_empty_string() {
		$this->assertSame( '', ( new NotExists() )->get_value_sql( 'anything' ) );
		$this->assertSame( '', ( new NotExists() )->get_value_sql() );
	}

	// -------------------------------------------------------------------------
	// get_sql (full WHERE expression).
	// -------------------------------------------------------------------------

	/**
	 * Test that get_sql returns the full WHERE expression for a scalar operator.
	 *
	 * @since 3.0.0
	 */
	public function test_get_sql_returns_full_where_expression() {
		$col = new Column(
			array(
				'name' => 'status',
				'type' => 'varchar',
			)
		);
		$sql = ( new Equal() )->get_sql( $col, '', 'active' );
		$this->assertStringContainsString( '`status`', $sql );
		$this->assertStringContainsString( '=', $sql );
		$this->assertStringContainsString( 'active', $sql );
	}

	/**
	 * Test that get_sql prefixes the column reference with the table alias.
	 *
	 * @since 3.0.0
	 */
	public function test_get_sql_includes_alias_in_column_reference() {
		$col = new Column(
			array(
				'name' => 'status',
				'type' => 'varchar',
			)
		);
		$sql = ( new Equal() )->get_sql( $col, 't', 'active' );
		$this->assertStringContainsString( '`t`.`status`', $sql );
	}

	/**
	 * Test that get_sql wraps the column side in CAST() when a cast is given,
	 * for a scalar operator.
	 *
	 * @since 3.1.0
	 */
	public function test_get_sql_casts_column_for_scalar_operator() {
		$col = new Column(
			array(
				'name' => 'total',
				'type' => 'varchar',
			)
		);
		$sql = ( new Equal() )->get_sql( $col, 't', '100', 'SIGNED' );
		$this->assertStringContainsString( 'CAST(`t`.`total` AS SIGNED)', $sql );
	}

	/**
	 * Test that get_sql casts the column side for a value-overriding operator
	 * (IN), proving the cast applies to the LHS regardless of operator subclass.
	 *
	 * @since 3.1.0
	 */
	public function test_get_sql_casts_column_for_in_operator() {
		$col = new Column(
			array(
				'name' => 'total',
				'type' => 'varchar',
			)
		);
		$sql = ( new In() )->get_sql( $col, 't', array( '1', '2' ), 'SIGNED' );
		$this->assertStringContainsString( 'CAST(`t`.`total` AS SIGNED)', $sql );
		$this->assertStringContainsStringIgnoringCase( 'IN', $sql );
	}

	/**
	 * Test that get_sql returns an empty string when get_value_sql returns empty.
	 *
	 * NotExists::get_value_sql() always returns '' so get_sql() short-circuits.
	 *
	 * @since 3.0.0
	 */
	public function test_get_sql_returns_empty_when_value_sql_is_empty() {
		$col = new Column(
			array(
				'name' => 'meta_key',
				'type' => 'varchar',
			)
		);
		$this->assertSame( '', ( new NotExists() )->get_sql( $col, '', 'anything' ) );
	}

	/**
	 * Test that get_sql uses get_sql_compare (not $compare) in the expression.
	 *
	 * Exists has $compare='EXISTS' but get_sql_compare() returns '=', so the
	 * assembled expression uses '=' rather than 'EXISTS'.
	 *
	 * @since 3.0.0
	 */
	public function test_get_sql_uses_sql_compare_for_exists() {
		$col = new Column(
			array(
				'name' => 'meta_key',
				'type' => 'varchar',
			)
		);
		$sql = ( new Exists() )->get_sql( $col, '', 'my_key' );
		$this->assertStringContainsString( '=', $sql );
		$this->assertStringNotContainsString( 'EXISTS', $sql );
	}

	/**
	 * Test that get_sql with In builds a full IN expression.
	 *
	 * @since 3.0.0
	 */
	public function test_get_sql_with_in_builds_full_expression() {
		$col = new Column(
			array(
				'name' => 'status',
				'type' => 'varchar',
			)
		);
		$sql = ( new In() )->get_sql( $col, '', array( 'active', 'pending' ) );
		$this->assertStringContainsString( '`status`', $sql );
		$this->assertStringContainsString( 'IN', $sql );
		$this->assertStringContainsString( 'active', $sql );
	}

	/**
	 * Test that get_sql with Like builds a full LIKE expression with wildcards.
	 *
	 * @since 3.0.0
	 */
	public function test_get_sql_with_like_builds_full_expression() {
		$col = new Column(
			array(
				'name' => 'title',
				'type' => 'varchar',
			)
		);
		$sql = $GLOBALS['wpdb']->remove_placeholder_escape( ( new Like() )->get_sql( $col, 't', 'hello' ) );
		$this->assertStringContainsString( '`t`.`title`', $sql );
		$this->assertStringContainsString( 'LIKE', $sql );
		$this->assertStringContainsString( '%hello%', $sql );
	}

	/**
	 * Test that get_sql derives the prepare pattern from the column type.
	 *
	 * An integer column has pattern '%d'; get_sql() reads this from $col->pattern
	 * so the caller does not need to supply it separately.
	 *
	 * @since 3.0.0
	 */
	public function test_get_sql_derives_pattern_from_column() {
		$col = new Column(
			array(
				'name' => 'count',
				'type' => 'bigint',
			)
		);
		$sql = ( new Equal() )->get_sql( $col, '', 42 );
		$this->assertStringContainsString( '`count`', $sql );
		$this->assertStringContainsString( '42', $sql );
	}

	// -------------------------------------------------------------------------
	// Opposite linkage ($opposite_compare / get_opposite_compare / is_positive).
	// -------------------------------------------------------------------------

	/**
	 * Every operator instance, keyed by its $compare string.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string, \BerlinDB\Database\Operators\Base>
	 */
	private function operators_by_compare(): array {
		$instances = array(
			new Equal(),
			new NotEqual(),
			new Exists(),
			new NotExists(),
			new In(),
			new NotIn(),
			new Like(),
			new NotLike(),
			new Regexp(),
			new NotRegexp(),
			new Rlike(),
			new Between(),
			new NotBetween(),
			new GreaterThan(),
			new GreaterThanOrEqual(),
			new LessThan(),
			new LessThanOrEqual(),
			new IsNull(),
			new IsNotNull(),
		);

		$map = array();

		foreach ( $instances as $op ) {
			$map[ $op->compare ] = $op;
		}

		return $map;
	}

	/**
	 * Test that each operator reports its logical opposite's $compare.
	 *
	 * @since 3.1.0
	 */
	public function test_operator_opposites() {
		$expected = array(
			'='           => '!=',
			'!='          => '=',
			'EXISTS'      => 'NOT EXISTS',
			'NOT EXISTS'  => 'EXISTS',
			'IN'          => 'NOT IN',
			'NOT IN'      => 'IN',
			'LIKE'        => 'NOT LIKE',
			'NOT LIKE'    => 'LIKE',
			'REGEXP'      => 'NOT REGEXP',
			'NOT REGEXP'  => 'REGEXP',
			'BETWEEN'     => 'NOT BETWEEN',
			'NOT BETWEEN' => 'BETWEEN',
			'>'           => '<=',
			'<='          => '>',
			'<'           => '>=',
			'>='          => '<',
			'IS NULL'     => 'IS NOT NULL',
			'IS NOT NULL' => 'IS NULL',
			'RLIKE'       => '',
		);

		$ops = $this->operators_by_compare();

		foreach ( $expected as $compare => $opposite ) {
			$this->assertSame( $opposite, $ops[ $compare ]->get_opposite_compare(), "opposite of {$compare}" );
		}
	}

	/**
	 * Test that opposites are involutive and that negatives flip to a positive.
	 *
	 * For every operator with a declared opposite, resolving that opposite and
	 * asking for ITS opposite returns the original (a <-> b). And any negating
	 * operator always flips to a positive one — the property the meta key-flip
	 * (build_relationship_clause) relies on to express NOT EXISTS.
	 *
	 * @since 3.1.0
	 */
	public function test_opposite_is_involutive_and_flips_negatives_positive() {
		$ops = $this->operators_by_compare();

		foreach ( $ops as $compare => $op ) {
			$opposite = $op->get_opposite_compare();

			// RLIKE (a REGEXP synonym) declares no distinct opposite; skip.
			if ( '' === $opposite ) {
				continue;
			}

			$this->assertArrayHasKey( $opposite, $ops, "{$compare} opposite {$opposite} resolves" );
			$this->assertSame( $compare, $ops[ $opposite ]->get_opposite_compare(), "{$compare} <-> {$opposite}" );

			// A negating operator must flip to a positive one.
			if ( ! $op->is_positive() ) {
				$this->assertTrue( $ops[ $opposite ]->is_positive(), "{$compare} flips positive" );
			}
		}
	}

	/**
	 * Test that RLIKE declares no opposite (it is a REGEXP synonym).
	 *
	 * @since 3.1.0
	 */
	public function test_rlike_has_no_opposite() {
		$this->assertSame( '', ( new Rlike() )->get_opposite_compare() );
	}

	/**
	 * Test that is_positive() reflects the $positive descriptor.
	 *
	 * @since 3.1.0
	 */
	public function test_is_positive_accessor_matches_descriptor() {
		$this->assertTrue( ( new Equal() )->is_positive() );
		$this->assertFalse( ( new NotEqual() )->is_positive() );
	}
}
