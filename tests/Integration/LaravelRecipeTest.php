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

    public function test_php_older_than_the_minimum_stops_the_deploy_before_a_release_exists(): void
    {
        $result = $this->deploy(self::RECIPE, ['DK_PHP_MINIMUM' => '99.0.0']);

        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('this deploy needs 99.0.0 or later', $result['output']);
        $this->assertSame([], glob($this->basePath() . '/releases/*') ?: []);
        $this->assertFileDoesNotExist($this->basePath() . '/.dep/deploy.lock');
        $this->assertSame([], $this->artisanLog());
    }

    public function test_php_check_is_skipped_without_a_minimum(): void
    {
        $result = $this->deploy(self::RECIPE, ['DK_PHP_MINIMUM' => '']);

        $this->assertSame(0, $result['exit'], $result['output']);
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

    public function test_whole_tree_upload_never_carries_secrets_or_local_state(): void
    {
        $src = $this->sourcePath();
        file_put_contents($src . '/.env', "APP_KEY=base64:topsecret\n");
        file_put_contents($src . '/.env.e2e', "E2E=1\n");
        $files = [
            'storage/x', 'node_modules/x', '.git/HEAD', 'vendor/x', 'bootstrap/cache/config.php',
            'public/hot', 'database/database.sqlite', 'database/database.sqlite-journal',
        ];
        foreach ($files as $file) {
            @mkdir(dirname($src . '/' . $file), 0755, true);
            file_put_contents($src . '/' . $file, 'workstation-only');
        }

        $result = $this->deploy(self::RECIPE, ['DK_NO_VENDORS' => '1']);
        $this->assertSame(0, $result['exit'], $result['output']);

        $release = $this->release();
        foreach (['.env.e2e', 'node_modules', '.git', 'vendor', 'public/hot', 'database/database.sqlite', 'database/database.sqlite-journal'] as $entry) {
            $this->assertFalse(file_exists($release . '/' . $entry) || is_link($release . '/' . $entry), $entry . ' was uploaded');
        }
        // config:cache writes its own bootstrap/cache/config.php in the release; the local one must not arrive.
        $this->assertStringNotContainsString('workstation-only', (string) @file_get_contents($release . '/bootstrap/cache/config.php'));
        $this->assertFileDoesNotExist($this->basePath() . '/shared/storage/x');
        $this->assertStringNotContainsString('topsecret', (string) @file_get_contents($this->basePath() . '/shared/.env'));
        $this->assertFileExists($release . '/artisan');
    }

    public function test_public_storage_links_into_shared_and_the_local_link_is_not_uploaded(): void
    {
        $elsewhere = $this->sourcePath() . '/elsewhere';
        mkdir($elsewhere);
        symlink($elsewhere, $this->sourcePath() . '/public/storage');

        $result = $this->deploy(self::RECIPE, ['DK_NO_VENDORS' => '1']);
        $this->assertSame(0, $result['exit'], $result['output']);

        $link = $this->release() . '/public/storage';
        $this->assertTrue(is_link($link), 'public/storage is not a link');
        $this->assertSame($this->basePath() . '/shared/storage/app/public', readlink($link));
    }

    public function test_local_public_storage_link_is_not_uploaded_even_without_the_link_step(): void
    {
        $elsewhere = $this->sourcePath() . '/elsewhere';
        mkdir($elsewhere);
        symlink($elsewhere, $this->sourcePath() . '/public/storage');

        $result = $this->deploy(self::RECIPE, ['DK_NO_VENDORS' => '1', 'DK_STORAGE_LINK' => '0']);
        $this->assertSame(0, $result['exit'], $result['output']);

        $link = $this->release() . '/public/storage';
        $this->assertFalse(file_exists($link) || is_link($link), 'the workstation link was uploaded');
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

    public function test_compiled_views_live_in_each_release(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $first = $this->release();
        $second = $this->deploy(self::RECIPE);
        $this->assertSame(0, $second['exit'], $second['output']);
        $secondRelease = $this->release();
        $this->assertNotSame($first, $secondRelease);

        $byCommand = [];
        foreach ($this->artisanEnvLog() as [$command, $value]) {
            $byCommand[$command][] = $value;
        }
        foreach (['config:cache', 'view:cache'] as $command) {
            $this->assertSame(
                [$first . '/bootstrap/cache/views', $secondRelease . '/bootstrap/cache/views'],
                $byCommand[$command] ?? [],
                $command . ' must get its own release\'s view path',
            );
        }
        $this->assertDirectoryExists($secondRelease . '/bootstrap/cache/views');
        $this->assertSame([], glob($this->basePath() . '/shared/storage/framework/views/*') ?: []);
    }

    public function test_view_path_outside_the_release_stops_before_the_switch(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $before = readlink($this->basePath() . '/current');
        $shared = $this->basePath() . '/shared/storage/framework/views';

        $failed = $this->deploy(self::RECIPE, ['DK_FAKE_VIEW_PATH' => $shared]);
        $this->assertNotSame(0, $failed['exit']);
        $this->assertStringContainsString(
            'view.compiled is not inside this release (' . $shared . '). The app must read VIEW_COMPILED_PATH in config/view.php.',
            $failed['output'],
        );
        $this->assertSame($before, readlink($this->basePath() . '/current'));
        $this->assertFileDoesNotExist($this->basePath() . '/.dep/deploy.lock');
        $this->assertNotContains('route:cache', array_slice($this->artisanLog(), 7), 'the deploy stops right after config:cache');
    }

    public function test_view_path_check_can_be_turned_off(): void
    {
        $result = $this->deploy(self::RECIPE, [
            'DK_FAKE_VIEW_PATH' => $this->basePath() . '/shared/storage/framework/views',
            'DK_VERIFY_VIEW_PATH' => '0',
        ]);
        $this->assertSame(0, $result['exit'], $result['output']);
    }

    public function test_namespaced_seeder_class_survives_the_shell(): void
    {
        $result = $this->deploy(self::RECIPE, ['DK_SEEDERS' => 'Database\\Seeders\\RolesSeeder']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertContains('db:seed --class=Database\\Seeders\\RolesSeeder --force', $this->artisanLog());
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

    public function test_failing_pending_check_stops_when_a_release_is_live(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $before = readlink($this->basePath() . '/current');

        $result = $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'migrate:status']);
        $this->assertNotSame(0, $result['exit']);
        foreach (['migrate:status --pending', 'sudo', 'database', '--allow-breaking'] as $part) {
            $this->assertStringContainsString($part, $result['output']);
        }
        $this->assertSame($before, readlink($this->basePath() . '/current'));
        $this->assertSame(1, count(array_keys($this->artisanLog(), 'migrate --force', true)));
        $this->assertFileDoesNotExist($this->basePath() . '/.dep/deploy.lock');
    }

    public function test_failing_pending_check_with_allow_breaking_warns_and_continues(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);

        $result = $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'migrate:status'], ['--allow-breaking']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('migration check was SKIPPED', $result['output']);
        $this->assertSame(2, count(array_keys($this->artisanLog(), 'migrate --force', true)));
    }

    public function test_failure_after_migrate_says_the_schema_moved(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);

        $result = $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'route:cache']);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString(
            'NOTE: migrations already ran. The database schema is ahead of the live release; the live code keeps working only if those migrations were additive.',
            $result['output'],
        );
    }

    public function test_failure_before_migrate_says_nothing_about_the_schema(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);

        $result = $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'migrate']);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringNotContainsString('NOTE: migrations already ran', $result['output']);
    }

    public function test_failing_pending_check_on_a_first_deploy_warns_and_continues(): void
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

    public function test_failed_releases_do_not_push_the_rollback_target_out(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        for ($i = 0; $i < 4; $i++) {
            $this->assertNotSame(0, $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'migrate'])['exit']);
        }
        $last = $this->deploy(self::RECIPE);
        $this->assertSame(0, $last['exit'], $last['output']);
        $this->assertDirectoryExists($this->basePath() . '/releases/1');

        $result = $this->runDep('rollback', self::RECIPE);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(realpath($this->basePath() . '/releases/1'), $this->currentTarget());
    }

    public function test_bad_and_unfinished_releases_are_removed_by_the_next_good_deploy(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $this->assertNotSame(0, $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'migrate'])['exit']);
        unlink($this->sourcePath() . '/public/up.txt');
        $this->assertNotSame(0, $this->deploy(self::RECIPE)['exit']);
        file_put_contents($this->sourcePath() . '/public/up.txt', 'ok');
        $this->assertFileExists($this->basePath() . '/releases/2/BAD_RELEASE');
        $this->assertFileExists($this->basePath() . '/releases/3/BAD_RELEASE');
        mkdir($this->basePath() . '/releases/77'); // an unfinished directory Deployer never logged

        $result = $this->deploy(self::RECIPE);
        $this->assertSame(0, $result['exit'], $result['output']);

        $left = array_map('basename', glob($this->basePath() . '/releases/*') ?: []);
        sort($left);
        $this->assertSame(['1', '4'], $left);
        $this->assertFalse(is_link($this->basePath() . '/release'));
    }

    /** Prepare shared/ the way the runbook's step 3 leaves it. */
    private function prepareShared(bool $env = true, bool $envHasContent = true, bool $storage = true): void
    {
        $shared = $this->basePath() . '/shared';
        @mkdir($shared, 0755, true);
        if ($env) {
            file_put_contents($shared . '/.env', $envHasContent ? "APP_ENV=production\n" : '');
        }
        if ($storage) {
            @mkdir($shared . '/storage', 0755, true);
        }
    }

    /** @return array<string, array{bool, bool, bool, string}> */
    public static function missingSharedState(): array
    {
        return [
            'missing .env' => [false, true, true, 'shared/.env is missing or empty'],
            'empty .env' => [true, false, true, 'shared/.env is missing or empty'],
            'missing storage' => [true, true, false, 'shared/storage is missing'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingSharedState')]
    public function test_missing_shared_state_stops_the_deploy(bool $env, bool $content, bool $storage, string $message): void
    {
        // A live release first, then the shared state goes missing.
        $this->prepareShared();
        $this->assertSame(0, $this->deploy(self::RECIPE, ['DK_REQUIRE_SHARED' => '1'])['exit']);
        $before = readlink($this->basePath() . '/current');
        $logged = count($this->artisanLog());

        $shared = $this->basePath() . '/shared';
        if (! $env) {
            unlink($shared . '/.env');
        } elseif (! $content) {
            file_put_contents($shared . '/.env', '');
        }
        if (! $storage) {
            (new \Symfony\Component\Process\Process(['rm', '-rf', $shared . '/storage']))->mustRun();
        }

        $result = $this->deploy(self::RECIPE, ['DK_REQUIRE_SHARED' => '1']);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString($message, $result['output']);
        $this->assertStringContainsString('docs/cutover-runbook.md', $result['output']);
        $this->assertStringNotContainsString('APP_ENV', $result['output']);
        $this->assertSame($before, readlink($this->basePath() . '/current'));
        $this->assertFileDoesNotExist($this->basePath() . '/.dep/deploy.lock');
        $this->assertSame($logged, count($this->artisanLog()), 'no artisan step ran');
        $this->assertCount(1, glob($this->basePath() . '/releases/*') ?: [], 'no release was created');
        if (! $storage) {
            $this->assertDirectoryDoesNotExist($shared . '/storage', 'deploy:shared must not create an empty storage');
        }
    }

    public function test_present_shared_state_lets_the_deploy_proceed(): void
    {
        $this->prepareShared();
        $result = $this->deploy(self::RECIPE, ['DK_REQUIRE_SHARED' => '1']);
        $this->assertSame(0, $result['exit'], $result['output']);
    }

    /** Where `current` points now; PHP caches realpath() results, which goes stale as the link moves. */
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

    public function test_failing_queue_restart_after_the_switch_lets_health_decide(): void
    {
        $result = $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'queue:restart']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('queue restart failed', $result['output']);
        $this->assertFileExists($this->release() . '/.dk_live');
    }

    public function test_failing_queue_restart_does_not_hide_the_rollback(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $first = basename((string) $this->currentTarget());

        unlink($this->sourcePath() . '/public/up.txt');
        $failed = $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'queue:restart']);
        $this->assertNotSame(0, $failed['exit']);
        $rolled = strpos($failed['output'], 'ROLLED BACK to ' . $first . ': health check failed');
        $this->assertNotFalse($rolled, $failed['output']);
        $warning = strpos($failed['output'], 'queue restart after the rollback failed', $rolled);
        $this->assertNotFalse($warning, 'the restart warning follows the outcome: ' . $failed['output']);
        $this->assertSame(realpath($this->basePath() . '/releases/' . $first), $this->currentTarget());
    }

    public function test_health_check_retries_and_reports_without_the_query(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);

        $url = 'file://' . $this->basePath() . '/nowhere.txt?token=secret';
        $failed = $this->deploy(self::RECIPE, ['DK_HEALTH_URL' => $url, 'DK_HEALTH_ATTEMPTS' => '3']);
        $this->assertNotSame(0, $failed['exit']);
        $this->assertStringContainsString('Health check failed for file://' . $this->basePath() . '/nowhere.txt after 3 attempts (last curl exit', $failed['output']);
        $this->assertStringNotContainsString('secret', $failed['output']);
    }

    public function test_health_check_passes_on_a_later_attempt(): void
    {
        $flag = $this->basePath() . '/late.txt';
        $result = $this->deploy(self::RECIPE, [
            'DK_HEALTH_URL' => 'file://' . $flag,
            'DK_HEALTH_ATTEMPTS' => '5',
            'DK_HEALTH_DELAY' => '1',
            'DK_HEALTH_LATE_FLAG' => $flag,
        ]);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringNotContainsString('ROLLED BACK', $result['output']);
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

    public function test_unfinished_release_is_never_a_rollback_target(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $this->assertNotSame(0, $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'migrate'])['exit']);

        unlink($this->sourcePath() . '/public/up.txt');
        $failed = $this->deploy(self::RECIPE);
        $this->assertNotSame(0, $failed['exit']);
        $this->assertSame(realpath($this->basePath() . '/releases/1'), $this->currentTarget());
        $this->assertStringContainsString('ROLLED BACK to 1:', $failed['output']);
        $this->assertFileExists($this->basePath() . '/releases/2/BAD_RELEASE');
    }

    public function test_manual_rollback_skips_unfinished_release(): void
    {
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);
        $this->assertNotSame(0, $this->deploy(self::RECIPE, ['DK_FAIL_ON' => 'migrate'])['exit']);
        $this->assertSame(0, $this->deploy(self::RECIPE)['exit']);

        $result = $this->runDep('rollback', self::RECIPE);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(realpath($this->basePath() . '/releases/1'), $this->currentTarget());
    }

    public function test_no_marked_release_means_no_rollback_target(): void
    {
        unlink($this->sourcePath() . '/public/up.txt');
        $this->assertNotSame(0, $this->deploy(self::RECIPE)['exit']);

        $second = $this->deploy(self::RECIPE);
        $this->assertNotSame(0, $second['exit']);
        $this->assertStringContainsString('no previous release', $second['output']);
        $this->assertStringNotContainsString('ROLLED BACK', $second['output']);
        $this->assertSame(realpath($this->basePath() . '/releases/2'), $this->currentTarget());
    }
}
