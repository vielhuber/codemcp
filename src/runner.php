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
$provider = $argv[2] ?? '';
$timeout = (int) ($argv[3] ?? 0);
$session_dir = $argv[4] ?? '';
if ($session_id === '' || $provider === '' || $timeout < 1 || $session_dir === '') {
    fwrite(STDERR, 'codemcp-runner: missing runner configuration' . PHP_EOL);
    exit(1);
}

codemcp::create([
    'provider' => $provider,
    'timeout' => $timeout,
    'session_dir' => $session_dir
])->executeJob($session_id);
