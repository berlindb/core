<?php
/**
 * Configuration log-survival regression test.
 *
 * A diagnostic emitted by a config sanitizer during validate_args() must survive
 * construction. configure() merges over the property snapshot and calls
 * set_vars(); excluding the reserved construction-machinery vars (the log store
 * among them) from that merge is what keeps set_vars() from resetting the log to
 * its empty snapshot. Before that fix, any such log entry was silently discarded.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Subject whose config sanitizer logs a warning while configuring.
 *
 * @since 3.1.0
 */
class ConfigLogSubject {

	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot;

	/**
	 * A configurable property.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	public $name = '';

	/**
	 * Declare 'name', sanitized by a callback that also logs.
	 *
	 * @since 3.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function get_config_callbacks(): array {
		return array( 'name' => array( $this, 'sanitize_and_log' ) );
	}

	/**
	 * Emit a diagnostic during validate_args(), then pass the value through.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value The incoming value.
	 * @return mixed
	 */
	protected function sanitize_and_log( $value ) {
		$this->log( 'warning', 'sanitizer_diagnostic', 'logged during sanitization' );

		return $value;
	}
}

/**
 * Tests that logs emitted during configuration survive set_vars().
 *
 * @since 3.1.0
 */
class ConfigurationLogSurvivalTest extends TestCase {

	/**
	 * A warning logged by a config sanitizer is present after construction.
	 *
	 * @since 3.1.0
	 */
	public function test_sanitizer_log_survives_construction() {
		$subject = new ConfigLogSubject( array( 'name' => 'berlin' ) );

		// The value was still configured normally.
		$this->assertSame( 'berlin', $subject->name );

		// And the diagnostic emitted mid-configuration was not wiped.
		$logs = $subject->get_logs( array( 'code' => 'sanitizer_diagnostic' ) );

		$this->assertNotEmpty( $logs );
		$this->assertSame( 'warning', $logs[0]['level'] );
	}
}
