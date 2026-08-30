<?php

declare(strict_types=1);

$stocked = static function (): Database {
    $db = new Database(temporaryDirectory());
    $db->execute('CREATE TABLE t (id INT, name TEXT, score FLOAT, active BOOL)');

    $rows = [
        [1, 'carol', 7.5, true],
        [2, 'ana', 9.5, false],
        [3, 'bo', 7.5, true],
        [4, null, null, null],
        [5, 'dave', 1.0, false],
    ];

    foreach ($rows as $row) {
        $db->execute('INSERT INTO t (id, name, score, active) VALUES (?, ?, ?, ?)', $row);
    }

    return $db;
};

$ids = static fn (Database $db, string $sql, array $params = []): array
    => array_column($db->execute($sql, $params)->rows, 'id');

return [
    'orders by an integer column' => function () use ($stocked, $ids): void {
        assertSame([1, 2, 3, 4, 5], $ids($stocked(), 'SELECT * FROM t ORDER BY id'));
        assertSame([5, 4, 3, 2, 1], $ids($stocked(), 'SELECT * FROM t ORDER BY id DESC'));
        assertSame([1, 2, 3, 4, 5], $ids($stocked(), 'SELECT * FROM t ORDER BY id ASC'));
    },

    'orders text lexicographically' => function () use ($stocked, $ids): void {
        assertSame([2, 3, 1, 5, 4], $ids($stocked(), 'SELECT * FROM t ORDER BY name'));
    },

    'orders floats' => function () use ($stocked, $ids): void {
        assertSame([5, 1, 3, 2, 4], $ids($stocked(), 'SELECT * FROM t ORDER BY score'));
    },

    'orders booleans with false below true' => function () use ($stocked, $ids): void {
        assertSame([2, 5, 1, 3, 4], $ids($stocked(), 'SELECT * FROM t ORDER BY active'));
    },

    'puts nulls last in both directions' => function () use ($stocked, $ids): void {
        assertSame(4, $ids($stocked(), 'SELECT * FROM t ORDER BY name')[4]);
        assertSame(4, $ids($stocked(), 'SELECT * FROM t ORDER BY name DESC')[4]);
        assertSame(4, $ids($stocked(), 'SELECT * FROM t ORDER BY score DESC')[4]);
    },

    'keeps ties in insertion order' => function () use ($stocked, $ids): void {
        assertSame([1, 3], array_slice($ids($stocked(), 'SELECT * FROM t ORDER BY score'), 1, 2));
        assertSame([1, 3], array_slice($ids($stocked(), 'SELECT * FROM t ORDER BY score DESC'), 1, 2));
    },

    'limits the number of rows' => function () use ($stocked, $ids): void {
        assertSame([1, 2], $ids($stocked(), 'SELECT * FROM t LIMIT 2'));
        assertSame([], $ids($stocked(), 'SELECT * FROM t LIMIT 0'));
        assertSame([1, 2, 3, 4, 5], $ids($stocked(), 'SELECT * FROM t LIMIT 99'));
    },

    'skips rows with offset' => function () use ($stocked, $ids): void {
        assertSame([3, 4], $ids($stocked(), 'SELECT * FROM t LIMIT 2 OFFSET 2'));
        assertSame([], $ids($stocked(), 'SELECT * FROM t LIMIT 2 OFFSET 99'));
        assertSame([1, 2, 3, 4, 5], $ids($stocked(), 'SELECT * FROM t LIMIT 99 OFFSET 0'));
    },

    'applies the order before the limit' => function () use ($stocked, $ids): void {
        assertSame([5, 4, 3], $ids($stocked(), 'SELECT * FROM t ORDER BY id DESC LIMIT 3'));
        assertSame([2, 1], $ids($stocked(), 'SELECT * FROM t ORDER BY score DESC LIMIT 2'));
    },

    'applies the filter before the order and the limit' => function () use ($stocked, $ids): void {
        assertSame([5, 4], $ids($stocked(), 'SELECT * FROM t WHERE id > ? ORDER BY id DESC LIMIT 2', [1]));
    },

    'binds limit and offset from parameters' => function () use ($stocked, $ids): void {
        assertSame([3, 4], $ids($stocked(), 'SELECT * FROM t LIMIT ? OFFSET ?', [2, 2]));
        assertSame([3, 4], $ids($stocked(), 'SELECT * FROM t LIMIT :take OFFSET :skip', ['take' => 2, 'skip' => 2]));
    },

    'rejects a negative limit or offset' => function () use ($stocked): void {
        $db = $stocked();

        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM t LIMIT ?', [-1]));
        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM t LIMIT 2 OFFSET ?', [-1]));
        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM t LIMIT -1'));
    },

    'rejects a limit that is not an integer' => function () use ($stocked): void {
        $db = $stocked();

        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM t LIMIT ?', ['two']));
        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM t LIMIT ?', [1.5]));
        assertThrows(SchemaException::class, fn () => $db->execute('SELECT * FROM t LIMIT ?', [null]));
    },

    'rejects an unknown column in order by' => function () use ($stocked): void {
        assertThrows(SchemaException::class, fn () => $stocked()->execute('SELECT * FROM t ORDER BY missing'));
    },

    'orders by a column the projection leaves out' => function () use ($stocked): void {
        $rows = $stocked()->execute('SELECT id FROM t ORDER BY name LIMIT 2')->rows;

        assertSame([['id' => 2], ['id' => 3]], $rows);
    },

    'rejects offset without limit' => function () use ($stocked): void {
        assertThrows(ParseException::class, fn () => $stocked()->execute('SELECT * FROM t OFFSET 2'));
    },

    'rejects order by after limit' => function () use ($stocked): void {
        assertThrows(ParseException::class, fn () => $stocked()->execute('SELECT * FROM t LIMIT 2 ORDER BY id'));
    },

    'rejects order by without a column' => function () use ($stocked): void {
        assertThrows(ParseException::class, fn () => $stocked()->execute('SELECT * FROM t ORDER BY'));
        assertThrows(ParseException::class, fn () => $stocked()->execute('SELECT * FROM t ORDER id'));
    },

    'counts limit and offset parameters as used' => function () use ($stocked): void {
        assertThrows(SchemaException::class, fn () => $stocked()->execute('SELECT * FROM t LIMIT ?', [2, 3]));
    },
];
