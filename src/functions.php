<?php

declare(strict_types=1);

namespace DeployKit;

/**
 * Shell command running artisan from a release, as the app account when one is set.
 */
function artisanCommand(string $releasePath, string $command, ?string $appUser, string $php = 'php'): string
{
    $line = $php . ' ' . $releasePath . '/artisan ' . $command;

    return $appUser === null ? $line : 'sudo -u ' . $appUser . ' ' . $line;
}

/**
 * artisanCommand() fed from the Deployer context (release_path, dk_app_user, bin/php).
 */
function artisan(string $command): string
{
    $user = \Deployer\get('dk_app_user');

    return artisanCommand(
        (string) \Deployer\get('release_path'),
        $command,
        $user === null || $user === '' ? null : (string) $user,
        (string) \Deployer\get('bin/php'),
    );
}

/**
 * artisan() for the `current` release: what runs after a rollback, when release_path no longer exists.
 */
function currentArtisan(string $command): string
{
    $user = \Deployer\get('dk_app_user');

    return artisanCommand(
        (string) \Deployer\get('current_path'),
        $command,
        $user === null || $user === '' ? null : (string) $user,
        (string) \Deployer\get('bin/php'),
    );
}

/**
 * The curl probe for the health URL. The body is discarded, never printed. For an https URL with
 * $resolveLocal the host is pinned to 127.0.0.1 (skipping DNS and the proxy; -k as the certificate
 * may not match). Other schemes are fetched as they are.
 */
function healthCommand(string $url, bool $resolveLocal): string
{
    $line = 'curl -fsS --max-time 20 -o /dev/null';
    $host = parse_url($url, PHP_URL_HOST);
    if ($resolveLocal && str_starts_with(strtolower($url), 'https://') && is_string($host) && $host !== '') {
        $line .= ' --resolve ' . $host . ':443:127.0.0.1 -k';
    }

    return $line . ' ' . escapeshellarg($url);
}

/** Shell command clearing the PHP-FPM pool's opcache through cachetool. */
function cachetoolCommand(string $cachetool, string $socket): string
{
    return 'sudo ' . $cachetool . ' opcache:reset --fcgi=' . $socket;
}

/**
 * Migration names in `migrate:status --pending` output.
 *
 * Accepts bare names one per line and Laravel's framed table, where a pending row looks like
 * `  2026_01_01_000000_create_x_table .......... Pending`. Anything else yields no names.
 *
 * @return list<string>
 */
function pendingMigrationNames(string $output): array
{
    preg_match_all('/(?<![\w])(\d{4}_\d{2}_\d{2}_\d{6}_\w+)/', $output, $matches);

    return array_values(array_unique($matches[1]));
}
