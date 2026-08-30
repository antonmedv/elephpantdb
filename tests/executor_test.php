<?php

declare(strict_types=1);

$database = static fn (): Database => new Database(temporaryDirectory());

$populated = static function () use ($database): Database {
    $db = $database();
    $db->execute('CREATE TABLE users (id INT PRIMARY KEY, name TEXT NOT NULL, score FLOAT, active BOOL)');
    $db->execute('INSERT INTO users (id, name, score, active) VALUES (?, ?, ?, ?)', [1, 'ana', 9.5, true]);
    $db->execute('INSERT INTO users (id, name, score, active) VALUES (?, ?, ?, ?)', [2, 'bo', 7.0, false]);

    return $db;
};

return [
    'creates the schema and heap files' => function (): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $result = $db->execute('CREATE TABLE users (id INT PRIMARY KEY, name TEXT)');

        assertSame(0, $result->rowsAffected);
        assertTrue(is_file($directory . '/users.schema'));
        assertTrue(is_file($directory . '/users.heap'));
    },

    'creates the data directory if it is missing' => function (): void {
        $directory = temporaryDirectory() . '/nested/data';
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT)');

        assertTrue(is_file($directory . '/t.schema'));
    },

    'refuses to create a table twice' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE users (id INT)');

        $failure = assertThrows(SchemaException::class, fn () => $db->execute('CREATE TABLE users (id INT)'));
        assertStringContains('already exists', $failure->getMessage());
    },

    'round-trips a row through insert and select' => function () use ($populated): void {
        $rows = $populated()->execute('SELECT * FROM users')->rows;

        assertSame(2, count($rows));
        assertSame(['id' => 1, 'name' => 'ana', 'score' => 9.5, 'active' => true], $rows[0]);
        assertSame(['id' => 2, 'name' => 'bo', 'score' => 7.0, 'active' => false], $rows[1]);
    },

    'reports how many rows an insert affected' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT)');

        $result = $db->execute('INSERT INTO t (id) VALUES (?)', [1]);

        assertSame(1, $result->rowsAffected);
        assertSame(null, $result->rows);
    },

    'binds named parameters' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT, name TEXT)');
        $db->execute('INSERT INTO t (id, name) VALUES (:id, :name)', ['name' => 'ana', 'id' => 7]);

        assertSame([['id' => 7, 'name' => 'ana']], $db->execute('SELECT * FROM t')->rows);
    },

    'binds literals written directly into the statement' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT, name TEXT, ok BOOL)');
        $db->execute("INSERT INTO t (id, name, ok) VALUES (-7, 'it''s', TRUE)");

        assertSame([['id' => -7, 'name' => "it's", 'ok' => true]], $db->execute('SELECT * FROM t')->rows);
    },

    'leaves an omitted column null' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT, name TEXT)');
        $db->execute('INSERT INTO t (id) VALUES (?)', [1]);

        assertSame([['id' => 1, 'name' => null]], $db->execute('SELECT * FROM t')->rows);
    },

    'rejects a null in a not null column' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT, name TEXT NOT NULL)');

        $failure = assertThrows(SchemaException::class, fn () => $db->execute('INSERT INTO t (id) VALUES (?)', [1]));
        assertStringContains('name', $failure->getMessage());

        assertThrows(SchemaException::class, fn () => $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, null]));
    },

    'rejects an unknown column in an insert' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT)');

        assertThrows(SchemaException::class, fn () => $db->execute('INSERT INTO t (missing) VALUES (?)', [1]));
    },

    'rejects the same column twice in an insert' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT, name TEXT)');

        assertThrows(SchemaException::class, fn () => $db->execute('INSERT INTO t (id, id) VALUES (?, ?)', [1, 2]));
    },

    'rejects a value of the wrong type' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT)');

        assertThrows(SchemaException::class, fn () => $db->execute('INSERT INTO t (id) VALUES (?)', ['seven']));
    },

    'rejects too few and too many positional parameters' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT, name TEXT)');

        assertThrows(SchemaException::class, fn () => $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1]));
        assertThrows(SchemaException::class, fn () => $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'a', 'b']));
    },

    'rejects a missing named parameter' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT, name TEXT)');

        $failure = assertThrows(
            SchemaException::class,
            fn () => $db->execute('INSERT INTO t (id, name) VALUES (:id, :name)', ['id' => 1]),
        );
        assertStringContains('name', $failure->getMessage());
    },

    'rejects a named parameter that the statement never uses' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT)');

        assertThrows(
            SchemaException::class,
            fn () => $db->execute('INSERT INTO t (id) VALUES (:id)', ['id' => 1, 'stray' => 2]),
        );
    },

    'projects the columns a select asks for, in that order' => function () use ($populated): void {
        $rows = $populated()->execute('SELECT name, id FROM users')->rows;

        assertSame(['name' => 'ana', 'id' => 1], $rows[0]);
        assertSame(['name', 'id'], array_keys($rows[1]));
    },

    'uses the declared column name whatever case the query used' => function () use ($populated): void {
        $rows = $populated()->execute('SELECT NAME FROM users')->rows;

        assertSame(['name' => 'ana'], $rows[0]);
    },

    'rejects an unknown column in a select' => function () use ($populated): void {
        assertThrows(SchemaException::class, fn () => $populated()->execute('SELECT missing FROM users'));
    },

    'rejects an unknown table' => function () use ($database): void {
        $db = $database();

        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM missing'));
        assertThrows(SchemaException::class, fn () => $db->execute('INSERT INTO missing (id) VALUES (?)', [1]));
    },

    'returns no rows from an empty table' => function () use ($database): void {
        $db = $database();
        $db->execute('CREATE TABLE t (id INT)');

        assertSame([], $db->execute('SELECT * FROM t')->rows);
    },

    'survives being reopened' => function (): void {
        $directory = temporaryDirectory();

        $first = new Database($directory);
        $first->execute('CREATE TABLE t (id INT, name TEXT)');
        $first->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'ana']);

        $second = new Database($directory);
        assertSame([['id' => 1, 'name' => 'ana']], $second->execute('SELECT * FROM t')->rows);
    },

    'reports a parse error rather than touching the filesystem' => function () use ($database): void {
        assertThrows(ParseException::class, fn () => $database()->execute('SELECT * FORM t'));
    },
];
