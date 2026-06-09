# Extending BerlinDB

How to hook BerlinDB's construction lifecycle and write custom parsers, and —
just as important — which methods to leave alone.

## Construction lifecycle

Every Kern class (`Schema`, `Table`, `Query`, `Column`, `Row`, `Index`,
`Relationship`) is constructed through the `Boot` trait with a single argument,
running these steps in order:

```
__construct($args) → boot() → sunrise → configure → init → consume_args → sunset
```

- **`sunrise()`** — runs first, before any configuration is applied. Empty by
  default; rare. Use only for state that must exist before config.
- **`configure($args)`** — applies the construct args to properties (the
  "definition"), then returns whatever it did not consume. **Do not override**
  (see below); override the hooks it consults instead.
- **`init()`** — the construction hook: runs after `configure()` (so it sees the
  configured identity) and before `consume_args()`. This is where a class builds
  state from its config — e.g. `Query` builds its schema object and query-var
  parsers here. Decompose into named `set_*()` helpers (see `Query`/`Table`).
- **`consume_args($args)`** — handles args `configure()` did not claim. No-op for
  most classes; `Query` overrides it to parse leftover query vars and run.
- **`sunset()`** — runs last, after `consume_args()`. Empty by default; rare.

### Override these

| Hook | Purpose |
|---|---|
| `sunrise()` | pre-config setup (rare) |
| `init()` | build state from the applied config (the construction home) |
| `consume_args( $args )` | handle leftover/non-config args (Query: run) |
| `sunset()` | post-construction teardown/finalize (rare) |
| `is_configuration( $args ): bool` | are the construct args a definition, or something else (Query: query vars)? Default `true` |
| `get_config_callbacks(): array` | declare accepted config keys → sanitizer callbacks |
| `is_strict_config(): bool` | opt out of strict config (default `true`) |
| `special_args()` / `validate_args()` | advanced: force/sanitize config values before they are set |

### Do NOT override these

| Method | Why |
|---|---|
| `__construct()` | entry point — override the hooks above, not this |
| `boot()` | the sequencer; define-once via `is_booted()` |
| `configure()` | the universal config pipeline; override the hooks it calls |
| `is_booted()` / `is_configured()` | read-only lifecycle state accessors |

(`run()` / `start()` / `finish()` from the `Lifecycle` trait are the *generic*
per-run bracket — used for construction *and* per-query runs — not construction
extension points.)

## Config args & strict mode

A class declares its accepted config keys in `get_config_callbacks()`, a map of
`key => sanitizer` (a callable, or `''` for pass-through):

```php
protected function get_config_callbacks(): array {
    return array(
        'table_name'  => array( $this, 'sanitize_table_name' ),
        'cache_group' => array( $this, 'sanitize_key' ),
        'table_schema' => '', // accepted, validated later by set_schema()
    );
}
```

Strict config is **on by default** (`is_strict_config()` returns `true`): a
construct key outside that declared surface is dropped and logged
(`config_unknown_arg`) instead of reaching `set_vars()`. This catches typos and
keeps framework-internal state (`booted`, `configured`, `logs`, …) from being set
via config. A `#[\AllowDynamicProperties]` class whose config is intentionally
open-ended — `Row`, whose keys are arbitrary table columns — overrides
`is_strict_config()` to return `false`.

## Custom parsers (the Parser API)

A query-var parser composes `Traits\Parser`, is constructed by a `Query`, and is
handed that Query as its caller (`$this->caller`). The engine calls
`get_join_where_clauses()` (and, for orderby, `get_orderby_sql()`) on the parser;
the parser builds its SQL by calling back into the caller.

These caller (`Query`) methods are the **stable Parser API** — a custom parser
may rely on them:

| Method | Returns |
|---|---|
| `get_column_by( $args )` | a `Column` matching the args, or `false` |
| `get_column_field( $args, $field, $fallback )` | one field off a matched column |
| `get_columns( $args, $operator, $field )` | columns (or a field list) |
| `get_primary_column_name()` | the primary key column name |
| `get_quoted_column_name_aliased( $name, $alias )` | a backtick-quoted, alias-prefixed reference |
| `get_in_sql( $name, $values, $wrap, $pattern )` | a prepared `IN (...)` fragment |
| `get_query_var( $key )` / `get_query_vars()` | a single query var / all of them |
| `parse_query_var( $vars, $key )` | a query var parsed to its value form |
| `get_table_name()` / `get_table_alias()` | the primary table name / alias |
| `get_meta_type()` | the object's meta type (for meta clauses) |
| `get_relationship( $name )` | a declared `Relationship`, or `false` |
| `get_item_name_plural()` | the plural item name |

Call them on the caller directly, guarding for absence:

```php
$pattern = (string) $this->caller?->get_column_field( array( 'name' => $name ), 'pattern', '%s' );
$aliased = (string) $this->caller?->get_quoted_column_name_aliased( $name );
```

Treat anything outside this list as internal and subject to change.
