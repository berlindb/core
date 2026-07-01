<?php
/**
 * The outcome of applying a patch / reconciling a table.
 *
 * @package     Database
 * @subpackage  Diff
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Diff;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A three-way outcome of an apply() / reconcile() attempt.
 *
 * The bare bool that apply() used to return could not tell a caller WHY nothing
 * happened, which matters most for the auto-upgrade path: a transient, incomplete
 * capture should be retried, but a hard ALTER failure should not be re-run on
 * every request. The three states make that decision possible:
 *
 *  - successful() - every requested change applied (or there was nothing to do).
 *    Safe to advance the stored version.
 *  - deferred()   - not attempted because the introspection was not complete
 *    (see Snapshot). Transient: do NOT advance; retry against a fresh capture.
 *  - failed()     - an ALTER failed. Persistent: log it and advance rather than
 *    loop on the same failing statement forever; error() carries the detail.
 *
 * Exactly one of the three is true.
 *
 * @since 3.1.0
 */
class Result {

	/**
	 * The outcome states.
	 *
	 * @since 3.1.0
	 */
	private const APPLIED  = 'applied';
	private const DEFERRED = 'deferred';
	private const FAILED   = 'failed';

	/**
	 * Which state this result is in (one of the class constants).
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $status;

	/**
	 * How many operations were applied successfully.
	 *
	 * @since 3.1.0
	 * @var int
	 */
	private $changes;

	/**
	 * The failure detail, or '' when not a failure.
	 *
	 * @since 3.1.0
	 * @var string
	 */
	private $error;

	/**
	 * @since 3.1.0
	 *
	 * @param string $status  One of the class state constants.
	 * @param int    $changes Operations applied successfully.
	 * @param string $error   Failure detail, or ''.
	 */
	private function __construct( string $status, int $changes, string $error ) {
		$this->status  = $status;
		$this->changes = $changes;
		$this->error   = $error;
	}

	/**
	 * A successful outcome: every requested change applied (or nothing to do).
	 *
	 * @since 3.1.0
	 *
	 * @param int $changes Operations applied successfully.
	 * @return self
	 */
	public static function applied( int $changes = 0 ): self {
		return new self( self::APPLIED, $changes, '' );
	}

	/**
	 * A deferred outcome: not attempted because the capture was incomplete.
	 *
	 * @since 3.1.0
	 *
	 * @return self
	 */
	public static function deferred(): self {
		return new self( self::DEFERRED, 0, '' );
	}

	/**
	 * A failed outcome: an ALTER failed after $changes had already applied.
	 *
	 * @since 3.1.0
	 *
	 * @param string $error   Failure detail (for logging).
	 * @param int    $changes Operations applied before the failure.
	 * @return self
	 */
	public static function failed( string $error, int $changes = 0 ): self {
		return new self( self::FAILED, $changes, $error );
	}

	/**
	 * Whether every requested change applied (or there was nothing to do).
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	public function is_successful(): bool {
		return self::APPLIED === $this->status;
	}

	/**
	 * Whether the attempt was deferred (incomplete capture; retry).
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	public function is_deferred(): bool {
		return self::DEFERRED === $this->status;
	}

	/**
	 * Whether an ALTER failed (persistent; log and move on).
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	public function is_failed(): bool {
		return self::FAILED === $this->status;
	}

	/**
	 * How many operations were applied successfully.
	 *
	 * @since 3.1.0
	 * @return int
	 */
	public function changes(): int {
		return $this->changes;
	}

	/**
	 * The failure detail, or '' when not a failure.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function error(): string {
		return $this->error;
	}
}
