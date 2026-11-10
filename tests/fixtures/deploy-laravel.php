<?php

declare(strict_types=1);

namespace Deployer;

require __DIR__ . '/../../recipe/laravel.php';

/** @return list<string>|null null when the variable is unset */
function dkEnvList(string $name): ?array
{
    $value = getenv($name);

    return $value === false ? null : array_values(array_filter(explode(',', $value), static fn ($v) => $v !== ''));
}

set('deploy_path', getenv('DK_BASE'));
set('dk_source_path', getenv('DK_SOURCE'));

if (($paths = dkEnvList('DK_UPLOAD_PATHS')) !== null) {
    set('dk_upload_paths', $paths);
}
if (($excludes = dkEnvList('DK_UPLOAD_EXCLUDES')) !== null) {
    set('dk_upload_excludes', $excludes);
}

if (($preflight = getenv('DK_PREFLIGHT')) !== false && $preflight !== '') {
    set('dk_preflight_command', $preflight);
}
if (($seeders = dkEnvList('DK_SEEDERS')) !== null) {
    set('dk_seeders', $seeders);
}

// The shared-state guard has its own tests; elsewhere a fresh temp base has no shared/ yet.
set('dk_require_shared', getenv('DK_REQUIRE_SHARED') === '1');
if (getenv('DK_VERIFY_VIEW_PATH') === '0') {
    set('dk_verify_view_path', false);
}
if (getenv('DK_STORAGE_LINK') === '0') {
    set('dk_storage_link', false);
}

set('dk_health_url', getenv('DK_HEALTH_URL') ?: 'file://{{deploy_path}}/current/public/up.txt');
set('dk_health_attempts', (int) (getenv('DK_HEALTH_ATTEMPTS') ?: 3));
set('dk_health_delay', (int) (getenv('DK_HEALTH_DELAY') ?: 0));
if (($late = getenv('DK_HEALTH_LATE_FLAG')) !== false && $late !== '') {
    // The app answers only a moment after the switch: the first probe fails, a later one passes.
    task('fixture:answer-late', function () use ($late) {
        run('(sleep 1; touch ' . escapeshellarg($late) . ') > /dev/null 2>&1 &');
    });
    before('dk:health', 'fixture:answer-late');
}
if (($socket = getenv('DK_FPM_SOCKET')) !== false && $socket !== '') {
    set('dk_fpm_socket', $socket);
}
if (getenv('DK_AFTER_UNLOCK_FAILS') !== false) {
    // Simulates a concurrent deploy taking the lock, then a failure after our own unlock.
    task('fixture:late-failure', function () {
        file_put_contents(get('deploy_path') . '/.dep/deploy.lock', 'someone');
        throw new \RuntimeException('failure after unlock');
    });
    after('deploy:unlock', 'fixture:late-failure');
}

// Deployer resolves these to absolute paths that may contain spaces on a workstation.
set('bin/php', 'php');
set('bin/composer', 'composer');

// Upload tests that leave composer.json out of the release have nothing for composer to install.
if (getenv('DK_NO_VENDORS') !== false) {
    task('dk:vendors', function () {});
}

localhost();
