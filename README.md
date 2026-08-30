# ElephpantDB

A small, persistent SQL database in a single PHP file, with no Composer dependencies. It keeps tables on local disk and
accepts SQL through a JSON-over-HTTP endpoint.

Requires PHP 8.3 or newer, a 64-bit build, and a writable directory on a local filesystem.
ElephpantDB probes `flock()` at startup and refuses to run on NFS or a shared volume where exclusion is not real.

## Quick start

```sh
export ELEPHPANTDB_DATA="$PWD/data"
export ELEPHPANTDB_TOKEN="change-me"

php -S 127.0.0.1:8080 elephpantdb.php
```

Send one statement per `POST`:

```sh
curl http://127.0.0.1:8080/ \
  -H 'Content-Type: application/json' \
  -H 'X-Elephpant-Token: change-me' \
  --data '{
    "sql": "CREATE TABLE users (id INT PRIMARY KEY, name TEXT NOT NULL, active BOOL)"
  }'
```

Later request bodies, with named and positional parameters:

```json
{"sql": "INSERT INTO users (id, name, active) VALUES (:id, :name, :active)",
 "params": {"id": 1, "name": "Ada", "active": true}}

{"sql": "SELECT * FROM users WHERE id = ?", "params": [1]}
```

The built-in PHP server is meant for local development. For a deployed instance, route the file through a normal PHP web
stack.

## HTTP API

The endpoint accepts only `POST`. Post to whichever URL routes to `elephpantdb.php`.

- `sql` is required and must contain one statement.
- `params` is optional: a JSON array for `?` placeholders, a JSON object for `:name` placeholders.
- Positional and named placeholders cannot be mixed in one statement.
- Every supplied parameter must be used, and every placeholder must have a value.
- The maximum request body size is 1 MiB.

Responses are a statement result, a row set, or an error:

```json
{"ok": true, "rowsAffected": 1}
{"ok": true, "rows": [{"id": 1, "name": "Ada", "active": true}], "count": 1}
{"ok": false, "error": "description"}
```

| Status | Meaning                                           |
|-------:|---------------------------------------------------|
|  `200` | Statement executed successfully.                  |
|  `400` | Invalid SQL, parameters, schema, or request body. |
|  `401` | Missing or invalid token.                         |
|  `405` | The request method was not `POST`.                |
|  `500` | Storage failure or unexpected internal failure.   |

### Authentication

Set `ELEPHPANTDB_TOKEN` to require the same value in the `X-Elephpant-Token` header. When it is unset or empty, the
endpoint accepts requests without authentication.

The token is only a second line of defence. The endpoint executes arbitrary supported SQL and has no users, roles, or
row-level authorization, so restrict it at nginx, another reverse proxy, or a network boundary. Do not expose it directly
to the public Internet.

## Using the PHP API from CLI code

Under a non-CLI SAPI the file handles the request itself. Under the CLI SAPI it only defines the engine classes, so CLI
programs and tests can drive the database directly:

```php
<?php

require __DIR__ . '/elephpantdb.php';

$db = new Database(__DIR__ . '/data');

$db->execute('INSERT INTO users (id, name) VALUES (?, ?)', [1, 'Ada']);

$result = $db->execute('SELECT id, name FROM users WHERE id = :id', ['id' => 1]);

var_dump($result->rows);
```

`QueryResult` carries `rows` for a `SELECT`, or `rowsAffected` for a schema or data-changing statement.

## SQL

One statement per call. A trailing semicolon is optional.

```sql
CREATE TABLE users (id INT PRIMARY KEY, name TEXT NOT NULL, score FLOAT, active BOOL)
CREATE INDEX users_name ON users (name)

INSERT INTO users (id, name, score, active) VALUES (:id, :name, :score, :active)

SELECT id, name FROM users
WHERE active = TRUE AND score >= :minimum
ORDER BY score DESC
LIMIT 20 OFFSET 0

UPDATE users SET active = FALSE, score = :score WHERE id = :id

DELETE FROM users WHERE active = FALSE

VACUUM users
```

| SQL type | PHP/JSON value                   |
|----------|----------------------------------|
| `INT`    | Integer                          |
| `FLOAT`  | Integer or floating-point number |
| `TEXT`   | String                           |
| `BOOL`   | Boolean                          |

Every type may contain `NULL` unless the column is declared `NOT NULL` or `PRIMARY KEY`.

Predicates combine `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `IS NULL`, `IS NOT NULL`, `NOT`, `AND`, `OR`, and parentheses:

```sql
(active = TRUE OR score > 9) AND name IS NOT NULL
```

Rules worth knowing:

- A table needs at least one column and may declare at most one primary key. `PRIMARY KEY` implies `NOT NULL` and
  creates an index automatically.
- Only one index may exist for a given column. Indexes are hash based and accelerate equality predicates.
- The `INSERT` column list is required. Columns omitted from it receive `NULL`, subject to `NOT NULL` constraints.
- String literals use single quotes. Escape an apostrophe by doubling it: `'O''Brien'`.
- `NULL` follows SQL three-valued logic. Comparisons with `NULL` are unknown; use `IS NULL` or `IS NOT NULL`.
- `LIMIT` and `OFFSET` must be non-negative integers and may be supplied through placeholders.
- `UPDATE` writes a replacement row and retires the old one. It does not overwrite the old record in place.
- `DELETE` marks records dead. `VACUUM` rewrites the table with only live records, replaces the old heap, rebuilds every
  index, and reports the number of dead records removed as `rowsAffected`.
- Table, column, index, and placeholder names begin with an ASCII letter or `_`, contain only ASCII letters, digits, and
  `_`, and are at most 64 characters long.

## How it works

SQL is tokenized and parsed into statement and expression objects. Placeholders stay structured nodes until execution, so
parameter values are never concatenated into SQL text. Before execution, the engine validates identifiers, parameter use,
column names, nullability, and value types.

A table named `users` owns `users.schema`, `users.heap`, `users.lock`, and one `users.idx.<column>` per index; do not
edit them by hand. The heap is the authoritative store: rows are append-only records identified by byte offset, each
carrying a CRC32 checksum and a live/dead flag. `INSERT` appends a live record, `UPDATE` appends a replacement and then
marks the previous record dead, and `DELETE` marks records dead.

Heap writes are unbuffered and ordered with `fsync()`. When a table opens, the engine verifies the heap header, truncates
bytes beyond the last acknowledged record, and resolves an interrupted superseding update. Checksums are validated on
every read: damage found at the tail is truncated safely, while corruption in the middle of a heap is reported as a
storage error rather than silently skipped. Schema and rebuilt index files are written to a temporary file, synced, and
renamed over the previous file. PHP cannot `fsync()` the containing directory through its stream API, so a sudden power
loss may lose an entire just-renamed replacement, but readers should not observe a partially written one.

Indexes are on-disk hash tables whose entries point to heap offsets, used for an equality comparison on an indexed column
found in the top-level `AND` chain of a `SELECT` predicate. They are never trusted to determine correctness: every hit is
read back from the heap and checked against the complete predicate, a stale index catches up from the heap tail, and a
damaged index is discarded and rebuilt. Each table has one lock file, shared for reads and exclusive for writes, schema
changes, recovery, compaction, and index rebuilding, so different PHP processes may share a data directory on the same
local machine when the filesystem implements `flock()` correctly.

## Configuration

| Environment variable | Default                    | Purpose                                                                       |
|----------------------|----------------------------|-------------------------------------------------------------------------------|
| `ELEPHPANTDB_DATA`   | `data` beside the PHP file | Directory containing database files.                                          |
| `ELEPHPANTDB_TOKEN`  | unset                      | Required value of `X-Elephpant-Token`. Authentication is disabled when empty. |

## Current limitations

Not implemented: transactions spanning multiple statements; joins, subqueries, aggregates, or grouping; foreign keys or
check constraints; `ALTER TABLE`, `DROP TABLE`, or `DROP INDEX`; defaults, generated values, or auto-increment columns;
any network protocol other than the JSON HTTP endpoint; user accounts, roles, or per-table authorization; shared-storage
or distributed operation.

Implemented, with sharp edges:

- `PRIMARY KEY` creates a non-null indexed column, but duplicate key values are not currently rejected.
- Only indexed equality predicates accelerate `SELECT`; every other predicate scans the heap.
- `UPDATE` and `DELETE` collect their targets with a heap scan.
- Sorting is performed in memory after matching rows are collected.
- One request executes one SQL statement.

## Operational guidance

- Keep the data directory on a local filesystem.
- Restrict the endpoint at a reverse proxy or a private network boundary, and set `ELEPHPANTDB_TOKEN` even on trusted
  internal networks.
- Run `VACUUM <table>` periodically when a table receives many updates or deletes.
- Back up the complete data directory as one unit, preferably while writers are stopped or through a filesystem
  snapshot.

## License

[MIT](LICENSE)
