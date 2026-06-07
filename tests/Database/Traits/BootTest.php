<?php
/**
 * Boot trait tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test subject for Boot trait behaviour.
 *
 * @since 3.0.0
 */
class BootTestSubject {

	use \BerlinDB\Database\Traits\Base {
		set_vars as protected base_set_vars;
	}
	use \BerlinDB\Database\Traits\Boot {
		__construct as protected boot_construct;
		configure as protected boot_configure;
	}

	/**
	 * Event names recorded during construction.
	 *
	 * @since 3.0.0
	 * @var list<string>
	 */
	public $events = array();

	/**
	 * Public property used to verify set_vars().
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $name = 'default';

	/**
	 * Public property used to verify special_args().
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $special = '';

	/**
	 * Public property used to verify validate_args().
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $validated = '';

	/**
	 * Static call log that is not affected by set_vars() restoring object state.
	 *
	 * @since 3.0.0
	 * @var list<string>
	 */
	public static $calls = array();

	/**
	 * Pass constructor arguments through the trait constructor explicitly.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed>|object $args Constructor arguments.
	 */
	public function __construct( $args = array() ) {
		$this->boot_construct( $args );
	}

	/**
	 * Expose stashed constructor state.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_stashed_args(): array {
		return $this->args;
	}

	/**
	 * Record configure() and defer to the Boot trait pipeline.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $args Construction arguments.
	 * @return array<string, mixed>
	 */
	protected function configure( array $args = array() ): array {
		$this->events[] = 'configure';

		return $this->boot_configure( $args );
	}

	/**
	 * Expose configure() so tests can attempt to re-configure after boot.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $args Construction arguments.
	 * @return array<string, mixed>
	 */
	public function expose_configure( array $args = array() ): array {
		return $this->configure( $args );
	}

	/**
	 * Expose boot() so tests can attempt to re-boot after construction.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $args Construction arguments.
	 */
	public function expose_boot( array $args = array() ): void {
		$this->boot( $args );
	}

	/**
	 * Record sunrise().
	 *
	 * @since 3.0.0
	 */
	protected function sunrise(): void {
		$this->events[] = 'sunrise';
	}

	/**
	 * Record special_args() and add a derived value.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $args Parsed arguments.
	 * @return array<string, mixed>
	 */
	protected function special_args( $args = array() ) {
		self::$calls[]   = 'special_args';
		$args['special'] = 'specialized';

		return $args;
	}

	/**
	 * Record set_vars() and defer to the Base trait implementation.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $args Parsed arguments.
	 */
	protected function set_vars( $args = array() ): void {
		self::$calls[] = 'set_vars';

		$this->base_set_vars( $args );
	}

	/**
	 * Record validate_args() and add a derived value.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $args Parsed arguments.
	 * @return array<string, mixed>
	 */
	protected function validate_args( $args = array() ) {
		self::$calls[]     = 'validate_args';
		$args['validated'] = 'yes';

		return $args;
	}

	/**
	 * Record init().
	 *
	 * @since 3.0.0
	 */
	protected function init(): void {
		$this->events[] = 'init';
	}

	/**
	 * Record finish().
	 *
	 * @since 3.0.0
	 */
	protected function finish(): void {
		$this->events[] = 'finish';
	}
}

/**
 * Test subject whose args are never a definition (mirrors Query's query-var path).
 *
 * @since 3.1.0
 */
class BootTestUnconfiguredSubject {

	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot {
		__construct as protected boot_construct;
	}

	/**
	 * Public property used to confirm config is NOT applied.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	public $name = 'default';

	/**
	 * @param array<string, mixed>|object $args Construction arguments.
	 */
	public function __construct( $args = array() ) {
		$this->boot_construct( $args );
	}

	/**
	 * These args are never configuration.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $args Construction arguments.
	 * @return bool
	 */
	protected function is_configuration( array $args ): bool {
		return false;
	}
}

/**
 * Tests for the Boot trait.
 *
 * @since 3.0.0
 */
class BootTest extends TestCase {

	/**
	 * Empty arguments run the lifecycle without configuring any properties.
	 *
	 * @since 3.0.0
	 */
	public function test_empty_arguments_run_lifecycle_without_configuring() {
		$subject = new BootTestSubject();

		$this->assertSame(
			array(
				'configure',
				'sunrise',
				'init',
				'finish',
			),
			$subject->events
		);
		$this->assertSame( 'default', $subject->name );
	}

	/**
	 * configure() processes non-empty args: special_args, set_vars, validate_args.
	 *
	 * @since 3.0.0
	 */
	public function test_configure_processes_non_empty_arguments() {
		BootTestSubject::$calls = array();

		$subject = new BootTestSubject( array( 'name' => 'Berlin' ) );

		$this->assertSame(
			array(
				'special_args',
				'set_vars',
				'validate_args',
				'set_vars',
			),
			BootTestSubject::$calls
		);
		$this->assertSame( 'Berlin', $subject->name );
		$this->assertSame( 'specialized', $subject->special );
		$this->assertSame( 'yes', $subject->validated );
		$this->assertSame( array( 'name' => 'Berlin' ), $subject->get_stashed_args()['param'] );
	}

	/**
	 * configure() runs before sunrise(), so derived state sees configured props.
	 *
	 * @since 3.1.0
	 */
	public function test_configure_runs_before_sunrise() {
		$subject = new BootTestSubject( array( 'name' => 'Berlin' ) );

		$configure_at = array_search( 'configure', $subject->events, true );
		$sunrise_at   = array_search( 'sunrise', $subject->events, true );

		$this->assertNotFalse( $configure_at );
		$this->assertNotFalse( $sunrise_at );
		$this->assertLessThan( $sunrise_at, $configure_at );
	}

	/**
	 * Construction completes with the object sealed (is_booted() is true).
	 *
	 * @since 3.1.0
	 */
	public function test_is_booted_true_after_construction() {
		$subject = new BootTestSubject();

		$this->assertTrue( $subject->is_booted() );
	}

	/**
	 * configure() is define-once: once booted, a re-configure does not re-assign
	 * properties (it returns the args unconsumed instead).
	 *
	 * @since 3.1.0
	 */
	public function test_configure_is_define_once_after_boot() {
		$subject = new BootTestSubject( array( 'name' => 'Configured' ) );
		$this->assertSame( 'Configured', $subject->name );

		// A post-boot re-definition must be ignored (and returned unconsumed).
		$remaining = $subject->expose_configure( array( 'name' => 'Changed' ) );

		$this->assertSame( 'Configured', $subject->name );
		$this->assertSame( array( 'name' => 'Changed' ), $remaining );
	}

	/**
	 * boot() is define-once: a second boot is a no-op for the whole lifecycle,
	 * not just configure().
	 *
	 * @since 3.1.0
	 */
	public function test_boot_is_define_once() {
		$subject = new BootTestSubject();
		$events  = $subject->events;

		// A second boot must not re-run any lifecycle step.
		$subject->expose_boot( array( 'name' => 'Again' ) );

		$this->assertSame( $events, $subject->events );
		$this->assertSame( 'default', $subject->name );
		$this->assertTrue( $subject->is_booted() );
	}

	/**
	 * is_configured() is true once a definition has been applied.
	 *
	 * @since 3.1.0
	 */
	public function test_is_configured_after_definition_applied() {
		$subject = new BootTestSubject( array( 'name' => 'Berlin' ) );

		$this->assertTrue( $subject->is_configured() );
	}

	/**
	 * is_configured() stays false when construct args are not a definition
	 * (the query-var path), and no config is applied — yet the object still boots.
	 *
	 * @since 3.1.0
	 */
	public function test_is_not_configured_when_args_are_not_a_definition() {
		$subject = new BootTestUnconfiguredSubject( array( 'name' => 'Berlin' ) );

		$this->assertFalse( $subject->is_configured() );
		$this->assertTrue( $subject->is_booted() );
		$this->assertSame( 'default', $subject->name );
	}
}
