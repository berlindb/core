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
 * The Lifecycle Trait provides before/after hooks around any repeatable action.
 *
 * It is the underlying mechanism for Boot (construction lifecycle) and for
 * per-run lifecycles in Query, Parser, Table, and any other class that has a
 * well-defined "action" to bracket:
 *
 *   Boot:  __construct > start() > sunrise/parse_args/set_vars/init > finish()
 *   Query: query()     > start() > parse_query/get_items            > finish()
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
	 * Reset at the beginning of each action by start().
	 *
	 * @since 3.0.0
	 * @var   array
	 */
	private $current = array();

	/**
	 * Called at the start of the action, before the main work begins.
	 *
	 * Override to reset current state or perform pre-action setup. Call
	 * parent::start() to preserve any behaviour added by intermediate classes.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function start() {}

	/**
	 * Called at the end of the action, after the main work completes.
	 *
	 * Override for post-action cleanup or logging. Call parent::finish() to
	 * preserve any behaviour added by intermediate classes.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function finish() {}

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
	 * Reset the current run's ephemeral state.
	 *
	 * Replaces the entire $current array. Pass an associative array to
	 * pre-populate keys; omit to clear everything.
	 *
	 * @since 3.0.0
	 *
	 * @param array $state Optional. Initial state. Default empty array.
	 * @return void
	 */
	protected function reset_current( $state = array() ) {
		$this->current = $state;
	}
}
