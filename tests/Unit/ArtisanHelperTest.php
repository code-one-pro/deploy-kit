<?php

declare(strict_types=1);

namespace DeployKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function DeployKit\artisanCommand;
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
}
