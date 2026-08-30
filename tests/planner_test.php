<?php

declare(strict_types=1);

const PLANNER_ROW_COUNT = 100000;

$indexed = static function (): Database {
    $db = new Database(temporaryDirectory());
    $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT, age INT)');

    foreach ([[1, 'ana', 41], [2, 'bo', 33], [3, 'carol', 55], [4, 'dave', 33]] as $row) {
        $db->execute('INSERT INTO t (id, name, age) VALUES (?, ?, ?)', $row);
    }

    return $db;
};

$reads = static function (Database $db, string $sql, array $params = []): array {
    Heap::resetRecordReads();
    $rows = $db->execute($sql, $params)->rows;

    return [$rows, Heap::recordReads()];
};

return [
    'finds a primary key in a large table without scanning it' => function () use ($reads): void {
        $db = new Database(temporaryDirectory());
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');

        for ($id = 1; $id <= PLANNER_ROW_COUNT; $id++) {
            $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [$id, 'n' . $id]);
        }

        [$rows, $count] = $reads($db, 'SELECT * FROM t WHERE id = ?', [PLANNER_ROW_COUNT - 7]);

        assertSame([['id' => PLANNER_ROW_COUNT - 7, 'name' => 'n' . (PLANNER_ROW_COUNT - 7)]], $rows);
        assertTrue($count < 10, "a primary key lookup read {$count} records, expected fewer than 10");
    },

    'uses the index for an equality test' => function () use ($indexed, $reads): void {
        [$rows, $count] = $reads($indexed(), 'SELECT * FROM t WHERE id = ?', [3]);

        assertSame([['id' => 3, 'name' => 'carol', 'age' => 55]], $rows);
        assertTrue($count < 4, "expected an index probe, read {$count} records");
    },

    'uses the index for an equality buried in an AND chain' => function () use ($indexed, $reads): void {
        [$rows, $count] = $reads($indexed(), 'SELECT * FROM t WHERE age > ? AND id = ? AND name != ?', [1, 3, 'zoe']);

        assertSame([['id' => 3, 'name' => 'carol', 'age' => 55]], $rows);
        assertTrue($count < 4, "expected an index probe, read {$count} records");
    },

    'uses the index when the column is on the right of the comparison' => function () use ($indexed, $reads): void {
        [$rows, $count] = $reads($indexed(), 'SELECT * FROM t WHERE ? = id', [3]);

        assertSame([['id' => 3, 'name' => 'carol', 'age' => 55]], $rows);
        assertTrue($count < 4, "expected an index probe, read {$count} records");
    },

    'falls back to a scan for a top-level OR' => function () use ($indexed, $reads): void {
        [$rows, $count] = $reads($indexed(), 'SELECT * FROM t WHERE id = ? OR id = ?', [1, 3]);

        assertSame([1, 3], array_column($rows, 'id'));
        assertTrue($count >= 4, 'an OR must not be answered from a single probe');
    },

    'falls back to a scan for a column with no index' => function () use ($indexed, $reads): void {
        [$rows, $count] = $reads($indexed(), 'SELECT * FROM t WHERE age = ?', [33]);

        assertSame([2, 4], array_column($rows, 'id'));
        assertTrue($count >= 4, 'an unindexed equality must scan');
    },

    'falls back to a scan for a range comparison' => function () use ($indexed, $reads): void {
        [$rows, $count] = $reads($indexed(), 'SELECT * FROM t WHERE id > ?', [2]);

        assertSame([3, 4], array_column($rows, 'id'));
        assertTrue($count >= 4, 'a range test cannot use a hash index');
    },

    'falls back to a scan under NOT' => function () use ($indexed, $reads): void {
        [$rows, $count] = $reads($indexed(), 'SELECT * FROM t WHERE NOT id = ?', [1]);

        assertSame([2, 3, 4], array_column($rows, 'id'));
        assertTrue($count >= 4, 'a negated equality must scan');
    },

    'falls back to a scan for an equality against null' => function () use ($indexed, $reads): void {
        [$rows, $count] = $reads($indexed(), 'SELECT * FROM t WHERE id = ?', [null]);

        assertSame([], $rows);
        assertTrue($count >= 4, 'nulls are never indexed, so this must scan');
    },

    'falls back to a scan when both sides are columns' => function () use ($indexed, $reads): void {
        [$rows, $count] = $reads($indexed(), 'SELECT * FROM t WHERE id = age');

        assertSame([], $rows);
        assertTrue($count >= 4);
    },

    'agrees with a scan on every query shape' => function () use ($indexed): void {
        $db = $indexed();
        $scanned = new Database(temporaryDirectory());
        $scanned->execute('CREATE TABLE t (id INT, name TEXT, age INT)');

        foreach ([[1, 'ana', 41], [2, 'bo', 33], [3, 'carol', 55], [4, 'dave', 33]] as $row) {
            $scanned->execute('INSERT INTO t (id, name, age) VALUES (?, ?, ?)', $row);
        }

        $queries = [
            ['SELECT * FROM t WHERE id = ?', [3]],
            ['SELECT * FROM t WHERE id = ? AND age > ?', [3, 1]],
            ['SELECT * FROM t WHERE id = ? AND age > ?', [3, 99]],
            ['SELECT * FROM t WHERE id = ? OR age = ?', [1, 33]],
            ['SELECT id FROM t WHERE id = ? ORDER BY id DESC', [2]],
            ['SELECT * FROM t WHERE id = ?', [999]],
            ['SELECT * FROM t WHERE id = ? AND name IS NOT NULL', [4]],
        ];

        foreach ($queries as [$sql, $params]) {
            assertSame(
                $scanned->execute($sql, $params)->rows,
                $db->execute($sql, $params)->rows,
                $sql,
            );
        }
    },

    'applies order and limit to an indexed result' => function () use ($indexed): void {
        $db = $indexed();
        $db->execute('INSERT INTO t (id, name, age) VALUES (?, ?, ?)', [5, 'eve', 33]);

        assertSame([['id' => 5]], $db->execute('SELECT id FROM t WHERE id = ? LIMIT 1', [5])->rows);
        assertSame([], $db->execute('SELECT id FROM t WHERE id = ? LIMIT 0', [5])->rows);
    },

    'never returns a retired row through the index' => function () use ($indexed, $reads): void {
        $db = $indexed();
        $db->execute('DELETE FROM t WHERE id = ?', [3]);

        [$rows, $count] = $reads($db, 'SELECT * FROM t WHERE id = ?', [3]);

        assertSame([], $rows);
        assertTrue($count < 4, 'the probe must still be a probe');
    },

    'returns an updated row exactly once through the index' => function () use ($indexed, $reads): void {
        $db = $indexed();

        for ($round = 0; $round < 50; $round++) {
            $db->execute('UPDATE t SET name = ? WHERE id = ?', ['round-' . $round, 3]);
        }

        [$rows, $count] = $reads($db, 'SELECT * FROM t WHERE id = ?', [3]);

        assertSame([['id' => 3, 'name' => 'round-49', 'age' => 55]], $rows);
        assertTrue($count < 10, "a hot key cost {$count} record reads");
    },

    'uses a secondary index created after the rows existed' => function () use ($indexed, $reads): void {
        $db = $indexed();
        $db->execute('CREATE INDEX idx_age ON t (age)');

        [$rows, $count] = $reads($db, 'SELECT id FROM t WHERE age = ?', [33]);

        assertSame([2, 4], array_column($rows, 'id'));
        assertTrue($count < 4, "expected an index probe, read {$count} records");
    },

    'rejects a second index on the same column' => function () use ($indexed): void {
        $db = $indexed();
        $db->execute('CREATE INDEX idx_age ON t (age)');

        assertThrows(SchemaException::class, fn () => $db->execute('CREATE INDEX idx_age_again ON t (age)'));
    },

    'rejects an index on an unknown column' => function () use ($indexed): void {
        assertThrows(SchemaException::class, fn () => $indexed()->execute('CREATE INDEX idx_x ON t (missing)'));
    },

    'keeps a secondary index current through later writes' => function () use ($indexed, $reads): void {
        $db = $indexed();
        $db->execute('CREATE INDEX idx_age ON t (age)');
        $db->execute('INSERT INTO t (id, name, age) VALUES (?, ?, ?)', [5, 'eve', 33]);
        $db->execute('UPDATE t SET age = ? WHERE id = ?', [33, 1]);
        $db->execute('DELETE FROM t WHERE id = ?', [2]);

        [$rows, $count] = $reads($db, 'SELECT id FROM t WHERE age = ? ORDER BY id', [33]);

        assertSame([1, 4, 5], array_column($rows, 'id'));
        assertTrue($count < 8, "expected an index probe, read {$count} records");
    },

    'rejects an index name that is already taken' => function () use ($indexed): void {
        $db = $indexed();
        $db->execute('CREATE INDEX idx_age ON t (age)');

        assertThrows(SchemaException::class, fn () => $db->execute('CREATE INDEX idx_age ON t (name)'));
    },

    'rejects creating an index on an unknown table' => function () use ($indexed): void {
        assertThrows(SchemaException::class, fn () => $indexed()->execute('CREATE INDEX idx_x ON missing (id)'));
    },

    'keeps the primary key index name reserved' => function () use ($indexed): void {
        assertThrows(SchemaException::class, fn () => $indexed()->execute('CREATE INDEX pk ON t (name)'));
    },
];
