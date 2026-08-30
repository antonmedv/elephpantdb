<?php

declare(strict_types=1);

$openHeap = static function (string $directory, int $columnCount = 2): Heap {
    $paths = new Paths($directory);

    return new Heap($paths->heap('users'), new Lock($paths->lock('users')), $columnCount);
};

$payloads = static fn (iterable $records): array => array_map(
    static fn (HeapRecord $record): string => $record->payload,
    iterator_to_array($records, false),
);

return [
    'writes a header on first open' => function () use ($openHeap): void {
        $directory = temporaryDirectory();
        $heap = $openHeap($directory, 3);
        $heap->close();

        $raw = (string) file_get_contents($directory . '/users.heap');

        assertSame(Heap::HEADER_BYTES, strlen($raw));
        assertSame(Heap::MAGIC, substr($raw, 0, 8));
        assertSame(Heap::VERSION, unpack('n', substr($raw, 8, 2))[1]);
        assertSame(3, unpack('n', substr($raw, 10, 2))[1]);
    },

    'reopens an existing heap without resetting it' => function () use ($openHeap, $payloads): void {
        $directory = temporaryDirectory();

        $first = $openHeap($directory);
        $first->append('alpha');
        $first->close();

        $second = $openHeap($directory);
        assertSame(['alpha'], $payloads($second->scan()));
        $second->close();
    },

    'rejects a foreign file' => function () use ($openHeap): void {
        $directory = temporaryDirectory();
        file_put_contents($directory . '/users.heap', str_repeat('X', Heap::HEADER_BYTES));

        assertThrows(StorageException::class, fn () => $openHeap($directory));
    },

    'rejects an unsupported version' => function () use ($openHeap): void {
        $directory = temporaryDirectory();
        $heap = $openHeap($directory);
        $heap->close();

        $raw = (string) file_get_contents($directory . '/users.heap');
        file_put_contents($directory . '/users.heap', substr($raw, 0, 8) . pack('n', 99) . substr($raw, 10));

        assertThrows(StorageException::class, fn () => $openHeap($directory));
    },

    'rejects a column count that disagrees with the schema' => function () use ($openHeap): void {
        $directory = temporaryDirectory();
        $openHeap($directory, 2)->close();

        assertThrows(StorageException::class, fn () => $openHeap($directory, 5));
    },

    'returns the byte offset of every appended record' => function () use ($openHeap): void {
        $heap = $openHeap(temporaryDirectory());

        $first = $heap->append('alpha');
        $second = $heap->append('bravo');

        assertSame(Heap::HEADER_BYTES, $first);
        assertSame(Heap::HEADER_BYTES + Heap::RECORD_HEADER_BYTES + 5, $second);
        $heap->close();
    },

    'round-trips payloads in append order' => function () use ($openHeap, $payloads): void {
        $heap = $openHeap(temporaryDirectory());

        foreach (['alpha', '', 'charlie', "binary\0payload"] as $payload) {
            $heap->append($payload);
        }

        assertSame(['alpha', '', 'charlie', "binary\0payload"], $payloads($heap->scan()));
        $heap->close();
    },

    'stores the supersedes pointer' => function () use ($openHeap): void {
        $heap = $openHeap(temporaryDirectory());

        $first = $heap->append('alpha');
        $heap->append('alpha revised', $first);

        $records = iterator_to_array($heap->scan(), false);

        assertSame(0, $records[0]->supersedes);
        assertSame($first, $records[1]->supersedes);
        $heap->close();
    },

    'skips records whose flag byte says dead' => function () use ($openHeap, $payloads): void {
        $directory = temporaryDirectory();
        $heap = $openHeap($directory);
        $first = $heap->append('alpha');
        $heap->append('bravo');
        $heap->close();

        $handle = fopen($directory . '/users.heap', 'r+b');
        fseek($handle, $first + Heap::FLAGS_OFFSET_IN_RECORD);
        fwrite($handle, pack('C', Heap::FLAG_DEAD));
        fclose($handle);

        $reopened = $openHeap($directory);
        assertSame(['bravo'], $payloads($reopened->scan()));
        assertSame(['alpha', 'bravo'], $payloads($reopened->scan(includeDead: true)));
        $reopened->close();
    },

    'truncates a torn record at the tail' => function () use ($openHeap, $payloads): void {
        $directory = temporaryDirectory();
        $heap = $openHeap($directory);
        $heap->append('alpha');
        $heap->append('bravo');
        $intact = $heap->size();
        $heap->append('charlie');
        $heap->close();

        $path = $directory . '/users.heap';
        $torn = substr((string) file_get_contents($path), 0, $intact + Heap::RECORD_HEADER_BYTES + 2);
        file_put_contents($path, $torn);

        $reopened = $openHeap($directory);
        assertSame(['alpha', 'bravo'], $payloads($reopened->scan()));
        assertSame($intact, $reopened->size());
        $reopened->close();
    },

    'drops a tail record whose checksum does not match' => function () use ($openHeap, $payloads): void {
        $directory = temporaryDirectory();
        $heap = $openHeap($directory);
        $heap->append('alpha');
        $intact = $heap->size();
        $last = $heap->append('bravo');
        $heap->close();

        $path = $directory . '/users.heap';
        $raw = (string) file_get_contents($path);
        $corruptAt = $last + Heap::RECORD_HEADER_BYTES;
        $raw[$corruptAt] = 'X';
        file_put_contents($path, $raw);

        $reopened = $openHeap($directory);
        assertSame(['alpha'], $payloads($reopened->scan()));
        assertSame($intact, $reopened->size());
        $reopened->close();
    },

    'refuses to silently discard a corrupt record that is not the tail' => function () use ($openHeap): void {
        $directory = temporaryDirectory();
        $heap = $openHeap($directory);
        $first = $heap->append('alpha');
        $heap->append('bravo');
        $heap->close();

        $path = $directory . '/users.heap';
        $raw = (string) file_get_contents($path);
        $raw[$first + Heap::RECORD_HEADER_BYTES] = 'X';
        file_put_contents($path, $raw);

        $reopened = $openHeap($directory);
        assertThrows(StorageException::class, fn () => iterator_to_array($reopened->scan(), false));
        $reopened->close();
    },

    'discards a record the header never acknowledged' => function () use ($openHeap, $payloads): void {
        $directory = temporaryDirectory();
        $heap = $openHeap($directory);
        $heap->append('alpha');
        $acknowledged = $heap->size();
        $heap->close();

        $path = $directory . '/users.heap';
        $raw = (string) file_get_contents($path);

        $unacknowledged = $openHeap($directory);
        $unacknowledged->append('bravo');
        $unacknowledged->close();

        $withStaleHeader = substr((string) file_get_contents($path), 0, Heap::LAST_RECORD_END_OFFSET)
            . pack('J', $acknowledged)
            . substr((string) file_get_contents($path), Heap::LAST_RECORD_END_OFFSET + 8);
        file_put_contents($path, $withStaleHeader);
        assertSame(strlen($raw), Heap::HEADER_BYTES + Heap::RECORD_HEADER_BYTES + 5);

        $reopened = $openHeap($directory);
        assertSame(['alpha'], $payloads($reopened->scan()));
        assertSame($acknowledged, $reopened->size());
        $reopened->close();
    },

    'falls back to a full scan when the header mark is impossible' => function () use ($openHeap, $payloads): void {
        $directory = temporaryDirectory();
        $heap = $openHeap($directory);
        $heap->append('alpha');
        $heap->append('bravo');
        $heap->close();

        $path = $directory . '/users.heap';
        $raw = (string) file_get_contents($path);
        file_put_contents(
            $path,
            substr($raw, 0, Heap::LAST_RECORD_END_OFFSET) . pack('J', PHP_INT_MAX) . substr($raw, Heap::LAST_RECORD_END_OFFSET + 8),
        );

        $reopened = $openHeap($directory);
        assertSame(['alpha', 'bravo'], $payloads($reopened->scan()));
        $reopened->close();
    },

    'reads a record back by offset' => function () use ($openHeap): void {
        $heap = $openHeap(temporaryDirectory());
        $heap->append('alpha');
        $offset = $heap->append('bravo');

        assertSame('bravo', $heap->read($offset)->payload);
        assertTrue($heap->read($offset)->live);
        $heap->close();
    },

    'rejects a read at an offset that is not a record boundary' => function () use ($openHeap): void {
        $heap = $openHeap(temporaryDirectory());
        $heap->append('alpha');

        assertThrows(StorageException::class, fn () => $heap->read(Heap::HEADER_BYTES + 3));
        assertThrows(StorageException::class, fn () => $heap->read($heap->size()));
        $heap->close();
    },

    'holds an exclusive lock against another handle' => function (): void {
        $paths = new Paths(temporaryDirectory());
        $path = $paths->lock('users');

        $lock = new Lock($path);
        $rival = fopen($path, 'c');

        $lock->exclusive(function () use ($rival): void {
            assertFalse(flock($rival, LOCK_EX | LOCK_NB), 'a second handle must not acquire the lock');
        });

        assertTrue(flock($rival, LOCK_EX | LOCK_NB), 'the lock must be released afterwards');
        flock($rival, LOCK_UN);
        fclose($rival);
    },

    'releases the lock when the work throws' => function (): void {
        $paths = new Paths(temporaryDirectory());
        $lock = new Lock($paths->lock('users'));
        $rival = fopen($paths->lock('users'), 'c');

        assertThrows(RuntimeException::class, fn () => $lock->exclusive(function (): never {
            throw new RuntimeException('boom');
        }));

        assertTrue(flock($rival, LOCK_EX | LOCK_NB), 'the lock must survive an exception');
        flock($rival, LOCK_UN);
        fclose($rival);
    },

    'refuses to nest an exclusive lock inside a shared one' => function (): void {
        $paths = new Paths(temporaryDirectory());
        $lock = new Lock($paths->lock('users'));

        assertThrows(StorageException::class, fn () => $lock->shared(fn () => $lock->exclusive(fn () => null)));
    },

    'allows a shared lock nested inside an exclusive one' => function (): void {
        $paths = new Paths(temporaryDirectory());
        $lock = new Lock($paths->lock('users'));

        assertSame('done', $lock->exclusive(fn () => $lock->shared(fn () => 'done')));
    },

    'verifies that the filesystem honours flock' => function (): void {
        Lock::verifyExclusion(temporaryDirectory());
    },
];
