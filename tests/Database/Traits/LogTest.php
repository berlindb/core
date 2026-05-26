<?php
/**
 * Log trait tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

/**
 * Test subject for Log trait behaviour.
 *
 * @since 3.0.0
 */
class LogTestSubject {

	use \BerlinDB\Database\Traits\Log;

	/**
	 * Entries passed through write_log().
	 *
	 * @since 3.0.0
	 * @var array<int, array<string, mixed>>
	 */
	public $written = array();

	/**
	 * Public wrapper around protected log().
	 *
	 * @since 3.0.0
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Log context.
	 */
	public function add_log( string $level = 'debug', string $message = '', array $context = array() ): void {
		$this->log( $level, $message, $context );
	}

	/**
	 * Capture entries that would be bridged to an external writer.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $entry Log entry.
	 */
	protected function write_log( array $entry ): void {
		$this->written[] = $entry;
	}
}

/**
 * Tests for the Log trait.
 *
 * @since 3.0.0
 */
class LogTest extends \PHPUnit\Framework\TestCase {

	/** @var LogTestSubject */
	protected $subject;

	/**
	 * Create a fresh Log trait test subject before each test.
	 *
	 * @since 3.0.0
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->subject = new LogTestSubject();
	}

	/**
	 * log() stores structured entries for programmatic inspection.
	 *
	 * @since 3.0.0
	 */
	public function test_log_stores_structured_entries() {
		$this->subject->add_log( 'Warning', ' Schema class missing. ', array( 'schema' => 'MissingSchema' ) );

		$logs = $this->subject->get_logs();

		$this->assertCount( 1, $logs );
		$this->assertSame( 'warning', $logs[0]['level'] );
		$this->assertSame( 'Schema class missing.', $logs[0]['message'] );
		$this->assertSame( array( 'schema' => 'MissingSchema' ), $logs[0]['context'] );
		$this->assertSame( LogTestSubject::class, $logs[0]['source'] );
		$this->assertIsFloat( $logs[0]['time'] );
	}

	/**
	 * get_logs() can filter entries by level.
	 *
	 * @since 3.0.0
	 */
	public function test_get_logs_filters_by_level() {
		$this->subject->add_log( 'debug', 'Debug message.' );
		$this->subject->add_log( 'error', 'Error message.' );

		$logs = $this->subject->get_logs( 'ERROR' );

		$this->assertCount( 1, $logs );
		$this->assertSame( 'error', $logs[0]['level'] );
		$this->assertSame( 'Error message.', $logs[0]['message'] );
	}

	/**
	 * clear_logs() can clear entries by level.
	 *
	 * @since 3.0.0
	 */
	public function test_clear_logs_filters_by_level() {
		$this->subject->add_log( 'debug', 'Debug message.' );
		$this->subject->add_log( 'warning', 'Warning message.' );
		$this->subject->add_log( 'error', 'Error message.' );

		$this->subject->clear_logs( 'warning' );

		$logs = $this->subject->get_logs();

		$this->assertCount( 2, $logs );
		$this->assertSame( array( 'debug', 'error' ), array_column( $logs, 'level' ) );
	}

	/**
	 * clear_logs() clears every entry by default.
	 *
	 * @since 3.0.0
	 */
	public function test_clear_logs_clears_all_by_default() {
		$this->subject->add_log( 'debug', 'Debug message.' );
		$this->subject->add_log( 'error', 'Error message.' );

		$this->subject->clear_logs();

		$this->assertSame( array(), $this->subject->get_logs() );
	}

	/**
	 * log() calls write_log() so subclasses can bridge to external writers.
	 *
	 * @since 3.0.0
	 */
	public function test_log_calls_write_log() {
		$this->subject->add_log( 'info', 'External writer message.' );

		$this->assertCount( 1, $this->subject->written );
		$this->assertSame( 'info', $this->subject->written[0]['level'] );
		$this->assertSame( 'External writer message.', $this->subject->written[0]['message'] );
	}

	/**
	 * log() ignores empty messages and levels.
	 *
	 * @since 3.0.0
	 */
	public function test_log_ignores_empty_messages_and_levels() {
		$this->subject->add_log( '', 'Missing level.' );
		$this->subject->add_log( 'debug', '' );

		$this->assertSame( array(), $this->subject->get_logs() );
		$this->assertSame( array(), $this->subject->written );
	}

	/**
	 * get_logs() returns an empty array when no entries have been recorded.
	 *
	 * @since 3.0.0
	 */
	public function test_get_logs_returns_empty_array_when_no_entries() {
		$this->assertSame( array(), $this->subject->get_logs() );
		$this->assertSame( array(), $this->subject->get_logs( 'debug' ) );
	}

	/**
	 * Entries are returned in insertion order.
	 *
	 * @since 3.0.0
	 */
	public function test_get_logs_preserves_insertion_order() {
		$this->subject->add_log( 'debug', 'First.' );
		$this->subject->add_log( 'debug', 'Second.' );
		$this->subject->add_log( 'debug', 'Third.' );

		$messages = array_column( $this->subject->get_logs(), 'message' );

		$this->assertSame( array( 'First.', 'Second.', 'Third.' ), $messages );
	}

	/**
	 * clear_logs() by level re-indexes the remaining entries.
	 *
	 * After removing a middle entry the keys must be sequential [0, 1], not [0, 2].
	 *
	 * @since 3.0.0
	 */
	public function test_clear_logs_reindexes_remaining_entries() {
		$this->subject->add_log( 'debug', 'Keep.' );
		$this->subject->add_log( 'warning', 'Remove.' );
		$this->subject->add_log( 'debug', 'Also keep.' );

		$this->subject->clear_logs( 'warning' );

		$logs = $this->subject->get_logs();

		$this->assertCount( 2, $logs );
		$this->assertSame( array( 0, 1 ), array_keys( $logs ) );
		$this->assertSame( 'Keep.', $logs[0]['message'] );
		$this->assertSame( 'Also keep.', $logs[1]['message'] );
	}
}
