#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Detached job runner: executes exactly one agent run for a session and
 * records the outcome in the session file. Spawned via setsid by
 * codemcp::spawnRunner() — never invoked by hand.
 */

foreach (
    [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
        __DIR__ . '/../../../../autoload.php'
    ]
    as $autoload_path
) {
    if (is_file($autoload_path)) {
        require_once $autoload_path;
        break;
    }
}

use vielhuber\codemcp\codemcp;

$session_id = $argv[1] ?? '';
if ($session_id === '') {
    fwrite(STDERR, 'codemcp-runner: missing session id' . PHP_EOL);
    exit(1);
}

codemcp::create()->executeJob($session_id);
