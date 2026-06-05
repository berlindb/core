<?php
/**
 * Date Query Var Parser Class.
 *
 * @package     Database
 * @subpackage  Date
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Parsers;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class for generating SQL clauses that filter a primary query according to
 * date.
 *
 * Is heavily inspired by the WP_Date_Query class in WordPress, with changes to
 * make it more flexible for custom tables and their columns.
 *
 * Date is a helper that allows primary query classes, to filter their results
 * by date columns, by generating `WHERE` subclauses to be attached to the
 * primary SQL query string.
 *
 * Attempting to filter by an invalid date value (eg month=13) will generate SQL
 * that will return no results. See Date::validate_values().
 *
 * Time-related parameters that normally require integer values:
 * - 'year', 'month', 'week', 'dayofyear', 'day', 'dayofweek', 'dayofweek_iso',
 *   'hour', 'minute', 'second'
 * accept arrays of integers for some values of:
 * - 'compare'.
 *
 * When 'compare' is 'IN' or 'NOT IN', arrays are accepted.
 *
 * When 'compare' is 'BETWEEN' or 'NOT BETWEEN', arrays of two valid values are
 * required.

 * See individual argument descriptions for accepted values.
 *
 * @since 3.0.0
 *
 * @param array $date_query {
 *     Array of date query clauses.
 *
 *     @type array ...$0 {
 *         @type string $column           Optional. The column to query against. If undefined, inherits the value of
 *                                        'date_created'. Accepts 'date_created', 'date_created_gmt',
 *                                        'post_modified','post_modified_gmt', 'comment_date', 'comment_date_gmt'.
 *                                        Default 'date_created'.
 *         @type string $compare          Optional. The comparison operator. Accepts '=', '!=', '>', '>=', '<', '<=',
 *                                        'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'. Default '='.
 *         @type string $relation         Optional. The boolean relationship between the date queries. Accepts 'OR' or 'AND'.
 *                                        Default 'OR'.
 *         @type int|array $start_of_week Optional. Day that week starts on. Accepts numbers 0-6
 *                                        (0 = Sunday, 1 is Monday). Default 0.
 *         @type array  ...$0 {
 *             Optional. An array of first-order clause parameters, or another fully-formed date query.
 *
 *             @type array|string $before {
 *                 Optional. Date to retrieve posts before. Accepts `strtotime()`-compatible string,
 *                 or array of 'year', 'month', 'day' values.
 *
 *                 @type string $year  The four-digit year. Default empty. Accepts any four-digit year.
 *                 @type string $month Optional when passing array.The month of the year.
 *                                     Default (string:empty)|(array:1). Accepts numbers 1-12.
 *                 @type string $day   Optional when passing array.The day of the month.
 *                                     Default (string:empty)|(array:1). Accepts numbers 1-31.
 *             }
 *             @type array|string $after {
 *                 Optional. Date to retrieve posts after. Accepts `strtotime()`-compatible string,
 *                 or array of 'year', 'month', 'day' values.
 *
 *                 @type string $year  The four-digit year. Accepts any four-digit year. Default empty.
 *                 @type string $month Optional when passing array. The month of the year. Accepts numbers 1-12.
 *                                     Default (string:empty)|(array:12).
 *                 @type string $day   Optional when passing array.The day of the month. Accepts numbers 1-31.
 *                                     Default (string:empty)|(array:last day of month).
 *             }
 *             @type string       $column        Optional. Used to add a clause comparing a column other than the
 *                                               column specified in the top-level `$column` parameter. Accepts
 *                                               'date_created', 'date_created_gmt', 'post_modified', 'post_modified_gmt',
 *                                               'comment_date', 'comment_date_gmt'. Default is the value of
 *                                               top-level `$column`.
 *             @type string       $compare       Optional. The comparison operator. Accepts '=', '!=', '>', '>=',
 *                                               '<', '<=', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'. 'IN',
 *                                               'NOT IN', 'BETWEEN', and 'NOT BETWEEN'. Comparisons support
 *                                               arrays in some time-related parameters. Default '='.
 *             @type int|array    $start_of_week Optional. Day that week starts on. Accepts numbers 0-6
 *                                               (0 = Sunday, 1 is Monday). Default 0.
 *             @type bool         $inclusive     Optional. Include results from dates specified in 'before' or
 *                                               'after'. Default false.
 *             @type int|array    $year          Optional. The four-digit year number. Accepts any four-digit year
 *                                               or an array of years if `$compare` supports it. Default empty.
 *             @type int|array    $month         Optional. The two-digit month number. Accepts numbers 1-12 or an
 *                                               array of valid numbers if `$compare` supports it. Default empty.
 *             @type int|array    $week          Optional. The week number of the year. Accepts numbers 0-53 or an
 *                                               array of valid numbers if `$compare` supports it. Default empty.
 *             @type int|array    $dayofyear     Optional. The day number of the year. Accepts numbers 1-366 or an
 *                                               array of valid numbers if `$compare` supports it.
 *             @type int|array    $day           Optional. The day of the month. Accepts numbers 1-31 or an array
 *                                               of valid numbers if `$compare` supports it. Default empty.
 *             @type int|array    $dayofweek     Optional. The day number of the week. Accepts numbers 1-7 (1 is
 *                                               Sunday) or an array of valid numbers if `$compare` supports it.
 *                                               Default empty.
 *             @type int|array    $dayofweek_iso Optional. The day number of the week (ISO). Accepts numbers 1-7
 *                                               (1 is Monday) or an array of valid numbers if `$compare` supports it.
 *                                               Default empty.
 *             @type int|array    $hour          Optional. The hour of the day. Accepts numbers 0-23 or an array
 *                                               of valid numbers if `$compare` supports it. Default empty.
 *             @type int|array    $minute        Optional. The minute of the hour. Accepts numbers 0-60 or an array
 *                                               of valid numbers if `$compare` supports it. Default empty.
 *             @type int|array    $second        Optional. The second of the minute. Accepts numbers 0-60 or an
 *                                               array of valid numbers if `$compare` supports it. Default empty.
 *         }
 *     }
 * }
 */
class Date extends Base {

	/**
	 * Internal identifier for this parser.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'date';

	/**
	 * Top-level query var key this parser consumes, or null when operating per-column.
	 *
	 * @since 3.0.0
	 * @var string|null
	 */
	protected $query_var = 'date_query';

	/**
	 * Column filter passed to get_column_names() to select relevant columns.
	 *
	 * @since 3.0.0
	 * @var array<string, bool>
	 */
	protected $column_filter = array( 'date_query' => true );

	/**
	 * Suffix appended to each matching column name to form the per-column query var key.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $column_suffix = '_query';

	/**
	 * Default value for the query var. Null defers to Query::$query_var_default_value.
	 *
	 * @since 3.0.0
	 * @var mixed
	 */
	protected $default = null;

	/**
	 * Whether this parser contributes ORDER BY SQL via get_orderby_sql().
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	public $sortable = true;

	/**
	 * Determines and validates what first-order keys to use.
	 *
	 * Use first $first_keys if passed and valid.
	 *
	 * @since 3.0.0
	 *
	 * @param list<string> $first_keys Array of first-order keys.
	 *
	 * @return list<string> The first-order keys.
	 */
	protected function get_first_keys( $first_keys = array() ) {
		return array(
			'after',
			'before',
			'value',
			'year',
			'month',
			'monthnum',
			'week',
			'w',
			'dayofyear',
			'day',
			'dayofweek',
			'dayofweek_iso',
			'hour',
			'minute',
			'second',
		);
	}

	/**
	 * Validates the given date_query values.
	 *
	 * Note that date queries with invalid date ranges are allowed to
	 * continue (though of course no items will be found for impossible dates).
	 * This method only generates debug notices for these cases.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $date_query The date_query array.
	 * @return bool True if all values in the query are valid, false if one or more fail.
	 */
	protected function validate_values( $date_query = array() ) {

		// Bail if empty.
		if ( empty( $date_query ) ) {
			return false;
		}

		// Default return value.
		$valid = true;

		/*
		 * Validate 'before' and 'after' up front, then let the
		 * validation routine continue to be sure that all invalid
		 * values generate errors too.
		 */
		if ( array_key_exists( 'before', $date_query ) && is_array( $date_query[ 'before' ] ) ) {
			if ( false === $this->validate_values( $date_query[ 'before' ] ) ) {
				$valid = false;
			}
		}

		if ( array_key_exists( 'after', $date_query ) && is_array( $date_query[ 'after' ] ) ) {
			if ( false === $this->validate_values( $date_query[ 'after' ] ) ) {
				$valid = false;
			}
		}

		// Values are passthroughs.
		if ( array_key_exists( 'value', $date_query ) ) {
			$valid = true;
		}

		// Array containing all min-max checks.
		$min_max_checks = array();

		// Days per year.
		if ( array_key_exists( 'year', $date_query ) ) {
			/*
			 * If a year exists in the date query, we can use it to get the days.
			 * If multiple years are provided (as in a BETWEEN), use the first one.
			 */
			if ( is_array( $date_query[ 'year' ] ) ) {
				$_year = reset( $date_query[ 'year' ] );
			} else {
				$_year = $date_query[ 'year' ];
			}

			$max_days_of_year = (int) gmdate( 'z', (int) gmmktime( 0, 0, 0, 12, 31, (int) $_year ) ) + 1;

			// Otherwise we use the max of 366 (leap-year).
		} else {
			$max_days_of_year = 366;
		}

		// Days of year.
		$min_max_checks[ 'dayofyear' ] = array(
			'min' => 1,
			'max' => $max_days_of_year,
		);

		// Days per week.
		$min_max_checks[ 'dayofweek' ] = array(
			'min' => 1,
			'max' => 7,
		);

		// Days per week.
		$min_max_checks[ 'dayofweek_iso' ] = array(
			'min' => 1,
			'max' => 7,
		);

		// Months per year.
		$min_max_checks[ 'month' ] = array(
			'min' => 1,
			'max' => 12,
		);

		// Weeks per year.
		if ( isset( $_year ) ) {
			/*
			 * If we have a specific year, use it to calculate number of weeks.
			 * Note: the number of weeks in a year is the date in which Dec 28 appears.
			 */
			$week_count = gmdate( 'W', (int) gmmktime( 0, 0, 0, 12, 28, (int) $_year ) );

			// Otherwise set the week-count to a maximum of 53.
		} else {
			$week_count = 53;
		}

		// Weeks per year.
		$min_max_checks[ 'week' ] = array(
			'min' => 1,
			'max' => $week_count,
		);

		// Days per month.
		$min_max_checks[ 'day' ] = array(
			'min' => 1,
			'max' => 31,
		);

		// Hours per day.
		$min_max_checks[ 'hour' ] = array(
			'min' => 0,
			'max' => 23,
		);

		// Minutes per hour.
		$min_max_checks[ 'minute' ] = array(
			'min' => 0,
			'max' => 59,
		);

		// Seconds per minute.
		$min_max_checks[ 'second' ] = array(
			'min' => 0,
			'max' => 59,
		);

		// Loop through min/max checks.
		foreach ( $min_max_checks as $key => $check ) {

			// Skip if not in query.
			if ( ! array_key_exists( $key, $date_query ) ) {
				continue;
			}

			// Check for invalid values.
			foreach ( (array) $date_query[ $key ] as $_value ) {
				$is_between = ( $_value >= $check[ 'min' ] ) && ( $_value <= $check[ 'max' ] );

				if ( ! is_numeric( $_value ) || ( false === $is_between ) ) {
					$valid = false;
				}
			}
		}

		// Bail if invalid query.
		if ( false === $valid ) {
			return $valid;
		}

		// Check what kinds of dates are being queried for.
		$day_exists   = array_key_exists( 'day', $date_query ) && is_numeric( $date_query[ 'day' ] );
		$month_exists = array_key_exists( 'month', $date_query ) && is_numeric( $date_query[ 'month' ] );
		$year_exists  = array_key_exists( 'year', $date_query ) && is_numeric( $date_query[ 'year' ] );

		// Checking at least day & month.
		if ( ! empty( $day_exists ) && ! empty( $month_exists ) ) {

			// Check for year query, or fallback to 2012 (for flexibility).
			$year = ! empty( $year_exists )
				? $date_query[ 'year' ]
				: '2012';

			// Check the date.
			if ( ! checkdate( (int) $date_query[ 'month' ], (int) $date_query[ 'day' ], (int) $year ) ) {
				$valid = false;
			}
		}

		// Return if valid or not.
		return $valid;
	}

	/**
	 * Generate SQL for a query clause.
	 *
	 * @since  3.0.0
	 *
	 * @param array<string, mixed> $clause       Query clause (passed by reference).
	 * @param array<string, mixed> $parent_query Parent query array.
	 * @param string               $clause_key   Optional. The array key used to name the clause.
	 *                                           If not provided, a key will be generated automatically.
	 *
	 * @return array{join: list<string>, where: list<string>} {
	 *     Array containing JOIN and WHERE SQL clauses to append to the main query.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	protected function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {

		// The sub-parts of a $where part.
		$where = array();

		// Get first-order clauses.
		$now           = $this->get_now( $clause );
		$column_name   = $this->get_column( $clause );
		$compare       = $this->get_compare( $clause );
		$start_of_week = $this->get_start_of_week( $clause );
		$inclusive     = ! empty( $clause[ 'inclusive' ] );

		/*
		 * Track whether the column was explicitly requested for THIS clause —
		 * either via the clause's own 'column', or (below) a {col}_query key.
		 * A column merely inherited from the parser default could belong to a
		 * foreign sub-array that only matched a date first-order key, so we must
		 * not fail those closed.
		 */
		$explicit = ! empty( $clause[ 'column' ] );

		/*
		 * Per-column shorthand (e.g. 'date_created_query', 'start_query',
		 * 'end_query'): when the clause carries no explicit 'column', recover it
		 * from the clause key by stripping the '_query' suffix. This is the form
		 * EDD and Sugar Calendar use.
		 *
		 * NOTE: a derived column is NOT treated as explicit. A sibling parser's
		 * var name also ends in '_query' (e.g. 'compare_query' strips to
		 * 'compare'), so when Date receives the full query_vars it can strip a
		 * foreign clause's key here. If that derived name isn't a date column it
		 * must DROP (below), not fail closed — failing closed would emit 1 = 0
		 * into an unrelated parser's query. Only a genuine '{date_col}_query'
		 * resolves to a real date column and proceeds.
		 */
		if ( empty( $column_name ) && is_string( $clause_key ) ) {
			$derived = $this->strip_column_suffix( $clause_key );

			if ( false !== $derived ) {
				$column_name = $derived;
			}
		}

		/*
		 * Bail if no date column is resolved — this clause doesn't belong to a
		 * date query (e.g. a non-date sub-array accidentally matched first_keys).
		 * Dropped (not failed closed) so it can't bleed into a foreign query.
		 */
		if ( empty( $column_name ) ) {
			return array(
				'join'  => array(),
				'where' => array(),
			);
		}

		// Resolve and qualify the column, validating date_query support.
		$column = $this->get_column_sql( $column_name, array( 'date_query' => true ) );

		/*
		 * The name doesn't map to a date column. When the clause itself named the
		 * column ('column' => ...), that's a typo/misuse — fail closed so it
		 * matches no rows instead of dropping (which widens results to every row).
		 * A column derived from the clause key or inherited from the default may
		 * belong to a foreign sub-array Date merely swept up, so those are dropped
		 * to avoid emitting 1 = 0 into an unrelated parser's query.
		 */
		if ( empty( $column ) ) {
			return $explicit
				? $this->unresolved_column_clause(
					array(
						'join'  => array(),
						'where' => array(),
					)
				)
				: array(
					'join'  => array(),
					'where' => array(),
				);
		}

		// Assign greater-than and less-than values.
		$lt = '<';
		$gt = '>';

		// Also equal-to if inclusive.
		if ( true === $inclusive ) {
			$lt .= '=';
			$gt .= '=';
		}

		// Pattern is always string.
		$pattern = '%s';

		// Range queries.
		if ( ! empty( $clause[ 'after' ] ) ) {
			$after_raw = $clause[ 'after' ];
			if ( is_array( $after_raw ) ) {
				/** @var array<string, int> $after_val */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
				$after_val = $after_raw;
			} elseif ( is_int( $after_raw ) || is_string( $after_raw ) ) {
				$after_val = $after_raw;
			} else {
				$after_val = '';
			}
			$after = $this->build_mysql_datetime( $after_val, ! $inclusive, $now );

			// Only add to where if valid datetime.
			if ( false !== $after ) {
				$where[] = (string) $this->db()->prepare( "{$column} {$gt} {$pattern}", $after );
			}
		}

		if ( ! empty( $clause[ 'before' ] ) ) {
			$before_raw = $clause[ 'before' ];
			if ( is_array( $before_raw ) ) {
				/** @var array<string, int> $before_val */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
				$before_val = $before_raw;
			} elseif ( is_int( $before_raw ) || is_string( $before_raw ) ) {
				$before_val = $before_raw;
			} else {
				$before_val = '';
			}
			$before = $this->build_mysql_datetime( $before_val, $inclusive, $now );

			// Only add to where if valid datetime.
			if ( false !== $before ) {
				$where[] = (string) $this->db()->prepare( "{$column} {$lt} {$pattern}", $before );
			}
		}

		// Specific value queries.
		if ( isset( $clause[ 'year' ] ) ) {
			$value = $this->build_numeric_value( $compare, $clause[ 'year' ] );
			if ( false !== $value ) {
				$where[] = "YEAR( {$column} ) {$compare} {$value}";
			}
		}

		// month / monthnum are aliases — try month first, fall back to monthnum.
		$value = false;
		if ( isset( $clause[ 'month' ] ) ) {
			$value = $this->build_numeric_value( $compare, $clause[ 'month' ] );
		}
		if ( false === $value && isset( $clause[ 'monthnum' ] ) ) {
			$value = $this->build_numeric_value( $compare, $clause[ 'monthnum' ] );
		}
		if ( false !== $value ) {
			$where[] = "MONTH( {$column} ) {$compare} {$value}";
		}

		// week / w are aliases — try week first, fall back to w.
		$value = false;
		if ( isset( $clause[ 'week' ] ) ) {
			$value = $this->build_numeric_value( $compare, $clause[ 'week' ] );
		}
		if ( false === $value && isset( $clause[ 'w' ] ) ) {
			$value = $this->build_numeric_value( $compare, $clause[ 'w' ] );
		}
		if ( false !== $value ) {
			$where[] = $this->build_mysql_week( $column, $start_of_week ) . " {$compare} {$value}";
		}

		if ( isset( $clause[ 'dayofyear' ] ) ) {
			$value = $this->build_numeric_value( $compare, $clause[ 'dayofyear' ] );
			if ( false !== $value ) {
				$where[] = "DAYOFYEAR( {$column} ) {$compare} {$value}";
			}
		}

		if ( isset( $clause[ 'day' ] ) ) {
			$value = $this->build_numeric_value( $compare, $clause[ 'day' ] );
			if ( false !== $value ) {
				$where[] = "DAYOFMONTH( {$column} ) {$compare} {$value}";
			}
		}

		if ( isset( $clause[ 'dayofweek' ] ) ) {
			$value = $this->build_numeric_value( $compare, $clause[ 'dayofweek' ] );
			if ( false !== $value ) {
				$where[] = "DAYOFWEEK( {$column} ) {$compare} {$value}";
			}
		}

		if ( isset( $clause[ 'dayofweek_iso' ] ) ) {
			$value = $this->build_numeric_value( $compare, $clause[ 'dayofweek_iso' ] );
			if ( false !== $value ) {
				$where[] = "WEEKDAY( {$column} ) + 1 {$compare} {$value}";
			}
		}

		// Straight value compare — build_value() normalises the mixed input.
		if ( isset( $clause[ 'value' ] ) ) {
			$value   = $this->build_value( $compare, $clause[ 'value' ] );
			$where[] = "{$column} {$compare} {$value}";
		}

		// Hour/Minute/Second.
		if ( isset( $clause[ 'hour' ] ) || isset( $clause[ 'minute' ] ) || isset( $clause[ 'second' ] ) ) {

			// Avoid notices.
			foreach ( array( 'hour', 'minute', 'second' ) as $unit ) {
				if ( ! isset( $clause[ $unit ] ) ) {
					$clause[ $unit ] = null;
				}
			}

			// Time query.
			$time_query = $this->build_time_query( $column, $compare, $clause[ 'hour' ], $clause[ 'minute' ], $clause[ 'second' ] );

			// Maybe add to where_parts.
			if ( ! empty( $time_query ) ) {
				$where[] = $time_query;
			}
		}

		// Return join/where array.
		return array(
			'join'  => array(),
			'where' => $where,
		);
	}

	/**
	 * Build an ORDER BY column reference for a '{column}_query' orderby value.
	 *
	 * When a caller passes orderby='{column}_query' (e.g. 'date_created_query'),
	 * this returns the qualified column name so MySQL sorts by the raw datetime
	 * value of that column.
	 *
	 * @since 3.0.0
	 *
	 * @param string $orderby The raw orderby value.
	 * @param bool   $alias   Whether to prefix with the table alias.
	 *
	 * @return string SQL fragment, or empty string if not a date column orderby.
	 */
	public function get_orderby_sql( $orderby = '', $alias = true ) {

		// Bail if no caller.
		if ( empty( $this->caller ) ) {
			return '';
		}

		// Bail if $orderby doesn't end with the expected suffix.
		if ( ! str_ends_with( $orderby, $this->column_suffix ) ) {
			return '';
		}

		// Strip the suffix to get the bare column name.
		$column_name = substr( $orderby, 0, -strlen( $this->column_suffix ) );

		// Return the qualified column name, validating date_query support.
		return $this->get_column_sql( $column_name, array( 'date_query' => true ), $alias );
	}
}
