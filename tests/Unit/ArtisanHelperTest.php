<?php

declare(strict_types=1);

namespace DeployKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function DeployKit\artisanCommand;
use function DeployKit\cachedViewPathCommand;
use function DeployKit\seedCommand;

final class ArtisanHelperTest extends TestCase
{
    public function test_artisan_helper_prefixes_app_user(): void
    {
        $with = artisanCommand('/srv/releases/1', 'migrate --force', 'app-x');
        $this->assertSame('sudo -u app-x php /srv/releases/1/artisan migrate --force', $with);

        $without = artisanCommand('/srv/releases/1', 'migrate --force', null, '/usr/bin/php8.4');
        $this->assertSame('/usr/bin/php8.4 /srv/releases/1/artisan migrate --force', $without);
        $this->assertStringNotContainsString('sudo', $without);
    }

    public function test_current_artisan_runs_from_the_current_path(): void
    {
        $this->assertSame(
            'sudo -u app-x php /srv/app/current/artisan queue:restart',
            artisanCommand('/srv/app/current', 'queue:restart', 'app-x'),
        );
    }

    public function test_seed_command_quotes_the_class(): void
    {
        $this->assertSame("db:seed --class='Database\\Seeders\\RolesSeeder' --force", seedCommand('Database\\Seeders\\RolesSeeder'));
        $this->assertSame(
            "sudo -u app-x php /srv/releases/1/artisan db:seed --class='Database\\Seeders\\X' --force",
            artisanCommand('/srv/releases/1', seedCommand('Database\\Seeders\\X'), 'app-x'),
        );
    }

    public function test_environment_reaches_artisan_without_sudo(): void
    {
        $this->assertSame(
            "env VIEW_COMPILED_PATH='/srv/releases/7/bootstrap/cache/views' php /srv/releases/7/artisan config:cache",
            artisanCommand('/srv/releases/7', 'config:cache', null, 'php', ['VIEW_COMPILED_PATH' => '/srv/releases/7/bootstrap/cache/views']),
        );
    }

    public function test_environment_survives_sudo(): void
    {
        $this->assertSame(
            "sudo -u app-x env VIEW_COMPILED_PATH='/srv/releases/7/bootstrap/cache/views' php /srv/releases/7/artisan view:cache",
            artisanCommand('/srv/releases/7', 'view:cache', 'app-x', 'php', ['VIEW_COMPILED_PATH' => '/srv/releases/7/bootstrap/cache/views']),
        );
    }

    public function test_environment_values_are_quoted(): void
    {
        $this->assertSame(
            "env A='x y' B='it'\\''s' php /r/artisan about",
            artisanCommand('/r', 'about', null, 'php', ['A' => 'x y', 'B' => "it's"]),
        );
    }

    public function test_environment_names_are_checked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        artisanCommand('/r', 'about', null, 'php', ['A=B; rm' => 'x']);
    }

    public function test_cached_view_path_command_prints_only_view_compiled(): void
    {
        $release = sys_get_temp_dir() . '/dk-cfg-' . bin2hex(random_bytes(4)) . "/rel 'x'";
        mkdir($release . '/bootstrap/cache', 0755, true);
        file_put_contents($release . '/bootstrap/cache/config.php', "<?php return ['app' => ['key' => 'topsecret'], 'view' => ['compiled' => '/srv/releases/7/bootstrap/cache/views']];");

        $command = cachedViewPathCommand($release, null, escapeshellarg(PHP_BINARY));
        exec($command . ' 2>&1', $out, $code);
        (new \Symfony\Component\Process\Process(['rm', '-rf', dirname($release)]))->mustRun();

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame(['/srv/releases/7/bootstrap/cache/views'], $out);
        $this->assertStringStartsWith('sudo -u app-x php -r ', cachedViewPathCommand('/srv/releases/7', 'app-x'));
    }
}
