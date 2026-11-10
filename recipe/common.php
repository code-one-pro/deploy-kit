<?php

declare(strict_types=1);

namespace Deployer;

use function DeployKit\cachetoolCommand;
use function DeployKit\currentArtisan;
use function DeployKit\healthCommand;
use function DeployKit\releasePathFor;
use function DeployKit\releasesToRemove;

use const DeployKit\RELEASE_NAME_PATTERN;

// Deployer's own common recipe (its bin/dep puts Deployer's directory first on the include path).
require 'recipe/common.php';

/** Never uploaded, whatever the settings: secrets and server-owned state. */
const DK_FIXED_EXCLUDES = [
    '/.env',
    '/.env.*',
    '/storage/',
    '/vendor/',
    '/node_modules/',
    '/.git/',
    '/bootstrap/cache/*',
    '/public/hot',
    '/public/storage',
    '/database/*.sqlite',
    '/database/*.sqlite-*',
];

set('dk_source_path', null);
set('dk_upload_paths', null);
set('dk_upload_excludes', []);
set('dk_app_user', null);
set('dk_health_url', null);
set('dk_health_resolve_local', true);
set('dk_fpm_socket', null);
set('dk_queue', false);
set('dk_cachetool', '/usr/local/bin/cachetool');

set('shared_dirs', ['storage']);
set('shared_files', ['.env']);
set('keep_releases', 5);

desc('Upload the source tree to the release with rsync');
task('dk:upload', function () {
    $source = get('dk_source_path');
    if (! is_string($source) || $source === '') {
        throw new \RuntimeException('dk_source_path is not set: name the local directory to upload.');
    }

    // rsync takes the first matching rule, so the fixed excludes go first and nothing can override them.
    $options = [];
    foreach (DK_FIXED_EXCLUDES as $pattern) {
        $options[] = '--exclude=' . $pattern;
    }
    foreach ((array) get('dk_upload_excludes') as $pattern) {
        $options[] = '--exclude=' . $pattern;
    }

    $paths = get('dk_upload_paths');
    if (is_array($paths)) {
        foreach ($paths as $entry) {
            $options[] = '--include=/' . trim((string) $entry, '/') . '/***';
        }
        $options[] = '--exclude=/*';
    }

    upload(rtrim($source, '/') . '/', '{{release_path}}/', ['options' => $options]);
});

/*
 * Positive rule for rollback targets (automatic and `dep rollback`): a release qualifies only when it
 * carries .dk_live, written after it passed its health check. BAD_RELEASE is skipped as well.
 */
set('rollback_candidate', function () {
    $currentRelease = basename(run('readlink {{current_path}}'));
    $afterCurrent = false;
    foreach (get('releases_list') as $release) {
        if ($release === $currentRelease) {
            $afterCurrent = true;
            continue;
        }
        if ($afterCurrent
            && test("[ -f {{deploy_path}}/releases/$release/.dk_live ]")
            && ! test("[ -f {{deploy_path}}/releases/$release/BAD_RELEASE ]")) {
            return $release;
        }
    }

    throw new \Deployer\Exception\Exception('No more releases you can revert to.');
});

desc('Mark the current release as having served traffic: a valid rollback target later');
task('dk:release:mark-live', function () {
    run('touch {{current_path}}/.dk_live');
});

desc('Ask queue workers to restart after the current job');
task('dk:queue:restart', function () {
    if (! get('dk_queue')) {
        return;
    }
    // From `current`: after a rollback there is no release_path, and after the switch both are the same code.
    // A failure only warns: right after the switch the health check must still run and decide.
    try {
        run(currentArtisan('queue:restart'));
    } catch (\Throwable $e) {
        warning('The queue restart failed; workers may keep running the previous code until they restart: ' . $e->getMessage());
    }
});

desc('Check the app answers; put the previous release back when it does not');
task('dk:health', function () {
    $url = get('dk_health_url');
    if (! is_string($url) || $url === '') {
        return;
    }
    $probe = healthCommand($url, (bool) get('dk_health_resolve_local'));
    if (test($probe)) {
        return;
    }

    writeln('Health check failed for ' . $url);
    cd('{{deploy_path}}');
    $bad = basename(run('readlink {{current_path}}'));
    try {
        $previous = get('rollback_candidate'); // Deployer's rule: the release before current, BAD_RELEASE skipped.
    } catch (\Throwable) {
        throw new \RuntimeException('Health check failed and there is no previous release to roll back to');
    }

    run("{{bin/symlink}} releases/$previous {{current_path}}");
    run("echo '" . timestamp() . ",{{user}}' > releases/$bad/BAD_RELEASE");
    $restartError = null;
    if (get('dk_queue')) {
        try {
            run(currentArtisan('queue:restart'));
        } catch (\Throwable $e) {
            $restartError = $e->getMessage();
        }
    }

    // The outcome first, whatever happened to the queue restart.
    $outcome = "ROLLED BACK to $previous: health check failed";
    writeln($outcome);
    if ($restartError !== null) {
        warning('The queue restart after the rollback failed; workers may keep running the bad release until they restart: ' . $restartError);
    }

    throw new \RuntimeException($outcome);
});

desc('Clear the PHP-FPM opcache after a passing health check');
task('dk:opcache:clear', function () {
    $socket = get('dk_fpm_socket');
    if (! is_string($socket) || $socket === '') {
        return;
    }
    try {
        run(cachetoolCommand((string) get('dk_cachetool'), $socket));
    } catch (\Throwable) {
        warning('The opcache could not be cleared (' . get('dk_cachetool') . ' via ' . $socket . '); PHP-FPM may serve old code until its next restart.');
    }
});

/*
 * Replaces deploy:cleanup, which keeps the newest releases by log (failed ones included, so failed
 * attempts can delete the last live release) and cannot remove a release whose bootstrap/cache belongs
 * to the app account.
 */
desc('Remove old releases, keeping current and the newest live ones');
task('dk:cleanup', function () {
    $raw = (string) get('deploy_path');
    if (trim($raw) === '') {
        throw new \RuntimeException('Refusing to clean up: deploy_path is empty.');
    }
    $deployPath = trim(run('cd {{deploy_path}} && pwd -P'));

    // Same bookkeeping as deploy:cleanup: a leftover `release` link names an unfinished release.
    run('cd {{deploy_path}} && if [ -h release ]; then rm release; fi');

    $releasesDir = escapeshellarg($deployPath . '/releases');
    if (! test("[ -d $releasesDir ]")) {
        return;
    }
    // One line per real directory: "<name> <1 when live, else 0>". Links and files are never listed.
    $listing = run("cd $releasesDir && for d in *; do [ -d \"\$d\" ] && [ ! -h \"\$d\" ] || continue; "
        . "if [ -f \"\$d/.dk_live\" ] && [ ! -f \"\$d/BAD_RELEASE\" ]; then echo \"\$d 1\"; else echo \"\$d 0\"; fi; done");
    $present = [];
    $live = [];
    foreach (explode("\n", $listing) as $line) {
        $parts = explode(' ', trim($line));
        if (count($parts) !== 2 || preg_match(RELEASE_NAME_PATTERN, $parts[0]) !== 1) {
            continue; // not a release name: never touched
        }
        $present[] = $parts[0];
        if ($parts[1] === '1') {
            $live[] = $parts[0];
        }
    }

    // Newest first: Deployer's log order, then directories it never logged, by name.
    $names = [];
    foreach (array_reverse(get('releases_log')) as $entry) {
        $name = (string) ($entry['release_name'] ?? '');
        if (in_array($name, $present, true) && ! in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
    $unlogged = array_values(array_diff($present, $names));
    usort($unlogged, static fn (string $a, string $b): int => strnatcmp($b, $a));
    $names = array_merge($names, $unlogged);

    $current = null;
    $currentReal = null;
    if (test('[ -h {{current_path}} ]')) {
        $current = basename(run('readlink {{current_path}}'));
        $currentReal = trim(run('cd {{current_path}} && pwd -P'));
    }

    $user = get('dk_app_user');
    foreach (releasesToRemove($names, $live, $current, (int) get('keep_releases')) as $name) {
        $path = releasePathFor($deployPath, $name);
        $quoted = escapeshellarg($path);
        if ($currentReal !== null && trim(run("cd $quoted && pwd -P")) === $currentReal) {
            continue; // never the release current resolves to
        }
        if ($user !== null && $user !== '') {
            // The app account owns bootstrap/cache; deploy cannot empty it.
            run("if [ -d $quoted/bootstrap/cache ]; then sudo -u " . escapeshellarg((string) $user) . " find $quoted/bootstrap/cache -mindepth 1 -delete; fi");
        }
        run("rm -rf $quoted");
    }
});

after('rollback', 'dk:queue:restart');
after('rollback', 'dk:opcache:clear');

// Remember our own lock: a deploy that found the lock taken must not release someone else's.
// Hooks are named tasks: an unnamed closure hook is replaced by any other one on the same task.
task('dk:lock:remember', function () {
    set('dk_locked', true);
});
after('deploy:lock', 'dk:lock:remember');
// Once released, a failure in a later task must not remove a lock another deploy has taken since.
task('dk:lock:forget', function () {
    set('dk_locked', false);
});
after('deploy:unlock', 'dk:lock:forget');
fail('deploy', function () {
    // Before the switch (the `release` link still exists) after migrate ran: the live code meets a newer schema.
    try {
        if (has('dk_migrated') && get('dk_migrated') && test('[ -h {{deploy_path}}/release ]')) {
            writeln('NOTE: migrations already ran. The database schema is ahead of the live release; the live code keeps working only if those migrations were additive.');
        }
    } catch (\Throwable) {
        // Best effort; the lock must still be released.
    }
    if (has('dk_locked') && get('dk_locked')) {
        // A release still linked as `release` never reached the switch: it is not a rollback target.
        try {
            if (test('[ -h {{deploy_path}}/release ]')) {
                $name = basename(run('readlink {{deploy_path}}/release'));
                run("echo '" . timestamp() . ",{{user}}' > {{deploy_path}}/releases/$name/BAD_RELEASE");
            }
        } catch (\Throwable) {
            // Marking is best effort; the lock must still be released.
        }
        invoke('deploy:unlock');
    }
});
