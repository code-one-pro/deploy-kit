# deploy-kit

Deployer recipes for zero-downtime deployments on one Ubuntu server with Caddy and PHP-FPM.
Code is sent by rsync from your workstation. The server never pulls from git.

There are two recipes:

- `recipe/laravel.php` for Laravel apps.
- `recipe/static-site.php` for static single-page apps (SPA).

The kit also has server-side files in `templates/` and a one-time move guide in
`docs/cutover-runbook.md`.

## Versions

- Deployer **8.0.5**, pinned as `^8.0.5`.
- PHP `^8.3` on the workstation (Deployer 8 needs it). The server runs PHP 8.4-FPM.

## What a deploy does

Each app has this layout on the server:

```
<base>/
  releases/<timestamp>/   one directory per deploy, complete before it goes live
  shared/                 .env and storage/ (Laravel); survives every release
  current -> releases/<timestamp>
```

A deploy builds a new release next to the live one and then switches the `current` link in one
step. If a step before the switch fails, the live site is not touched. If the health check fails
after the switch, the kit puts the previous release back.

## Install

### In a Laravel app

Add the GitHub repository to the app's `composer.json`, then require the kit as a dev dependency:

```
composer config repositories.deploy-kit vcs https://github.com/transistorized-cmd/deploy-kit.git
composer require --dev transistorized-cmd/deploy-kit:dev-main
```

Use the branch or tag you want in place of `dev-main`. Then write a `deploy.php` in the app root:

```php
<?php
namespace Deployer;

require __DIR__ . '/vendor/transistorized-cmd/deploy-kit/recipe/laravel.php';

host('deploy-hetzner')
    ->set('remote_user', 'deploy')
    ->set('deploy_path', '/var/www/<app>');

set('dk_source_path', __DIR__);
set('dk_upload_paths', ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'artisan', 'composer.json', 'composer.lock']);
set('dk_app_user', 'app-<name>');
set('dk_health_url', 'https://<domain>/up');
set('dk_fpm_socket', '/run/php/php8.4-fpm-app-<name>.sock');
```

Run it with `vendor/bin/dep deploy`.

### For a static site (no Composer in the project)

Install the kit globally once on the workstation:

```
composer global config repositories.deploy-kit vcs https://github.com/transistorized-cmd/deploy-kit.git
composer global require transistorized-cmd/deploy-kit:dev-main
```

In the project, write a `deploy.php` that requires the recipe from the global directory
(`composer global config home` prints it):

```php
<?php
namespace Deployer;

require '<composer-home>/vendor/transistorized-cmd/deploy-kit/recipe/static-site.php';

host('deploy-hetzner')
    ->set('remote_user', 'deploy')
    ->set('deploy_path', '/var/www/<site>');

set('dk_source_path', __DIR__ . '/dist');
set('dk_health_url', 'https://<domain>/');
```

Run it with `<composer-home>/vendor/bin/dep deploy`. Build the site before you deploy; the
recipe uploads what is in `dk_source_path`.

## Configuration keys

Set them with `set('key', value)` in `deploy.php`.

| Key | Default | Meaning |
|---|---|---|
| `deploy_path` | none (Deployer) | Base path on the server, for example `/var/www/<app>`. |
| `dk_source_path` | `null` | Local directory to upload. Required. |
| `dk_upload_paths` | `null` | List of top-level paths to upload. `null` uploads the whole tree; `[]` uploads nothing. |
| `dk_upload_excludes` | `[]` | Extra rsync exclude patterns. |
| `dk_app_user` | `null` | System account of the app. Artisan commands run as this account with `sudo -u`, and `bootstrap/cache` is handed to it. |
| `dk_health_url` | `null` | URL to check after the switch. `null` skips the check. |
| `dk_health_resolve_local` | `true` | For an https URL, pin the host to `127.0.0.1:443` (no DNS, no proxy). |
| `dk_fpm_socket` | `null` | PHP-FPM socket of the app. `null` skips the opcache clear. |
| `dk_cachetool` | `/usr/local/bin/cachetool` | Path of the cachetool binary on the server. |
| `dk_queue` | `false` in `common.php`; `true` in the `laravel` recipe | Run `queue:restart` after the switch. |
| `dk_preflight_command` | `null` | Artisan command run before migrations (Laravel only). |
| `dk_seeders` | `[]` | Seeder classes run after `migrate` (Laravel only). |
| `dk_composer_options` | `--no-dev --optimize-autoloader --no-interaction --no-progress` | Options for `composer install` (Laravel only). |
| `dk_storage_link` | `true` (Laravel) | Link `public/storage` in each release to `<base>/shared/storage/app/public`, as `storage:link` would. |
| `dk_verify_view_path` | `true` (Laravel) | After `config:cache`, stop the deploy when `view.compiled` is not inside the release (see "Compiled views"). |
| `dk_assets_dir` | `assets` | Directory of hashed assets to carry over (static only). |
| `keep_releases` | `5` | Releases kept on the server. |
| `shared_dirs` | `['storage']` (Laravel), `[]` (static) | Directories shared between releases. |
| `shared_files` | `['.env']` (Laravel), `[]` (static) | Files shared between releases. |

These are never uploaded, whatever you set: `/.env`, `/.env.*`, `/storage/`, `/vendor/`,
`/node_modules/`, `/.git/`, `/bootstrap/cache/*`, `/public/hot` (the Vite dev-server marker),
`/public/storage` (a link to a workstation path; the kit makes its own, see below),
`/database/*.sqlite` and `/database/*.sqlite-*` (local SQLite files).

## Task order

Laravel (`dep deploy`):

```
deploy:info, deploy:setup, deploy:lock, deploy:release, dk:upload, deploy:shared,
dk:storage:link, dk:vendors, dk:handover, dk:preflight, dk:migrations:check, dk:migrate, dk:seed, dk:cache,
deploy:symlink, dk:queue:restart, dk:health, dk:release:mark-live, dk:opcache:clear,
deploy:unlock, deploy:cleanup
```

Static site:

```
deploy:setup, deploy:lock, deploy:release, dk:upload, dk:static:carry-assets,
deploy:symlink, dk:health, dk:release:mark-live, deploy:unlock, deploy:cleanup
```

A new static release keeps the live release's own hashed asset files, for one generation, so a
browser tab opened before the deploy can still load its old chunks. Each release lists its own
files in `.dk-own-assets`.

## Compiled views

Laravel names a compiled Blade view after its path relative to the app, so every release would write
the same file names into one shared `storage/framework/views`. A new release would then overwrite the
views the live release is serving, and after a rollback the old code would keep the new templates.

The kit keeps compiled views per release, in `releases/<n>/bootstrap/cache/views`. It sets the real
environment variable `VIEW_COMPILED_PATH` to that path on the `dk:cache` artisan commands
(`config:cache`, `route:cache`, `view:cache`, `event:cache`). A real environment variable wins over
`.env`, and `config:cache` freezes the value into the release's cached config, so PHP-FPM, the queue
worker and cron all use the release's own directory afterwards.

The one requirement on the app: `config/view.php`, if you published it, must keep
`'compiled' => env('VIEW_COMPILED_PATH', ...)`. Laravel's own default does. After `config:cache` the
kit reads `view.compiled` back from the release's cached config, and stops the deploy before the
switch when it is not inside the release. Set `dk_verify_view_path` to `false` to skip that check.

## Roll back

```
dep rollback
```

This points `current` at the newest earlier release that passed a health check, then restarts the
queue workers and clears the opcache. It does not undo database migrations. A release is a rollback
target only if it has a `.dk_live` file (written after its health check passed) and no `BAD_RELEASE`
file. A release that failed its health check and was rolled back automatically gets `BAD_RELEASE`.

## The migration rule

A migration must never break the code that is already running, because the old code serves
requests until the switch, and a rollback puts it back.

- Additive changes (a new table, a new nullable column, a new column with a default, a new index)
  ship in the same deploy as the code that uses them.
- Breaking changes (rename, drop, type change, `NOT NULL`) take two deploys. The first adds the new
  shape and stops using the old one. A later deploy removes the old shape.

Before `migrate`, `dk:migrations:check` reads the pending migrations and flags `dropColumn` and
`dropColumns` (also `Schema::dropColumns(...)`), `dropSoftDeletes`, `dropTimestamps` (and their `Tz`
forms), `dropMorphs`, `dropRememberToken`, `renameColumn`, table rename or drop (also through
`Schema::connection(...)`), `->change()`, `dropForeign` and raw `DROP COLUMN`, `DROP TABLE`, `RENAME`
and `ALTER COLUMN`. It is a text check: it can flag harmless code, and it can miss things. It is a
prompt to think, not a proof.

What it does NOT detect:

- A new `NOT NULL` column without a default. The old code does not fill it, so its inserts fail.
- A statement split across several lines (the check reads one line at a time).

- In a terminal it shows the file and line and asks you to confirm.
- Without a terminal it stops the deploy.
- To deploy anyway, pass the option: `dep deploy --allow-breaking`.

If `migrate:status` itself fails (a first deploy has no migrations table), the check is skipped with
a warning and `migrate` still runs.

## Pause the scheduler

The scheduler is cron plus `templates/scheduler-run.sh`. A deploy never restarts it.

```
sudo -u <app-user> touch <base>/shared/storage/scheduler.paused   # pause: the next run does nothing
sudo -u <app-user> rm <base>/shared/storage/scheduler.paused      # resume
cat <base>/shared/storage/scheduler.heartbeat    # UTC time of the last run that finished well
```

The flag sits next to the heartbeat in `shared/storage`, which the app account owns, so the app
itself (an admin page running as that account) can pause and resume it too.

A healthy scheduler writes a new heartbeat every minute. Its output is in
`<base>/shared/storage/logs/scheduler.log`.

## Server files

- `templates/scheduler-run.sh`: the program cron runs. Install it as `/usr/local/bin/dk-scheduler-run`.
- `templates/scheduler.cron`: the `/etc/cron.d` line for one app.
- `templates/Caddyfile.snippet`: Caddy site blocks for a PHP site and for a static SPA.
- `docs/cutover-runbook.md`: how to move an app to this layout, one time per app.

## Things to know

- **Rollback targets.** A rollback target must have passed a health check under the kit. Releases
  deployed before the kit was installed are not rollback targets until one good deploy has happened.
- **`curl` on the server.** A host without `curl` fails the health check and triggers a rollback.
- **Your own failure handler.** A project that calls `fail('deploy', ...)` itself replaces the kit's
  failure handler. It must remove the deploy lock on its own.
- **Empty upload list.** `dk_upload_paths = []` uploads nothing. Use `null` to upload the whole tree.
- **Opcache.** The opcache clear affects every PHP app on the server, because they all share one
  PHP-FPM service. It causes a brief slowdown. No request is dropped.
- **`www-data`.** It is not used anywhere in the kit. The code tree belongs to `deploy`; runtime
  writes belong to the app account.

## Tests

```
composer install
vendor/bin/phpunit
```
