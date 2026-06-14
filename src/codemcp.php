<?php
declare(strict_types=1);

namespace vielhuber\codemcp;

use RuntimeException;
use vielhuber\simplemcp\Attributes\McpTool;

final class codemcp
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->loadEnvFile();
        $this->config = $config ?? $this->envConfig();
    }

    public static function create(?array $config = null): self
    {
        return new self(config: $config);
    }

    #[McpTool(name: 'start')]
    public function startTool(string $prompt, ?string $workdir = null, ?string $provider = null): array
    {
        return $this->start(prompt: $prompt, workdir: $workdir, provider: $provider);
    }

    #[McpTool(name: 'continue')]
    public function continueTool(string $session_id, string $prompt): array
    {
        return $this->continue(session_id: $session_id, prompt: $prompt);
    }

    #[McpTool(name: 'status')]
    public function statusTool(?string $session_id = null): array
    {
        return $this->status(session_id: $session_id);
    }

    #[McpTool(name: 'providers')]
    public function providersTool(): array
    {
        return $this->providers();
    }

    public function start(string $prompt, ?string $workdir = null, ?string $provider = null): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('codemcp: prompt must not be empty.');
        }

        $provider_name = $this->normalizeProvider($provider);
        $workdir = $this->resolveWorkdir($workdir);

        $result = match ($provider_name) {
            'codex' => $this->startCodex(prompt: $prompt, workdir: $workdir),
            'claude' => $this->startClaude(prompt: $prompt, workdir: $workdir),
            default => throw new RuntimeException('codemcp: unsupported provider: ' . $provider_name)
        };
        $session = $this->saveSession([
            'provider' => $provider_name,
            'workdir' => $workdir,
            'thread_id' => $result['thread_id'] ?? null,
            'last_content' => $result['content'] ?? null
        ]);

        return [
            'session_id' => $session['id'],
            'provider' => $provider_name,
            'workdir' => $workdir,
            'thread_id' => $session['thread_id'],
            'content' => $result['content'] ?? null,
            'raw' => $result['raw'] ?? $result
        ];
    }

    public function continue(string $session_id, string $prompt): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('codemcp: prompt must not be empty.');
        }

        $session = $this->getSession($session_id);
        $thread_id = (string) ($session['thread_id'] ?? '');
        if ($thread_id === '') {
            throw new RuntimeException('codemcp: session has no upstream thread id and cannot be continued.');
        }

        $provider_name = (string) $session['provider'];
        $result = match ($provider_name) {
            'codex' => $this->continueCodex(thread_id: $thread_id, prompt: $prompt),
            'claude' => $this->continueClaude(thread_id: $thread_id, prompt: $prompt),
            default => throw new RuntimeException('codemcp: unsupported provider: ' . $provider_name)
        };
        $session['thread_id'] = $result['thread_id'] ?? $thread_id;
        $session['last_content'] = $result['content'] ?? null;
        $session = $this->saveSession($session);

        return [
            'session_id' => $session['id'],
            'provider' => $provider_name,
            'thread_id' => $session['thread_id'],
            'content' => $result['content'] ?? null,
            'raw' => $result['raw'] ?? $result
        ];
    }

    public function status(?string $session_id = null): array
    {
        return [
            'config' => [
                'provider' => $this->config['provider'],
                'workdir' => $this->config['workdir'],
                'allow_write' => $this->config['allow_write'],
                'timeout' => $this->config['timeout']
            ],
            'sessions' => $this->sessionStatus($session_id)
        ];
    }

    public function providers(): array
    {
        return [
            'codex' => [
                'provider' => 'codex',
                'command' => 'codex mcp-server',
                'mode' => 'mcp-agent'
            ],
            'claude' => [
                'provider' => 'claude',
                'command' => 'claude -p',
                'mode' => 'cli-agent'
            ]
        ];
    }

    private function startCodex(string $prompt, string $workdir): array
    {
        return $this->callCodexTool('codex', [
            'prompt' => $prompt,
            'cwd' => $workdir,
            'sandbox' => $this->config['allow_write'] === true ? 'workspace-write' : 'read-only',
            'approval-policy' => 'never'
        ], $workdir);
    }

    private function continueCodex(string $thread_id, string $prompt): array
    {
        return $this->callCodexTool('codex-reply', [
            'threadId' => $thread_id,
            'prompt' => $prompt
        ], null);
    }

    private function startClaude(string $prompt, string $workdir): array
    {
        $thread_id = $this->createUuid();
        $permission_mode = $this->config['allow_write'] === true ? 'acceptEdits' : 'plan';
        $result = $this->runCommand([
            'claude',
            '-p',
            '--output-format',
            'json',
            '--session-id',
            $thread_id,
            '--permission-mode',
            $permission_mode,
            $prompt
        ], $workdir);
        $result['thread_id'] = $result['thread_id'] ?? $thread_id;
        return $result;
    }

    private function continueClaude(string $thread_id, string $prompt): array
    {
        $result = $this->runCommand([
            'claude',
            '--resume',
            $thread_id,
            '-p',
            '--output-format',
            'json',
            '--permission-mode',
            'acceptEdits',
            $prompt
        ], null);
        $result['thread_id'] = $result['thread_id'] ?? $thread_id;
        return $result;
    }

    private function callCodexTool(string $tool, array $arguments, ?string $workdir): array
    {
        $client = $this->startStdioMcp(command: 'codex', args: ['mcp-server'], workdir: $workdir);
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
            'buffer' => ''
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
        $started = time();
        while ((time() - $started) <= $this->config['timeout']) {
            $status = proc_get_status($client['process']);
            if (($status['running'] ?? false) === false) {
                $stderr = stream_get_contents($client['pipes'][2]);
                throw new RuntimeException('codemcp: MCP server exited before response: ' . trim((string) $stderr));
            }
            $chunk = stream_get_contents($client['pipes'][1]);
            if ($chunk !== false) {
                $client['buffer'] .= $chunk;
            }
            while (str_contains($client['buffer'], "\n")) {
                [$line, $client['buffer']] = explode("\n", $client['buffer'], 2);
                $message = json_decode(trim($line), true);
                if (!is_array($message) || ($message['id'] ?? null) !== $id) {
                    continue;
                }
                if (isset($message['error'])) {
                    throw new RuntimeException('codemcp: MCP error: ' . json_encode($message['error']));
                }
                return $message;
            }
            usleep(10000);
        }
        throw new RuntimeException('codemcp: MCP request timed out after ' . $this->config['timeout'] . ' seconds.');
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

    private function runCommand(array $command, ?string $workdir): array
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
            $this->processEnv()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('codemcp: failed to start command: ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = time();

        while ((time() - $started) <= $this->config['timeout']) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === false) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                $exit_code = proc_close($process);
                if ($exit_code !== 0) {
                    throw new RuntimeException('codemcp: command failed: ' . trim($stderr));
                }
                return $this->normalizeCliResult($stdout);
            }
            usleep(10000);
        }

        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('codemcp: command timed out after ' . $this->config['timeout'] . ' seconds.');
    }

    private function normalizeMcpResult(array $result, string $tool): array
    {
        $structured = $result['structuredContent'] ?? null;
        if (is_array($structured)) {
            return [
                'provider' => 'codex',
                'tool' => $tool,
                'thread_id' => $structured['threadId'] ?? $structured['conversationId'] ?? null,
                'content' => $structured['content'] ?? null,
                'raw' => $result
            ];
        }
        return [
            'provider' => 'codex',
            'tool' => $tool,
            'thread_id' => $result['threadId'] ?? $result['conversationId'] ?? null,
            'content' => $this->textContent($result['content'] ?? null),
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

    private function resolveWorkdir(?string $workdir): string
    {
        $workdir = rtrim(trim($workdir ?: $this->config['workdir']), '/');
        if ($workdir === '') {
            throw new RuntimeException('codemcp: workdir must not be empty.');
        }
        if (!is_dir($workdir)) {
            throw new RuntimeException('codemcp: workdir does not exist: ' . $workdir);
        }
        return $workdir;
    }

    private function envConfig(): array
    {
        return [
            'provider' => strtolower($this->env('CODEMCP_PROVIDER', 'codex')),
            'workdir' => $this->env('CODEMCP_WORKDIR', getcwd() ?: dirname(__DIR__)),
            'allow_write' => $this->boolEnv('CODEMCP_ALLOW_WRITE', false),
            'timeout' => max(1, (int) $this->env('CODEMCP_TIMEOUT', '1800')),
            'session_dir' => $this->absolutePath($this->env('CODEMCP_SESSION_DIR', '.codemcp/sessions'))
        ];
    }

    private function loadEnvFile(): void
    {
        $cwd = getcwd();
        $paths = array_unique(array_filter([
            is_string($cwd) ? $cwd . '/.env' : null,
            dirname(__DIR__) . '/.env'
        ]));

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if ($key !== '' && getenv($key) === false) {
                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    private function env(string $key, string $default): string
    {
        $value = getenv($key);
        return $value === false ? $default : (string) $value;
    }

    private function boolEnv(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        $cwd = getcwd();
        return rtrim(is_string($cwd) ? $cwd : dirname(__DIR__), '/') . '/' . $path;
    }

    private function saveSession(array $session): array
    {
        $this->ensureSessionDirectory();
        $id = (string) ($session['id'] ?? $this->createUuid());
        $session['id'] = $id;
        $session['updated_at'] = date(DATE_ATOM);
        $session['created_at'] = $session['created_at'] ?? $session['updated_at'];
        $json = json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($this->sessionPath($id), $json) === false) {
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

    private function sessionStatus(?string $id): array
    {
        if ($id !== null && trim($id) !== '') {
            return $this->getSession($id);
        }
        if (!is_dir($this->config['session_dir'])) {
            return ['sessions' => []];
        }
        $sessions = [];
        foreach (glob($this->config['session_dir'] . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $sessions[] = $data;
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

    private function createUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function processEnv(): array
    {
        $env = getenv();
        return is_array($env) ? $env : $_ENV;
    }
}
