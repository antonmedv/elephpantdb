<?php

declare(strict_types=1);

final class TestServer
{
    /** @var resource */
    private $process;

    /** @var list<resource> */
    private array $pipes = [];

    private function __construct(public readonly string $origin, $process, array $pipes)
    {
        $this->process = $process;
        $this->pipes = $pipes;
    }

    public static function start(string $dataDirectory, array $environment = []): self
    {
        if (!function_exists('proc_open')) {
            skip('proc_open is disabled, so the HTTP endpoint cannot be exercised');
        }

        $port = self::freePort();
        $root = dirname(__DIR__);
        $command = sprintf('%s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg($root . '/elephpantdb.php'));

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            array_merge(['ELEPHPANTDB_DATA' => $dataDirectory, 'PATH' => getenv('PATH')], $environment),
        );

        if (!is_resource($process)) {
            skip('could not start php -S');
        }

        $origin = "http://127.0.0.1:{$port}";
        $server = new self($origin, $process, $pipes);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);

            if (is_resource($probe)) {
                fclose($probe);

                return $server;
            }

            usleep(50000);
        }

        $server->stop();
        skip('php -S did not come up in time');
    }

    public function post(string $body, array $headers = []): array
    {
        return $this->request('POST', $body, $headers);
    }

    public function request(string $method, ?string $body, array $headers = []): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => array_merge(['Content-Type: application/json'], $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 5,
        ]]);

        $raw = @file_get_contents($this->origin . '/elephpantdb.php', false, $context);
        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#\AHTTP/\S+ (\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => $raw === false ? '' : $raw, 'json' => json_decode((string) $raw, true)];
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_terminate($this->process);
        proc_close($this->process);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}

$withServer = static function (callable $work, array $environment = []): void {
    $server = TestServer::start(temporaryDirectory(), $environment);

    try {
        $work($server);
    } finally {
        $server->stop();
    }
};

return [
    'creates, inserts and selects over HTTP' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $created = $server->post(json_encode(['sql' => 'CREATE TABLE users (id INT PRIMARY KEY, name TEXT)']));
            assertSame(200, $created['status']);
            assertSame(true, $created['json']['ok']);
            assertSame(0, $created['json']['rowsAffected']);

            $inserted = $server->post(json_encode([
                'sql' => 'INSERT INTO users (id, name) VALUES (?, ?)',
                'params' => [7, 'ana'],
            ]));
            assertSame(200, $inserted['status']);
            assertSame(1, $inserted['json']['rowsAffected']);

            $selected = $server->post(json_encode(['sql' => 'SELECT * FROM users']));
            assertSame(200, $selected['status']);
            assertSame(1, $selected['json']['count']);
            assertSame([['id' => 7, 'name' => 'ana']], $selected['json']['rows']);
        });
    },

    'accepts named parameters as a JSON object' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $server->post(json_encode(['sql' => 'CREATE TABLE t (id INT, name TEXT)']));
            $server->post(json_encode([
                'sql' => 'INSERT INTO t (id, name) VALUES (:id, :name)',
                'params' => ['id' => 1, 'name' => 'ana'],
            ]));

            $selected = $server->post(json_encode(['sql' => 'SELECT name FROM t']));

            assertSame([['name' => 'ana']], $selected['json']['rows']);
        });
    },

    'returns an empty row list rather than null' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $server->post(json_encode(['sql' => 'CREATE TABLE t (id INT)']));
            $selected = $server->post(json_encode(['sql' => 'SELECT * FROM t']));

            assertSame([], $selected['json']['rows']);
            assertSame(0, $selected['json']['count']);
            assertStringContains('"rows":[]', $selected['body']);
        });
    },

    'preserves unicode and slashes in results' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $server->post(json_encode(['sql' => 'CREATE TABLE t (note TEXT)']));
            $server->post(json_encode(['sql' => 'INSERT INTO t (note) VALUES (?)', 'params' => ['a/b ☃']]));

            $selected = $server->post(json_encode(['sql' => 'SELECT * FROM t']));

            assertSame('a/b ☃', $selected['json']['rows'][0]['note']);
            assertStringContains('a/b ☃', $selected['body']);
        });
    },

    'rejects anything other than POST' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            foreach (['GET', 'PUT', 'DELETE'] as $method) {
                $response = $server->request($method, null);

                assertSame(405, $response['status'], $method);
                assertSame(false, $response['json']['ok']);
            }
        });
    },

    'reports malformed JSON as a client error' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            foreach (['not json', '', '"a string"', '42'] as $body) {
                $response = $server->post($body);

                assertSame(400, $response['status'], describeValue($body));
                assertSame(false, $response['json']['ok']);
            }
        });
    },

    'reports a missing sql field as a client error' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $response = $server->post(json_encode(['params' => [1]]));

            assertSame(400, $response['status']);
            assertStringContains('sql', $response['json']['error']);
        });
    },

    'reports params of the wrong shape as a client error' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $response = $server->post(json_encode(['sql' => 'SELECT * FROM t', 'params' => 'nope']));

            assertSame(400, $response['status']);
            assertStringContains('params', $response['json']['error']);
        });
    },

    'reports a parse error with its position' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $response = $server->post(json_encode(['sql' => 'SELECT * FORM t']));

            assertSame(400, $response['status']);
            assertStringContains('FORM', $response['json']['error']);
            assertStringContains('position 9', $response['json']['error']);
        });
    },

    'reports an unknown table as a client error' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $response = $server->post(json_encode(['sql' => 'SELECT * FROM missing']));

            assertSame(400, $response['status']);
            assertStringContains('missing', $response['json']['error']);
        });
    },

    'never leaks a path or a stack trace' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $responses = [
                $server->post('not json'),
                $server->post(json_encode(['sql' => 'SELECT * FORM t'])),
                $server->post(json_encode(['sql' => 'SELECT * FROM missing'])),
                $server->request('GET', null),
            ];

            foreach ($responses as $response) {
                assertFalse(str_contains($response['body'], 'Stack trace'), $response['body']);
                assertFalse(str_contains($response['body'], '/elephpantdb.php'), $response['body']);
                assertFalse(str_contains($response['body'], dirname(__DIR__)), $response['body']);
            }
        });
    },

    'persists across requests' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $server->post(json_encode(['sql' => 'CREATE TABLE t (id INT)']));

            foreach ([1, 2, 3] as $id) {
                $server->post(json_encode(['sql' => 'INSERT INTO t (id) VALUES (?)', 'params' => [$id]]));
            }

            $selected = $server->post(json_encode(['sql' => 'SELECT * FROM t']));

            assertSame(3, $selected['json']['count']);
            assertSame([1, 2, 3], array_column($selected['json']['rows'], 'id'));
        });
    },

    'runs the full select surface over HTTP with positional placeholders' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $server->post(json_encode(['sql' => 'CREATE TABLE users (id INT PRIMARY KEY, name TEXT, age INT)']));

            foreach ([[1, 'ana', 41], [2, 'bo', 33], [3, 'carol', 55], [4, 'dave', 28]] as $row) {
                $server->post(json_encode([
                    'sql' => 'INSERT INTO users (id, name, age) VALUES (?, ?, ?)',
                    'params' => $row,
                ]));
            }

            $response = $server->post(json_encode([
                'sql' => 'SELECT id, name FROM users WHERE age > ? AND name != ? ORDER BY age DESC LIMIT 10 OFFSET 1',
                'params' => [30, 'carol'],
            ]));

            assertSame(200, $response['status']);
            assertSame([['id' => 2, 'name' => 'bo']], $response['json']['rows']);
        });
    },

    'runs the full select surface over HTTP with named placeholders' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $server->post(json_encode(['sql' => 'CREATE TABLE users (id INT, name TEXT, age INT)']));

            foreach ([[1, 'ana', 41], [2, 'bo', 33], [3, 'carol', 55]] as $row) {
                $server->post(json_encode([
                    'sql' => 'INSERT INTO users (id, name, age) VALUES (:id, :name, :age)',
                    'params' => ['id' => $row[0], 'name' => $row[1], 'age' => $row[2]],
                ]));
            }

            $response = $server->post(json_encode([
                'sql' => 'SELECT name FROM users WHERE age >= :floor ORDER BY age ASC LIMIT :take',
                'params' => ['floor' => 33, 'take' => 2],
            ]));

            assertSame([['name' => 'bo'], ['name' => 'ana']], $response['json']['rows']);
        });
    },

    'filters on a null test over HTTP' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $server->post(json_encode(['sql' => 'CREATE TABLE t (id INT, note TEXT)']));
            $server->post(json_encode(['sql' => 'INSERT INTO t (id, note) VALUES (?, ?)', 'params' => [1, 'here']]));
            $server->post(json_encode(['sql' => 'INSERT INTO t (id) VALUES (?)', 'params' => [2]]));

            $response = $server->post(json_encode(['sql' => 'SELECT id FROM t WHERE note IS NULL']));

            assertSame([['id' => 2]], $response['json']['rows']);
        });
    },

    'runs the whole mutation surface over HTTP' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $post = static fn (array $request): array => $server->post(json_encode($request));

            assertSame(0, $post(['sql' => 'CREATE TABLE t (id INT PRIMARY KEY, name TEXT, age INT)'])['json']['rowsAffected']);
            assertSame(0, $post(['sql' => 'CREATE INDEX idx_age ON t (age)'])['json']['rowsAffected']);

            foreach ([[1, 'ana', 41], [2, 'bo', 33], [3, 'carol', 55]] as $row) {
                $post(['sql' => 'INSERT INTO t (id, name, age) VALUES (?, ?, ?)', 'params' => $row]);
            }

            assertSame(1, $post(['sql' => 'UPDATE t SET name = ? WHERE id = ?', 'params' => ['ANA', 1]])['json']['rowsAffected']);
            assertSame(1, $post(['sql' => 'DELETE FROM t WHERE id = ?', 'params' => [2]])['json']['rowsAffected']);

            $selected = $post(['sql' => 'SELECT id, name FROM t ORDER BY id']);
            assertSame([['id' => 1, 'name' => 'ANA'], ['id' => 3, 'name' => 'carol']], $selected['json']['rows']);

            $vacuumed = $post(['sql' => 'VACUUM t']);
            assertSame(200, $vacuumed['status']);
            assertSame(2, $vacuumed['json']['rowsAffected']);

            assertSame(
                [['id' => 3, 'name' => 'carol']],
                $post(['sql' => 'SELECT id, name FROM t WHERE age = ?', 'params' => [55]])['json']['rows'],
            );
        });
    },

    'accepts any request when no token is configured' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            assertSame(200, $server->post(json_encode(['sql' => 'CREATE TABLE t (id INT)']))['status']);
        });
    },

    'demands a token when one is configured' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $response = $server->post(json_encode(['sql' => 'CREATE TABLE t (id INT)']));

            assertSame(401, $response['status']);
            assertSame(false, $response['json']['ok']);
            assertStringContains('X-Elephpant-Token', $response['json']['error']);
        }, ['ELEPHPANTDB_TOKEN' => 'secret-value']);
    },

    'rejects a wrong token' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            foreach (['wrong', '', 'secret-valu', 'secret-valuee', 'Secret-Value'] as $presented) {
                $response = $server->post(
                    json_encode(['sql' => 'CREATE TABLE t (id INT)']),
                    ['X-Elephpant-Token: ' . $presented],
                );

                assertSame(401, $response['status'], describeValue($presented));
            }
        }, ['ELEPHPANTDB_TOKEN' => 'secret-value']);
    },

    'accepts the right token' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $response = $server->post(
                json_encode(['sql' => 'CREATE TABLE t (id INT)']),
                ['X-Elephpant-Token: secret-value'],
            );

            assertSame(200, $response['status']);
            assertSame(0, $response['json']['rowsAffected']);
        }, ['ELEPHPANTDB_TOKEN' => 'secret-value']);
    },

    'checks the token before the method' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            assertSame(405, $server->request('GET', null)['status'], 'a bad method is still a bad method');
        }, ['ELEPHPANTDB_TOKEN' => 'secret-value']);
    },

    'maps every failure to its documented status' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $cases = [
                [400, 'not json'],
                [400, json_encode(['params' => []])],
                [400, json_encode(['sql' => 'SELECT * FORM t'])],
                [400, json_encode(['sql' => 'SELECT * FROM missing'])],
                [400, json_encode(['sql' => 'CREATE TABLE t (id INT)', 'params' => [1]])],
            ];

            foreach ($cases as [$expected, $body]) {
                $response = $server->post($body);

                assertSame($expected, $response['status'], describeValue($body));
                assertSame(false, $response['json']['ok']);
                assertTrue(is_string($response['json']['error']));
            }
        });
    },

    'rejects a body larger than the cap' => function () use ($withServer): void {
        $withServer(function (TestServer $server): void {
            $response = $server->post(json_encode(['sql' => 'SELECT * FROM t', 'params' => [str_repeat('x', 1100000)]]));

            assertSame(400, $response['status']);
            assertStringContains('larger than', $response['json']['error']);
        });
    },
];
