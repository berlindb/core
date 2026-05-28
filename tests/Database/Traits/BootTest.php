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
		parse_args as public expose_parse_args;
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
	 * Expose boot() so tests can exercise argument parsing after construction.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed>|object $args Boot arguments.
	 */
	public function reboot( $args = array() ): void {
		$this->events = array();
		$this->boot( $args );
	}

	/**
	 * Called before arguments are parsed.
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
	 * Called after arguments are parsed and set.
	 *
	 * @since 3.0.0
	 */
	protected function init(): void {
		$this->events[] = 'init';
	}

	/**
	 * Called by Lifecycle after boot completes.
	 *
	 * @since 3.0.0
	 */
	protected function finish(): void {
		$this->events[] = 'finish';
	}
}

/**
 * Tests for the Boot trait.
 *
 * @since 3.0.0
 */
class BootTest extends TestCase {

	/**
	 * Empty constructor arguments still run the lifecycle without setting vars.
	 *
	 * @since 3.0.0
	 */
	public function test_boot_with_empty_arguments_skips_set_vars() {
		$subject = new BootTestSubject();

		$this->assertSame(
			array(
				'sunrise',
				'init',
				'finish',
			),
			$subject->events
		);
		$this->assertSame( 'default', $subject->name );
	}

	/**
	 * parse_args() stashes input, applies special args, sets vars, and validates.
	 *
	 * @since 3.0.0
	 */
	public function test_parse_args_processes_non_empty_arguments() {
		$subject                = new BootTestSubject();
		$subject->events        = array();
		BootTestSubject::$calls = array();

		$result = $subject->expose_parse_args( array( 'name' => 'Berlin' ) );

		$this->assertSame(
			array(
				'special_args',
				'set_vars',
				'validate_args',
			),
			BootTestSubject::$calls
		);
		$this->assertSame( 'Berlin', $subject->name );
		$this->assertSame( 'specialized', $subject->special );
		$this->assertSame( 'yes', $result['validated'] );
		$this->assertSame( array( 'name' => 'Berlin' ), $subject->get_stashed_args()['param'] );
	}
}
