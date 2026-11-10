<?php

declare(strict_types=1);

namespace Deployer;

use DeployKit\MigrationScanner;
use Symfony\Component\Console\Input\InputOption;

use function DeployKit\artisan;
use function DeployKit\pendingMigrationNames;

require __DIR__ . '/common.php';

set('dk_preflight_command', null);
set('dk_seeders', []);
set('dk_queue', true);
set('dk_composer_options', '--no-dev --optimize-autoloader --no-interaction --no-progress');

option('allow-breaking', null, InputOption::VALUE_NONE, 'Continue when pending migrations may break the running code.');

desc('Install composer dependencies in the release');
task('dk:vendors', function () {
    run('cd {{release_path}} && {{bin/composer}} install {{dk_composer_options}}');
});

desc('Hand the release\'s bootstrap/cache to the app account');
task('dk:handover', function () {
    $user = get('dk_app_user');
    if ($user === null || $user === '') {
        return;
    }
    run('mkdir -p {{release_path}}/bootstrap/cache');
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
        warning('The migration check was SKIPPED: `migrate:status --pending` failed (a first deploy has no migrations table yet). Migrations will still run.');

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
});

desc('Run the configured seeders');
task('dk:seed', function () {
    foreach ((array) get('dk_seeders') as $seeder) {
        run(artisan('db:seed --class=' . $seeder . ' --force'), forceOutput: true);
    }
});

desc('Build the config, route, view and event caches');
task('dk:cache', function () {
    foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache'] as $command) {
        run(artisan($command));
    }
});

desc('Ask queue workers to restart after the current job');
task('dk:queue:restart', function () {
    if (get('dk_queue')) {
        run(artisan('queue:restart'));
    }
});

// Remember our own lock: a deploy that found the lock taken must not release someone else's.
after('deploy:lock', function () {
    set('dk_locked', true);
});
fail('deploy', function () {
    if (has('dk_locked') && get('dk_locked')) {
        invoke('deploy:unlock');
    }
});

task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'dk:upload',
    'deploy:shared',
    'dk:vendors',
    'dk:handover',
    'dk:preflight',
    'dk:migrations:check',
    'dk:migrate',
    'dk:seed',
    'dk:cache',
    'deploy:symlink',
    'dk:queue:restart',
    // Task 5 inserts dk:health and dk:opcache:clear here.
    'deploy:unlock',
    'deploy:cleanup',
]);
