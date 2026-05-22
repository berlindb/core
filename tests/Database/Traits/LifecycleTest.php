<?php
/**
 * Lifecycle trait tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

/**
 * Test double that exposes Lifecycle internals for assertion.
 *
 * Overrides start() and finish() to append entries to a public $log array so
 * tests can assert call order and whether finish() fired despite an exception.
 *
 * @since 3.0.0
 */
class LifecycleTestDouble {

	use \BerlinDB\Database\Traits\Lifecycle;

	/** @var string[] Ordered call log — entries are 'start' or 'finish'. */
	public $log = array();

	protected function start() {
		$this->log[] = 'start';
	}

	protected function finish() {
		$this->log[] = 'finish';
	}

	/**
	 * Public entry point for run() so tests can invoke it directly.
	 *
	 * @since 3.0.0
	 *
	 * @param callable $action
	 * @return mixed
	 */
	public function execute( callable $action ) {
		return $this->run( $action );
	}
}

/**
 * Tests for the Lifecycle trait.
 *
 * @since 3.0.0
 */
class LifecycleTest extends \PHPUnit\Framework\TestCase {

	/** @var LifecycleTestDouble */
	protected $subject;

	protected function setUp(): void {
		parent::setUp();
		$this->subject = new LifecycleTestDouble();
	}

	// ========================================================================
	// run() tests.
	// ========================================================================

	/**
	 * run() calls start() before the action and finish() after.
	 *
	 * @since 3.0.0
	 */
	public function test_run_calls_start_before_action_and_finish_after() {
		$log_mid_action = array();

		$this->subject->execute( function() use ( &$log_mid_action ) {
			$log_mid_action = $this->subject->log;
		} );

		// start() must have fired before the action body ran.
		$this->assertSame( array( 'start' ), $log_mid_action );

		// finish() must have fired after the action returned.
		$this->assertSame( array( 'start', 'finish' ), $this->subject->log );
	}

	/**
	 * run() passes the action's return value through to the caller.
	 *
	 * @since 3.0.0
	 */
	public function test_run_returns_action_return_value() {
		$result = $this->subject->execute( function() {
			return 'expected';
		} );

		$this->assertSame( 'expected', $result );
	}

	/**
	 * run() calls finish() even when the action throws an exception.
	 *
	 * This is the key contract: finish() is guaranteed via a try/finally block,
	 * so cleanup always runs regardless of whether the action succeeds.
	 *
	 * @since 3.0.0
	 */
	public function test_run_calls_finish_even_when_action_throws() {
		try {
			$this->subject->execute( function() {
				throw new \RuntimeException( 'boom' );
			} );
		} catch ( \RuntimeException $e ) {
			// Expected — we only care that finish() still fired.
		}

		$this->assertContains( 'finish', $this->subject->log );
	}
}
