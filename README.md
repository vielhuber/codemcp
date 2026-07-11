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

`.env` in the project root:

```dotenv
CODEMCP_PROVIDER=codex          # default agent: codex | claude
CODEMCP_WORKDIR=/app            # default working directory
CODEMCP_MODEL=                  # optional default model
CODEMCP_EFFORT=                 # optional default effort
CODEMCP_TIMEOUT=1800            # max seconds per agent run

MCP_TOKEN=
```

## api

start the mcp server with `vendor/bin/mcp-server.php`. tools:

- `start(prompt, workdir?, provider?, model?, effort?)` — spawn a detached run, returns immediately (`status: running`). **folder continuity**: if the workdir already has history (own session or console thread), the newest thread is continued automatically; only a fresh folder starts a new thread.
- `wait(session_id, timeout_seconds?)` — block up to 240s until the run finishes, then returns the session. check `status` afterwards; call again while still `running`.
- `status(session_id?)` — non-blocking snapshot (single session incl. `log_tail`, or all sessions).
- `continue(prompt, session_id?, workdir?, provider?)` — async follow-up with full context. without `session_id` the newest thread of the folder is continued (console parity with `codex resume --last` / `claude --continue`), including threads started outside this mcp.
- `stop(session_id)` — abort a running session (kills the whole process tree).
- `providers()` — available agents.

session fields: `status` (`running` → `completed` | `error` | `stopped`), `last_content` (final answer), `error`, `thread_id`, `log_tail` (live activity). sessions claiming `running` whose runner died are self-healed to `error` on read.

## model & effort

| provider | model                                                                                                              | effort (`minimal`&#124;`low`&#124;`medium`&#124;`high`&#124;`xhigh`)                                              |
| -------- | ------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------- |
| codex    | `model` argument of the `codex` MCP tool; omit by default unless you know the account supports the requested model | config override `model_reasoning_effort`; `minimal` is ignored because Codex rejects it with the built-in toolset |
| claude   | `--model` CLI flag (e.g. `claude-opus-4-8`, `sonnet`)                                                              | native `--effort` flag (`minimal` maps to `low`)                                                                  |

both persist for the whole session; codex threads keep the settings they were started with. claude runs with `--dangerously-skip-permissions` (+ `IS_SANDBOX=1`), codex with `danger-full-access` — intended for externally sandboxed environments.

## php usage

```php
$code = codemcp::create();
$session = $code->start(
    prompt: 'Fix the failing tests.',
    workdir: '/app',
    provider: 'claude',
    model: 'claude-opus-4-8',
    effort: 'high'
);
do {
    $session = $code->wait($session['session_id'], 120);
} while ($session['status'] === 'running');
echo $session['status'] === 'completed' ? $session['last_content'] : $session['error'];
```

## tests

```bash
vendor/bin/phpunit
```
