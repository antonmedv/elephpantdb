<?php

declare(strict_types=1);

require __DIR__ . '/../elephpantdb.php';

final class AssertionFailed extends Exception
{
}

final class SkippedTest extends Exception
{
}

function fail(string $message): never
{
    throw new AssertionFailed($message);
}

function skip(string $reason): never
{
    throw new SkippedTest($reason);
}

function assertTrue(bool $condition, string $message = 'expected true'): void
{
    if ($condition !== true) {
        fail($message);
    }
}

function assertFalse(bool $condition, string $message = 'expected false'): void
{
    if ($condition !== false) {
        fail($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected === $actual) {
        return;
    }

    $detail = 'expected ' . describeValue($expected) . ', got ' . describeValue($actual);
    fail($message === '' ? $detail : "{$message}: {$detail}");
}

function assertThrows(string $exceptionClass, callable $subject, string $message = ''): Throwable
{
    try {
        $subject();
    } catch (Throwable $thrown) {
        if ($thrown instanceof $exceptionClass) {
            return $thrown;
        }

        $detail = "expected {$exceptionClass}, got " . $thrown::class . ": {$thrown->getMessage()}";
        fail($message === '' ? $detail : "{$message}: {$detail}");
    }

    $detail = "expected {$exceptionClass}, nothing thrown";
    fail($message === '' ? $detail : "{$message}: {$detail}");
}

function assertStringContains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        return;
    }

    $detail = 'expected ' . describeValue($haystack) . ' to contain ' . describeValue($needle);
    fail($message === '' ? $detail : "{$message}: {$detail}");
}

function describeValue(mixed $value): string
{
    if (is_string($value)) {
        $printable = (string) preg_replace('/[^\x20-\x7E]/', '.', $value);

        if (strlen($printable) > 80) {
            $printable = substr($printable, 0, 80) . '...';
        }

        return "'{$printable}'";
    }

    if (is_array($value)) {
        $rendered = [];

        foreach ($value as $key => $item) {
            $rendered[] = (is_int($key) ? '' : describeValue($key) . ' => ') . describeValue($item);

            if (count($rendered) === 12) {
                $rendered[] = '...';
                break;
            }
        }

        return '[' . implode(', ', $rendered) . ']';
    }

    if (is_object($value)) {
        return $value::class;
    }

    return var_export($value, true);
}

$temporaryDirectories = [];

function temporaryDirectory(): string
{
    global $temporaryDirectories;

    $path = sys_get_temp_dir() . '/elephpantdb-test-' . bin2hex(random_bytes(8));

    if (!mkdir($path, 0700) && !is_dir($path)) {
        fail("could not create temporary directory {$path}");
    }

    $temporaryDirectories[] = $path;

    return $path;
}

function removeDirectory(string $path): void
{
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $entry = $path . '/' . $name;
        is_dir($entry) ? removeDirectory($entry) : unlink($entry);
    }

    rmdir($path);
}

register_shutdown_function(static function (): void {
    global $temporaryDirectories;

    foreach ($temporaryDirectories as $path) {
        if (is_dir($path)) {
            removeDirectory($path);
        }
    }
});

function parseFilter(array $arguments): ?string
{
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--filter=')) {
            return substr($argument, strlen('--filter='));
        }
    }

    return null;
}

// A warning or deprecation is a defect, not decoration: turn every diagnostic
// into a failure so it cannot scroll past unnoticed.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

$filter = parseFilter($argv);
$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);

$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($files as $file) {
    $suite = basename($file, '_test.php');
    $tests = require $file;

    if (!is_array($tests)) {
        fwrite(STDERR, "{$suite}: test file must return an array of name => closure\n");
        $failed++;
        continue;
    }

    $selected = [];

    foreach ($tests as $name => $test) {
        $label = "{$suite}: {$name}";

        if ($filter === null || str_contains($suite, $filter) || str_contains($label, $filter)) {
            $selected[$name] = $test;
        }
    }

    if ($selected === []) {
        continue;
    }

    echo "{$suite}\n";

    foreach ($selected as $name => $test) {
        try {
            $test();
            $passed++;
            echo "  PASS  {$name}\n";
        } catch (SkippedTest $skipReason) {
            $skipped++;
            echo "  SKIP  {$name}\n";
            echo "        {$skipReason->getMessage()}\n";
        } catch (Throwable $failure) {
            $failed++;
            echo "  FAIL  {$name}\n";
            echo '        ' . $failure::class . ': ' . $failure->getMessage() . "\n";
            echo '        ' . basename($failure->getFile()) . ':' . $failure->getLine() . "\n";
        }
    }

    echo "\n";
}

$summary = "{$passed} passed, {$failed} failed";

if ($skipped > 0) {
    $summary .= ", {$skipped} skipped";
}

echo "{$summary}\n";

exit($failed > 0 ? 1 : 0);
