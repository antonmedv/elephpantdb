<?php

declare(strict_types=1);

return [
    'accepts ordinary names' => function (): void {
        assertSame('users', Identifier::validate('users'));
        assertSame('_private', Identifier::validate('_private'));
        assertSame('a1', Identifier::validate('a1'));
        assertSame('User_Table_2', Identifier::validate('User_Table_2'));
    },

    'accepts a name at the length limit' => function (): void {
        $atLimit = 'a' . str_repeat('b', 63);
        assertSame(64, strlen($atLimit));
        assertSame($atLimit, Identifier::validate($atLimit));
    },

    'rejects a name past the length limit' => function (): void {
        assertThrows(SchemaException::class, fn () => Identifier::validate('a' . str_repeat('b', 64)));
    },

    'rejects the empty string' => function (): void {
        assertThrows(SchemaException::class, fn () => Identifier::validate(''));
    },

    'rejects a leading digit' => function (): void {
        assertThrows(SchemaException::class, fn () => Identifier::validate('1users'));
    },

    'rejects path separators and traversal' => function (): void {
        foreach (['../etc/passwd', '..', 'users/heap', 'users\\heap', '/absolute'] as $hostile) {
            assertThrows(SchemaException::class, fn () => Identifier::validate($hostile), $hostile);
        }
    },

    'rejects characters that would collide with file suffixes' => function (): void {
        foreach (['users.heap', 'users.schema', 'users.idx.id'] as $hostile) {
            assertThrows(SchemaException::class, fn () => Identifier::validate($hostile), $hostile);
        }
    },

    'rejects whitespace, null bytes and non-ascii' => function (): void {
        foreach (['us ers', "a\0b", "users\n", 'ünïcode', 'users-1'] as $hostile) {
            assertThrows(SchemaException::class, fn () => Identifier::validate($hostile), 'hostile input');
        }
    },

    'reports the offending name without leaking control characters' => function (): void {
        $failure = assertThrows(SchemaException::class, fn () => Identifier::validate("bad\0name"));
        assertStringContains("bad?name", $failure->getMessage());
        assertFalse(str_contains($failure->getMessage(), "\0"));
    },

    'truncates an overlong name in the error message' => function (): void {
        $failure = assertThrows(SchemaException::class, fn () => Identifier::validate(str_repeat('x', 200)));
        assertStringContains('...', $failure->getMessage());
        assertTrue(strlen($failure->getMessage()) < 100);
    },

    'builds every table path from the data directory' => function (): void {
        $paths = new Paths('/var/data');

        assertSame('/var/data/users.schema', $paths->schema('users'));
        assertSame('/var/data/users.heap', $paths->heap('users'));
        assertSame('/var/data/users.lock', $paths->lock('users'));
        assertSame('/var/data/users.idx.id', $paths->index('users', 'id'));
        assertSame('/var/data/users.heap.tmp', $paths->temporary($paths->heap('users')));
    },

    'validates the table name in every path builder' => function (): void {
        $paths = new Paths('/var/data');

        foreach (['schema', 'heap', 'lock'] as $method) {
            assertThrows(SchemaException::class, fn () => $paths->{$method}('../escape'), $method);
        }

        assertThrows(SchemaException::class, fn () => $paths->index('../escape', 'id'));
    },

    'validates the column name in the index path builder' => function (): void {
        $paths = new Paths('/var/data');

        assertThrows(SchemaException::class, fn () => $paths->index('users', '../escape'));
        assertThrows(SchemaException::class, fn () => $paths->index('users', 'id.heap'));
    },
];
