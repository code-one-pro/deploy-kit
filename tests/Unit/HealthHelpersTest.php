<?php

declare(strict_types=1);

namespace DeployKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function DeployKit\cachetoolCommand;
use function DeployKit\healthCommand;

final class HealthHelpersTest extends TestCase
{
    public function test_cachetool_command(): void
    {
        $this->assertSame(
            'sudo /usr/local/bin/cachetool opcache:reset --fcgi=/run/php/php8.4-fpm-app.sock',
            cachetoolCommand('/usr/local/bin/cachetool', '/run/php/php8.4-fpm-app.sock'),
        );
    }

    public function test_health_command_for_https_resolves_locally(): void
    {
        $this->assertSame(
            "curl -fsS --max-time 20 -o /dev/null --resolve app.example.com:443:127.0.0.1 -k 'https://app.example.com/up'",
            healthCommand('https://app.example.com/up', true),
        );
    }

    public function test_health_command_without_local_resolve_or_for_other_schemes(): void
    {
        $this->assertSame(
            "curl -fsS --max-time 20 -o /dev/null 'https://app.example.com/up'",
            healthCommand('https://app.example.com/up', false),
        );
        $this->assertSame(
            "curl -fsS --max-time 20 -o /dev/null 'file:///x/up.txt'",
            healthCommand('file:///x/up.txt', true),
        );
        $this->assertStringNotContainsString('--resolve', healthCommand('http://localhost/up', true));
    }
}
