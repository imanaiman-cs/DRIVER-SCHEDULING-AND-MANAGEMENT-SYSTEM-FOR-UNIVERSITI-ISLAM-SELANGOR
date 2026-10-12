<?php
/**
 * Tiny test runner (no PHPUnit needed, so it works on any XAMPP):
 *
 *     php tests/run.php            run every tests/*Test.php
 *     php tests/run.php Recommender   only files whose name contains "Recommender"
 *
 * Each *Test.php file returns an array: 'test name' => function () { ... }.
 * Inside a test use the assert_* helpers below. Tests that touch the database run
 * inside a transaction that is rolled back, so they never change your real data.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$GLOBALS['assertions'] = 0;
function assert_true($condition, string $message = 'expected true'): void
{
    $GLOBALS['assertions']++;
    if (!$condition) { throw new AssertionError($message); }
}
function assert_same($expected, $actual, string $message = ''): void
{
    $GLOBALS['assertions']++;
    if ($expected !== $actual) {
        throw new AssertionError(($message ? "$message: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function assert_near(float $expected, float $actual, float $delta = 0.01, string $message = ''): void
{
    $GLOBALS['assertions']++;
    if (abs($expected - $actual) > $delta) {
        throw new AssertionError(($message ? "$message: " : '') . "expected $expected (+/- $delta), got $actual");
    }
}

$filter = $argv[1] ?? '';
$pass = $fail = 0;
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    if ($filter !== '' && stripos(basename($file), $filter) === false) { continue; }
    echo "\n" . basename($file, '.php') . "\n";
    foreach ((require $file) as $name => $test) {
        $db = null;
        try {
            $db = Database::connection();
            $db->beginTransaction();            // every test starts from a clean, rolled-back state
            $test();
            echo "  PASS  $name\n"; $pass++;
        } catch (Throwable $e) {
            echo "  FAIL  $name\n        " . $e->getMessage() . "\n"; $fail++;
        } finally {
            if ($db && $db->inTransaction()) { $db->rollBack(); }
        }
    }
}
echo "\n$pass passed, $fail failed ({$GLOBALS['assertions']} assertions)\n";
exit($fail ? 1 : 0);
