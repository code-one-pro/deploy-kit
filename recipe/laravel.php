<?php

declare(strict_types=1);

namespace Deployer;

use DeployKit\MigrationScanner;
use Symfony\Component\Console\Input\InputOption;

use function DeployKit\artisan;
use function DeployKit\cachedViewPathCommand;
use function DeployKit\releaseViewPath;
use function DeployKit\pendingMigrationNames;
use function DeployKit\seedCommand;

require __DIR__ . '/common.php';

set('dk_preflight_command', null);
set('dk_seeders', []);
set('dk_queue', true);
set('dk_composer_options', '--no-dev --optimize-autoloader --no-interaction --no-progress');
set('dk_storage_link', true);
set('dk_verify_view_path', true);
set('dk_require_shared', true);

option('allow-breaking', null, InputOption::VALUE_NONE, 'Continue when pending migrations may break the running code.');

/*
 * deploy:shared would quietly create an empty shared/.env and shared/storage: a deploy would then go live
 * with no configuration and no uploads. The contents of .env are never read: `test -s` only checks size.
 */
desc('Stop when shared/.env or shared/storage is missing');
task('dk:shared:check', function () {
    if (! get('dk_require_shared')) {
        return;
    }
    $missing = [];
    if (! test('[ -s {{deploy_path}}/shared/.env ]')) {
        $missing[] = 'shared/.env is missing or empty';
    }
    if (! test('[ -d {{deploy_path}}/shared/storage ]')) {
        $missing[] = 'shared/storage is missing';
    }
    if ($missing !== []) {
        throw new \RuntimeException(implode('; ', $missing) . ' in ' . get('deploy_path') . '. Move the live .env and storage there first '
            . '(docs/cutover-runbook.md, step 3); the deploy stopped so it does not go live without them.');
    }
});

/*
 * Done as the SSH user, not through artisan: public/ belongs to `deploy`. The target may not exist yet;
 * a link to the right place is still correct, and the app creates the directory on its first upload.
 */
desc('Link public/storage to the shared storage/app/public');
task('dk:storage:link', function () {
    if (! get('dk_storage_link')) {
        return;
    }
    run('mkdir -p {{release_path}}/public');
    run('ln -sfn {{deploy_path}}/shared/storage/app/public {{release_path}}/public/storage');
});

desc('Install composer dependencies in the release');
task('dk:vendors', function () {
    run('cd {{release_path}} && {{bin/composer}} install {{dk_composer_options}}');
});

desc('Hand the release\'s bootstrap/cache (with its own compiled views directory) to the app account');
task('dk:handover', function () {
    run('mkdir -p ' . releaseViewPath('{{release_path}}'));
    $user = get('dk_app_user');
    if ($user === null || $user === '') {
        return;
    }
    run('sudo chown -R ' . escapeshellarg((string) $user) . ': {{release_path}}/bootstrap/cache');
});

desc('Run the app preflight command, when one is configured');
task('dk:preflight', function () {
    $command = get('dk_preflight_command');
    if (is_string($command) && $command !== '') {
        run(artisan($command), forceOutput: true);
    }
});

desc('Warn about pending migrations that may break the running code');
task('dk:migrations:check', function () {
    // run() reports no exit status when nothrow is set, so the command appends its own.
    $output = run(artisan('migrate:status --pending') . '; echo "__DK_EXIT:$?"', nothrow: true);
    if (preg_match('/__DK_EXIT:(\d+)\s*$/', $output, $m) !== 1 || $m[1] !== '0') {
        // Only a first deploy is expected to have no migrations table; with a live release, stop.
        if (test('[ -e {{current_path}} ]') && ! input()->getOption('allow-breaking')) {
            throw new \RuntimeException('`migrate:status --pending` failed, so the pending migrations could not be checked. '
                . 'Likely causes: sudo (the SSH user may not run artisan as the app account) or the database '
                . '(unreachable, wrong credentials). Fix it, or re-run with --allow-breaking to deploy without the check.');
        }
        warning('The migration check was SKIPPED: `migrate:status --pending` failed (a first deploy has no migrations table yet; a sudo or database error can also cause it). Migrations will still run.');

        return;
    }

    $scanner = new MigrationScanner();
    $findings = [];
    foreach (pendingMigrationNames($output) as $name) {
        $file = rtrim((string) get('dk_source_path'), '/') . '/database/migrations/' . $name . '.php';
        if (! is_file($file)) {
            continue;
        }
        foreach ($scanner->scan((string) file_get_contents($file)) as $finding) {
            $findings[] = $name . '.php:' . $finding['line'] . '  ' . $finding['text'];
        }
    }
    if ($findings === []) {
        return;
    }

    writeln('Pending migrations that may break the code that is running:');
    foreach ($findings as $line) {
        writeln('  ' . $line);
    }

    if (input()->getOption('allow-breaking')) {
        return;
    }
    if (! input()->isInteractive()) {
        throw new \RuntimeException('Breaking migrations found; re-run with --allow-breaking to deploy anyway.');
    }
    if (! askConfirmation('These migrations may break the code that is running. Continue?')) {
        throw new \RuntimeException('Aborted: breaking migrations (use --allow-breaking to skip this question).');
    }
});

desc('Run database migrations');
task('dk:migrate', function () {
    run(artisan('migrate --force'), forceOutput: true);
    set('dk_migrated', true); // read by the failure handler: the schema has moved
});

desc('Run the configured seeders');
task('dk:seed', function () {
    foreach ((array) get('dk_seeders') as $seeder) {
        run(artisan(seedCommand((string) $seeder)), forceOutput: true);
    }
});

/*
 * Compiled views are per release: Laravel names them by their path relative to the app, so a shared
 * directory would let a new release overwrite the live one's views. The real environment variable wins
 * over .env, and config:cache freezes it, so PHP-FPM, the worker and cron use the release's own path.
 */
desc('Build the config, route, view and event caches');
task('dk:cache', function () {
    $release = (string) get('release_path');
    $env = ['VIEW_COMPILED_PATH' => releaseViewPath($release)];
    foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache'] as $command) {
        run(artisan($command, $env));
        if ($command === 'config:cache' && get('dk_verify_view_path')) {
            $user = get('dk_app_user');
            $value = trim(run(cachedViewPathCommand($release, $user === null || $user === '' ? null : (string) $user, (string) get('bin/php'))));
            if (! str_starts_with($value, rtrim($release, '/') . '/')) {
                throw new \RuntimeException("view.compiled is not inside this release ($value). The app must read VIEW_COMPILED_PATH in config/view.php.");
            }
        }
    }
});

task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'dk:shared:check',
    'deploy:release',
    'dk:upload',
    'deploy:shared',
    'dk:storage:link',
    'dk:vendors',   // must stay before dk:handover: composer writes bootstrap/cache (packages.php, services.php) as deploy
    'dk:handover',
    'dk:preflight',
    'dk:migrations:check',
    'dk:migrate',
    'dk:seed',
    'dk:cache',
    'deploy:symlink',
    'dk:queue:restart',
    'dk:health',
    'dk:release:mark-live',
    'dk:opcache:clear',
    'deploy:unlock',
    'dk:cleanup',
]);
