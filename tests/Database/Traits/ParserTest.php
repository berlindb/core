<?php
/**
 * Parser trait tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Parsers\Base as ParserBase;
use PHPUnit\Framework\TestCase;

/**
 * Test subject for shared Parser trait behaviour.
 *
 * @since 3.0.0
 */
class ParserTestSubject extends ParserBase {

	/**
	 * Expose the protected OR-relation flag.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public function has_or_relation(): bool {
		return $this->has_or_relation;
	}

	/**
	 * Configure first-order keys for tests.
	 *
	 * @since 3.0.0
	 *
	 * @param list<string> $keys First-order keys.
	 */
	public function expose_set_first_keys( array $keys ): void {
		$this->set_first_keys( $keys );
	}
}

/**
 * Tests for the shared Parser trait.
 *
 * @since 3.0.0
 */
class ParserTest extends TestCase {

	/**
	 * get_cast_for_type() accepts supported MySQL cast targets.
	 *
	 * @since 3.0.0
	 *
	 * @dataProvider valid_cast_type_provider
	 *
	 * @param string $type     Input cast type.
	 * @param string $expected Expected normalized cast type.
	 */
	public function test_get_cast_for_type_accepts_supported_types( string $type, string $expected ) {
		$parser = new ParserTestSubject();

		$this->assertSame( $expected, $parser->get_cast_for_type( $type ) );
	}

	/**
	 * get_cast_for_type() falls back to CHAR for empty or unsupported types.
	 *
	 * @since 3.0.0
	 *
	 * @dataProvider invalid_cast_type_provider
	 *
	 * @param string $type Unsupported cast type.
	 */
	public function test_get_cast_for_type_falls_back_to_char( string $type ) {
		$parser = new ParserTestSubject();

		$this->assertSame( 'CHAR', $parser->get_cast_for_type( $type ) );
	}

	/**
	 * sanitize_query() removes invalid numeric children and tracks OR relations.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_query_removes_invalid_numeric_children_and_tracks_or_relation() {
		$parser = new ParserTestSubject();
		$parser->expose_set_first_keys( array( 'key', 'value' ) );

		$result = $parser->sanitize_query(
			array(
				'relation' => 'OR',
				'ignored',
				array(
					'key'   => 'status',
					'value' => 'active',
				),
			)
		);

		$this->assertArrayNotHasKey( 0, $result );
		$this->assertSame( 'OR', $result['relation'] );
		$this->assertTrue( $parser->has_or_relation() );
		$this->assertSame( 'status', $result[1]['key'] );
		$this->assertSame( 'active', $result[1]['value'] );
	}

	/**
	 * Valid cast type provider.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, array{string, string}>
	 */
	public function valid_cast_type_provider(): array {
		return array(
			'binary'            => array( 'binary', 'BINARY' ),
			'char'              => array( 'char', 'CHAR' ),
			'date'              => array( 'date', 'DATE' ),
			'datetime'          => array( 'datetime', 'DATETIME' ),
			'signed'            => array( 'signed', 'SIGNED' ),
			'unsigned'          => array( 'unsigned', 'UNSIGNED' ),
			'time'              => array( 'time', 'TIME' ),
			'numeric alias'     => array( 'numeric', 'SIGNED' ),
			'numeric precision' => array( 'numeric(10, 2)', 'NUMERIC(10, 2)' ),
			'decimal precision' => array( 'decimal(10,2)', 'DECIMAL(10,2)' ),
		);
	}

	/**
	 * Invalid cast type provider.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_cast_type_provider(): array {
		return array(
			'empty'        => array( '' ),
			'varchar'      => array( 'varchar' ),
			'sql fragment' => array( 'SIGNED) UNSIGNED' ),
		);
	}
}
