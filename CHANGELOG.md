# Changelog

Notable changes to BerlinDB are documented here.

## 3.1.0 - Unreleased

- Documents and enforces the JSON column round-trip contract: values, types, and array
  (list) order are preserved exactly, but JSON *object key order* is not guaranteed. MySQL's
  native `json` type reorders object keys (by length, then bytewise) on storage, while
  MariaDB (`json` = `longtext`) preserves insertion order; object member order is
  insignificant per RFC 8259. Byte-exact key-order preservation would require storing JSON as
  `longtext` and is tracked in #247. JSON columns now encode with native `json_encode()`
  instead of `wp_json_encode()` (identical output for valid data on the PHP 8.1+ minimum;
  invalid UTF-8 falls back to `{}` like other invalid input).
- Fixes case-sensitive meta `REGEXP` (`type_key`/`type => BINARY`) on MySQL 8. The old
  form cast only one operand (`CAST(meta_key AS BINARY) REGEXP '<pattern>'`), which MySQL
  8's `regexp_like` rejects with *"Character set 'binary' cannot be used in conjunction
  with 'utf8mb4_...'"*. Both operands are now CAST to `BINARY`
  (`CAST(... AS BINARY) REGEXP CAST(... AS BINARY)`) - case-sensitive and portable across
  MySQL 5.7/8.x and MariaDB, and forward-compatible (WordPress core's `REGEXP BINARY %s`
  uses the `BINARY` operator, which MySQL 8.4 removed). Applies to the bespoke JOIN engine
  (key and value sides) and the store-backed relationship path; scalar comparisons only
  (a list/range value is not wrapped).
- Documents the supported database floor - **MySQL 5.7+ / MariaDB 10.2+** - and runs the
  test suite against both engines at that floor and a current release (MySQL 8.4, MariaDB
  11.4) in CI, so MySQL/MariaDB dialect divergence surfaces in CI rather than at a user's
  site (#230). No code change; `bin/run-tests.sh` gains a `-i <image>` flag to select the
  database image for either engine.
- Adds a `$hook_prefix` Query property so hook/filter NAMES can be namespaced
  independently of `$prefix` (#242). `$prefix` still drives table, cache-group, and meta
  resolution; `$hook_prefix`, when set, is applied only where a hook/filter name is built
  (`the_{plural}`, `pre_get_{plural}`, `parse_{plural}_query`, `found_{plural}_query`,
  `{plural}_query_clauses`, `filter_{item}_item`, `query_var_parsers`, `{item}_deleted`,
  `transition_{item}_{column}`). This lets a Query registered over an EXISTING table keep
  `$prefix` empty (so `posts` resolves to the real `{$wpdb->prefix}posts`) while still
  namespacing its hooks - firing `acme_the_posts`, never WordPress core's own `the_posts`.
  `get_hook_prefix()` returns the property when set, else `$prefix`, so unset is fully
  backward-compatible. The parser-fired `{plural}_search_columns` filter is unchanged (it
  has never been prefix-namespaced and stays that way for backward compatibility).
- Adds an explicit `$meta_type` Query property as the first-class source of the WordPress
  meta type (#243). `get_meta_type()` returns it when set, else falls back to the previous
  prefixed-`item_name` derivation - so a Query registered over an existing table whose
  `item_name` is namespaced (e.g. `wpct_post`) can set `protected $meta_type = 'post'`
  instead of overriding the method. The type is now the single source of truth for the meta
  table (`{type}meta`) and its object-id column (`{type}_id`): `delete_all_item_meta()` no
  longer independently guesses the column from `item_name`, and the WordPress meta-cache
  prime is gated to the legacy path (a store-backed object caches through its own `meta`
  relationship). Backward-compatible - an unset property preserves existing behavior.
- Adds conditioned relationships. A relationship may declare a fixed `condition` (a
  `column => scalar` equality map, e.g. `object_type => 'order'`) that scopes the related
  rows, so a polymorphic child table - one table shared across parent types via an
  `object_id` + `object_type` pair - models as a single relationship rather than hand-coded
  SQL. The condition is appended (`AND {remote}.{col} = {val}`) across `get_related()`
  traversal, the correlated `EXISTS` filter, and nested `EXISTS`. It is application-layer
  only (a `FOREIGN KEY` cannot encode a discriminator, so `enforce` is dropped), defaults to
  the `join` / `EXISTS` filter strategy, and fails closed on an unknown condition column; a
  condition on a `many_to_many` is rejected. The condition column must declare `in => true`
  on the remote for `get_related()` traversal (the `join` / `EXISTS` path needs no flag).
  Equality-with-scalar values in this version; richer predicates (operators / `IN`) and
  `many_to_many` support are follow-ups (#246).
- Adds a per-column `compare` flag and its `{column}_compare` query-var shorthand. A column
  declared `compare => true` can be filtered with comparison operators (`>`, `>=`, `<`,
  `<=`, `!=`, `BETWEEN`, ...) through `{column}_compare` - exact sugar that folds into a
  `compare_query` clause, sharing that engine's SQL path and cache key. Value shapes mirror
  the container: `array( 'compare' => '>', 'value' => 40 )`, a bare list (defaults to `IN`),
  or a bare scalar (defaults to `=`). Opt-in, since most columns are filtered by equality or
  `__in` rather than compared - typical for numeric, monetary, and date columns.
- Adds a `temporary` Table mode for session-scoped tables. A `Table` declared
  `temporary => true` (or `protected $temporary = true`) emits `CREATE TEMPORARY TABLE`
  / `DROP TEMPORARY TABLE`, so the table lives only for the current database connection
  and is auto-dropped when it ends. Because a temporary table does not persist, it skips
  the stored version option, the uninstall tombstone, and the `admin_init` auto-install
  hook (create it on demand within the session that uses it, not once via
  `maybe_upgrade()`); a stored version would otherwise outlive the gone table and mislead
  upgrades. `exists()` probes the table directly (a `LIMIT 0` read) rather than via
  `SHOW TABLES`, which does not list temporary tables. `is_temporary()` reports the mode.
  This is a scope/lifecycle mode over the SAME table shape - distinct from a VIEW (#235),
  whose shape is a `SELECT`, not a column list.
- Adds a Connection-owned platform descriptor (#232), the first step toward engine
  awareness (#220). `Adapters\Platform` names the underlying product (MySQL / MariaDB /
  SQLite), its version, and answers named capability questions (`has_storage_engines()`) -
  the platform owns the vocabulary, so call sites read as domain questions rather than a
  generic `supports( 'flag' )`. A Connection MAY implement the new opt-in
  `Interfaces\PlatformProvider` (the `Wpdb` adapter does); one that does not yields
  `Platform::unknown()`, whose questions all answer permissively - BerlinDB's existing
  MySQL-family assumption - so nothing changes for existing setups. A construct degrades
  only where the engine is KNOWN to lack it. First consumer: `Table::engine()` and the
  `ENGINE=` clause in CREATE TABLE are skipped on a platform with no storage engines (e.g.
  SQLite). SQLite is detected by WordPress Playground's SQLite
  Database Integration drop-in (its `db_version()` reports a fake MySQL '8.0', so the wpdb
  subclass identity is used, not the version); a `berlindb_platform` filter overrides
  detection. Constructs that plugin's translator already rewrites at runtime (AUTO_INCREMENT,
  REGEXP, SHOW/DESCRIBE) are deliberately left supported so BerlinDB does not fight it. No SQL
  rewriting yet - that per-engine rendering seam is the 4.0 effort #220 scopes.
- Surfaces DROPPED relationship declarations in `Schema::get_validation_errors()` (#206). A
  shorthand declaration the `Column` sanitizer rejects (unknown `type`, missing `query`/`column`,
  invalid class) is dropped fail-closed and was only visible in the structured log; the schema
  validation surface now reads those `relationship_`-coded warnings back (`Column {name}: …`), so
  `is_valid()` catches a typo'd declaration instead of it vanishing silently. Also aligns the
  `Relationship` value object with the same reject-not-mutate stance: a directly-passed unrecognized
  `type` now resolves to `''` and is flagged by `Relationship::get_validation_errors()` rather than
  silently coercing to `belongs_to` (an omitted `type` still defaults to `belongs_to`; a set
  `through` still infers `many_to_many`). Deep remote validation (`Query::get_relationship_errors()`,
  resolving the remote Query and its columns) was already in place.
- Fixes an `OR` `meta_query` returning DUPLICATE rows when a base row matches more than one OR
  branch through multiple meta rows. The per-clause INNER JOINs fan the row out; the Query now
  groups by the primary key to dedupe in row mode (mirroring WP_Query's
  `meta_query->has_or_relation()`), and the paginated found-rows total and direct `count => true`
  switch to `COUNT(DISTINCT primary)` so they report distinct base rows, not fanned-out JOIN rows.
  An explicit `groupby` or `distinct` still takes precedence. Pre-existing since 3.0 (the parser's
  `has_or_relation` flag was set but never consumed); exposed by the parser audit.
- Fixes a nested clause subgroup with no explicit `relation` defaulting to the query's
  TOP-LEVEL relation instead of AND. `sanitize_query()` seeded a relation-less subgroup's
  default from `$this->relation` (the top-level AND/OR), so a `meta_query` / `date_query`
  subgroup meant as `( a AND b )` under an `OR` parent wrongly rendered `( a OR b )`. A
  relation-less subgroup now defaults to a neutral AND, matching WP_Meta_Query semantics
  (pre-existing since 3.0); a subgroup that declares its own relation still keeps it.
  UPGRADE NOTE: a plugin that passes a nested `meta_query` / `date_query` subgroup without an
  explicit `relation` will see its generated SQL change from `OR` to `AND` for that subgroup -
  the corrected, WP-consistent behavior. Add an explicit `'relation' => 'OR'` to any subgroup
  that genuinely relied on the old (incorrect) OR combining.
- Filters two or more relationship hops out via a nested `relation` clause (#211 Lever D). A
  `relation` clause whose own `relation` key holds another clause (an ARRAY, not the `AND`/`OR`
  boolean string) filters down the chain (`order -> customer -> region -> country`), each hop
  emitting a correlated `EXISTS` that nests arbitrarily deep. A nested `relation` forces the
  `join` strategy (a JOIN cannot correlate inside a subquery); `where` applies at every hop and
  `exists => false` negates the hop it sits on (`NOT EXISTS`); nested chains are `belongs_to` /
  `has_many` only; any unknown/unresolvable hop at any depth fails the whole clause closed
  (`1 = 0`), never widening. Fixes an unbounded recursion this exposed in the shared parser:
  `sanitize_query()` treated `relation` as an always-string group directive and had children
  INHERIT it, so an array-valued nested `relation` propagated into every child and recursed
  without end - it now never inherits a non-string `relation` into a child clause.
- Batch-primes composite (multi-column) relationships via `with`, killing the per-item N+1
  (#229). A `with => [ name ]` on a composite `belongs_to` / `has_many` now does ONE bulk read
  - a portable OR-of-ANDs match `( a = ? AND b = ? ) OR ( ... )`, not a row-value `IN` (which
  would add a MySQL 5.7 / MariaDB 10.2 floor) - and seeds the exact result caches each per-item
  `get_related()` reads (empty tuples included, so a no-match / childless lookup is a hit too),
  so the accessor lookups fire zero SQL. Because it seeds the normal result cache, `last_changed`
  rotation invalidates it for free on any write - no bespoke composite cache group. The reusable
  primitive is `Cache::prime_relationship_tuples()`. Also primes a single-column `belongs_to`
  that references a NON-primary column (previously never batch-primed); a single-column
  primary-key `belongs_to` keeps its by-id `prime_items()` fast path.
- Save-time interception can now tell an omitted column from an explicit value (key presence),
  so a preset acts on genuine omission (#233). A preset's `intercept()` now receives
  `( $value, Presets\Column\Context $context )` - a value object carrying the method, the
  Column, and `$context->provided()` (whether the caller supplied the column) - replacing the
  prior positional `( $method, $value, $column )`, so future save context is added to `Context`
  without changing the signature. `add_item()` captures the caller's column keys before defaults
  are merged in, `update_item()` uses the post-diff keys. First payoff: an explicit `null` on a
  nullable `CURRENT_TIMESTAMP` column now stores SQL `NULL` (it is an explicit value), while an
  omitted column still defers to the DB DEFAULT. The `created` / `modified` presets also stamp
  on genuine omission via `provided()` instead of the brittle "value equals the column default"
  check, so a caller who deliberately passes the default value is now honored rather than
  overwritten.
- Omits the DEFAULT clause for a JSON column with a declared default, instead of emitting an
  invalid literal (`default '[]'`). MySQL rejects a literal DEFAULT on JSON (and BLOB/TEXT)
  columns - only a parenthesized expression default is allowed, which BerlinDB does not emit -
  so the `is_json()` guard now runs before the explicit-default branch in `get_default_sql()`.
  A nullable JSON column's `DEFAULT NULL` is unaffected.
- Supports MySQL-managed `CURRENT_TIMESTAMP` datetime/timestamp columns end-to-end. A column
  declared `default => 'CURRENT_TIMESTAMP'` now emits the unquoted SQL function
  `DEFAULT CURRENT_TIMESTAMP` (paired correctly with an `ON UPDATE CURRENT_TIMESTAMP` extra, no
  duplication) instead of the invalid quoted `default 'CURRENT_TIMESTAMP'`, and BerlinDB now
  DEFERS the write so MySQL populates it. A new `Presets\Column\CurrentTimestamp` preset
  activates for a datetime/timestamp column declared with a `CURRENT_TIMESTAMP` default and/or an
  `ON UPDATE CURRENT_TIMESTAMP` extra, and drops the field on insert (DEFAULT) and on update
  (ON UPDATE) when the value is empty, the keyword, or an unparseable date, so MySQL populates it
  rather than BerlinDB writing the literal string. An explicit, valid datetime value (or one a
  prior preset such as `created`/`modified` set) still wins. The keyword is case-insensitive and
  only unquoted on temporal columns; on a string column it stays a quoted literal.
- Unifies the empty-datetime value behind a single `Column::get_empty_datetime()` source and
  makes nullable datetimes representable as SQL `NULL` (#231). The DDL default
  (`get_default_sql()`) and the runtime fallback (`validate_datetime()`) now read the same
  value, so they no longer disagree - previously the DDL emitted `'0000-00-00 00:00:00'` while
  the runtime returned `''`. A datetime declared `allow_null => true, default => null` now
  emits `DEFAULT NULL` and validates empty/invalid input (including a legacy zero-date literal)
  to `null`; every other datetime keeps the zero-date default, which is intentional for
  WordPress (wpdb strips `NO_ZERO_DATE` from the session sql_mode and WP core itself declares
  `NOT NULL DEFAULT '0000-00-00 00:00:00'` datetime columns). Off WordPress, or wherever
  `NO_ZERO_DATE` is on, declare a nullable datetime to store `NULL` instead. Behavior change:
  a NOT NULL datetime with no explicit default now resolves empty input to the explicit zero
  date rather than `''` (the value MySQL stored either way), and `ON UPDATE CURRENT_TIMESTAMP`
  emits the bare keyword rather than the parenthesized `current_timestamp()`. Also fixes a
  pre-existing bug where a datetime/timestamp column carrying the `ON UPDATE CURRENT_TIMESTAMP`
  extra emitted the clause twice (once from the default fragment, once from the extra),
  producing invalid `CREATE TABLE` DDL - the default fragment now yields it only once.
- Adds composite (multi-column) foreign-key relationships (#211 Lever D). A `belongs_to` /
  `has_many` relationship may now key on more than one column (`columns` / `references` as
  equal-length arrays), declared via a Schema's `get_relationships()` (the per-column
  `relationships` shorthand stays single-column). Composite relationships **filter**
  (`relation_query` JOIN / correlated `EXISTS`, correlated on every key column with AND-ed
  pairs) and **fetch** (`get_related()` matches all key columns), and their opt-in
  `FOREIGN KEY` DDL (`FOREIGN KEY ( a, b ) REFERENCES ... ( a, b )`) is emitted when enforced.
  Composite keys default to the `join` strategy (the `in` materialize strategy is
  single-column only) and are batch-primed by `with` (#229). Single-column relationship SQL
  is unchanged.
- Unifies the WP-core-meta engine's `meta_key` comparisons onto the operator path (#212).
  The bespoke `switch ( $meta_compare_key )` in `Parsers\Meta` that hand-built key SQL is
  gone; each key comparison now uses the same `cast_reference()` + `operator->get_sql_compare()`
  + `build_value()` assembly the `meta_value` side already used (negative operators nest their
  positive opposite in the existing correlated `NOT EXISTS` subquery). Behavior is preserved at
  the result level for well-formed clauses; the emitted SQL shape converges on the value side's
  idiom, with three intentional deltas: `meta_key REGEXP BINARY 'x'` -> `CAST(meta_key AS BINARY)
  REGEXP 'x'` (matching the value side), the empty-cast `REGEXP  ` double space collapses to one,
  and a `meta_key IN (...)` list gains a space after each comma. A degenerate empty `IN` / `NOT IN`
  key list, which previously emitted invalid `IN ()` SQL, now fails closed (matches nothing).
  Characterization tests lock every branch's SQL.
- Adds an `Operators\Logical\` operator family (#211) - `Conjunction` (AND), `Disjunction`
  (OR), `ExclusiveDisjunction` (XOR), and `Negation` (NOT) - alongside the existing
  `Comparisons\` and `Arithmetic\` families, each a thin keyword carrier resolved through
  a canonical `Operators\Logical\Registry`. `Clauses\BooleanGroup` now joins fragments via
  a resolved relation operator (and wraps negated groups via `Negation`) instead of a raw
  string, and the parser boolean tree resolves its `relation` through the same registry.
  The visible new capability is **`relation => 'XOR'`** in any parser bucket (`compare_query`
  / `meta_query` / `date_query`), the meta/relationship group builders, and the `criteria`
  tree - clauses joined by chained SQL `XOR` (matches when an ODD number are true; for the
  common two-clause group, exactly one). Unknown relations still fall back to `AND`.
- Adds the variadic scalar functions `GREATEST` / `LEAST` ( return the largest / smallest
  argument, with a COALESCE-style derived return type ) and `CONCAT` / `CONCAT_WS` ( join
  arguments into a string, so they compare as `%s` ) to the operand function allow-list
  (#226). All are variadic ( two or more arguments ) and fail closed on fewer.

- Adds an arithmetic `math` operand (#211): `array( 'operand' => 'math', 'operator' =>
  '+', 'operands' => array( ... ) )` renders a parenthesized infix expression - `( price
  * quantity ) > 100`, `( a + b ) = c` - which the plain operator/value model can't
  express. Allow-listed operators are `+ - * /` (no arbitrary operator or raw SQL);
  members are themselves scalar operands, so arithmetic nests. The compared-scalar
  placeholder is numeric and never truncates (division and any float member derive `%f`,
  otherwise `%d`). Fails closed on an unknown operator, fewer than two members, or an
  unresolvable member.
- Adds relative-date filtering (#211): `NOW()` (a zero-argument function), `DATE_SUB` /
  `DATE_ADD`, and an `interval` operand (`array( 'operand' => 'interval', 'value' => 30,
  'unit' => 'DAY' )`) compose into `date_created > DATE_SUB( NOW(), INTERVAL 30 DAY )`
  ("the last 30 days") - built entirely from operands, no PHP-side timestamp math. The
  interval amount is integer-cast (injection-proof) and the unit is allow-listed
  (`SECOND`..`YEAR`); an interval is context-restricted (it can ONLY be a `DATE_SUB` /
  `DATE_ADD` argument, never a comparison value), enforced by a new POSITIONAL
  `arg_kinds` form on function descriptors. Also allow-lists `WEEKDAY`, `WEEK` (2-arg),
  and `DATE_FORMAT` (2-arg).
- Migrates the `date_query` parser fully onto the shared operator/operand engine (#211).
  The `value` compare, `after`/`before` ranges, every date-part (`year`/`month`/`week`/
  `dayof*`), the ISO day-of-week (`WEEKDAY(col) + 1`), and the `hour`/`minute`/`second`
  time query now delegate to the same engine as `compare_query` instead of hand-built
  SQL - so date columns gain value-side `IS NULL` / `IS NOT NULL` (opt in with `compare
  => 'IS NULL'` and `value => null`) and operand-spec values (a column / function / cast
  on the value side, e.g. `date_created < date_modified`). Functionally identical
  otherwise (a "forget me" `null`/`false` value is still ignored, matching WP_Date_Query;
  `0` stays a real value; a genuinely malformed input fails the clause closed). The
  legacy `build_numeric_value()` / `build_mysql_week()` / `build_time_query()` builders
  are removed.
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
  `DAYOFWEEK`/`HOUR`/`MINUTE`/`SECOND`/`COALESCE`, each declaring the column-type
  categories it accepts — a column argument whose declared type is wrong for the
  function (e.g. `YEAR()` of a numeric column, `ABS()` of a string column) fails the
  clause closed. `COALESCE` is the first *variadic* function (two or more arguments,
  returning the first non-`NULL`) and the first with a *derived* return type: having
  no type of its own, the placeholder a bare scalar compares against is the common
  type of its arguments (a `%d`-patterned argument and an integer literal derive
  `%d`; mixed types fall back to a string placeholder).
  A right-hand operand may also be a `list` (for `IN` / `NOT IN`) or a `range` (for
  `BETWEEN` / `NOT BETWEEN`) whose members are themselves operands, so
  `array( 'operand' => 'list', 'items' => array( array( 'operand' => 'column', 'name' => 'other_col' ), 5 ) )`
  renders `IN ( other_col, 5 )` — mixing columns, functions, and values in a way the
  bare value list can't. An empty list, a range that isn't exactly two bounds, a
  nested list/range member, or a shape mismatch (a list on a scalar operator, a
  single operand on `IN`) fails the clause closed. The bare-array value path
  (`'value' => array( 1, 2, 3 )`) is unchanged. A `tuple` operand builds a row
  constructor usable on either side of a comparison -
  `array( 'operand' => 'tuple', 'items' => array( ... ) )` renders `( a, b )` - so
  `( a, b ) = ( c, d )` and `( a, b ) IN ( ( 1, 2 ), ( 3, 4 ) )` work. Operands carry
  a width (a scalar is 1, a tuple is its member count, a list is its members' common
  width), and a comparison pairs two operands only when their widths match; a width
  or shape mismatch (unequal tuple widths, a ragged list of tuples, a value-shape
  collection/range used as the left subject, a tuple with `IS NULL`) fails closed.
  Any *scalar* operand (column / value / function / nested cast) may carry a `cast`
  key (a validated CAST target) that wraps it in `CAST( ... AS <type> )` - so an
  arbitrary expression casts, not just a column, e.g.
  `array( 'operand' => 'func', 'name' => 'LOWER', 'args' => array( ... ), 'cast' => 'CHAR' )`.
  Columns keep applying the cast inline (it also feeds their type-category check);
  every other scalar operand is wrapped in an `Operands\Cast` decorator, which
  composes as a function argument (validated by its target category, e.g. a `DATE`
  cast is accepted by `YEAR()`). A cast on a non-scalar shape (`list`/`range`/`tuple`),
  a requested-but-invalid target, or `cast => true` on a non-column (no declared type
  to derive from) fails the clause closed. The compared-scalar placeholder derives
  from the target: `SIGNED` prepares as `%d`, everything else (`UNSIGNED`/`DECIMAL`/
  char/temporal) as `%s`, so the value is preserved losslessly and the `CAST` performs
  the conversion.
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
- Adds scalar aggregate methods (#50, #211): `Query::get_sum()`, `get_avg()`,
  `get_max()`, and `get_min()`, each taking a column and optional query vars to
  filter the rows. `get_sum`/`get_avg` return `float|null`; `get_max`/`get_min`
  return the raw scalar. The `FUNC( column )` expression renders through the same
  operand value objects the clause builder uses, so it fails closed on an unknown
  column (and, for `SUM`/`AVG`, a non-numeric one) and on an empty result set. A
  filter that produces a `JOIN` (a meta / relationship filter, or a scoping hook)
  is aggregated over a distinct-primary subquery, so a one-to-many join does not
  fan out and double-count.
- Adds an `aggregate` query-var container (#225): compute one or more aggregates in a
  single query, returned keyed by alias -
  `array( 'aggregate' => array( 'revenue' => array( 'sum', 'amount' ), 'peak' => array( 'max', 'created' ) ) )`
  yields `array( 'revenue' => '1234.50', 'peak' => '...' )`. Accepts the shorthand
  `array( 'sum' => 'amount' )` (the key is the function, the alias defaults to it), the
  positional `array( 'revenue' => array( 'sum', 'amount' ) )`, and the named
  `array( 'revenue' => array( 'function' => 'sum', 'column' => 'amount' ) )`. Supports
  `SUM`/`AVG`/`MAX`/`MIN`; honors the query's filters; a fan-out `JOIN` is aggregated
  over a distinct-primary subquery (no double-count); results are cached like any query.
  It always returns an associative array (the `get_sum()`/etc. scalar methods remain the
  friendly scalar path); an empty set is `null` per alias, not `0`. An unknown or
  non-numeric column, an unsupported function, or a duplicate alias is logged and dropped.
  With a `groupby` var it becomes a grouped query, returning a list of rows (the group
  column(s) plus each alias) - `array( 'aggregate' => array( 'revenue' => array( 'sum', 'amount' ) ), 'groupby' => 'status' )`
  yields one row per status. Grouping happens after the fan-out dedup (no double-count);
  an unknown group column is treated as ungrouped, and an alias colliding with a group
  column is dropped. The scalar methods are always ungrouped. `COUNT` is a container
  aggregate too, so counts and column aggregates come back in one query -
  `array( 'orders' => array( 'count', '*' ), 'revenue' => array( 'sum', 'amount' ) )`;
  `'*'` is the row count (`COUNT(*)`), a real column is `COUNT(col)` (non-null), and an
  empty set counts 0 (not null). The top-level `count => true` pagination var is
  unchanged - this is the value count, a peer of the other aggregates. A grouped
  aggregate can be filtered by its results with a `having` container -
  `array( 'having' => array( 'revenue' => array( '>', 1000 ) ) )` keeps only the
  groups whose `revenue` exceeds 1000. HAVING accepts the scalar comparison operators
  (`=`, `!=`, `<`, `<=`, `>`, `>=`) against a surviving aggregate alias (named
  `array( 'compare' => '>', 'value' => 1000 )` or positional `array( '>', 1000 )`),
  reusing the query's operator library to render and prepare each comparison; multiple
  entries AND together, and it applies to grouped aggregates only.
- Adds a `by` column-filter container:
  `array( 'by' => array( 'status' => 'active', 'type' => array( 1, 2 ) ) )` folds each
  entry to the canonical `{column}__in` var during normalization (rendering `= value`
  for a single value, `IN (...)` for a list). It is a friendlier, collision-proof way
  to filter a column whose bare name would shadow a reserved control var (a `count`,
  `order`, or `number` column). Only in-filterable columns are translated; an explicit
  top-level `{column}__in` wins over the container entry; an unknown column or a
  malformed container is logged and ignored.
- Reserved control vars now keep precedence over a same-named column. The per-column
  shorthand a column registers (e.g. a `count` or `order` column) can no longer clobber
  a reserved query var's default during parser registration; the control var wins and
  the collision is recorded in the in-memory log, pointing at the `{column}__in`
  shorthand that still filters the column.
- Adds per-column `NULLS FIRST` / `NULLS LAST` ordering (#211): a per-column orderby
  direction may carry the suffix, e.g.
  `'orderby' => array( 'priority' => 'ASC NULLS LAST' )`. MySQL has no native
  syntax, so it is emulated with a leading `ISNULL( col )` sort key (`DESC` floats
  NULLs first, `ASC` sinks them last); a plain direction is unchanged. Applies to a
  single sort key; the rest of the orderby list orders normally.
- Adds ordering by an expression (#211): an `orderby` term may be an operand spec,
  so a query can sort by a column or an allow-listed function over one -
  `'orderby' => array( 'operand' => 'func', 'name' => 'LENGTH', 'args' => array( array( 'operand' => 'column', 'name' => 'name' ) ) )`
  renders `ORDER BY LENGTH( name )`, either alone or mixed into a numeric orderby
  list. It resolves through the same operand machinery as `compare_query` (schema-
  checked columns, allow-listed functions, no raw SQL). Only a `column` / `func`
  spec is meaningful to sort by; an unresolvable or non-scalar spec is dropped (not
  failed closed, since ordering does not change which rows match). The string, list,
  and `column => direction` map forms are unchanged.
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
- Adds value-side `IS NULL` / `IS NOT NULL` to `meta_query` (#211), in both the
  bespoke JOIN engine and the store-backed relationship path, so they behave
  identically regardless of caller. The predicate is value-less (any supplied `value`
  is ignored) and requires a matching meta row — so it means "has a meta row for this
  key whose value is (non-)NULL", and key ABSENCE never satisfies `IS NULL` (that is
  `NOT EXISTS`). Previously a unary `compare` silently fell back to `=`.
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
- The schema diff engine now detects **modified** columns and indexes (#224 phase 2b),
  not just added/dropped ones. A same-named column or index defined differently on the two
  sides becomes a `Diff\ColumnDiff` / `Diff\IndexDiff` (a from/to pair) in the Patch.
  Equivalence is decided by `Diff\ColumnNormalizer` / `Diff\IndexNormalizer`, which compare
  canonical signatures to avoid dbDelta-style phantom diffs: the column normalizer compares
  type, length (non-numeric only - an integer's `int(11)` display width is ignored),
  nullability, and unsigned/zerofill (numeric only), and deliberately excludes the
  default value, charset/collation, `extra`, and comment for now (each a phantom-diff
  source needing more context); the index normalizer compares kind plus the ordered column
  list with prefix lengths and directions. The bias is conservative - a missed change is
  safer than a false modification that churns.
- Adds a schema **diff subsystem** (`BerlinDB\Database\Diff\`, decoupled from `Kern`):
  a pure, stateless `Comparator` compares two schemas and returns a `Patch` describing
  the changes that transform one into the other - added/dropped columns and indexes
  (matched by identity; the primary key by type). `Schema::diff( Schema $other )` is the
  pure entry point; `Table::diff()` introspects the live table and compares it to the
  declared schema, and `Table::diverged()` is the boolean drift check. The `Patch` carries
  the real `Column`/`Index` objects and exposes `is_empty()`, `revert()` (the inverse
  patch), and stubbed `apply()`/`to_sql()` (phase 3). v1 detects adds/drops only; modified
  detection lands next (#224 phase 2).
- `Schema::from_table()` now introspects **indexes** as well as columns: it runs
  `SHOW INDEX FROM`, groups the rows by `Key_name`, and builds an `Index` per group via
  `Index::from_mysql()`, so a live table round-trips losslessly into a `Schema` (#224
  phase 1). Indexes the factory cannot represent (SPATIAL, functional key parts) are
  skipped (#216).
- Adds `Index::from_mysql()` - builds an `Index` from the `SHOW INDEX` rows for one index
  (grouped by `Key_name`, ordered by `Seq_in_index`), mapping `Sub_part` to a prefix
  length, `Collation` `'D'` to a `DESC` direction, `Non_unique` to the unique flag, and
  the `Index_type` (`FULLTEXT`/`HASH`). Returns `false` for forms it cannot faithfully
  represent - functional/expression key parts, `SPATIAL`/`RTREE` types, invisible indexes
  (`Visible = NO` / MariaDB `Ignored = YES`), and FULLTEXT indexes with a custom
  `WITH PARSER` - rather than emitting DDL with changed semantics. Mirrors
  `Column::from_mysql()` and is the prerequisite for introspecting indexes into a
  `Schema` (toward #224).
- Emits a column's `comment` in its `CREATE TABLE` definition (`COMMENT '...'`, quotes
  escaped) - previously a column comment was accepted and sanitized but silently dropped
  from the DDL, even though an index comment was already emitted.
- Reserves the index name `primary` (via `Schema::RESERVED_INDEX_NAMES`): an ordinary
  index may not be named `primary`, since that name is the alias for the primary key
  (addressed by index type). `get_validation_errors()` reports a non-primary index that
  claims it. Keeps the `'primary'` alias unambiguous by construction.
- Refactors `Schema`'s item accessors so the generic primitives (`get_item`, `has_item`,
  `remove_item`) are type-agnostic - they match an already-normalized name
  case-insensitively - while the typed wrappers (`get_column`/`get_index` and friends) own
  the type-specific rules: which sanitizer canonicalizes a raw name, and the index-only
  `'primary'` alias (which resolves to the primary key regardless of the index's own name).
  Behavior is preserved; the generic `get_item`/`has_item`/`remove_item` now expect an
  already-normalized name (their documented contract) and no longer special-case `'primary'`
  - use `get_index`/`remove_index` for that.
- Adds `Schema::get_primary_column_name()` (the `primary`-flagged column's name, or the
  `id` fallback). `Query::get_primary_column_name()` delegates to it when the schema
  exposes it - centralizing the primary-column concept on the schema that derives the
  PRIMARY KEY - and falls back to the flagged column for a `get_columns()`-only schema.
- Fixes legacy class aliases (`BerlinDB\Database\Column`, `\Index`, `\Query`, `\Row`,
  `\Schema`, `\Table`) not resolving under `instanceof`. PHP's `instanceof` does not
  trigger autoloading, so a type check against a legacy name resolved to false until the
  name was loaded some other way. The alias is now registered eagerly when its `Kern\*`
  target class loads (#223).
- Fixes a `groupby` with an unknown column emitting a malformed `GROUP BY`. An
  invalid column is now dropped (treated as ungrouped) across rows, count, and
  aggregate queries, with the validation shared by one helper (#217).
- Adds `Index::safe_prefix_chars()` — computes a safe index prefix length (in
  characters) from a named storage-engine profile and a column charset,
  replacing the hardcoded `191`. Conservative by default (legacy InnoDB +
  utf8mb4 = 191); the engine/charset-aware path lands with #220/#221 (#222).
- Adds `COUNT(DISTINCT col)` to the `aggregate` container via a `distinct`
  modifier — `array( 'buyers' => array( 'function' => 'count', 'column' =>
  'user_id', 'distinct' => true ) )` renders `COUNT(DISTINCT user_id)`. Named
  form only; currently `COUNT`-only, with the canonical model open to other
  aggregates. `Operands\Func` gained a `distinct` option (#225).
- Adds ordering to grouped aggregates — a grouped `aggregate` query can be
  ordered by an aggregate alias or a group column through the standard
  `orderby`/`order` vars (`array( 'aggregate' => array( 'revenue' => array(
  'sum', 'amount' ) ), 'groupby' => 'status', 'orderby' => 'revenue', 'order' =>
  'DESC' )`). Unknown keys are dropped; ORDER BY runs on the outer grouped query
  (never the fan-out dedup subquery). `LIMIT`/top-N is a follow-on (#225).
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
