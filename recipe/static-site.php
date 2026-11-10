<?php

declare(strict_types=1);

namespace Deployer;

require __DIR__ . '/common.php';

set('dk_assets_dir', 'assets');
set('shared_dirs', []);
set('shared_files', []);

/*
 * A browser tab opened before a deploy still asks for the old build's hashed chunks. Each release lists the
 * files of its own build in .dk-own-assets (names relative to dk_assets_dir), and a new release takes over
 * only the files the live release owns: one generation, so carried files are never carried twice.
 */
desc('Keep the live release\'s own asset files available in the new release');
task('dk:static:carry-assets', function () {
    $assets = trim((string) get('dk_assets_dir'), '/');
    $new = '{{release_path}}';

    // Written first, from what the build uploaded, before anything is carried.
    run("mkdir -p $new/$assets && cd $new/$assets && find . -type f | sed 's|^\\./||' | sort > $new/.dk-own-assets");

    if (! test('[ -L {{current_path}} ]')) {
        return; // first deploy: nothing to carry
    }
    $live = '{{deploy_path}}/releases/' . basename(run('readlink {{current_path}}'));
    if (! test("[ -d $live/$assets ]")) {
        return;
    }

    // A live release without a manifest predates this feature: every file in its assets directory is its own.
    $own = "if [ -f $live/.dk-own-assets ]; then cat $live/.dk-own-assets; "
        . "else (cd $live/$assets && find . -type f | sed 's|^\\./||'); fi";
    run("($own) | rsync -a --ignore-existing --files-from=- $live/$assets/ $new/$assets/");
});

task('deploy', [
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'dk:upload',
    'dk:static:carry-assets',
    'deploy:symlink',
    'dk:health',
    'dk:release:mark-live',
    'deploy:unlock',
    'dk:cleanup',
]);
