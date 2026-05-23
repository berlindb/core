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

use BerlinDB\Database\Operators\Between;
use BerlinDB\Database\Operators\Equal;
use BerlinDB\Database\Operators\Exists;
use BerlinDB\Database\Operators\GreaterThan;
use BerlinDB\Database\Operators\GreaterThanOrEqual;
use BerlinDB\Database\Operators\In;
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
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for all BerlinDB operator classes.
 *
 * Covers descriptor properties ($compare, $positive, $multi, $numeric) and
 * the get_sql() output for each operator, including overridden implementations
 * in In, NotIn, Between, NotBetween, Like, NotLike, Exists, and NotExists.
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
	// Scalar get_sql (trait default).
	// -------------------------------------------------------------------------

	/**
	 * Test that the default get_sql prepares a scalar string value.
	 *
	 * @since 3.0.0
	 */
	public function test_scalar_get_sql_prepares_string_value() {
		$sql = ( new Equal() )->get_sql( 'active' );
		$this->assertStringContainsString( 'active', $sql );
	}

	/**
	 * Test that the default get_sql trims leading and trailing whitespace.
	 *
	 * @since 3.0.0
	 */
	public function test_scalar_get_sql_trims_whitespace() {
		$trimmed = ( new Equal() )->get_sql( 'active' );
		$padded  = ( new Equal() )->get_sql( '  active  ' );
		$this->assertSame( $trimmed, $padded );
	}

	// -------------------------------------------------------------------------
	// In / NotIn.
	// -------------------------------------------------------------------------

	/**
	 * Test that In::get_sql produces a parenthesised list from an array.
	 *
	 * @since 3.0.0
	 */
	public function test_in_get_sql_with_array_produces_parenthesised_list() {
		$sql = ( new In() )->get_sql( array( 'active', 'inactive' ) );
		$this->assertStringStartsWith( '(', $sql );
		$this->assertStringEndsWith( ')', $sql );
		$this->assertStringContainsString( 'active', $sql );
		$this->assertStringContainsString( 'inactive', $sql );
	}

	/**
	 * Test that In::get_sql splits a comma-delimited string into values.
	 *
	 * @since 3.0.0
	 */
	public function test_in_get_sql_splits_delimited_string() {
		$from_array  = ( new In() )->get_sql( array( 'a', 'b', 'c' ) );
		$from_string = ( new In() )->get_sql( 'a, b, c' );
		$this->assertSame( $from_array, $from_string );
	}

	/**
	 * Test that NotIn::get_sql produces the same parenthesised fragment as In.
	 *
	 * @since 3.0.0
	 */
	public function test_not_in_get_sql_produces_same_fragment_as_in() {
		$in     = ( new In() )->get_sql( array( 'active', 'pending' ) );
		$not_in = ( new NotIn() )->get_sql( array( 'active', 'pending' ) );
		$this->assertSame( $in, $not_in );
	}

	// -------------------------------------------------------------------------
	// Between / NotBetween.
	// -------------------------------------------------------------------------

	/**
	 * Test that Between::get_sql produces a low AND high fragment from an array.
	 *
	 * @since 3.0.0
	 */
	public function test_between_get_sql_with_array_produces_and_fragment() {
		$sql = ( new Between() )->get_sql( array( 1, 10 ) );
		$this->assertStringContainsString( ' AND ', $sql );
		$this->assertStringContainsString( '1', $sql );
		$this->assertStringContainsString( '10', $sql );
	}

	/**
	 * Test that Between::get_sql splits a space-delimited string.
	 *
	 * @since 3.0.0
	 */
	public function test_between_get_sql_splits_delimited_string() {
		$from_array  = ( new Between() )->get_sql( array( 1, 10 ) );
		$from_string = ( new Between() )->get_sql( '1 10' );
		$this->assertSame( $from_array, $from_string );
	}

	/**
	 * Test that Between::get_sql only uses the first two values from the array.
	 *
	 * @since 3.0.0
	 */
	public function test_between_get_sql_uses_only_first_two_values() {
		$two   = ( new Between() )->get_sql( array( 1, 10 ) );
		$three = ( new Between() )->get_sql( array( 1, 10, 99 ) );
		$this->assertSame( $two, $three );
	}

	/**
	 * Test that NotBetween::get_sql produces the same fragment as Between.
	 *
	 * @since 3.0.0
	 */
	public function test_not_between_get_sql_produces_same_fragment_as_between() {
		$between     = ( new Between() )->get_sql( array( 1, 10 ) );
		$not_between = ( new NotBetween() )->get_sql( array( 1, 10 ) );
		$this->assertSame( $between, $not_between );
	}

	// -------------------------------------------------------------------------
	// Like / NotLike.
	// -------------------------------------------------------------------------

	/**
	 * Test that Like::get_sql wraps the value in % wildcards.
	 *
	 * @since 3.0.0
	 */
	public function test_like_get_sql_wraps_value_in_wildcards() {
		$sql = ( new Like() )->get_sql( 'hello' );
		$this->assertStringContainsString( '%hello%', $sql );
	}

	/**
	 * Test that Like::get_sql escapes LIKE special characters with esc_like.
	 *
	 * @since 3.0.0
	 */
	public function test_like_get_sql_escapes_like_special_chars() {
		$sql = ( new Like() )->get_sql( '50% off' );
		$this->assertStringContainsString( '\%', $sql );
	}

	/**
	 * Test that NotLike::get_sql produces the same fragment as Like.
	 *
	 * @since 3.0.0
	 */
	public function test_not_like_get_sql_produces_same_fragment_as_like() {
		$like     = ( new Like() )->get_sql( 'hello' );
		$not_like = ( new NotLike() )->get_sql( 'hello' );
		$this->assertSame( $like, $not_like );
	}

	// -------------------------------------------------------------------------
	// Exists / NotExists.
	// -------------------------------------------------------------------------

	/**
	 * Test that Exists::get_sql prepares a scalar value normally.
	 *
	 * @since 3.0.0
	 */
	public function test_exists_get_sql_prepares_value() {
		$sql = ( new Exists() )->get_sql( 'my_meta_key' );
		$this->assertStringContainsString( 'my_meta_key', $sql );
	}

	/**
	 * Test that NotExists::get_sql always returns an empty string.
	 *
	 * @since 3.0.0
	 */
	public function test_not_exists_get_sql_always_returns_empty_string() {
		$this->assertSame( '', ( new NotExists() )->get_sql( 'anything' ) );
		$this->assertSame( '', ( new NotExists() )->get_sql() );
	}
}
