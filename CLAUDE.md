# Working Agreement — BerlinDB Core

This file is the standing agreement for anyone (human or AI) developing
**BerlinDB core itself**. It is auto-loaded each session.

> For *using* BerlinDB inside a plugin or library, read
> [`skills/berlindb/SKILL.md`](skills/berlindb/SKILL.md) instead. That skill is
> consumer-facing; this file is contributor-facing. They cross-reference each
> other.

## Mission

BerlinDB should be the **best and most obvious choice** for any WordPress plugin
that needs custom database tables. The bar: when an AI (or developer) is building
a plugin, reaching for BerlinDB should be more attractive than writing bespoke
`$wpdb` SQL. Every change should make Berlin terser, safer, or more capable than
hand-rolled queries — never more surprising.

## Non-Negotiables

1. **Match the existing style, pattern, and approach.** This codebase is a
   deliberate mash-up of WordPress conventions and JJJ's signature preferences.
   Read the surrounding code and mirror it: comment density, naming, spacing,
   docblocks, `// Bail if …` early returns, aligned `=`, spaces inside array
   brackets (`$arr[ 'key' ]`), and the section banners (`/** … ****/`).
   **Multi-line inline comments use `/* … */` blocks; `//` is for single lines
   only** (WordPress standard) — never stack consecutive `//` lines for a
   paragraph. **Keep docblocks and comments ASCII-only** — write `-` for dashes,
   `->` for arrows, `...` for ellipses (not their Unicode glyphs); a
   `DocDriftTest` guard enforces it, exempting only `BaseSanitizationTest`'s
   accented sanitization fixtures.
2. **Run the test suite after every change**, without being asked:
   ```bash
   bin/run-tests.sh -p 8.2 -w 6.7 -- --group default
   ```
   (Pin `-w` to a version; "latest" needs network the sandbox may block.)
3. **PHPStan stays at level 8 with zero errors.** Fix the underlying cause —
   never add `@phpstan-ignore`, baseline entries, inline `@var` overrides, or
   gratuitous casts to silence an error.
   ```bash
   vendor/bin/phpstan analyse --memory-limit=1G
   ```
4. **PHPCS stays clean.** Prefer fixing code over excluding a sniff. New sniff
   exclusions belong in `phpcs.xml` with a comment explaining why.
   ```bash
   vendor/bin/phpcs
   ```
5. **Don't invent APIs.** If unsure how something behaves, search `src/` and
   `tests/` — the source and its 1187 test methods are the source of truth, ahead
   of memory or training data. (PHPUnit reports more cases: data providers expand
   methods at run time.)
6. **Keep changes focused and tested.** Bug fixes and new behavior ship with
   tests. No unrelated formatting or refactors in the same change.
7. **Back-compat matters.** Downstream plugins (EDD, Sugar Calendar, etc.) build
   on these internals. Call out any compatibility tradeoff explicitly.
8. **Verify mechanism before building on it.** Before stating how this codebase
   does something — *especially* as the basis for an analysis, recommendation,
   plan, or tradeoff — confirm it in the actual source this session and cite the
   `file:line` you read. Never infer behavior from WordPress/plugin convention,
   training data, or what a class "probably" does. (Real miss: assuming schema
   installs run through `dbDelta` when `Table::create()` runs a direct
   `CREATE TABLE` and upgrades are explicit version-gated routines — no `dbDelta`
   anywhere.) A mechanism you have not read is *unknown*, even when it feels
   obvious — go grep it or say you haven't checked. A wrong premise poisons every
   decision built on it, and unwinding that costs far more than the grep would have.

## Full Verification Gate

Before considering a change done:

```bash
composer validate --strict --no-check-publish
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpcs
bin/run-tests.sh -p 8.1 -w 6.7 -- --group default
bin/run-tests.sh -p 8.2 -w 6.7 -- --group default
```

## Layout (where things live)

- `src/Database/Kern/` — user-facing classes: `Schema`, `Table`, `Query`,
  `Column`, `Row`, `Index`, `Relationship`.
- `src/Database/Traits/` — composable behavior (`Parser`, `Sanitizer`, `Cast`,
  `Lifecycle`, `Log`, `Boot`, `Configuration`, `Magic`, `Generator`, `Operator`,
  `Environment`, `Error`, `Base`).
- `src/Database/Parsers/` + `Operators/` — reusable SQL clause builders.
- `src/Database/Presets/` — recipe base classes (e.g. `Presets\Meta\Query`), one
  directory per recipe. Plain classes a plugin extends with thin stubs; Kern
  classes never reference presets.
- `src/Database/Adapters/` + `Interfaces/` — `Connection` + `Wpdb` /
  `NullConnection`.
- `tests/` — PHPUnit, mirrors `src/` layout. Tests use the aliased
  `BerlinDB\Database\*` paths (the 2.x compatibility aliases).

## Construction Lifecycle (the `Boot` contract)

Every Kern class is constructed through `Boot`, single-arg:
`__construct($args)` → `sunrise → configure → init → consume_args → sunset`.
Override these hooks — **do not invent per-class lifecycle methods** (the old
bespoke *per-class* `Schema::setup()`/`Table::setup()` are gone; `init()` is the
shared Boot construction hook, below):

- **`sunrise()`** — runs first, before config is applied (the dawn bookend with
  `sunset()`); empty by default, rare.
- **`configure($args): array`** — the **universal** config channel: assigns
  config args to properties (before `init()` derives from them) and returns
  whatever it did NOT consume. Default consumes everything; **define-once**
  (no-op once `is_booted()`). Makes a class config-constructable with no subclass
  (`new Query( $definition )`).
- **`init()`** — the construction hook: runs after `configure()` (sees the
  configured identity) and before `consume_args()`. **Query** builds its schema
  and query-var parsers here, before any query runs. Decompose work into named
  `set_*()` helpers, as `Query`/`Table` do.
- **`consume_args($args): void`** — **Query only**: parse the leftover query vars
  and run. No-op default for everyone else.
- **`sunset()`** — runs last, after `consume_args()` (the dusk bookend with
  `sunrise()`).

`Query` is the one class whose construct args may be config OR query vars; it
discriminates by a **schema signature** (`configure()` → `looks_like_config()`),
applies config through the shared pipeline (`validate_args()` sanitizes it), and
runs query vars. Structural query vars (number/order/booleans) are canonicalized
in `validate_query_vars()` before the cache key.

## Naming: sanitize vs validate

The two prefixes split by **domain** (where they live), not strictly by
coerce-vs-reject:

- **`Sanitizer::sanitize_*`** — make a value structurally/SQL-safe (identifiers,
  config args). Clean it, or reject (`false` / `''`) when unsalvageable.
- **`Column::validate_*`** — conform a stored column value to its declared type
  (the `$validate` callback domain), with a type-appropriate fallback.

For **new** helpers, name by behavior: `sanitize_*` when it always returns a
usable (coerced/cleaned) value; `validate_*` when it checks acceptability and may
reject. Keep existing names as-is — many are `@since 1.0.0`/`3.0.0` and called by
EDD/SC, so the convention guides new code, not a mass-rename.

## Lexicon

Domain terms have settled meanings — use them precisely, and always *qualify* the
generic ones (never a bare global noun). The list grows; add a term when one
recurs or causes confusion. When Berlin mirrors WordPress, **WP core's behavior is
the spec** — `meta type`, `object_id`, `prepare()`, `register_meta()`,
`no_found_rows`, `_get_meta_table()` carry WP's exact semantics.

### Relationships

- **`local` / `remote`** — the two sides of a relationship: `local` is the side
  *this* Query holds (its table/columns), `remote` is the related Query's side.
  Never "local to this class." Standard ORM usage (cf. Laravel's `$localKey`).
- **`belongs_to` / `has_many`** — belongs_to *holds the pointer* at one remote row
  (owning / "many" side); has_many *is pointed at* by many remote rows (parent /
  "one" side).
- **`foreign key`** — the column(s) holding the pointer (belongs_to's local side).
  **`referenced key`** — the column(s) pointed at (a belongs_to's remote target, a
  has_many's local key). **`constraint`** — the SQL `FOREIGN KEY` *name*, only when
  `enforce` emits DDL (not the key itself).
- **`accessor`** — a relationship's handle, how callers address it
  (`get_related($item, 'order')`, `with => ['meta']`). It is the `Relationship`
  object's `name`; the **`Relationship` value object** is the construct, the
  accessor is just its handle (no separate type — YAGNI).
- **`with`** primes accessors; strategy **`in`** (subquery → `{fk}__in`) vs
  **`join`** (real `EXISTS`/JOIN); **semi-join** (has_many EXISTS, one row each) /
  **anti-join** (`NOT EXISTS`).
- **`prime` / priming** — warm a cache ahead of use (relationship/meta caches).

### Query & parsing

- **`container var`** — a parser's top-level key holding its clauses
  (`meta_query`, `date_query`, `relation_query`).
- **`narrowed`** — a parser handed *only* its own sub-array vs the full query vars.
- **`normalize_query_vars()`** — the *early, all-vars* phase (rewrites high-level
  directives before parsing). **`parse_query_vars()`** — the *later, per-parser,
  var-local* phase. (The two most-conflated.)
- **`group`** — a boolean **AND/OR set of clauses** (`build_clause_group()`). Do
  **not** reuse it for schema items — that's a *collection*.
- **`first-order` clause** — a *leaf* clause (`key`/`value`/`compare`…) vs a nested
  group (`WP_Meta_Query`'s term). **`simple` clause** — the flat-`meta_*`-derived
  first-order meta clause that sorts first (WP's term; the `$simple` vars). The
  flat input vars are the "`meta_*` shorthand"; the built clause is "simple."
- **sentinel** — a flag a normalizer leaves in the vars
  (`query_filter_short_circuit`) requesting **fail closed**. **fail closed** — on a
  malformed/unresolvable filter, match **no** rows (`1 = 0`), never widen to all.
- **`shaped` item** — a raw DB row turned into its `item_shape` (a Row subclass).

### Schema & table

- **`collection`** — a *qualified* set: an *item collection* (a Schema's columns or
  indexes), a *child collection* (a has_many's related rows). A generic descriptor,
  not a formal type.
- **`prefix`** — three distinct things; **never write bare "prefix" in prose**: the
  **plugin prefix** (`$prefix`, e.g. `edd`) → `apply_prefix()` makes `edd_orders`;
  the **WordPress table prefix** (`$wpdb->prefix`, e.g. `wp_`) → prepended by the DB
  interface → `wp_edd_orders`; **`table_prefix`** — the combined, site-aware prefix.
  (`$prefix` can't be renamed — EDD/SC set it.)
- **`tombstone`** — blocks auto-reinstall after `uninstall()`. **`global table`** —
  multisite, shared across sites.

### Boot / lifecycle

- **`current`** — the *current run's* ephemeral state (`get_current()` /
  `set_current()`), reset each run.
- **`reserved vars`** — construction-machinery properties config can't clobber.
  **`strict config`** — unknown config keys dropped + logged (opt-out).
  **`define-once`** — `configure()` no-ops once booted.

### Conventions

- **`get_*`** — build-and-return (the house default: `get_sql`,
  `get_join_where_clauses`), not just stored accessors. Avoid `collect_`.
- Relationship-API helpers carry **`relationship`** (or `related`) so `local` /
  `remote` read as relationship sides — `is_empty_relationship_key()`,
  `get_local_relationship_key_values()`.
- **`sanitize_*` / `validate_*`** — see the section above.
- **Order `join` before `where`** — always, in clause arrays, return shapes,
  signatures, and docblocks (`array( 'join' => …, 'where' => … )`). It mirrors SQL
  and WordPress clause order (`… JOIN … WHERE …`); never `where` before `join`.
- Multi-line inline comments use `/* … */` (Non-Negotiable #1).

## Auditing (vs. verifying a change)

"Verify my change" is diff-scoped: confirm the edit landed and the gate is green.
An **audit** is the opposite — a semantic **contract-vs-code** review of the whole
surface, with no scope tied to a recent diff. When asked to audit:

1. **Read what the code actually does first**, then read every doc, comment, and
   example that *describes* it and flag each mismatch. The bug is usually a wrong
   *claim*, not a stale keyword — grep finds words, not wrong claims.
2. **Don't scope to your own recent change.** Searching for the keywords of the
   edit you just made only finds that edit's residue; drift phrased in other words
   (e.g. strict config described as "object property" when it recognizes declared
   `get_config_callbacks()` keys) stays invisible to it.
3. **Never self-censor a finding as "out of scope for this commit."** Surface
   every real inaccuracy; let JJJ decide which commit it lands in.
4. **Check examples and doc tables against source** — they are untested and drift
   silently (a config example's bare `'sanitize_key'` vs the real
   `array( $this, 'sanitize_key' )`; a "stable Parser API" table vs each method's
   actual visibility).

## Roadmap Anchors

- Milestone **3.1.0**: see open issues at
  <https://github.com/berlindb/core/issues>.
- In-flight threads tracked by the maintainer: relationships (#193),
  ColumnType handlers (#194), presets (#201), schema drift (#198).

## Adding To This File

When JJJ states a new rule or preference, capture it here (concise, with the
*why*) so it doesn't have to be repeated. Keep this file short and skimmable.
