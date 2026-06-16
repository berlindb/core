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
   `tests/` — the source and the 926-test suite are the source of truth, ahead
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
