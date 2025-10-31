# Research: IF EXISTS / IF NOT EXISTS Implementation

## Summary

This document provides research findings and implementation details for adding `IF EXISTS` and `IF NOT EXISTS` syntax to BerlinDB table operations.

## Research Question

Should BerlinDB use `IF EXISTS` / `IF NOT EXISTS` syntax for table operations to convert MySQL errors into warnings, improving reliability in unpredictable PHP environments?

## Answer: YES (with exceptions)

### Implemented Changes

1. **CREATE TABLE IF NOT EXISTS** ✅
   - Changed: `CREATE TABLE` → `CREATE TABLE IF NOT EXISTS`
   - Location: `Table::create()` method (line 469)
   - Location: `Table::_clone()` method (line 597)

2. **DROP TABLE IF EXISTS** ✅
   - Changed: `DROP TABLE` → `DROP TABLE IF EXISTS`
   - Location: `Table::drop()` method (line 506)

3. **TRUNCATE TABLE** ❌ (No change)
   - `TRUNCATE TABLE IF EXISTS` is NOT supported by MySQL/MariaDB
   - See: https://bugs.mysql.com/bug.php?id=61890
   - Added documentation note in code

## Benefits

### 1. Eliminates Race Conditions

**Before:**
```php
if (!$table->exists()) {  // Check at T1
    // Another process creates table at T1.5
    $table->create();      // Error at T2: table already exists
}
```

**After:**
```php
$table->create();  // CREATE TABLE IF NOT EXISTS - always safe
```

### 2. Better Performance

- **Before**: 2 queries (`SHOW TABLES LIKE` + `CREATE/DROP`)
- **After**: 1 query (atomic operation)
- **Improvement**: 50% reduction in database round trips

### 3. More Permissive Behavior

- **Before**: MySQL Error 1050/1051 stops execution
- **After**: MySQL Warning 1050/1051 (operation succeeds)
- **Result**: More reliable in unpredictable environments

### 4. Industry Standard

- WordPress core uses `CREATE TABLE IF NOT EXISTS` in multisite setup
- WordPress `dbDelta()` includes IF NOT EXISTS logic
- Standard practice in modern database frameworks

## Database Compatibility

| Database | CREATE IF NOT EXISTS | DROP IF EXISTS | Since |
|----------|---------------------|----------------|-------|
| MySQL | ✅ Supported | ✅ Supported | MySQL 3.23 (2001) |
| MariaDB | ✅ Supported | ✅ Supported | MariaDB 5.1 |
| SQLite | ✅ Supported | ✅ Supported | SQLite 3.0 (2004) |
| PostgreSQL | ✅ Supported | ✅ Supported | PG 9.1 (2011) |

**Conclusion**: All major database engines support this syntax.

## Error vs Warning Behavior

### Before Implementation

```sql
CREATE TABLE wp_existing_table (...);
-- Error Code: 1050. Table 'wp_existing_table' already exists
-- Query fails, PHP execution may stop
```

```sql
DROP TABLE wp_nonexistent_table;
-- Error Code: 1051. Unknown table 'wp_nonexistent_table'
-- Query fails, PHP execution may stop
```

### After Implementation

```sql
CREATE TABLE IF NOT EXISTS wp_existing_table (...);
-- 0 row(s) affected, 1 warning(s): 1050 Table 'wp_existing_table' already exists
-- Query succeeds, PHP execution continues
```

```sql
DROP TABLE IF EXISTS wp_nonexistent_table;
-- 0 row(s) affected, 1 warning(s): 1051 Unknown table 'wp_nonexistent_table'
-- Query succeeds, PHP execution continues
```

## Impact Assessment

### Breaking Changes

**MINIMAL** - This change is more permissive:

- ✅ Code expecting success will still get success
- ✅ Code checking `is_success()` will still pass
- ⚠️ Error handlers expecting errors won't fire (safer behavior)
- ⚠️ Unit tests expecting errors may need updates

### Migration Impact

**NONE** - Backward compatible change:

- Existing code continues to work
- Tables created before update work identically
- No schema changes required
- No data migration needed

## Testing Implications

### Unit Tests Benefits

1. **Simpler test cleanup**: Can always call `drop()` safely
2. **Idempotent operations**: Tests can re-run without manual cleanup
3. **Parallel test execution**: Less risk of conflicts
4. **Test isolation**: Temporary tables easier to manage

### Example Test Pattern

**Before:**
```php
// Setup
if ($table->exists()) {
    $table->drop();
}
$table->create();

// Teardown  
if ($table->exists()) {
    $table->drop();
}
```

**After:**
```php
// Setup
$table->create();  // Safe even if exists

// Teardown
$table->drop();    // Safe even if doesn't exist
```

## Why TRUNCATE is Excluded

### Technical Limitation

```sql
TRUNCATE TABLE IF EXISTS table_name;
-- ERROR 1064: You have an error in your SQL syntax
```

- Not supported by MySQL, MariaDB, PostgreSQL, or SQL Server
- Open MySQL bug since 2011: https://bugs.mysql.com/bug.php?id=61890
- Marked "Won't Fix" by MySQL team

### Workaround Options

1. **Check existence first** (current implementation):
   ```php
   if ($table->exists()) {
       $table->truncate();
   }
   ```

2. **Use DELETE instead** (slower but safer):
   ```php
   $table->delete_all();  // DELETE FROM table_name
   ```

## Recommendations

### For Library Users

1. **Trust the operations**: No need to check existence before create/drop
2. **Simplify code**: Remove defensive existence checks
3. **Handle warnings**: Warnings are success, not failure

### For Future Development

1. Consider deprecating `exists()` checks before create/drop
2. Document the new behavior in upgrade notes
3. Update examples to show idempotent patterns

## References

### MySQL Documentation
- CREATE TABLE: https://dev.mysql.com/doc/refman/8.0/en/create-table.html
- DROP TABLE: https://dev.mysql.com/doc/refman/8.0/en/drop-table.html
- TRUNCATE Limitation: https://bugs.mysql.com/bug.php?id=61890

### SQLite Documentation
- CREATE TABLE: https://www.sqlite.org/lang_createtable.html
- DROP TABLE: https://www.sqlite.org/lang_droptable.html

### PostgreSQL Documentation
- CREATE TABLE: https://www.postgresql.org/docs/current/sql-createtable.html
- DROP TABLE: https://www.postgresql.org/docs/current/sql-droptable.html

### WordPress
- dbDelta: https://developer.wordpress.org/reference/functions/dbdelta/
- Uses CREATE TABLE IF NOT EXISTS in wp_install_defaults()

## Conclusion

The implementation of `IF EXISTS` / `IF NOT EXISTS` syntax aligns with BerlinDB's core mission:

> "One of the basic ideologies of BerlinDB is to coexist and play nicely in an unpredictable PHP environment."

By converting errors to warnings, we achieve:
- ✅ Better reliability in concurrent environments
- ✅ Improved performance (fewer queries)
- ✅ Industry-standard patterns
- ✅ Broad database compatibility
- ✅ Minimal code changes

This is a clear improvement with minimal risk.
