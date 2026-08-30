<?php

declare(strict_types=1);

// =============================================================================
// BOOTSTRAP
//
// Section order is fixed for the life of this file. Each layer may only depend
// on sections above it, which is the only structural discipline available when
// the whole engine ships as one file.
// =============================================================================

final class Runtime
{
    public const VERSION = '0.1.0';

    private const MIN_PHP_VERSION_ID = 80300;
    private const REQUIRED_INT_SIZE = 8;

    public static function assertSupportedEnvironment(): void
    {
        if (PHP_INT_SIZE !== self::REQUIRED_INT_SIZE) {
            self::refuse("needs a 64-bit PHP build: pack('J') round-trips corrupt integers silently on 32-bit");
        }

        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            self::refuse('needs PHP 8.3 or newer');
        }
    }

    public static function isHttpRequest(): bool
    {
        return PHP_SAPI !== 'cli';
    }

    private static function refuse(string $reason): never
    {
        if (self::isHttpRequest()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => "elephpantdb {$reason}"], JSON_UNESCAPED_SLASHES);
        } else {
            fwrite(STDERR, "elephpantdb {$reason}\n");
        }

        exit(1);
    }
}

Runtime::assertSupportedEnvironment();

// =============================================================================
// EXCEPTIONS
//
// Every failure leaves the engine as one of these three, mapped to a status
// code at the single HTTP boundary at the bottom of this file.
// =============================================================================

abstract class ElephpantException extends RuntimeException
{
    public const HTTP_STATUS = 500;
}

final class ParseException extends ElephpantException
{
    public const HTTP_STATUS = 400;

    public function __construct(string $message, private readonly ?int $position = null)
    {
        parent::__construct($position === null ? $message : "{$message} (position {$position})");
    }

    public function position(): ?int
    {
        return $this->position;
    }
}

final class SchemaException extends ElephpantException
{
    public const HTTP_STATUS = 400;
}

final class StorageException extends ElephpantException
{
    public const HTTP_STATUS = 500;
}

// =============================================================================
// IDENTIFIERS AND PATHS
//
// The only barrier between a query and the filesystem. Every path in the engine
// is built here so that no call site can skip validation.
// =============================================================================

final class Identifier
{
    // \A and \z, never ^ and $: PCRE's $ also matches before a trailing newline,
    // which would let "users\n" through and into a filename.
    private const PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/';
    private const MAX_REPORTED_LENGTH = 32;

    public static function validate(string $name): string
    {
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new SchemaException('invalid identifier: ' . self::describe($name));
        }

        return $name;
    }

    private static function describe(string $name): string
    {
        $printable = (string) preg_replace('/[^\x20-\x7E]/', '?', $name);

        if (strlen($printable) > self::MAX_REPORTED_LENGTH) {
            $printable = substr($printable, 0, self::MAX_REPORTED_LENGTH) . '...';
        }

        return "'{$printable}'";
    }
}

final class Paths
{
    public const SCHEMA_SUFFIX = '.schema';
    public const HEAP_SUFFIX = '.heap';
    public const LOCK_SUFFIX = '.lock';
    public const INDEX_PREFIX = '.idx.';
    public const TEMP_SUFFIX = '.tmp';

    public function __construct(private readonly string $dataDirectory)
    {
    }

    public function dataDirectory(): string
    {
        return $this->dataDirectory;
    }

    public function schema(string $table): string
    {
        return $this->forTable($table, self::SCHEMA_SUFFIX);
    }

    public function heap(string $table): string
    {
        return $this->forTable($table, self::HEAP_SUFFIX);
    }

    public function lock(string $table): string
    {
        return $this->forTable($table, self::LOCK_SUFFIX);
    }

    public function index(string $table, string $column): string
    {
        return $this->forTable($table, self::INDEX_PREFIX . Identifier::validate($column));
    }

    public function temporary(string $path): string
    {
        return $path . self::TEMP_SUFFIX;
    }

    private function forTable(string $table, string $suffix): string
    {
        return $this->dataDirectory . DIRECTORY_SEPARATOR . Identifier::validate($table) . $suffix;
    }
}

// =============================================================================
// VALUE ENCODING
//
// One codec serves both the heap payload and the index key, so a column's
// on-disk representation cannot drift between the two files.
// =============================================================================

enum ColumnType: int
{
    case Integer = 1;
    case Float = 2;
    case Text = 3;
    case Boolean = 4;
}

final class ValueCodec
{
    private const INT_BYTES = 8;
    private const FLOAT_BYTES = 8;
    private const BOOL_BYTES = 1;
    private const LENGTH_BYTES = 4;
    private const BITS_PER_BYTE = 8;
    private const SIGN_SHIFT = 63;

    public static function nullBitmapBytes(int $columnCount): int
    {
        return intdiv($columnCount + self::BITS_PER_BYTE - 1, self::BITS_PER_BYTE);
    }

    /**
     * @param list<ColumnType> $types
     * @param list<mixed>      $values
     */
    public static function encodeRow(array $types, array $values): string
    {
        $types = array_values($types);
        $values = array_values($values);

        if (count($values) !== count($types)) {
            throw new SchemaException('expected ' . count($types) . ' values, got ' . count($values));
        }

        $bitmap = array_fill(0, self::nullBitmapBytes(count($types)), 0);
        $body = '';

        foreach ($types as $ordinal => $type) {
            $value = $values[$ordinal];

            if ($value === null) {
                $bitmap[intdiv($ordinal, self::BITS_PER_BYTE)] |= 1 << ($ordinal % self::BITS_PER_BYTE);
                continue;
            }

            $body .= self::encodeValue($type, $value);
        }

        return ($bitmap === [] ? '' : pack('C*', ...$bitmap)) . $body;
    }

    /**
     * @param list<ColumnType> $types
     *
     * @return list<mixed>
     */
    public static function decodeRow(array $types, string $payload): array
    {
        $types = array_values($types);
        $bitmapBytes = self::nullBitmapBytes(count($types));

        if (strlen($payload) < $bitmapBytes) {
            throw new StorageException('payload shorter than its null bitmap');
        }

        $offset = $bitmapBytes;
        $values = [];

        foreach ($types as $ordinal => $type) {
            $bit = ord($payload[intdiv($ordinal, self::BITS_PER_BYTE)]) & (1 << ($ordinal % self::BITS_PER_BYTE));

            if ($bit !== 0) {
                $values[] = null;
                continue;
            }

            [$values[], $offset] = self::decodeValue($type, $payload, $offset);
        }

        if ($offset !== strlen($payload)) {
            throw new StorageException('payload has ' . (strlen($payload) - $offset) . ' trailing bytes');
        }

        return $values;
    }

    public static function encodeValue(ColumnType $type, mixed $value): string
    {
        return match ($type) {
            ColumnType::Integer => is_int($value)
                ? pack('J', self::zigzag($value))
                : self::rejectValue($type, $value),
            ColumnType::Float => is_float($value) || is_int($value)
                ? pack('E', (float) $value)
                : self::rejectValue($type, $value),
            ColumnType::Text => is_string($value)
                ? pack('N', strlen($value)) . $value
                : self::rejectValue($type, $value),
            ColumnType::Boolean => is_bool($value)
                ? pack('C', $value ? 1 : 0)
                : self::rejectValue($type, $value),
        };
    }

    /**
     * @return array{0: mixed, 1: int}
     */
    public static function decodeValue(ColumnType $type, string $payload, int $offset): array
    {
        return match ($type) {
            ColumnType::Integer => [
                self::unzigzag((int) self::readFixed($payload, $offset, self::INT_BYTES, 'J')),
                $offset + self::INT_BYTES,
            ],
            ColumnType::Float => [
                (float) self::readFixed($payload, $offset, self::FLOAT_BYTES, 'E'),
                $offset + self::FLOAT_BYTES,
            ],
            ColumnType::Boolean => [
                self::readFixed($payload, $offset, self::BOOL_BYTES, 'C') === 1,
                $offset + self::BOOL_BYTES,
            ],
            ColumnType::Text => self::readText($payload, $offset),
        };
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function readText(string $payload, int $offset): array
    {
        $length = (int) self::readFixed($payload, $offset, self::LENGTH_BYTES, 'N');
        $start = $offset + self::LENGTH_BYTES;

        if ($start + $length > strlen($payload)) {
            throw new StorageException("text length {$length} overruns the payload");
        }

        return [substr($payload, $start, $length), $start + $length];
    }

    private static function readFixed(string $payload, int $offset, int $length, string $format): int|float
    {
        if ($offset + $length > strlen($payload)) {
            throw new StorageException("payload truncated: needed {$length} bytes at offset {$offset}");
        }

        return unpack($format, substr($payload, $offset, $length))[1];
    }

    // Zigzag keeps small negative integers from encoding as all-ones, which
    // matters for compaction ratios and makes hexdumps readable during recovery.
    private static function zigzag(int $value): int
    {
        return ($value << 1) ^ ($value >> self::SIGN_SHIFT);
    }

    // PHP has no logical right shift, so the arithmetic one is masked back down.
    private static function unzigzag(int $encoded): int
    {
        return (($encoded >> 1) & PHP_INT_MAX) ^ -($encoded & 1);
    }

    private static function rejectValue(ColumnType $type, mixed $value): never
    {
        throw new SchemaException('expected ' . strtolower($type->name) . ', got ' . get_debug_type($value));
    }
}

// =============================================================================
// FILES
//
// Every durable write in the engine funnels through here, so there is exactly
// one place that decides what "written" means.
// =============================================================================

final class BinaryReader
{
    private int $offset = 0;

    public function __construct(private readonly string $buffer)
    {
    }

    public function uint8(): int
    {
        return ord($this->take(1));
    }

    public function uint16(): int
    {
        return unpack('n', $this->take(2))[1];
    }

    public function uint32(): int
    {
        return unpack('N', $this->take(4))[1];
    }

    public function uint64(): int
    {
        return unpack('J', $this->take(8))[1];
    }

    public function bytes(int $length): string
    {
        return $this->take($length);
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function remaining(): int
    {
        return strlen($this->buffer) - $this->offset;
    }

    public function expectEnd(string $what): void
    {
        if ($this->remaining() !== 0) {
            throw new StorageException("{$what} has {$this->remaining()} trailing bytes");
        }
    }

    private function take(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->buffer)) {
            throw new StorageException("truncated: needed {$length} bytes at offset {$this->offset}");
        }

        $slice = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $slice;
    }
}

final class FileSystem
{
    private const TEMP_PERMISSIONS = 0600;

    /**
     * @param resource $handle
     */
    public static function sync($handle): void
    {
        if (fflush($handle) === false || fsync($handle) === false) {
            throw new StorageException('could not flush to disk');
        }
    }

    // The contents are synced before the rename, so a reader never observes a
    // half-written file. The rename itself is not synced: PHP cannot open a
    // directory as a stream, so there is no handle to fsync. A power loss can
    // therefore lose the whole replacement, never a partial one.
    public static function replaceAtomically(string $path, string $contents): void
    {
        $temporary = $path . Paths::TEMP_SUFFIX;
        $handle = @fopen($temporary, 'wb');

        if ($handle === false) {
            throw new StorageException('could not open a temporary file beside ' . basename($path));
        }

        stream_set_write_buffer($handle, 0);

        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new StorageException('short write to ' . basename($temporary));
            }

            self::sync($handle);
        } catch (Throwable $failure) {
            fclose($handle);
            @unlink($temporary);

            throw $failure;
        }

        fclose($handle);
        @chmod($temporary, self::TEMP_PERMISSIONS);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new StorageException('could not replace ' . basename($path));
        }
    }
}

final class Lock
{
    // Never deleted: unlinking it races another process that has it open and
    // would make this probe report a working filesystem as broken.
    private const PROBE_NAME = '.elephpantdb-lock-probe';

    /** @var array<string, true> */
    private static array $verifiedDirectories = [];

    /** @var resource */
    private $handle;

    private int $depth = 0;
    private int $mode = 0;

    public function __construct(private readonly string $path)
    {
        self::verifyExclusion(dirname($path));

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new StorageException('could not open lock file ' . basename($path));
        }

        $this->handle = $handle;
    }

    public function exclusive(callable $work): mixed
    {
        return $this->hold(LOCK_EX, $work);
    }

    public function shared(callable $work): mixed
    {
        return $this->hold(LOCK_SH, $work);
    }

    public function acquire(int $mode): void
    {
        if ($this->depth > 0) {
            if ($mode === LOCK_EX && $this->mode === LOCK_SH) {
                throw new StorageException('cannot upgrade a shared lock to exclusive while it is held');
            }

            $this->depth++;

            return;
        }

        if (!flock($this->handle, $mode)) {
            throw new StorageException('could not lock ' . basename($this->path));
        }

        $this->mode = $mode;
        $this->depth = 1;
    }

    public function release(): void
    {
        if ($this->depth === 0) {
            return;
        }

        $this->depth--;

        if ($this->depth === 0) {
            flock($this->handle, LOCK_UN);
            $this->mode = 0;
        }
    }

    // flock() silently degrades to a no-op on NFS and some container volume
    // drivers, which would leave concurrent writers with no coordination at all.
    public static function verifyExclusion(string $directory): void
    {
        if (isset(self::$verifiedDirectories[$directory])) {
            return;
        }

        $probe = $directory . DIRECTORY_SEPARATOR . self::PROBE_NAME;
        $held = @fopen($probe, 'c');
        $rival = @fopen($probe, 'c');

        if ($held === false || $rival === false) {
            throw new StorageException("could not write a lock probe into {$directory}");
        }

        try {
            if (!flock($held, LOCK_EX | LOCK_NB)) {
                throw new StorageException("another process is probing locks in {$directory}");
            }

            if (flock($rival, LOCK_EX | LOCK_NB)) {
                throw new StorageException(
                    "flock() does not exclude in {$directory}: elephpantdb needs a local disk, not NFS or a shared volume",
                );
            }
        } finally {
            fclose($held);
            fclose($rival);
        }

        self::$verifiedDirectories[$directory] = true;
    }

    private function hold(int $mode, callable $work): mixed
    {
        $this->acquire($mode);

        try {
            return $work();
        } finally {
            $this->release();
        }
    }
}

// =============================================================================
// SCHEMA
// =============================================================================

final class Column
{
    public function __construct(
        public readonly string $name,
        public readonly ColumnType $type,
        public readonly bool $primaryKey = false,
        public readonly bool $notNull = false,
    ) {
        Identifier::validate($name);
    }
}

final class IndexDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly int $columnOrdinal,
    ) {
        Identifier::validate($name);
    }
}

final class Schema
{
    private const PRIMARY_KEY_FLAG = 1;
    private const NOT_NULL_FLAG = 2;

    /**
     * @param list<Column>          $columns
     * @param list<IndexDefinition> $indexes
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $indexes = [],
    ) {
        if ($columns === []) {
            throw new SchemaException('a table needs at least one column');
        }

        $seen = [];
        $primaryKeys = 0;

        foreach ($columns as $column) {
            $lowered = strtolower($column->name);

            if (isset($seen[$lowered])) {
                throw new SchemaException("duplicate column '{$column->name}'");
            }

            $seen[$lowered] = true;
            $primaryKeys += $column->primaryKey ? 1 : 0;
        }

        if ($primaryKeys > 1) {
            throw new SchemaException('a table may declare at most one primary key');
        }
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    /**
     * @return list<ColumnType>
     */
    public function types(): array
    {
        return array_map(static fn (Column $column): ColumnType => $column->type, $this->columns);
    }

    public function ordinal(string $name): int
    {
        foreach ($this->columns as $ordinal => $column) {
            if (strcasecmp($column->name, $name) === 0) {
                return $ordinal;
            }
        }

        throw new SchemaException("unknown column '" . Identifier::validate($name) . "'");
    }

    public function column(int $ordinal): Column
    {
        return $this->columns[$ordinal]
            ?? throw new SchemaException("no column at ordinal {$ordinal}");
    }

    public function primaryKeyOrdinal(): ?int
    {
        foreach ($this->columns as $ordinal => $column) {
            if ($column->primaryKey) {
                return $ordinal;
            }
        }

        return null;
    }

    public function hasIndex(string $name): bool
    {
        foreach ($this->indexes as $index) {
            if (strcasecmp($index->name, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function indexForColumn(int $ordinal): ?IndexDefinition
    {
        foreach ($this->indexes as $index) {
            if ($index->columnOrdinal === $ordinal) {
                return $index;
            }
        }

        return null;
    }

    public function withIndex(IndexDefinition $index): self
    {
        if ($index->columnOrdinal < 0 || $index->columnOrdinal >= $this->columnCount()) {
            throw new SchemaException("index '{$index->name}' names ordinal {$index->columnOrdinal}, out of range");
        }

        if ($this->hasIndex($index->name)) {
            throw new SchemaException("index '{$index->name}' already exists");
        }

        return new self($this->columns, [...$this->indexes, $index]);
    }

    public function flagsFor(int $ordinal): int
    {
        $column = $this->column($ordinal);

        return ($column->primaryKey ? self::PRIMARY_KEY_FLAG : 0)
            | ($column->notNull ? self::NOT_NULL_FLAG : 0);
    }

    public static function primaryKeyFlag(): int
    {
        return self::PRIMARY_KEY_FLAG;
    }

    public static function notNullFlag(): int
    {
        return self::NOT_NULL_FLAG;
    }
}

final class SchemaCodec
{
    public const MAGIC = 'EPHPSCHM';
    public const VERSION = 1;

    private const RESERVED_32 = 0;
    private const RESERVED_64 = 0;

    public static function encode(Schema $schema): string
    {
        $encoded = self::MAGIC
            . pack('n', self::VERSION)
            . pack('n', $schema->columnCount())
            . pack('N', self::RESERVED_32)
            . pack('J', self::RESERVED_64);

        foreach ($schema->columns as $ordinal => $column) {
            $encoded .= pack('C', $column->type->value)
                . pack('C', $schema->flagsFor($ordinal))
                . pack('n', strlen($column->name))
                . $column->name;
        }

        $encoded .= pack('n', count($schema->indexes));

        foreach ($schema->indexes as $index) {
            $encoded .= pack('n', strlen($index->name))
                . $index->name
                . pack('n', $index->columnOrdinal);
        }

        return $encoded;
    }

    public static function decode(string $encoded): Schema
    {
        $reader = new BinaryReader($encoded);

        if ($reader->bytes(strlen(self::MAGIC)) !== self::MAGIC) {
            throw new StorageException('not an elephpantdb schema file');
        }

        $version = $reader->uint16();

        if ($version !== self::VERSION) {
            throw new StorageException("unsupported schema version {$version}");
        }

        $columnCount = $reader->uint16();
        $reader->uint32();
        $reader->uint64();

        $columns = [];

        for ($ordinal = 0; $ordinal < $columnCount; $ordinal++) {
            $type = ColumnType::tryFrom($reader->uint8())
                ?? throw new StorageException("unknown column type at ordinal {$ordinal}");
            $flags = $reader->uint8();
            $name = $reader->bytes($reader->uint16());

            $columns[] = new Column(
                $name,
                $type,
                ($flags & Schema::primaryKeyFlag()) !== 0,
                ($flags & Schema::notNullFlag()) !== 0,
            );
        }

        $schema = new Schema($columns);
        $indexCount = $reader->uint16();

        for ($position = 0; $position < $indexCount; $position++) {
            $name = $reader->bytes($reader->uint16());
            $schema = $schema->withIndex(new IndexDefinition($name, $reader->uint16()));
        }

        $reader->expectEnd('schema file');

        return $schema;
    }
}

final class SchemaStore
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function exists(string $table): bool
    {
        return is_file($this->paths->schema($table));
    }

    public function load(string $table): Schema
    {
        $path = $this->paths->schema($table);
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new SchemaException("unknown table '{$table}'");
        }

        return SchemaCodec::decode($contents);
    }

    public function save(string $table, Schema $schema): void
    {
        FileSystem::replaceAtomically($this->paths->schema($table), SchemaCodec::encode($schema));
    }
}

// =============================================================================
// HEAP
//
// Records are append-only and a row's identity is its byte offset, so no row-id
// counter exists to be fsynced or to drift from the data.
// =============================================================================

final class HeapRecord
{
    public function __construct(
        public readonly int $offset,
        public readonly int $end,
        public readonly int $supersedes,
        public readonly bool $live,
        public readonly string $payload,
    ) {
    }
}

final class HeapDamage
{
    public function __construct(
        public readonly int $offset,
        public readonly string $reason,
        public readonly bool $atTail,
    ) {
    }
}

final class Heap
{
    public const MAGIC = 'EPHPHEAP';
    public const VERSION = 1;
    public const HEADER_BYTES = 32;
    public const LAST_RECORD_END_OFFSET = 12;
    public const RECOVERY_FLAGS_OFFSET = 20;
    public const SUPERSEDE_PENDING = 1;
    public const RECORD_HEADER_BYTES = 24;
    public const FLAGS_OFFSET_IN_RECORD = 16;
    public const FLAG_DEAD = 0;
    public const FLAG_LIVE = 1;

    private const RESERVED_RECORD_BYTES = 7;
    private const RESERVED_HEADER_BYTES = 11;

    // Instrumentation, not bookkeeping: the planner's whole claim is that an
    // indexed lookup does not scan, and that is only checkable by counting.
    private static int $recordReads = 0;

    /** @var resource */
    private $handle;

    public function __construct(
        private readonly string $path,
        private readonly Lock $lock,
        private readonly int $columnCount,
    ) {
        $this->openHandle();

        $this->lock->exclusive(function (): void {
            $this->size() === 0 ? $this->writeHeader() : $this->verifyHeader();
            $this->reconcile();
            $this->recover();
        });
    }

    public function close(): void
    {
        fclose($this->handle);
    }

    private function openHandle(): void
    {
        $handle = @fopen($this->path, 'c+b');

        if ($handle === false) {
            throw new StorageException('could not open ' . basename($this->path));
        }

        // Unbuffered: a userspace write buffer would make which of two writes
        // survives a SIGKILL depend on whether something happened to flush it,
        // and every ordering guarantee here is expressed in syscall order.
        stream_set_write_buffer($handle, 0);
        $this->handle = $handle;
    }

    public static function recordReads(): int
    {
        return self::$recordReads;
    }

    public static function resetRecordReads(): void
    {
        self::$recordReads = 0;
    }

    public function size(): int
    {
        fseek($this->handle, 0, SEEK_END);

        return (int) ftell($this->handle);
    }

    // Rewrites the heap keeping only live records, then swaps it in. Offsets all
    // change, so every index over this table is invalid afterwards and must be
    // rebuilt by the caller.
    public function compact(): int
    {
        return $this->lock->exclusive(function (): int {
            $temporary = $this->path . Paths::TEMP_SUFFIX;
            $handle = @fopen($temporary, 'wb');

            if ($handle === false) {
                throw new StorageException('could not open a temporary heap beside ' . basename($this->path));
            }

            stream_set_write_buffer($handle, 0);

            try {
                $kept = 0;
                $total = 0;
                $end = self::HEADER_BYTES;

                fwrite($handle, $this->headerBytes(self::HEADER_BYTES, 0));

                foreach ($this->scan(includeDead: true) as $record) {
                    $total++;

                    if (!$record->live) {
                        continue;
                    }

                    $kept++;
                    $end += self::RECORD_HEADER_BYTES + strlen($record->payload);
                    fwrite($handle, self::recordBytes($record->payload, 0));
                }

                fseek($handle, 0);
                fwrite($handle, $this->headerBytes($end, 0));
                FileSystem::sync($handle);
            } catch (Throwable $failure) {
                fclose($handle);
                @unlink($temporary);

                throw $failure;
            }

            fclose($handle);

            if (!@rename($temporary, $this->path)) {
                @unlink($temporary);

                throw new StorageException('could not replace ' . basename($this->path));
            }

            fclose($this->handle);
            $this->openHandle();

            return $total - $kept;
        });
    }

    public function append(string $payload, int $supersedes = 0): int
    {
        return $this->lock->exclusive(function () use ($payload, $supersedes): int {
            $this->ensureCurrent();
            $offset = $this->reconcile();

            $record = self::recordBytes($payload, $supersedes);

            $this->writeAt($offset, $record);
            FileSystem::sync($this->handle);

            // The mark and the pending flag are one contiguous write, never two.
            // Advancing the mark without the warning is the single ordering that
            // strands a superseded record alive forever.
            $flags = $this->readRecoveryFlags() | ($supersedes !== 0 ? self::SUPERSEDE_PENDING : 0);
            $this->writeHeaderState($offset + strlen($record), $flags);
            FileSystem::sync($this->handle);

            return $offset;
        });
    }

    public function markDead(int $offset): void
    {
        $this->lock->exclusive(function () use ($offset): void {
            $this->ensureCurrent();

            if ($offset < self::HEADER_BYTES || $offset + self::RECORD_HEADER_BYTES > $this->size()) {
                throw new StorageException("offset {$offset} is outside the heap");
            }

            $this->writeAt($offset + self::FLAGS_OFFSET_IN_RECORD, pack('C', self::FLAG_DEAD));
            FileSystem::sync($this->handle);
        });
    }

    // Called once a statement has tombstoned every record it superseded. Until
    // this lands, an opener knows it must resolve supersedes itself.
    public function finishSupersede(): void
    {
        $this->lock->exclusive(function (): void {
            $this->writeRecoveryFlags($this->readRecoveryFlags() & ~self::SUPERSEDE_PENDING);
            FileSystem::sync($this->handle);
        });
    }

    public function read(int $offset): HeapRecord
    {
        return $this->lock->shared(function () use ($offset): HeapRecord {
            $this->ensureCurrent();
            $size = $this->size();

            if ($offset < self::HEADER_BYTES || $offset >= $size) {
                throw new StorageException("offset {$offset} is outside the heap");
            }

            $outcome = $this->readAt($offset, $size);

            if ($outcome instanceof HeapDamage) {
                throw new StorageException("no readable record at offset {$offset}: {$outcome->reason}");
            }

            return $outcome;
        });
    }

    /**
     * @return Generator<int, HeapRecord>
     */
    public function scan(bool $includeDead = false): Generator
    {
        return $this->scanFrom(self::HEADER_BYTES, $includeDead);
    }

    /**
     * @return Generator<int, HeapRecord>
     */
    public function scanFrom(int $from, bool $includeDead = false): Generator
    {
        $damage = null;

        $this->lock->acquire(LOCK_SH);

        try {
            $this->ensureCurrent();
            $size = $this->size();
            $offset = $from;

            while ($offset < $size) {
                $outcome = $this->readAt($offset, $size);

                if ($outcome instanceof HeapDamage) {
                    $damage = $outcome;
                    break;
                }

                if ($includeDead || $outcome->live) {
                    yield $outcome;
                }

                $offset = $outcome->end;
            }
        } finally {
            $this->lock->release();
        }

        if ($damage !== null) {
            $this->repair($damage);
        }
    }

    private function repair(HeapDamage $damage): void
    {
        if (!$damage->atTail) {
            throw new StorageException("corrupt record at offset {$damage->offset}: {$damage->reason}");
        }

        $this->lock->exclusive(function () use ($damage): void {
            ftruncate($this->handle, $damage->offset);
            $this->writeMark($damage->offset);
            FileSystem::sync($this->handle);
        });
    }

    private function readAt(int $offset, int $size): HeapRecord|HeapDamage
    {
        self::$recordReads++;

        if ($offset + self::RECORD_HEADER_BYTES > $size) {
            return new HeapDamage($offset, 'record header runs past the end of the file', true);
        }

        $header = $this->readBytes($offset, self::RECORD_HEADER_BYTES);
        $length = unpack('N', substr($header, 0, 4))[1];
        $checksum = unpack('N', substr($header, 4, 4))[1];
        $supersedes = unpack('J', substr($header, 8, 8))[1];
        $flags = ord($header[self::FLAGS_OFFSET_IN_RECORD]);
        $end = $offset + self::RECORD_HEADER_BYTES + $length;

        if ($end > $size || $end < $offset) {
            return new HeapDamage($offset, 'payload runs past the end of the file', true);
        }

        $payload = $this->readBytes($offset + self::RECORD_HEADER_BYTES, $length);

        if (crc32($payload) !== $checksum) {
            return new HeapDamage($offset, 'checksum mismatch', $end === $size);
        }

        return new HeapRecord($offset, $end, $supersedes, $flags === self::FLAG_LIVE, $payload);
    }

    // Returns the offset a new record may be written at: everything past the
    // acknowledged mark belongs to a writer that died before its second fsync.
    private function reconcile(): int
    {
        $size = $this->size();
        $mark = $this->readMark();

        if ($mark < self::HEADER_BYTES || $mark > $size) {
            $mark = $this->rebuildMark($size);
        }

        if ($size > $mark) {
            ftruncate($this->handle, $mark);
            $this->writeMark($mark);
            FileSystem::sync($this->handle);
        }

        return $mark;
    }

    // Resolving supersedes needs a full scan, so it runs only when the header
    // says a writer died between appending a replacement and retiring the record
    // it replaced. Marking an already-dead record dead again writes the same
    // byte, which is what makes a repeated recovery a no-op.
    private function recover(): void
    {
        if (($this->readRecoveryFlags() & self::SUPERSEDE_PENDING) === 0) {
            return;
        }

        $size = $this->size();
        $offset = self::HEADER_BYTES;
        $superseded = [];

        while ($offset < $size) {
            $outcome = $this->readAt($offset, $size);

            if ($outcome instanceof HeapDamage) {
                break;
            }

            if ($outcome->live && $outcome->supersedes !== 0) {
                $superseded[$outcome->supersedes] = true;
            }

            $offset = $outcome->end;
        }

        foreach (array_keys($superseded) as $target) {
            $this->writeAt($target + self::FLAGS_OFFSET_IN_RECORD, pack('C', self::FLAG_DEAD));
        }

        $this->writeRecoveryFlags(0);
        FileSystem::sync($this->handle);
    }

    private function rebuildMark(int $size): int
    {
        $offset = self::HEADER_BYTES;

        while ($offset < $size) {
            $outcome = $this->readAt($offset, $size);

            if ($outcome instanceof HeapDamage) {
                break;
            }

            $offset = $outcome->end;
        }

        return $offset;
    }

    private function writeHeader(): void
    {
        $this->writeAt(0, $this->headerBytes(self::HEADER_BYTES, 0));
        FileSystem::sync($this->handle);
    }

    private function headerBytes(int $mark, int $recoveryFlags): string
    {
        return self::MAGIC
            . pack('n', self::VERSION)
            . pack('n', $this->columnCount)
            . pack('J', $mark)
            . pack('C', $recoveryFlags)
            . str_repeat("\0", self::RESERVED_HEADER_BYTES);
    }

    private static function recordBytes(string $payload, int $supersedes): string
    {
        return pack('N', strlen($payload))
            . pack('N', crc32($payload))
            . pack('J', $supersedes)
            . pack('C', self::FLAG_LIVE)
            . str_repeat("\0", self::RESERVED_RECORD_BYTES)
            . $payload;
    }

    // Another process may have compacted this table and swapped a new file into
    // place. Writing on through the old handle would target an unlinked inode,
    // and every one of those writes would be silently thrown away.
    private function ensureCurrent(): void
    {
        $onDisk = @stat($this->path);
        $own = fstat($this->handle);

        if ($onDisk === false || $onDisk['ino'] === $own['ino']) {
            return;
        }

        fclose($this->handle);
        $this->openHandle();
        $this->verifyHeader();
    }

    private function verifyHeader(): void
    {
        if ($this->size() < self::HEADER_BYTES) {
            throw new StorageException(basename($this->path) . ' is too short to hold a heap header');
        }

        $header = $this->readBytes(0, self::HEADER_BYTES);

        if (substr($header, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new StorageException(basename($this->path) . ' is not an elephpantdb heap file');
        }

        $version = unpack('n', substr($header, 8, 2))[1];

        if ($version !== self::VERSION) {
            throw new StorageException("unsupported heap version {$version}");
        }

        $columnCount = unpack('n', substr($header, 10, 2))[1];

        if ($columnCount !== $this->columnCount) {
            throw new StorageException(
                "heap holds {$columnCount} columns but the schema declares {$this->columnCount}",
            );
        }
    }

    private function readMark(): int
    {
        return unpack('J', $this->readBytes(self::LAST_RECORD_END_OFFSET, 8))[1];
    }

    private function writeMark(int $end): void
    {
        $this->writeHeaderState($end, $this->readRecoveryFlags());
    }

    // lastRecordEnd and recoveryFlags are adjacent so that they can always be
    // written together.
    private function writeHeaderState(int $end, int $flags): void
    {
        $this->writeAt(self::LAST_RECORD_END_OFFSET, pack('J', $end) . pack('C', $flags));
    }

    private function readRecoveryFlags(): int
    {
        return ord($this->readBytes(self::RECOVERY_FLAGS_OFFSET, 1));
    }

    private function writeRecoveryFlags(int $flags): void
    {
        $this->writeAt(self::RECOVERY_FLAGS_OFFSET, pack('C', $flags));
    }

    private function readBytes(int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        fseek($this->handle, $offset);
        $bytes = fread($this->handle, $length);

        if ($bytes === false || strlen($bytes) !== $length) {
            throw new StorageException("short read of {$length} bytes at offset {$offset}");
        }

        return $bytes;
    }

    private function writeAt(int $offset, string $bytes): void
    {
        fseek($this->handle, $offset);

        if (fwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new StorageException('short write to ' . basename($this->path));
        }
    }
}

// =============================================================================
// INDEX
//
// An index is a derivable cache and the heap is authoritative, which is what
// lets the heap fsync first and the index catch up afterwards. A stale or wrong
// index can make a query slow; it can never make one wrong, because every hit
// is verified against the heap record it points at.
// =============================================================================

final class HashIndex
{
    public const MAGIC = 'EPHPHIDX';
    public const VERSION = 1;
    public const HEADER_BYTES = 32;
    public const BUCKET_COUNT_OFFSET = 12;
    public const ENTRY_COUNT_OFFSET = 16;
    public const HEAP_SIZE_OFFSET = 24;
    public const INITIAL_BUCKETS = 64;
    public const MAX_LOAD_FACTOR = 4;

    private const MAX_BUCKETS = 1 << 24;
    private const BUCKET_BYTES = 8;
    private const ENTRY_FIXED_BYTES = 20;
    private const EMPTY_BUCKET = 0;

    /** @var resource */
    private $handle;

    private int $bucketCount = 0;

    public function __construct(
        private readonly string $path,
        private readonly Lock $lock,
    ) {
        $this->open();
    }

    public function close(): void
    {
        fclose($this->handle);
    }

    public function bucketCount(): int
    {
        return $this->bucketCount;
    }

    public function entryCount(): int
    {
        return $this->lock->shared(fn (): int => $this->readUint64(self::ENTRY_COUNT_OFFSET));
    }

    public function heapSizeAtLastSync(): int
    {
        return $this->lock->shared(fn (): int => $this->readUint64(self::HEAP_SIZE_OFFSET));
    }

    public function setHeapSize(int $size): void
    {
        $this->lock->exclusive(function () use ($size): void {
            $this->writeAt(self::HEAP_SIZE_OFFSET, pack('J', $size));
            FileSystem::sync($this->handle);
        });
    }

    /**
     * @return list<int> heap offsets for the key, newest first
     */
    public function offsetsFor(string $key): array
    {
        return $this->lock->shared(function () use ($key): array {
            $this->ensureCurrent();
            $offsets = [];
            $entry = $this->readBucket($this->bucketFor($key));

            while ($entry !== self::EMPTY_BUCKET) {
                [$next, $entryKey, $heapOffset] = $this->readEntry($entry);

                if ($entryKey === $key) {
                    $offsets[] = $heapOffset;
                }

                $entry = $next;
            }

            return $offsets;
        });
    }

    // The heap size the index is current through is written in the same fsync as
    // the entry, so an index update costs one sync however many fields it touches.
    public function insert(string $key, int $heapOffset, int $heapSize): void
    {
        $this->lock->exclusive(function () use ($key, $heapOffset, $heapSize): void {
            $this->ensureCurrent();
            $this->appendEntry($key, $heapOffset);
            $this->writeAt(self::HEAP_SIZE_OFFSET, pack('J', $heapSize));
            FileSystem::sync($this->handle);
            $this->growIfOverloaded();
        });
    }

    // An UPDATE that leaves the indexed value unchanged repoints the existing
    // entry rather than chaining a new one. Without this, a row updated N times
    // leaves an N-long chain of entries pointing at dead records, and every
    // lookup pays a heap read per link to discover they are dead.
    public function repoint(string $key, int $fromHeapOffset, int $toHeapOffset, int $heapSize): bool
    {
        return $this->lock->exclusive(function () use ($key, $fromHeapOffset, $toHeapOffset, $heapSize): bool {
            $entry = $this->readBucket($this->bucketFor($key));

            while ($entry !== self::EMPTY_BUCKET) {
                [$next, $entryKey, $heapOffset] = $this->readEntry($entry);

                if ($entryKey === $key && $heapOffset === $fromHeapOffset) {
                    $this->writeAt($entry + 12 + strlen($entryKey), pack('J', $toHeapOffset));
                    $this->writeAt(self::HEAP_SIZE_OFFSET, pack('J', $heapSize));
                    FileSystem::sync($this->handle);

                    return true;
                }

                $entry = $next;
            }

            return false;
        });
    }

    /**
     * @return Generator<int, array{0: string, 1: int}>
     */
    public function entries(): Generator
    {
        $this->lock->acquire(LOCK_SH);

        try {
            $offset = $this->entriesStart();
            $size = $this->size();

            while ($offset < $size) {
                [$next, $key, $heapOffset] = $this->readEntry($offset);

                yield [$key, $heapOffset];

                $offset += self::ENTRY_FIXED_BYTES + strlen($key);
            }
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param iterable<array{0: string, 1: int}> $entries
     */
    public function rebuild(iterable $entries, int $heapSize): void
    {
        $this->lock->exclusive(function () use ($entries, $heapSize): void {
            $materialised = is_array($entries) ? $entries : iterator_to_array($entries, false);

            FileSystem::replaceAtomically($this->path, self::render($materialised, $heapSize));

            fclose($this->handle);
            $this->open();
        });
    }

    // Another process may have rebuilt or compacted this index and renamed a new
    // file over it; the old handle points at an unlinked inode.
    private function ensureCurrent(): void
    {
        $onDisk = @stat($this->path);
        $own = fstat($this->handle);

        if ($onDisk === false || $onDisk['ino'] === $own['ino']) {
            return;
        }

        fclose($this->handle);
        $this->open();
    }

    private function open(): void
    {
        $handle = @fopen($this->path, 'c+b');

        if ($handle === false) {
            throw new StorageException('could not open ' . basename($this->path));
        }

        stream_set_write_buffer($handle, 0);
        $this->handle = $handle;

        $this->lock->exclusive(function (): void {
            if ($this->size() === 0) {
                $this->writeHeaderAndBuckets(self::INITIAL_BUCKETS, Heap::HEADER_BYTES);
                FileSystem::sync($this->handle);
            } else {
                $this->verifyHeader();
            }

            $this->bucketCount = (int) unpack('N', $this->readBytes(self::BUCKET_COUNT_OFFSET, 4))[1];
            $this->verifyBuckets();
        });
    }

    private function verifyHeader(): void
    {
        if ($this->size() < self::HEADER_BYTES) {
            throw new StorageException(basename($this->path) . ' is too short to hold an index header');
        }

        $header = $this->readBytes(0, self::HEADER_BYTES);

        if (substr($header, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new StorageException(basename($this->path) . ' is not an elephpantdb index file');
        }

        $version = unpack('n', substr($header, 8, 2))[1];

        if ($version !== self::VERSION) {
            throw new StorageException("unsupported index version {$version}");
        }
    }

    // Cheap structural check: a truncated or scribbled index shows up as a chain
    // head that cannot be an entry. Walking every chain would be O(n) at open,
    // which is the cost this whole design exists to avoid.
    private function verifyBuckets(): void
    {
        if ($this->bucketCount < 1 || $this->bucketCount > self::MAX_BUCKETS) {
            throw new StorageException(basename($this->path) . ' declares an impossible bucket count');
        }

        $entriesStart = $this->entriesStart();
        $size = $this->size();

        if ($size < $entriesStart) {
            throw new StorageException(basename($this->path) . ' is shorter than its bucket array');
        }

        $packed = $this->readBytes(self::HEADER_BYTES, $this->bucketCount * self::BUCKET_BYTES);

        foreach (unpack('J*', $packed) as $head) {
            if ($head !== self::EMPTY_BUCKET && ($head < $entriesStart || $head + 12 > $size)) {
                throw new StorageException(basename($this->path) . ' has a chain head outside the file');
            }
        }
    }

    private function writeHeaderAndBuckets(int $buckets, int $heapSize): void
    {
        $header = self::MAGIC
            . pack('n', self::VERSION)
            . pack('n', 0)
            . pack('N', $buckets)
            . pack('J', 0)
            . pack('J', $heapSize);

        $this->writeAt(0, $header . str_repeat("\0", $buckets * self::BUCKET_BYTES));
    }

    /**
     * @param list<array{0: string, 1: int}> $entries
     */
    private static function render(array $entries, int $heapSize): string
    {
        $buckets = self::bucketsFor(count($entries));
        $bucketHeads = array_fill(0, $buckets, self::EMPTY_BUCKET);
        $body = '';
        $cursor = self::HEADER_BYTES + $buckets * self::BUCKET_BYTES;

        foreach ($entries as [$key, $heapOffset]) {
            $bucket = crc32($key) % $buckets;
            $body .= pack('J', $bucketHeads[$bucket]) . pack('N', strlen($key)) . $key . pack('J', $heapOffset);
            $bucketHeads[$bucket] = $cursor;
            $cursor += self::ENTRY_FIXED_BYTES + strlen($key);
        }

        $header = self::MAGIC
            . pack('n', self::VERSION)
            . pack('n', 0)
            . pack('N', $buckets)
            . pack('J', count($entries))
            . pack('J', $heapSize);

        $packedBuckets = '';

        foreach ($bucketHeads as $head) {
            $packedBuckets .= pack('J', $head);
        }

        return $header . $packedBuckets . $body;
    }

    private function appendEntry(string $key, int $heapOffset): void
    {
        $bucket = $this->bucketFor($key);
        $offset = $this->size();

        $this->writeAt(
            $offset,
            pack('J', $this->readBucket($bucket)) . pack('N', strlen($key)) . $key . pack('J', $heapOffset),
        );
        $this->writeBucket($bucket, $offset);
        $this->writeAt(self::ENTRY_COUNT_OFFSET, pack('J', $this->readUint64(self::ENTRY_COUNT_OFFSET) + 1));
    }

    private function growIfOverloaded(): void
    {
        if ($this->readUint64(self::ENTRY_COUNT_OFFSET) <= $this->bucketCount * self::MAX_LOAD_FACTOR) {
            return;
        }

        $entries = iterator_to_array($this->entries(), false);
        $this->rebuild($entries, $this->readUint64(self::HEAP_SIZE_OFFSET));
    }

    private static function bucketsFor(int $entryCount): int
    {
        $buckets = self::INITIAL_BUCKETS;

        while ($entryCount > $buckets * self::MAX_LOAD_FACTOR) {
            $buckets *= 2;
        }

        return $buckets;
    }

    private function bucketFor(string $key): int
    {
        return crc32($key) % $this->bucketCount;
    }

    private function entriesStart(): int
    {
        return self::HEADER_BYTES + $this->bucketCount * self::BUCKET_BYTES;
    }

    private function readBucket(int $bucket): int
    {
        return $this->readUint64(self::HEADER_BYTES + $bucket * self::BUCKET_BYTES);
    }

    private function writeBucket(int $bucket, int $entryOffset): void
    {
        $this->writeAt(self::HEADER_BYTES + $bucket * self::BUCKET_BYTES, pack('J', $entryOffset));
    }

    /**
     * @return array{0: int, 1: string, 2: int}
     */
    private function readEntry(int $offset): array
    {
        $fixed = $this->readBytes($offset, 12);
        $next = unpack('J', substr($fixed, 0, 8))[1];
        $keyLength = unpack('N', substr($fixed, 8, 4))[1];
        $key = $this->readBytes($offset + 12, $keyLength);
        $heapOffset = $this->readUint64($offset + 12 + $keyLength);

        return [$next, $key, $heapOffset];
    }

    private function readUint64(int $offset): int
    {
        return (int) unpack('J', $this->readBytes($offset, 8))[1];
    }

    private function size(): int
    {
        fseek($this->handle, 0, SEEK_END);

        return (int) ftell($this->handle);
    }

    private function readBytes(int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        fseek($this->handle, $offset);
        $bytes = fread($this->handle, $length);

        if ($bytes === false || strlen($bytes) !== $length) {
            throw new StorageException("short read of {$length} bytes at offset {$offset} in " . basename($this->path));
        }

        return $bytes;
    }

    private function writeAt(int $offset, string $bytes): void
    {
        fseek($this->handle, $offset);

        if (fwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new StorageException('short write to ' . basename($this->path));
        }
    }
}

// =============================================================================
// TOKENIZER
// =============================================================================

enum TokenType
{
    case Keyword;
    case Identifier;
    case Integer;
    case Float;
    case String;
    case Operator;
    case Punctuation;
    case PositionalPlaceholder;
    case NamedPlaceholder;
    case End;
}

final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $text,
        public readonly int $position,
        public readonly int|float|string|null $value = null,
    ) {
    }

    public function is(TokenType $type, ?string $text = null): bool
    {
        return $this->type === $type && ($text === null || $this->text === $text);
    }
}

final class Tokenizer
{
    private const KEYWORDS = [
        'SELECT', 'FROM', 'WHERE', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE',
        'CREATE', 'TABLE', 'INDEX', 'ON', 'VACUUM',
        'AND', 'OR', 'NOT', 'IS', 'NULL', 'TRUE', 'FALSE',
        'ORDER', 'BY', 'ASC', 'DESC', 'LIMIT', 'OFFSET',
        'PRIMARY', 'KEY',
        'INT', 'FLOAT', 'TEXT', 'BOOL',
    ];

    private const PUNCTUATION = ['(', ')', ',', '*', ';'];
    private const QUOTE = "'";
    private const MAX_PLACEHOLDER_NAME = 64;

    /**
     * @return list<Token>
     */
    public static function tokenize(string $sql): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($sql);

        while ($offset < $length) {
            $character = $sql[$offset];

            if (ctype_space($character)) {
                $offset++;
                continue;
            }

            $token = match (true) {
                $character === self::QUOTE => self::readString($sql, $offset),
                ctype_digit($character) => self::readNumber($sql, $offset),
                self::startsWord($character) => self::readWord($sql, $offset),
                $character === '?' => new Token(TokenType::PositionalPlaceholder, '?', $offset),
                $character === ':' => self::readNamedPlaceholder($sql, $offset),
                in_array($character, self::PUNCTUATION, true) => new Token(TokenType::Punctuation, $character, $offset),
                default => self::readOperator($sql, $offset),
            };

            $tokens[] = $token;
            $offset = self::advance($token, $offset);
        }

        $tokens[] = new Token(TokenType::End, '', $length);

        return $tokens;
    }

    private static function advance(Token $token, int $offset): int
    {
        return $token->type === TokenType::String
            ? $offset + self::quotedLength((string) $token->value)
            : $offset + strlen($token->text);
    }

    // A string token's text is its unescaped value, so its source width has to
    // be recomputed from the doubled quotes it was written with.
    private static function quotedLength(string $value): int
    {
        return 2 + strlen($value) + substr_count($value, self::QUOTE);
    }

    private static function startsWord(string $character): bool
    {
        return ctype_alpha($character) || $character === '_';
    }

    private static function readWord(string $sql, int $offset): Token
    {
        preg_match('/\A[A-Za-z_][A-Za-z0-9_]*/', substr($sql, $offset), $matches);
        $word = $matches[0];
        $upper = strtoupper($word);

        return in_array($upper, self::KEYWORDS, true)
            ? new Token(TokenType::Keyword, $upper, $offset)
            : new Token(TokenType::Identifier, $word, $offset);
    }

    private static function readNumber(string $sql, int $offset): Token
    {
        preg_match('/\A\d+(\.\d+)?([eE][+-]?\d+)?/', substr($sql, $offset), $matches);
        $text = $matches[0];
        $isFloat = isset($matches[1]) && $matches[1] !== '' || isset($matches[2]) && $matches[2] !== '';

        if ($isFloat) {
            return new Token(TokenType::Float, $text, $offset, (float) $text);
        }

        $value = filter_var($text, FILTER_VALIDATE_INT);

        if ($value === false) {
            throw new ParseException("integer literal '{$text}' is too large for a 64-bit column", $offset);
        }

        return new Token(TokenType::Integer, $text, $offset, $value);
    }

    private static function readString(string $sql, int $offset): Token
    {
        $length = strlen($sql);
        $cursor = $offset + 1;
        $value = '';

        while ($cursor < $length) {
            if ($sql[$cursor] !== self::QUOTE) {
                $value .= $sql[$cursor];
                $cursor++;
                continue;
            }

            if (($sql[$cursor + 1] ?? '') === self::QUOTE) {
                $value .= self::QUOTE;
                $cursor += 2;
                continue;
            }

            return new Token(TokenType::String, $value, $offset, $value);
        }

        throw new ParseException('unterminated string literal', $offset);
    }

    private static function readNamedPlaceholder(string $sql, int $offset): Token
    {
        preg_match('/\A[A-Za-z_][A-Za-z0-9_]*/', substr($sql, $offset + 1), $matches);
        $name = $matches[0] ?? '';

        if ($name === '' || strlen($name) > self::MAX_PLACEHOLDER_NAME) {
            throw new ParseException('expected a placeholder name after ":"', $offset);
        }

        return new Token(TokenType::NamedPlaceholder, ':' . $name, $offset, $name);
    }

    private static function readOperator(string $sql, int $offset): Token
    {
        foreach (['!=', '<>', '<=', '>='] as $operator) {
            if (substr($sql, $offset, 2) === $operator) {
                return new Token(TokenType::Operator, $operator, $offset);
            }
        }

        if (in_array($sql[$offset], ['=', '<', '>', '-'], true)) {
            return new Token(TokenType::Operator, $sql[$offset], $offset);
        }

        throw new ParseException('unexpected character ' . self::describe($sql[$offset]), $offset);
    }

    private static function describe(string $character): string
    {
        return ctype_print($character)
            ? "'{$character}'"
            : '0x' . strtoupper(bin2hex($character));
    }
}

// =============================================================================
// PARSER
//
// Bound parameters become Placeholder nodes and are resolved at execution time.
// Nothing here ever splices a value into SQL text, so injection is not a thing
// that can happen rather than a thing that is escaped away.
// =============================================================================

interface Statement
{
}

interface Expression
{
}

final class LiteralExpression implements Expression
{
    public function __construct(public readonly int|float|string|bool|null $value)
    {
    }
}

final class PlaceholderExpression implements Expression
{
    public function __construct(public readonly int|string $key)
    {
    }
}

final class ColumnExpression implements Expression
{
    public function __construct(public readonly string $name)
    {
        Identifier::validate($name);
    }
}

final class ComparisonExpression implements Expression
{
    public function __construct(
        public readonly Expression $left,
        public readonly string $operator,
        public readonly Expression $right,
    ) {
    }
}

final class NullTestExpression implements Expression
{
    public function __construct(
        public readonly Expression $operand,
        public readonly bool $negated,
    ) {
    }
}

final class LogicalExpression implements Expression
{
    public function __construct(
        public readonly string $operator,
        public readonly Expression $left,
        public readonly Expression $right,
    ) {
    }
}

final class NotExpression implements Expression
{
    public function __construct(public readonly Expression $operand)
    {
    }
}

final class CreateTableStatement implements Statement
{
    public function __construct(
        public readonly string $table,
        public readonly Schema $schema,
    ) {
    }
}

final class InsertStatement implements Statement
{
    /**
     * @param list<string>     $columns
     * @param list<Expression> $values
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly array $values,
    ) {
    }
}

final class OrderBy
{
    public function __construct(
        public readonly string $column,
        public readonly bool $descending,
    ) {
    }
}

final class VacuumStatement implements Statement
{
    public function __construct(public readonly string $table)
    {
    }
}

final class CreateIndexStatement implements Statement
{
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly string $column,
    ) {
    }
}

final class Assignment
{
    public function __construct(
        public readonly string $column,
        public readonly Expression $value,
    ) {
    }
}

final class UpdateStatement implements Statement
{
    /**
     * @param list<Assignment> $assignments
     */
    public function __construct(
        public readonly string $table,
        public readonly array $assignments,
        public readonly ?Expression $where,
    ) {
    }
}

final class DeleteStatement implements Statement
{
    public function __construct(
        public readonly string $table,
        public readonly ?Expression $where,
    ) {
    }
}

final class SelectStatement implements Statement
{
    /**
     * @param list<string>|null $columns null selects every column
     */
    public function __construct(
        public readonly string $table,
        public readonly ?array $columns,
        public readonly ?Expression $where = null,
        public readonly ?OrderBy $orderBy = null,
        public readonly ?Expression $limit = null,
        public readonly ?Expression $offset = null,
    ) {
    }
}

final class Parser
{
    private const COMPARISONS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    private const COLUMN_TYPES = [
        'INT' => ColumnType::Integer,
        'FLOAT' => ColumnType::Float,
        'TEXT' => ColumnType::Text,
        'BOOL' => ColumnType::Boolean,
    ];

    private int $cursor = 0;
    private ?bool $positional = null;
    private int $placeholders = 0;

    /**
     * @param list<Token> $tokens
     */
    private function __construct(private readonly array $tokens)
    {
    }

    public static function parse(string $sql): Statement
    {
        return (new self(Tokenizer::tokenize($sql)))->statement();
    }

    private function statement(): Statement
    {
        $statement = match (true) {
            $this->peek()->is(TokenType::Keyword, 'CREATE') => $this->create(),
            $this->peek()->is(TokenType::Keyword, 'INSERT') => $this->insert(),
            $this->peek()->is(TokenType::Keyword, 'SELECT') => $this->select(),
            $this->peek()->is(TokenType::Keyword, 'UPDATE') => $this->update(),
            $this->peek()->is(TokenType::Keyword, 'DELETE') => $this->delete(),
            $this->peek()->is(TokenType::Keyword, 'VACUUM') => $this->vacuum(),
            default => throw $this->unexpected('a statement'),
        };

        if ($this->peek()->is(TokenType::Punctuation, ';')) {
            $this->advance();
        }

        if ($this->peek()->type !== TokenType::End) {
            throw $this->unexpected('end of statement');
        }

        return $statement;
    }

    private function create(): Statement
    {
        $this->expectKeyword('CREATE');

        if ($this->consumeKeyword('INDEX')) {
            return $this->createIndex();
        }

        return $this->createTable();
    }

    private function createIndex(): CreateIndexStatement
    {
        $name = $this->name();
        $this->expectKeyword('ON');
        $table = $this->name();
        $this->expectPunctuation('(');
        $column = $this->name();
        $this->expectPunctuation(')');

        return new CreateIndexStatement($name, $table, $column);
    }

    private function createTable(): CreateTableStatement
    {
        $this->expectKeyword('TABLE');

        $table = $this->name();
        $this->expectPunctuation('(');

        $columns = [];

        do {
            $columns[] = $this->columnDefinition();
        } while ($this->consumePunctuation(','));

        $this->expectPunctuation(')');

        return new CreateTableStatement($table, new Schema($columns));
    }

    private function columnDefinition(): Column
    {
        $name = $this->name();
        $token = $this->advance();
        $type = self::COLUMN_TYPES[$token->text] ?? null;

        if ($type === null || $token->type !== TokenType::Keyword) {
            throw $this->unexpectedToken($token, 'a column type');
        }

        $primaryKey = false;
        $notNull = false;

        while (true) {
            if ($this->peek()->is(TokenType::Keyword, 'PRIMARY')) {
                $this->advance();
                $this->expectKeyword('KEY');
                $primaryKey = true;
                continue;
            }

            if ($this->peek()->is(TokenType::Keyword, 'NOT')) {
                $this->advance();
                $this->expectKeyword('NULL');
                $notNull = true;
                continue;
            }

            break;
        }

        // A primary key that could be null would give two rows the same absent
        // identity, so the constraint is implied rather than merely allowed.
        return new Column($name, $type, $primaryKey, $notNull || $primaryKey);
    }

    private function insert(): InsertStatement
    {
        $this->expectKeyword('INSERT');
        $this->expectKeyword('INTO');

        $table = $this->name();
        $this->expectPunctuation('(');

        $columns = [];

        do {
            $columns[] = $this->name();
        } while ($this->consumePunctuation(','));

        $this->expectPunctuation(')');
        $this->expectKeyword('VALUES');
        $this->expectPunctuation('(');

        $values = [];

        do {
            $values[] = $this->value();
        } while ($this->consumePunctuation(','));

        $closing = $this->peek();
        $this->expectPunctuation(')');

        if (count($values) !== count($columns)) {
            throw new ParseException(
                'expected ' . count($columns) . ' values for ' . count($columns) . ' columns, found ' . count($values),
                $closing->position,
            );
        }

        return new InsertStatement($table, $columns, $values);
    }

    private function select(): SelectStatement
    {
        $this->expectKeyword('SELECT');

        $columns = null;

        if ($this->consumePunctuation('*')) {
            $columns = null;
        } else {
            $columns = [];

            do {
                $columns[] = $this->name();
            } while ($this->consumePunctuation(','));
        }

        $this->expectKeyword('FROM');
        $table = $this->name();
        $where = $this->whereClause();
        $orderBy = $this->orderByClause();
        [$limit, $offset] = $this->limitClause();

        return new SelectStatement($table, $columns, $where, $orderBy, $limit, $offset);
    }

    private function update(): UpdateStatement
    {
        $this->expectKeyword('UPDATE');
        $table = $this->name();
        $this->expectKeyword('SET');

        $assignments = [];

        do {
            $column = $this->name();
            $this->expectOperator('=');
            $assignments[] = new Assignment($column, $this->value());
        } while ($this->consumePunctuation(','));

        return new UpdateStatement($table, $assignments, $this->whereClause());
    }

    private function vacuum(): VacuumStatement
    {
        $this->expectKeyword('VACUUM');

        return new VacuumStatement($this->name());
    }

    private function delete(): DeleteStatement
    {
        $this->expectKeyword('DELETE');
        $this->expectKeyword('FROM');

        return new DeleteStatement($this->name(), $this->whereClause());
    }

    private function orderByClause(): ?OrderBy
    {
        if (!$this->consumeKeyword('ORDER')) {
            return null;
        }

        $this->expectKeyword('BY');
        $column = $this->name();

        if ($this->consumeKeyword('DESC')) {
            return new OrderBy($column, true);
        }

        $this->consumeKeyword('ASC');

        return new OrderBy($column, false);
    }

    /**
     * @return array{0: Expression|null, 1: Expression|null}
     */
    private function limitClause(): array
    {
        if (!$this->consumeKeyword('LIMIT')) {
            return [null, null];
        }

        $limit = $this->value();

        return [$limit, $this->consumeKeyword('OFFSET') ? $this->value() : null];
    }

    private function whereClause(): ?Expression
    {
        return $this->consumeKeyword('WHERE') ? $this->expression() : null;
    }

    private function expression(): Expression
    {
        return $this->disjunction();
    }

    private function disjunction(): Expression
    {
        $left = $this->conjunction();

        while ($this->consumeKeyword('OR')) {
            $left = new LogicalExpression('OR', $left, $this->conjunction());
        }

        return $left;
    }

    private function conjunction(): Expression
    {
        $left = $this->negation();

        while ($this->consumeKeyword('AND')) {
            $left = new LogicalExpression('AND', $left, $this->negation());
        }

        return $left;
    }

    // A '(' can only ever open a grouped predicate here: there is no arithmetic,
    // so no operand is ever parenthesised.
    private function negation(): Expression
    {
        if ($this->consumeKeyword('NOT')) {
            return new NotExpression($this->negation());
        }

        if ($this->consumePunctuation('(')) {
            $inner = $this->expression();
            $this->expectPunctuation(')');

            return $inner;
        }

        return $this->comparison();
    }

    private function comparison(): Expression
    {
        $left = $this->operand();

        if ($this->consumeKeyword('IS')) {
            $negated = $this->consumeKeyword('NOT');
            $this->expectKeyword('NULL');

            return new NullTestExpression($left, $negated);
        }

        $token = $this->peek();

        if ($token->type === TokenType::Operator && in_array($token->text, self::COMPARISONS, true)) {
            $this->advance();

            return new ComparisonExpression($left, $token->text, $this->operand());
        }

        throw $this->unexpected('a comparison operator or IS NULL');
    }

    private function operand(): Expression
    {
        return $this->peek()->type === TokenType::Identifier
            ? new ColumnExpression($this->name())
            : $this->value();
    }

    private function value(): Expression
    {
        $token = $this->peek();

        if ($token->type === TokenType::PositionalPlaceholder || $token->type === TokenType::NamedPlaceholder) {
            return $this->placeholder($this->advance());
        }

        if ($token->is(TokenType::Operator, '-')) {
            $this->advance();
            $number = $this->advance();

            return match ($number->type) {
                TokenType::Integer => new LiteralExpression(-(int) $number->value),
                TokenType::Float => new LiteralExpression(-(float) $number->value),
                default => throw $this->unexpectedToken($number, 'a number after "-"'),
            };
        }

        $this->advance();

        return match (true) {
            $token->type === TokenType::Integer, $token->type === TokenType::Float,
            $token->type === TokenType::String => new LiteralExpression($token->value),
            $token->is(TokenType::Keyword, 'TRUE') => new LiteralExpression(true),
            $token->is(TokenType::Keyword, 'FALSE') => new LiteralExpression(false),
            $token->is(TokenType::Keyword, 'NULL') => new LiteralExpression(null),
            default => throw $this->unexpectedToken($token, 'a value'),
        };
    }

    private function placeholder(Token $token): PlaceholderExpression
    {
        $isPositional = $token->type === TokenType::PositionalPlaceholder;

        if ($this->positional === null) {
            $this->positional = $isPositional;
        } elseif ($this->positional !== $isPositional) {
            throw new ParseException(
                'a statement uses either ? or :name placeholders, never both',
                $token->position,
            );
        }

        return new PlaceholderExpression($isPositional ? $this->placeholders++ : (string) $token->value);
    }

    private function name(): string
    {
        $token = $this->advance();

        if ($token->type !== TokenType::Identifier) {
            throw $this->unexpectedToken($token, 'a name');
        }

        return Identifier::validate($token->text);
    }

    private function peek(): Token
    {
        return $this->tokens[$this->cursor];
    }

    private function advance(): Token
    {
        $token = $this->tokens[$this->cursor];

        if ($token->type !== TokenType::End) {
            $this->cursor++;
        }

        return $token;
    }

    private function expectKeyword(string $keyword): void
    {
        $token = $this->advance();

        if (!$token->is(TokenType::Keyword, $keyword)) {
            throw $this->unexpectedToken($token, $keyword);
        }
    }

    private function expectPunctuation(string $character): void
    {
        $token = $this->advance();

        if (!$token->is(TokenType::Punctuation, $character)) {
            throw $this->unexpectedToken($token, "'{$character}'");
        }
    }

    private function expectOperator(string $operator): void
    {
        $token = $this->advance();

        if (!$token->is(TokenType::Operator, $operator)) {
            throw $this->unexpectedToken($token, "'{$operator}'");
        }
    }

    private function consumeKeyword(string $keyword): bool
    {
        if (!$this->peek()->is(TokenType::Keyword, $keyword)) {
            return false;
        }

        $this->advance();

        return true;
    }

    private function consumePunctuation(string $character): bool
    {
        if (!$this->peek()->is(TokenType::Punctuation, $character)) {
            return false;
        }

        $this->advance();

        return true;
    }

    private function unexpected(string $expected): ParseException
    {
        return $this->unexpectedToken($this->peek(), $expected);
    }

    private function unexpectedToken(Token $token, string $expected): ParseException
    {
        $found = $token->type === TokenType::End ? 'end of statement' : "'{$token->text}'";

        return new ParseException("expected {$expected}, found {$found}", $token->position);
    }
}

// =============================================================================
// PLANNER
//
// One rule: an equality test on an indexed column, anywhere in the top-level AND
// chain, becomes an index probe whose hits are then verified against the rest of
// the clause. Everything else scans. A wrong answer here costs speed, never
// correctness, because the heap record still has to satisfy the full predicate.
// =============================================================================

final class Planner
{
    /**
     * @return list<int>|null heap offsets worth reading, or null to scan
     */
    public static function candidates(Expression $where, Schema $schema, Table $table, Binder $binder): ?array
    {
        foreach (self::conjuncts($where) as $conjunct) {
            if (!$conjunct instanceof ComparisonExpression || $conjunct->operator !== '=') {
                continue;
            }

            $candidates = self::probe($conjunct, $schema, $table, $binder);

            if ($candidates !== null) {
                return $candidates;
            }
        }

        return null;
    }

    /**
     * @return list<Expression>
     */
    private static function conjuncts(Expression $expression): array
    {
        if ($expression instanceof LogicalExpression && $expression->operator === 'AND') {
            return [...self::conjuncts($expression->left), ...self::conjuncts($expression->right)];
        }

        return [$expression];
    }

    /**
     * @return list<int>|null
     */
    private static function probe(ComparisonExpression $comparison, Schema $schema, Table $table, Binder $binder): ?array
    {
        foreach ([[$comparison->left, $comparison->right], [$comparison->right, $comparison->left]] as [$column, $value]) {
            if (!$column instanceof ColumnExpression || $value instanceof ColumnExpression) {
                continue;
            }

            $candidates = $table->candidatesFor($schema->ordinal($column->name), $binder->value($value));

            if ($candidates !== null) {
                return $candidates;
            }
        }

        return null;
    }
}

// =============================================================================
// EXECUTOR
// =============================================================================

final class QueryResult
{
    /**
     * @param list<array<string, mixed>>|null $rows
     */
    private function __construct(
        public readonly ?array $rows,
        public readonly ?int $rowsAffected,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function fromRows(array $rows): self
    {
        return new self($rows, null);
    }

    public static function affected(int $count): self
    {
        return new self(null, $count);
    }
}

final class Binder
{
    /** @var array<int|string, true> */
    private array $used = [];

    /**
     * @param array<int|string, mixed> $parameters
     */
    public function __construct(private readonly array $parameters)
    {
    }

    public function value(Expression $expression): mixed
    {
        return match (true) {
            $expression instanceof LiteralExpression => $expression->value,
            $expression instanceof PlaceholderExpression => $this->parameter($expression->key),
            default => throw new SchemaException('expected a value'),
        };
    }

    public function assertEveryParameterUsed(): void
    {
        $unused = array_diff_key($this->parameters, $this->used);

        if ($unused === []) {
            return;
        }

        $names = array_map(
            static fn (int|string $key): string => is_int($key) ? (string) ($key + 1) : "':{$key}'",
            array_keys($unused),
        );

        throw new SchemaException('the statement does not use parameter ' . implode(', ', $names));
    }

    private function parameter(int|string $key): mixed
    {
        if (!array_key_exists($key, $this->parameters)) {
            throw new SchemaException(
                is_int($key) ? 'missing parameter ' . ($key + 1) : "missing parameter ':{$key}'",
            );
        }

        $this->used[$key] = true;

        return $this->parameters[$key];
    }
}

// Three-valued logic, not two: comparing anything with NULL yields unknown, and
// unknown must stay unknown under NOT. Collapsing it to false would make
// "NOT name = 'zoe'" return the rows where name is NULL.
final class Predicate
{
    /** @var array<string, int> */
    private array $ordinals = [];

    public function __construct(
        private readonly Expression $expression,
        private readonly Schema $schema,
        private readonly Binder $binder,
    ) {
        $this->check($expression);
    }

    /**
     * @param list<mixed> $values
     */
    public function matches(array $values): bool
    {
        return $this->evaluate($this->expression, $values) === true;
    }

    private function check(Expression $expression): void
    {
        if ($expression instanceof LogicalExpression) {
            $this->check($expression->left);
            $this->check($expression->right);

            return;
        }

        if ($expression instanceof NotExpression) {
            $this->check($expression->operand);

            return;
        }

        if ($expression instanceof NullTestExpression) {
            $this->checkOperand($expression->operand);

            return;
        }

        if ($expression instanceof ComparisonExpression) {
            $this->checkOperand($expression->left);
            $this->checkOperand($expression->right);
            $this->checkComparable($expression);

            return;
        }

        throw new SchemaException('a where clause must be a condition');
    }

    private function checkOperand(Expression $expression): void
    {
        if ($expression instanceof ColumnExpression) {
            $this->ordinals[strtolower($expression->name)] = $this->schema->ordinal($expression->name);

            return;
        }

        $this->binder->value($expression);
    }

    private function checkComparable(ComparisonExpression $comparison): void
    {
        $left = $this->staticType($comparison->left);
        $right = $this->staticType($comparison->right);

        // A NULL on either side is comparable with anything; the answer is just
        // always unknown.
        if ($left === null || $right === null) {
            return;
        }

        $numeric = ['integer', 'float'];

        if ($left === $right || (in_array($left, $numeric, true) && in_array($right, $numeric, true))) {
            return;
        }

        throw new SchemaException("cannot compare {$left} with {$right}");
    }

    private function staticType(Expression $expression): ?string
    {
        if ($expression instanceof ColumnExpression) {
            return strtolower($this->schema->column($this->ordinals[strtolower($expression->name)])->type->name);
        }

        return match (get_debug_type($this->binder->value($expression))) {
            'int' => 'integer',
            'float' => 'float',
            'string' => 'text',
            'bool' => 'boolean',
            default => null,
        };
    }

    /**
     * @param list<mixed> $values
     */
    private function evaluate(Expression $expression, array $values): ?bool
    {
        if ($expression instanceof LogicalExpression) {
            return $expression->operator === 'AND'
                ? $this->conjunction($expression, $values)
                : $this->disjunction($expression, $values);
        }

        if ($expression instanceof NotExpression) {
            $inner = $this->evaluate($expression->operand, $values);

            return $inner === null ? null : !$inner;
        }

        if ($expression instanceof NullTestExpression) {
            $value = $this->operandValue($expression->operand, $values);

            return $expression->negated ? $value !== null : $value === null;
        }

        if ($expression instanceof ComparisonExpression) {
            $left = $this->operandValue($expression->left, $values);
            $right = $this->operandValue($expression->right, $values);

            return $left === null || $right === null
                ? null
                : self::compare($left, $right, $expression->operator);
        }

        throw new SchemaException('a where clause must be a condition');
    }

    /**
     * @param list<mixed> $values
     */
    private function conjunction(LogicalExpression $expression, array $values): ?bool
    {
        $left = $this->evaluate($expression->left, $values);

        if ($left === false) {
            return false;
        }

        $right = $this->evaluate($expression->right, $values);

        if ($right === false) {
            return false;
        }

        return $left === null || $right === null ? null : true;
    }

    /**
     * @param list<mixed> $values
     */
    private function disjunction(LogicalExpression $expression, array $values): ?bool
    {
        $left = $this->evaluate($expression->left, $values);

        if ($left === true) {
            return true;
        }

        $right = $this->evaluate($expression->right, $values);

        if ($right === true) {
            return true;
        }

        return $left === null || $right === null ? null : false;
    }

    /**
     * @param list<mixed> $values
     */
    private function operandValue(Expression $expression, array $values): mixed
    {
        return $expression instanceof ColumnExpression
            ? $values[$this->ordinals[strtolower($expression->name)]]
            : $this->binder->value($expression);
    }

    // PHP's <=> compares two numeric strings numerically, which would order
    // TEXT values as if they were numbers, so strings go through strcmp.
    private static function compare(mixed $left, mixed $right, string $operator): bool
    {
        $ordering = is_string($left) ? strcmp($left, (string) $right) <=> 0 : $left <=> $right;

        return match ($operator) {
            '=' => $ordering === 0,
            '!=', '<>' => $ordering !== 0,
            '<' => $ordering < 0,
            '<=' => $ordering <= 0,
            '>' => $ordering > 0,
            '>=' => $ordering >= 0,
            default => throw new SchemaException("unknown operator '{$operator}'"),
        };
    }
}

final class Table
{
    /** @var array<int, HashIndex> */
    private array $indexes = [];

    private function __construct(
        public readonly string $name,
        public readonly Schema $schema,
        public readonly Heap $heap,
        public readonly Lock $lock,
        private readonly Paths $paths,
    ) {
    }

    public static function open(Paths $paths, SchemaStore $schemas, string $name, Lock $lock): self
    {
        $schema = $schemas->load($name);
        $table = new self($name, $schema, new Heap($paths->heap($name), $lock, $schema->columnCount()), $lock, $paths);
        $table->openIndexes();

        return $table;
    }

    /**
     * @return array<int, HashIndex>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @param list<mixed> $values
     */
    public function indexRow(int $offset, array $values): void
    {
        $heapSize = $this->heap->size();

        foreach ($this->indexes as $ordinal => $index) {
            if ($values[$ordinal] !== null) {
                $index->insert($this->key($ordinal, $values[$ordinal]), $offset, $heapSize);
            }
        }
    }

    /**
     * @param list<mixed> $oldValues
     * @param list<mixed> $newValues
     */
    public function reindexRow(int $oldOffset, array $oldValues, int $newOffset, array $newValues): void
    {
        $heapSize = $this->heap->size();

        foreach ($this->indexes as $ordinal => $index) {
            $new = $newValues[$ordinal];

            if ($new !== null && $new === $oldValues[$ordinal]
                && $index->repoint($this->key($ordinal, $new), $oldOffset, $newOffset, $heapSize)) {
                continue;
            }

            if ($new !== null) {
                $index->insert($this->key($ordinal, $new), $newOffset, $heapSize);
            }
        }
    }

    /**
     * @return list<int>|null heap offsets worth reading, or null when the column has no index
     */
    public function candidatesFor(int $ordinal, mixed $value): ?array
    {
        $index = $this->indexes[$ordinal] ?? null;

        if ($index === null || $value === null) {
            return null;
        }

        return $index->offsetsFor($this->key($ordinal, $value));
    }

    public function compact(): int
    {
        return $this->lock->exclusive(function (): int {
            $reclaimed = $this->heap->compact();

            // Every offset moved, so no index over this table means anything now.
            foreach (array_keys($this->indexes) as $ordinal) {
                $this->rebuildIndex($ordinal);
            }

            return $reclaimed;
        });
    }

    private function openIndexes(): void
    {
        foreach ($this->schema->indexes as $definition) {
            $ordinal = $definition->columnOrdinal;
            $path = $this->paths->index($this->name, $this->schema->column($ordinal)->name);

            try {
                $this->indexes[$ordinal] = new HashIndex($path, $this->lock);
                $this->catchUp($ordinal);
            } catch (StorageException) {
                // The heap is authoritative, so a damaged index is thrown away
                // rather than repaired.
                unset($this->indexes[$ordinal]);
                @unlink($path);
                $this->indexes[$ordinal] = new HashIndex($path, $this->lock);
                $this->rebuildIndex($ordinal);
            }
        }
    }

    private function catchUp(int $ordinal): void
    {
        $this->lock->exclusive(function () use ($ordinal): void {
            $index = $this->indexes[$ordinal];
            $mark = $index->heapSizeAtLastSync();
            $size = $this->heap->size();

            if ($mark === $size) {
                return;
            }

            if ($mark < Heap::HEADER_BYTES || $mark > $size) {
                $this->rebuildIndex($ordinal);

                return;
            }

            // The mark only advances once the replayed tail is durable, so a
            // crash inside catch-up replays the same tail again rather than
            // leaving a gap.
            foreach ($this->heap->scanFrom($mark) as $record) {
                $values = ValueCodec::decodeRow($this->schema->types(), $record->payload);

                if ($values[$ordinal] !== null) {
                    $index->insert($this->key($ordinal, $values[$ordinal]), $record->offset, $mark);
                }
            }

            $index->setHeapSize($size);
        });
    }

    private function rebuildIndex(int $ordinal): void
    {
        $this->lock->exclusive(function () use ($ordinal): void {
            $size = $this->heap->size();
            $entries = [];

            foreach ($this->heap->scan() as $record) {
                $values = ValueCodec::decodeRow($this->schema->types(), $record->payload);

                if ($values[$ordinal] !== null) {
                    $entries[] = [$this->key($ordinal, $values[$ordinal]), $record->offset];
                }
            }

            $this->indexes[$ordinal]->rebuild($entries, $size);
        });
    }

    private function key(int $ordinal, mixed $value): string
    {
        return ValueCodec::encodeValue($this->schema->column($ordinal)->type, $value);
    }
}

final class Executor
{
    private const PRIMARY_KEY_INDEX = 'pk';

    /** @var array<string, Table> */
    private array $tables = [];

    /** @var array<string, Lock> */
    private array $locks = [];

    public function __construct(
        private readonly Paths $paths,
        private readonly SchemaStore $schemas,
    ) {
    }

    /**
     * @param array<int|string, mixed> $parameters
     */
    public function run(Statement $statement, array $parameters): QueryResult
    {
        $binder = new Binder($parameters);

        $result = match (true) {
            $statement instanceof CreateTableStatement => $this->createTable($statement),
            $statement instanceof CreateIndexStatement => $this->createIndex($statement),
            $statement instanceof InsertStatement => $this->insert($statement, $binder),
            $statement instanceof SelectStatement => $this->select($statement, $binder),
            $statement instanceof UpdateStatement => $this->update($statement, $binder),
            $statement instanceof DeleteStatement => $this->delete($statement, $binder),
            $statement instanceof VacuumStatement => $this->vacuum($statement),
            default => throw new SchemaException('unsupported statement'),
        };

        $binder->assertEveryParameterUsed();

        return $result;
    }

    private function createTable(CreateTableStatement $statement): QueryResult
    {
        return $this->lock($statement->table)->exclusive(function () use ($statement): QueryResult {
            if ($this->schemas->exists($statement->table)) {
                throw new SchemaException("table '{$statement->table}' already exists");
            }

            $schema = $statement->schema;
            $primaryKey = $schema->primaryKeyOrdinal();

            if ($primaryKey !== null) {
                $schema = $schema->withIndex(new IndexDefinition(self::PRIMARY_KEY_INDEX, $primaryKey));
            }

            $this->schemas->save($statement->table, $schema);
            $this->table($statement->table);

            return QueryResult::affected(0);
        });
    }

    private function vacuum(VacuumStatement $statement): QueryResult
    {
        return $this->lock($statement->table)->exclusive(function () use ($statement): QueryResult {
            $reclaimed = $this->table($statement->table)->compact();

            return QueryResult::affected($reclaimed);
        });
    }

    private function createIndex(CreateIndexStatement $statement): QueryResult
    {
        return $this->lock($statement->table)->exclusive(function () use ($statement): QueryResult {
            $schema = $this->schemas->load($statement->table);
            $ordinal = $schema->ordinal($statement->column);

            if ($schema->indexForColumn($ordinal) !== null) {
                throw new SchemaException("column '{$statement->column}' is already indexed");
            }

            $this->schemas->save(
                $statement->table,
                $schema->withIndex(new IndexDefinition($statement->name, $ordinal)),
            );

            unset($this->tables[$statement->table]);
            $this->table($statement->table);

            return QueryResult::affected(0);
        });
    }

    private function insert(InsertStatement $statement, Binder $binder): QueryResult
    {
        $table = $this->table($statement->table);
        $schema = $table->schema;

        $values = array_fill(0, $schema->columnCount(), null);
        $assigned = [];

        foreach ($statement->columns as $position => $name) {
            $ordinal = $schema->ordinal($name);

            if (isset($assigned[$ordinal])) {
                throw new SchemaException("column '{$name}' is assigned twice");
            }

            $assigned[$ordinal] = true;
            $values[$ordinal] = $binder->value($statement->values[$position]);
        }

        foreach ($schema->columns as $ordinal => $column) {
            if ($column->notNull && $values[$ordinal] === null) {
                throw new SchemaException("column '{$column->name}' cannot be null");
            }
        }

        $table->lock->exclusive(function () use ($table, $schema, $values): void {
            $table->indexRow($table->heap->append(ValueCodec::encodeRow($schema->types(), $values)), $values);
        });

        return QueryResult::affected(1);
    }

    private function select(SelectStatement $statement, Binder $binder): QueryResult
    {
        $table = $this->table($statement->table);
        $schema = $table->schema;
        $ordinals = $this->projection($schema, $statement->columns);
        $predicate = $statement->where === null ? null : new Predicate($statement->where, $schema, $binder);
        $sortOrdinal = $statement->orderBy === null ? null : $schema->ordinal($statement->orderBy->column);
        $offset = $this->boundCount($statement->offset, $binder, 'OFFSET') ?? 0;
        $limit = $this->boundCount($statement->limit, $binder, 'LIMIT');
        $types = $schema->types();

        $candidates = $statement->where === null
            ? null
            : Planner::candidates($statement->where, $schema, $table, $binder);

        $matches = $candidates === null
            ? $this->scanFor($table, $types, $predicate)
            : $this->readCandidates($table, $types, $candidates, $predicate);

        if ($sortOrdinal !== null) {
            $descending = $statement->orderBy->descending;
            usort(
                $matches,
                static fn (array $left, array $right): int
                    => self::compareForOrder($left[$sortOrdinal], $right[$sortOrdinal], $descending),
            );
        }

        if ($limit !== null || $offset > 0) {
            $matches = array_slice($matches, $offset, $limit);
        }

        $rows = [];

        foreach ($matches as $values) {
            $row = [];

            foreach ($ordinals as $ordinal) {
                $row[$schema->column($ordinal)->name] = $values[$ordinal];
            }

            $rows[] = $row;
        }

        return QueryResult::fromRows($rows);
    }

    private function update(UpdateStatement $statement, Binder $binder): QueryResult
    {
        $table = $this->table($statement->table);
        $schema = $table->schema;
        $types = $schema->types();

        $assignments = [];

        foreach ($statement->assignments as $assignment) {
            $ordinal = $schema->ordinal($assignment->column);

            if (array_key_exists($ordinal, $assignments)) {
                throw new SchemaException("column '{$assignment->column}' is assigned twice");
            }

            $value = $binder->value($assignment->value);

            if ($value === null && $schema->column($ordinal)->notNull) {
                throw new SchemaException("column '{$schema->column($ordinal)->name}' cannot be null");
            }

            if ($value !== null) {
                ValueCodec::encodeValue($schema->column($ordinal)->type, $value);
            }

            $assignments[$ordinal] = $value;
        }

        $predicate = $statement->where === null ? null : new Predicate($statement->where, $schema, $binder);

        // The scan runs inside the exclusive lock so no other writer can retire a
        // row between it being matched and it being replaced.
        return $table->lock->exclusive(function () use ($table, $types, $assignments, $predicate): QueryResult {
            $targets = $this->collect($table, $types, $predicate);

            foreach ($targets as [$offset, $values]) {
                $replacement = $values;

                foreach ($assignments as $ordinal => $value) {
                    $replacement[$ordinal] = $value;
                }

                $newOffset = $table->heap->append(ValueCodec::encodeRow($types, $replacement), $offset);
                $table->heap->markDead($offset);
                $table->reindexRow($offset, $values, $newOffset, $replacement);
            }

            if ($targets !== []) {
                $table->heap->finishSupersede();
            }

            return QueryResult::affected(count($targets));
        });
    }

    private function delete(DeleteStatement $statement, Binder $binder): QueryResult
    {
        $table = $this->table($statement->table);
        $types = $table->schema->types();
        $predicate = $statement->where === null
            ? null
            : new Predicate($statement->where, $table->schema, $binder);

        return $table->lock->exclusive(function () use ($table, $types, $predicate): QueryResult {
            $targets = $this->collect($table, $types, $predicate);

            foreach ($targets as [$offset, $_]) {
                $table->heap->markDead($offset);
            }

            return QueryResult::affected(count($targets));
        });
    }

    /**
     * @param list<ColumnType> $types
     *
     * @return list<array{0: int, 1: list<mixed>}>
     */
    private function collect(Table $table, array $types, ?Predicate $predicate): array
    {
        $targets = [];

        foreach ($table->heap->scan() as $record) {
            $values = ValueCodec::decodeRow($types, $record->payload);

            if ($predicate === null || $predicate->matches($values)) {
                $targets[] = [$record->offset, $values];
            }
        }

        return $targets;
    }

    /**
     * @param list<ColumnType> $types
     *
     * @return list<list<mixed>>
     */
    private function scanFor(Table $table, array $types, ?Predicate $predicate): array
    {
        $matches = [];

        foreach ($table->heap->scan() as $record) {
            $values = ValueCodec::decodeRow($types, $record->payload);

            if ($predicate === null || $predicate->matches($values)) {
                $matches[] = $values;
            }
        }

        return $matches;
    }

    /**
     * @param list<ColumnType> $types
     * @param list<int>        $candidates
     *
     * @return list<list<mixed>>
     */
    private function readCandidates(Table $table, array $types, array $candidates, ?Predicate $predicate): array
    {
        $matches = [];
        $seen = [];

        foreach ($candidates as $offset) {
            if (isset($seen[$offset])) {
                continue;
            }

            $seen[$offset] = true;

            try {
                $record = $table->heap->read($offset);
            } catch (StorageException) {
                // The index pointed somewhere unreadable. It is a cache, so the
                // answer is to stop trusting it for this query, not to guess.
                return $this->scanFor($table, $types, $predicate);
            }

            if (!$record->live) {
                continue;
            }

            $values = ValueCodec::decodeRow($types, $record->payload);

            if ($predicate === null || $predicate->matches($values)) {
                $matches[$offset] = $values;
            }
        }

        // Heap order, so an indexed query and a scanned one agree on row order.
        ksort($matches);

        return array_values($matches);
    }

    // Nulls sort last in both directions, so the descending flip is applied only
    // to the comparison between two present values.
    private static function compareForOrder(mixed $left, mixed $right, bool $descending): int
    {
        if ($left === null || $right === null) {
            return $left === $right ? 0 : ($left === null ? 1 : -1);
        }

        $ordering = is_string($left) ? strcmp($left, (string) $right) <=> 0 : $left <=> $right;

        return $descending ? -$ordering : $ordering;
    }

    private function boundCount(?Expression $expression, Binder $binder, string $clause): ?int
    {
        if ($expression === null) {
            return null;
        }

        $value = $binder->value($expression);

        if (!is_int($value) || $value < 0) {
            throw new SchemaException("{$clause} needs a non-negative integer");
        }

        return $value;
    }

    /**
     * @param list<string>|null $columns
     *
     * @return list<int>
     */
    private function projection(Schema $schema, ?array $columns): array
    {
        if ($columns === null) {
            return range(0, $schema->columnCount() - 1);
        }

        return array_map(static fn (string $name): int => $schema->ordinal($name), $columns);
    }

    private function table(string $name): Table
    {
        return $this->tables[$name] ??= Table::open($this->paths, $this->schemas, $name, $this->lock($name));
    }

    // One Lock instance per table per process. A second instance would be a
    // second file handle, and flock() would have it block against its own owner.
    private function lock(string $name): Lock
    {
        return $this->locks[$name] ??= new Lock($this->paths->lock($name));
    }
}

// =============================================================================
// DATABASE
// =============================================================================

final class Database
{
    private const DIRECTORY_PERMISSIONS = 0700;

    private readonly Executor $executor;

    public function __construct(string $dataDirectory)
    {
        if (!is_dir($dataDirectory) && !@mkdir($dataDirectory, self::DIRECTORY_PERMISSIONS, true) && !is_dir($dataDirectory)) {
            throw new StorageException('could not create the data directory');
        }

        $paths = new Paths($dataDirectory);
        $this->executor = new Executor($paths, new SchemaStore($paths));
    }

    /**
     * @param array<int|string, mixed> $parameters
     */
    public function execute(string $sql, array $parameters = []): QueryResult
    {
        return $this->executor->run(Parser::parse($sql), $parameters);
    }
}

// =============================================================================
// HTTP BOUNDARY
//
// Guarded so that including this file from the test runner defines the engine
// without serving a request.
// =============================================================================

final class HttpEndpoint
{
    private const MAX_BODY_BYTES = 1048576;
    private const DATA_DIRECTORY_VARIABLE = 'ELEPHPANTDB_DATA';
    private const TOKEN_VARIABLE = 'ELEPHPANTDB_TOKEN';
    private const TOKEN_HEADER = 'HTTP_X_ELEPHPANT_TOKEN';
    private const DEFAULT_DATA_DIRECTORY = 'data';

    public static function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::emit(405, ['ok' => false, 'error' => 'only POST is accepted']);

            return;
        }

        if (!self::authorized()) {
            self::emit(401, ['ok' => false, 'error' => 'a valid X-Elephpant-Token header is required']);

            return;
        }

        try {
            self::emit(200, self::execute((string) file_get_contents('php://input')));
        } catch (ElephpantException $failure) {
            self::emit($failure::HTTP_STATUS, ['ok' => false, 'error' => $failure->getMessage()]);
        } catch (Throwable) {
            // Deliberately opaque: an unexpected failure must not hand a caller
            // a stack trace or a filesystem path.
            self::emit(500, ['ok' => false, 'error' => 'internal error']);
        }
    }

    // A second line of defence only. This endpoint runs arbitrary SQL and has no
    // per-user authorization, so the first line is restricting it at nginx.
    private static function authorized(): bool
    {
        $expected = getenv(self::TOKEN_VARIABLE);

        if (!is_string($expected) || $expected === '') {
            return true;
        }

        $presented = $_SERVER[self::TOKEN_HEADER] ?? '';

        return is_string($presented) && hash_equals($expected, $presented);
    }

    /**
     * @return array<string, mixed>
     */
    private static function execute(string $body): array
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new SchemaException('request body is larger than ' . self::MAX_BODY_BYTES . ' bytes');
        }

        $request = json_decode($body, true);

        if (!is_array($request)) {
            throw new SchemaException('request body must be a JSON object');
        }

        $sql = $request['sql'] ?? null;

        if (!is_string($sql)) {
            throw new SchemaException('request must carry a "sql" string');
        }

        $parameters = $request['params'] ?? [];

        if (!is_array($parameters)) {
            throw new SchemaException('"params" must be an array or an object');
        }

        $result = self::database()->execute($sql, $parameters);

        return $result->rows === null
            ? ['ok' => true, 'rowsAffected' => $result->rowsAffected]
            : ['ok' => true, 'rows' => $result->rows, 'count' => count($result->rows)];
    }

    private static function database(): Database
    {
        $directory = getenv(self::DATA_DIRECTORY_VARIABLE);

        if (!is_string($directory) || $directory === '') {
            $directory = __DIR__ . DIRECTORY_SEPARATOR . self::DEFAULT_DATA_DIRECTORY;
        }

        return new Database($directory);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function emit(int $status, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            $status = 500;
            $encoded = '{"ok":false,"error":"result could not be encoded as JSON"}';
        }

        http_response_code($status);
        header('Content-Type: application/json');
        echo $encoded;
    }
}

if (Runtime::isHttpRequest()) {
    HttpEndpoint::handle();
}
