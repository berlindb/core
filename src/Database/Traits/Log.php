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
	 * @var   array<int, array{level: string, code: string, message: string, context: array<string, mixed>, time: float, source: string}>
	 */
	protected $logs = array();

	/**
	 * Add a structured diagnostic log entry.
	 *
	 * @since 3.0.0
	 *
	 * @param string               $level   Log level, such as debug, info, warning, or error.
	 * @param string               $code    Stable machine-readable event code.
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Optional structured context. Default empty array.
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
	 * @param array<string, mixed> $args     Optional field/value pairs to match. Default empty array.
	 * @param string               $operator Optional comparison operator. Accepts 'and' or 'or'. Default 'and'.
	 * @return array<int, array{level: string, code: string, message: string, context: array<string, mixed>, time: float, source: string}>
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
	 * @param array<string, mixed> $args     Optional field/value pairs to match. Default empty array clears all logs.
	 * @param string               $operator Optional comparison operator. Accepts 'and' or 'or'. Default 'and'.
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
	 * @param array<int, array<string, mixed>> $logs     Log entries.
	 * @param array<string, mixed>             $args     Field/value pairs to match.
	 * @param string                           $operator Optional comparison operator. Accepts 'and' or 'or'. Default 'and'.
	 * @return array<int, array<string, mixed>>
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
	 * @param array<string, mixed> $entry    Log entry.
	 * @param array<string, mixed> $args     Field/value pairs to match.
	 * @param string               $operator Optional comparison operator. Accepts 'and' or 'or'. Default 'and'.
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
	 * @param array{level: string, code: string, message: string, context: array<string, mixed>, time: float, source: string} $entry Log entry.
	 */
	protected function write_log( array $entry ): void {}

	/** Helpers ***************************************************************/

	/**
	 * Log an empty required value.
	 *
	 * @since 3.0.0
	 *
	 * @param string               $name    Human-readable value name.
	 * @param array<int, mixed>    $caller  Optional callable-style caller context.
	 * @param array<string, mixed> $context Optional structured context. Special keys:
	 *                                      'code', 'message', and 'level' override defaults.
	 */
	protected function log_empty_value( string $name = '', array $caller = array(), array $context = array() ): void {

		// Normalize.
		$name = trim( $name );

		// Defaults.
		$level   = $context['level'] ?? 'error';
		$code    = $context['code'] ?? $this->get_log_code( 'empty_value', $caller );
		$message = $context['message'] ?? "{$name} is empty.";

		// Do not duplicate override-only values into context.
		unset( $context['level'], $context['code'], $context['message'] );

		// Log.
		$this->log(
			$level,
			$code,
			$message,
			array_merge(
				$this->get_log_caller_context( $caller ),
				array(
					'name' => $name,
				),
				$context
			)
		);
	}

	/**
	 * Log a missing class.
	 *
	 * @since 3.0.0
	 *
	 * @param string               $class   Class name.
	 * @param array<int, mixed>    $caller  Optional callable-style caller context.
	 * @param array<string, mixed> $context Optional structured context. Special keys:
	 *                                      'code', 'message', and 'level' override defaults.
	 */
	protected function log_class_not_found( string $class = '', array $caller = array(), array $context = array() ): void {

		// Normalize.
		$class = trim( $class );

		// Defaults.
		$level   = $context['level'] ?? 'error';
		$code    = $context['code'] ?? $this->get_log_code( 'class_not_found', $caller );
		$message = $context['message'] ?? 'Class does not exist.';

		// Do not duplicate override-only values into context.
		unset( $context['level'], $context['code'], $context['message'] );

		// Log.
		$this->log(
			$level,
			$code,
			$message,
			array_merge(
				$this->get_log_caller_context( $caller ),
				array(
					'class' => $class,
				),
				$context
			)
		);
	}

	/**
	 * Log a class instantiation failure.
	 *
	 * @since 3.0.0
	 *
	 * @param string               $class     Class name.
	 * @param \Throwable           $exception Throwable caught during instantiation.
	 * @param array<int, mixed>    $caller    Optional callable-style caller context.
	 * @param array<string, mixed> $context   Optional structured context. Special keys:
	 *                                        'code', 'message', and 'level' override defaults.
	 */
	protected function log_class_instantiation_failed( string $class, \Throwable $exception, array $caller = array(), array $context = array() ): void {

		// Normalize.
		$class = trim( $class );

		// Defaults.
		$level   = $context['level'] ?? 'error';
		$code    = $context['code'] ?? $this->get_log_code( 'class_instantiation_failed', $caller );
		$message = $context['message'] ?? 'Class could not be instantiated.';

		// Do not duplicate override-only values into context.
		unset( $context['level'], $context['code'], $context['message'] );

		// Log.
		$this->log(
			$level,
			$code,
			$message,
			array_merge(
				$this->get_log_caller_context( $caller ),
				array(
					'class'             => $class,
					'exception'         => get_class( $exception ),
					'exception_message' => $exception->getMessage(),
				),
				$context
			)
		);
	}

	/**
	 * Log a missing method.
	 *
	 * @since 3.0.0
	 *
	 * @param object|string        $target  Object or class name.
	 * @param string               $method  Method name.
	 * @param array<int, mixed>    $caller  Optional callable-style caller context.
	 * @param array<string, mixed> $context Optional structured context. Special keys:
	 *                                      'code', 'message', and 'level' override defaults.
	 */
	protected function log_method_not_found( $target, string $method = '', array $caller = array(), array $context = array() ): void {

		// Normalize.
		$class  = is_object( $target )
			? get_class( $target )
			: trim( (string) $target );
		$method = trim( $method );

		// Defaults.
		$level   = $context['level'] ?? 'error';
		$code    = $context['code'] ?? $this->get_log_code( 'method_not_found', $caller );
		$message = $context['message'] ?? 'Method does not exist.';

		// Do not duplicate override-only values into context.
		unset( $context['level'], $context['code'], $context['message'] );

		// Log.
		$this->log(
			$level,
			$code,
			$message,
			array_merge(
				$this->get_log_caller_context( $caller ),
				array(
					'class'  => $class,
					'method' => $method,
				),
				$context
			)
		);
	}

	/**
	 * Build a default log code from caller context and a suffix.
	 *
	 * @since 3.0.0
	 *
	 * @param string            $suffix Code suffix.
	 * @param array<int, mixed> $caller Optional callable-style caller context.
	 * @return string
	 */
	protected function get_log_code( string $suffix = '', array $caller = array() ) {

		// Default parts.
		$parts = array();

		// Maybe include class short name.
		if ( ! empty( $caller[0] ) ) {
			$class   = is_object( $caller[0] )
				? get_class( $caller[0] )
				: (string) $caller[0];
			$parts[] = $this->get_log_class_short_name( $class );
		}

		// Maybe include function.
		if ( ! empty( $caller[1] ) && is_string( $caller[1] ) ) {
			$parts[] = $caller[1];
		}

		// Add suffix.
		$parts[] = $suffix;

		// Normalize parts.
		$parts = array_filter( array_map( array( $this, 'normalize_log_key' ), $parts ) );

		// Return code.
		return implode( '_', $parts );
	}

	/**
	 * Build caller context for a log entry.
	 *
	 * @since 3.0.0
	 *
	 * @param array<int, mixed> $caller Optional callable-style caller context.
	 * @return array<string, string>
	 */
	protected function get_log_caller_context( array $caller = array() ) {

		// Bail if no caller.
		if ( empty( $caller ) ) {
			return array();
		}

		// Default return value.
		$retval = array();

		// Class.
		if ( ! empty( $caller[0] ) ) {
			$retval['caller_class'] = is_object( $caller[0] )
				? get_class( $caller[0] )
				: (string) $caller[0];
		}

		// Function.
		if ( ! empty( $caller[1] ) && is_string( $caller[1] ) ) {
			$retval['caller_function'] = $caller[1];
		}

		// Caller string.
		if ( ! empty( $retval['caller_class'] ) && ! empty( $retval['caller_function'] ) ) {
			$retval['caller'] = "{$retval['caller_class']}::{$retval['caller_function']}";
		}

		// Return context.
		return $retval;
	}

	/**
	 * Return a class short name without requiring Reflection.
	 *
	 * @since 3.0.0
	 *
	 * @param string $class Fully-qualified class name.
	 * @return string
	 */
	protected function get_log_class_short_name( string $class = '' ) {
		$parts = explode( '\\', trim( $class, '\\' ) );

		return (string) end( $parts );
	}

	/**
	 * Normalize a string for use in log codes and keys.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key Key to normalize.
	 * @return string
	 */
	protected function normalize_log_key( string $key = '' ) {
		$key = strtolower( trim( $key ) );
		$key = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$key = preg_replace( '/_+/', '_', (string) $key );

		return trim( (string) $key, '_' );
	}
}
