<?php

declare(strict_types=1);

namespace DeployKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

abstract class DeployTestCase extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @var list<string> */
    private array $tempDirs = [];

    private ?string $base = null;

    private ?string $source = null;

    private ?string $log = null;

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            (new Process(['rm', '-rf', $dir]))->mustRun();
        }
        $this->tempDirs = [];
        $this->base = $this->source = $this->log = null;
        parent::tearDown();
    }

    /** A fresh temp deploy path, one per test. */
    protected function basePath(): string
    {
        return $this->base ??= $this->makeTempDir('dk-base');
    }

    /** A per-test copy of tests/fixtures/app that tests may modify freely. */
    protected function sourcePath(): string
    {
        if ($this->source === null) {
            $this->source = $this->makeTempDir('dk-src');
            (new Process(['cp', '-R', __DIR__ . '/../fixtures/app/.', $this->source]))->mustRun();
            @chmod($this->source . '/artisan', 0755);
        }

        return $this->source;
    }

    /** @return list<string> commands the fake artisan received, in order */
    protected function artisanLog(): array
    {
        $file = $this->logFile();
        if (! is_file($file)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', file($file, FILE_IGNORE_NEW_LINES) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * Run `dep deploy` for the recipe against the localhost host.
     *
     * @param array<string, string> $env
     * @param list<string> $args extra dep arguments
     * @return array{exit:int, output:string}
     */
    protected function deploy(string $recipeFile, array $env = [], array $args = []): array
    {
        $process = new Process(
            [PHP_BINARY, self::ROOT . '/vendor/bin/dep', 'deploy', '-f', $recipeFile, 'localhost', ...$args],
            self::ROOT,
            array_merge([
                'DK_BASE' => $this->basePath(),
                'DK_SOURCE' => $this->sourcePath(),
                'DK_FAKE_LOG' => $this->logFile(),
            ], $env),
            null,
            300,
        );
        $process->run();

        return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput() . $process->getErrorOutput()];
    }

    /**
     * Run the fixture artisan the way a deploy would.
     *
     * @param array<string, string> $env
     */
    protected function runFakeArtisan(array $args, array $env = []): int
    {
        $process = new Process(
            [PHP_BINARY, $this->sourcePath() . '/artisan', ...$args],
            null,
            array_merge(['DK_FAKE_LOG' => $this->logFile()], $env),
        );
        $process->run();

        return (int) $process->getExitCode();
    }

    private function logFile(): string
    {
        return $this->log ??= $this->makeTempDir('dk-log') . '/artisan.log';
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);

        return $this->tempDirs[] = realpath($dir) ?: $dir;
    }
}
