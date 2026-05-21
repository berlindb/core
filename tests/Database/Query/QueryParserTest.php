<?php
/**
 * Query parser wiring tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       2.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Parsers\Base as ParserBase;
use BerlinDB\Database\Operators\Base as OperatorBase;
use BerlinDB\Database\Parsers\Meta as MetaParser;
use BerlinDB\Database\Query as BerlinQuery;
use BerlinDB\Tests\Fixtures\TestQuery;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Spy parser used to capture the parser handoff from parse_join_where_parsers().
 *
 * @since 2.1.0
 */
class QueryParserSpy extends ParserBase {

	/** @var string */
	protected $name = 'spy';

	/** @var string|null */
	protected $query_var = 'spy_query';

	/** @var array */
	protected $column_filter = array();

	/** @var string */
	protected $column_suffix = '';

	/** @var mixed */
	protected $default = null;

	/** @var string|null */
	public static $primary_table = null;

	/** @var string|null */
	public static $primary_column = null;

	/** @var string|null */
	public static $type = null;

	/** @var string|null */
	public static $query_alias = null;

	/** @var string|null */
	public static $caller_table_name = null;

	/** @var string|null */
	public static $caller_meta_type = null;

	/**
	 * Reset static state between tests.
	 *
	 * @since 2.1.0
	 */
	public static function reset() {
		self::$primary_table      = null;
		self::$primary_column     = null;
		self::$type               = null;
		self::$query_alias        = null;
		self::$caller_table_name  = null;
		self::$caller_meta_type   = null;
	}

	/**
	 * Use a custom first-order key so the query payload survives sanitization.
	 *
	 * @since 2.1.0
	 *
	 * @param array $first_keys Optional. Ignored.
	 * @return array
	 */
	protected function get_first_keys( $first_keys = array() ) {
		return array( 'probe' );
	}

	/**
	 * Capture the parser inputs and return empty SQL fragments.
	 *
	 * This spy verifies that parse_join_where_parsers() uses the modern approach
	 * of having parsers call $this->caller() to fetch values directly.
	 *
	 * @since 3.0.0
	 *
	 * @return array{join: string, where: string}
	 */
	public function get_join_where_clauses() {

		// Parameters are never passed positionally in 3.0.0+.
		self::$type           = '';
		self::$primary_table  = '';
		self::$primary_column = '';
		self::$query_alias    = $this->queries[0]['alias'] ?? null;

		// Capture values retrieved from caller (the modern approach).
		self::$caller_table_name = $this->caller( 'get_table_name' );
		self::$caller_meta_type  = $this->caller( 'get_meta_type' );

		return array(
			'join'  => '',
			'where' => '',
		);
	}

	/**
	 * Satisfy the abstract parser contract.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $clause       Optional. Unused.
	 * @param array  $parent_query Optional. Unused.
	 * @param string $clause_key   Optional. Unused.
	 * @return array{join: array, where: array}
	 */
	public function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {
		return array(
			'join'  => array(),
			'where' => array(),
		);
	}
}

/**
 * Query fixture that overrides the accessor methods parse_join_where_parsers() now uses.
 *
 * @since 2.1.0
 */
class QueryParserSpyQuery extends TestQuery {

	/** @var string[] */
	protected $query_var_parsers = array( QueryParserSpy::class );

	/**
	 * Avoid running a real query when the fixture is constructed without args.
	 *
	 * @since 2.1.0
	 *
	 * @param array $args Optional. Query args.
	 */
	protected function parse_args( $args = array() ) {
		if ( empty( $args ) ) {
			return;
		}

		parent::parse_args( $args );
	}

	/**
	 * Return a resolved table name that differs from the raw property value.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function get_table_name() {
		return 'resolved_test_widgets';
	}

	/**
	 * Return a resolved table alias that differs from the raw property value.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function get_table_alias() {
		return 'resolved_tw';
	}
}

/**
 * Query fixture for alias sanitization behavior.
 *
 * @since 2.1.0
 */
class QueryParserAliasSpyQuery extends QueryParserSpyQuery {

	/**
	 * Return an alias containing non-word characters for sanitization tests.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function get_table_alias() {
		return 'resolved tw';
	}
}

/**
 * Plain caller stub used to verify Meta parser state is resolved from caller methods.
 *
 * @since 3.0.0
 */
class QueryMetaCallerSpy {

	/**
	 * Return a meta object type used by Meta::get_sql().
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_meta_type() {
		return 'post';
	}

	/**
	 * Return a table name resolved by the caller.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_table_name() {
		return 'resolved_meta_widgets';
	}

	/**
	 * Return a table alias resolved by the caller.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_table_alias() {
		return 'resolved_mw';
	}

	/**
	 * Return a primary column name resolved by the caller.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_primary_column_name() {
		return 'resolved_widget_id';
	}
}

/**
 * Minimal operator used to prove custom operator class registration works.
 *
 * @since 3.0.0
 */
class QueryOperatorSpy extends OperatorBase {

	/** @var string */
	protected $name = 'spy_operator';

	/** @var string */
	protected $compare = 'SPY';

	/** @var bool */
	protected $positive = true;

	/** @var bool */
	protected $multi = false;

	/** @var bool */
	protected $numeric = false;
}

/**
 * Parser fixture used to prove parser class registration works.
 *
 * @since 3.0.0
 */
class QueryParserRegistrySpy extends ParserBase {

	/** @var string */
	protected $name = 'registry_spy';

	/** @var string|null */
	protected $query_var = 'registry_spy';

	/** @var array */
	protected $column_filter = array();

	/** @var string */
	protected $column_suffix = '';

	/** @var mixed */
	protected $default = null;

	/**
	 * Satisfy the abstract parser contract.
	 *
	 * @since 3.0.0
	 *
	 * @param array  $clause       Optional. Unused.
	 * @param array  $parent_query Optional. Unused.
	 * @param string $clause_key   Optional. Unused.
	 * @return array{join: array, where: array}
	 */
	public function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {
		return array(
			'join'  => array(),
			'where' => array(),
		);
	}
}

/**
 * Tests for Query::parse_join_where_parsers().
 *
 * @since 2.1.0
 */
class QueryParserTest extends TestCase {

	/**
	 * Ensure parse_join_where_parsers() no longer threads table metadata through positional args.
	 *
	 * @since 2.1.0
	 */
	public function test_parse_join_where_parsers_uses_caller_methods_for_parser_inputs() {
		$query = new QueryParserSpyQuery();
		QueryParserSpy::reset();

		$method = new \ReflectionMethod( BerlinQuery::class, 'parse_join_where_parsers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$result = $method->invoke(
			$query,
			array(
				'spy_query' => array(
					'probe' => 'value',
				),
			)
		);

		// Legacy positional parameters should be empty (not passed).
		$this->assertSame( '', QueryParserSpy::$type );
		$this->assertSame( '', QueryParserSpy::$primary_table );
		$this->assertSame( '', QueryParserSpy::$primary_column );
		$this->assertSame( 'resolved_tw', QueryParserSpy::$query_alias );

		// Modern approach: Parsers call $this->caller() methods directly.
		$this->assertSame( 'resolved_test_widgets', QueryParserSpy::$caller_table_name );
		$this->assertSame( 'berlindb_database_widget', QueryParserSpy::$caller_meta_type );

		// Result should be the empty fragments returned by the spy.
		$this->assertSame(
			array(
				'join'  => array(),
				'where' => array(),
			),
			$result
		);
	}

	/**
	 * Ensure alias sanitization follows MySQL spec and normalizes underscores.
	 *
	 * @since 2.1.0
	 */
	public function test_parse_join_where_parsers_sanitizes_alias_conservatively() {
		$query = new QueryParserAliasSpyQuery();
		QueryParserSpy::reset();

		$method = new \ReflectionMethod( BerlinQuery::class, 'parse_join_where_parsers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke(
			$query,
			array(
				'spy_query' => array(
					'probe' => 'value',
				),
			)
		);

		// Alias 'resolved tw' should be normalized to 'resolved_tw' per MySQL spec.
		$this->assertSame( 'resolved_tw', QueryParserSpy::$query_alias );
	}

	/**
	 * Ensure alias sanitization normalizes multiple consecutive underscores.
	 *
	 * @since 2.1.0
	 */
	public function test_parse_join_where_parsers_normalizes_alias_underscores() {
		// Create a test query that returns an alias with consecutive underscores.
		$query = new class extends QueryParserSpyQuery {
			public function get_table_alias() {
				return 'resolved__tw___alias';
			}
		};

		QueryParserSpy::reset();

		$method = new \ReflectionMethod( BerlinQuery::class, 'parse_join_where_parsers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$method->invoke(
			$query,
			array(
				'spy_query' => array(
					'probe' => 'value',
				),
			)
		);

		// Multiple underscores should normalize to single underscore.
		$this->assertSame( 'resolved_tw_alias', QueryParserSpy::$query_alias );
	}

	/**
	 * Ensure Meta::get_sql() resolves its table metadata from the caller query.
	 *
	 * @since 3.0.0
	 */
	public function test_meta_get_sql_uses_caller_methods_for_table_resolution() {
		$query  = new QueryMetaCallerSpy();
		$parser = new MetaParser();

		$parser->init( array(), $query );
		$result = $parser->get_sql();

		$this->assertIsArray( $result );
		$this->assertSame( _get_meta_table( 'post' ), $parser->meta_table );
		$this->assertSame( 'post_id', $parser->meta_column );
		$this->assertSame( 'resolved_meta_widgets', $parser->primary_table );
		$this->assertSame( 'resolved_widget_id', $parser->primary_column );
	}

	/**
	 * Ensure query parser classes can be extended through the registration hook.
	 *
	 * @since 3.0.0
	 */
	public function test_query_var_parsers_can_be_registered_via_filter() {
		$filter = function( $classes, $query ) {
			$this->assertInstanceOf( BerlinQuery::class, $query );

			return array( QueryParserRegistrySpy::class );
		};

		add_filter( 'berlindb_database_query_var_parsers', $filter, 10, 2 );

		try {
			$query = new class extends TestQuery {
				protected function parse_args( $args = array() ) {
					if ( empty( $args ) ) {
						return;
					}

					parent::parse_args( $args );
				}
			};

			$parser_classes = new \ReflectionProperty( BerlinQuery::class, 'query_var_parsers' );
			if ( PHP_VERSION_ID < 80100 ) {
				$parser_classes->setAccessible( true );
			}

			$this->assertSame( array( QueryParserRegistrySpy::class ), $parser_classes->getValue( $query ) );

			$parsers = new \ReflectionProperty( BerlinQuery::class, 'parsers' );
			if ( PHP_VERSION_ID < 80100 ) {
				$parsers->setAccessible( true );
			}

			$this->assertArrayHasKey( 'registry_spy', $parsers->getValue( $query ) );
		} finally {
			remove_filter( 'berlindb_database_query_var_parsers', $filter, 10 );
		}
	}

	/**
	 * Ensure parser operator classes can be extended through the registration hook.
	 *
	 * @since 3.0.0
	 */
	public function test_operator_classes_can_be_registered_via_filter() {
		$filter = function( $classes, $parser ) {
			$this->assertInstanceOf( ParserBase::class, $parser );

			return array( QueryOperatorSpy::class );
		};

		add_filter( 'berlindb_database_operator_classes', $filter, 10, 2 );

		try {
			$parser = new QueryOperatorSpyParser();

			$this->assertCount( 1, $parser->operators );
			$this->assertInstanceOf( QueryOperatorSpy::class, $parser->operators[0] );
			$this->assertSame( 'spy_operator', $parser->operators[0]->name );
			$this->assertSame( 'SPY', $parser->operators[0]->compare );
		} finally {
			remove_filter( 'berlindb_database_operator_classes', $filter, 10 );
		}
	}
}

/**
 * Parser fixture used to exercise the operator registration hook.
 *
 * @since 3.0.0
 */
class QueryOperatorSpyParser extends ParserBase {

	/** @var string */
	protected $name = 'operator_spy';

	/** @var string|null */
	protected $query_var = 'operator_spy';

	/** @var array */
	protected $column_filter = array();

	/** @var string */
	protected $column_suffix = '';

	/** @var mixed */
	protected $default = null;

	/**
	 * Satisfy the abstract parser contract.
	 *
	 * @since 3.0.0
	 *
	 * @param array  $clause       Optional. Unused.
	 * @param array  $parent_query Optional. Unused.
	 * @param string $clause_key   Optional. Unused.
	 * @return array{join: array, where: array}
	 */
	public function get_sql_for_clause( &$clause = array(), $parent_query = array(), $clause_key = '' ) {
		return array(
			'join'  => array(),
			'where' => array(),
		);
	}
}