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
	 * Record setup().
	 *
	 * @since 3.1.0
	 */
	protected function setup(): void {
		$this->events[] = 'setup';
	}

	/**
	 * Record sunset().
	 *
	 * @since 3.1.0
	 */
	protected function sunset(): void {
		$this->events[] = 'sunset';
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
 * Test subject that opts OUT of strict config (mirrors Row's dynamic columns):
 * unknown keys pass through instead of being rejected.
 *
 * @since 3.1.0
 */
#[\AllowDynamicProperties]
class BootTestLooseSubject {

	use \BerlinDB\Database\Traits\Base;
	use \BerlinDB\Database\Traits\Boot {
		__construct as protected boot_construct;
	}

	/**
	 * A real, known config property.
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
	 * Opt out of strict configuration.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	protected function is_strict_config(): bool {
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
				'sunrise',
				'configure',
				'setup',
				'init',
				'sunset',
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
	 * sunrise() runs before configure(), and configure() runs before setup() —
	 * so setup() (the derived-state hook) sees the configured props.
	 *
	 * @since 3.1.0
	 */
	public function test_configure_runs_before_setup() {
		$subject = new BootTestSubject( array( 'name' => 'Berlin' ) );

		$sunrise_at   = array_search( 'sunrise', $subject->events, true );
		$configure_at = array_search( 'configure', $subject->events, true );
		$setup_at     = array_search( 'setup', $subject->events, true );

		$this->assertNotFalse( $sunrise_at );
		$this->assertNotFalse( $configure_at );
		$this->assertNotFalse( $setup_at );
		$this->assertLessThan( $configure_at, $sunrise_at );
		$this->assertLessThan( $setup_at, $configure_at );
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
	 * is_configured() is true even when the definition comes from the class's own
	 * property defaults (the not-a-definition / query-var path): the definition is
	 * settled, and the construct args are NOT applied as properties.
	 *
	 * @since 3.1.0
	 */
	public function test_is_configured_when_definition_comes_from_class() {
		$subject = new BootTestUnconfiguredSubject( array( 'name' => 'Berlin' ) );

		$this->assertTrue( $subject->is_configured() );
		$this->assertTrue( $subject->is_booted() );
		$this->assertSame( 'default', $subject->name );
	}

	/**
	 * Strict config is the default: a key that is neither a property nor a
	 * declared config key is dropped (no junk property) and logged loudly, while
	 * the recognized key is applied.
	 *
	 * @since 3.1.0
	 */
	public function test_strict_config_is_the_default() {
		$subject = new BootTestSubject(
			array(
				'name'  => 'Strict',
				'bogus' => 'nope',
			)
		);

		// Known arg applied; unknown arg never became a property.
		$this->assertSame( 'Strict', $subject->name );
		$this->assertFalse( property_exists( $subject, 'bogus' ) );

		// The unknown key was logged loudly.
		$logs = $subject->get_logs( array( 'code' => 'config_unknown_arg' ) );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'bogus', $logs[0]['context']['key'] );
	}

	/**
	 * Strict config leaves recognized keys alone (no false positives, no log).
	 *
	 * @since 3.1.0
	 */
	public function test_strict_config_allows_known_args() {
		$subject = new BootTestSubject( array( 'name' => 'OK' ) );

		$this->assertSame( 'OK', $subject->name );
		$this->assertSame( array(), $subject->get_logs( array( 'code' => 'config_unknown_arg' ) ) );
	}

	/**
	 * A class that opts OUT of strict config (e.g. Row, with dynamic columns)
	 * still passes unknown keys through and applies them, with no log.
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_args_pass_through_when_not_strict() {
		$subject = new BootTestLooseSubject(
			array(
				'name'  => 'Loose',
				'extra' => 'kept',
			)
		);

		$this->assertSame( 'Loose', $subject->name );
		$this->assertSame( 'kept', $subject->extra );
		$this->assertSame( array(), $subject->get_logs( array( 'code' => 'config_unknown_arg' ) ) );
	}
}
