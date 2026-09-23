# Upgrading from BerlinDB 3.0.x to 3.1.0

BerlinDB 3.1.0 is not released yet. This guide covers changes that may require
consumer code or schema declarations to change. The [3.1.0 changelog](../CHANGELOG.md#310---unreleased)
has the full list of additions and fixes.

## Subclasses and method signatures

PHP checks overridden method declarations when a subclass loads. An old override
of any method below can cause a fatal before the plugin runs:

| 3.0.x extension | 3.1.0 action |
| --- | --- |
| `Boot` or `Query::parse_args( $args = array() )` construction hook | Rename the override to `consume_args( array $args = array() ): void`. The old `parse_args()` name now belongs to an array-parsing helper with an optional `$defaults` argument and an `array` return type. |
| `Column::is_json()`, `is_bool()`, `is_date_time()`, `is_int()`, `is_decimal()`, `is_text()`, or `is_binary()` | Add the optional `$type = ''` parameter to any override. A call without an argument still checks the column's own type. |
| `Column::get_name_sql( string $alias = '' ): string` | Add the optional `string $cast = ''` parameter to any override. The method now renders an explicit SQL cast when requested. |

For a `Query` subclass that used the old construction hook, move that work to
`consume_args()` and call `parent::consume_args( $args )` when the query should
run. Query setup now happens in `init()` after configuration is applied, rather
than in `sunrise()`. Move setup-dependent `sunrise()` overrides to `init()` and
call `parent::init()` first. An `init()` override that expected constructor query
results must instead run after `parent::consume_args()` or in `sunset()`.

The released `Schema::get_create_table_string()` signature remains unchanged.
The 3.0.x comparison-operator class names under
`BerlinDB\Database\Operators\*` remain available as aliases for the moved
`BerlinDB\Database\Operators\Comparisons\*` classes. Custom operators that
customize rendering of explicit casts should override `get_sql_with_cast()`.

Some `Parser`, `Column`, and `Query` helper methods introduced in 3.0.0 are now
`protected`. For example, `Column::validate_json()`, `validate_null()`,
`validate_numeric()`, and `validate_int()` are no longer public. Code calling
these helpers from outside a subclass needs to use the public API instead.
The older datetime, decimal, and UUID Column validators remain public.

## Configuration and schemas

- Construction is strict by default: unknown configuration keys are logged and
  dropped. A subclass that accepts a custom key must include it in
  `get_config_callbacks()`; merely declaring a property does not register it.
  Override `is_strict_config()` to return `false` only for classes that
  deliberately accept dynamic configuration, as `Row` does for data columns.
- `Query::get_columns()` now reads columns from its schema object, not from a
  `$columns` property on the Query subclass. Move inline column definitions
  into a `Schema`. Released Schema subclasses with only `get_columns()` still
  work; `get_filtered_columns()` is the new filtering accessor.
- A non-primary index named `primary` is invalid. Use another name; the
  `primary` name is reserved for the primary-key alias.
- Review generated DDL before upgrading existing tables. The schema now
  derives indexes for applicable `primary`, `unique`, `index`, `uuid`,
  `belongs_to`, and `cache_key` declarations. A `created` or `modified` column
  without a date-bearing type now defaults to `datetime`. An explicit column
  name on a preset is respected rather than replaced by the preset default.
  Declare an explicit `UNIQUE` index if a UUID must be unique in the database;
  the derived UUID lookup index is not unique.
- A `Table` version bump with no pending custom upgrade callback now adds
  missing declared columns and indexes automatically. Check the declared Schema
  before bumping its version. Set `reconcile => false` on a Table to keep the
  old version-only behavior; use `true` to opt into modifications, or an
  explicit operations list to opt into drops. An incomplete schema snapshot
  defers the version bump so the upgrade can retry.
- Temporal columns declared with `CURRENT_TIMESTAMP` now emit the unquoted SQL
  function, and empty values can defer to MySQL's default or `ON UPDATE`
  behavior. Nullable datetimes with a `null` default now store SQL `NULL` for
  empty or invalid input; non-null datetimes without an explicit default use
  BerlinDB's zero-date fallback. Review these declarations and generated DDL
  before running a table upgrade.

## Query and metadata behavior

- A nested `meta_query` or `date_query` subgroup without its own `relation`
  now combines its clauses with `AND`, even under an `OR` parent. Add an
  explicit `'relation' => 'OR'` if that subgroup really requires OR.
- Reserved query controls such as `count` and `order` take precedence over
  same-named columns. Filter a colliding column through its `{column}__in`
  shorthand or the `by` container. Parser-backed predicates with unresolvable
  or misdeclared columns now fail closed; unknown `by` entries are logged and
  ignored.
- `add_item()` and `copy_item()` can return a string primary key, and deletion
  and transition hooks now receive the actual `int|string` item ID. Remove
  assumptions that every ID is an integer when using string or UUID keys.
- A successful meta-only `update_item()` now returns `true`. An empty key in
  `get_item_meta()` retrieves all item meta. The documented
  `delete_item_meta( $id, $key, '', true )` call now deletes that key across
  every object, so audit calls that pass `true` for the final argument.
- Invalid relationship declarations are dropped with a warning. Use
  `Schema::get_validation_errors()` and `Query::get_relationship_errors()` to
  inspect local and remote declarations during an upgrade.

Before deploying, load each custom subclass under 3.1.0 to catch declaration
errors, run the plugin's tests, and inspect generated table DDL and queries
where the changes above apply.
