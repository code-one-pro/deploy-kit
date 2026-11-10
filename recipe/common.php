<?php

declare(strict_types=1);

namespace Deployer;

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
