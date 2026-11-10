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
