<?php

declare(strict_types=1);

$openIndex = static function (string $directory, string $column = 'id'): HashIndex {
    $paths = new Paths($directory);

    return new HashIndex($paths->index('t', $column), new Lock($paths->lock('t')));
};

// A single-row index puts the only entry at the head of the only occupied
// bucket, so the lookup for that row is guaranteed to read it.
$scribbleFirstEntry = static function (int $fieldOffset, string $bytes): string {
    $directory = temporaryDirectory();
    $db = new Database($directory);
    $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');
    $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'ana']);

    $path = $directory . '/t.idx.id';
    $raw = (string) file_get_contents($path);
    $entry = HashIndex::HEADER_BYTES + HashIndex::INITIAL_BUCKETS * 8;
    $at = $entry + $fieldOffset;

    file_put_contents($path, substr($raw, 0, $at) . $bytes . substr($raw, $at + strlen($bytes)));

    return $directory;
};

return [
    'writes a header on first open' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $index = $openIndex($directory);
        $index->close();

        $raw = (string) file_get_contents($directory . '/t.idx.id');

        assertSame(HashIndex::MAGIC, substr($raw, 0, 8));
        assertSame(HashIndex::VERSION, unpack('n', substr($raw, 8, 2))[1]);
        assertSame(HashIndex::INITIAL_BUCKETS, unpack('N', substr($raw, HashIndex::BUCKET_COUNT_OFFSET, 4))[1]);
        assertSame(HashIndex::HEADER_BYTES + HashIndex::INITIAL_BUCKETS * 8, strlen($raw));
    },

    'rejects a foreign file' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        file_put_contents($directory . '/t.idx.id', str_repeat('X', HashIndex::HEADER_BYTES));

        assertThrows(StorageException::class, fn () => $openIndex($directory));
    },

    'rejects an unsupported version' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $openIndex($directory)->close();

        $path = $directory . '/t.idx.id';
        $raw = (string) file_get_contents($path);
        file_put_contents($path, substr($raw, 0, 8) . pack('n', 99) . substr($raw, 10));

        assertThrows(StorageException::class, fn () => $openIndex($directory));
    },

    'finds an inserted key' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());
        $index->insert('alpha', 128, Heap::HEADER_BYTES);

        assertSame([128], $index->offsetsFor('alpha'));
        assertSame([], $index->offsetsFor('bravo'));
        $index->close();
    },

    'keeps several distinct keys apart' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());

        foreach (range(1, 50) as $number) {
            $index->insert('key-' . $number, $number * 100, Heap::HEADER_BYTES);
        }

        foreach (range(1, 50) as $number) {
            assertSame([$number * 100], $index->offsetsFor('key-' . $number), 'key-' . $number);
        }

        $index->close();
    },

    'returns every offset for a repeated key, newest first' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());
        $index->insert('alpha', 100, Heap::HEADER_BYTES);
        $index->insert('alpha', 200, Heap::HEADER_BYTES);
        $index->insert('alpha', 300, Heap::HEADER_BYTES);

        assertSame([300, 200, 100], $index->offsetsFor('alpha'));
        $index->close();
    },

    'walks a collision chain' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());
        $buckets = HashIndex::INITIAL_BUCKETS;
        $colliding = [];

        for ($candidate = 0; count($colliding) < 3; $candidate++) {
            if (crc32('k' . $candidate) % $buckets === 7) {
                $colliding[] = 'k' . $candidate;
            }
        }

        foreach ($colliding as $position => $key) {
            $index->insert($key, ($position + 1) * 1000, Heap::HEADER_BYTES);
        }

        foreach ($colliding as $position => $key) {
            assertSame([($position + 1) * 1000], $index->offsetsFor($key), $key);
        }

        $index->close();
    },

    'handles binary and empty keys' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());
        $index->insert('', 64, Heap::HEADER_BYTES);
        $index->insert("with\0null", 128, Heap::HEADER_BYTES);

        assertSame([64], $index->offsetsFor(''));
        assertSame([128], $index->offsetsFor("with\0null"));
        $index->close();
    },

    'counts entries' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());

        assertSame(0, $index->entryCount());
        $index->insert('a', 32, Heap::HEADER_BYTES);
        $index->insert('b', 64, Heap::HEADER_BYTES);

        assertSame(2, $index->entryCount());
        $index->close();
    },

    'grows the bucket array when the load factor is exceeded' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $index = $openIndex($directory);
        $entries = HashIndex::INITIAL_BUCKETS * HashIndex::MAX_LOAD_FACTOR + 44;

        for ($number = 0; $number < $entries; $number++) {
            $index->insert('key-' . $number, ($number + 1) * 8, Heap::HEADER_BYTES);
        }

        assertSame(HashIndex::INITIAL_BUCKETS * 2, $index->bucketCount(), 'the array must have doubled once');
        assertSame($entries, $index->entryCount());

        for ($number = 0; $number < $entries; $number++) {
            assertSame([($number + 1) * 8], $index->offsetsFor('key-' . $number), 'key-' . $number . ' survived growth');
        }

        $index->close();
    },

    'leaves no temporary file behind after growth' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $index = $openIndex($directory);

        for ($number = 0; $number <= HashIndex::INITIAL_BUCKETS * HashIndex::MAX_LOAD_FACTOR; $number++) {
            $index->insert('key-' . $number, ($number + 1) * 8, Heap::HEADER_BYTES);
        }

        $index->close();
        $names = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($names);

        assertSame(['.elephpantdb-lock-probe', 't.idx.id', 't.lock'], $names);
    },

    'keeps newest-first order across growth' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());
        $index->insert('hot', 8, Heap::HEADER_BYTES);

        for ($number = 0; $number < HashIndex::INITIAL_BUCKETS * HashIndex::MAX_LOAD_FACTOR; $number++) {
            $index->insert('key-' . $number, ($number + 2) * 8, Heap::HEADER_BYTES);
        }

        $index->insert('hot', 4096, Heap::HEADER_BYTES);

        assertSame([4096, 8], $index->offsetsFor('hot'));
        $index->close();
    },

    'repoints an existing entry in place' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());
        $index->insert('alpha', 100, Heap::HEADER_BYTES);
        $index->insert('bravo', 200, Heap::HEADER_BYTES);

        assertTrue($index->repoint('alpha', 100, 300, Heap::HEADER_BYTES));
        assertSame([300], $index->offsetsFor('alpha'));
        assertSame([200], $index->offsetsFor('bravo'));
        assertSame(2, $index->entryCount(), 'repointing must not add an entry');
        $index->close();
    },

    'reports when there is nothing to repoint' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());
        $index->insert('alpha', 100, Heap::HEADER_BYTES);

        assertFalse($index->repoint('alpha', 999, 300, Heap::HEADER_BYTES));
        assertFalse($index->repoint('missing', 100, 300, Heap::HEADER_BYTES));
        assertSame([100], $index->offsetsFor('alpha'));
        $index->close();
    },

    'repoints only the entry that matches' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());
        $index->insert('alpha', 100, Heap::HEADER_BYTES);
        $index->insert('alpha', 200, Heap::HEADER_BYTES);

        assertTrue($index->repoint('alpha', 100, 300, Heap::HEADER_BYTES));
        assertSame([200, 300], $index->offsetsFor('alpha'));
        $index->close();
    },

    'remembers how far into the heap it has been maintained' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $index = $openIndex($directory);

        assertSame(Heap::HEADER_BYTES, $index->heapSizeAtLastSync());

        $index->insert('alpha', 32, Heap::HEADER_BYTES);
        $index->setHeapSize(4096);

        assertSame(4096, $index->heapSizeAtLastSync());
        $index->close();

        assertSame(4096, $openIndex($directory)->heapSizeAtLastSync());
    },

    'survives being reopened' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $index = $openIndex($directory);

        foreach (range(1, 30) as $number) {
            $index->insert('key-' . $number, $number * 16, Heap::HEADER_BYTES);
        }

        $index->close();
        $reopened = $openIndex($directory);

        foreach (range(1, 30) as $number) {
            assertSame([$number * 16], $reopened->offsetsFor('key-' . $number));
        }

        assertSame(30, $reopened->entryCount());
        $reopened->close();
    },

    'lists every entry in insertion order' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());
        $index->insert('a', 32, Heap::HEADER_BYTES);
        $index->insert('b', 64, Heap::HEADER_BYTES);
        $index->insert('a', 96, Heap::HEADER_BYTES);

        assertSame([['a', 32], ['b', 64], ['a', 96]], iterator_to_array($index->entries(), false));
        $index->close();
    },

    'rebuilds from a supplied set of entries' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $index = $openIndex($directory);
        $index->insert('stale', 32, Heap::HEADER_BYTES);

        $index->rebuild([['alpha', 64], ['bravo', 128], ['alpha', 192]], 512);

        assertSame([], $index->offsetsFor('stale'));
        assertSame([192, 64], $index->offsetsFor('alpha'));
        assertSame([128], $index->offsetsFor('bravo'));
        assertSame(3, $index->entryCount());
        assertSame(512, $index->heapSizeAtLastSync());
        $index->close();
    },

    'sizes a rebuilt index for the entries it is given' => function () use ($openIndex): void {
        $index = $openIndex(temporaryDirectory());
        $entries = [];

        for ($number = 0; $number < 1000; $number++) {
            $entries[] = ['key-' . $number, ($number + 1) * 8];
        }

        $index->rebuild($entries, 8192);

        assertTrue($index->bucketCount() >= 250, 'a rebuild must not leave the array overloaded');
        assertSame([8], $index->offsetsFor('key-0'));
        assertSame([8000], $index->offsetsFor('key-999'));
        $index->close();
    },

    'indexes the primary key when a table is created' => function (): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');

        assertTrue(is_file($directory . '/t.idx.id'));
        assertSame(1, count((new SchemaStore(new Paths($directory)))->load('t')->indexes));
    },

    'creates no index for a table without a primary key' => function (): void {
        $directory = temporaryDirectory();
        (new Database($directory))->execute('CREATE TABLE t (id INT, name TEXT)');

        assertFalse(is_file($directory . '/t.idx.id'));
    },

    'adds an index entry for every insert' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');

        foreach (range(1, 5) as $id) {
            $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [$id, 'n' . $id]);
        }

        $index = $openIndex($directory);

        assertSame(5, $index->entryCount());

        foreach (range(1, 5) as $id) {
            assertSame(1, count($index->offsetsFor(ValueCodec::encodeValue(ColumnType::Integer, $id))), "id {$id}");
        }

        $index->close();
    },

    'never indexes a null' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');
        $db->execute('CREATE INDEX idx_name ON t (name)');
        $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'ana']);
        $db->execute('INSERT INTO t (id) VALUES (?)', [2]);

        $index = $openIndex($directory, 'name');

        assertSame(1, $index->entryCount(), 'a null value must not take an index entry');
        $index->close();
    },

    'repoints rather than chains when an update leaves the key alone' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');
        $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'start']);

        for ($round = 0; $round < 200; $round++) {
            $db->execute('UPDATE t SET name = ? WHERE id = ?', ['round-' . $round, 1]);
        }

        $index = $openIndex($directory);

        assertSame(1, $index->entryCount(), 'a hot key must not grow a chain of dead entries');
        assertSame(1, count($index->offsetsFor(ValueCodec::encodeValue(ColumnType::Integer, 1))));
        $index->close();

        assertSame([['id' => 1, 'name' => 'round-199']], $db->execute('SELECT * FROM t')->rows);
    },

    'chains a new entry when an update changes the key' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');
        $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'ana']);
        $db->execute('UPDATE t SET id = ? WHERE id = ?', [2, 1]);

        $index = $openIndex($directory);

        assertSame(2, $index->entryCount());
        $index->close();

        assertSame([['id' => 2, 'name' => 'ana']], $db->execute('SELECT * FROM t')->rows);
    },

    'rebuilds an index that was deleted' => function (): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');

        foreach (range(1, 20) as $id) {
            $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [$id, 'n' . $id]);
        }

        $expected = $db->execute('SELECT * FROM t WHERE id = ?', [7])->rows;
        unlink($directory . '/t.idx.id');

        $reopened = new Database($directory);

        assertSame($expected, $reopened->execute('SELECT * FROM t WHERE id = ?', [7])->rows);
        assertTrue(is_file($directory . '/t.idx.id'), 'the index must come back');
    },

    'rebuilds an index that was truncated' => function (): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');

        foreach (range(1, 20) as $id) {
            $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [$id, 'n' . $id]);
        }

        $path = $directory . '/t.idx.id';
        file_put_contents($path, substr((string) file_get_contents($path), 0, HashIndex::HEADER_BYTES + 40));

        $reopened = new Database($directory);

        assertSame([['id' => 7, 'name' => 'n7']], $reopened->execute('SELECT * FROM t WHERE id = ?', [7])->rows);
    },

    'rebuilds an index whose header is not ours' => function (): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');
        $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'ana']);

        file_put_contents($directory . '/t.idx.id', str_repeat('X', 512));

        assertSame([['id' => 1, 'name' => 'ana']], (new Database($directory))->execute('SELECT * FROM t WHERE id = ?', [1])->rows);
    },

    'replays exactly the heap tail an index missed' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');

        foreach (range(1, 10) as $id) {
            $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [$id, 'n' . $id]);
        }

        $index = $openIndex($directory);
        $shortMark = $index->heapSizeAtLastSync();
        $index->close();

        foreach (range(11, 20) as $id) {
            $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [$id, 'n' . $id]);
        }

        // Rewind the index to before the last ten inserts, as a crash between the
        // heap fsync and the index fsync would.
        $rewound = $openIndex($directory);
        $rewound->rebuild(array_slice(iterator_to_array($rewound->entries(), false), 0, 10), $shortMark);
        $rewound->close();

        $reopened = new Database($directory);

        assertSame([['id' => 17, 'name' => 'n17']], $reopened->execute('SELECT id, name FROM t WHERE id = ?', [17])->rows);

        $caughtUp = $openIndex($directory);

        assertSame(20, $caughtUp->entryCount(), 'the tail must be replayed exactly once');
        $caughtUp->close();
    },

    'never returns a row an index entry points at wrongly' => function () use ($openIndex): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');

        foreach (range(1, 5) as $id) {
            $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [$id, 'n' . $id]);
        }

        $index = $openIndex($directory);
        $wrongOffset = $index->offsetsFor(ValueCodec::encodeValue(ColumnType::Integer, 3))[0];
        $index->insert(ValueCodec::encodeValue(ColumnType::Integer, 1), $wrongOffset, $index->heapSizeAtLastSync());
        $index->close();

        $rows = (new Database($directory))->execute('SELECT * FROM t WHERE id = ?', [1])->rows;

        assertSame([['id' => 1, 'name' => 'n1']], $rows, 'a falsified entry must not produce a wrong row');
    },

    'ignores index entries that point at retired records' => function (): void {
        $directory = temporaryDirectory();
        $db = new Database($directory);
        $db->execute('CREATE TABLE t (id INT PRIMARY KEY, name TEXT)');
        $db->execute('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'ana']);
        $db->execute('DELETE FROM t WHERE id = ?', [1]);

        assertSame([], $db->execute('SELECT * FROM t WHERE id = ?', [1])->rows);
        assertSame([], (new Database($directory))->execute('SELECT * FROM t WHERE id = ?', [1])->rows);
    },

    'refuses a chain that does not walk backwards' => function () use ($scribbleFirstEntry): void {
        $directory = $scribbleFirstEntry(0, pack('J', HashIndex::HEADER_BYTES + HashIndex::INITIAL_BUCKETS * 8));

        assertThrows(
            StorageException::class,
            fn () => (new Database($directory))->execute('SELECT * FROM t WHERE id = ?', [1]),
        );
    },

    'refuses a key longer than the file' => function () use ($scribbleFirstEntry): void {
        $directory = $scribbleFirstEntry(8, pack('N', 0xFFFFFFFF));

        assertThrows(
            StorageException::class,
            fn () => (new Database($directory))->execute('SELECT * FROM t WHERE id = ?', [1]),
        );
    },
];
