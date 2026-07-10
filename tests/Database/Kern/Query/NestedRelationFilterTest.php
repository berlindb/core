<?php
/**
 * Nested-relationship filtering, end to end (#211 Lever D).
 *
 * order -> customer -> region. A `relation` clause whose `relation` key names a
 * further relationship filters two hops out via a nested correlated EXISTS.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Row;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/** Country: the far end of a THREE-hop chain (order -> customer -> region -> country). */
class NrfCountrySchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'    => 'code',
			'type'    => 'varchar',
			'length'  => '20',
			'default' => '',
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Region: belongs_to a country; the far-middle hop of the three-hop chain. */
class NrfRegionSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'    => 'code',
			'type'    => 'varchar',
			'length'  => '20',
			'default' => '',
		),
		array(
			'name'          => 'country_id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'default'       => 0,
			'relationships' => array(
				array(
					'name'   => 'country',
					'query'  => NrfCountryQuery::class,
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Customer: belongs_to a region; the middle hop. */
class NrfCustomerSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'          => 'region_id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'default'       => 0,
			'relationships' => array(
				array(
					'name'   => 'region',
					'query'  => NrfRegionQuery::class,
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
		),
		array(
			'name'    => 'status',
			'type'    => 'varchar',
			'length'  => '20',
			'default' => '',
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

/** Order: belongs_to a customer; the near end. */
class NrfOrderSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'cache_key' => true,
		),
		array(
			'name'          => 'customer_id',
			'type'          => 'bigint',
			'length'        => '20',
			'unsigned'      => true,
			'default'       => 0,
			'relationships' => array(
				array(
					'name'   => 'customer',
					'query'  => NrfCustomerQuery::class,
					'column' => 'id',
					'type'   => 'belongs_to',
				),
			),
		),
	);
	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
	);
}

class NrfCountryRow extends Row {
	public $id   = 0;
	public $code = '';
}
class NrfRegionRow extends Row {
	public $id         = 0;
	public $code       = '';
	public $country_id = 0;
}
class NrfCustomerRow extends Row {
	public $id        = 0;
	public $region_id = 0;
	public $status    = '';
}
class NrfOrderRow extends Row {
	public $id          = 0;
	public $customer_id = 0;
}

class NrfCountryQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'nrf_country_test';
	protected $table_alias      = 'nrfn';
	protected $table_schema     = NrfCountrySchema::class;
	protected $item_name        = 'nrf_country';
	protected $item_name_plural = 'nrf_countries';
	protected $item_shape       = NrfCountryRow::class;
	protected $cache_group      = 'berlindb-nrf-country';
}
class NrfRegionQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'nrf_region_test';
	protected $table_alias      = 'nrfr';
	protected $table_schema     = NrfRegionSchema::class;
	protected $item_name        = 'nrf_region';
	protected $item_name_plural = 'nrf_regions';
	protected $item_shape       = NrfRegionRow::class;
	protected $cache_group      = 'berlindb-nrf-region';
}
class NrfCustomerQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'nrf_customer_test';
	protected $table_alias      = 'nrfc';
	protected $table_schema     = NrfCustomerSchema::class;
	protected $item_name        = 'nrf_customer';
	protected $item_name_plural = 'nrf_customers';
	protected $item_shape       = NrfCustomerRow::class;
	protected $cache_group      = 'berlindb-nrf-customer';
}
class NrfOrderQuery extends Query {
	protected $prefix           = 'berlindb';
	protected $table_name       = 'nrf_order_test';
	protected $table_alias      = 'nrfo';
	protected $table_schema     = NrfOrderSchema::class;
	protected $item_name        = 'nrf_order';
	protected $item_name_plural = 'nrf_orders';
	protected $item_shape       = NrfOrderRow::class;
	protected $cache_group      = 'berlindb-nrf-order';
}

class NrfCountryTable extends Table {
	protected $schema  = NrfCountrySchema::class;
	protected $name    = 'berlindb_nrf_country_test';
	protected $version = '202607090';
}
class NrfRegionTable extends Table {
	protected $schema  = NrfRegionSchema::class;
	protected $name    = 'berlindb_nrf_region_test';
	protected $version = '202607090';
}
class NrfCustomerTable extends Table {
	protected $schema  = NrfCustomerSchema::class;
	protected $name    = 'berlindb_nrf_customer_test';
	protected $version = '202607090';
}
class NrfOrderTable extends Table {
	protected $schema  = NrfOrderSchema::class;
	protected $name    = 'berlindb_nrf_order_test';
	protected $version = '202607090';
}

/**
 * End-to-end nested-relationship filtering (two and three hops out).
 *
 * @since 3.1.0
 */
class NestedRelationFilterTest extends TestCase {

	/** @var NrfCountryTable */
	private static $country_table;

	/** @var NrfRegionTable */
	private static $region_table;

	/** @var NrfCustomerTable */
	private static $customer_table;

	/** @var NrfOrderTable */
	private static $order_table;

	/** @var NrfCountryQuery */
	private static $countries;

	/** @var NrfRegionQuery */
	private static $regions;

	/** @var NrfCustomerQuery */
	private static $customers;

	/** @var NrfOrderQuery */
	private static $orders;

	/**
	 * Install the four tables.
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$country_table = new NrfCountryTable();
		if ( ! self::$country_table->exists() ) {
			self::$country_table->install();
		}
		self::$region_table = new NrfRegionTable();
		if ( ! self::$region_table->exists() ) {
			self::$region_table->install();
		}
		self::$customer_table = new NrfCustomerTable();
		if ( ! self::$customer_table->exists() ) {
			self::$customer_table->install();
		}
		self::$order_table = new NrfOrderTable();
		if ( ! self::$order_table->exists() ) {
			self::$order_table->install();
		}

		self::$countries = new NrfCountryQuery();
		self::$regions   = new NrfRegionQuery();
		self::$customers = new NrfCustomerQuery();
		self::$orders    = new NrfOrderQuery();
	}

	/**
	 * Reset rows before each test.
	 *
	 * @since 3.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		self::$order_table->delete_all();
		self::$customer_table->delete_all();
		self::$region_table->delete_all();
		self::$country_table->delete_all();
		wp_cache_flush();
	}

	/**
	 * Drop the tables after the class.
	 *
	 * @since 3.1.0
	 */
	public static function tearDownAfterClass(): void {
		self::$order_table->uninstall();
		self::$customer_table->uninstall();
		self::$region_table->uninstall();
		self::$country_table->uninstall();
		parent::tearDownAfterClass();
	}

	/**
	 * Seed: EU/active customer, US/active customer, EU/inactive customer, each with
	 * one order. Each region belongs_to a country (EU -> EUR, US -> USA) so the
	 * three-hop chain (order -> customer -> region -> country) has data to match.
	 * Returns the three order ids keyed by their customer's shape.
	 *
	 * @since 3.1.0
	 *
	 * @return array{eu_active:int,us_active:int,eu_inactive:int}
	 */
	private function seed(): array {
		$eur = self::$countries->add_item( array( 'code' => 'EUR' ) );
		$usa = self::$countries->add_item( array( 'code' => 'USA' ) );

		$eu = self::$regions->add_item(
			array(
				'code'       => 'EU',
				'country_id' => $eur,
			)
		);
		$us = self::$regions->add_item(
			array(
				'code'       => 'US',
				'country_id' => $usa,
			)
		);

		$cust_eu_active   = self::$customers->add_item(
			array(
				'region_id' => $eu,
				'status'    => 'active',
			)
		);
		$cust_us_active   = self::$customers->add_item(
			array(
				'region_id' => $us,
				'status'    => 'active',
			)
		);
		$cust_eu_inactive = self::$customers->add_item(
			array(
				'region_id' => $eu,
				'status'    => 'inactive',
			)
		);

		return array(
			'eu_active'   => (int) self::$orders->add_item( array( 'customer_id' => $cust_eu_active ) ),
			'us_active'   => (int) self::$orders->add_item( array( 'customer_id' => $cust_us_active ) ),
			'eu_inactive' => (int) self::$orders->add_item( array( 'customer_id' => $cust_eu_inactive ) ),
		);
	}

	/**
	 * A two-hop filter (order -> customer -> region) matches on the far column.
	 *
	 * @since 3.1.0
	 */
	public function test_filters_two_hops_out() {
		$o = $this->seed();

		$ids = self::$orders->query(
			array(
				'relation' => array(
					'name'     => 'customer',
					'relation' => array(
						'name'  => 'region',
						'where' => array( 'code' => 'EU' ),
					),
				),
				'fields'   => 'ids',
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		// Both EU-region customers' orders, regardless of status.
		$this->assertSame(
			array( $o['eu_active'], $o['eu_inactive'] ),
			array_map( 'intval', $ids )
		);
	}

	/**
	 * Conditions apply at every hop: the middle `where` AND the far `where`.
	 *
	 * @since 3.1.0
	 */
	public function test_conditions_at_each_hop() {
		$o = $this->seed();

		$ids = self::$orders->query(
			array(
				'relation' => array(
					'name'     => 'customer',
					'where'    => array( 'status' => 'active' ),
					'relation' => array(
						'name'  => 'region',
						'where' => array( 'code' => 'EU' ),
					),
				),
				'fields'   => 'ids',
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		// Active AND EU: only the eu_active order (eu_inactive fails the status hop).
		$this->assertSame( array( $o['eu_active'] ), array_map( 'intval', $ids ) );
	}

	/**
	 * `exists => false` at the nested level negates that hop (NOT EXISTS).
	 *
	 * @since 3.1.0
	 */
	public function test_anti_at_nested_level() {
		$o = $this->seed();

		$ids = self::$orders->query(
			array(
				'relation' => array(
					'name'     => 'customer',
					'relation' => array(
						'name'   => 'region',
						'where'  => array( 'code' => 'EU' ),
						'exists' => false,
					),
				),
				'fields'   => 'ids',
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		// Customers whose region is NOT EU: only the US customer's order.
		$this->assertSame( array( $o['us_active'] ), array_map( 'intval', $ids ) );
	}

	/**
	 * An unknown nested relationship fails the whole clause closed (no rows).
	 *
	 * @since 3.1.0
	 */
	public function test_unknown_nested_relationship_fails_closed() {
		$this->seed();

		$ids = self::$orders->query(
			array(
				'relation' => array(
					'name'     => 'customer',
					'relation' => array(
						'name'  => 'nonexistent',
						'where' => array( 'code' => 'EU' ),
					),
				),
				'fields'   => 'ids',
			)
		);

		$this->assertSame( array(), $ids );
	}

	/**
	 * A three-hop filter (order -> customer -> region -> country) matches on the
	 * far column, proving the correlated-EXISTS recursion terminates below depth 2.
	 *
	 * This is the depth regression guard: an earlier defaults-inheritance bug in
	 * sanitize_query made a nested `relation` array recurse without bound (OOM),
	 * so a chain this deep is exactly what must stay finite. See the fix in
	 * Traits\Parser::sanitize_query().
	 *
	 * @since 3.1.0
	 */
	public function test_filters_three_hops_out() {
		$o = $this->seed();

		$ids = self::$orders->query(
			array(
				'relation' => array(
					'name'     => 'customer',
					'relation' => array(
						'name'     => 'region',
						'relation' => array(
							'name'  => 'country',
							'where' => array( 'code' => 'EUR' ),
						),
					),
				),
				'fields'   => 'ids',
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		// Every order whose customer's region is in the EUR country: both EU orders.
		$this->assertSame(
			array( $o['eu_active'], $o['eu_inactive'] ),
			array_map( 'intval', $ids )
		);
	}

	/**
	 * Conditions apply at all three hops: the middle `where` (customer status) AND
	 * the far `where` (country code) both constrain the result.
	 *
	 * @since 3.1.0
	 */
	public function test_conditions_at_three_hops() {
		$o = $this->seed();

		$ids = self::$orders->query(
			array(
				'relation' => array(
					'name'     => 'customer',
					'where'    => array( 'status' => 'active' ),
					'relation' => array(
						'name'     => 'region',
						'relation' => array(
							'name'  => 'country',
							'where' => array( 'code' => 'EUR' ),
						),
					),
				),
				'fields'   => 'ids',
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		// EUR country AND active customer: only the eu_active order.
		$this->assertSame( array( $o['eu_active'] ), array_map( 'intval', $ids ) );
	}
}
