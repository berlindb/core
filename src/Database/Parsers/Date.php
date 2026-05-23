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
	 * @since 3.0.0
	 * @var string
	 */
	protected $name = 'date';

	/**
	 * @since 3.0.0
	 * @var string|null
	 */
	protected $query_var = 'date_query';

	/**
	 * @since 3.0.0
	 * @var array<string, bool>
	 */
	protected $column_filter = array( 'date_query' => true );

	/**
	 * @since 3.0.0
	 * @var string
	 */
	protected $column_suffix = '_query';

	/**
	 * @since 3.0.0
	 * @var mixed
	 */
	protected $default = null;

	/**
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
	public function validate_values( $date_query = array() ) {

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
		if ( array_key_exists( 'before', $date_query ) && is_array( $date_query['before'] ) ) {
			if ( false === $this->validate_values( $date_query['before'] ) ) {
				$valid = false;
			}
		}

		if ( array_key_exists( 'after', $date_query ) && is_array( $date_query['after'] ) ) {
			if ( false === $this->validate_values( $date_query['after'] ) ) {
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
			if ( is_array( $date_query['year'] ) ) {
				$_year = reset( $date_query['year'] );
			} else {
				$_year = $date_query['year'];
			}

			$max_days_of_year = (int) gmdate( 'z', gmmktime( 0, 0, 0, 12, 31, $_year ) ) + 1;

			// Otherwise we use the max of 366 (leap-year).
		} else {
			$max_days_of_year = 366;
		}

		// Days of year.
		$min_max_checks['dayofyear'] = array(
			'min' => 1,
			'max' => $max_days_of_year,
		);

		// Days per week.
		$min_max_checks['dayofweek'] = array(
			'min' => 1,
			'max' => 7,
		);

		// Days per week.
		$min_max_checks['dayofweek_iso'] = array(
			'min' => 1,
			'max' => 7,
		);

		// Months per year.
		$min_max_checks['month'] = array(
			'min' => 1,
			'max' => 12,
		);

		// Weeks per year.
		if ( isset( $_year ) ) {
			/*
			 * If we have a specific year, use it to calculate number of weeks.
			 * Note: the number of weeks in a year is the date in which Dec 28 appears.
			 */
			$week_count = gmdate( 'W', gmmktime( 0, 0, 0, 12, 28, $_year ) );

			// Otherwise set the week-count to a maximum of 53.
		} else {
			$week_count = 53;
		}

		// Weeks per year.
		$min_max_checks['week'] = array(
			'min' => 1,
			'max' => $week_count,
		);

		// Days per month.
		$min_max_checks['day'] = array(
			'min' => 1,
			'max' => 31,
		);

		// Hours per day.
		$min_max_checks['hour'] = array(
			'min' => 0,
			'max' => 23,
		);

		// Minutes per hour.
		$min_max_checks['minute'] = array(
			'min' => 0,
			'max' => 59,
		);

		// Seconds per minute.
		$min_max_checks['second'] = array(
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
				$is_between = ( $_value >= $check['min'] ) && ( $_value <= $check['max'] );

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
		$day_exists   = array_key_exists( 'day', $date_query ) && is_numeric( $date_query['day']   );
		$month_exists = array_key_exists( 'month', $date_query ) && is_numeric( $date_query['month'] );
		$year_exists  = array_key_exists( 'year', $date_query ) && is_numeric( $date_query['year']  );

		// Checking at least day & month.
		if ( ! empty( $day_exists ) && ! empty( $month_exists ) ) {

			// Check for year query, or fallback to 2012 (for flexibility).
			$year = ! empty( $year_exists )
				? $date_query['year']
				: '2012';

			// Check the date.
			if ( ! checkdate( $date_query['month'], $date_query['day'], $year ) ) {
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
	public function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {

		// Get the database interface.
		$db = $this->get_db();

		// Bail if no database interface is available.
		if ( empty( $db ) ) {
			return array(
				'join'  => array(),
				'where' => array(),
			);
		}

		// The sub-parts of a $where part.
		$where = array();

		// Get first-order clauses.
		$now           = $this->get_now( $clause );
		$column_name   = $this->get_column( $clause );
		$compare       = $this->get_compare( $clause );
		$start_of_week = $this->get_start_of_week( $clause );
		$inclusive     = ! empty( $clause['inclusive'] );

		/*
		 * Bail if no date column is resolved — this clause doesn't belong to a
		 * date query (e.g. a non-date sub-array accidentally matched first_keys).
		 */
		if ( empty( $column_name ) ) {
			return array(
				'join'  => array(),
				'where' => array(),
			);
		}

		// Resolve and qualify the column, validating date_query support.
		$column = $this->get_column_sql( $column_name, array( 'date_query' => true ) );

		// Bail if the name doesn't map to a schema date column.
		if ( empty( $column ) ) {
			return array(
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
		if ( ! empty( $clause['after'] ) ) {
			$after = $this->build_mysql_datetime( $clause['after'], ! $inclusive, $now );

			// Only add to where if valid datetime.
			if ( false !== $after ) {
				$where[] = $db->prepare( "{$column} {$gt} {$pattern}", $after );
			}
		}

		if ( ! empty( $clause['before'] ) ) {
			$before = $this->build_mysql_datetime( $clause['before'], $inclusive, $now );

			// Only add to where if valid datetime.
			if ( false !== $before ) {
				$where[] = $db->prepare( "{$column} {$lt} {$pattern}", $before );
			}
		}

		// Specific value queries.
		if ( isset( $clause['year'] ) && false !== ( $value = $this->build_numeric_value( $compare, $clause['year'] ) ) ) {
			$where[] = "YEAR( {$column} ) {$compare} {$value}";
		}

		if ( isset( $clause['month'] ) && false !== ( $value = $this->build_numeric_value( $compare, $clause['month'] ) ) ) {
			$where[] = "MONTH( {$column} ) {$compare} {$value}";
		} elseif ( isset( $clause['monthnum'] ) && false !== ( $value = $this->build_numeric_value( $compare, $clause['monthnum'] ) ) ) {
			$where[] = "MONTH( {$column} ) {$compare} {$value}";
		}

		if ( isset( $clause['week'] ) && false !== ( $value = $this->build_numeric_value( $compare, $clause['week'] ) ) ) {
			$where[] = $this->build_mysql_week( $column, $start_of_week ) . " {$compare} {$value}";
		} elseif ( isset( $clause['w'] ) && false !== ( $value = $this->build_numeric_value( $compare, $clause['w'] ) ) ) {
			$where[] = $this->build_mysql_week( $column, $start_of_week ) . " {$compare} {$value}";
		}

		if ( isset( $clause['dayofyear'] ) && false !== ( $value = $this->build_numeric_value( $compare, $clause['dayofyear'] ) ) ) {
			$where[] = "DAYOFYEAR( {$column} ) {$compare} {$value}";
		}

		if ( isset( $clause['day'] ) && false !== ( $value = $this->build_numeric_value( $compare, $clause['day'] ) ) ) {
			$where[] = "DAYOFMONTH( {$column} ) {$compare} {$value}";
		}

		if ( isset( $clause['dayofweek'] ) && false !== ( $value = $this->build_numeric_value( $compare, $clause['dayofweek'] ) ) ) {
			$where[] = "DAYOFWEEK( {$column} ) {$compare} {$value}";
		}

		if ( isset( $clause['dayofweek_iso'] ) && false !== ( $value = $this->build_numeric_value( $compare, $clause['dayofweek_iso'] ) ) ) {
			$where[] = "WEEKDAY( {$column} ) + 1 {$compare} {$value}";
		}

		// Straight value compare.
		if ( isset( $clause['value'] ) ) {
			$value   = $this->build_value( $compare, $clause['value'] );
			$where[] = "{$column} {$compare} {$value}";
		}

		// Hour/Minute/Second.
		if ( isset( $clause['hour'] ) || isset( $clause['minute'] ) || isset( $clause['second'] ) ) {

			// Avoid notices.
			foreach ( array( 'hour', 'minute', 'second' ) as $unit ) {
				if ( ! isset( $clause[ $unit ] ) ) {
					$clause[ $unit ] = null;
				}
			}

			// Time query.
			$time_query = $this->build_time_query( $column, $compare, $clause['hour'], $clause['minute'], $clause['second'] );

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
