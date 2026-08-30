<?php

declare(strict_types=1);

$stocked = static function (): Database {
    $db = new Database(temporaryDirectory());
    $db->execute('CREATE TABLE t (id INT, name TEXT, score FLOAT, active BOOL)');

    $rows = [
        [1, 'ana', 9.5, true],
        [2, 'bo', 7.0, false],
        [3, 'carol', null, true],
        [4, null, 3.5, null],
    ];

    foreach ($rows as $row) {
        $db->execute('INSERT INTO t (id, name, score, active) VALUES (?, ?, ?, ?)', $row);
    }

    return $db;
};

$ids = static fn (Database $db, string $sql, array $params = []): array
    => array_column($db->execute($sql, $params)->rows, 'id');

return [
    'filters on equality' => function () use ($stocked, $ids): void {
        assertSame([2], $ids($stocked(), 'SELECT * FROM t WHERE id = ?', [2]));
    },

    'filters with every comparison operator' => function () use ($stocked, $ids): void {
        $db = $stocked();

        assertSame([2], $ids($db, 'SELECT * FROM t WHERE id = ?', [2]));
        assertSame([1, 3, 4], $ids($db, 'SELECT * FROM t WHERE id != ?', [2]));
        assertSame([1, 3, 4], $ids($db, 'SELECT * FROM t WHERE id <> ?', [2]));
        assertSame([1], $ids($db, 'SELECT * FROM t WHERE id < ?', [2]));
        assertSame([1, 2], $ids($db, 'SELECT * FROM t WHERE id <= ?', [2]));
        assertSame([3, 4], $ids($db, 'SELECT * FROM t WHERE id > ?', [2]));
        assertSame([2, 3, 4], $ids($db, 'SELECT * FROM t WHERE id >= ?', [2]));
    },

    'compares text lexicographically, not numerically' => function () use ($ids): void {
        $db = new Database(temporaryDirectory());
        $db->execute('CREATE TABLE t (id INT, label TEXT)');
        $db->execute('INSERT INTO t (id, label) VALUES (1, ?)', ['10']);
        $db->execute('INSERT INTO t (id, label) VALUES (2, ?)', ['9']);

        assertSame([1], $ids($db, 'SELECT * FROM t WHERE label < ?', ['9']));
    },

    'compares integers against floats' => function () use ($stocked, $ids): void {
        assertSame([2], $ids($stocked(), 'SELECT * FROM t WHERE score = ?', [7]));
        assertSame([1], $ids($stocked(), 'SELECT * FROM t WHERE score > ?', [8]));
    },

    'orders booleans with false below true' => function () use ($stocked, $ids): void {
        assertSame([1, 3], $ids($stocked(), 'SELECT * FROM t WHERE active = ?', [true]));
        assertSame([1, 3], $ids($stocked(), 'SELECT * FROM t WHERE active > ?', [false]));
    },

    'compares one column against another' => function () use ($ids): void {
        $db = new Database(temporaryDirectory());
        $db->execute('CREATE TABLE t (id INT, a INT, b INT)');
        $db->execute('INSERT INTO t (id, a, b) VALUES (1, 5, 9)');
        $db->execute('INSERT INTO t (id, a, b) VALUES (2, 9, 5)');

        assertSame([1], $ids($db, 'SELECT * FROM t WHERE a < b'));
    },

    'never matches a null with a comparison' => function () use ($stocked, $ids): void {
        $db = $stocked();

        assertSame([], $ids($db, 'SELECT * FROM t WHERE name = ?', [null]));
        assertSame([], $ids($db, 'SELECT * FROM t WHERE name != ?', [null]));
        assertSame([1, 2, 3], $ids($db, 'SELECT * FROM t WHERE name != ?', ['zoe']));
        assertSame([1, 2, 4], $ids($db, 'SELECT * FROM t WHERE score > ?', [1.0]));
    },

    'keeps a null unknown under NOT rather than flipping it true' => function () use ($stocked, $ids): void {
        assertSame([1, 2, 3], $ids($stocked(), 'SELECT * FROM t WHERE NOT name = ?', ['zoe']));
    },

    'treats AND with an unknown as unknown unless the other side is false' => function () use ($stocked, $ids): void {
        $db = $stocked();

        assertSame([], $ids($db, 'SELECT * FROM t WHERE score > ? AND name = ?', [0, null]));
        assertSame([], $ids($db, 'SELECT * FROM t WHERE id > ? AND score > ?', [99, 0]));
    },

    'treats OR with an unknown as true when the other side is true' => function () use ($stocked, $ids): void {
        $db = $stocked();

        assertSame([1, 2, 3, 4], $ids($db, 'SELECT * FROM t WHERE id > ? OR score = ?', [0, null]));
        assertSame([3], $ids($db, 'SELECT * FROM t WHERE id = ? OR score = ?', [3, null]));
    },

    'tests for null explicitly' => function () use ($stocked, $ids): void {
        $db = $stocked();

        assertSame([3], $ids($db, 'SELECT * FROM t WHERE score IS NULL'));
        assertSame([4], $ids($db, 'SELECT * FROM t WHERE name IS NULL'));
        assertSame([1, 2, 4], $ids($db, 'SELECT * FROM t WHERE score IS NOT NULL'));
    },

    'combines a null test with a comparison' => function () use ($stocked, $ids): void {
        assertSame([1, 2], $ids($stocked(), 'SELECT * FROM t WHERE score IS NOT NULL AND name IS NOT NULL'));
    },

    'applies parentheses to the result, not just the tree' => function () use ($stocked, $ids): void {
        $db = $stocked();

        assertSame([1, 2], $ids($db, 'SELECT * FROM t WHERE id = ? OR id = ? AND active = ?', [1, 2, false]));
        assertSame([2], $ids($db, 'SELECT * FROM t WHERE (id = ? OR id = ?) AND active = ?', [1, 2, false]));
    },

    'filters with a literal written into the statement' => function () use ($stocked, $ids): void {
        assertSame([1], $ids($stocked(), "SELECT * FROM t WHERE name = 'ana'"));
        assertSame([4], $ids($stocked(), 'SELECT * FROM t WHERE score < 5.0'));
    },

    'projects only the requested columns from filtered rows' => function () use ($stocked): void {
        $rows = $stocked()->execute('SELECT name FROM t WHERE id = ?', [1])->rows;

        assertSame([['name' => 'ana']], $rows);
    },

    'rejects a parameter whose type cannot meet the column' => function () use ($stocked): void {
        $db = $stocked();

        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM t WHERE id = ?', ['seven']));
        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM t WHERE name = ?', [7]));
        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM t WHERE active = ?', [1]));
    },

    'rejects a literal whose type cannot meet the column' => function () use ($stocked): void {
        assertThrows(SchemaException::class, fn () => $stocked()->execute("SELECT * FROM t WHERE id = 'seven'"));
    },

    'rejects comparing columns of unrelated types' => function () use ($stocked): void {
        assertThrows(SchemaException::class, fn () => $stocked()->execute('SELECT * FROM t WHERE id = name'));
    },

    'allows comparing an integer column with a float column' => function () use ($stocked): void {
        assertSame(0, count($stocked()->execute('SELECT * FROM t WHERE id = score')->rows));
    },

    'rejects an unknown column in a where clause' => function () use ($stocked): void {
        assertThrows(SchemaException::class, fn () => $stocked()->execute('SELECT * FROM t WHERE missing = ?', [1]));
    },

    'reports a type error before scanning rather than per row' => function () use ($ids): void {
        $db = new Database(temporaryDirectory());
        $db->execute('CREATE TABLE t (id INT, name TEXT)');

        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM t WHERE id = ?', ['seven']));
    },

    'counts a where clause parameter as used' => function () use ($stocked): void {
        assertThrows(
            SchemaException::class,
            fn () => $stocked()->execute('SELECT * FROM t WHERE id = ?', [1, 2]),
        );
    },
];
