<?php

declare(strict_types=1);

namespace DeployKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SchedulerRunScriptTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../templates/scheduler-run.sh';

    private const FAKE_ARTISAN = __DIR__ . '/../fixtures/app/artisan';

    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/dk-sched-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/shared/storage', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->base]))->mustRun();
    }

    private function installArtisan(): void
    {
        mkdir($this->base . '/current', 0777, true);
        copy(self::FAKE_ARTISAN, $this->base . '/current/artisan');
    }

    /** @param array<string, string> $env */
    private function runScript(array $args, array $env = []): Process
    {
        $process = new Process(['sh', self::SCRIPT, ...$args], null, $env + ['DK_FAKE_LOG' => $this->base . '/artisan.log']);
        $process->run();

        return $process;
    }

    /** @return list<string> */
    private function artisanLog(): array
    {
        $file = $this->base . '/artisan.log';

        return is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)))) : [];
    }

    public function test_noop_without_current(): void
    {
        $process = $this->runScript([$this->base]);

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame([], $this->artisanLog());
        $this->assertFileDoesNotExist($this->base . '/shared/storage/scheduler.heartbeat');
    }

    public function test_noop_when_paused(): void
    {
        $this->installArtisan();
        touch($this->base . '/shared/storage/scheduler.paused');

        $process = $this->runScript([$this->base]);

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame([], $this->artisanLog());
        $this->assertFileDoesNotExist($this->base . '/shared/storage/scheduler.heartbeat');
    }

    public function test_runs_and_writes_heartbeat(): void
    {
        $this->installArtisan();

        $process = $this->runScript([$this->base]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(['schedule:run'], $this->artisanLog());
        $heartbeat = trim((string) file_get_contents($this->base . '/shared/storage/scheduler.heartbeat'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $heartbeat);
    }

    public function test_failed_run_writes_no_heartbeat(): void
    {
        $this->installArtisan();

        $process = $this->runScript([$this->base], ['DK_FAIL_ON' => 'schedule:run']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertFileDoesNotExist($this->base . '/shared/storage/scheduler.heartbeat');
    }

    public function test_usage_error_without_exactly_one_argument(): void
    {
        $none = $this->runScript([]);
        $two = $this->runScript([$this->base, 'extra']);

        $this->assertSame(2, $none->getExitCode());
        $this->assertStringContainsString('usage', strtolower($none->getErrorOutput()));
        $this->assertSame(2, $two->getExitCode());
    }
}
