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
   `tests/` — the source and the 688-test suite are the source of truth, ahead
   of memory or training data.
6. **Keep changes focused and tested.** Bug fixes and new behavior ship with
   tests. No unrelated formatting or refactors in the same change.
7. **Back-compat matters.** Downstream plugins (EDD, Sugar Calendar, etc.) build
   on these internals. Call out any compatibility tradeoff explicitly.

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
  `Column`, `Row`, `Index`.
- `src/Database/Traits/` — composable behavior (`Parser`, `Sanitizer`, `Cast`,
  `Lifecycle`, `Log`, `Boot`, `Magic`, `Generator`, `Operator`, `Environment`,
  `Error`, `Base`).
- `src/Database/Parsers/` + `Operators/` — reusable SQL clause builders.
- `src/Database/Adapters/` + `Interfaces/` — `Connection` + `Wpdb` /
  `NullConnection`.
- `tests/` — PHPUnit, mirrors `src/` layout. Tests use the aliased
  `BerlinDB\Database\*` paths (the 2.x compatibility aliases).

## Construction Lifecycle (the `Boot` contract)

Every Kern class is constructed through `Boot`, single-arg:
`__construct($args)` → `sunrise → configure → setup → parse_args → init → sunset`.
Override these hooks — **do not invent per-class lifecycle methods** (the old
bespoke *per-class* `Schema::setup()`/`Table::setup()` are gone; `setup()` is now
a shared Boot hook, below):

- **`sunrise()`** — runs first, before config is applied (the dawn bookend with
  `sunset()`); empty by default, rare.
- **`configure($args): array`** — the **universal** config channel: assigns
  config args to properties (before `setup()`/`init()` derive from them) and
  returns whatever it did NOT consume. Default consumes everything; **define-once**
  (no-op once `is_booted()`). Makes a class config-constructable with no subclass
  (`new Query( $definition )`).
- **`setup()`** — post-config setup; **Query** builds its schema and query-var
  parsers here, before `parse_args()` runs.
- **`parse_args($args): void`** — **Query only**: parse the leftover query vars
  and run. No-op default for everyone else.
- **`init()`** — the normal home for post-config construction. Decompose work
  into named `set_*()` helpers, as `Query`/`Table` do.
- **`sunset()`** — runs last, after `init()` (the dusk bookend with `sunrise()`).

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

## Roadmap Anchors

- Milestone **3.1.0**: see open issues at
  <https://github.com/berlindb/core/issues>.
- In-flight threads tracked by the maintainer: relationships (#193),
  ColumnType handlers (#194), presets (#201), schema drift (#198).

## Adding To This File

When JJJ states a new rule or preference, capture it here (concise, with the
*why*) so it doesn't have to be repeated. Keep this file short and skimmable.
