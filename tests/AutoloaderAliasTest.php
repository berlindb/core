<?php
/**
 * Legacy class-alias autoloader tests.
 *
 * The autoloader maps the pre-3.0 class names (BerlinDB\Database\Column, \Index,
 * \Query, \Row, \Schema, \Table) to their Kern\* implementations. The alias is
 * registered eagerly when the Kern target loads - not lazily on the alias name -
 * because PHP's instanceof does not trigger autoloading, so a type check against a
 * legacy name would otherwise resolve to false until the name was loaded some other
 * way (issue #223).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the legacy class-name aliases.
 *
 * @since 3.1.0
 */
class AutoloaderAliasTest extends TestCase {

	/**
	 * Legacy alias => Kern target pairs.
	 *
	 * @since 3.1.0
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function provide_legacy_aliases(): array {
		return array(
			array( 'BerlinDB\\Database\\Column', 'BerlinDB\\Database\\Kern\\Column' ),
			array( 'BerlinDB\\Database\\Index', 'BerlinDB\\Database\\Kern\\Index' ),
			array( 'BerlinDB\\Database\\Query', 'BerlinDB\\Database\\Kern\\Query' ),
			array( 'BerlinDB\\Database\\Row', 'BerlinDB\\Database\\Kern\\Row' ),
			array( 'BerlinDB\\Database\\Schema', 'BerlinDB\\Database\\Kern\\Schema' ),
			array( 'BerlinDB\\Database\\Table', 'BerlinDB\\Database\\Kern\\Table' ),
		);
	}

	/**
	 * Loading a Kern class registers its legacy alias, pointing at the same class.
	 *
	 * @since 3.1.0
	 *
	 * @dataProvider provide_legacy_aliases
	 *
	 * @param string $alias  The legacy class name.
	 * @param string $target The Kern class it must resolve to.
	 */
	public function test_legacy_alias_resolves_to_kern_class( string $alias, string $target ): void {

		// Loading the Kern target eagerly registers the alias (no autoload of the name).
		$this->assertTrue( class_exists( $target ), "Kern class {$target} should exist." );
		$this->assertTrue( class_exists( $alias, false ), "Legacy alias {$alias} should resolve without re-autoloading." );

		// The alias and the target must be the very same class.
		$this->assertSame(
			$target,
			( new \ReflectionClass( $alias ) )->getName(),
			"{$alias} must alias {$target}."
		);
	}
}
