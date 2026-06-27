<?php
/**
 * Generator trait tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Minimal test subject for the Generator trait.
 *
 * Provides its own apply_prefix() stub so the trait can be exercised
 * without pulling in the full Base trait composition.
 *
 * @since 3.1.0
 */
class GeneratorTestSubject {

	use \BerlinDB\Database\Traits\Generator;

	/** @var string */
	public $prefix = '';

	/**
	 * Mirrors the apply_prefix() contract from Base: prepend $prefix + '_'
	 * unless the value is already prefixed or $prefix is empty.
	 *
	 * @param string $value
	 * @param string $sep
	 * @return string
	 */
	protected function apply_prefix( $value = '', $sep = '_' ): string {
		$value = trim( (string) $value );

		if ( empty( $this->prefix ) ) {
			return $value;
		}

		$new_prefix = $this->prefix . $sep;

		if ( str_starts_with( $value, $new_prefix ) ) {
			return $value;
		}

		return $new_prefix . $value;
	}

	public function uuid(): string {
		return $this->generate_uuid();
	}

	public function random_string(): string {
		return $this->generate_random_string();
	}
}

/**
 * Generator subject that overrides the generate_random_int() seam with a fixed
 * value, to prove generate_uuid() draws its randomness through that override.
 *
 * @since 3.1.0
 */
class GeneratorSeamTestSubject extends GeneratorTestSubject {

	protected function generate_random_int( int $min, int $max ): int {
		return 0;
	}
}

/**
 * Tests for the Generator trait.
 *
 * @since 3.1.0
 */
class GeneratorTest extends TestCase {

	/** Matches a URN UUID v4 string. */
	private const UUID_PATTERN = '/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/**
	 * Return a fresh test subject, optionally with a prefix set.
	 *
	 * @since 3.1.0
	 *
	 * @param string $prefix
	 * @return GeneratorTestSubject
	 */
	private function make_subject( string $prefix = '' ): GeneratorTestSubject {
		$subject         = new GeneratorTestSubject();
		$subject->prefix = $prefix;
		return $subject;
	}

	// -------------------------------------------------------------------------
	// generate_uuid.
	// -------------------------------------------------------------------------

	/**
	 * generate_uuid() returns a string with the urn:uuid: prefix.
	 *
	 * @since 3.1.0
	 */
	public function test_generate_uuid_has_urn_prefix(): void {
		$this->assertStringStartsWith( 'urn:uuid:', $this->make_subject()->uuid() );
	}

	/**
	 * generate_uuid() returns a well-formed v4 UUID.
	 *
	 * The version nibble must be 4 and the variant nibble must be 8, 9, a, or b.
	 *
	 * @since 3.1.0
	 */
	public function test_generate_uuid_matches_v4_format(): void {
		$this->assertMatchesRegularExpression( self::UUID_PATTERN, $this->make_subject()->uuid() );
	}

	/**
	 * generate_uuid() produces a different value on each call.
	 *
	 * @since 3.1.0
	 */
	public function test_generate_uuid_is_unique_across_calls(): void {
		$subject = $this->make_subject();
		$values  = array_map( fn() => $subject->uuid(), range( 1, 5 ) );
		$this->assertSame( count( $values ), count( array_unique( $values ) ) );
	}

	/**
	 * generate_uuid() draws its randomness from the overridable
	 * generate_random_int() seam: a subclass that fixes it yields a deterministic
	 * (still well-formed) v4 UUID - the override point that replaces wp_rand()'s
	 * pluggability.
	 *
	 * @since 3.1.0
	 */
	public function test_generate_uuid_routes_through_generate_random_int_seam(): void {
		$uuid = ( new GeneratorSeamTestSubject() )->uuid();

		// The version (4) and variant (8) masks still apply over the fixed zeros.
		$this->assertSame( 'urn:uuid:00000000-0000-4000-8000-000000000000', $uuid );
		$this->assertMatchesRegularExpression( self::UUID_PATTERN, $uuid );
	}

	// -------------------------------------------------------------------------
	// generate_random_string.
	// -------------------------------------------------------------------------

	/**
	 * generate_random_string() returns a non-empty string.
	 *
	 * @since 3.1.0
	 */
	public function test_generate_random_string_is_non_empty(): void {
		$this->assertNotEmpty( $this->make_subject()->random_string() );
	}

	/**
	 * The value is a plain, unprefixed random hex string (an opaque sentinel).
	 *
	 * @since 3.1.0
	 */
	public function test_generate_random_string_is_unprefixed_hex(): void {
		$value = $this->make_subject( 'test' )->random_string();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]+$/', $value );
		$this->assertStringStartsNotWith( 'test_', $value );
	}

	/**
	 * generate_random_string() produces a different value on each call.
	 *
	 * @since 3.1.0
	 */
	public function test_generate_random_string_is_unique_across_calls(): void {
		$subject = $this->make_subject();
		$values  = array_map( fn() => $subject->random_string(), range( 1, 5 ) );
		$this->assertSame( count( $values ), count( array_unique( $values ) ) );
	}
}
