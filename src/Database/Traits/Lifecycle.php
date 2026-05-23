<?php
/**
 * Lifecycle Trait Class.
 *
 * @package     Database
 * @subpackage  Base
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Lifecycle Trait brackets any repeatable action with setup and teardown.
 *
 * It is the underlying mechanism for Boot (construction lifecycle) and for
 * per-run lifecycles in Query, Parser, Table, and any other class that has a
 * well-defined unit of work to bound.
 *
 * start() and finish() are template methods — empty by default and meant to be
 * overridden by subclasses. They are not external hooks; they are internal
 * extension points called by run() at the boundaries of each run:
 *
 *   Boot:  __construct > run() > start > sunrise/parse_args/set_vars/init > finish
 *   Query: query()     > run() > start > parse_query/get_items             > finish
 *
 * run() guarantees finish() fires even if the action throws, via try/finally.
 *
 * Per-run ephemeral state is managed privately through get_current() and
 * set_current(). Each class decides which keys it uses; nothing is required
 * at the trait level.
 *
 * @since 3.0.0
 */
trait Lifecycle {

	/**
	 * Ephemeral state for the current run.
	 *
	 * Private to this trait; access through get_current() and set_current().
	 * Initialized at the beginning of each action by start().
	 *
	 * @since 3.0.0
	 * @var   array<string, mixed>
	 */
	private $current = array();

	/**
	 * Template method called at the start of each run, before the main work.
	 *
	 * Override in a subclass to initialize per-run state or perform setup.
	 * Call parent::start() to preserve behaviour from any intermediate class.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function start() {}

	/**
	 * Template method called at the end of each run, after the main work.
	 *
	 * Override in a subclass for cleanup or logging.
	 * Call parent::finish() to preserve behaviour from any intermediate class.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function finish() {}

	/**
	 * Execute an action within the lifecycle.
	 *
	 * Calls start(), runs the action, then calls finish() via a finally block
	 * so finish() is guaranteed to fire even if the action throws an exception.
	 *
	 * @since 3.0.0
	 *
	 * @param callable $action The work to perform.
	 * @return mixed Whatever the action returns.
	 */
	protected function run( callable $action ) {

		// Start the lifecycle.
		$this->start();

		// Run the action, ensuring finish() fires even if it throws.
		try {
			return $action();
		} finally {
			$this->finish();
		}
	}

	/**
	 * Get a value from the current run's ephemeral state.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key     State key.
	 * @param mixed  $default Default value when the key is not set.
	 * @return mixed
	 */
	protected function get_current( $key, $default = null ) {
		return $this->current[ $key ] ?? $default;
	}

	/**
	 * Set a value in the current run's ephemeral state.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key   State key.
	 * @param mixed  $value Value to store.
	 * @return void
	 */
	protected function set_current( $key, $value ) {
		$this->current[ $key ] = $value;
	}

	/**
	 * Initialize the current run's ephemeral state.
	 *
	 * Called at the start of each run, typically from start(). Pass an
	 * associative array to pre-populate keys; omit to start from an empty slate.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $state Optional. Initial state for this run. Default empty array.
	 * @return void
	 */
	protected function init_current( $state = array() ) {
		$this->current = $state;
	}
}
