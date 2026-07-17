<?php
declare(strict_types=1);

namespace vielhuber\codemcp;

use RuntimeException;
use vielhuber\simplemcp\Attributes\McpTool;

final class codemcp
{
    private const DEFAULT_PROVIDER = 'codex';
    private const DEFAULT_INACTIVITY_TIMEOUT = 1800;
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = array_merge($this->defaultConfig(), $config ?? []);
    }

    public static function create(?array $config = null): self
    {
        return new self(config: $config);
    }

    /**
     * Start an agentic coding session ASYNCHRONOUSLY — with automatic folder
     * continuity: if the working directory already has history (a previous
     * session of this MCP or a thread started on a console), the NEWEST thread
     * of that folder is CONTINUED with full context; only a folder without any
     * history begins a fresh thread. This mirrors the console habit of
     * `codex resume --last` / `claude --continue`. If the folder's newest
     * session is still running, the prompt is QUEUED there and processed in
     * order (CLI typing parity).
     *
     * The chosen agent (Codex CLI or Claude Code) is spawned DETACHED with its
     * FULL native harness: it autonomously reads and edits files, runs shell
     * commands and tests, and works the task end-to-end inside the working
     * directory.
     *
     * This call returns IMMEDIATELY with `session_id` and status "running" —
     * it does NOT wait for the agent. Poll with `wait` (preferred) or `status`
     * until the status leaves "running"; the agent's final answer is then in
     * `last_content`. Long runs (10+ minutes) are normal and NOT an error.
     *
     * The agent has write access inside the working directory. For pure
     * analysis/review, state "do not change any files" explicitly in the
     * prompt.
     *
     * @param string $prompt Complete, self-contained task description. The agent knows NOTHING about this conversation — include the concrete goal, target paths/files, constraints (e.g. "analysis only, do not change files"), and the command(s) to verify success (e.g. "run vendor/bin/phpunit and make it pass").
     * @param string $model Provider-native model name. Required for every new session; claude examples: "claude-opus-4-8" or "sonnet".
     * @param string $effort Reasoning effort: "minimal", "low", "medium", "high" or "xhigh". Maps to the reasoning-effort config on codex and to the native --effort level on claude. Higher = more thorough but slower.
     * @param string|null $workdir Absolute path the agent works in — also the key for automatic folder continuity. Created when missing. Omit to create a new isolated directory under the system temp directory.
     * @param string|null $provider Coding agent: "codex" (Codex CLI) or "claude" (Claude Code). Omit to use the configured default.
     */
    #[McpTool(name: 'start')]
    public function startTool(
        string $prompt,
        string $model,
        string $effort,
        ?string $workdir = null,
        ?string $provider = null
    ): array
    {
        return $this->start(
            prompt: $prompt,
            workdir: $workdir,
            provider: $provider,
            model: $model,
            effort: $effort
        );
    }

    /**
     * Send a follow-up prompt into a session ASYNCHRONOUSLY with full context
     * preservation: the agent resumes its native thread and still knows
     * everything from the previous turns — files it read, changes it made,
     * decisions it took. Model and effort of the session are kept.
     *
     * CLI parity: prompts can be fired at any time, even while the session is
     * still running — they are QUEUED and processed strictly in order, exactly
     * like typing further messages into an interactive claude/codex session.
     * `wait` returns once the whole queue is drained.
     *
     * Returns immediately — poll with `wait`/`status` for the result. Use
     * continue ONLY for follow-ups that belong to the same task; start a fresh
     * session (other folder) for unrelated tasks. To continue the newest
     * thread of a FOLDER, simply call `start` — it auto-continues folders
     * that already have history.
     *
     * @param string $session_id The `session_id` returned by a previous `start` call (also listed by `status`).
     * @param string $prompt Follow-up instruction for the same task. May reference prior context ("now also fix the failing test you mentioned").
     */
    #[McpTool(name: 'continue')]
    public function continueTool(string $session_id, string $prompt): array
    {
        return $this->continue(session_id: $session_id, prompt: $prompt);
    }

    /**
     * Wait for a running session to finish, up to `timeout_seconds` (1–240,
     * default 60). A session counts as finished only when its WHOLE prompt
     * queue is drained. Returns the session either way — ALWAYS check its
     * `status` field: "running" means the agent is still working (simply call
     * `wait` again; long runs are normal), "completed" means the final answer
     * is in `last_content`, "error" means the run failed (see `error`),
     * "stopped" means it was aborted. `log_tail` shows the agent's recent
     * activity including the answers to already-processed queued prompts.
     * Prefer this over busy-polling `status` in a loop.
     *
     * @param string $session_id The session to wait for.
     * @param int $timeout Maximum seconds to wait in this single call (1–240). If the session is still running afterwards, just call `wait` again.
     */
    #[McpTool(name: 'wait')]
    public function waitTool(string $session_id, int $timeout = 60): array
    {
        return $this->wait(session_id: $session_id, timeout: $timeout);
    }

    /**
     * Show the active configuration (default provider and inactivity timeout) and stored
     * sessions. Without `session_id`, lists all known
     * sessions newest first; with `session_id`, returns just that session
     * including `status` ("running" | "completed" | "error" | "stopped"),
     * `last_content` (the final answer when completed) and `log_tail` (recent
     * agent activity). Non-blocking snapshot — use `wait` to actually wait.
     *
     * @param string|null $session_id Session id to inspect. Omit to list all sessions.
     */
    #[McpTool(name: 'status')]
    public function statusTool(?string $session_id = null): array
    {
        return $this->status(session_id: $session_id);
    }

    /**
     * Abort a running session: terminates the detached agent run including its
     * whole process tree and marks the session as "stopped". Changes the agent
     * already made in the working directory are NOT rolled back. Fails if the
     * session is not running.
     *
     * @param string $session_id The running session to abort.
     */
    #[McpTool(name: 'stop')]
    public function stopTool(string $session_id): array
    {
        return $this->stop(session_id: $session_id);
    }

    /**
     * List the available coding agents ("codex" and "claude") with the exact
     * command each one is launched with and its integration mode (codex:
     * mcp-agent via `codex mcp-server`; claude: cli-agent via `claude -p`).
     * Purely informational — helps to choose the `provider` argument for
     * `start`.
     */
    #[McpTool(name: 'providers')]
    public function providersTool(): array
    {
        return $this->providers();
    }

    public function start(
        string $prompt,
        string $model,
        string $effort,
        ?string $workdir = null,
        ?string $provider = null
    ): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('codemcp: prompt must not be empty.');
        }

        $provider_name = $this->normalizeProvider($provider);
        $model = $this->normalizeModel($model);
        $effort = $this->normalizeEffort($effort);
        $workdir = $this->resolveWorkdir($workdir);

        // folder continuity: existing history wins over a fresh thread
        $resumed = $this->continueFolderIfHistory($workdir, $provider_name, $prompt, $model, $effort);
        if ($resumed !== null) {
            return $resumed;
        }

        $session = $this->saveSession([
            'provider' => $provider_name,
            'workdir' => $workdir,
            'model' => $model,
            'effort' => $effort,
            'mode' => 'start',
            'prompt' => $prompt,
            'queue' => [$prompt],
            'status' => 'running',
            'thread_id' => null,
            'last_content' => null,
            'error' => null,
            'pid' => null,
            'started_at' => date(DATE_ATOM),
            'finished_at' => null
        ]);
        $session = $this->spawnRunner($session);
        return $this->publicSession($session);
    }

    public function continue(string $session_id, string $prompt): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('codemcp: prompt must not be empty.');
        }
        return $this->continueSession($session_id, $prompt);
    }

    /**
     * Folder continuity used by start(): resolve the newest thread of the
     * workdir and continue it. A running MCP session wins to prevent parallel
     * work; otherwise the most recently active native thread wins over stale
     * MCP metadata. Returns null when the folder has no history at all.
     */
    private function continueFolderIfHistory(string $workdir, string $provider_name, string $prompt, ?string $model, ?string $effort): ?array
    {
        $sessions = $this->sessionStatus()['sessions'];
        foreach ($sessions as $candidate) {
            if (($candidate['workdir'] ?? '') !== $workdir) {
                continue;
            }
            if (($candidate['provider'] ?? '') !== $provider_name) {
                continue;
            }
            if ((string) ($candidate['thread_id'] ?? '') === '') {
                continue;
            }
            if (($candidate['status'] ?? '') !== 'running') {
                continue;
            }
            return $this->continueSession((string) $candidate['id'], $prompt, $model, $effort);
        }
        $native_thread_id = match ($provider_name) {
            'codex' => $this->findLatestCodexThreadFor($workdir),
            'claude' => $this->findLatestClaudeThreadFor($workdir),
            default => null
        };
        foreach ($sessions as $candidate) {
            if (($candidate['workdir'] ?? '') !== $workdir) {
                continue;
            }
            if (($candidate['provider'] ?? '') !== $provider_name) {
                continue;
            }
            if ((string) ($candidate['thread_id'] ?? '') === '') {
                continue;
            }
            if ($native_thread_id !== null && (string) $candidate['thread_id'] !== $native_thread_id) {
                continue;
            }
            return $this->continueSession((string) $candidate['id'], $prompt, $model, $effort);
        }
        if ($native_thread_id === null) {
            return null;
        }
        $session = $this->saveSession([
            'provider' => $provider_name,
            'workdir' => $workdir,
            'model' => $model,
            'effort' => $effort,
            'mode' => 'continue_last',
            'prompt' => $prompt,
            'queue' => [$prompt],
            'status' => 'running',
            'thread_id' => $native_thread_id,
            'last_content' => null,
            'error' => null,
            'pid' => null,
            'started_at' => date(DATE_ATOM),
            'finished_at' => null
        ]);
        $session = $this->spawnRunner($session);
        return $this->publicSession($session);
    }

    private function continueSession(string $session_id, string $prompt, ?string $model = null, ?string $effort = null): array
    {
        $spawn = false;
        $session = $this->withSessionLock($session_id, function () use ($session_id, $prompt, $model, $effort, &$spawn) {
            $session = $this->getSession($session_id);
            $queue = is_array($session['queue'] ?? null) ? $session['queue'] : [];
            $queue[] = $prompt;
            $session['queue'] = $queue;
            if ($model !== null) {
                $session['model'] = $model;
            }
            if ($effort !== null) {
                $session['effort'] = $effort;
            }
            // an actively running runner will pick the queued prompt up on its
            // own; anything else (finished, errored, stopped, dead runner)
            // needs a fresh runner to drain the queue
            $pid = (int) ($session['pid'] ?? 0);
            $started = strtotime((string) ($session['started_at'] ?? ''));
            $booting = $pid === 0 && $started !== false && time() - $started < 30;
            $alive = ($session['status'] ?? '') === 'running' && ($this->processAlive($pid) || $booting);
            if (!$alive) {
                $session['status'] = 'running';
                $session['error'] = null;
                $session['pid'] = null;
                $session['started_at'] = date(DATE_ATOM);
                $session['finished_at'] = null;
                $spawn = true;
            }
            return $this->saveSession($session);
        });
        if ($spawn) {
            $session = $this->spawnRunner($session);
        }
        return $this->publicSession(
            $session,
            $spawn
                ? null
                : 'prompt queued (position ' .
                    count($session['queue'] ?? []) .
                    ') — the running agent processes queued prompts strictly in order; poll with wait(session_id).'
        );
    }

    /**
     * Expose the session under the `session_id` key that `continue`/`wait`/
     * `stop` expect as input, plus a polling hint for tool consumers.
     */
    private function publicSession(array $session, ?string $hint = null): array
    {
        return array_merge(['session_id' => $session['id']], $session, [
            'hint' =>
                $hint ??
                'agent is running detached — poll with wait(session_id) until status is no longer "running"; the final answer will be in last_content.'
        ]);
    }

    public function wait(string $session_id, int $timeout = 60): array
    {
        $timeout = max(1, min(240, $timeout));
        $deadline = time() + $timeout;
        while (true) {
            $session = $this->refreshSession($this->getSession($session_id));
            if (($session['status'] ?? 'completed') !== 'running' || time() >= $deadline) {
                $session['log_tail'] = $this->logTail($session);
                return $session;
            }
            sleep(2);
        }
    }

    public function stop(string $session_id): array
    {
        $session = $this->refreshSession($this->getSession($session_id));
        if (($session['status'] ?? 'completed') !== 'running') {
            throw new RuntimeException('codemcp: session is not running.');
        }
        $pid = (int) ($session['pid'] ?? 0);
        if ($pid > 0) {
            // the runner is a session leader (setsid), so killing by session id
            // reaps the entire tree — including bash -ic children that job
            // control moved into their own process groups
            exec('pkill -TERM -s ' . $pid . ' 2>/dev/null');
            usleep(300000);
            exec('pkill -KILL -s ' . $pid . ' 2>/dev/null');
        }
        $session = $this->withSessionLock($session_id, function () use ($session_id) {
            $session = $this->getSession($session_id);
            $session['status'] = 'stopped';
            // stop means abort everything, including not-yet-processed prompts
            $session['queue'] = [];
            $session['pid'] = null;
            $session['finished_at'] = date(DATE_ATOM);
            return $this->saveSession($session);
        });
        $this->logLine($session, 'session stopped on request');
        return $session;
    }

    public function status(?string $session_id = null): array
    {
        if ($session_id !== null && trim($session_id) !== '') {
            $session = $this->refreshSession($this->getSession($session_id));
            $session['log_tail'] = $this->logTail($session);
            return $session;
        }
        return [
            'config' => [
                'provider' => $this->config['provider'],
                'timeout' => $this->config['timeout']
            ],
            'sessions' => $this->sessionStatus()
        ];
    }

    public function providers(): array
    {
        return [
            'codex' => [
                'provider' => 'codex',
                'command' => 'bash -ic "cd ' . $this->projectDir() . ' && ./node_modules/.bin/codex mcp-server"',
                'mode' => 'mcp-agent'
            ],
            'claude' => [
                'provider' => 'claude',
                'command' => $this->agentBinary('claude') . ' -p',
                'mode' => 'cli-agent'
            ]
        ];
    }

    /**
     * Detached-runner entry point (called by runner.php, never by MCP tools):
     * drains the session's prompt queue strictly in order — prompts enqueued
     * while a run is in flight are picked up automatically, exactly like
     * typing further messages into an interactive CLI session. All state
     * transitions of a run happen here.
     */
    public function executeJob(string $session_id): void
    {
        $session = $this->withSessionLock($session_id, function () use ($session_id) {
            $session = $this->getSession($session_id);
            $session['pid'] = getmypid();
            return $this->saveSession($session);
        });
        $this->logLine(
            $session,
            'runner started (pid ' .
                $session['pid'] .
                ', mode ' .
                ($session['mode'] ?? 'start') .
                ', provider ' .
                ($session['provider'] ?? '?') .
                ($session['model'] ?? null ? ', model ' . $session['model'] : '') .
                ($session['effort'] ?? null ? ', effort ' . $session['effort'] : '') .
                ')'
        );
        while (true) {
            // claim the next queued prompt; an empty queue terminates the run
            [$session, $prompt] = $this->withSessionLock($session_id, function () use ($session_id) {
                $session = $this->getSession($session_id);
                if (($session['status'] ?? '') === 'stopped') {
                    return [$session, null];
                }
                $queue = is_array($session['queue'] ?? null) ? $session['queue'] : [];
                if ($queue === []) {
                    $session['status'] = 'completed';
                    $session['pid'] = null;
                    $session['finished_at'] = date(DATE_ATOM);
                    return [$this->saveSession($session), null];
                }
                $prompt = (string) array_shift($queue);
                $session['queue'] = $queue;
                $session['prompt'] = $prompt;
                return [$this->saveSession($session), $prompt];
            });
            if ($prompt === null) {
                $this->logLine($session, 'runner finished (' . ($session['status'] ?? '?') . ')');
                return;
            }
            try {
                $result = $this->executeQueuedPrompt($session, $prompt);
            } catch (\Throwable $e) {
                // top-level boundary of the detached process: record ANY failure
                // in the session file so pollers see a terminal state instead of
                // a silently dead runner. remaining queued prompts are kept —
                // a later continue() respawns a runner that drains them.
                $this->withSessionLock($session_id, function () use ($session_id, $e) {
                    $session = $this->getSession($session_id);
                    if (($session['status'] ?? '') === 'stopped') {
                        return $session;
                    }
                    $session['status'] = 'error';
                    $session['error'] = $e->getMessage();
                    $session['pid'] = null;
                    $session['finished_at'] = date(DATE_ATOM);
                    return $this->saveSession($session);
                });
                $this->logLine($session, 'runner failed: ' . $e->getMessage());
                return;
            }
            $session = $this->withSessionLock($session_id, function () use ($session_id, $result) {
                $session = $this->getSession($session_id);
                if (($session['status'] ?? '') === 'stopped') {
                    return $session;
                }
                $session['thread_id'] = $result['thread_id'] ?? ($session['thread_id'] ?? null);
                $session['last_content'] = $result['content'] ?? null;
                $session['error'] = null;
                return $this->saveSession($session);
            });
            if (($session['status'] ?? '') === 'stopped') {
                return;
            }
            $this->logLine($session, 'prompt done: ' . mb_substr(trim((string) ($result['content'] ?? '')), 0, 120));
        }
    }

    /**
     * Execute one queued prompt: the first prompt of a fresh session starts a
     * new thread (or resolves the folder's native history), every later prompt
     * continues the recorded thread.
     */
    private function executeQueuedPrompt(array $session, string $prompt): array
    {
        $session_id = (string) $session['id'];
        $mode = (string) ($session['mode'] ?? 'start');
        $provider_name = (string) ($session['provider'] ?? '');
        $workdir = (string) ($session['workdir'] ?? '');
        $model = isset($session['model']) && $session['model'] !== null && $session['model'] !== '' ? (string) $session['model'] : null;
        $effort = isset($session['effort']) && $session['effort'] !== null && $session['effort'] !== '' ? (string) $session['effort'] : null;
        $thread_id = (string) ($session['thread_id'] ?? '');
        $event_log = $this->logPath($session_id);

        return match (true) {
            $thread_id !== '' && $provider_name === 'codex' => $this->continueCodex(
                thread_id: $thread_id,
                prompt: $prompt,
                workdir: $workdir,
                event_log: $event_log
            ),
            $thread_id !== '' && $provider_name === 'claude' => $this->continueClaude(
                thread_id: $thread_id,
                prompt: $prompt,
                workdir: $workdir,
                model: $model,
                effort: $effort
            ),
            $mode === 'continue_last' && $provider_name === 'codex' => $this->continueCodex(
                thread_id: $this->latestCodexThreadFor($workdir),
                prompt: $prompt,
                workdir: $workdir,
                event_log: $event_log
            ),
            $mode === 'continue_last' && $provider_name === 'claude' => $this->continueLastClaude(
                prompt: $prompt,
                workdir: $workdir,
                model: $model,
                effort: $effort
            ),
            $provider_name === 'codex' => $this->startCodex(
                prompt: $prompt,
                workdir: $workdir,
                model: $model,
                effort: $effort,
                event_log: $event_log
            ),
            $provider_name === 'claude' => $this->startClaude(
                prompt: $prompt,
                workdir: $workdir,
                model: $model,
                effort: $effort
            ),
            default => throw new RuntimeException('codemcp: unsupported job: ' . $mode . '/' . $provider_name)
        };
    }

    private function spawnRunner(array $session): array
    {
        $id = (string) $session['id'];
        $log = $this->logPath($id);
        // re-read under lock: a concurrently enqueued prompt must not be lost
        // to a stale overwrite of the session we still hold in memory
        $session = $this->withSessionLock($id, function () use ($id, $log) {
            $session = $this->getSession($id);
            $session['log_file'] = $log;
            return $this->saveSession($session);
        });
        // setsid makes the runner a session leader: it survives the MCP request
        // lifecycle and stop() can reap the whole tree via pkill -s
        $command =
            'setsid ' .
            escapeshellarg(PHP_BINARY) .
            ' ' .
            escapeshellarg(__DIR__ . '/runner.php') .
            ' ' .
            escapeshellarg($id) .
            ' ' .
            escapeshellarg((string) $this->config['provider']) .
            ' ' .
            escapeshellarg((string) $this->config['timeout']) .
            ' ' .
            escapeshellarg((string) $this->config['session_dir']) .
            ' >> ' .
            escapeshellarg($log) .
            ' 2>&1 < /dev/null &';
        $process = proc_open(
            ['bash', '-c', $command],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w']
            ],
            $pipes,
            $this->projectDir(),
            $this->processEnv()
        );
        if (!is_resource($process)) {
            return $this->withSessionLock($id, function () use ($id) {
                $session = $this->getSession($id);
                $session['status'] = 'error';
                $session['error'] = 'failed to spawn detached runner';
                $session['finished_at'] = date(DATE_ATOM);
                return $this->saveSession($session);
            });
        }
        proc_close($process);
        return $session;
    }

    /**
     * Self-heal on read: a session claiming "running" whose runner process is
     * gone (crash, OOM, reboot) is marked as error so pollers never wait on a
     * ghost. Sessions from the pre-async era carry no status and count as
     * completed.
     */
    private function refreshSession(array $session): array
    {
        if (!isset($session['status'])) {
            $session['status'] = 'completed';
            return $session;
        }
        if ($session['status'] !== 'running') {
            return $session;
        }
        $pid = (int) ($session['pid'] ?? 0);
        if ($pid > 0 && $this->processAlive($pid)) {
            return $session;
        }
        if ($pid === 0) {
            $started = strtotime((string) ($session['started_at'] ?? ''));
            if ($started !== false && time() - $started < 30) {
                // runner still booting — pid lands in the session file within
                // the first seconds of executeJob
                return $session;
            }
        }
        $session['status'] = 'error';
        $session['error'] = 'runner process died unexpectedly (see log_tail)';
        $session['pid'] = null;
        $session['finished_at'] = date(DATE_ATOM);
        return $this->saveSession($session);
    }

    private function processAlive(int $pid): bool
    {
        return $pid > 0 && is_dir('/proc/' . $pid);
    }

    private function startCodex(string $prompt, string $workdir, ?string $model = null, ?string $effort = null, ?string $event_log = null): array
    {
        $arguments = [
            'prompt' => $prompt,
            'cwd' => $workdir,
            'sandbox' => 'danger-full-access',
            'approval-policy' => 'never'
        ];
        if ($model !== null) {
            $arguments['model'] = $model;
        }
        if ($effort !== null && $effort !== 'minimal') {
            $arguments['config'] = ['model_reasoning_effort' => $effort];
        }
        return $this->callCodexTool('codex', $arguments, $workdir, $event_log);
    }

    private function continueCodex(string $thread_id, string $prompt, string $workdir, ?string $event_log = null): array
    {
        // codex mcp-server can only continue threads held in the SAME server
        // process — a fresh process answers "Session not found". `codex exec
        // resume` resumes from the on-disk rollout instead and keeps the
        // thread id stable across resumes.
        $output_file = tempnam(sys_get_temp_dir(), 'codemcp_last_');
        if ($output_file === false) {
            throw new RuntimeException('codemcp: failed to create temp file for codex output.');
        }
        try {
            $result = $this->runInteractiveCommand(
                [
                    $this->agentBinary('codex'),
                    'exec',
                    'resume',
                    $thread_id,
                    '--dangerously-bypass-approvals-and-sandbox',
                    '--skip-git-repo-check',
                    '--json',
                    '--output-last-message',
                    $output_file,
                    $prompt
                ],
                $workdir,
                [],
                $event_log
            );
            $stdout = is_string($result['raw'] ?? null) ? $result['raw'] : '';
            $content = is_file($output_file) ? trim((string) file_get_contents($output_file)) : '';
            return [
                'provider' => 'codex',
                'tool' => 'codex-exec-resume',
                'thread_id' => $thread_id,
                'content' => $content !== '' ? $content : ($result['content'] ?? null),
                'raw' => $stdout !== '' ? $stdout : $result
            ];
        } finally {
            if (is_file($output_file)) {
                unlink($output_file);
            }
        }
    }

    private function startClaude(string $prompt, string $workdir, ?string $model = null, ?string $effort = null): array
    {
        $thread_id = $this->createUuid();
        $command = array_merge(
            [$this->agentBinary('claude'), '-p', '--output-format', 'json', '--session-id', $thread_id],
            $this->claudeArgs($model, $effort),
            [$prompt]
        );
        $result = $this->runCommand($command, $workdir, $this->claudeEnv());
        $result['thread_id'] = $result['thread_id'] ?? $thread_id;
        return $result;
    }

    private function continueClaude(
        string $thread_id,
        string $prompt,
        string $workdir,
        ?string $model = null,
        ?string $effort = null
    ): array
    {
        $command = array_merge(
            [$this->agentBinary('claude'), '--resume', $thread_id, '-p', '--output-format', 'json'],
            $this->claudeArgs($model, $effort),
            [$prompt]
        );
        $result = $this->runCommand($command, $workdir, $this->claudeEnv());
        $result['thread_id'] = $result['thread_id'] ?? $thread_id;
        return $result;
    }

    /**
     * Folder-based codex resume: find the newest rollout whose recorded cwd
     * matches the workdir. Modification time matters because resuming an older
     * thread makes that thread the most recently active one without renaming
     * its rollout file.
     */
    private function findLatestCodexThreadFor(string $workdir): ?string
    {
        $home = getenv('CODEX_HOME') ?: ((getenv('HOME') ?: '/root') . '/.codex');
        $files = glob($home . '/sessions/*/*/*/rollout-*.jsonl') ?: [];
        usort($files, function (string $first, string $second): int {
            $first_modified = filemtime($first) ?: 0;
            $second_modified = filemtime($second) ?: 0;
            return $second_modified <=> $first_modified ?: strcmp($second, $first);
        });
        $needle = '"cwd":' . json_encode(rtrim($workdir, '/'), JSON_UNESCAPED_SLASHES);
        foreach ($files as $file) {
            $head = (string) file_get_contents($file, false, null, 0, 8192);
            if (!str_contains($head, $needle)) {
                continue;
            }
            if (preg_match('/rollout-.*-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.jsonl$/', $file, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    private function latestCodexThreadFor(string $workdir): string
    {
        $thread_id = $this->findLatestCodexThreadFor($workdir);
        if ($thread_id === null) {
            throw new RuntimeException('codemcp: no previous codex session found for workdir: ' . $workdir);
        }
        return $thread_id;
    }

    private function findLatestClaudeThreadFor(string $workdir): ?string
    {
        $home = getenv('CLAUDE_CONFIG_DIR') ?: ((getenv('HOME') ?: '/root') . '/.claude');
        $slug = preg_replace('/[^a-zA-Z0-9]/', '-', rtrim($workdir, '/')) ?? '';
        $files = glob($home . '/projects/' . $slug . '/*.jsonl') ?: [];
        usort($files, function (string $first, string $second): int {
            $first_modified = filemtime($first) ?: 0;
            $second_modified = filemtime($second) ?: 0;
            return $second_modified <=> $first_modified ?: strcmp($second, $first);
        });
        if ($files === []) {
            return null;
        }
        return pathinfo($files[0], PATHINFO_FILENAME);
    }

    private function continueLastClaude(string $prompt, string $workdir, ?string $model = null, ?string $effort = null): array
    {
        // claude scopes --continue to the current directory → run inside workdir
        $command = array_merge(
            [$this->agentBinary('claude'), '--continue', '-p', '--output-format', 'json'],
            $this->claudeArgs($model, $effort),
            [$prompt]
        );
        return $this->runCommand($command, $workdir, $this->claudeEnv());
    }

    private function claudeArgs(?string $model, ?string $effort): array
    {
        // full autonomy, matching codex's danger-full-access
        $args = ['--dangerously-skip-permissions'];
        if ($model !== null) {
            $args[] = '--model';
            $args[] = $model;
        }
        if ($effort !== null) {
            $args[] = '--effort';
            // claude has no "minimal" level
            $args[] = $effort === 'minimal' ? 'low' : $effort;
        }
        return $args;
    }

    private function claudeEnv(): array
    {
        // claude refuses --dangerously-skip-permissions as root unless the
        // environment is declared as a sandbox
        return ['IS_SANDBOX' => '1'];
    }

    private function callCodexTool(string $tool, array $arguments, ?string $workdir, ?string $event_log = null): array
    {
        $client = $this->startStdioMcp(command: 'bash', args: ['-ic', 'cd ' . escapeshellarg($this->projectDir()) . ' && ./node_modules/.bin/codex mcp-server'], workdir: $workdir);
        $client['event_log'] = $event_log;
        try {
            $this->mcpRequest($client, 'initialize', [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => [
                    'name' => 'codemcp',
                    'version' => '1.0.0'
                ]
            ]);
            $this->mcpNotify($client, 'notifications/initialized');
            $response = $this->mcpRequest($client, 'tools/call', [
                'name' => $tool,
                'arguments' => $arguments
            ]);
            return $this->normalizeMcpResult($response['result'] ?? [], $tool);
        } finally {
            $this->closeStdioMcp($client);
        }
    }

    private function startStdioMcp(string $command, array $args, ?string $workdir): array
    {
        $process = proc_open(
            array_merge([$command], $args),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ],
            $pipes,
            $workdir,
            $this->processEnv()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('codemcp: failed to start ' . $command);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return [
            'process' => $process,
            'pipes' => $pipes,
            'id' => 1,
            'buffer' => '',
            'event_log' => null
        ];
    }

    private function mcpRequest(array &$client, string $method, array $params = []): array
    {
        $id = $client['id']++;
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method
        ];
        if ($params !== []) {
            $payload['params'] = $params;
        }
        $this->mcpWrite($client, $payload);
        return $this->mcpRead($client, $id);
    }

    private function mcpNotify(array &$client, string $method): void
    {
        $this->mcpWrite($client, [
            'jsonrpc' => '2.0',
            'method' => $method
        ]);
    }

    private function mcpWrite(array $client, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || fwrite($client['pipes'][0], $json . "\n") === false) {
            throw new RuntimeException('codemcp: failed to write MCP request.');
        }
        fflush($client['pipes'][0]);
    }

    private function mcpRead(array &$client, int $id): array
    {
        $last_activity = microtime(true);
        while ((microtime(true) - $last_activity) <= $this->config['timeout']) {
            $status = proc_get_status($client['process']);
            if (($status['running'] ?? false) === false) {
                $stderr = ($client['stderr'] ?? '') . stream_get_contents($client['pipes'][2]);
                throw new RuntimeException('codemcp: MCP server exited before response: ' . trim((string) $stderr));
            }
            $chunk = stream_get_contents($client['pipes'][1]);
            $stderr_chunk = stream_get_contents($client['pipes'][2]);
            if ($chunk !== false && $chunk !== '') {
                $client['buffer'] .= $chunk;
                $last_activity = microtime(true);
            }
            if ($stderr_chunk !== false && $stderr_chunk !== '') {
                $client['stderr'] = ($client['stderr'] ?? '') . $stderr_chunk;
                $last_activity = microtime(true);
            }
            while (str_contains($client['buffer'], "\n")) {
                [$line, $client['buffer']] = explode("\n", $client['buffer'], 2);
                $message = json_decode(trim($line), true);
                if (!is_array($message) || ($message['id'] ?? null) !== $id) {
                    // codex streams progress notifications (codex/event) while
                    // the request runs — mirror them into the session log so
                    // status/wait pollers see live activity
                    if (is_array($message) && isset($message['method']) && ($client['event_log'] ?? null) !== null) {
                        $event = (string) ($message['params']['msg']['type'] ?? $message['method']);
                        file_put_contents(
                            $client['event_log'],
                            '[' . date('H:i:s') . '] ' . $event . "\n",
                            FILE_APPEND
                        );
                    }
                    continue;
                }
                if (isset($message['error'])) {
                    throw new RuntimeException('codemcp: MCP error: ' . json_encode($message['error']));
                }
                return $message;
            }
            usleep(10000);
        }
        throw new RuntimeException(
            'codemcp: MCP request produced no activity for ' . $this->config['timeout'] . ' seconds.'
        );
    }

    private function closeStdioMcp(array $client): void
    {
        foreach ($client['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($client['process'])) {
            proc_terminate($client['process']);
            proc_close($client['process']);
        }
    }

    private function runCommand(array $command, ?string $workdir, array $extra_env = [], ?string $event_log = null): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ],
            $pipes,
            $workdir,
            $this->processEnv($extra_env)
        );
        if (!is_resource($process)) {
            throw new RuntimeException('codemcp: failed to start command: ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $event_buffer = '';
        $last_activity = microtime(true);

        while ((microtime(true) - $last_activity) <= $this->config['timeout']) {
            $stdout_chunk = (string) stream_get_contents($pipes[1]);
            $stderr_chunk = (string) stream_get_contents($pipes[2]);
            $stdout .= $stdout_chunk;
            $stderr .= $stderr_chunk;
            if ($stdout_chunk !== '' || $stderr_chunk !== '') {
                $last_activity = microtime(true);
            }
            if ($event_log !== null && $stdout_chunk !== '') {
                $event_buffer .= $stdout_chunk;
                while (($newline_position = strpos($event_buffer, "\n")) !== false) {
                    $line = substr($event_buffer, 0, $newline_position);
                    $event_buffer = substr($event_buffer, $newline_position + 1);
                    $event = json_decode(trim($line), true);
                    if (is_array($event) && isset($event['type'])) {
                        file_put_contents($event_log, '[' . date('H:i:s') . '] ' . $event['type'] . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
            }
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
                if ($event_log !== null && trim($event_buffer) !== '') {
                    $event = json_decode(trim($event_buffer), true);
                    if (is_array($event) && isset($event['type'])) {
                        file_put_contents($event_log, '[' . date('H:i:s') . '] ' . $event['type'] . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                $exit_code = (int) ($status['exitcode'] ?? -1);
                $closed_exit_code = proc_close($process);
                if ($exit_code === -1) {
                    $exit_code = $closed_exit_code;
                }
                if ($exit_code !== 0) {
                    $details = trim($stderr);
                    if ($details === '') {
                        $payload = json_decode(trim($stdout), true);
                        if (is_array($payload)) {
                            $details = trim((string) ($payload['error'] ?? $payload['message'] ?? $payload['result'] ?? ''));
                        }
                    }
                    if ($details === '') {
                        $details = trim($stdout);
                    }
                    if ($details === '') {
                        $details = 'no diagnostic output';
                    }
                    throw new RuntimeException(
                        'codemcp: command failed (exit code ' . $exit_code . '): ' . substr($details, 0, 4000)
                    );
                }
                return $this->normalizeCliResult($stdout);
            }
            usleep(10000);
        }

        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('codemcp: command produced no activity for ' . $this->config['timeout'] . ' seconds.');
    }

    private function runInteractiveCommand(array $command, ?string $workdir, array $extra_env = [], ?string $event_log = null): array
    {
        $script = implode(' ', array_map('escapeshellarg', $command));
        if ($workdir !== null) {
            $script = 'cd ' . escapeshellarg($workdir) . ' && ' . $script;
        }
        return $this->runCommand(['bash', '-ic', $script], null, $extra_env, $event_log);
    }

    private function normalizeMcpResult(array $result, string $tool): array
    {
        $structured = $result['structuredContent'] ?? null;
        $content = is_array($structured)
            ? ($structured['content'] ?? null)
            : $this->textContent($result['content'] ?? null);
        $decodedContent = is_string($content) ? json_decode(trim($content), true) : null;
        $errorPayload = is_array($structured) ? $structured : $decodedContent;
        if (
            ($result['isError'] ?? false) === true ||
            (is_array($errorPayload) && ($errorPayload['type'] ?? null) === 'error') ||
            (is_array($errorPayload) && (int) ($errorPayload['status'] ?? 0) >= 400)
        ) {
            $error = is_array($errorPayload) ? ($errorPayload['error'] ?? null) : null;
            $message = is_array($error) ? ($error['message'] ?? null) : $error;
            if (!is_string($message) || trim($message) === '') {
                $message = is_string($content) && trim($content) !== '' ? $content : 'Codex tool execution failed.';
            }
            throw new RuntimeException('codemcp: ' . $message);
        }
        return [
            'provider' => 'codex',
            'tool' => $tool,
            'thread_id' => is_array($structured)
                ? ($structured['threadId'] ?? $structured['conversationId'] ?? null)
                : ($result['threadId'] ?? $result['conversationId'] ?? null),
            'content' => $content,
            'raw' => $result
        ];
    }

    private function normalizeCliResult(string $stdout): array
    {
        $stdout = trim($stdout);
        $data = json_decode($stdout, true);
        if (!is_array($data)) {
            return [
                'provider' => 'claude',
                'tool' => 'claude',
                'thread_id' => null,
                'content' => $stdout,
                'raw' => $stdout
            ];
        }
        return [
            'provider' => 'claude',
            'tool' => 'claude',
            'thread_id' => $data['session_id'] ?? $data['sessionId'] ?? null,
            'content' => $data['result'] ?? $data['content'] ?? $data['text'] ?? $stdout,
            'raw' => $data
        ];
    }

    private function textContent(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return null;
        }
        $parts = [];
        foreach ($content as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'text') {
                $parts[] = (string) ($item['text'] ?? '');
            }
        }
        return $parts === [] ? null : implode("\n", $parts);
    }

    private function normalizeProvider(?string $provider): string
    {
        $provider = strtolower(trim($provider ?: $this->config['provider']));
        if (!in_array($provider, ['codex', 'claude'], true)) {
            throw new RuntimeException('codemcp: unsupported provider: ' . $provider);
        }
        return $provider;
    }

    private function normalizeModel(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            throw new RuntimeException('codemcp: model must not be empty.');
        }
        return $model;
    }

    private function normalizeEffort(string $effort): string
    {
        $effort = strtolower(trim($effort));
        if ($effort === '') {
            throw new RuntimeException('codemcp: effort must not be empty.');
        }
        if (!in_array($effort, ['minimal', 'low', 'medium', 'high', 'xhigh'], true)) {
            throw new RuntimeException('codemcp: unsupported effort (use minimal|low|medium|high|xhigh): ' . $effort);
        }
        return $effort;
    }

    private function resolveWorkdir(?string $workdir): string
    {
        if ($workdir === null || trim($workdir) === '') {
            $workdir = rtrim(sys_get_temp_dir(), '/') . '/codemcp/' . $this->createUuid();
            if (!is_dir($workdir) && !mkdir($workdir, 0775, true) && !is_dir($workdir)) {
                throw new RuntimeException('codemcp: temporary workdir could not be created: ' . $workdir);
            }
        }
        $workdir = rtrim(trim($workdir), '/');
        if ($workdir === '') {
            throw new RuntimeException('codemcp: workdir must not be empty.');
        }
        if (!is_dir($workdir) && !mkdir($workdir, 0775, true) && !is_dir($workdir)) {
            throw new RuntimeException('codemcp: workdir could not be created: ' . $workdir);
        }
        return $workdir;
    }

    private function defaultConfig(): array
    {
        return [
            'provider' => self::DEFAULT_PROVIDER,
            'timeout' => self::DEFAULT_INACTIVITY_TIMEOUT,
            'session_dir' => $this->absolutePath('.codemcp/sessions')
        ];
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        $cwd = getcwd();
        return rtrim(is_string($cwd) ? $cwd : dirname(__DIR__), '/') . '/' . $path;
    }

    /**
     * Serialize read-modify-write cycles on a session file: the MCP process
     * (enqueueing prompts) and the detached runner (claiming prompts, writing
     * results) mutate the same session concurrently — without the lock, a
     * queued prompt could be lost to a stale overwrite.
     */
    private function withSessionLock(string $id, callable $fn): mixed
    {
        $this->ensureSessionDirectory();
        $handle = fopen($this->sessionPath($id) . '.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('codemcp: failed to open session lock: ' . $id);
        }
        try {
            flock($handle, LOCK_EX);
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function saveSession(array $session): array
    {
        $this->ensureSessionDirectory();
        $id = (string) ($session['id'] ?? $this->createUuid());
        $session['id'] = $id;
        $session['updated_at'] = date(DATE_ATOM);
        $session['created_at'] = $session['created_at'] ?? $session['updated_at'];
        $json = json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // atomic write (tmp + rename): the MCP process and the detached runner
        // both write session files — a torn read must never be possible
        $path = $this->sessionPath($id);
        $tmp = $path . '.tmp';
        if ($json === false || file_put_contents($tmp, $json) === false || rename($tmp, $path) === false) {
            throw new RuntimeException('codemcp: failed to write session: ' . $id);
        }
        return $session;
    }

    private function getSession(string $id): array
    {
        $path = $this->sessionPath($id);
        if (!is_file($path)) {
            throw new RuntimeException('codemcp: session not found: ' . $id);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException('codemcp: invalid session file: ' . $id);
        }
        return $data;
    }

    private function sessionStatus(): array
    {
        if (!is_dir($this->config['session_dir'])) {
            return ['sessions' => []];
        }
        $sessions = [];
        foreach (glob($this->config['session_dir'] . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $sessions[] = $this->refreshSession($data);
            }
        }
        usort($sessions, fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        return ['sessions' => $sessions];
    }

    private function ensureSessionDirectory(): void
    {
        if (!is_dir($this->config['session_dir']) && !mkdir($this->config['session_dir'], 0775, true) && !is_dir($this->config['session_dir'])) {
            throw new RuntimeException('codemcp: failed to create session directory: ' . $this->config['session_dir']);
        }
    }

    private function sessionPath(string $id): string
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $id)) {
            throw new RuntimeException('codemcp: invalid session id: ' . $id);
        }
        return rtrim($this->config['session_dir'], '/') . '/' . $id . '.json';
    }

    private function logPath(string $id): string
    {
        return preg_replace('/\.json$/', '.log', $this->sessionPath($id)) ?? $this->sessionPath($id) . '.log';
    }

    private function logLine(array $session, string $text): void
    {
        $path = (string) ($session['log_file'] ?? $this->logPath((string) $session['id']));
        file_put_contents($path, '[' . date('H:i:s') . '] ' . $text . "\n", FILE_APPEND);
    }

    private function logTail(array $session): ?string
    {
        $path = (string) ($session['log_file'] ?? $this->logPath((string) $session['id']));
        if (!is_file($path)) {
            return null;
        }
        $content = trim((string) file_get_contents($path));
        if ($content === '') {
            return null;
        }
        return strlen($content) > 2500 ? substr($content, -2500) : $content;
    }

    private function createUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function processEnv(array $extra = []): array
    {
        $env = getenv();
        return array_merge(is_array($env) ? $env : $_ENV, $extra);
    }

    private function projectDir(): string
    {
        $cwd = getcwd();
        return is_string($cwd) ? $cwd : dirname(__DIR__);
    }

    private function agentBinary(string $name): string
    {
        return $this->projectDir() . '/node_modules/.bin/' . $name;
    }
}
