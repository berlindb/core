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
	 * @var   array<int, array{level: string, message: string, context: array<string, mixed>, time: float, source: string}>
	 */
	protected $logs = array();

	/**
	 * Add a structured diagnostic log entry.
	 *
	 * @since 3.0.0
	 *
	 * @param string               $level   Log level, such as debug, info, warning, or error.
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Optional structured context. Default empty array.
	 */
	protected function log( string $level = 'debug', string $message = '', array $context = array() ): void {

		// Normalize level and message.
		$level   = strtolower( trim( $level ) );
		$message = trim( $message );

		// Bail if there is nothing useful to record.
		if ( empty( $level ) || empty( $message ) ) {
			return;
		}

		// Build structured log entry.
		$entry = array(
			'level'   => $level,
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
	 * Return collected log entries, optionally filtered by level.
	 *
	 * @since 3.0.0
	 *
	 * @param string $level Optional log level to return. Default empty string returns all logs.
	 * @return array<int, array{level: string, message: string, context: array<string, mixed>, time: float, source: string}>
	 */
	public function get_logs( string $level = '' ) {

		// Return all entries by default.
		if ( '' === $level ) {
			return $this->logs;
		}

		// Normalize level.
		$level = strtolower( trim( $level ) );

		// Filter by log level.
		return array_values(
			array_filter(
				$this->logs,
				static function ( $entry ) use ( $level ) {
					return $level === $entry['level'];
				}
			)
		);
	}

	/**
	 * Clear collected log entries, optionally filtered by level.
	 *
	 * @since 3.0.0
	 *
	 * @param string $level Optional log level to clear. Default empty string clears all logs.
	 */
	public function clear_logs( string $level = '' ): void {

		// Clear all entries by default.
		if ( '' === $level ) {
			$this->logs = array();
			return;
		}

		// Normalize level.
		$level = strtolower( trim( $level ) );

		// Keep entries that do not match the requested level.
		$this->logs = array_values(
			array_filter(
				$this->logs,
				static function ( $entry ) use ( $level ) {
					return $level !== $entry['level'];
				}
			)
		);
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
	 * @param array{level: string, message: string, context: array<string, mixed>, time: float, source: string} $entry Log entry.
	 */
	protected function write_log( array $entry ): void {}
}
