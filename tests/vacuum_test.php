<?php

declare(strict_types=1);

$stocked = static function (int $rows = 20): array {
    $directory = temporaryDirectory();
    $db = new Database($directory);
    $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT, score FLOAT, active BOOL)');

    for ($id = 1; $id <= $rows; $id++) {
        $db->execute(
            'INSERT INTO t (id, name, score, active) VALUES (?, ?, ?, ?)',
            [$id, 'n' . $id, $id / 2, $id % 2 === 0],
        );
    }

    return [$db, $directory];
};

$openIndex = static fn (string $directory, string $column): HashIndex
    => new HashIndex((new Paths($directory))->index('t', $column), new Lock((new Paths($directory))->lock('t')));

return [
    'keeps every live row' => function () use ($stocked): void {
        [$db] = $stocked();
        $before = $db->execute('SELECT * FROM t ORDER BY id')->rows;

        $db->execute('VACUUM t');

        assertSame($before, $db->execute('SELECT * FROM t ORDER BY id')->rows);
    },

    'preserves every column type through a rewrite' => function () use ($stocked): void {
        [$db] = $stocked(3);
        $db->execute('INSERT INTO t (id) VALUES (?)', [99]);
        $before = $db->execute('SELECT * FROM t ORDER BY id')->rows;

        $db->execute('VACUUM t');

        assertSame($before, $db->execute('SELECT * FROM t ORDER BY id')->rows);
        assertSame(null, $db->execute('SELECT * FROM t WHERE id = ?', [99])->rows[0]['name']);
    },

    'shrinks the heap after mass deletes' => function () use ($stocked): void {
        [$db, $directory] = $stocked(200);
        $db->execute('DELETE FROM t WHERE id > ?', [10]);
        $before = filesize($directory . '/t.heap');

        $db->execute('VACUUM t');
        clearstatcache();

        assertTrue(filesize($directory . '/t.heap') < $before / 2, 'the heap must actually get smaller');
        assertSame(10, count($db->execute('SELECT * FROM t')->rows));
    },

    'reports how many records it reclaimed' => function () use ($stocked): void {
        [$db] = $stocked(20);
        $db->execute('DELETE FROM t WHERE id > ?', [15]);
        $db->execute('UPDATE t SET name = ? WHERE id = ?', ['x', 1]);

        assertSame(6, $db->execute('VACUUM t')->rowsAffected, '5 deleted plus 1 superseded');
    },

    'reclaims nothing on a table that is already compact' => function () use ($stocked): void {
        [$db] = $stocked(5);
        $db->execute('VACUUM t');

        assertSame(0, $db->execute('VACUUM t')->rowsAffected);
        assertSame(5, count($db->execute('SELECT * FROM t')->rows));
    },

    'vacuums an empty table' => function () use ($stocked): void {
        [$db] = $stocked(0);

        assertSame(0, $db->execute('VACUUM t')->rowsAffected);
        assertSame([], $db->execute('SELECT * FROM t')->rows);
    },

    'leaves no temporary file behind' => function () use ($stocked): void {
        [$db, $directory] = $stocked(10);
        $db->execute('VACUUM t');

        $names = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($names);

        assertSame(['.elephpantdb-lock-probe', 't.heap', 't.idx.id', 't.lock', 't.schema'], $names);
    },

    'finds rows through the index at their new offsets' => function () use ($stocked): void {
        [$db] = $stocked(50);
        $db->execute('DELETE FROM t WHERE id < ?', [40]);
        $db->execute('VACUUM t');

        Heap::resetRecordReads();
        $rows = $db->execute('SELECT * FROM t WHERE id = ?', [45])->rows;

        assertSame(45, $rows[0]['id']);
        assertTrue(Heap::recordReads() < 4, 'the index must have been rebuilt against the new offsets');
    },

    'shortens an index chain grown by repeated key changes' => function () use ($stocked, $openIndex): void {
        [$db, $directory] = $stocked(1);
        $db->execute('CREATE INDEX idx_name ON t (name)');

        for ($round = 0; $round < 50; $round++) {
            $db->execute('UPDATE t SET name = ? WHERE id = ?', ['round-' . $round, 1]);
        }

        $grown = $openIndex($directory, 'name');
        $entriesBefore = $grown->entryCount();
        $grown->close();

        assertTrue($entriesBefore > 50, "expected a grown chain, found {$entriesBefore} entries");

        $db->execute('VACUUM t');

        $compacted = $openIndex($directory, 'name');

        assertSame(1, $compacted->entryCount(), 'compaction must drop entries for retired records');
        $compacted->close();

        assertSame([['id' => 1, 'name' => 'round-49']], $db->execute('SELECT id, name FROM t')->rows);
    },

    'clears supersedes pointers in the rewritten heap' => function () use ($stocked): void {
        [$db, $directory] = $stocked(3);
        $db->execute('UPDATE t SET name = ? WHERE id = ?', ['x', 2]);
        $db->execute('VACUUM t');

        $paths = new Paths($directory);
        $heap = new Heap($paths->heap('t'), new Lock($paths->lock('t')), 4);

        foreach ($heap->scan(includeDead: true) as $record) {
            assertSame(0, $record->supersedes, 'a compacted record supersedes nothing');
            assertTrue($record->live);
        }

        $heap->close();
    },

    'rejects a vacuum on an unknown table' => function (): void {
        assertThrows(SchemaException::class, fn () => (new Database(temporaryDirectory()))->execute('VACUUM missing'));
    },

    'survives a kill in the middle of a vacuum' => function () use ($stocked): void {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            skip('pcntl_fork or posix_kill is unavailable');
        }

        for ($round = 0; $round < 6; $round++) {
            [$db, $directory] = $stocked(4000);
            $db->execute('DELETE FROM t WHERE id > ?', [2000]);
            $expected = $db->execute('SELECT * FROM t ORDER BY id')->rows;

            $child = pcntl_fork();

            if ($child === 0) {
                $GLOBALS['temporaryDirectories'] = [];
                (new Database($directory))->execute('VACUUM t');
                exit(0);
            }

            usleep(random_int(200, 4000));
            posix_kill($child, SIGKILL);
            pcntl_waitpid($child, $status);

            $reopened = new Database($directory);

            assertSame($expected, $reopened->execute('SELECT * FROM t ORDER BY id')->rows, "round {$round}");
            assertSame([['id' => 7, 'name' => 'n7']], $reopened->execute('SELECT id, name FROM t WHERE id = ?', [7])->rows);
        }
    },
];
