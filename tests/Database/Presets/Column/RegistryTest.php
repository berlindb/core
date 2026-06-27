<?php
/**
 * Column Preset Registry tests (#201).
 *
 * The Registry resolves preset keys to instances: built-ins (id/primary/serial/
 * uuid/created/modified/version) are memoized, a registered preset of the same key
 * overrides its built-in, and reset() drops registrations. DB-free.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Presets\Column\Base;
use BerlinDB\Database\Presets\Column\Registry;
use BerlinDB\Database\Presets\Column\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BerlinDB\Database\Presets\Column\Registry.
 *
 * @since 3.1.0
 */
class RegistryTest extends TestCase {

	/**
	 * Drop any registrations between tests; the registry is static.
	 *
	 * @since 3.1.0
	 */
	protected function tearDown(): void {
		Registry::reset();

		parent::tearDown();
	}

	/**
	 * Test that get() resolves a built-in preset by key.
	 *
	 * @since 3.1.0
	 */
	public function test_get_resolves_builtin_by_key() {
		$this->assertInstanceOf( Uuid::class, Registry::get( 'uuid' ) );
	}

	/**
	 * Test that get() returns null for an unknown key.
	 *
	 * @since 3.1.0
	 */
	public function test_get_returns_null_for_unknown_key() {
		$this->assertNull( Registry::get( 'nope' ) );
	}

	/**
	 * Test that all() includes every built-in, keyed by key().
	 *
	 * @since 3.1.0
	 */
	public function test_all_includes_builtins() {
		$keys = array_keys( Registry::all() );

		foreach ( array( 'id', 'primary', 'serial', 'uuid', 'created', 'modified', 'version' ) as $key ) {
			$this->assertContains( $key, $keys );
		}
	}

	/**
	 * Test that a registered preset overrides the built-in of the same key.
	 *
	 * @since 3.1.0
	 */
	public function test_register_overrides_builtin() {
		$custom = $this->custom_uuid_preset();

		Registry::register( $custom );

		$this->assertSame( $custom, Registry::get( 'uuid' ) );
	}

	/**
	 * Test that reset() restores the built-in after an override.
	 *
	 * @since 3.1.0
	 */
	public function test_reset_restores_builtin() {
		Registry::register( $this->custom_uuid_preset() );
		Registry::reset();

		$this->assertInstanceOf( Uuid::class, Registry::get( 'uuid' ) );
	}

	/**
	 * A throwaway preset that claims the 'uuid' key, for override tests.
	 *
	 * @since 3.1.0
	 * @return Base
	 */
	private function custom_uuid_preset(): Base {
		return new class() extends Base {
			public function key(): string {
				return 'uuid';
			}
		};
	}
}
