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
	 * @var array<string,bool>
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
	protected $sortable = true;

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
	 * @param array<string,mixed> $date_query The date_query array.
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
	 * @param array<string,mixed> $clause       Query clause (passed by reference).
	 * @param array<string,mixed> $parent_query Parent query array.
	 * @param string              $clause_key   Optional. The array key used to name the clause.
	 *                                          If not provided, a key will be generated automatically.
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
		 * Track whether the column was explicitly requested for THIS clause -
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
		 * must DROP (below), not fail closed - failing closed would emit 1 = 0
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
		 * Bail if no date column is resolved - this clause doesn't belong to a
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
		 * column ('column' => ...), that's a typo/misuse - fail closed so it
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

		/*
		 * Shared left operand for the branches migrated onto the shared engine
		 * (#211: after / before / value) - the same date column as $column, as an
		 * Operands\Column the engine can render. The still-bespoke date-part branches
		 * below keep using the qualified $column string. Null only if the column
		 * vanished between resolution and here, which cannot happen.
		 */
		$date_column = $this->caller?->get_column_by(
			array(
				'name'       => $column_name,
				'date_query' => true,
			)
		);
		$lhs         = ( $date_column instanceof \BerlinDB\Database\Kern\Column )
			? new \BerlinDB\Database\Operands\Column(
				array(
					'column' => $date_column,
					'alias'  => $this->caller->get_table_alias() ?? '',
				)
			)
			: null;

		// Range queries.
		if ( ! empty( $clause[ 'after' ] ) ) {
			$after_raw = $clause[ 'after' ];
			if ( is_array( $after_raw ) ) {
				/** @var array<string,int> $after_val */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
				$after_val = $after_raw;
			} elseif ( is_int( $after_raw ) || is_string( $after_raw ) ) {
				$after_val = $after_raw;
			} else {
				$after_val = '';
			}
			$after = $this->build_mysql_datetime( $after_val, ! $inclusive, $now );

			/*
			 * Only add to where if a valid datetime; the range compare ( >/>= ) is
			 * delegated to the shared engine, matching the migrated `value` branch.
			 */
			$after_operator = $this->get_operator( $gt );

			if ( ( false !== $after ) && ( null !== $lhs ) && ( false !== $after_operator ) ) {
				$expr = $this->build_operand_clause( $lhs, $after_operator, $after, false );

				if ( is_string( $expr ) && ( '' !== $expr ) ) {
					$where[] = $expr;
				}
			}
		}

		if ( ! empty( $clause[ 'before' ] ) ) {
			$before_raw = $clause[ 'before' ];
			if ( is_array( $before_raw ) ) {
				/** @var array<string,int> $before_val */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
				$before_val = $before_raw;
			} elseif ( is_int( $before_raw ) || is_string( $before_raw ) ) {
				$before_val = $before_raw;
			} else {
				$before_val = '';
			}
			$before = $this->build_mysql_datetime( $before_val, $inclusive, $now );

			// Only add to where if a valid datetime; the range compare ( </<= ) is delegated.
			$before_operator = $this->get_operator( $lt );

			if ( ( false !== $before ) && ( null !== $lhs ) && ( false !== $before_operator ) ) {
				$expr = $this->build_operand_clause( $lhs, $before_operator, $before, false );

				if ( is_string( $expr ) && ( '' !== $expr ) ) {
					$where[] = $expr;
				}
			}
		}

		/*
		 * Specific date-part queries ( #211 Date migration ): each extracts a part with
		 * an allow-listed function operand and compares it through the shared engine.
		 * week / w use WEEK ( + DATE_SUB / INTERVAL for a mid-week start ), dayofweek_iso
		 * uses a WEEKDAY( col ) + 1 math operand, and the combined time query uses
		 * DATE_FORMAT - all now on the shared engine ( see the helpers below ).
		 */
		if ( isset( $clause[ 'year' ] ) ) {
			$expr = $this->build_date_part_expression( 'YEAR', $column_name, $clause[ 'year' ], $compare );

			if ( null !== $expr ) {
				$where[] = $expr;
			}
		}

		// month / monthnum are aliases - try month first, fall back to monthnum.
		$month_raw = null;

		if ( isset( $clause[ 'month' ] ) ) {
			$month_raw = $clause[ 'month' ];
		}

		$expr = ( null !== $month_raw )
			? $this->build_date_part_expression( 'MONTH', $column_name, $month_raw, $compare )
			: null;

		if ( ( null === $expr ) && isset( $clause[ 'monthnum' ] ) ) {
			$expr = $this->build_date_part_expression( 'MONTH', $column_name, $clause[ 'monthnum' ], $compare );
		}

		if ( null !== $expr ) {
			$where[] = $expr;
		}

		// week / w are aliases - try week first, fall back to w.
		$week_ints = null;

		if ( isset( $clause[ 'week' ] ) ) {
			$week_ints = $this->normalize_date_part_value( $clause[ 'week' ] );
		}

		if ( ( null === $week_ints ) && isset( $clause[ 'w' ] ) ) {
			$week_ints = $this->normalize_date_part_value( $clause[ 'w' ] );
		}

		if ( null !== $week_ints ) {
			$where[] = $this->build_date_comparison( $this->build_week_operand( $column_name, $start_of_week ), $week_ints, $compare );
		}

		if ( isset( $clause[ 'dayofyear' ] ) ) {
			$expr = $this->build_date_part_expression( 'DAYOFYEAR', $column_name, $clause[ 'dayofyear' ], $compare );

			if ( null !== $expr ) {
				$where[] = $expr;
			}
		}

		if ( isset( $clause[ 'day' ] ) ) {
			$expr = $this->build_date_part_expression( 'DAYOFMONTH', $column_name, $clause[ 'day' ], $compare );

			if ( null !== $expr ) {
				$where[] = $expr;
			}
		}

		if ( isset( $clause[ 'dayofweek' ] ) ) {
			$expr = $this->build_date_part_expression( 'DAYOFWEEK', $column_name, $clause[ 'dayofweek' ], $compare );

			if ( null !== $expr ) {
				$where[] = $expr;
			}
		}

		if ( isset( $clause[ 'dayofweek_iso' ] ) ) {
			$iso_ints = $this->normalize_date_part_value( $clause[ 'dayofweek_iso' ] );

			if ( null !== $iso_ints ) {

				// ISO day-of-week is WEEKDAY( col ) + 1, an arithmetic ( math ) operand.
				$iso_lhs = $this->caller?->resolve_operand(
					array(
						'operand'  => 'math',
						'operator' => '+',
						'operands' => array(
							array(
								'operand' => 'func',
								'name'    => 'WEEKDAY',
								'args'    => array(
									array(
										'operand' => 'column',
										'name'    => $column_name,
									),
								),
							),
							array(
								'operand' => 'value',
								'value'   => 1,
								'pattern' => '%d',
							),
						),
					)
				);

				$where[] = $this->build_date_comparison( $iso_lhs, $iso_ints, $compare );
			}
		}

		/*
		 * Straight value compare, delegated to the shared operator/operand engine
		 * (Parser::build_operand_clause) rather than hand-built SQL - #211 Date
		 * migration, slice 1. This renders a unary IS NULL / IS NOT NULL, an
		 * operand-spec value (column / function / cast), and IN / BETWEEN uniformly,
		 * and stays byte-identical for a plain scalar (both sides prepare the value
		 * with the operator's own get_value_sql at the column's '%s' pattern). The
		 * date-part branches above remain bespoke for now. array_key_exists() so a
		 * null value is not silently skipped.
		 */
		if ( array_key_exists( 'value', $clause ) && ( null !== $lhs ) ) {

			/*
			 * Resolve the operator allowing unary ( get_compare() above excludes it
			 * because the bespoke branches have no value-less path ); fall back to
			 * '='. A unary operator ( IS NULL / IS NOT NULL, opted into with
			 * `value => null` ) renders value-less; the clause value is ignored.
			 */
			$value_compare = ! empty( $clause[ 'compare' ] )
				? strtoupper( (string) $clause[ 'compare' ] )
				: $this->compare;
			$operator      = $this->get_operator( $value_compare );

			if ( false === $operator ) {
				$operator = $this->get_operator( '=' );
			}

			/*
			 * A "forget me" falsey value ( null / false ) is IGNORED for a
			 * non-unary operator - the same intent every date-part key honors via
			 * build_numeric_value() ( which bails on null and filters out non-numeric
			 * values ), and the WP_Date_Query contract. A real value ( a datetime
			 * string, 0 = midnight / 0000, or '' ) is processed. A unary operator
			 * ( IS NULL / IS NOT NULL, opted into with `value => null` ) renders
			 * value-less regardless.
			 */
			$forget_me = ( null === $clause[ 'value' ] ) || ( false === $clause[ 'value' ] );

			if ( ( false !== $operator ) && ( $operator->is_unary() || ! $forget_me ) ) {
				$value_is_operand = \BerlinDB\Database\Operands\Base::is_spec( $clause[ 'value' ] );
					$value        = $clause[ 'value' ];

					/*
					 * Normalize a non-operand value exactly as the former build_value()
					 * did, so the migrated branch stays byte-identical: reindex arrays,
					 * stringify floats, and coerce bool / object / null to null.
					 */
				if ( ! $value_is_operand ) {
					if ( is_array( $value ) ) {
						$value = array_values( $value );
					} elseif ( is_float( $value ) ) {
						$value = (string) $value;
					} elseif ( ! is_int( $value ) && ! is_string( $value ) ) {
						$value = null;
					}
				}

					$expr = $this->build_operand_clause( $lhs, $operator, $value, $value_is_operand );

					/*
					 * Fail the clause closed on a broken operand ( false ) OR a value
					 * that renders nothing ( '' - e.g. an empty IN / BETWEEN list ):
					 * dropping it would silently WIDEN the date filter to every row. A
					 * unary predicate and an ordinary scalar always render non-empty.
					 */
					$where[] = ( ( false === $expr ) || ( '' === $expr ) )
						? '1 = 0'
						: $expr;
			}
		}

		// Hour/Minute/Second.
		if ( isset( $clause[ 'hour' ] ) || isset( $clause[ 'minute' ] ) || isset( $clause[ 'second' ] ) ) {

			// Avoid notices.
			foreach ( array( 'hour', 'minute', 'second' ) as $unit ) {
				if ( ! isset( $clause[ $unit ] ) ) {
					$clause[ $unit ] = null;
				}
			}

			// Time query, delegated to the shared engine ( #211 ).
			$time_query = $this->build_time_expression( $column_name, $compare, $clause[ 'hour' ], $clause[ 'minute' ], $clause[ 'second' ] );

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
	 * Normalize a numeric date-part value for the shared engine, mirroring the former
	 * build_numeric_value(): a null value bails, non-numeric members are dropped, and
	 * the rest are cast to int. Returns the ints as a list ( the caller shapes them per
	 * operator - the whole list for IN/BETWEEN, the first for a scalar compare ), or
	 * null to skip the branch - the "forget me" contract every date-part key already
	 * honors ( a null / non-numeric value was never a filter ). 0 stays a real value.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value The raw date-part value ( scalar or array ).
	 * @return list<int>|null The normalized ints, or null to skip.
	 */
	private function normalize_date_part_value( $value ): ?array {

		// A null value is "forget me" - nothing to compare.
		if ( is_null( $value ) ) {
			return null;
		}

		// Drop non-numeric members ( keeps 0 ); bail if nothing numeric remains.
		$numeric = array_filter( (array) $value, 'is_numeric' );

		if ( empty( $numeric ) ) {
			return null;
		}

		return array_map( 'intval', array_values( $numeric ) );
	}

	/**
	 * Build a date-part comparison ( e.g. `YEAR( column ) = 2024` ) through the shared
	 * operator/operand engine: the extracted part is an allow-listed function operand
	 * over the date column, compared with the normalized numeric value. Replaces the
	 * hand-built `FUNC( col ) {compare} {value}` string ( #211 Date migration, slice 3 ).
	 *
	 * @since 3.1.0
	 *
	 * @param string $func_name   The allow-listed function ( 'YEAR', 'MONTH', ... ).
	 * @param string $column_name The date column name.
	 * @param mixed  $raw_value   The raw date-part value.
	 * @param string $compare     The comparison operator ( already unary-excluded ).
	 * @return string|null The SQL fragment, '1 = 0' on engine failure ( fail closed ),
	 *                     or null to SKIP the branch ( a "forget me" value ).
	 */
	private function build_date_part_expression( string $func_name, string $column_name, $raw_value, string $compare ): ?string {

		/*
		 * A "forget me" / non-numeric value skips the branch entirely ( as
		 * build_numeric_value returning false did ) - this is the ONLY null return, so
		 * the monthnum fallback below keys off exactly that, never an engine failure.
		 */
		$ints = $this->normalize_date_part_value( $raw_value );

		if ( null === $ints ) {
			return null;
		}

		// The date part is an allow-listed function operand over the date column.
		$lhs = $this->caller?->resolve_operand(
			array(
				'operand' => 'func',
				'name'    => $func_name,
				'args'    => array(
					array(
						'operand' => 'column',
						'name'    => $column_name,
					),
				),
			)
		);

		return $this->build_date_comparison( $lhs, $ints, $compare );
	}

	/**
	 * Compare a ( pre-resolved ) date expression operand against normalized numeric
	 * date-part value(s) through the shared engine. Shared by every migrated date-part
	 * branch ( a function LHS ), the ISO day-of-week branch ( a math LHS ), and the
	 * week branch ( a WEEK / DATE_SUB LHS ).
	 *
	 * The value is shaped as build_numeric_value did: a list operator ( IN ) takes the
	 * whole list and a range operator ( BETWEEN ) its bounds, but a scalar operator uses
	 * only the FIRST value. An unresolvable LHS operand or operator is an engine failure
	 * ( fail closed, `1 = 0` ), never a widen.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed      $lhs     The resolved left operand ( or false/null if it failed ).
	 * @param list<int>  $ints    The normalized numeric value(s), never empty.
	 * @param string     $compare The comparison operator.
	 * @return string The SQL fragment, or '1 = 0' on an engine failure.
	 */
	private function build_date_comparison( $lhs, array $ints, string $compare ): string {
		$operator = $this->get_operator( $compare );

		if ( ! ( $lhs instanceof \BerlinDB\Database\Operands\Base ) || ( false === $operator ) ) {
			return '1 = 0';
		}

		$value = ( $operator->is_list() || $operator->is_range() )
			? $ints
			: $ints[0];

		$expr = $this->build_operand_clause( $lhs, $operator, $value, false );

		return ( is_string( $expr ) && ( '' !== $expr ) )
			? $expr
			: '1 = 0';
	}

	/**
	 * Resolve the WEEK() expression for the given week-start, matching the former
	 * build_mysql_week(): `WEEK( col, 1 )` when the week starts Monday, `WEEK( col, 0 )`
	 * for Sunday, and `WEEK( DATE_SUB( col, INTERVAL n DAY ), 0 )` for a mid-week start
	 * ( 2-6 ). Returns the resolved operand, or false/null if it could not be built.
	 *
	 * @since 3.1.0
	 *
	 * @param string $column_name   The date column name.
	 * @param mixed  $start_of_week The 0-6 week-start day.
	 * @return \BerlinDB\Database\Operands\Base|false|null
	 */
	private function build_week_operand( string $column_name, $start_of_week ) {
		$start       = (int) $start_of_week;
		$column_spec = array(
			'operand' => 'column',
			'name'    => $column_name,
		);

		if ( ( $start >= 2 ) && ( $start <= 6 ) ) {

			// Shift the date back by $start days, then WEEK( ..., 0 ).
			$expr = array(
				'operand' => 'func',
				'name'    => 'DATE_SUB',
				'args'    => array(
					$column_spec,
					array(
						'operand' => 'interval',
						'value'   => $start,
						'unit'    => 'DAY',
					),
				),
			);
			$mode = 0;

		} else {
			$expr = $column_spec;
			$mode = ( 1 === $start ) ? 1 : 0;
		}

		return $this->caller?->resolve_operand(
			array(
				'operand' => 'func',
				'name'    => 'WEEK',
				'args'    => array(
					$expr,
					array(
						'operand' => 'value',
						'value'   => $mode,
						'pattern' => '%d',
					),
				),
			)
		);
	}

	/**
	 * Build the hour / minute / second time comparison through the shared engine,
	 * replacing the former build_time_query(). Three shapes, preserved exactly:
	 *
	 *  - A multi-value operator ( IN / NOT IN / BETWEEN ) compares each present unit
	 *    separately ( HOUR / MINUTE / SECOND ), AND-joined.
	 *  - A single unit compares just that part ( e.g. HOUR( col ) = 9 ).
	 *  - Two or more units with a scalar operator compare a zero-padded
	 *    DATE_FORMAT( col, '%H.%i%s' ) against the same-shaped time string, so a whole
	 *    time-of-day orders and matches lexically ( minute must be set - hour+second
	 *    alone is not a supported combination ).
	 *
	 * @since 3.1.0
	 *
	 * @param string $column_name The date column name.
	 * @param string $compare     The comparison operator.
	 * @param mixed  $hour        The hour value, or null.
	 * @param mixed  $minute      The minute value, or null.
	 * @param mixed  $second      The second value, or null.
	 * @return string|null The SQL fragment, or null when nothing to compare.
	 */
	private function build_time_expression( string $column_name, string $compare, $hour, $minute, $second ): ?string {

		// Need at least one unit.
		if ( ! isset( $hour ) && ! isset( $minute ) && ! isset( $second ) ) {
			return null;
		}

		$operator = $this->get_operator( $compare );
		$is_multi = ( false !== $operator ) && ( $operator->is_list() || $operator->is_range() );

		// Multi-value: one comparison per present unit, AND-joined.
		if ( $is_multi ) {
			$parts = array();
			$units = array(
				'HOUR'   => $hour,
				'MINUTE' => $minute,
				'SECOND' => $second,
			);

			foreach ( $units as $func_name => $raw ) {
				if ( ! isset( $raw ) ) {
					continue;
				}

				$expr = $this->build_date_part_expression( $func_name, $column_name, $raw, $compare );

				if ( null !== $expr ) {
					$parts[] = $expr;
				}
			}

			return ! empty( $parts )
				? implode( ' AND ', $parts )
				: null;
		}

		// A single unit is just that date-part comparison.
		if ( isset( $hour ) && ! isset( $minute ) && ! isset( $second ) ) {
			return $this->build_date_part_expression( 'HOUR', $column_name, $hour, $compare );
		}

		if ( ! isset( $hour ) && isset( $minute ) && ! isset( $second ) ) {
			return $this->build_date_part_expression( 'MINUTE', $column_name, $minute, $compare );
		}

		if ( ! isset( $hour ) && ! isset( $minute ) && isset( $second ) ) {
			return $this->build_date_part_expression( 'SECOND', $column_name, $second, $compare );
		}

		// Combined: minute must be set ( hour + second alone is not supported ).
		if ( ! isset( $minute ) ) {
			return null;
		}

		// Build the zero-padded format and time strings ( mirrors build_time_query ).
		if ( null !== $hour ) {
			$format = '%H.';
			$time   = sprintf( '%02d', $hour ) . '.';
		} else {
			$format = '0.';
			$time   = '0.';
		}

		$format .= '%i';
		$time   .= sprintf( '%02d', $minute );

		if ( isset( $second ) ) {
			$format .= '%s';
			$time   .= sprintf( '%02d', $second );
		}

		// DATE_FORMAT( col, format ) {compare} time, all through the shared engine.
		$lhs = $this->caller?->resolve_operand(
			array(
				'operand' => 'func',
				'name'    => 'DATE_FORMAT',
				'args'    => array(
					array(
						'operand' => 'column',
						'name'    => $column_name,
					),
					array(
						'operand' => 'value',
						'value'   => $format,
					),
				),
			)
		);

		if ( ! ( $lhs instanceof \BerlinDB\Database\Operands\Base ) || ( false === $operator ) ) {
			return '1 = 0';
		}

		$expr = $this->build_operand_clause( $lhs, $operator, $time, false );

		return ( is_string( $expr ) && ( '' !== $expr ) )
			? $expr
			: '1 = 0';
	}

	/**
	 * Build an ORDER BY column reference for a '{column}_query' orderby value.
	 *
	 * When a caller passes orderby='{column}_query' (e.g. 'date_created_query'),
	 * this returns the qualified column name so MySQL sorts by the raw datetime
	 * value of that column.
	 *
	 * @since 3.0.0
	 * @internal Query/Parser collaborator API.
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
