# Changelog

Notable changes to BerlinDB are documented here.

## 3.1.0 - Unreleased

- Adds first-class `Relationship` objects (`belongs_to`/`has_many`), wired through
  `Column` and `Schema`, with a Query-level relationship API (#193).
- Adds relationship-filtered queries — `'in'` (subquery) and `'join'`
  (EXISTS / NOT EXISTS) strategies in both directions, recursive nested AND/OR
  subgroups, and an opt-in LEFT OUTER JOIN for `belongs_to`.
- Adds relationship cache priming via `with` for `belongs_to` and `has_many`, and
  opt-in enforced FOREIGN KEY DDL; fails closed on malformed or unresolvable clauses.
- Adds opt-in typed `CAST` in comparisons (e.g. `AS SIGNED` / `DATETIME`),
  sanitized at the boundary and fail-closed on invalid casts. A single shared
  resolver applies the `cast` clause key to both relationship `where` conditions
  and `compare_query` clauses; absent `cast` is unchanged (never cast by default).
- Adds opt-in expression operands on the right-hand side of a comparison, starting
  with column-to-column (`array( 'operand' => 'column', 'name' => 'other_col' )`)
  on the scalar comparison operators (`=`, `!=`, `<`, `<=`, `>`, `>=`) — in both
  `compare_query` and relationship `where` clauses. Operators opt in via an
  `is_expression()` descriptor, so a structured operand on an unsupported operator
  (or an unknown referenced column) fails the clause closed. A plain scalar/list
  value is unchanged. The right-hand operand may also be an allow-listed SQL
  function wrapping recursive arguments (column / literal / nested function), e.g.
  `array( 'operand' => 'func', 'name' => 'LOWER', 'args' => array( array( 'operand' => 'column', 'name' => 'name' ) ) )`;
  only listed functions with a matching arity and argument kinds are permitted —
  there is no arbitrary-function or raw-SQL passthrough. The same operands also
  apply to the LEFT side via the clause `key` (e.g. `'key' => array( 'operand' =>
  'func', 'name' => 'LOWER', ... )` for `LOWER(name) = …`), so functions/columns
  can wrap either side of a comparison; a bare scalar on the other side is
  prepared with the operand's return type. Position (`key` vs `value`) selects the
  side. Unary operators (`IS NULL`) apply to an operand `key` too. An operand
  `key` also pairs with a bare value through any operator's own value rendering, so
  `YEAR(post_date) IN (2023, 2024)`, `LENGTH(name) BETWEEN 3 AND 20`, and
  `LOWER(name) LIKE '%term%'` work (the operator owns the IN/BETWEEN/LIKE fragment,
  the operand supplies the left side). A *structured* right-hand operand (column or
  function) still requires a scalar comparison operator. Allow-listed functions
  cover `LOWER`/`UPPER`/`LENGTH`/`ABS`/`DATE`/`YEAR`/`MONTH`/`DAYOFMONTH`/`DAYOFYEAR`/
  `DAYOFWEEK`/`HOUR`/`MINUTE`/`SECOND`, each declaring the column-type categories it
  accepts — a column argument whose declared type is wrong for the function (e.g.
  `YEAR()` of a numeric column, `ABS()` of a string column) fails the clause closed.
- Adds cross-parser boolean composition via the `criteria` query var (#211): a
  top-level tree combines whole parser WHERE fragments with `OR`/`AND` (nestable)
  instead of the historical implicit `AND`, e.g.
  `'criteria' => array( 'relation' => 'OR', 'columns', 'meta' )` for
  `( <columns> OR <meta> )`. Leaves are parser buckets (`columns` aliases the direct
  column conditions; `meta`/`date`/`compare`/`relation`/`search`/`in`/`not_in`), not
  raw comparisons; any parser the tree does not name is `AND`-ed on. A group may also
  carry `'not' => true` to negate it, e.g.
  `array( 'relation' => 'OR', 'not' => true, 'columns', 'compare' )` for
  `NOT ( <columns> OR <compare> )` (standard SQL three-valued logic — a negated
  comparison excludes `NULL` rows). Fails closed on a malformed tree, an unknown leaf,
  or a `JOIN`-emitting parser under `OR` *or* `NOT` (its `JOIN` pre-filters rows, so
  `OR` cannot widen it and `NOT` cannot invert it). Built on a new inert clause builder
  (`Clauses\Builder` assembling `Clauses\Join` / `Clauses\Where`) that constructs the
  `JOIN`/`WHERE` without executing — the reusable seam write operations share.
  Absent `criteria`, behavior is unchanged.
- Adds `delete_items()` (#214, #130) — delete a set of items by a single ID, a list
  of IDs (numeric keys tolerated), or a query-var filter (the same vocabulary as
  `query()`, including `criteria` and store-backed `meta_query`). Filter deletes
  resolve the matching primary IDs and loop `delete_item()`, preserving per-item
  capability checks, meta cleanup, cache invalidation, and the `{item}_deleted`
  action. An empty or unconstrained (no-`WHERE`) filter deletes nothing — the empty
  set never widens to "all rows" — and resolution honors the same scoping hooks a
  read does (`parse_{plural}_query`, `pre_get_{plural}`, `{plural}_query_clauses`).
  This is the first `Operations\` verb (`Operations\Base` + `Operations\Delete`),
  consuming the inert `Clauses\Builder` seam. Returns the number deleted, or `false`.
- Adds `update_items()` (#214) — the write sibling of `delete_items()`: write one
  set of column values to a set of items named by a single ID, a list of IDs, or a
  query-var filter. Resolves the matching primary IDs (shared `Operations\Base`
  resolution) and loops `update_item( $id, $data )`, so per-item validation,
  capability reduction, meta handling, cache invalidation, and the
  `transition_{item}_{key}` actions all still fire. Empty `$data`, an empty input,
  or a filter with no `WHERE` updates nothing — the empty set never widens to "all
  rows". `Operations\Update` returns the number updated, or `false`.
- Adds `add_items()` (#214, #18) — the create sibling of `delete_items()` /
  `update_items()`: insert a list of new items, one data array per item. Unlike the
  other two it takes no set selector (the rows do not exist yet) — the input is
  always a plain list of data arrays — and it loops `add_item()`, so per-item
  default values, primary-key/UUID generation, sanitization, meta handling, and
  cache priming all still happen. Because the new IDs are the point of a batch
  insert, `Operations\Add` returns them in input order (each slot the new ID, or
  `false` where that one insert failed) rather than a count; an empty input inserts
  nothing and returns `array()`.
- Adds a `distinct` query var: `'distinct' => true` renders `SELECT DISTINCT` (and
  `COUNT(DISTINCT id)` when counting or computing found rows), so a relationship or
  meta `JOIN` that multiplies rows does not duplicate results or inflate counts.
- Adds per-column `NULLS FIRST` / `NULLS LAST` ordering (#211): a per-column orderby
  direction may carry the suffix, e.g.
  `'orderby' => array( 'priority' => 'ASC NULLS LAST' )`. MySQL has no native
  syntax, so it is emulated with a leading `ISNULL( col )` sort key (`DESC` floats
  NULLs first, `ASC` sinks them last); a plain direction is unchanged. Applies to a
  single sort key; the rest of the orderby list orders normally.
- Adds an `index_hints` query var (#219) for MySQL/MariaDB index hints
  (`USE` / `FORCE` / `IGNORE INDEX`). Takes one spec or a list of them -
  `array( 'type' => 'force', 'indexes' => array( 'idx_status' ), 'for' => 'join' )` -
  and renders the hint(s) right after the base table reference (e.g.
  `FROM ... a FORCE INDEX FOR JOIN (idx_status)`). Index names are validated against the schema's
  declared indexes plus `PRIMARY` (which also closes off injection); `type` and the
  optional `for` scope (`join` / `order by` / `group by`) are closed enums.
  A hint never changes which rows return, so it **fails open**: an unknown index
  name, an unknown type, or an illegal `USE`+`FORCE` mix is dropped and logged, and
  the query runs un-hinted. Applies to the base table of the read path only (not
  relationship JOIN targets, and not the `delete_items()`/`update_items()`
  ID-resolution path). Because a hint never changes which rows return, it is
  **excluded from the result-cache key** (like `with`), so a hinted and an unhinted
  query share one cache entry. MySQL/MariaDB only - cross-engine rendering is
  tracked in the dialect audit #220.
- Adds end-to-end support for a string/UUID primary key (not `auto_increment`):
  `add_item()` with a supplied key returns that key, and `get_item()`,
  `update_item()`, `delete_item()`, `copy_item()`, query result-shaping, the
  transition/deleted action hooks, and store-backed item meta all address rows by
  the string key. The legacy WordPress metadata fallback (a primary with no meta
  store) still requires an integer ID, since `{type}meta` tables are int-keyed.
- Adds the Meta preset (#204, in progress): `Presets\Meta\Query` and
  `Presets\Meta\Table` are base classes a plugin extends with thin stubs naming
  their primary/query counterparts. The query stub derives its `{object}_meta`
  identity and key/value EAV schema from the primary — the generated foreign key
  mirrors the primary key's storage shape (a non-`bigint` integer or `varchar`
  key is mirrored faithfully in the sibling table) — and declares a `belongs_to`
  back; the primary declares the matching `has_many` in its own schema.
  Both resolve through the ordinary relationship engine; no Kern class references
  presets. `Presets\Meta\Query` implements the new `Interfaces\MetaStore`
  contract, and `Query`'s protected `*_item_meta()` methods route through a
  declared `meta` relationship when the remote query implements that contract,
  falling back to the legacy WordPress metadata API otherwise. Bulk meta (extra
  non-column keys passed to `add_item()` / `update_item()`) and the delete-item
  purge route through the store as well; with a store declared, the WordPress
  `register_meta()` key gate is intentionally skipped — the `meta` relationship
  is the registration. For a store-backed object, `meta_query` / `meta_key` /
  `meta_value` are now translated into relationship `EXISTS` filters against the
  sibling table (honoring `compare`, `type` casts, and `relation` AND/OR, with
  nesting); WordPress-core objects keep the bespoke meta parser. A negative
  `compare_key` (`!=` / `NOT IN` / `NOT LIKE` / `NOT REGEXP` / `NOT EXISTS`) is
  flipped to its positive operator and emitted as `NOT EXISTS` over the key —
  "the object has no meta row whose key matches" — matching the bespoke engine;
  combining a negative `compare_key` with a value still fails closed.
- Adds ordering by meta value (#204): `orderby => 'meta_value'` / `'meta_value_num'`
  (numeric), or a named `meta_query` clause key, orders results by that key's value —
  for both the bespoke parser (via the clause's JOIN alias) and store-backed objects
  (via a deterministic correlated subquery, where the oldest `meta_id` wins a
  multi-valued key). Multiple meta keys, and mixing a meta key with a real column,
  compose in one array `orderby`.
- Fixes a named (string-keyed) `meta_query` clause being silently dropped by the
  bespoke parser: it was mistaken for flat `meta_*` vars and emitted no SQL, so it
  neither filtered nor sorted (only positional and `relation` clauses built). Named
  clauses now build like positional ones (WP_Meta_Query parity), so both
  `meta_query => array( 'name' => array( … ) )` filtering and `orderby => 'name'` work.
- Relationship `relation_query` clauses may be combined into nested AND/OR groups
  (`'relation' => 'OR'` over a clause group), composing `EXISTS(a) OR EXISTS(b)`;
  malformed or unresolvable clauses fail the whole group closed.
- Schema validation now reconciles primary-key declarations: a column flagged
  `primary` that is covered by the `primary` index counts as ONE key (the flag is
  the semantic marker queries/parsers read; the index emits the DDL), so schemas
  may declare both. Real conflicts — multiple primary indexes, multiple flagged
  columns without a covering composite index, or a flagged column outside the
  primary index — still fail validation.
- Adds relationship-declaration validation (#206): `Schema::get_validation_errors()`
  checks each declaration's local side (own shape, local columns, accessor
  uniqueness, unsupported composite, a named-but-missing remote query class);
  `Query::get_relationship_errors()` checks the remote side on demand (the class is a
  sibling `Query`, the referenced remote columns exist); and relationship
  declarations dropped by `Column::sanitize_relationships()` are now logged with
  stable reason codes (`relationship_invalid_query_class`, `relationship_invalid_type`, …).
- Adds a per-column save-time `intercept()` hook and a `Generator` trait; UUID
  generation moves into `intercept()` (#194).
- Improves cache-key coherence: per-group `last_changed` salting, id-pointer
  lookups routed through the salt, and stale `cache_key` slots invalidated on
  update (#203).
- Adds `$engine`, `$row_format`, and `$auto_increment` table properties with
  `engine()`, `auto_increment()`, and `get_create_sql()`; prevents
  auto-reinstallation after `uninstall()` via a tombstone and an `$auto_install`
  flag.
- Makes every Kern class config-constructable from an array — no subclass required
  (`new Query( $definition )`) — through a normalized `Boot` lifecycle
  (`sunrise → configure → init → consume_args → sunset`) split across the
  `Boot`, `Lifecycle`, and `Configuration` traits.
- `Query` accepts a definition or query vars via one constructor argument
  (discriminated by a schema signature); structural query vars are canonicalized
  before the cache key.
- Adds opt-out strict configuration: construction keys outside the declared config
  surface (`get_config_callbacks()`) are dropped and logged.
- Reserves the construction-machinery properties (`get_reserved_vars()`, each trait
  declaring its own) and excludes them from the config merge, so configuration can
  no longer clobber internal state — most visibly, a diagnostic logged by a config
  sanitizer during `validate_args()` now survives construction.
- Isolates query-var parsers from each other's clauses and fails closed on
  unresolvable or misdeclared columns; consolidates shared parser helpers onto the
  base.
- Adds an early, all-vars query-var normalization step: parsers may implement
  `normalize_query_vars( $query_vars, $caller )` to rewrite high-level directives
  into canonical query vars before the `parse_{items}_query` action (distinct from
  the later, var-local `parse_query_vars()`). The `relation` and store-backed
  `meta_query` translations run here, so `Query` no longer special-cases them;
  a normalizer fails closed by returning a `query_filter_short_circuit` directive.
- Reduces WordPress coupling: reimplements `wp_validate_boolean()`, `absint()`, and
  (filter-free) `sanitize_key()` in the `Sanitizer` trait and (filter-free)
  `wp_parse_args()` as `Base::parse_args()`, and uses native PHP CSPRNG for UUIDs
  and random integers instead of `wp_rand()`.
- Removes the internal `Parser::caller()` indirection in favor of direct,
  type-checked calls.

### 3.1.0 Upgrade Notes

- `add_item()` and `copy_item()` now return `int|string|false` (was `int|false`):
  the supplied primary key for a string/UUID-keyed table, or the auto-increment
  value otherwise. The `{item}_deleted` and `transition_{item}_{key}` action hooks
  now pass `$item_id` as `int|string` (the real key) rather than casting to `int`.
  Both are no-ops for the common `bigint` `auto_increment` case.
- The `Query::sunrise()` construction hook (3.0.0) is renamed to `Query::init()`,
  which now runs after `configure()` and before `consume_args()`; `sunrise()` still
  exists but now runs *before* configuration. Rename any override that derived state
  from the query's configuration.
- The `parse_args()` construction hook (`Boot`/`Query`, 3.0.0) is renamed to
  `consume_args()`; `parse_args()` is now a `wp_parse_args()`-style array helper.
  Rename any override of the leftover-args hook to `consume_args()`.
- Configuration is strict by default — keys outside the declared config surface
  (`get_config_callbacks()`) are dropped and logged. Override `is_strict_config()`
  to opt out (as `Row` does for its
  dynamic columns).
- Several `Parser`/`Column`/`Query` methods introduced in 3.0.0 are now `protected`
  rather than public.
- Parsers now fail closed (return no rows) on unresolvable or misdeclared columns,
  and no longer bleed clauses across parser types.
- `update_item()` now returns `true` for a meta-only update whose bulk meta saved
  successfully (previously it wrote the meta but reported `false`). An identical-
  value meta-only update still reports `false` (nothing changed), consistent with
  column diffing.
- `get_item_meta()` with an empty meta key now returns ALL meta for the item
  (matching `get_metadata()` and `MetaStore::get_meta()`); previously the empty key
  was rejected and it returned `false`. Applies to both the meta-store and legacy
  WordPress paths.
- `delete_item_meta( $id, $key, '', true )` now reaches the global purge it
  documents — the key is deleted across every object (`$delete_all`, ignoring the
  item ID), matching `delete_metadata()` and `MetaStore::delete_meta()`. Previously
  the empty-ID guard returned `false` before routing, so the capability — already
  implemented on both the store and legacy paths — was unreachable. A meta key is
  still required.
- Malformed relationship declarations are still dropped (fail-closed), but now log a
  non-fatal warning identifying the column and the reason. Call
  `Query::get_relationship_errors()` to validate the remote side of the surviving
  relationships on demand.
- Re-homes the "special column" shapes into pluggable `Presets\Column\*` strategy
  objects (#201): each preset declares its trigger (`matches()`), the column shape it
  forces (a declarative `SHAPE` const), an optional soft default name, and an
  optional value `intercept()`. The built-ins are `id`, `primary`, `serial`, `uuid`,
  `created`, `modified`, and `version`; resolve and override them through
  `Presets\Column\Registry`. More than one preset can apply to a column (e.g.
  `uuid` + `primary`), applied in a fixed precedence order. Behavior is unchanged for
  existing columns; the preset name is now a SOFT default (an explicit `name` is no
  longer overridden). Validation stays on `Column`.
- Adds an `id => true` column shorthand for the conventional unsigned `bigint(20)`
  AUTO_INCREMENT primary key (the Id preset), and a `version => true` shorthand for
  an optimistic-lock column (unsigned `bigint(20)`, NOT NULL, default `0`). The
  version column is declaration-only for now; the increment-on-update guard that
  makes it a working lock is a later, opt-in change (#218).
- A `created`/`modified` column now defaults its type to `datetime` when none (or a
  non-date type) is given, while respecting an explicit date-bearing type
  (`DATE`/`DATETIME`/`TIMESTAMP`). Existing declarations that already set `datetime`
  are unaffected.
- Adds `wp_meta_key => true` (varchar(191) named `meta_key`) and `wp_meta_value => true`
  (nullable `longtext` named `meta_value`) shorthands for the columns of a key/value
  meta table; the Meta recipe's `build_schema()` now builds its own columns from these
  and the `id` preset. The flags are `wp_`-namespaced so they neither overpromise (the
  191 length is a WordPress convention) nor trip `WordPress.DB.SlowDBQuery`.
- Column-preset wiring is now derived from the `Presets\Column\Registry`: a registered
  preset's boolean flag is auto-recognized as a config arg, takes a precedence slot
  from the Registry's order, and is consumed during shaping when it has no backing
  property. Registering a preset with a brand-new flag is a drop-in — no `Column`
  change required.
- Adds `unique => true` and `index => true` column flags: the `Schema` derives a
  single-column index named after the column - a UNIQUE index for `unique`, a plain
  KEY for `index` (`unique` wins when both are set) - unless an existing index already
  satisfies it (exact single-column coverage, UNIQUE for the unique flag). The flag is
  the semantic marker; the derived index emits the DDL (#221).
- A lone `primary => true` column now derives the `PRIMARY KEY` when the schema has no
  primary index, so the explicit primary index becomes optional. Derivation is
  conservative: it fires only for a single primary column with no primary index at all
  - multiple primary columns still need an explicit composite primary index (column
  order is semantic), and a primary index that does not cover the flag stays the
  specific validation conflict rather than being masked by a second primary key. A
  column inside a composite primary key still derives its own `unique`/`index`.
- A `uuid => true` column now derives a plain lookup `KEY` (not `UNIQUE`): its value is
  generated only on the Query insert path, so a UNIQUE constraint would reject any row
  inserted directly (raw `$wpdb`, bulk loads) without one. Declare an explicit `UNIQUE`
  index to enforce uniqueness at the database level.
- A `belongs_to` foreign-key column now derives a lookup `KEY`, independent of whether
  the relationship enforces a real FOREIGN KEY (#205). An existing index with the FK as
  its leftmost, full-length column already satisfies it (so the Meta recipe's explicit
  FK index is not duplicated). A foreign key too long to index in full (a long
  varchar/text/blob) is skipped with a logged warning rather than emitting
  uninstallable DDL - declare an explicit prefixed Index for it.
- A `cache_key => true` column (a `get_item_by()` lookup column) now derives a plain
  lookup `KEY` - the same single-column equality index a foreign key gets, deduped the
  same way. The primary cache_key needs no extra index (the primary key already covers
  it). It is a `KEY`, not `UNIQUE`: a cache_key's identity is an application invariant,
  not a database constraint.
- `Schema::get_items()`, `Schema::get_columns()`, and `Schema::get_indexes()` accept
  optional `wp_filter_object_list()` match args, an `'and'`/`'or'`/`'not'` operator, and
  a `$field` to pluck from each match (mirroring `Query::get_columns()`), e.g.
  `get_columns( array( 'primary' => true ), 'and', 'name' )`. The `type` arg is matched
  case-insensitively (Column types are stored uppercase, Index types lowercase). With no
  args and no field the whole collection is returned as before; a filtered or plucked
  result is a reindexed list.
- `Query::get_columns()` now delegates to the schema object's `get_columns()` rather than
  resolving columns itself.
- Adds `Schema::get_primary_column_name()` (the `primary`-flagged column's name, or the
  `id` fallback). `Query::get_primary_column_name()` delegates to it when the schema
  exposes it - centralizing the primary-column concept on the schema that derives the
  PRIMARY KEY - and falls back to the flagged column for a `get_columns()`-only schema.
- Fixes legacy class aliases (`BerlinDB\Database\Column`, `\Index`, `\Query`, `\Row`,
  `\Schema`, `\Table`) not resolving under `instanceof`. PHP's `instanceof` does not
  trigger autoloading, so a type check against a legacy name resolved to false until the
  name was loaded some other way. The alias is now registered eagerly when its `Kern\*`
  target class loads (#223).
- **Breaking:** `Query::get_columns()` no longer reads a legacy `$columns` property
  declared directly on a `Query` (sub)class - the pre-3.0 inline-columns source. Columns
  now come solely from the schema object. A subclass that defined its columns via a
  `$columns` array instead of a `Schema` must move them into its schema. (The property
  was undeclared on `Query` in 3.0 - already a deprecated dynamic property under PHP 8.2+
  - and unused throughout the library, so most consumers are unaffected.)

## 3.0.0 - 2026-06-01

- Modernizes the project structure around adapters, interfaces, kern objects,
  operators, parsers, and traits.
- Adds a dedicated database connection interface with `wpdb` and null connection adapters.
- Adds parser and operator classes for reusable SQL clause generation.
- Adds schema object injection and MySQL introspection factories for columns,
  indexes, schemas, and tables.
- Adds automated data typing via column casts, including JSON column support.
- Adds lifecycle state management, structured logging, magic property helpers,
  and environment/database connection helpers.
- Adds `cache_results` query support and improves query cache behavior.
- Adds parser-driven `ORDER BY` support for date, meta, and `__in` query vars.
- Improves table copy, duplicate, rename, repair, and upgrade behavior.
- Expands schema, table, query, parser, operator, lifecycle, environment,
  logging, and error handling coverage.
- Adds PHPStan, PHPCS, and PHPUnit GitHub Actions.
- Declares PHP 8.1 as the minimum supported PHP version.
- Improves Composer package metadata and distribution contents.
- Ships as the 300th commit from the 3.0.0 release branch.

### 3.0.0. Upgrade Notes

- PHP 8.1 or newer is required.
- The object model has been reorganized around `Adapters`, `Interfaces`, `Kern`,
  `Operators`, `Parsers`, and `Traits` namespaces.
- Database access now flows through the `Connection` interface and adapter classes.
- Query parsing is more schema-aware, especially for column casts,
  parser-specific clauses, and supported `__in`/`__not_in` query vars.
- Projects extending internals should review renamed traits, parser/operator
  extraction, and table/query lifecycle behavior before upgrading.

## 2.0.2 - 2025-10-23

- Fixes the Composer autoloader for the current repository structure.
- Fixes `Query::add_item()` return typing.
- Fixes date query SQL generation to use the table alias/name correctly.
- Fixes query item-shape state so one query cannot affect another query.
- Fixes `parse_groupby()` argument handling.
- Updates Composer dependencies.
- Adds temporary dynamic-property compatibility attributes.
- Changes `Table::exists()` to search only the current database via information
  schema, then reverts the broader table-exists change from PR #166.

## 2.0.1 - 2022-03-10

- Changes the project license from GPL to MIT.
- Adds a `_clone()` shim for compatibility with downstream Sugar Calendar usage.
- Fixes the `$order` argument typo in query handling.
- Audits `shape_item_id()` usage.
- Refreshes inline documentation.

## 2.0.0 - 2021-07-12

- Improves update behavior so `Query::update_item()` only updates relevant changed columns.
- Removes unnecessary `stripslashes()` handling from item validation.
- Replaces hardcoded `id` references with the configured primary column name.
- Fixes `get_meta_table_name()` return behavior.
- Improves meta handling during item updates.
- Improves `parse_groupby()` alias handling.
- Adds `Table::columns()`.
- Updates table uninstall behavior to clean version information when the table no longer exists.
- Tightens query and table property typing and array handling.

## 1.1.0 - 2021-02-22

- Adds custom meta-query support by abstracting the `WP_Meta_Query` dependency
  into BerlinDB's own meta parser.
- Adds `query_var_default_value` and `is_query_var_default()` support.
- Refactors date query handling to better align with meta and compare queries.
- Adds table `clone()` and `copy()` helpers.
- Adds `Table::index_exists()`.
- Improves `get_item()` and related query helpers.
- Adds `Column::sanitize_default()`.
- Improves null-value validation for nullable columns.
- Uses GMT-safe date and time helpers.
- Adds `start_of_week` support for date clauses.
- Adds `Query::get_meta_type()` and improves meta table name handling.
- Improves upgrade filters, metadata deletion, cache cleaning, and inline documentation.

## 1.0.0 - Initial Development

- Introduces the original Base, Schema, Row, Query, Table, Column, Date, and Compare classes.
- Adds the first README and project naming.
- Adds early decimal and datetime validation helpers.
