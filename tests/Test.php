<?php
declare(strict_types=1);

use vielhuber\codemcp\codemcp;

final class Test extends \PHPUnit\Framework\TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/codemcp_' . uniqid();
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function test__missing_explicit_workdir_is_created(): void
    {
        $workdir = $this->directory . '/new/project';
        $code = codemcp::create($this->config());
        $session = $code->start(
            prompt: 'change something',
            model: 'test-model',
            effort: 'high',
            workdir: $workdir,
            provider: 'codex'
        );

        $this->assertDirectoryExists($workdir);
        $this->assertSame($workdir, $session['workdir']);
        $this->waitUntilFinished($code, $session['session_id']);
    }

    public function test__unknown_provider_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        codemcp::create($this->config())->start(
            prompt: 'review',
            model: 'test-model',
            effort: 'high',
            workdir: $this->directory,
            provider: 'missing'
        );
    }

    public function test__missing_workdir_uses_a_random_isolated_temporary_directory(): void
    {
        $temporaryWorkdir = null;
        try {
            $code = codemcp::create($this->config());
            $session = $code->start(
                prompt: 'review',
                model: 'test-model',
                effort: 'high',
                provider: 'codex'
            );
            $temporaryWorkdir = $session['workdir'];
            $this->assertMatchesRegularExpression(
                '#^' . preg_quote(sys_get_temp_dir(), '#') . '/codemcp/[a-f0-9-]{36}$#',
                $temporaryWorkdir
            );
            $session = $this->waitUntilFinished($code, $session['session_id']);
            $this->assertSame('error', $session['status']);
        } finally {
            if ($temporaryWorkdir !== null) {
                $this->removeDirectory($temporaryWorkdir);
            }
        }
    }

    public function test__explicit_workdir_is_used(): void
    {
        $code = codemcp::create($this->config());
        $session = $code->start(
            prompt: 'review',
            model: 'test-model',
            effort: 'high',
            workdir: $this->directory,
            provider: 'codex'
        );

        $this->assertSame($this->directory, $session['workdir']);
        $this->waitUntilFinished($code, $session['session_id']);
    }

    public function test__status_lists_sessions(): void
    {
        $status = codemcp::create($this->config())->status();

        $this->assertSame([], $status['sessions']['sessions']);
    }

    public function test__invalid_effort_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        codemcp::create($this->config())->start(
            prompt: 'review',
            model: 'test-model',
            workdir: $this->directory,
            provider: 'codex',
            effort: 'ultra'
        );
    }

    public function test__empty_model_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        codemcp::create($this->config())->start(
            prompt: 'review',
            model: '',
            effort: 'high',
            workdir: $this->directory,
            provider: 'codex'
        );
    }

    public function test__empty_effort_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        codemcp::create($this->config())->start(
            prompt: 'review',
            model: 'test-model',
            effort: '',
            workdir: $this->directory,
            provider: 'codex'
        );
    }

    public function test__model_and_effort_are_required_tool_arguments(): void
    {
        $parameters = [];
        foreach ((new ReflectionMethod(codemcp::class, 'startTool'))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        $this->assertFalse($parameters['model']->isOptional());
        $this->assertFalse($parameters['effort']->isOptional());
    }

    public function test__continue_on_running_session_enqueues(): void
    {
        $this->writeSession('running-1', [
            'status' => 'running',
            'pid' => getmypid(),
            'thread_id' => 'abc',
            'started_at' => date(DATE_ATOM)
        ]);
        $code = codemcp::create($this->config());
        $session = $code->continue(session_id: 'running-1', prompt: 'first follow-up');
        $this->assertSame('running', $session['status']);
        $this->assertSame(['first follow-up'], $session['queue']);

        $session = $code->continue(session_id: 'running-1', prompt: 'second follow-up');
        $this->assertSame(['first follow-up', 'second follow-up'], $session['queue']);
        $this->assertStringContainsString('queued (position 2)', (string) $session['hint']);
    }

    public function test__start_on_running_folder_session_enqueues(): void
    {
        $this->writeSession('older', [
            'status' => 'running',
            'pid' => getmypid(),
            'provider' => 'codex',
            'workdir' => $this->directory,
            'thread_id' => 'thread-old',
            'started_at' => date(DATE_ATOM)
        ]);
        $session = codemcp::create($this->config())->start(
            prompt: 'more work',
            model: 'test-model',
            effort: 'high',
            workdir: $this->directory,
            provider: 'codex'
        );

        $this->assertSame('older', $session['session_id']);
        $this->assertSame('running', $session['status']);
        $this->assertSame(['more work'], $session['queue']);
    }

    public function test__start_continues_existing_folder_session(): void
    {
        $this->writeSession('folder-done', [
            'status' => 'completed',
            'provider' => 'codex',
            'workdir' => $this->directory,
            'thread_id' => 'thread-1'
        ]);
        $session = codemcp::create($this->config())->start(
            prompt: 'again',
            model: 'test-model',
            effort: 'high',
            workdir: $this->directory,
            provider: 'codex'
        );

        $this->assertSame('folder-done', $session['session_id']);
        $this->assertSame('running', $session['status']);
        $this->assertSame(['again'], $session['queue']);
    }

    public function test__start_in_fresh_folder_starts_new_thread_and_runner_reports_errors(): void
    {
        // the repo has no agent binaries in node_modules — the detached runner
        // must surface the spawn failure as a terminal error state
        $code = codemcp::create($this->config());
        $session = $code->start(
            prompt: 'fresh task',
            model: 'test-model',
            effort: 'high',
            workdir: $this->directory,
            provider: 'codex'
        );
        $this->assertSame('start', $session['mode']);
        $this->assertSame('running', $session['status']);
        $session = $this->waitUntilFinished($code, $session['session_id']);
        $this->assertSame('error', $session['status']);
        $this->assertNotSame('', (string) $session['error']);
    }

    public function test__status_self_heals_dead_runner(): void
    {
        $this->writeSession('dead-1', [
            'status' => 'running',
            'pid' => 99999999,
            'started_at' => date(DATE_ATOM, time() - 3600)
        ]);
        $status = codemcp::create($this->config())->status(session_id: 'dead-1');

        $this->assertSame('error', $status['status']);
    }

    public function test__stop_requires_running_session(): void
    {
        $this->writeSession('done-1', [
            'status' => 'completed',
            'last_content' => 'result'
        ]);
        $this->expectException(RuntimeException::class);
        codemcp::create($this->config())->stop(session_id: 'done-1');
    }

    public function test__wait_returns_finished_session_immediately(): void
    {
        $this->writeSession('done-2', [
            'status' => 'completed',
            'last_content' => 'the answer'
        ]);
        $session = codemcp::create($this->config())->wait(session_id: 'done-2', timeout: 30);

        $this->assertSame('completed', $session['status']);
        $this->assertSame('the answer', $session['last_content']);
    }

    public function test__legacy_session_without_status_counts_as_completed(): void
    {
        $this->writeSession('legacy-1', [
            'last_content' => 'old result',
            'thread_id' => 'xyz'
        ]);
        $status = codemcp::create($this->config())->status(session_id: 'legacy-1');

        $this->assertSame('completed', $status['status']);
    }

    private function writeSession(string $id, array $data): void
    {
        $dir = $this->directory . '/sessions';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $data['id'] = $id;
        $data['updated_at'] = $data['updated_at'] ?? date(DATE_ATOM);
        file_put_contents($dir . '/' . $id . '.json', json_encode($data, JSON_PRETTY_PRINT));
    }

    private function waitUntilFinished(codemcp $code, string $sessionId): array
    {
        $deadline = time() + 30;
        do {
            $session = $code->wait($sessionId, 5);
        } while ($session['status'] === 'running' && time() < $deadline);
        return $session;
    }

    private function config(): array
    {
        return [
            'provider' => 'codex',
            'timeout' => 1,
            'session_dir' => $this->directory . '/sessions'
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }
            unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
