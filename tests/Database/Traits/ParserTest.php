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

	/**
	 * Expose the protected sanitize_query() for tests.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string|int, mixed> $queries      Query clauses.
	 * @param array<string|int, mixed> $parent_query Parent query.
	 * @return array<string|int, mixed>
	 */
	public function expose_sanitize_query( $queries = array(), $parent_query = array() ) {
		return $this->sanitize_query( $queries, $parent_query );
	}
}

/**
 * Tests for the shared Parser trait.
 *
 * @since 3.0.0
 */
class ParserTest extends TestCase {

	/**
	 * sanitize_query() removes invalid numeric children and tracks OR relations.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_query_removes_invalid_numeric_children_and_tracks_or_relation() {
		$parser = new ParserTestSubject();
		$parser->expose_set_first_keys( array( 'key', 'value' ) );

		$result = $parser->expose_sanitize_query(
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
}
