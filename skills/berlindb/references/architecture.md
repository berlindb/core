# BerlinDB architecture: the SQL-layer map

This is the standing yardstick for *where a new concept belongs*. SQL nests in
layers; BerlinDB's directories populate those layers. When a feature doesn't map
cleanly onto a layer, that is the bespoke alarm (it is how `with` and the original
`where_query` went wrong).

> **Statement** contains **Clauses**, a clause contains **Expressions** (an
> **Operator** over **Operands**), an expression names **Identifiers** of a **Type**.

| Layer | What it is | SQL members | Where it lives in BerlinDB |
|---|---|---|---|
| **Statement** | the executable verb | DML: SELECT/INSERT/UPDATE/DELETE/REPLACE; DDL: CREATE/ALTER/DROP/TRUNCATE; TCL: BEGIN/COMMIT/ROLLBACK; DCL: GRANT/REVOKE; Utility: EXPLAIN/SHOW | `Operations/` (`Base` + `Delete` + `Update` + `Add` shipped; `Select` planned) |
| **Clause** | a part of a statement | projection, FROM, JOIN, WHERE, GROUP BY, HAVING, ORDER BY, LIMIT, WINDOW, WITH (CTE), VALUES, SET, RETURNING | `Clauses/` (`BooleanGroup`, `Where`, `Join`) |
| **Expression** | yields a value | Operators; Operands; Functions (scalar/aggregate/window); **Predicates** (boolean: `=`, BETWEEN, IN, LIKE, EXISTS, IS NULL); CASE; subqueries | `Operators/` + `Operands/` (incl. `Func`) |
| **Identifier / object** | named, persistent things | Database/Schema, Table, View, Column, Index, Constraint (PK/FK/UNIQUE/CHECK/DEFAULT), Sequence, Trigger, Alias | `Kern/` (`Schema`, `Table`, `Column`, `Index`, `Relationship`) |
| **Type / value** | the data dimension | data Types, Collations, Literals/Values, NULL, Casts | `Column->type` + `Traits\Cast` + `Operands\Value` |

BerlinDB-specific families that bridge WP_Query vocabulary to this map:

- **`Parsers/`** — *filters*: each turns its query var(s) into a WHERE/JOIN fragment
  (the `by`/`compare`/`meta`/`date`/`relation`/`search`/`in`/`not_in` family). They
  are the entry to the Expression+Clause layers from the query-var API.
- **`Kern\Row`** — result shaping (a selected row -> its `item_shape`).
- **`Adapters/` + `Interfaces/`** — the connection (`Wpdb`/`NullConnection`).

## Directive taxonomy (why `with` smelled)

Query *vars* are not all the same kind of thing. They fall in three layers; conflating
them is the bespoke smell:

- **Construction directives** — shape *which rows* are targeted: the parser filters,
  parser normalization, the cross-parser **criteria tree** (`criteria`), JOIN/WHERE.
  Consumed by the clause builder.
- **Operation directives** — choose the verb and its shape: select/count/delete/update,
  `fields`, `orderby`, `limits`.
- **Result directives** — act *after* rows exist: cache flags, **`with`** (relationship
  priming), item/meta priming. NOT construction; a DELETE has no result set to prime.

So `with` is a *result* directive wearing a query-var costume, and the cross-parser
boolean is a *construction* directive (a Predicate-layer tree), not a SELECT `WHERE`
string — which is why it became `criteria`, not `where_query`.

**Negation lives in two layers, deliberately.** `criteria`'s `'not' => true` is a
*structure* operator: it negates a whole parser *bucket* or grouped boolean
(`NOT ( <columns> OR <compare> )`), peer to `relation => AND/OR`, and a JOIN-emitting
bucket under it fails closed (same reason as under `OR`). Negating a single *condition*
(`status != 'x'`) is the within-parser layer's job — the `NotEqual`/`NotIn`/`NotLike`/
`NotBetween`/`NotExists`/`IsNotNull` operators and `{col}__not_in`. So `'not'` stays a
boolean flag and never takes an array of conditions: that would either smuggle raw
predicates into a tree whose leaves are parser *names* (breaking the invariant + the
fail-safe model), or duplicate what a nested negated group already expresses
(`array( 'not' => true, 'compare' )`). Bucket granularity vs condition granularity is
the line; finer needs are solved in the parser/operator layer, not by overloading
`criteria` leaves. See `references/query-row.md` for the consumer-facing when-to-use guide.

## The reusable construction path (in progress)

filters -> Query runs parsers -> **builder** (`Clauses\Builder` assembles via
`Clauses\Join` / `Clauses\Where`) -> `{join, where}` -> **operation** (renders + runs
its verb). The builder is *inert* (building never executes), which is what lets a
write operation reuse the same construction without pretending to be a SELECT.

`Operations\Delete` (via `Query::delete_items()`) is the first operation to cash this
in: it resolves each matching row's full primary KEY through `Query::select_primary_keys()`
(which runs the *full* read preparation — `parse_query` + the `parse_{plural}_query` /
`pre_get_{plural}` scoping actions + the `{plural}_query_clauses` filter — so a delete is
scoped exactly as a read is; selecting every primary column keeps composite-key tables
addressable) and then loops the composite-aware `delete_item()`. It fails closed: a filter that compiles
to no `WHERE` deletes nothing (a `JOIN` alone is not trusted — a `LEFT JOIN` does not
constrain the base table). `Operations\Update` is the write sibling (same resolution,
looping `update_item()`); `Operations\Add` is the create verb and the lone exception —
it resolves no set (the rows do not exist yet), so it loops `add_item()` over a list of
new-item data without touching the builder at all. The SELECT path is still bespoke in
`get_items()`; an `Operations\Select` that owns it (with `Query::query()` delegating) is
the next step.

## The rule

**Every new feature should name its layer.** A Statement, a Clause, an Expression/
Predicate, an Identifier, a Type, or one of the three directive kinds. If it doesn't
fit, stop and find the missing layer rather than bolting another var onto SELECT.
