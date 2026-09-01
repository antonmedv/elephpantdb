<?php

declare(strict_types=1);

$dump = static function (Expression $expression) use (&$dump): string {
    return match (true) {
        $expression instanceof LogicalExpression => $expression->operator . '(' . $dump($expression->left) . ',' . $dump($expression->right) . ')',
        $expression instanceof NotExpression => 'NOT(' . $dump($expression->operand) . ')',
        $expression instanceof ComparisonExpression => $expression->operator . '(' . $dump($expression->left) . ',' . $dump($expression->right) . ')',
        $expression instanceof NullTestExpression => ($expression->negated ? 'NOTNULL(' : 'ISNULL(') . $dump($expression->operand) . ')',
        $expression instanceof ColumnExpression => 'col:' . $expression->name,
        $expression instanceof PlaceholderExpression => '?' . $expression->key,
        $expression instanceof LiteralExpression => 'lit:' . var_export($expression->value, true),
        default => 'unknown',
    };
};

$where = static fn (string $sql): Expression => Parser::parse($sql)->where;

return [
    'parses a create table statement' => function (): void {
        $statement = Parser::parse('CREATE TABLE users (id INT PRIMARY KEY, name TEXT NOT NULL, score FLOAT, active BOOL)');

        assertTrue($statement instanceof CreateTableStatement);
        assertSame('users', $statement->table);

        $schema = $statement->schema;
        assertSame(['id', 'name', 'score', 'active'], array_map(fn (Column $c) => $c->name, $schema->columns));
        assertSame(
            [ColumnType::Integer, ColumnType::Text, ColumnType::Float, ColumnType::Boolean],
            $schema->types(),
        );
        assertTrue($schema->column(0)->primaryKey);
        assertTrue($schema->column(0)->notNull);
        assertTrue($schema->column(1)->notNull);
        assertFalse($schema->column(2)->notNull);
    },

    'treats a primary key as not null' => function (): void {
        $statement = Parser::parse('CREATE TABLE t (id INT PRIMARY KEY)');

        assertTrue($statement->schema->column(0)->notNull);
    },

    'rejects an unknown column type' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Parser::parse('CREATE TABLE t (id BIGINT)'));
        assertStringContains('BIGINT', $failure->getMessage());
    },

    'rejects a create table with no columns' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('CREATE TABLE t ()'));
    },

    'rejects duplicate columns and two primary keys' => function (): void {
        assertThrows(SchemaException::class, fn () => Parser::parse('CREATE TABLE t (id INT, id TEXT)'));
        assertThrows(SchemaException::class, fn () => Parser::parse('CREATE TABLE t (a INT PRIMARY KEY, b INT PRIMARY KEY)'));
    },

    'rejects a table name that is not a valid identifier' => function (): void {
        assertThrows(SchemaException::class, fn () => Parser::parse('CREATE TABLE ' . str_repeat('t', 65) . ' (id INT)'));
    },

    'parses an insert with placeholders' => function (): void {
        $statement = Parser::parse('INSERT INTO users (id, name) VALUES (?, ?)');

        assertTrue($statement instanceof InsertStatement);
        assertSame('users', $statement->table);
        assertSame(['id', 'name'], $statement->columns);
        assertSame(2, count($statement->values));
        assertTrue($statement->values[0] instanceof PlaceholderExpression);
        assertSame(0, $statement->values[0]->key);
        assertSame(1, $statement->values[1]->key);
    },

    'numbers positional placeholders in source order' => function (): void {
        $statement = Parser::parse('INSERT INTO t (a, b, c) VALUES (?, ?, ?)');

        assertSame([0, 1, 2], array_map(fn (PlaceholderExpression $p) => $p->key, $statement->values));
    },

    'keeps named placeholder names' => function (): void {
        $statement = Parser::parse('INSERT INTO t (a, b) VALUES (:first, :second)');

        assertSame(['first', 'second'], array_map(fn (PlaceholderExpression $p) => $p->key, $statement->values));
    },

    'rejects a statement that mixes placeholder styles' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Parser::parse('INSERT INTO t (a, b) VALUES (?, :name)'));
        assertStringContains('placeholder', $failure->getMessage());

        assertThrows(ParseException::class, fn () => Parser::parse('INSERT INTO t (a, b) VALUES (:name, ?)'));
    },

    'parses literals of every type' => function (): void {
        $statement = Parser::parse("INSERT INTO t (a, b, c, d, e, f) VALUES (7, 1.5, 'ana', TRUE, FALSE, NULL)");

        assertSame(
            [7, 1.5, 'ana', true, false, null],
            array_map(fn (LiteralExpression $l) => $l->value, $statement->values),
        );
    },

    'parses negative numbers' => function (): void {
        $statement = Parser::parse('INSERT INTO t (a, b) VALUES (-7, -1.5)');

        assertSame([-7, -1.5], array_map(fn (LiteralExpression $l) => $l->value, $statement->values));
    },

    'rejects an insert whose value count does not match its columns' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Parser::parse('INSERT INTO t (a, b) VALUES (1)'));
        assertStringContains('2 columns', $failure->getMessage());
    },

    'rejects an insert with no column list' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('INSERT INTO t VALUES (1)'));
    },

    'rejects an insert with an empty column list' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('INSERT INTO t () VALUES ()'));
    },

    'parses select star' => function (): void {
        $statement = Parser::parse('SELECT * FROM users');

        assertTrue($statement instanceof SelectStatement);
        assertSame('users', $statement->table);
        assertSame(null, $statement->columns);
    },

    'parses a select column list' => function (): void {
        $statement = Parser::parse('SELECT id, name FROM users');

        assertSame(['id', 'name'], $statement->columns);
    },

    'rejects a select with no columns' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT FROM users'));
    },

    'rejects a select with no table' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT *'));
    },

    'accepts one trailing semicolon' => function (): void {
        assertTrue(Parser::parse('SELECT * FROM users;') instanceof SelectStatement);
    },

    'rejects anything after the statement' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM users SELECT * FROM users'));
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM users; SELECT * FROM users;'));
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM users users'));
    },

    'rejects an empty statement' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse(''));
        assertThrows(ParseException::class, fn () => Parser::parse('   '));
    },

    'rejects an unknown leading keyword' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Parser::parse('DROP TABLE users'));
        assertStringContains('DROP', $failure->getMessage());
    },

    'reports the position of the offending token' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FORM users'));

        assertSame(9, $failure->position());
        assertStringContains('FORM', $failure->getMessage());
    },

    'parses a where clause' => function () use ($dump, $where): void {
        assertSame('=(col:id,?0)', $dump($where('SELECT * FROM t WHERE id = ?')));
    },

    'leaves where null when the clause is absent' => function (): void {
        assertSame(null, Parser::parse('SELECT * FROM t')->where);
    },

    'parses every comparison operator' => function () use ($dump, $where): void {
        foreach (['=', '!=', '<>', '<', '<=', '>', '>='] as $operator) {
            assertSame("{$operator}(col:a,?0)", $dump($where("SELECT * FROM t WHERE a {$operator} ?")));
        }
    },

    'binds AND tighter than OR' => function () use ($dump, $where): void {
        assertSame(
            'OR(=(col:a,?0),AND(=(col:b,?1),=(col:c,?2)))',
            $dump($where('SELECT * FROM t WHERE a = ? OR b = ? AND c = ?')),
        );

        assertSame(
            'OR(AND(=(col:a,?0),=(col:b,?1)),=(col:c,?2))',
            $dump($where('SELECT * FROM t WHERE a = ? AND b = ? OR c = ?')),
        );
    },

    'chains repeated operators to the left' => function () use ($dump, $where): void {
        assertSame(
            'AND(AND(=(col:a,?0),=(col:b,?1)),=(col:c,?2))',
            $dump($where('SELECT * FROM t WHERE a = ? AND b = ? AND c = ?')),
        );
    },

    'lets parentheses override precedence' => function () use ($dump, $where): void {
        assertSame(
            'AND(OR(=(col:a,?0),=(col:b,?1)),=(col:c,?2))',
            $dump($where('SELECT * FROM t WHERE (a = ? OR b = ?) AND c = ?')),
        );
    },

    'binds NOT tighter than AND but looser than a comparison' => function () use ($dump, $where): void {
        assertSame(
            'AND(NOT(=(col:a,?0)),=(col:b,?1))',
            $dump($where('SELECT * FROM t WHERE NOT a = ? AND b = ?')),
        );

        assertSame(
            'NOT(AND(=(col:a,?0),=(col:b,?1)))',
            $dump($where('SELECT * FROM t WHERE NOT (a = ? AND b = ?)')),
        );
    },

    'parses repeated NOT' => function () use ($dump, $where): void {
        assertSame('NOT(NOT(=(col:a,?0)))', $dump($where('SELECT * FROM t WHERE NOT NOT a = ?')));
    },

    'parses null tests' => function () use ($dump, $where): void {
        assertSame('ISNULL(col:a)', $dump($where('SELECT * FROM t WHERE a IS NULL')));
        assertSame('NOTNULL(col:a)', $dump($where('SELECT * FROM t WHERE a IS NOT NULL')));
    },

    'parses null tests inside a boolean chain' => function () use ($dump, $where): void {
        assertSame(
            'AND(ISNULL(col:a),=(col:b,?0))',
            $dump($where('SELECT * FROM t WHERE a IS NULL AND b = ?')),
        );
    },

    'accepts a literal or placeholder on either side' => function () use ($dump, $where): void {
        assertSame('=(?0,col:a)', $dump($where('SELECT * FROM t WHERE ? = a')));
        assertSame('<(lit:7,col:a)', $dump($where('SELECT * FROM t WHERE 7 < a')));
        assertSame('=(col:a,col:b)', $dump($where('SELECT * FROM t WHERE a = b')));
    },

    'parses literals of every type in a where clause' => function () use ($dump, $where): void {
        assertSame("=(col:a,lit:'ana')", $dump($where("SELECT * FROM t WHERE a = 'ana'")));
        assertSame('=(col:a,lit:true)', $dump($where('SELECT * FROM t WHERE a = TRUE')));
        assertSame('=(col:a,lit:false)', $dump($where('SELECT * FROM t WHERE a = FALSE')));
        assertSame('>(col:a,lit:-5)', $dump($where('SELECT * FROM t WHERE a > -5')));
        assertSame('>(col:a,lit:-1.5)', $dump($where('SELECT * FROM t WHERE a > -1.5')));
    },

    'numbers placeholders across the whole statement' => function () use ($dump, $where): void {
        assertSame(
            'AND(=(col:a,?0),=(col:b,?1))',
            $dump($where('SELECT * FROM t WHERE a = ? AND b = ?')),
        );
    },

    'rejects mixed placeholder styles in a where clause' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM t WHERE a = ? AND b = :x'));
    },

    'rejects an empty where clause' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM t WHERE'));
    },

    'rejects unbalanced parentheses with a position' => function (): void {
        $failure = assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM t WHERE (a = ?'));
        assertStringContains("')'", $failure->getMessage());

        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM t WHERE a = ?)'));
    },

    'rejects a dangling operator' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM t WHERE a ='));
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM t WHERE a = ? AND'));
    },

    'rejects IS without NULL' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM t WHERE a IS 7'));
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM t WHERE a IS NOT 7'));
    },

    'rejects a comparison chained without a connective' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('SELECT * FROM t WHERE a = ? b = ?'));
    },

    'parses an update' => function () use ($dump): void {
        $statement = Parser::parse('UPDATE users SET name = ?, score = ? WHERE id = ?');

        assertTrue($statement instanceof UpdateStatement);
        assertSame('users', $statement->table);
        assertSame(['name', 'score'], array_map(fn (Assignment $a) => $a->column, $statement->assignments));
        assertSame([0, 1], array_map(fn (Assignment $a) => $a->value->key, $statement->assignments));
        assertSame('=(col:id,?2)', $dump($statement->where));
    },

    'parses an update with no where clause' => function (): void {
        $statement = Parser::parse('UPDATE t SET a = 1');

        assertSame(null, $statement->where);
        assertSame(1, count($statement->assignments));
    },

    'parses assignments of every literal type' => function (): void {
        $statement = Parser::parse("UPDATE t SET a = 7, b = -1.5, c = 'ana', d = TRUE, e = NULL");

        assertSame([7, -1.5, 'ana', true, null], array_map(fn (Assignment $a) => $a->value->value, $statement->assignments));
    },

    'rejects an update with no assignments' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('UPDATE t SET'));
        assertThrows(ParseException::class, fn () => Parser::parse('UPDATE t WHERE a = ?'));
    },

    'rejects an assignment without a value' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('UPDATE t SET a ='));
        assertThrows(ParseException::class, fn () => Parser::parse('UPDATE t SET a'));
    },

    'parses a delete' => function () use ($dump): void {
        $statement = Parser::parse('DELETE FROM users WHERE id = ?');

        assertTrue($statement instanceof DeleteStatement);
        assertSame('users', $statement->table);
        assertSame('=(col:id,?0)', $dump($statement->where));
    },

    'parses a delete with no where clause' => function (): void {
        assertSame(null, Parser::parse('DELETE FROM t')->where);
    },

    'rejects a delete without FROM' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('DELETE t'));
    },

    'rejects order by or limit on a mutation' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('DELETE FROM t LIMIT 1'));
        assertThrows(ParseException::class, fn () => Parser::parse('UPDATE t SET a = 1 ORDER BY a'));
    },

    'parses a create index statement' => function (): void {
        $statement = Parser::parse('CREATE INDEX idx_users_name ON users (name)');

        assertTrue($statement instanceof CreateIndexStatement);
        assertSame('idx_users_name', $statement->name);
        assertSame('users', $statement->table);
        assertSame('name', $statement->column);
    },

    'rejects a malformed create index' => function (): void {
        assertThrows(ParseException::class, fn () => Parser::parse('CREATE INDEX idx ON users'));
        assertThrows(ParseException::class, fn () => Parser::parse('CREATE INDEX idx ON users ()'));
        assertThrows(ParseException::class, fn () => Parser::parse('CREATE INDEX ON users (name)'));
        assertThrows(ParseException::class, fn () => Parser::parse('CREATE INDEX idx users (name)'));
        assertThrows(ParseException::class, fn () => Parser::parse('CREATE INDEX idx ON users (a, b)'));
    },

    'parses nesting that stays under the depth limit' => function (): void {
        $nested = Parser::parse('SELECT * FROM t WHERE ' . str_repeat('NOT ', 90) . 'a = 1')->where;

        assertTrue($nested instanceof NotExpression);
    },

    'rejects nesting deep enough to overflow the stack' => function (): void {
        assertThrows(
            ParseException::class,
            fn () => Parser::parse('SELECT * FROM t WHERE ' . str_repeat('NOT ', 20000) . 'a = 1'),
        );

        assertThrows(
            ParseException::class,
            fn () => Parser::parse(
                'SELECT * FROM t WHERE ' . str_repeat('(', 20000) . 'a = 1' . str_repeat(')', 20000),
            ),
        );
    },
];
