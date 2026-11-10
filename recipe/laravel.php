<?php

declare(strict_types=1);

namespace Deployer;

use DeployKit\MigrationScanner;
use Symfony\Component\Console\Input\InputOption;

use function DeployKit\artisan;
use function DeployKit\cachetoolCommand;
use function DeployKit\currentArtisan;
use function DeployKit\healthCommand;
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
    'dk:health',
    'dk:release:mark-live',
    'dk:opcache:clear',
    'deploy:unlock',
    'deploy:cleanup',
]);
