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
