<?php
/**
 * Tests for Query::sunrise() property-initialisation methods.
 *
 * Covers the three private setters introduced in 3.1.0 that run before
 * set_prefixes() to guarantee $table_name and $cache_group are never empty:
 *
 *   set_table_name()   – auto-derives $table_name from $item_name_plural or
 *                        the short class name when the subclass leaves it blank.
 *   set_cache_group()  – auto-derives $cache_group from $item_name_plural or
 *                        the already-resolved $table_name, ensuring
 *                        apply_prefix('', '-') can never produce the bare
 *                        "prefix-" string that caused cache-group collisions
 *                        (berlindb/core discussions #114).
 *
 * Because the methods are private they cannot be called directly. Their effects
 * are verified by reading the protected $table_name, $table_alias, and
 * $cache_group properties via ReflectionProperty after construction.
 *
 * Constructing a Query subclass without arguments triggers sunrise() but does
 * NOT execute a database query — parse_args() returns early on empty input —
 * so no live table is required for these tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Kern\Query;
use BerlinDB\Tests\Fixtures\TestSchema;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Fixture subclasses — defined here so static::class resolves predictably.
// ---------------------------------------------------------------------------

/**
 * All three key properties are set explicitly; sunrise() must not overwrite
 * any of them.
 */
class SunriseExplicitQuery extends Query {
	protected $prefix       = 'myapp';
	protected $table_name   = 'orders';
	protected $table_alias  = 'o';
	protected $table_schema = TestSchema::class;
	protected $cache_group  = 'orders';
}

/**
 * Only $item_name_plural is provided; $table_name and $cache_group are empty
 * and must be derived from the plural.
 */
class SunrisePluralDerivedQuery extends Query {
	protected $prefix           = 'myapp';
	protected $table_schema     = TestSchema::class;
	protected $item_name_plural = 'customers';
	// $table_name and $cache_group intentionally left as '' (default).
}

/**
 * $item_name_plural contains hyphens.  $table_name must convert them to
 * underscores; $cache_group must keep hyphens.
 */
class SunriseHyphenPluralQuery extends Query {
	protected $prefix           = 'myapp';
	protected $table_schema     = TestSchema::class;
	protected $item_name_plural = 'order-items';
}

/**
 * $item_name_plural contains underscores.  $cache_group must convert them to
 * hyphens; $table_name must keep underscores.
 */
class SunriseUnderscorePluralQuery extends Query {
	protected $prefix           = 'myapp';
	protected $table_schema     = TestSchema::class;
	protected $item_name_plural = 'order_items';
}

/**
 * $item_name_plural is explicitly blank AND $table_name is blank, so both
 * must be derived from the class name.
 *
 * Expected derivation path (sunrise order):
 *   set_table_name():  'BerlinDB\Tests\SunriseClassNameQuery'
 *                      → short  = 'SunriseClassNameQuery'
 *                      → strip  = 'SunriseClassName'
 *                      → snake  = 'sunrise_class_name'
 *   set_cache_group(): item_name_plural empty → fallback to table_name
 *                      'sunrise_class_name' → 'sunrise-class-name'
 *   set_prefixes():    prefix 'cn' applied
 *                      table_name  = 'cn_sunrise_class_name'
 *                      cache_group = 'cn-sunrise-class-name'
 */
class SunriseClassNameQuery extends Query {
	protected $prefix           = 'cn';
	protected $table_schema     = TestSchema::class;
	protected $item_name_plural = ''; // Override the default 'items'.
}

/**
 * No $prefix set — verifies that the derivation still works and that the
 * absence of a prefix does not corrupt the derived values.
 */
class SunriseNoPrefixQuery extends Query {
	protected $table_schema     = TestSchema::class;
	protected $item_name_plural = 'events';
}

/**
 * Pair A for the anti-collision regression test.  Same prefix as B.
 */
class SunriseCollisionAQuery extends Query {
	protected $prefix           = 'collision';
	protected $table_schema     = TestSchema::class;
	protected $item_name_plural = 'orders';
}

/**
 * Pair B for the anti-collision regression test.  Same prefix as A.
 */
class SunriseCollisionBQuery extends Query {
	protected $prefix           = 'collision';
	protected $table_schema     = TestSchema::class;
	protected $item_name_plural = 'customers';
}

// ---------------------------------------------------------------------------
// Test class.
// ---------------------------------------------------------------------------

/**
 * Tests for the sunrise() property-initialisation methods introduced in 3.1.0.
 *
 * @since 3.1.0
 */
class QuerySunriseTest extends TestCase {

	// =========================================================================
	// Helpers.
	// =========================================================================

	/**
	 * Read a protected property from a Query subclass via Reflection.
	 *
	 * @since 3.1.0
	 *
	 * @param object $object   The query instance.
	 * @param string $property Property name (e.g. 'table_name').
	 * @return mixed The property value.
	 */
	private function prop( Query $object, string $property ): mixed {
		$ref = new \ReflectionProperty( $object, $property );
		return $ref->getValue( $object );
	}

	// =========================================================================
	// set_table_name() tests.
	// =========================================================================

	/**
	 * An explicitly-set $table_name must survive sunrise() unchanged (modulo
	 * the prefix that set_prefixes() applies afterwards).
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_table_name_is_prefixed_not_overwritten(): void {
		$query = new SunriseExplicitQuery();

		// 'myapp' + '_' + 'orders' — set_table_name() must not replace it.
		$this->assertSame( 'myapp_orders', $this->prop( $query, 'table_name' ) );
	}

	/**
	 * When $table_name is empty and $item_name_plural is set, set_table_name()
	 * must fill $table_name with the plural (hyphens→underscores).
	 *
	 * @since 3.1.0
	 */
	public function test_table_name_derived_from_item_name_plural(): void {
		$query = new SunrisePluralDerivedQuery();

		// 'myapp' + '_' + 'customers'
		$this->assertSame( 'myapp_customers', $this->prop( $query, 'table_name' ) );
	}

	/**
	 * Hyphens in $item_name_plural must become underscores in $table_name so
	 * the value is a valid MySQL identifier.
	 *
	 * @since 3.1.0
	 */
	public function test_table_name_converts_hyphens_to_underscores(): void {
		$query = new SunriseHyphenPluralQuery();

		// item_name_plural = 'order-items' → table fragment = 'order_items'
		$this->assertSame( 'myapp_order_items', $this->prop( $query, 'table_name' ) );
	}

	/**
	 * When both $table_name and $item_name_plural are empty, set_table_name()
	 * must derive a snake_case name from the short class name, stripping a
	 * trailing "Query" suffix.
	 *
	 * SunriseClassNameQuery  →  SunriseClassName  →  sunrise_class_name
	 *
	 * @since 3.1.0
	 */
	public function test_table_name_derived_from_class_name_strips_query_suffix(): void {
		$query = new SunriseClassNameQuery();

		// prefix 'cn' + '_' + 'sunrise_class_name'
		$this->assertSame( 'cn_sunrise_class_name', $this->prop( $query, 'table_name' ) );

		// Confirm the raw "Query" suffix is absent from the derived fragment.
		$this->assertStringNotContainsStringIgnoringCase(
			'query',
			str_replace( 'cn_', '', $this->prop( $query, 'table_name' ) ),
			'set_table_name() must strip the trailing Query suffix from the class name.'
		);
	}

	// =========================================================================
	// set_cache_group() tests.
	// =========================================================================

	/**
	 * An explicitly-set $cache_group must survive sunrise() unchanged (modulo
	 * the prefix applied by set_prefixes()).
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_cache_group_is_prefixed_not_overwritten(): void {
		$query = new SunriseExplicitQuery();

		// 'myapp' + '-' + 'orders'
		$this->assertSame( 'myapp-orders', $this->prop( $query, 'cache_group' ) );
	}

	/**
	 * When $cache_group is empty and $item_name_plural is set, set_cache_group()
	 * must fill $cache_group with the plural (underscores→hyphens).
	 *
	 * @since 3.1.0
	 */
	public function test_cache_group_derived_from_item_name_plural(): void {
		$query = new SunrisePluralDerivedQuery();

		// 'myapp' + '-' + 'customers'
		$this->assertSame( 'myapp-customers', $this->prop( $query, 'cache_group' ) );
	}

	/**
	 * Underscores in $item_name_plural must become hyphens in $cache_group so
	 * the group name is human-readable and consistent with WordPress cache
	 * group naming conventions.
	 *
	 * @since 3.1.0
	 */
	public function test_cache_group_converts_underscores_to_hyphens(): void {
		$query = new SunriseUnderscorePluralQuery();

		// item_name_plural = 'order_items' → cache fragment = 'order-items'
		$this->assertSame( 'myapp-order-items', $this->prop( $query, 'cache_group' ) );
	}

	/**
	 * Hyphens in $item_name_plural must be kept as hyphens in $cache_group —
	 * the symmetric counterpart to the underscore→hyphen conversion test above.
	 *
	 * @since 3.1.0
	 */
	public function test_cache_group_keeps_hyphens_from_item_name_plural(): void {
		$query = new SunriseHyphenPluralQuery();

		// item_name_plural = 'order-items' → cache fragment = 'order-items' (unchanged)
		$this->assertSame( 'myapp-order-items', $this->prop( $query, 'cache_group' ) );
	}

	/**
	 * When both $cache_group and $item_name_plural are empty, set_cache_group()
	 * must fall back to the already-resolved $table_name (underscores→hyphens).
	 *
	 * This path is exercised by SunriseClassNameQuery which overrides
	 * $item_name_plural to '' and leaves $table_name empty.
	 *
	 * @since 3.1.0
	 */
	public function test_cache_group_falls_back_to_table_name_when_plural_empty(): void {
		$query = new SunriseClassNameQuery();

		// table fragment = 'sunrise_class_name' → cache fragment = 'sunrise-class-name'
		$this->assertSame( 'cn-sunrise-class-name', $this->prop( $query, 'cache_group' ) );
	}

	/**
	 * Regression: setting $prefix without $cache_group must never produce the
	 * bare "prefix-" string that caused all Query subclasses sharing a prefix
	 * to write into the same cache bucket (discussions #114).
	 *
	 * The cache group must be non-empty and must not end with a lone hyphen.
	 *
	 * @since 3.1.0
	 */
	public function test_cache_group_is_never_bare_prefix_with_trailing_hyphen(): void {
		$fixtures = array(
			new SunrisePluralDerivedQuery(),
			new SunriseHyphenPluralQuery(),
			new SunriseUnderscorePluralQuery(),
			new SunriseClassNameQuery(),
			new SunriseNoPrefixQuery(),
		);

		foreach ( $fixtures as $query ) {
			$group = $this->prop( $query, 'cache_group' );
			$class = get_class( $query );

			$this->assertNotEmpty( $group, "{$class}: cache_group must never be empty." );
			$this->assertStringEndsNotWith(
				'-',
				$group,
				"{$class}: cache_group must not end with a lone hyphen (the pre-3.1.0 collision pattern)."
			);
		}
	}

	/**
	 * Two subclasses that share the same $prefix but have different
	 * $item_name_plural values must receive different $cache_group values after
	 * sunrise() — the anti-collision guarantee.
	 *
	 * @since 3.1.0
	 */
	public function test_different_subclasses_same_prefix_get_unique_cache_groups(): void {
		$a = new SunriseCollisionAQuery();
		$b = new SunriseCollisionBQuery();

		$group_a = $this->prop( $a, 'cache_group' );
		$group_b = $this->prop( $b, 'cache_group' );

		$this->assertSame( 'collision-orders', $group_a );
		$this->assertSame( 'collision-customers', $group_b );
		$this->assertNotSame(
			$group_a,
			$group_b,
			'Two Query subclasses with the same prefix must not share a cache group.'
		);
	}

	// =========================================================================
	// set_table_alias() interaction tests.
	// =========================================================================

	/**
	 * An explicitly-set $table_alias must survive sunrise() unchanged (modulo
	 * the prefix applied by set_prefixes()).
	 *
	 * @since 3.1.0
	 */
	public function test_explicit_table_alias_is_prefixed_not_overwritten(): void {
		$query = new SunriseExplicitQuery();

		// prefix='myapp', explicit alias='o' → 'myapp_o'
		$this->assertSame( 'myapp_o', $this->prop( $query, 'table_alias' ) );
	}

	/**
	 * When $table_alias is empty, set_table_alias() must derive it from the
	 * first letters of $table_name (which has already been resolved by
	 * set_table_name() at that point).
	 *
	 * @since 3.1.0
	 */
	public function test_table_alias_derived_from_resolved_table_name(): void {
		$query = new SunrisePluralDerivedQuery();

		// table_name resolved to 'customers' → first_letters() = 'c' → prefixed 'myapp_c'.
		$this->assertSame( 'myapp_c', $this->prop( $query, 'table_alias' ) );
	}

	// =========================================================================
	// No-prefix edge case.
	// =========================================================================

	/**
	 * When no $prefix is set, the derived values must equal the plural directly
	 * (no prefix applied, no trailing separator).
	 *
	 * @since 3.1.0
	 */
	public function test_derived_values_without_prefix(): void {
		$query = new SunriseNoPrefixQuery();

		$this->assertSame( 'events', $this->prop( $query, 'table_name' ) );
		$this->assertSame( 'events', $this->prop( $query, 'cache_group' ) );
	}
}
