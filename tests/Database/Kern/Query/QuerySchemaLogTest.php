<?php
/**
 * Query schema logging tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Schema-like class missing the Query schema API.
 *
 * @since 3.0.0
 */
class QuerySchemaLogInvalidSchema {}

/**
 * Tests for Query schema setup logging.
 *
 * @since 3.0.0
 */
class QuerySchemaLogTest extends TestCase {

	/**
	 * Query logs when the configured schema class is empty.
	 *
	 * @since 3.0.0
	 */
	public function test_query_logs_empty_schema_class() {
		$query = new class() extends Query {
			protected $table_schema = '';
		};

		$logs = $query->get_logs( array( 'code' => 'query_schema_unavailable' ) );

		$this->assertCount( 1, $logs );
		$this->assertSame( 'error', $logs[0]['level'] );
	}

	/**
	 * Query logs when the configured schema class does not exist.
	 *
	 * @since 3.0.0
	 */
	public function test_query_logs_missing_schema_class() {
		$query = new class() extends Query {
			protected $table_schema = 'BerlinDB\\Tests\\MissingQuerySchema';
		};

		$logs = $query->get_logs( array( 'code' => 'query_schema_unavailable' ) );

		$this->assertCount( 1, $logs );
		$this->assertSame( 'BerlinDB\\Tests\\MissingQuerySchema', $logs[0]['context']['schema'] );
	}

	/**
	 * Query logs when the configured schema class does not expose get_columns().
	 *
	 * @since 3.0.0
	 */
	public function test_query_logs_unusable_schema_class() {
		$query = new class() extends Query {
			protected $table_schema = QuerySchemaLogInvalidSchema::class;
		};

		$logs = $query->get_logs( array( 'code' => 'query_schema_unavailable' ) );

		$this->assertCount( 1, $logs );
		$this->assertSame( QuerySchemaLogInvalidSchema::class, $logs[0]['context']['schema'] );
		$this->assertSame( 'get_columns', $logs[0]['context']['method'] );
	}
}
