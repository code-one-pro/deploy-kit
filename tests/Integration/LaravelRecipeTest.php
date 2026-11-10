<?php

declare(strict_types=1);

namespace DeployKit\Tests\Integration;

final class LaravelRecipeTest extends DeployTestCase
{
    private const RECIPE = __DIR__ . '/../fixtures/deploy-laravel.php';

    private function release(): string
    {
        $current = $this->currentTarget();
        $this->assertNotFalse($current);
        $this->assertSame(realpath($this->basePath() . '/releases'), dirname($current));

        return $current;
    }

    public function test_first_deploy_creates_layout(): void
    {
        $result = $this->deploy(self::RECIPE);
        $this->assertSame(0, $result['exit'], $result['output']);

        $base = $this->basePath();
        $this->assertDirectoryExists($base . '/releases');
        $this->assertDirectoryExists($base . '/shared/storage');
        $this->assertTrue(is_link($base . '/current'));
        $release = $this->release();
        $this->assertFileExists($release . '/artisan');
        foreach (['storage', '.env'] as $entry) {
            $this->assertTrue(is_link($release . '/' . $entry), $entry . ' is not a symlink');
            $this->assertSame(realpath($base . '/shared/' . $entry), realpath($release . '/' . $entry));
        }
    }

    public function test_never_uploads_secrets_or_state(): void
    {
        $src = $this->sourcePath();
        file_put_contents($src . '/.env', "APP_KEY=base64:topsecret\n");
        file_put_contents($src . '/.env.e2e', "E2E=1\n");
        foreach (['storage' => 'secret.txt', 'node_modules' => 'x', '.git' => 'HEAD', 'vendor' => 'x'] as $dir => $file) {
            mkdir($src . '/' . $dir, 0755, true);
            file_put_contents($src . '/' . $dir . '/' . $file, 'x');
        }

        $result = $this->deploy(self::RECIPE, ['DK_UPLOAD_PATHS' => 'artisan,public,storage,.env', 'DK_NO_VENDORS' => '1']);
        $this->assertSame(0, $result['exit'], $result['output']);

        $release = $this->release();
        foreach (['node_modules', '.git', 'vendor', '.env.e2e'] as $entry) {
            $this->assertFalse(file_exists($release . '/' . $entry) || is_link($release . '/' . $entry), $entry);
        }
        foreach (['storage', '.env'] as $entry) {
            $this->assertTrue(is_link($release . '/' . $entry), $entry . ' must stay the shared symlink');
        }
        $this->assertFileDoesNotExist($this->basePath() . '/shared/storage/secret.txt');
        $this->assertStringNotContainsString('topsecret', (string) @file_get_contents($this->basePath() . '/shared/.env'));
    }

    public function test_allowlist_limits_upload(): void
    {
        mkdir($this->sourcePath() . '/docs');
        file_put_contents($this->sourcePath() . '/docs/a.md', 'x');

        $result = $this->deploy(self::RECIPE, ['DK_UPLOAD_PATHS' => 'artisan,public', 'DK_NO_VENDORS' => '1']);
        $this->assertSame(0, $result['exit'], $result['output']);

        $release = $this->release();
        $this->assertFileExists($release . '/artisan');
        $this->assertFileExists($release . '/public/up.txt');
        $this->assertDirectoryDoesNotExist($release . '/docs');
        $this->assertFileDoesNotExist($release . '/composer.json');
    }

    public function test_extra_excludes_apply(): void
    {
        $result = $this->deploy(self::RECIPE, ['DK_UPLOAD_EXCLUDES' => 'composer.json', 'DK_NO_VENDORS' => '1']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertFileDoesNotExist($this->release() . '/composer.json');
        $this->assertFileExists($this->release() . '/artisan');
    }

    private const MIGRATION = '2026_01_01_000000_drop_x';

    private function addBreakingMigration(): void
    {
        $dir = $this->sourcePath() . '/database/migrations';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . self::MIGRATION . ".php", <<<'PHP'
<?php

return new class {
    public function up(): void
    {
        Schema::table('users', function ($table) {
            $table->dropColumn('legacy');
        });
    }
};
PHP);
    }

    public function test_runs_artisan_steps_in_order(): void
    {
        $result = $this->deploy(self::RECIPE);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame([
            'migrate:status --pending', 'migrate --force', 'config:cache', 'route:cache',
            'view:cache', 'event:cache', 'queue:restart',
        ], $this->artisanLog());
    }

    public function test_preflight_and_seeders_run_when_configured(): void
    {
        $result = $this->deploy(self::RECIPE, ['DK_PREFLIGHT' => 'deploy:requirements --preflight', 'DK_SEEDERS' => 'ASeeder']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $log = $this->artisanLog();
        $this->assertSame('deploy:requirements --preflight', $log[0]);
        $this->assertSame('migrate:status --pending', $log[1]);
        $this->assertSame('migrate --force', $log[2]);
        $this->assertSame('db:seed --class=ASeeder --force', $log[3]);
    }

    public function test_failed_migrate_leaves_current_untouched(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $before = readlink($this->basePath() . '/current');

        $failed = $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'migrate']);
        $this->assertNotSame(0, $failed['exit']);
        $this->assertSame($before, readlink($this->basePath() . '/current'));
        $this->assertFileDoesNotExist($this->basePath() . '/.dep/deploy.lock');
        $this->assertSame(1, count(array_keys($this->artisanLog(), 'queue:restart', true)), 'queue:restart only from the first deploy');

        $third = $this->deploy(self::RECIPE);
        $this->assertSame(0, $third['exit'], $third['output']);
        $this->assertNotSame($before, readlink($this->basePath() . '/current'));
    }

    public function test_failed_composer_leaves_current_untouched(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $before = readlink($this->basePath() . '/current');

        file_put_contents($this->sourcePath() . '/composer.json', '{ not json');
        $failed = $this->deploy(self::RECIPE);
        $this->assertNotSame(0, $failed['exit']);
        $this->assertSame($before, readlink($this->basePath() . '/current'));
        $this->assertFileDoesNotExist($this->basePath() . '/.dep/deploy.lock');

        file_put_contents($this->sourcePath() . '/composer.json', '{"name":"fixture/app","require":{}}');
        $third = $this->deploy(self::RECIPE);
        $this->assertSame(0, $third['exit'], $third['output']);
    }

    public function test_lock_held_by_another_deploy_is_not_released(): void
    {
        mkdir($this->basePath() . '/.dep', 0755, true);
        file_put_contents($this->basePath() . '/.dep/deploy.lock', 'someone');

        $result = $this->deploy(self::RECIPE);
        $this->assertNotSame(0, $result['exit']);
        $this->assertFileExists($this->basePath() . '/.dep/deploy.lock');
    }

    public function test_breaking_pending_migration_aborts_without_flag(): void
    {
        $this->addBreakingMigration();
        $result = $this->deploy(self::RECIPE, ['DK_PENDING' => self::MIGRATION]);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString(self::MIGRATION . '.php:7', $result['output']);
        $this->assertStringContainsString('--allow-breaking', $result['output']);
        $this->assertNotContains('migrate --force', $this->artisanLog());
        $this->assertFalse(is_link($this->basePath() . '/current'));
    }

    public function test_breaking_pending_migration_proceeds_with_flag(): void
    {
        $this->addBreakingMigration();
        $result = $this->deploy(self::RECIPE, ['DK_PENDING' => self::MIGRATION], ['--allow-breaking']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertContains('migrate --force', $this->artisanLog());
    }

    public function test_pending_migration_without_local_file_is_skipped(): void
    {
        $result = $this->deploy(self::RECIPE, ['DK_PENDING' => '2026_01_01_000000_gone']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertContains('migrate --force', $this->artisanLog());
    }

    public function test_failing_pending_check_warns_and_continues(): void
    {
        $result = $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'migrate:status']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('migration check was SKIPPED', $result['output']);
        $this->assertContains('migrate --force', $this->artisanLog());
    }

    public function test_keeps_five_releases(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $result = $this->deploy(self::RECIPE);
            $this->assertSame(0, $result['exit'], $result['output']);
        }
        $this->assertCount(5, glob($this->basePath() . '/releases/*') ?: []);
    }

    /** Where `current` points now; PHP caches realpath() results, which goes stale as the link moves. */
    private function currentTarget(): string|false
    {
        clearstatcache(true);

        return realpath($this->basePath() . '/current');
    }

    private function lastLogLine(): string
    {
        $log = $this->artisanLog();

        return end($log) ?: '';
    }

    private function releaseCount(): int
    {
        return count(glob($this->basePath() . '/releases/*') ?: []);
    }

    public function test_healthy_deploy_passes(): void
    {
        $result = $this->deploy(self::RECIPE);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringNotContainsString('ROLLED BACK', $result['output']);
    }

    public function test_failed_health_rolls_back(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $first = $this->currentTarget();
        $logged = count($this->artisanLog());

        unlink($this->sourcePath() . '/public/up.txt');
        $failed = $this->deploy(self::RECIPE);
        $this->assertNotSame(0, $failed['exit']);
        $this->assertSame($first, $this->currentTarget());
        $this->assertStringContainsString('ROLLED BACK to ' . basename($first) . ': health check failed', $failed['output']);
        $this->assertFileDoesNotExist($this->basePath() . '/.dep/deploy.lock');

        $second = array_slice($this->artisanLog(), $logged);
        $this->assertSame(2, count(array_keys($second, 'queue:restart', true)), 'once after the switch, once after the rollback');
        $this->assertSame('queue:restart', end($second));

        file_put_contents($this->sourcePath() . '/public/up.txt', 'ok');
        $third = $this->deploy(self::RECIPE);
        $this->assertSame(0, $third['exit'], $third['output']);
        $this->assertNotSame($first, $this->currentTarget(), $third['output']);
    }

    public function test_bad_release_is_not_a_later_rollback_target(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $first = basename((string) $this->currentTarget());

        unlink($this->sourcePath() . '/public/up.txt');
        $this->assertNotSame(0, $this->deploy(self::RECIPE)['exit']);
        $this->assertFileExists($this->basePath() . '/releases/' . ($first + 1) . '/BAD_RELEASE');

        // Release 3 fails too: the bad release 2 must be skipped, so the target is release 1.
        $failed = $this->deploy(self::RECIPE);
        $this->assertNotSame(0, $failed['exit']);
        $this->assertSame(realpath($this->basePath() . '/releases/' . $first), $this->currentTarget());
        $this->assertStringContainsString('ROLLED BACK to ' . $first . ':', $failed['output']);
        $this->assertSame(3, $this->releaseCount());
    }

    public function test_failed_health_on_first_deploy_changes_nothing(): void
    {
        unlink($this->sourcePath() . '/public/up.txt');
        $result = $this->deploy(self::RECIPE);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('Health check failed and there is no previous release to roll back to', $result['output']);
        $this->assertStringNotContainsString('ROLLED BACK', $result['output']);
        $this->assertSame(1, $this->releaseCount());
        $this->assertSame(realpath($this->basePath() . '/releases/1'), $this->currentTarget());
        $this->assertFileDoesNotExist($this->basePath() . '/.dep/deploy.lock');
    }

    public function test_manual_rollback_restarts_queue(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $first = $this->currentTarget();
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);

        $result = $this->runDep('rollback', self::RECIPE);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame($first, $this->currentTarget());
        $this->assertSame('queue:restart', $this->lastLogLine());
    }

    public function test_opcache_clear_is_not_attempted_without_a_socket(): void
    {
        $result = $this->deploy(self::RECIPE, [], ['-vvv']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('config:cache', $result['output'], 'verbose output must show commands');
        $this->assertStringNotContainsString('opcache:reset', $result['output']);
    }

    public function test_lock_taken_after_unlock_is_not_released_by_a_late_failure(): void
    {
        $result = $this->deploy(self::RECIPE, ['DK_AFTER_UNLOCK_FAILS' => '1']);
        $this->assertNotSame(0, $result['exit']);
        $this->assertFileExists($this->basePath() . '/.dep/deploy.lock');
    }
}
