<?php

declare(strict_types=1);

// A tiny test runner (no PHPUnit):  composer test   or   php tests/run.php [name filter]
// Every PHP warning or notice is a failure. A test needing the database or the library is skipped
// when it is missing, except when CI=true: there a skip counts as a failure.

error_reporting(E_ALL);
set_error_handler(static fn (int $no, string $msg, string $file, int $line) => (error_reporting() & $no) ? throw new ErrorException($msg, 0, $no, $file, $line) : false);  // `@` still silences
require __DIR__ . '/../vendor/autoload.php';

final class Skip extends RuntimeException
{
}

function test(string $name, callable $body): void
{
    $GLOBALS['tests'][$name] = $body;
}

function check(bool $condition, string $what): void
{
    $condition || throw new AssertionError($what);
}

function same(mixed $expected, mixed $actual, string $what): void
{
    check($expected === $actual, "$what: expected " . json_encode($expected) . ', got ' . json_encode($actual));
}

function fails(int $status, callable $body, string $what): void
{
    try {
        $body();
    } catch (App\ApiError $e) {
        same($status, $e->status, $what);
        return;
    }
    throw new AssertionError("$what: expected an ApiError $status");
}

require __DIR__ . '/ModelTest.php';
require __DIR__ . '/AppTest.php';

[$passed, $failed, $skipped] = [0, 0, 0];
foreach ($GLOBALS['tests'] as $name => $body) {
    if (isset($argv[1]) && !str_contains($name, $argv[1])) {
        continue;
    }
    try {
        $body();
        echo "  ok    $name\n";
        $passed++;
    } catch (Skip $e) {
        echo "  skip  $name ({$e->getMessage()})\n";
        $skipped++;
    } catch (Throwable $e) {
        echo "  FAIL  $name\n        {$e->getMessage()} (" . basename($e->getFile()) . ":{$e->getLine()})\n";
        $failed++;
    }
}
echo "\n$passed passed, $failed failed, $skipped skipped\n";
exit($failed === 0 && ($skipped === 0 || getenv('CI') !== 'true') ? 0 : 1);
