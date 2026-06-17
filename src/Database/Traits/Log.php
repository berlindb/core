<?php
/**
 * Database Log Trait.
 *
 * @package     Database
 * @subpackage  Log
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for collecting debug and diagnostic log entries.
 *
 * The default implementation stores structured entries in memory and exposes
 * them via get_logs(). Subclasses may override write_log() to bridge entries to
 * a host application logger, PHP error logging, or another persistence layer.
 *
 * @since 3.0.0
 */
trait Log {

	/**
	 * In-memory diagnostic log entries.
	 *
	 * @since 3.0.0
	 * @var   array<int,array{level: string, code: string, message: string, context: array<string,mixed>, time: float, source: string}>
	 */
	protected $logs = array();

	/**
	 * Add a structured diagnostic log entry.
	 *
	 * @since 3.0.0
	 *
	 * @param string              $level   Log level, such as debug, info, warning, or error.
	 * @param string              $code    Stable machine-readable event code.
	 * @param string              $message Human-readable message.
	 * @param array<string,mixed> $context Optional structured context. Default empty array.
	 */
	protected function log( string $level, string $code, string $message, array $context = array() ): void {

		// Normalize strings.
		$level   = strtolower( trim( $level ) );
		$code    = strtolower( trim( $code ) );
		$message = trim( $message );

		// Bail if there is nothing useful to record.
		if ( empty( $level ) || empty( $code ) || empty( $message ) ) {
			return;
		}

		// Build structured log entry.
		$entry = array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
			'context' => $context,
			'time'    => microtime( true ),
			'source'  => static::class,
		);

		// Store locally for programmatic inspection.
		$this->logs[] = $entry;

		// Allow subclasses to bridge to an external log writer.
		$this->write_log( $entry );
	}

	/**
	 * Return collected log entries, optionally filtered by entry fields.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $args     Optional field/value pairs to match. Default empty array.
	 * @param string              $operator Optional comparison operator. Accepts 'and' or 'or'. Default 'and'.
	 * @return array<int,array{level: string, code: string, message: string, context: array<string,mixed>, time: float, source: string}>
	 */
	public function get_logs( array $args = array(), string $operator = 'and' ) {

		// Return all entries if there are no filters.
		if ( empty( $args ) ) {
			return $this->logs;
		}

		// Return filtered entries.
		return $this->filter_logs( $this->logs, $args, $operator );
	}

	/**
	 * Clear collected log entries, optionally filtered by entry fields.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $args     Optional field/value pairs to match. Default empty array clears all logs.
	 * @param string              $operator Optional comparison operator. Accepts 'and' or 'or'. Default 'and'.
	 */
	public function clear_logs( array $args = array(), string $operator = 'and' ): void {

		// Clear all entries by default.
		if ( empty( $args ) ) {
			$this->logs = array();
			return;
		}

		// Keep entries that do not match the requested filters.
		$this->logs = array_values(
			array_filter(
				$this->logs,
				function ( $entry ) use ( $args, $operator ) {
					return ! $this->log_matches( $entry, $args, $operator );
				}
			)
		);
	}

	/**
	 * Filter log entries by field/value pairs.
	 *
	 * @since 3.0.0
	 *
	 * @param array<int,array{level: string, code: string, message: string, context: array<string,mixed>, time: float, source: string}> $logs     Log entries.
	 * @param array<string,mixed>                                                                                                       $args     Field/value pairs to match.
	 * @param string                                                                                                                    $operator Optional comparison operator. Accepts 'and' or 'or'. Default 'and'.
	 * @return array<int,array{level: string, code: string, message: string, context: array<string,mixed>, time: float, source: string}>
	 */
	protected function filter_logs( array $logs = array(), array $args = array(), string $operator = 'and' ): array {
		return array_values(
			array_filter(
				$logs,
				function ( $entry ) use ( $args, $operator ) {
					return $this->log_matches( $entry, $args, $operator );
				}
			)
		);
	}

	/**
	 * Determine whether a log entry matches field/value filters.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string,mixed> $entry    Log entry.
	 * @param array<string,mixed> $args     Field/value pairs to match.
	 * @param string              $operator Optional comparison operator. Accepts 'and' or 'or'. Default 'and'.
	 * @return bool
	 */
	protected function log_matches( array $entry = array(), array $args = array(), string $operator = 'and' ): bool {

		// Empty filters match everything.
		if ( empty( $args ) ) {
			return true;
		}

		// Normalize operator.
		$operator = strtolower( trim( $operator ) );

		// OR needs one match.
		if ( 'or' === $operator ) {
			foreach ( $args as $key => $value ) {
				if ( array_key_exists( $key, $entry ) && $value === $entry[ $key ] ) {
					return true;
				}
			}

			return false;
		}

		// AND needs every match.
		foreach ( $args as $key => $value ) {
			if ( ! array_key_exists( $key, $entry ) || $value !== $entry[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Optional bridge to an external log writer.
	 *
	 * The base implementation is intentionally a no-op. Applications can
	 * override this method to write to debug.log, error_log(), Monolog, Query
	 * Monitor, or any other logging destination.
	 *
	 * @since 3.0.0
	 *
	 * @param array{level: string, code: string, message: string, context: array<string,mixed>, time: float, source: string} $entry Log entry.
	 */
	protected function write_log( array $entry ): void {}

	/**
	 * Reserved property names owned by the Log trait.
	 *
	 * The in-memory log store, which configuration must never set or have
	 * clobbered. Unioned by Configuration::get_reserved_vars(), so each trait owns
	 * its own internal-state declaration.
	 *
	 * @since 3.1.0
	 *
	 * @return list<string>
	 */
	protected function get_log_reserved_vars(): array {
		return array( 'logs' );
	}
}
