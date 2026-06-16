<?php
/**
 * Base::instantiate_class() tests.
 *
 * The shared guarded-instantiation helper: class_exists() + new with fail-closed
 * null, optional constructor args, and optional structured failure logging under
 * a caller-supplied code (which also traps a construction exception).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

/**
 * Subject exposing the protected instantiate_class() helper.
 *
 * @since 3.1.0
 */
class InstantiateClassSubject {

	use \BerlinDB\Database\Traits\Base;

	/**
	 * Public wrapper around protected instantiate_class().
	 *
	 * @since 3.1.0
	 *
	 * @param string $class    Class name.
	 * @param string $log_code Failure log code ('' = silent).
	 * @param mixed  ...$args  Constructor arguments.
	 * @return object|null
	 */
	public function make( string $class, string $log_code = '', ...$args ) {
		return $this->instantiate_class( $class, $log_code, ...$args );
	}

	/**
	 * No-op the external writer so the helper needs no WordPress bootstrap.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $entry Log entry.
	 */
	protected function write_log( array $entry ): void {}
}

/**
 * A plain target whose constructor records its argument.
 *
 * @since 3.1.0
 */
class InstantiateTarget {

	/** @var mixed */
	public $arg;

	/**
	 * @param mixed $arg Stored verbatim.
	 */
	public function __construct( $arg = null ) {
		$this->arg = $arg;
	}
}

/**
 * A target whose constructor always throws.
 *
 * @since 3.1.0
 */
class InstantiateThrower {

	public function __construct() {
		throw new \RuntimeException( 'boom' );
	}
}

/**
 * Tests for Base::instantiate_class().
 *
 * @since 3.1.0
 */
class InstantiateClassTest extends \PHPUnit\Framework\TestCase {

	/** @var InstantiateClassSubject */
	private $subject;

	/**
	 * Fresh subject per test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();
		$this->subject = new InstantiateClassSubject();
	}

	/**
	 * A valid class is instantiated and returned.
	 *
	 * @since 3.1.0
	 */
	public function test_returns_instance_for_valid_class() {
		$instance = $this->subject->make( InstantiateTarget::class );

		$this->assertInstanceOf( InstantiateTarget::class, $instance );
	}

	/**
	 * Constructor arguments are forwarded.
	 *
	 * @since 3.1.0
	 */
	public function test_forwards_constructor_arguments() {
		$instance = $this->subject->make( InstantiateTarget::class, '', 'hello' );

		$this->assertInstanceOf( InstantiateTarget::class, $instance );
		$this->assertSame( 'hello', $instance->arg );
	}

	/**
	 * An empty class name fails closed to null, with no log when no code is given.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_class_returns_null_without_logging() {
		$instance = $this->subject->make( '' );

		$this->assertNull( $instance );
		$this->assertSame( array(), $this->subject->get_logs() );
	}

	/**
	 * An unloadable class fails closed to null, silently without a code.
	 *
	 * @since 3.1.0
	 */
	public function test_missing_class_returns_null_without_logging() {
		$instance = $this->subject->make( 'BerlinDB\\Tests\\NoSuchClass_xyz' );

		$this->assertNull( $instance );
		$this->assertSame( array(), $this->subject->get_logs() );
	}

	/**
	 * An unloadable class WITH a code logs a warning under that code.
	 *
	 * @since 3.1.0
	 */
	public function test_missing_class_with_code_logs_warning() {
		$instance = $this->subject->make( 'BerlinDB\\Tests\\NoSuchClass_xyz', 'thing_missing' );

		$this->assertNull( $instance );

		$logs = $this->subject->get_logs( array( 'code' => 'thing_missing' ) );
		$this->assertNotEmpty( $logs );
		$this->assertSame( 'warning', $logs[0]['level'] );
	}

	/**
	 * A throwing constructor WITH a code is trapped: null + a logged warning.
	 *
	 * @since 3.1.0
	 */
	public function test_throwing_constructor_with_code_is_trapped() {
		$instance = $this->subject->make( InstantiateThrower::class, 'thing_threw' );

		$this->assertNull( $instance );
		$this->assertNotEmpty( $this->subject->get_logs( array( 'code' => 'thing_threw' ) ) );
	}

	/**
	 * In silent mode (no code), a constructor exception surfaces to the caller.
	 *
	 * @since 3.1.0
	 */
	public function test_silent_mode_lets_constructor_exception_surface() {
		$this->expectException( \RuntimeException::class );

		$this->subject->make( InstantiateThrower::class );
	}
}
