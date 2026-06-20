<?php
/**
 * Magic trait tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

/**
 * Test subject for Magic trait behaviour.
 *
 * - $prop_with_getter: protected property that also has a get_*() method,
 *   so the getter should win over raw property access.
 * - $prop_without_getter: protected property with no getter, so raw value
 *   is returned directly.
 * - get_virtual(): a getter with no backing property - a virtual property.
 *
 * Note: public properties bypass PHP's magic methods entirely and are never
 * routed through __get() or __isset(), regardless of this trait.
 *
 * @since 3.0.0
 */
class MagicTestSubject {

	use \BerlinDB\Database\Traits\Magic;

	/** @var string Protected property that has a corresponding getter. */
	protected $prop_with_getter = 'raw_value';

	/** @var string Protected property with no getter. */
	protected $prop_without_getter = 'direct_value';

	/**
	 * Getter that shadows $prop_with_getter externally.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	protected function get_prop_with_getter() {
		return 'getter_value';
	}

	/**
	 * Virtual property - no backing $virtual property exists.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	protected function get_virtual() {
		return 'virtual_value';
	}
}

/**
 * Sibling subject used to document PHP protected-property access rules.
 *
 * @since 3.0.0
 */
class MagicSiblingReader extends MagicTestSubject {

	/**
	 * Read a protected property from another same-family object.
	 *
	 * PHP allows this direct access, so __get() is not invoked and the raw
	 * property value is returned.
	 *
	 * @since 3.0.0
	 *
	 * @param MagicTestSubject $subject Subject to read from.
	 * @return string
	 */
	public function read_prop_with_getter( MagicTestSubject $subject ) {
		return $subject->prop_with_getter;
	}
}

/**
 * Tests for the Magic trait.
 *
 * @since 3.0.0
 */
class MagicTest extends \PHPUnit\Framework\TestCase {

	/** @var MagicTestSubject */
	protected $subject;

	/**
	 * Create a fresh Magic trait test subject before each test.
	 *
	 * @since 3.0.0
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->subject = new MagicTestSubject();
	}

	// ========================================================================
	// __get() tests.
	// ========================================================================

	/**
	 * __get() calls get_{$key}() when a getter exists, even if a same-named
	 * property also exists - the getter takes priority.
	 *
	 * @since 3.0.0
	 */
	public function test_get_prefers_getter_over_property() {
		$this->assertSame( 'getter_value', $this->subject->prop_with_getter );
	}

	/**
	 * Same-family protected property access bypasses __get().
	 *
	 * This preserves PHP's native protected-property behaviour: a subclass can
	 * read a protected property declared on an ancestor from another object in
	 * that inheritance family, so the raw property value is returned.
	 *
	 * @since 3.0.0
	 */
	public function test_same_family_protected_access_bypasses_getter() {
		$reader = new MagicSiblingReader();

		$this->assertSame( 'raw_value', $reader->read_prop_with_getter( $this->subject ) );
	}

	/**
	 * __get() returns the property value directly when no getter exists.
	 *
	 * @since 3.0.0
	 */
	public function test_get_returns_property_when_no_getter() {
		$this->assertSame( 'direct_value', $this->subject->prop_without_getter );
	}

	/**
	 * __get() supports virtual properties - keys with a getter but no backing
	 * property still return the getter's value.
	 *
	 * @since 3.0.0
	 */
	public function test_get_returns_virtual_property_via_getter() {
		$this->assertSame( 'virtual_value', $this->subject->virtual );
	}

	/**
	 * __get() returns null for a key that has neither a getter nor a property.
	 *
	 * @since 3.0.0
	 */
	public function test_get_returns_null_for_unknown_key() {
		$this->assertNull( $this->subject->nonexistent );
	}

	// ========================================================================
	// __isset() tests.
	// ========================================================================

	/**
	 * __isset() returns true when a get_{$key}() method exists, even with no
	 * backing property - virtual properties appear to be set.
	 *
	 * @since 3.0.0
	 */
	public function test_isset_returns_true_for_virtual_property() {
		$this->assertTrue( isset( $this->subject->virtual ) );
	}

	/**
	 * __isset() returns true for a protected property that has no getter.
	 *
	 * @since 3.0.0
	 */
	public function test_isset_returns_true_for_existing_property() {
		$this->assertTrue( isset( $this->subject->prop_without_getter ) );
	}

	/**
	 * __isset() returns false for a key that has neither a getter nor a property.
	 *
	 * @since 3.0.0
	 */
	public function test_isset_returns_false_for_unknown_key() {
		$this->assertFalse( isset( $this->subject->nonexistent ) );
	}
}
