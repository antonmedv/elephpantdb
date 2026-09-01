<?php

declare(strict_types=1);

$requireFork = static function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
        skip('pcntl_fork is unavailable, so concurrent writers cannot be exercised');
    }
};

$fanOut = static function (int $writers, callable $work): void {
    $children = [];

    for ($writer = 0; $writer < $writers; $writer++) {
        $child = pcntl_fork();

        if ($child === -1) {
            skip('could not fork');
        }

        if ($child === 0) {
            // The child must not run the parent's temporary directory cleanup.
            $GLOBALS['temporaryDirectories'] = [];

            try {
                $work($writer);
                exit(0);
            } catch (Throwable $failure) {
                fwrite(STDERR, "writer {$writer}: " . $failure->getMessage() . "\n");
                exit(1);
            }
        }

        $children[] = $child;
    }

    foreach ($children as $child) {
        pcntl_waitpid($child, $status);

        assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'every writer must exit cleanly');
    }
};

return [
    'keeps every row from eight concurrent writers' => function () use ($requireFork, $fanOut): void {
        $requireFork();

        $writers = 8;
        $perWriter = 1000;
        $directory = temporaryDirectory();

        $db = new Database($directory);
        $db->execute('CREATE TABLE t (writer INT, sequence INT)');

        $fanOut($writers, static function (int $writer) use ($directory, $perWriter): void {
            $db = new Database($directory);

            for ($sequence = 0; $sequence < $perWriter; $sequence++) {
                $db->execute('INSERT INTO t (writer, sequence) VALUES (?, ?)', [$writer, $sequence]);
            }
        });

        $rows = (new Database($directory))->execute('SELECT * FROM t')->rows;

        assertSame($writers * $perWriter, count($rows), 'no acknowledged insert may be lost');

        $seen = [];

        foreach ($rows as $row) {
            $key = $row['writer'] . ':' . $row['sequence'];

            assertFalse(isset($seen[$key]), "row {$key} appears twice");
            $seen[$key] = true;
        }

        for ($writer = 0; $writer < $writers; $writer++) {
            for ($sequence = 0; $sequence < $perWriter; $sequence++) {
                assertTrue(isset($seen["{$writer}:{$sequence}"]), "row {$writer}:{$sequence} is missing");
            }
        }
    },

    'leaves no torn record behind after concurrent writes' => function () use ($requireFork, $fanOut): void {
        $requireFork();

        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (writer INT, note TEXT)');

        $fanOut(6, static function (int $writer) use ($directory): void {
            $db = new Database($directory);

            for ($sequence = 0; $sequence < 120; $sequence++) {
                $db->execute(
                    'INSERT INTO t (writer, note) VALUES (?, ?)',
                    [$writer, str_repeat((string) $writer, $sequence % 40 + 1)],
                );
            }
        });

        $paths = new Paths($directory);
        $heap = new Heap($paths->heap('t'), new Lock($paths->lock('t')), 2);
        $records = iterator_to_array($heap->scan(), false);
        $heap->close();

        assertSame(720, count($records));

        foreach ($records as $record) {
            $values = ValueCodec::decodeRow([ColumnType::Integer, ColumnType::Text], $record->payload);

            assertSame(str_repeat((string) $values[0], strlen((string) $values[1])), $values[1], 'a record decoded as a mix of two writers');
        }
    },

    'leaves exactly one live record per row after concurrent updates' => function () use ($requireFork, $fanOut): void {
        $requireFork();

        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');

        foreach (range(1, 5) as $id) {
            $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [$id, 'seed']);
        }

        $fanOut(8, static function (int $writer) use ($directory): void {
            $db = new Database($directory);

            for ($round = 0; $round < 40; $round++) {
                $db->execute(
                    'UPDATE t SET name = ? WHERE id = ?',
                    ["w{$writer}r{$round}", ($round % 5) + 1],
                );
            }
        });

        $rows = (new Database($directory))->execute('SELECT * FROM t ORDER BY id')->rows;

        assertSame([1, 2, 3, 4, 5], array_column($rows, 'id'));
        assertSame(5, count($rows), 'concurrent updates must not duplicate a row');
    },

    'keeps deletes and inserts consistent under concurrency' => function () use ($requireFork, $fanOut): void {
        $requireFork();

        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (writer INT, sequence INT)');

        $fanOut(4, static function (int $writer) use ($directory): void {
            $db = new Database($directory);

            for ($sequence = 0; $sequence < 100; $sequence++) {
                $db->execute('INSERT INTO t (writer, sequence) VALUES (?, ?)', [$writer, $sequence]);

                if ($sequence % 2 === 1) {
                    $db->execute(
                        'DELETE FROM t WHERE writer = ? AND sequence = ?',
                        [$writer, $sequence],
                    );
                }
            }
        });

        $rows = (new Database($directory))->execute('SELECT * FROM t')->rows;

        assertSame(200, count($rows), 'exactly the even-numbered rows must survive');

        foreach ($rows as $row) {
            assertSame(0, $row['sequence'] % 2, 'an odd row survived its own delete');
        }
    },

    'lets exactly one of eight writers claim a primary key' => function () use ($requireFork, $fanOut): void {
        $requireFork();

        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');

        $fanOut(8, static function (int $writer) use ($directory): void {
            $db = new Database($directory);

            try {
                $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, "w{$writer}"]);
            } catch (SchemaException) {
                // Seven of the eight writers must lose this race.
            }
        });

        $rows = (new Database($directory))->execute('SELECT * FROM t')->rows;

        assertSame(1, count($rows), 'the key may be claimed once');
    },

    'waits for a rival lock probe instead of rejecting it' => function () use ($requireFork): void {
        $requireFork();

        $directory = temporaryDirectory();
        $probe = fopen($directory . '/.elephpantdb-lock-probe', 'c');

        assertTrue($probe !== false && flock($probe, LOCK_EX), 'the parent must hold the probe first');

        $child = pcntl_fork();

        if ($child === -1) {
            skip('could not fork');
        }

        if ($child === 0) {
            $GLOBALS['temporaryDirectories'] = [];

            try {
                Lock::verifyExclusion($directory);
                exit(0);
            } catch (Throwable $failure) {
                fwrite(STDERR, 'probe: ' . $failure->getMessage() . "\n");
                exit(1);
            }
        }

        usleep(200000);
        flock($probe, LOCK_UN);
        fclose($probe);
        pcntl_waitpid($child, $status);

        assertTrue(
            pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
            'a cold start must block on a rival probe, not fail',
        );
    },
];
