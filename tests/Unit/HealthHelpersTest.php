<?php

declare(strict_types=1);

namespace DeployKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function DeployKit\cachetoolCommand;
use function DeployKit\healthCommand;
use function DeployKit\healthUrlForDisplay;
use function DeployKit\rollbackSwitchCommands;

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
            "curl -fsS --max-time 20 -o /dev/null --resolve 'app.example.com:443:127.0.0.1' -k 'https://app.example.com/up'",
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

    public function test_health_command_takes_the_port_from_the_url(): void
    {
        $this->assertSame(
            "curl -fsS --max-time 20 -o /dev/null --resolve 'app.example.com:8443:127.0.0.1' -k 'https://app.example.com:8443/up?x=1'",
            healthCommand('https://app.example.com:8443/up?x=1', true),
        );
    }

    public function test_health_command_quotes_hostile_input(): void
    {
        $command = healthCommand("https://a.example.com/up';touch /tmp/x;'", true);
        $this->assertStringContainsString("'https://a.example.com/up'\\'';touch /tmp/x;'\\'''", $command);
    }

    public function test_display_url_keeps_only_scheme_host_and_path(): void
    {
        $this->assertSame('https://app.example.com/up', healthUrlForDisplay('https://user:pw@app.example.com:8443/up?token=secret#x'));
        $this->assertSame('https://app.example.com', healthUrlForDisplay('https://app.example.com'));
        $this->assertSame('file:///x/current/up.txt', healthUrlForDisplay('file:///x/current/up.txt'));
    }

    public function test_rollback_switch_is_atomic_when_mv_supports_it(): void
    {
        $this->assertSame(
            ["cd '/var/www/app' && ln -sfn 'releases/4' '/var/www/app/current.dk_tmp' && mv -T '/var/www/app/current.dk_tmp' '/var/www/app/current'"],
            rollbackSwitchCommands('/var/www/app', '/var/www/app/current', '4', true),
        );
    }

    public function test_rollback_switch_falls_back_to_deployer_symlink(): void
    {
        $this->assertSame(
            ["cd '/var/www/app' && {{bin/symlink}} 'releases/4' '/var/www/app/current'"],
            rollbackSwitchCommands('/var/www/app', '/var/www/app/current', '4', false),
        );
    }
}
