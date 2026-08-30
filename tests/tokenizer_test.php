<?php

declare(strict_types=1);

$shape = static fn (string $sql): array => array_map(
    static fn (Token $token): string => $token->type->name . ':' . $token->text,
    array_slice(Tokenizer::tokenize($sql), 0, -1),
);

$values = static fn (string $sql): array => array_map(
    static fn (Token $token): mixed => $token->value,
    array_slice(Tokenizer::tokenize($sql), 0, -1),
);

return [
    'appends an end token' => function (): void {
        $tokens = Tokenizer::tokenize('');

        assertSame(1, count($tokens));
        assertSame(TokenType::End, $tokens[0]->type);
        assertSame(0, $tokens[0]->position);
    },

    'uppercases keywords whatever case they arrive in' => function () use ($shape): void {
        assertSame(
            ['Keyword:SELECT', 'Punctuation:*', 'Keyword:FROM', 'Identifier:users'],
            $shape('select * From users'),
        );
    },

    'preserves identifier case' => function () use ($shape): void {
        assertSame(['Identifier:userName'], $shape('userName'));
    },

    'treats a non-keyword word as an identifier' => function () use ($shape): void {
        assertSame(['Identifier:selected'], $shape('selected'));
    },

    'reads integers and floats as distinct types' => function () use ($shape, $values): void {
        assertSame(['Integer:7', 'Float:1.5', 'Float:2.0e3', 'Float:5E-2'], $shape('7 1.5 2.0e3 5E-2'));
        assertSame([7, 1.5, 2000.0, 0.05], $values('7 1.5 2.0e3 5E-2'));
    },

    'reads integer boundaries without losing precision' => function () use ($values): void {
        assertSame([PHP_INT_MAX], $values((string) PHP_INT_MAX));
    },

    'rejects an integer literal too large for a 64-bit column' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Tokenizer::tokenize('99999999999999999999'));
        assertStringContains('too large', $failure->getMessage());
    },

    'reads single-quoted strings' => function () use ($shape, $values): void {
        assertSame(['String:ana'], $shape("'ana'"));
        assertSame(['ana'], $values("'ana'"));
        assertSame([''], $values("''"));
    },

    'unescapes a doubled quote inside a string' => function () use ($values): void {
        assertSame(["it's"], $values("'it''s'"));
        assertSame(["''"], $values("''''''"));
    },

    'keeps whitespace and keywords inside strings intact' => function () use ($values): void {
        assertSame(['  SELECT from  '], $values("'  SELECT from  '"));
    },

    'reads every comparison operator' => function () use ($shape): void {
        assertSame(
            ['Operator:=', 'Operator:!=', 'Operator:<>', 'Operator:<', 'Operator:<=', 'Operator:>', 'Operator:>='],
            $shape('= != <> < <= > >='),
        );
    },

    'splits adjacent tokens without whitespace' => function () use ($shape): void {
        assertSame(
            ['Identifier:a', 'Operator:>=', 'PositionalPlaceholder:?'],
            $shape('a>=?'),
        );

        assertSame(
            ['Identifier:a', 'Operator:<', 'Operator:>', 'Identifier:b'],
            $shape('a< >b'),
        );
    },

    'reads punctuation' => function () use ($shape): void {
        assertSame(
            ['Punctuation:(', 'Punctuation:)', 'Punctuation:,', 'Punctuation:*', 'Punctuation:;'],
            $shape('(),*;'),
        );
    },

    'reads the minus sign as an operator' => function () use ($shape): void {
        assertSame(['Operator:-', 'Integer:5'], $shape('-5'));
    },

    'reads positional and named placeholders' => function () use ($shape, $values): void {
        assertSame(['PositionalPlaceholder:?'], $shape('?'));
        assertSame(['NamedPlaceholder::name'], $shape(':name'));
        assertSame(['name'], $values(':name'));
    },

    'rejects a colon that names nothing' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Tokenizer::tokenize('WHERE id = : '));
        assertStringContains('position 11', $failure->getMessage());
    },

    'rejects a placeholder name that is not an identifier' => function (): void {
        assertThrows(ParseException::class, fn () => Tokenizer::tokenize(':1st'));
        assertThrows(ParseException::class, fn () => Tokenizer::tokenize(':../escape'));
    },

    'skips whitespace and newlines' => function () use ($shape): void {
        assertSame(['Keyword:SELECT', 'Punctuation:*'], $shape("SELECT\n\t  *\r\n"));
    },

    'reports the byte position of every token' => function (): void {
        $tokens = Tokenizer::tokenize('SELECT * FROM users');

        assertSame([0, 7, 9, 14, 19], array_map(static fn (Token $t): int => $t->position, $tokens));
    },

    'rejects an unterminated string with its opening position' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Tokenizer::tokenize("SELECT 'ana"));

        assertStringContains('unterminated', $failure->getMessage());
        assertSame(7, $failure->position());
    },

    'rejects a trailing quote that only looks escaped' => function (): void {
        assertThrows(ParseException::class, fn () => Tokenizer::tokenize("'ana''"));
    },

    'rejects an unknown character with its position' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Tokenizer::tokenize('SELECT # FROM t'));

        assertSame(7, $failure->position());
        assertStringContains('#', $failure->getMessage());
    },

    'rejects a bare exclamation mark' => function (): void {
        assertThrows(ParseException::class, fn () => Tokenizer::tokenize('a ! b'));
    },

    'does not leak control characters into the error message' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Tokenizer::tokenize("SELECT \x01"));

        assertFalse(str_contains($failure->getMessage(), "\x01"));
    },
];
