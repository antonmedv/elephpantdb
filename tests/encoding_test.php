<?php

declare(strict_types=1);

$roundTrip = static function (array $types, array $values): array {
    return ValueCodec::decodeRow($types, ValueCodec::encodeRow($types, $values));
};

return [
    'round-trips one column of each type' => function () use ($roundTrip): void {
        $types = [ColumnType::Integer, ColumnType::Float, ColumnType::Text, ColumnType::Boolean];
        $values = [42, 1.5, 'ana', true];

        assertSame($values, $roundTrip($types, $values));
    },

    'round-trips integer extremes' => function () use ($roundTrip): void {
        $types = [ColumnType::Integer];

        foreach ([0, 1, -1, 2, -2, 127, -128, PHP_INT_MAX, PHP_INT_MIN] as $value) {
            assertSame([$value], $roundTrip($types, [$value]), "integer {$value}");
        }
    },

    'encodes small integers without sign extension blowing up the payload' => function (): void {
        $encoded = ValueCodec::encodeRow([ColumnType::Integer], [-1]);

        assertSame(1 + 8, strlen($encoded));
    },

    'round-trips float extremes' => function () use ($roundTrip): void {
        $types = [ColumnType::Float];

        foreach ([0.0, 1.5, -1.5, PHP_FLOAT_MIN, PHP_FLOAT_MAX, -PHP_FLOAT_MAX, INF, -INF] as $value) {
            assertSame([$value], $roundTrip($types, [$value]), 'float');
        }
    },

    'preserves negative zero' => function () use ($roundTrip): void {
        [$decoded] = $roundTrip([ColumnType::Float], [-0.0]);

        assertTrue($decoded === 0.0);
        assertTrue(fdiv(1.0, $decoded) === -INF, 'sign bit of negative zero must survive');
    },

    'round-trips NAN' => function () use ($roundTrip): void {
        [$decoded] = $roundTrip([ColumnType::Float], [NAN]);

        assertTrue(is_float($decoded) && is_nan($decoded));
    },

    'widens an integer written to a float column' => function () use ($roundTrip): void {
        [$decoded] = $roundTrip([ColumnType::Float], [7]);

        assertSame(7.0, $decoded);
    },

    'round-trips text including empty, multibyte and binary' => function () use ($roundTrip): void {
        $types = [ColumnType::Text];

        foreach (['', 'ana', 'ünïcode ☃', "with\0null\0bytes", str_repeat('x', 70000)] as $value) {
            assertSame([$value], $roundTrip($types, [$value]), 'text');
        }
    },

    'round-trips booleans' => function () use ($roundTrip): void {
        assertSame([true, false], $roundTrip([ColumnType::Boolean, ColumnType::Boolean], [true, false]));
    },

    'round-trips a row that is entirely null' => function () use ($roundTrip): void {
        $types = [ColumnType::Integer, ColumnType::Float, ColumnType::Text, ColumnType::Boolean];

        assertSame([null, null, null, null], $roundTrip($types, [null, null, null, null]));
    },

    'round-trips nulls interleaved with values' => function () use ($roundTrip): void {
        $types = [ColumnType::Integer, ColumnType::Text, ColumnType::Integer, ColumnType::Boolean];
        $values = [null, 'ana', 7, null];

        assertSame($values, $roundTrip($types, $values));
    },

    'sizes the null bitmap at a byte boundary' => function () use ($roundTrip): void {
        foreach ([1, 7, 8, 9, 16, 17] as $count) {
            $types = array_fill(0, $count, ColumnType::Boolean);
            $values = array_fill(0, $count, null);

            assertSame(intdiv($count + 7, 8), strlen(ValueCodec::encodeRow($types, $values)), "{$count} columns");
            assertSame($values, $roundTrip($types, $values), "{$count} columns");
        }
    },

    'distinguishes null from every falsy value' => function () use ($roundTrip): void {
        $types = [ColumnType::Integer, ColumnType::Float, ColumnType::Text, ColumnType::Boolean];
        $values = [0, 0.0, '', false];

        assertSame($values, $roundTrip($types, $values));
    },

    'round-trips a single value the way an index key is stored' => function (): void {
        foreach ([[ColumnType::Integer, -9], [ColumnType::Text, 'ana'], [ColumnType::Boolean, true], [ColumnType::Float, 2.5]] as [$type, $value]) {
            $encoded = ValueCodec::encodeValue($type, $value);

            assertSame($value, ValueCodec::decodeValue($type, $encoded, 0)[0], 'index key');
        }
    },

    'rejects a value of the wrong type' => function (): void {
        $mismatches = [
            [ColumnType::Integer, 'seven'],
            [ColumnType::Integer, 1.5],
            [ColumnType::Integer, true],
            [ColumnType::Text, 7],
            [ColumnType::Boolean, 1],
            [ColumnType::Float, 'x'],
        ];

        foreach ($mismatches as [$type, $value]) {
            assertThrows(
                SchemaException::class,
                fn () => ValueCodec::encodeRow([$type], [$value]),
                $type->name . ' <- ' . describeValue($value),
            );
        }
    },

    'rejects the wrong number of values' => function (): void {
        $types = [ColumnType::Integer, ColumnType::Text];

        assertThrows(SchemaException::class, fn () => ValueCodec::encodeRow($types, [1]));
        assertThrows(SchemaException::class, fn () => ValueCodec::encodeRow($types, [1, 'a', true]));
    },

    'rejects a truncated payload' => function (): void {
        $types = [ColumnType::Integer, ColumnType::Text];
        $encoded = ValueCodec::encodeRow($types, [1, 'ana']);

        foreach ([1, 4, strlen($encoded) - 1] as $cut) {
            assertThrows(
                StorageException::class,
                fn () => ValueCodec::decodeRow($types, substr($encoded, 0, $cut)),
                "truncated to {$cut} bytes",
            );
        }
    },

    'rejects trailing bytes after the last column' => function (): void {
        $types = [ColumnType::Integer];
        $encoded = ValueCodec::encodeRow($types, [1]);

        assertThrows(StorageException::class, fn () => ValueCodec::decodeRow($types, $encoded . 'x'));
    },

    'rejects a text length that overruns the payload' => function (): void {
        $types = [ColumnType::Text];
        $payload = "\x00" . pack('N', 1000) . 'short';

        assertThrows(StorageException::class, fn () => ValueCodec::decodeRow($types, $payload));
    },
];
