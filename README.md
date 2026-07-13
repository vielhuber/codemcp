[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/codemcp)](https://github.com/vielhuber/codemcp/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/codemcp)](https://github.com/vielhuber/codemcp/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/codemcp)](https://github.com/vielhuber/codemcp/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/codemcp)](https://packagist.org/packages/vielhuber/codemcp)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/codemcp)](https://packagist.org/packages/vielhuber/codemcp)

# 📟 codemcp 📟

codemcp exposes agentic coding through the official harnesses of **Codex** and **Claude Code** as a small mcp server. runs are **asynchronous**: they execute detached, results are collected by polling — safe behind any transport timeout.

## installation

```bash
composer require vielhuber/codemcp
```

## setup

`.env` in the project root (only for mcps via `http`):

```dotenv
MCP_TOKEN=
```

## usage

every function below is also exposed 1:1 as an mcp tool of the same name.

```php
$code = codemcp::create();

$session = $code->start(
    prompt: 'Fix the failing tests.',
    workdir: '/app',
    provider: 'claude',
    model: 'claude-opus-4-8',
    effort: 'high'
);

$session = $code->wait(
    session_id: $session['session_id'],
    timeout: 120
);

$session = $code->status(
    session_id: $session['session_id']
);
$code->status();

$session = $code->continue(
    session_id: $session['session_id'],
    prompt: 'Now also fix the linter warnings.'
);

$session = $code->stop(
    session_id: $session['session_id']
);

$providers = $code->providers();
```

When `workdir` is omitted, each new session gets a random isolated directory under `sys_get_temp_dir()/codemcp/`. An explicit directory is created recursively when it does not exist, so new projects start in their final workspace and retain folder continuity. `model` and `effort` are required for every new session. Supported effort values are `minimal`, `low`, `medium`, `high` and `xhigh`.

## tests

```bash
./vendor/bin/phpunit
```
