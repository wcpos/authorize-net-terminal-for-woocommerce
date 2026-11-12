<?php
/**
 * Minimal regression test runner for Authorize.net Tap to Pay for WooCommerce POS.
 */

require __DIR__ . '/support/bootstrap.php';

$tests = glob(__DIR__ . '/regression/*.php');

if ($tests === false || count($tests) === 0) {
    echo "No regression tests found.\n";
    exit(1);
}

sort($tests);

foreach ($tests as $test) {
    echo 'Running ' . basename($test) . "...\n";
    try {
        require $test;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Failed ' . basename($test) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

echo 'All regression tests passed (' . count($tests) . ").\n";
