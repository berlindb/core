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

## Validating relationship declarations

Relationship declarations are validated in two tiers, by **where the context
lives** (a `Relationship` is a context-free value object — it does not know its
owning schema):

- **`Schema::get_validation_errors()`** — the local, context-free side: each
  relationship's own shape, that its local columns exist in the schema, accessor
  uniqueness, a named-but-missing remote query class, and composite (multi-column)
  declarations (unsupported at runtime). Runs at install time (gates
  `get_create_table_string()`), so override `Relationship::get_validation_errors()`
  to extend the per-relationship shape checks.
- **`Query::get_relationship_errors()`** — the remote side, which needs Query
  context to resolve: the class is a real sibling `Query`, and the referenced remote
  columns exist. On demand by design — call it from your tests or dev tooling.

Malformed shorthand declarations are dropped by `Column::sanitize_relationships()`
(fail-closed, **reject-not-mutate** — a typo'd `type`/`query`/`column` drops the whole
declaration rather than coercing it to something real) and logged inline with stable
codes (`relationship_invalid_query_class`, `relationship_invalid_type`, …) at the point
of rejection. Such diagnostics survive construction because `configure()` excludes the
reserved construction-machinery vars (`get_reserved_vars()` — the log store among them)
from the property snapshot it merges over, so `set_vars()` cannot reset the log a
sanitizer just wrote to. `Schema::get_validation_errors()` also **reads those drop
warnings back** (`Column {name}: …`), so a dropped declaration surfaces in the
validation errors — not only the log — and `is_valid()` catches the typo.

The `Relationship` value object matches that reject-not-mutate stance: a directly-passed
unrecognized `type` (`new Relationship( array( 'type' => 'nonsense' ) )`) resolves to `''`
and is flagged by `Relationship::get_validation_errors()`, rather than silently coercing
to `belongs_to`. An **omitted** type still defaults to `belongs_to`, and a set `through`
still infers `many_to_many`.

## Presets

`src/Database/Presets/` holds two distinct families, both pluggable, both keyed by
their subdirectory:

- **Recipe presets** (`Presets/{Recipe}/`, e.g. `Meta`) — base classes a plugin
  extends with a thin stub, built entirely from the public Kern surface. **No Kern
  class references a recipe preset**: it is just a conventional way to assemble
  ordinary Schemas, Tables, Queries, and relationships, so Kern stays recipe-agnostic
  and any number of recipes can exist.
- **Column presets** (`Presets\Column\*`) — small strategy objects that re-home the
  "special column" shapes Kern's `Column` used to hard-code. Unlike recipe presets,
  `Column` *does* delegate to these (resolving them through `Presets\Column\Registry`),
  so they are an extension point on Kern itself rather than a plugin-assembly convention.

(For *using* the Meta preset, see the recipe in `SKILL.md`; this section is about the
extension points if you author or extend one.)

### Recipe presets

The Meta preset is the reference recipe. Its two base classes derive everything
from one named counterpart, in `init()` (the Boot construction hook), and **fail
loudly rather than construct something broken**:

- **`Presets\Meta\Query`** — a stub sets `$primary_query_class`; the base derives
  its `{object}_meta` identity, prefix, and an EAV schema (`meta_id` PK,
  `{object}_id` FK mirroring the primary key's storage shape, `meta_key`,
  `meta_value`) plus a `belongs_to` back to the primary. A misconfigured stub logs
  a structured `warning` with a stable code (`meta_primary_missing`,
  `meta_primary_not_a_query`, `meta_primary_key_missing`,
  `meta_primary_key_unsupported`) and leaves `is_configured_from_primary()` false
  instead of producing a default-identity object.
- **`Presets\Meta\Table`** — a stub sets `$meta_query_class`; the base derives its
  name and prefix from that query and installs **the exact same `Schema` instance**
  the query runs against (`get_schema()`). It consults
  `is_configured_from_primary()` and logs its own codes
  (`meta_table_query_missing`, `meta_table_not_meta_query`,
  `meta_table_query_misconfigured`, `meta_table_schema_missing`) before
  provisioning.

**Override point — `static::build_schema()`.** The generated EAV schema comes from
a `public static` method so a stub can override it (late static binding):

```php
public static function build_schema( Column $primary_key_column, string $object_name, string $primary_query_class ): Schema
```

Override it to add columns or indexes to the meta table; keep the `meta_id` /
`{object}_id` / `meta_key` / `meta_value` shape and the `belongs_to` so routing and
`get_related()` still resolve. Bump the Table stub's `$version` when you do, so the
upgrade path detects the change.

**The `MetaStore` contract.** `Presets\Meta\Query` implements
`Interfaces\MetaStore` (`add_meta` / `get_meta` / `update_meta` / `delete_meta` /
`delete_all_meta`), whose semantics mirror the WordPress metadata API. `Kern\Query`
routes its protected `*_item_meta()` methods (and bulk meta and the delete-item
purge) to a store **only when both hold**: the query declares a relationship named
`meta`, and the resolved remote `instanceof MetaStore` — the name picks *which*
relationship, the interface proves capability. Otherwise it falls back to the
legacy WordPress metadata path unchanged. `MetaStore` is an ordinary interface:
implement it on any class (e.g. a WP-core-backed adapter) and the same router will
delegate to it.

### Column presets

A Column preset re-homes one "special column" shape that `Column` used to branch on.
The built-ins are `id`, `primary`, `serial`, `uuid`, `created`, `modified`, `version`,
and the meta-table pair `wp_meta_key` / `wp_meta_value`; each is triggered by a column
declaration (a flag like `uuid => true`, or the `SERIAL` extra) and provides up to
five things, all optional except the key:

- **`key()`** — the stable key it registers and resolves under.
- **`flag()`** — the boolean declaration flag (defaults to `key()`; a preset triggered
  by something else, like `Serial` off the `extra` value, returns `''`). `Column`
  reads this to auto-recognize the flag and to consume it after shaping.
- **`matches( $args )`** — whether the declaration is present (defaults to a truthy
  `flag()`; override for a different signal, as `Serial` does for the `extra` value).
- **`SHAPE`** — a `const` array of column args the preset forces; `set_args()` merges
  it over the incoming args (a SHAPE key wins). Override `set_args()` only for a shape
  that depends on the column (as `Serial` does, promoting only integer types).
- **`default_name()`** — a SOFT default name, applied only when the caller gave none.
- **`intercept()`** — generate/stamp the stored value on save (mirrors
  `Column::intercept()`; return the unset sentinel to remove the field).

More than one preset can apply to a single column (e.g. `uuid` + `primary`). `Column`
collects every preset whose `matches()` is true, in the Registry's stable order
(built-ins first, registered presets appended), applies their SHAPEs in turn, then
threads the value through their intercepts. Validation is NOT a preset concern —
`Column` keeps its own type-based `validate_*` methods, keyed on the mirror flags.

Register or override one through the registry (e.g. in a bootstrap):

```php
use BerlinDB\Database\Presets\Column\Registry as ColumnPresets;

ColumnPresets::register( new My_Uuid_Preset() );   // overrides the built-in 'uuid'
ColumnPresets::register( new Slug_Preset() );      // adds a brand-new 'slug' flag
```

A registered preset overrides the built-in of the same key; `Registry::reset()` drops
registrations (call it in a test teardown). A brand-new flag is a **drop-in** — no
core change: `Column` derives its config-arg recognition and apply-precedence from the
Registry, and consumes any trigger flag that has no backing property, so a registered
preset's flag is recognized, resolved, and shaped automatically. Register at bootstrap,
before the schemas that use it are constructed.

## Query-var normalization (two points)

A parser participates in query-var handling at **two distinct points**, and the
difference matters:

- **`normalize_query_vars( array $query_vars, Query $caller ): array`** — runs
  EARLY, once, before the `parse_{$items}_query` action, and sees **all** of the
  query vars. It may rewrite cross-parser vars — translating a high-level directive
  into another parser's canonical var. The `Query` iterates its registered parser
  descriptors and threads the vars through each one's `normalize_query_vars()`, so
  the action and the SQL parsers all see the canonical, normalized vars. Default is
  a no-op. Examples in core: `Parsers\Relationship` turns the `relation` directive
  into `relation_query` / `{fk}__in`; `Parsers\Meta` turns a store-backed
  `meta_query` into `relation_query`.
- **`parse_query_vars( $query_vars )`** — runs LATER, at SQL-build time, and is
  **isolated to this parser's own var** (the engine narrows it and strips siblings).
  Use it for shorthand/structure local to your var, never to touch another parser's.

The lifecycle is therefore: raw args → merge defaults / `validate_query_vars()`
(canonicalize structural types) → `normalize_query_vars()` (all-var rewrites) →
`parse_{$items}_query` action → SQL parser isolation/build.

**Fail-closed from a normalizer.** A normalizer cannot reach Query's private
short-circuit helper, so to force a query to match no rows it returns a
`query_filter_short_circuit` query var — `array{ source: string, reason: string }`
(an empty `reason` is a legitimate empty match and is not logged). The `Query`
consumes and removes it. This is how a misconfigured `relation` / `meta_query`
fails closed instead of silently widening to every row.

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
