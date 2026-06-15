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

    public function test__unknown_workdir_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        codemcp::create($this->config())->start(
            prompt: 'change something',
            workdir: $this->directory . '/missing',
            provider: 'codex'
        );
    }

    public function test__unknown_provider_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        codemcp::create($this->config())->start(
            prompt: 'review',
            workdir: $this->directory,
            provider: 'missing'
        );
    }

    public function test__status_lists_sessions(): void
    {
        $status = codemcp::create($this->config())->status();

        $this->assertSame([], $status['sessions']['sessions']);
    }

    private function config(): array
    {
        return [
            'provider' => 'codex',
            'workdir' => $this->directory,
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
