# ElephpantDB

A small, persistent SQL database in a single PHP file, with no Composer dependencies.

ElephpantDB keeps tables on local disk and accepts SQL through a JSON-over-HTTP endpoint. It implements a deliberately
compact subset of SQL: creating tables and indexes, inserting and querying rows, updating and deleting data, and
reclaiming space with `VACUUM`.

## Features

- Persistent binary storage: an append-only row heap with checksums, tombstones, startup recovery, and index rebuilding.
- `CREATE TABLE`, `CREATE INDEX`, `INSERT`, `SELECT`, `UPDATE`, `DELETE`, and `VACUUM`.
- `INT`, `FLOAT`, `TEXT`, and `BOOL` columns, nullable unless declared otherwise.
- Positional (`?`) and named (`:name`) parameters, which are never concatenated into SQL text.
- Boolean predicates, comparisons, `IS NULL`, ordering, limits, and offsets.
- Hash indexes for equality lookups.
- Per-table file locking, so several local PHP processes can share one data directory.
- Optional token authentication for the HTTP endpoint.

## Requirements

- PHP 8.3 or newer.
- A 64-bit PHP build.
- A writable directory on a local filesystem.
- Working `flock()` exclusion. ElephpantDB probes for it and refuses to run on NFS or a shared volume where `flock()`
  does not provide real exclusion.

## Quick start

Assuming the database file is named `elephpantdb.php`, start a local development server:

```sh
export ELEPHPANTDB_DATA="$PWD/data"
export ELEPHPANTDB_TOKEN="change-me"

php -S 127.0.0.1:8080 elephpantdb.php
```

Send SQL as a JSON object in a `POST` request:

```sh
curl http://127.0.0.1:8080/ \
  -H 'Content-Type: application/json' \
  -H 'X-Elephpant-Token: change-me' \
  --data '{
    "sql": "CREATE TABLE users (id INT PRIMARY KEY, name TEXT NOT NULL, active BOOL)"
  }'
```

Response:

```json
{
  "ok": true,
  "rowsAffected": 0
}
```

Insert a row with named parameters:

```sh
curl http://127.0.0.1:8080/ \
  -H 'Content-Type: application/json' \
  -H 'X-Elephpant-Token: change-me' \
  --data '{
    "sql": "INSERT INTO users (id, name, active) VALUES (:id, :name, :active)",
    "params": {
      "id": 1,
      "name": "Ada",
      "active": true
    }
  }'
```

Query it with a positional parameter:

```sh
curl http://127.0.0.1:8080/ \
  -H 'Content-Type: application/json' \
  -H 'X-Elephpant-Token: change-me' \
  --data '{
    "sql": "SELECT * FROM users WHERE id = ?",
    "params": [1]
  }'
```

Response:

```json
{
  "ok": true,
  "rows": [
    {
      "id": 1,
      "name": "Ada",
      "active": true
    }
  ],
  "count": 1
}
```

The PHP built-in server is meant for local development. For a deployed instance, route the file through a normal PHP web
stack.

## HTTP API

The endpoint accepts only `POST` requests. Post to whichever URL routes to `elephpantdb.php`.

### Request

```json
{
  "sql": "SELECT * FROM users WHERE id = :id",
  "params": {
    "id": 1
  }
}
```

- `sql` is required and must contain one statement.
- `params` is optional. Use a JSON array for `?` placeholders or a JSON object for `:name` placeholders.
- Positional and named placeholders cannot be mixed in one statement.
- Every supplied parameter must be used, and every placeholder must have a value.
- The maximum request body size is 1 MiB.

### Authentication

Set `ELEPHPANTDB_TOKEN` to require the same value in the `X-Elephpant-Token` request header.

```sh
export ELEPHPANTDB_TOKEN='a-long-random-secret'
```

When `ELEPHPANTDB_TOKEN` is unset or empty, the endpoint accepts requests without authentication.

The token is only a second line of defence. The endpoint executes arbitrary supported SQL and has no users, roles, or
row-level authorization, so restrict it at nginx, another reverse proxy, or a network boundary. Do not expose it directly
to the public Internet.

### Responses

A statement that returns rows produces:

```json
{
  "ok": true,
  "rows": [],
  "count": 0
}
```

A statement that changes data or schema produces:

```json
{
  "ok": true,
  "rowsAffected": 1
}
```

Errors produce:

```json
{
  "ok": false,
  "error": "description"
}
```

Typical status codes are:

| Status | Meaning                                           |
|-------:|---------------------------------------------------|
|  `200` | Statement executed successfully.                  |
|  `400` | Invalid SQL, parameters, schema, or request body. |
|  `401` | Missing or invalid token.                         |
|  `405` | The request method was not `POST`.                |
|  `500` | Storage failure or unexpected internal failure.   |

## Using the PHP API from CLI code

Under a non-CLI SAPI the file handles the request itself. Under the CLI SAPI it only defines the engine classes, so CLI
programs and tests can drive the database directly:

```php
<?php

require __DIR__ . '/elephpantdb.php';

$db = new Database(__DIR__ . '/data');

$db->execute(
    'CREATE TABLE users (id INT PRIMARY KEY, name TEXT NOT NULL, active BOOL)'
);

$db->execute(
    'INSERT INTO users (id, name, active) VALUES (?, ?, ?)',
    [1, 'Ada', true],
);

$result = $db->execute(
    'SELECT id, name FROM users WHERE id = :id',
    ['id' => 1],
);

var_dump($result->rows);
```

`QueryResult` contains either:

- `rows`, for a `SELECT`; or
- `rowsAffected`, for a schema or data-changing statement.

## SQL reference

ElephpantDB supports one statement per call. A trailing semicolon is optional.

### Column types

| SQL type | PHP/JSON value                   |
|----------|----------------------------------|
| `INT`    | Integer                          |
| `FLOAT`  | Integer or floating-point number |
| `TEXT`   | String                           |
| `BOOL`   | Boolean                          |

Every type may contain `NULL` unless the column is declared `NOT NULL` or `PRIMARY KEY`.

### Create a table

```sql
CREATE TABLE users
(
    id     INT PRIMARY KEY,
    name   TEXT NOT NULL,
    score  FLOAT,
    active BOOL
)
```

A table must have at least one column and may declare at most one primary key. `PRIMARY KEY` implies `NOT NULL` and
creates an index automatically.

### Create an index

```sql
CREATE INDEX users_name ON users (name)
```

Only one index may exist for a given column. Indexes are hash based and accelerate equality predicates.

### Insert

```sql
INSERT INTO users (id, name, score, active)
VALUES (:id, :name, :score, :active)
```

The column list is required. Columns omitted from it receive `NULL`, subject to `NOT NULL` constraints.

### Select

```sql
SELECT id, name, score
FROM users
WHERE active = TRUE
  AND score >= :minimum
ORDER BY score DESC
LIMIT 20 OFFSET 0
```

Select every column with `*`:

```sql
SELECT * FROM users
```

Supported comparison operators:

```text
=  !=  <>  <  <=  >  >=
```

Supported predicate syntax:

```sql
name = 'Ada'
name <> 'Grace'
score >= 9.5
active = TRUE
name IS NULL
name IS NOT NULL
NOT active = FALSE
active = TRUE AND score > 5
active = TRUE OR score > 9
(active = TRUE OR score > 9) AND name IS NOT NULL
```

String literals use single quotes. Escape an apostrophe by doubling it:

```sql
name = 'O''Brien'
```

`NULL` follows SQL three-valued logic. Comparisons with `NULL` are unknown; use `IS NULL` or `IS NOT NULL` when testing
for null values.

`LIMIT` and `OFFSET` must be non-negative integers and may be supplied through placeholders.

### Update

```sql
UPDATE users
SET active = FALSE,
    score  = :score
WHERE id = :id
```

An update writes a replacement row and retires the old row. It does not overwrite the old record in place.

### Delete

```sql
DELETE
FROM users
WHERE active = FALSE
```

A delete marks matching records as dead. Disk space is reclaimed later by `VACUUM`.

### Vacuum

```sql
VACUUM users
```

`VACUUM` rewrites the table with only live records, replaces the old heap, and rebuilds every index. Its `rowsAffected`
value is the number of dead records removed.

## Identifiers

Table, column, index, and placeholder names must:

- begin with an ASCII letter or `_`;
- contain only ASCII letters, digits, and `_`; and
- be at most 64 characters long.

Examples:

```text
users
user_events
_private
users_2026
```

## How it works

```text
HTTP JSON request
       |
       v
   Tokenizer
       |
       v
     Parser
       |
       v
     Planner ---- equality probe ----> Hash index
       |                                  |
       v                                  v
    Executor --------------------------> Heap
       |
       v
  JSON response
```

### Parser and parameter binding

SQL is tokenized and parsed into statement and expression objects. Placeholders remain structured nodes until execution;
parameter values are never concatenated into SQL text.

Before execution, the engine validates identifiers, parameter use, column names, nullability, and value types.
Predicates use SQL-style three-valued logic for `NULL`.

### Per-table files

A table named `users` may create files like these in the data directory:

```text
users.schema
users.heap
users.lock
users.idx.id
users.idx.name
```

- `.schema` stores the binary column and index definitions.
- `.heap` is the authoritative row store.
- `.lock` coordinates readers and writers.
- `.idx.<column>` is a derivable hash index for one column.

Do not edit these files manually.

### Append-only heap

Rows are stored as variable-length records in an append-only heap. A row's identity is its byte offset, so the engine
does not need a separate row-ID counter.

Each record contains:

- its payload length;
- a CRC32 checksum;
- the offset of a record it supersedes, when applicable;
- a live/dead flag; and
- the encoded row values.

`INSERT` appends a live record. `UPDATE` appends a replacement and then marks the previous record dead. `DELETE` marks
records dead. This makes write ordering and crash recovery explicit while avoiding in-place row rewrites.

### Durability and recovery

Heap writes use unbuffered file handles and `fsync()` ordering:

1. The new record is appended and synced.
2. The heap header advances its acknowledged end marker and is synced.
3. For an update, the superseded record is retired and recovery state is cleared.

When a table opens, the engine verifies the heap header, truncates bytes beyond the last acknowledged record, and
resolves an interrupted superseding update.

Whenever records are read or scanned, their lengths and checksums are validated. Damage discovered at the tail can be
truncated safely; corruption in the middle of a heap is reported as a storage error rather than silently skipped.

Schema and rebuilt index files are written to a temporary file, synced, and renamed over the previous file. PHP cannot
`fsync()` the containing directory through its stream API, so a sudden power loss may lose an entire just-renamed
replacement, but readers should not observe a partially written replacement file.

### Indexes

Indexes are on-disk hash tables whose entries point to heap offsets. The planner uses an index for an equality
comparison on an indexed column found in the top-level `AND` chain of a `SELECT` predicate.

The heap remains authoritative:

- every index hit is read from the heap and checked against the complete predicate;
- a stale index catches up from the heap tail;
- a damaged index is discarded and rebuilt; and
- a bad index may make a query slower, but it is not trusted to determine correctness.

### Concurrency

Each table has one lock file. Reads take a shared lock, while writes, schema changes, recovery, compaction, and index
rebuilding take an exclusive lock.

Different PHP processes may use the same database directory on the same local machine, provided the filesystem
implements `flock()` correctly. ElephpantDB probes this behavior and refuses to continue when exclusion is ineffective.

## Configuration

| Environment variable | Default                    | Purpose                                                                       |
|----------------------|----------------------------|-------------------------------------------------------------------------------|
| `ELEPHPANTDB_DATA`   | `data` beside the PHP file | Directory containing database files.                                          |
| `ELEPHPANTDB_TOKEN`  | unset                      | Required value of `X-Elephpant-Token`. Authentication is disabled when empty. |

## Current limitations

Not implemented:

- transactions spanning multiple statements;
- joins, subqueries, aggregates, or grouping;
- foreign keys or check constraints;
- `ALTER TABLE`, `DROP TABLE`, or `DROP INDEX`;
- defaults, generated values, or auto-increment columns;
- a network protocol other than the JSON HTTP endpoint;
- user accounts, roles, or per-table authorization;
- shared-storage or distributed operation.

Implemented, with sharp edges:

- `PRIMARY KEY` creates a non-null indexed column, but duplicate key values are not currently rejected.
- Only indexed equality predicates accelerate `SELECT`; every other predicate scans the heap.
- `UPDATE` and `DELETE` collect their targets with a heap scan.
- Sorting is performed in memory after matching rows are collected.
- Identifiers are restricted to 64-character ASCII names.
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
