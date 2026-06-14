[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/codemcp)](https://github.com/vielhuber/codemcp/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/codemcp)](https://github.com/vielhuber/codemcp/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/codemcp)](https://github.com/vielhuber/codemcp/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/codemcp)](https://packagist.org/packages/vielhuber/codemcp)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/codemcp)](https://packagist.org/packages/vielhuber/codemcp)

# 📟 codemcp 📟

codemcp is a small mcp server that exposes agentic coding through codex and claude code. it does not reimplement a coding agent, it forwards normalized MCP tools to the closest official agent interface:

- **Codex** via `codex mcp-server`
- **Claude Code** via `claude -p` / `claude --resume`

## installation

install once with [composer](https://getcomposer.org):

```bash
composer require vielhuber/codemcp
```

then add this to your files:

```php
require __DIR__ . '/vendor/autoload.php';
use vielhuber\codemcp\codemcp;
```

## setup

codemcp reads configuration from the `.env` in your project root.

```dotenv
CODEMCP_PROVIDER=codex
CODEMCP_WORKDIR=/app
CODEMCP_ALLOW_WRITE=false

MCP_TOKEN=
```

Claude Code also has `claude mcp serve`, but that exposes Claude Code's tools to another MCP client. For running Claude Code as the coding agent, codemcp uses print/resume mode.

codemcp expects `codex` and `claude` in the local `node_modules/.bin` directory of the project where the MCP server is started.

## usage

```php
$code = codemcp::create();

$result = $code->start(
    prompt: 'Review this project and list the highest-risk bugs.',
    workdir: '/app',
    provider: 'codex'
);

print_r($result);
```

## mcp server

codemcp ships as a standalone MCP server:

```bash
vendor/bin/mcp-server.php
```

available tools:

- `start(prompt, workdir?, provider?)`
- `continue(session_id, prompt)`
- `status(session_id?)`
- `providers()`

## write access

Set `CODEMCP_ALLOW_WRITE=true` when the agent may edit files. Otherwise Codex runs read-only and Claude Code runs in plan mode.

## tests

```bash
vendor/bin/phpunit
```
