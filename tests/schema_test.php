<?php

declare(strict_types=1);

$sample = static function (): Schema {
    return new Schema([
        new Column('id', ColumnType::Integer, primaryKey: true, notNull: true),
        new Column('name', ColumnType::Text, notNull: true),
        new Column('score', ColumnType::Float),
        new Column('active', ColumnType::Boolean),
    ]);
};

return [
    'round-trips columns through the codec' => function () use ($sample): void {
        $schema = $sample();
        $decoded = SchemaCodec::decode(SchemaCodec::encode($schema));

        assertSame(4, $decoded->columnCount());
        assertSame(['id', 'name', 'score', 'active'], array_map(fn (Column $c) => $c->name, $decoded->columns));
        assertSame($schema->types(), $decoded->types());
        assertTrue($decoded->column(0)->primaryKey);
        assertTrue($decoded->column(1)->notNull);
        assertFalse($decoded->column(2)->notNull);
        assertFalse($decoded->column(3)->primaryKey);
    },

    'round-trips index descriptors' => function () use ($sample): void {
        $schema = $sample()
            ->withIndex(new IndexDefinition('idx_users_name', 1))
            ->withIndex(new IndexDefinition('idx_users_score', 2));

        $decoded = SchemaCodec::decode(SchemaCodec::encode($schema));

        assertSame(2, count($decoded->indexes));
        assertSame('idx_users_name', $decoded->indexes[0]->name);
        assertSame(1, $decoded->indexes[0]->columnOrdinal);
        assertSame(2, $decoded->indexes[1]->columnOrdinal);
    },

    'round-trips a schema with no indexes' => function () use ($sample): void {
        assertSame([], SchemaCodec::decode(SchemaCodec::encode($sample()))->indexes);
    },

    'resolves column ordinals by name' => function () use ($sample): void {
        $schema = $sample();

        assertSame(0, $schema->ordinal('id'));
        assertSame(3, $schema->ordinal('active'));
        assertThrows(SchemaException::class, fn () => $schema->ordinal('missing'));
    },

    'reports the primary key ordinal' => function () use ($sample): void {
        assertSame(0, $sample()->primaryKeyOrdinal());
        assertSame(null, (new Schema([new Column('x', ColumnType::Integer)]))->primaryKeyOrdinal());
    },

    'finds an index by column ordinal' => function () use ($sample): void {
        $schema = $sample()->withIndex(new IndexDefinition('idx_name', 1));

        assertSame('idx_name', $schema->indexForColumn(1)?->name);
        assertSame(null, $schema->indexForColumn(2));
        assertTrue($schema->hasIndex('idx_name'));
        assertFalse($schema->hasIndex('idx_other'));
    },

    'rejects a schema with no columns' => function (): void {
        assertThrows(SchemaException::class, fn () => new Schema([]));
    },

    'rejects duplicate column names' => function (): void {
        assertThrows(SchemaException::class, fn () => new Schema([
            new Column('id', ColumnType::Integer),
            new Column('id', ColumnType::Text),
        ]));
    },

    'rejects two primary keys' => function (): void {
        assertThrows(SchemaException::class, fn () => new Schema([
            new Column('a', ColumnType::Integer, primaryKey: true),
            new Column('b', ColumnType::Integer, primaryKey: true),
        ]));
    },

    'validates column names' => function (): void {
        assertThrows(SchemaException::class, fn () => new Column('../escape', ColumnType::Integer));
    },

    'validates index names and ordinals' => function () use ($sample): void {
        assertThrows(SchemaException::class, fn () => new IndexDefinition('../escape', 0));
        assertThrows(SchemaException::class, fn () => $sample()->withIndex(new IndexDefinition('idx_bad', 9)));
        assertThrows(SchemaException::class, fn () => $sample()
            ->withIndex(new IndexDefinition('idx_dupe', 1))
            ->withIndex(new IndexDefinition('idx_dupe', 2)));
    },

    'rejects a bad magic number' => function () use ($sample): void {
        $encoded = SchemaCodec::encode($sample());

        assertThrows(StorageException::class, fn () => SchemaCodec::decode('XXXXXXXX' . substr($encoded, 8)));
    },

    'rejects an unknown version' => function () use ($sample): void {
        $encoded = SchemaCodec::encode($sample());
        $tampered = substr($encoded, 0, 8) . pack('n', 9999) . substr($encoded, 10);

        $failure = assertThrows(StorageException::class, fn () => SchemaCodec::decode($tampered));
        assertStringContains('version', $failure->getMessage());
    },

    'rejects a truncated schema' => function () use ($sample): void {
        $encoded = SchemaCodec::encode($sample());

        foreach ([0, 8, 20, 30, strlen($encoded) - 1] as $cut) {
            assertThrows(StorageException::class, fn () => SchemaCodec::decode(substr($encoded, 0, $cut)), "cut {$cut}");
        }
    },

    'rejects trailing bytes' => function () use ($sample): void {
        assertThrows(StorageException::class, fn () => SchemaCodec::decode(SchemaCodec::encode($sample()) . 'x'));
    },

    'saves and loads through the store' => function () use ($sample): void {
        $store = new SchemaStore(new Paths(temporaryDirectory()));

        assertFalse($store->exists('users'));
        $store->save('users', $sample());
        assertTrue($store->exists('users'));

        $loaded = $store->load('users');
        assertSame(['id', 'name', 'score', 'active'], array_map(fn (Column $c) => $c->name, $loaded->columns));
    },

    'leaves no temporary file behind' => function () use ($sample): void {
        $directory = temporaryDirectory();
        $store = new SchemaStore(new Paths($directory));
        $store->save('users', $sample());

        assertSame(['users.schema'], array_map('basename', glob($directory . '/*') ?: []));
    },

    'replaces an existing schema in place' => function () use ($sample): void {
        $store = new SchemaStore(new Paths(temporaryDirectory()));
        $store->save('users', $sample());
        $store->save('users', $sample()->withIndex(new IndexDefinition('idx_name', 1)));

        assertSame(1, count($store->load('users')->indexes));
    },

    'reports a missing table' => function (): void {
        $store = new SchemaStore(new Paths(temporaryDirectory()));

        assertThrows(SchemaException::class, fn () => $store->load('users'));
    },

    'validates the table name before touching the filesystem' => function () use ($sample): void {
        $store = new SchemaStore(new Paths(temporaryDirectory()));

        assertThrows(SchemaException::class, fn () => $store->load('../escape'));
        assertThrows(SchemaException::class, fn () => $store->exists('../escape'));
        assertThrows(SchemaException::class, fn () => $store->save('../escape', $sample()));
    },
];
