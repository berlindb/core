<?php
/**
 * Table schema logging tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Schema-like class missing the Table schema API.
 *
 * @since 3.0.0
 */
class TableSchemaLogInvalidSchema {}

/**
 * Tests for Table schema setup logging.
 *
 * @since 3.0.0
 */
class TableSchemaLogTest extends TestCase {

	/**
	 * Table logs when the configured schema class is empty.
	 *
	 * @since 3.0.0
	 */
	public function test_table_logs_empty_schema_class() {
		$table = new class() extends Table {
			protected $name    = 'schema_log_empty';
			protected $version = '1';
			protected $schema  = '';

			protected function is_testing() {
				return false;
			}
		};

		$logs = $table->get_logs( array( 'code' => 'table_schema_unavailable' ) );

		$this->assertCount( 1, $logs );
		$this->assertSame( 'error', $logs[0]['level'] );
	}

	/**
	 * Table logs when the configured schema class does not exist.
	 *
	 * @since 3.0.0
	 */
	public function test_table_logs_missing_schema_class() {
		$table = new class() extends Table {
			protected $name    = 'schema_log_missing';
			protected $version = '1';
			protected $schema  = 'BerlinDB\\Tests\\MissingTableSchema';

			protected function is_testing() {
				return false;
			}
		};

		$logs = $table->get_logs( array( 'code' => 'table_schema_unavailable' ) );

		$this->assertCount( 1, $logs );
		$this->assertSame( 'BerlinDB\\Tests\\MissingTableSchema', $logs[0]['context']['schema'] );
	}

	/**
	 * Table logs when the configured schema class does not expose get_create_table_string().
	 *
	 * @since 3.0.0
	 */
	public function test_table_logs_unusable_schema_class() {
		$table = new class() extends Table {
			protected $name    = 'schema_log_invalid';
			protected $version = '1';
			protected $schema  = TableSchemaLogInvalidSchema::class;

			protected function is_testing() {
				return false;
			}
		};

		$logs = $table->get_logs( array( 'code' => 'table_schema_unavailable' ) );

		$this->assertCount( 1, $logs );
		$this->assertSame( TableSchemaLogInvalidSchema::class, $logs[0]['context']['schema'] );
		$this->assertSame( 'get_create_table_string', $logs[0]['context']['method'] );
	}
}
