<?php

declare(strict_types=1);

namespace Deployer;

use function DeployKit\cachetoolCommand;
use function DeployKit\currentArtisan;
use function DeployKit\healthCommand;

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
    if (get('dk_queue')) {
        // From `current`: after a rollback there is no release_path, and after the switch both are the same code.
        run(currentArtisan('queue:restart'));
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
    if (get('dk_queue')) {
        run(currentArtisan('queue:restart'));
    }

    throw new \RuntimeException("ROLLED BACK to $previous: health check failed");
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
