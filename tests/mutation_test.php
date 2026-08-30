<?php

declare(strict_types=1);

$stocked = static function (?string $directory = null): Database {
    $db = new Database($directory ?? temporaryDirectory());
    $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT, score FLOAT)');

    foreach ([[1, 'ana', 9.5], [2, 'bo', 7.0], [3, 'carol', 3.5]] as $row) {
        $db->execute('INSERT INTO t (id, name, score) VALUES (?, ?, ?)', $row);
    }

    return $db;
};

$rows = static fn (Database $db, string $sql = 'SELECT * FROM t ORDER BY id', array $params = []): array
    => $db->execute($sql, $params)->rows;

return [
    'updates a matching row exactly once' => function () use ($stocked, $rows): void {
        $db = $stocked();
        $result = $db->execute('UPDATE t SET name = ? WHERE id = ?', ['ANA', 1]);

        assertSame(1, $result->rowsAffected);
        assertSame(null, $result->rows);

        $all = $rows($db);
        assertSame(3, count($all));
        assertSame('ANA', $all[0]['name']);
        assertSame([1, 2, 3], array_column($all, 'id'));
    },

    'updates several columns at once' => function () use ($stocked, $rows): void {
        $db = $stocked();
        $db->execute('UPDATE t SET name = ?, score = ? WHERE id = ?', ['zed', 1.5, 2]);

        assertSame(['id' => 2, 'name' => 'zed', 'score' => 1.5], $rows($db)[1]);
    },

    'reports how many rows an update affected' => function () use ($stocked): void {
        $db = $stocked();

        assertSame(0, $db->execute('UPDATE t SET name = ? WHERE id = ?', ['x', 99])->rowsAffected);
        assertSame(2, $db->execute('UPDATE t SET name = ? WHERE id > ?', ['x', 1])->rowsAffected);
        assertSame(3, $db->execute('UPDATE t SET score = ?', [0.0])->rowsAffected);
    },

    'updates every row when there is no where clause' => function () use ($stocked, $rows): void {
        $db = $stocked();
        $db->execute('UPDATE t SET name = ?', ['same']);

        assertSame(['same', 'same', 'same'], array_column($rows($db), 'name'));
    },

    'finds an updated row by its new value' => function () use ($stocked, $rows): void {
        $db = $stocked();
        $db->execute('UPDATE t SET name = ? WHERE id = ?', ['zed', 1]);

        assertSame([1], array_column($rows($db, 'SELECT id FROM t WHERE name = ?', ['zed']), 'id'));
        assertSame([], $rows($db, 'SELECT id FROM t WHERE name = ?', ['ana']));
    },

    'appends a new record rather than rewriting in place' => function () use ($stocked): void {
        $directory = temporaryDirectory();
        $db = $stocked($directory);
        $before = filesize($directory . '/t.heap');

        $db->execute('UPDATE t SET name = ? WHERE id = ?', ['ana revised', 1]);

        assertTrue(filesize($directory . '/t.heap') > $before, 'an update must append');
    },

    'points the new record at the one it replaces' => function () use ($stocked): void {
        $directory = temporaryDirectory();
        $db = $stocked($directory);
        $db->execute('UPDATE t SET name = ? WHERE id = ?', ['ana revised', 1]);

        $paths = new Paths($directory);
        $heap = new Heap($paths->heap('t'), new Lock($paths->lock('t')), 3);
        $records = iterator_to_array($heap->scan(includeDead: true), false);

        assertSame(4, count($records));
        assertSame(Heap::HEADER_BYTES, $records[0]->offset);
        assertFalse($records[0]->live, 'the replaced record must be dead');
        assertTrue($records[3]->live);
        assertSame($records[0]->offset, $records[3]->supersedes);
        $heap->close();
    },

    'changes only the flags byte of the record it retires' => function () use ($stocked): void {
        $directory = temporaryDirectory();
        $db = $stocked($directory);
        $path = $directory . '/t.heap';

        $before = (string) file_get_contents($path);
        $recordLength = Heap::RECORD_HEADER_BYTES + unpack('N', substr($before, Heap::HEADER_BYTES, 4))[1];
        $original = substr($before, Heap::HEADER_BYTES, $recordLength);

        $db->execute('UPDATE t SET name = ? WHERE id = ?', ['ana revised', 1]);

        $after = substr((string) file_get_contents($path), Heap::HEADER_BYTES, $recordLength);
        $differing = [];

        for ($index = 0; $index < $recordLength; $index++) {
            if ($original[$index] !== $after[$index]) {
                $differing[] = $index;
            }
        }

        assertSame([Heap::FLAGS_OFFSET_IN_RECORD], $differing);
    },

    'deletes a matching row' => function () use ($stocked, $rows): void {
        $db = $stocked();
        $result = $db->execute('DELETE FROM t WHERE id = ?', [2]);

        assertSame(1, $result->rowsAffected);
        assertSame([1, 3], array_column($rows($db), 'id'));
    },

    'reports how many rows a delete affected' => function () use ($stocked): void {
        $db = $stocked();

        assertSame(0, $db->execute('DELETE FROM t WHERE id = ?', [99])->rowsAffected);
        assertSame(2, $db->execute('DELETE FROM t WHERE id > ?', [1])->rowsAffected);
    },

    'deletes every row when there is no where clause' => function () use ($stocked, $rows): void {
        $db = $stocked();

        assertSame(3, $db->execute('DELETE FROM t')->rowsAffected);
        assertSame([], $rows($db));
    },

    'does not resurrect a deleted row on a later update' => function () use ($stocked, $rows): void {
        $db = $stocked();
        $db->execute('DELETE FROM t WHERE id = ?', [2]);

        assertSame(0, $db->execute('UPDATE t SET name = ? WHERE id = ?', ['x', 2])->rowsAffected);
        assertSame([1, 3], array_column($rows($db), 'id'));
    },

    'updates a row more than once' => function () use ($stocked, $rows): void {
        $db = $stocked();

        foreach (['one', 'two', 'three'] as $name) {
            $db->execute('UPDATE t SET name = ? WHERE id = ?', [$name, 1]);
        }

        assertSame(3, count($rows($db)));
        assertSame('three', $rows($db)[0]['name']);
    },

    'survives a reopen after mutations' => function () use ($stocked, $rows): void {
        $directory = temporaryDirectory();
        $db = $stocked($directory);
        $db->execute('UPDATE t SET name = ? WHERE id = ?', ['zed', 1]);
        $db->execute('DELETE FROM t WHERE id = ?', [3]);

        $reopened = new Database($directory);

        assertSame([['id' => 1, 'name' => 'zed'], ['id' => 2, 'name' => 'bo']], $rows($reopened, 'SELECT id, name FROM t ORDER BY id'));
    },

    'binds update parameters in both styles' => function () use ($stocked, $rows): void {
        $db = $stocked();
        $db->execute('UPDATE t SET name = :name WHERE id = :id', ['name' => 'named', 'id' => 1]);

        assertSame('named', $rows($db)[0]['name']);
    },

    'rejects an unknown column in an update' => function () use ($stocked): void {
        assertThrows(SchemaException::class, fn () => $stocked()->execute('UPDATE t SET missing = ?', ['x']));
    },

    'rejects the same column assigned twice' => function () use ($stocked): void {
        assertThrows(SchemaException::class, fn () => $stocked()->execute('UPDATE t SET name = ?, name = ?', ['a', 'b']));
    },

    'rejects setting a not null column to null' => function (): void {
        $db = new Database(temporaryDirectory());
        $db->execute('CREATE TABLE t (id INT, name TEXT NOT NULL)');
        $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'ana']);

        assertThrows(SchemaException::class, fn () => $db->execute('UPDATE t SET name = ?', [null]));
    },

    'rejects an assignment of the wrong type' => function () use ($stocked): void {
        assertThrows(SchemaException::class, fn () => $stocked()->execute('UPDATE t SET id = ?', ['seven']));
    },

    'rejects a mutation on an unknown table' => function (): void {
        $db = new Database(temporaryDirectory());

        assertThrows(SchemaException::class, fn () => $db->execute('UPDATE missing SET a = ?', [1]));
        assertThrows(SchemaException::class, fn () => $db->execute('DELETE FROM missing'));
    },

    'leaves the table untouched when an assignment is rejected' => function () use ($stocked, $rows): void {
        $db = $stocked();

        assertThrows(SchemaException::class, fn () => $db->execute('UPDATE t SET id = ?', ['seven']));
        assertSame(['ana', 'bo', 'carol'], array_column($rows($db), 'name'));
    },

    'counts mutation parameters as used' => function () use ($stocked): void {
        assertThrows(SchemaException::class, fn () => $stocked()->execute('DELETE FROM t WHERE id = ?', [1, 2]));
    },
];
