<?php

declare(strict_types=1);

$seed = static function (string $directory): void {
    $db = new Database($directory);
    $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');

    foreach ([[1, 'one'], [2, 'two'], [3, 'three']] as $row) {
        $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', $row);
    }
};

$openHeap = static function (string $directory): Heap {
    $paths = new Paths($directory);

    return new Heap($paths->heap('t'), new Lock($paths->lock('t')), 2);
};

$liveRecords = static function (string $directory) use ($openHeap): array {
    $heap = $openHeap($directory);
    $records = iterator_to_array($heap->scan(), false);
    $heap->close();

    return $records;
};

$recordFlagOffsets = static function (string $raw): array {
    $offsets = [];
    $offset = Heap::HEADER_BYTES;

    while ($offset + Heap::RECORD_HEADER_BYTES <= strlen($raw)) {
        $length = unpack('N', substr($raw, $offset, 4))[1];
        $offsets[] = $offset;
        $offset += Heap::RECORD_HEADER_BYTES + $length;
    }

    return $offsets;
};

$setHeader = static function (string $raw, ?int $mark, ?int $flags): string {
    if ($mark !== null) {
        $raw = substr($raw, 0, Heap::LAST_RECORD_END_OFFSET) . pack('J', $mark)
            . substr($raw, Heap::LAST_RECORD_END_OFFSET + 8);
    }

    if ($flags !== null) {
        $raw[Heap::RECOVERY_FLAGS_OFFSET] = pack('C', $flags);
    }

    return $raw;
};

return [
    'a completed update leaves one live record for the row' => function () use ($seed, $liveRecords): void {
        $directory = temporaryDirectory();
        $seed($directory);
        (new Database($directory))->execute('UPDATE t SET name = ? WHERE id = ?', ['ONE', 1]);

        assertSame(3, count($liveRecords($directory)));
    },

    'clears the pending flag when an update completes' => function () use ($seed): void {
        $directory = temporaryDirectory();
        $seed($directory);
        (new Database($directory))->execute('UPDATE t SET name = ? WHERE id = ?', ['ONE', 1]);

        $raw = (string) file_get_contents($directory . '/t.heap');

        assertSame(0, ord($raw[Heap::RECOVERY_FLAGS_OFFSET]));
    },

    'never sets the pending flag for a plain insert' => function () use ($seed): void {
        $directory = temporaryDirectory();
        $seed($directory);

        $raw = (string) file_get_contents($directory . '/t.heap');

        assertSame(0, ord($raw[Heap::RECOVERY_FLAGS_OFFSET]));
    },

    'retires the replaced record after a crash between the two fsyncs' => function () use ($seed, $liveRecords, $recordFlagOffsets, $setHeader): void {
        $directory = temporaryDirectory();
        $seed($directory);
        (new Database($directory))->execute('UPDATE t SET name = ? WHERE id = ?', ['ONE', 1]);

        $path = $directory . '/t.heap';
        $raw = (string) file_get_contents($path);
        $first = $recordFlagOffsets($raw)[0];

        // The append and the header write reached disk; the tombstone did not.
        $raw[$first + Heap::FLAGS_OFFSET_IN_RECORD] = pack('C', Heap::FLAG_LIVE);
        file_put_contents($path, $setHeader($raw, null, Heap::SUPERSEDE_PENDING));

        $records = $liveRecords($directory);

        assertSame(3, count($records), 'the replaced record must not survive alongside its replacement');

        $db = new Database($directory);
        assertSame([['id' => 1, 'name' => 'ONE']], $db->execute('SELECT * FROM t WHERE id = ?', [1])->rows);
    },

    'is idempotent when recovery already ran' => function () use ($seed, $recordFlagOffsets, $setHeader): void {
        $directory = temporaryDirectory();
        $seed($directory);
        (new Database($directory))->execute('UPDATE t SET name = ? WHERE id = ?', ['ONE', 1]);

        $path = $directory . '/t.heap';
        $raw = (string) file_get_contents($path);
        $first = $recordFlagOffsets($raw)[0];
        $raw[$first + Heap::FLAGS_OFFSET_IN_RECORD] = pack('C', Heap::FLAG_LIVE);
        file_put_contents($path, $setHeader($raw, null, Heap::SUPERSEDE_PENDING));

        (new Database($directory))->execute('SELECT * FROM t');
        $afterFirst = (string) file_get_contents($path);

        (new Database($directory))->execute('SELECT * FROM t');
        $afterSecond = (string) file_get_contents($path);

        assertSame($afterFirst, $afterSecond, 'a second recovery must change nothing');
    },

    'recovers when the crash landed after the tombstone but before the flag was cleared' => function () use ($seed, $liveRecords, $setHeader): void {
        $directory = temporaryDirectory();
        $seed($directory);
        (new Database($directory))->execute('UPDATE t SET name = ? WHERE id = ?', ['ONE', 1]);

        $path = $directory . '/t.heap';
        file_put_contents($path, $setHeader((string) file_get_contents($path), null, Heap::SUPERSEDE_PENDING));

        assertSame(3, count($liveRecords($directory)));
        assertSame(0, ord(((string) file_get_contents($path))[Heap::RECOVERY_FLAGS_OFFSET]));
    },

    'discards an update whose header write never landed' => function () use ($seed, $recordFlagOffsets, $setHeader): void {
        $directory = temporaryDirectory();
        $seed($directory);
        $path = $directory . '/t.heap';
        $beforeUpdate = (string) file_get_contents($path);

        (new Database($directory))->execute('UPDATE t SET name = ? WHERE id = ?', ['ONE', 1]);

        // The replacement record reached disk but neither the header nor the
        // tombstone did, so the update was never acknowledged.
        $raw = (string) file_get_contents($path);
        $first = $recordFlagOffsets($raw)[0];
        $raw[$first + Heap::FLAGS_OFFSET_IN_RECORD] = pack('C', Heap::FLAG_LIVE);
        file_put_contents($path, $setHeader($raw, strlen($beforeUpdate), 0));

        $db = new Database($directory);
        $rows = $db->execute('SELECT * FROM t ORDER BY id')->rows;

        assertSame(3, count($rows));
        assertSame('one', $rows[0]['name'], 'an unacknowledged update must be absent, not half applied');
        assertSame(strlen($beforeUpdate), filesize($path));
    },

    'recovers a torn tail and a pending supersede together' => function () use ($seed, $liveRecords, $setHeader): void {
        $directory = temporaryDirectory();
        $seed($directory);
        $path = $directory . '/t.heap';

        (new Database($directory))->execute('UPDATE t SET name = ? WHERE id = ?', ['ONE', 1]);
        $complete = (string) file_get_contents($path);

        $torn = $complete . substr($complete, Heap::HEADER_BYTES, 10);
        file_put_contents($path, $setHeader($torn, null, Heap::SUPERSEDE_PENDING));

        assertSame(3, count($liveRecords($directory)));
        assertSame(strlen($complete), filesize($path));
    },

    'never advances the mark past a supersede without setting the pending flag' => function () use ($seed): void {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            skip('pcntl_fork or posix_kill is unavailable, so real crash recovery cannot be exercised');
        }

        // Read the raw file before anything opens it, so the invariant is checked
        // on what the crash actually left, not on what recovery repaired.
        for ($round = 0; $round < 40; $round++) {
            $directory = temporaryDirectory();
            $seed($directory);
            $child = pcntl_fork();

            if ($child === 0) {
                $db = new Database($directory);

                for ($iteration = 0; ; $iteration++) {
                    $db->execute('UPDATE t SET name = ? WHERE id = ?', ['n' . $iteration, ($iteration % 3) + 1]);
                }
            }

            usleep(random_int(200, 6000));
            posix_kill($child, SIGKILL);
            pcntl_waitpid($child, $status);

            $raw = (string) file_get_contents($directory . '/t.heap');
            $mark = unpack('J', substr($raw, Heap::LAST_RECORD_END_OFFSET, 8))[1];
            $pending = (ord($raw[Heap::RECOVERY_FLAGS_OFFSET]) & Heap::SUPERSEDE_PENDING) !== 0;

            $live = [];
            $supersedes = [];
            $offset = Heap::HEADER_BYTES;

            while ($offset + Heap::RECORD_HEADER_BYTES <= $mark) {
                $length = unpack('N', substr($raw, $offset, 4))[1];

                if ($offset + Heap::RECORD_HEADER_BYTES + $length > $mark) {
                    break;
                }

                if (ord($raw[$offset + Heap::FLAGS_OFFSET_IN_RECORD]) === Heap::FLAG_LIVE) {
                    $live[$offset] = true;
                    $target = unpack('J', substr($raw, $offset + 8, 8))[1];

                    if ($target !== 0) {
                        $supersedes[$offset] = $target;
                    }
                }

                $offset += Heap::RECORD_HEADER_BYTES + $length;
            }

            foreach ($supersedes as $replacement => $target) {
                if (isset($live[$target])) {
                    assertTrue(
                        $pending,
                        "round {$round}: record {$replacement} supersedes live record {$target} but the header carries no warning",
                    );
                }
            }
        }
    },

    'survives a kill at an arbitrary point during repeated updates' => function () use ($seed, $liveRecords): void {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            skip('pcntl_fork or posix_kill is unavailable, so real crash recovery cannot be exercised');
        }

        for ($round = 0; $round < 40; $round++) {
            $directory = temporaryDirectory();
            $seed($directory);

            $child = pcntl_fork();

            if ($child === -1) {
                skip('could not fork');
            }

            if ($child === 0) {
                $db = new Database($directory);

                for ($iteration = 0; ; $iteration++) {
                    $db->execute(
                        'UPDATE t SET name = ? WHERE id = ?',
                        ['round-' . $iteration, ($iteration % 3) + 1],
                    );
                }
            }

            usleep(random_int(200, 6000));
            posix_kill($child, SIGKILL);
            pcntl_waitpid($child, $status);

            $records = $liveRecords($directory);

            assertSame(3, count($records), "round {$round}: exactly one live record per row");

            $rows = (new Database($directory))->execute('SELECT * FROM t ORDER BY id')->rows;
            assertSame([1, 2, 3], array_column($rows, 'id'), "round {$round}: every row still readable");

            foreach ($rows as $row) {
                assertTrue(is_string($row['name']), "round {$round}: name must decode");
            }
        }
    },

    'leaves the heap byte-identical across two reopens after a crash' => function () use ($seed): void {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            skip('pcntl_fork or posix_kill is unavailable');
        }

        $directory = temporaryDirectory();
        $seed($directory);
        $child = pcntl_fork();

        if ($child === 0) {
            $db = new Database($directory);

            for ($iteration = 0; ; $iteration++) {
                $db->execute('UPDATE t SET name = ? WHERE id = ?', ['x' . $iteration, ($iteration % 3) + 1]);
            }
        }

        usleep(random_int(500, 5000));
        posix_kill($child, SIGKILL);
        pcntl_waitpid($child, $status);

        $path = $directory . '/t.heap';

        (new Database($directory))->execute('SELECT * FROM t');
        $first = (string) file_get_contents($path);

        (new Database($directory))->execute('SELECT * FROM t');
        assertSame($first, (string) file_get_contents($path));
    },
];
