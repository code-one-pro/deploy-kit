# deploy-kit v0.1 + Open Brain Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the shared zero-downtime deploy recipes and move Open Brain onto them, proving the mechanism before any other app moves.

**Architecture:** A Composer package of Deployer recipes (`laravel`, `static-site`) over a common base: rsync upload into `releases/<n>/`, shared `.env` + `storage/`, atomic `current` switch, health check with automatic rollback. One pure PHP class scans pending migrations. Recipes are tested locally against a fake app through Deployer's `localhost()` host; server behaviour is proven on Open Brain.

**Tech Stack:** PHP 8.4, Deployer (version pinned in Task 1), PHPUnit 11, rsync, Caddy 2.11, PHP-FPM 8.4, cron, cachetool.

**Spec:** `docs/superpowers/specs/2026-11-10-zero-downtime-deploy-design.md`

**Scope:** This plan covers spec sections 4 to 7 and step 1 of section 8. Belenos, Code One Pro and Quote Master Pro each get their own plan after Task 11's go/no-go.

## Global Constraints

- Free, open-source tools only. Stack stays Caddy + PHP-FPM; no containers, no CI server.
- The server never pulls from git; code arrives by rsync from the workstation.
- `.env`, `storage/` and Passport keys are never uploaded, printed or logged.
- `www-data` is deprecated and is never used. Runtime writes belong to the app account (`app-openbrain`); the code tree belongs to `deploy`.
- No `systemctl reload php8.4-fpm` and no scheduler restart in a deploy.
- Keep the last 5 releases.
- There is no maintenance-mode path.
- Every server file changed gets a dated `.bak` first and is copied back to `~/Documents/Github/hetzner-server` under `rootfs/`.
- Server commands (Tasks 8, 10, 11) run only with JL's go-ahead in that session. Nothing else on the box is touched.
- Commit and push only when JL asks. Commit steps below mean "stage and commit locally if JL has said to commit; otherwise leave staged".
- Config keys and task names of the kit are prefixed `dk_` / `dk:`.

## Review Focus

1. A migration whose `down()` drops or renames but whose `up()` is additive: must not be flagged (Task 2).
2. A deploy that fails at `composer install` or `migrate`: `current` still points at the previous release, the lock is released, the next deploy works (Task 4).
3. A failed health check on the very first deploy, when there is no previous release: fails loudly, does not delete or repoint anything, says so (Task 5).
4. A source tree that contains `.env`, `storage/`, `node_modules/`, `.git/`: none of them reaches the server, whatever the app's upload list says (Task 3).
5. The scheduler cron line when `current` does not exist yet or the pause file exists: exits 0, writes nothing alarming, runs nothing (Task 7).

---

## File structure

```
composer.json                      package transistorized-cmd/deploy-kit
recipe/common.php                  config defaults, dk:upload, dk:health, dk:opcache:clear, helpers
recipe/laravel.php                 the Laravel deploy task chain
recipe/static-site.php             the static SPA deploy task chain
src/MigrationScanner.php           pure: flags breaking statements in a migration's up()
templates/scheduler.cron           cron line with pause flag
templates/Caddyfile.snippet        root + resolve_root_symlink block
docs/cutover-runbook.md            one-time move of an app, generic + per-app worksheet
README.md                          install, deploy.php reference, rollback
tests/Unit/MigrationScannerTest.php
tests/Integration/DeployTestCase.php   runs `dep` against tests/fixtures with localhost()
tests/Integration/LaravelRecipeTest.php
tests/Integration/StaticSiteRecipeTest.php
tests/fixtures/app/                fake app: artisan script, composer.json, public/, database/migrations/
tests/fixtures/deploy-laravel.php, tests/fixtures/deploy-static.php
```

---

### Task 1: Package scaffold, Deployer pin, test harness

**Files:**
- Create: `composer.json`, `phpunit.xml`, `.gitignore`, `tests/Integration/DeployTestCase.php`, `tests/fixtures/app/artisan`, `tests/fixtures/app/composer.json`, `tests/fixtures/app/public/up.txt`, `tests/fixtures/deploy-laravel.php`, `recipe/common.php` (empty namespace file)

**Interfaces:**
- Produces: `DeployKit\Tests\Integration\DeployTestCase` with
  `deploy(string $recipeFile, array $env = [], array $args = []): array{exit:int, output:string}`,
  `basePath(): string` (a fresh temp deploy path per test), `sourcePath(): string` (a per-test copy of `tests/fixtures/app`),
  `artisanLog(): list<string>` (commands the fake artisan received, in order).
- Fake `artisan`: appends its arguments to the file in env `DK_FAKE_LOG`; exits 1 when its first argument equals env `DK_FAIL_ON`; `migrate:status --pending` prints the names in env `DK_PENDING` (comma-separated), one per line.

- [ ] **Step 1:** Read the official Deployer releases and docs (deployer.org, github.com/deployphp/deployer). Record in `README.md` under "Versions": the newest stable version, its PHP requirement, and confirm these exist in it: `localhost()`, `upload()`, `set/get`, `task/before/after/fail`, `recipe/common.php` tasks `deploy:setup`, `deploy:lock`, `deploy:release`, `deploy:shared`, `deploy:symlink`, `deploy:unlock`, `deploy:cleanup`, `rollback`, and `keep_releases`. If the newest major lacks any, pin the previous major.
- [ ] **Step 2:** `composer.json`: `"name": "transistorized-cmd/deploy-kit"`, `"type": "library"`, `"license": "MIT"`, require `php ^8.2` and `deployer/deployer` pinned to the version from Step 1 (caret on the minor), require-dev `phpunit/phpunit ^11.5`, PSR-4 `DeployKit\\` → `src/`, `DeployKit\\Tests\\` → `tests/`. Run `composer install`. Expected: `vendor/bin/dep --version` prints the pinned version.
- [ ] **Step 3:** Write `tests/Integration/HarnessTest.php::test_fake_artisan_logs_and_fails_on_request`: run the fixture `artisan` with `DK_FAIL_ON=migrate` for `config:cache` then `migrate`; assert exit codes `0` and `1` and `artisanLog() === ['config:cache', 'migrate']`.
- [ ] **Step 4:** Run `vendor/bin/phpunit --filter HarnessTest`. Expected: FAIL (class missing).
- [ ] **Step 5:** Implement the fake `artisan` and `DeployTestCase` (Symfony Process, which Deployer already brings). `deploy()` runs `vendor/bin/dep deploy -f <recipe> localhost` with `DK_BASE`, `DK_SOURCE`, `DK_FAKE_LOG` plus `$env`.
- [ ] **Step 6:** Run `vendor/bin/phpunit --filter HarnessTest`. Expected: PASS.
- [ ] **Step 7:** Commit: `chore: scaffold package and deploy test harness`.

### Task 2: Migration scanner

**Files:**
- Create: `src/MigrationScanner.php`
- Test: `tests/Unit/MigrationScannerTest.php`

**Interfaces:**
- Produces: `DeployKit\MigrationScanner::scan(string $source): list<array{line:int, text:string, pattern:string}>`. Pure, no I/O. Only the body of `up()` is scanned.

- [ ] **Step 1:** Write the tests. Each feeds a migration source string:
  - `test_additive_up_is_clean`: `Schema::create`, `$table->string('x')->nullable()`, `$table->index(...)` → `[]`.
  - `test_flags_each_breaking_statement`: data provider, one finding each for `$table->dropColumn('a')`, `$table->dropColumns(['a'])`, `$table->renameColumn('a','b')`, `Schema::rename('a','b')`, `Schema::drop('a')`, `Schema::dropIfExists('a')`, `$table->string('a')->change()`, `$table->dropForeign(['a'])`, `DB::statement('ALTER TABLE t DROP COLUMN a')`, `DB::statement('alter table t rename column a to b')`, `DB::statement('ALTER TABLE t ALTER COLUMN a SET NOT NULL')`. Assert `pattern` is a short label (`dropColumn`, `renameColumn`, `rename`, `drop`, `change`, `dropForeign`, `raw-sql`) and `line` is the 1-based line in the source.
  - `test_down_is_ignored`: additive `up()`, `down()` with `Schema::dropIfExists` and `dropColumn` → `[]`.
  - `test_reports_every_finding_in_order`: two breaking lines → two findings, ascending `line`.
  - `test_source_without_up_method_is_clean`: → `[]`.
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/Unit`. Expected: FAIL (class missing).
- [ ] **Step 3:** Implement `scan()`. Find `function up(`, take its body by brace matching (skip braces inside strings and comments), then match per line, case-insensitive for raw SQL.
- [ ] **Step 4:** Run `vendor/bin/phpunit tests/Unit`. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: migration scanner`.

### Task 3: Common recipe: layout, upload, app-account helper

**Files:**
- Modify: `recipe/common.php`, `tests/fixtures/deploy-laravel.php`
- Test: `tests/Integration/LaravelRecipeTest.php`

**Interfaces:**
- Produces (Deployer config, with defaults):
  - `dk_source_path` (string, required): local directory to upload.
  - `dk_upload_paths` (list<string>|null, null): allowlist of top-level entries; null = whole tree.
  - `dk_upload_excludes` (list<string>, `[]`): extra rsync excludes.
  - `dk_app_user` (string|null, null): account artisan runs as; null = no sudo.
  - `shared_dirs` = `['storage']`, `shared_files` = `['.env']`, `keep_releases` = `5`.
- Produces: task `dk:upload` (rsync `dk_source_path` into `{{release_path}}`), and
  `DeployKit\artisan(string $command): string`, returning the shell command for `{{release_path}}/artisan $command`, prefixed `sudo -u <dk_app_user>` when set.
- `dk:upload` always excludes, regardless of the two settings above: `.env`, `.env.*`, `storage/`, `vendor/`, `node_modules/`, `.git/`, `bootstrap/cache/*`.

- [ ] **Step 1:** Write tests (fixture recipe at this stage: `deploy:setup`, `deploy:lock`, `deploy:release`, `dk:upload`, `deploy:shared`, `deploy:symlink`, `deploy:unlock`):
  - `test_first_deploy_creates_layout`: `basePath()` has `releases/`, `shared/storage`, and `current` is a symlink to a directory under `releases/`; `current/artisan` exists; `current/storage` and `current/.env` are symlinks into `shared/`.
  - `test_never_uploads_secrets_or_state`: put `.env`, `.env.e2e`, `storage/secret.txt`, `node_modules/x`, `.git/HEAD`, `vendor/x` in `sourcePath()` and set `dk_upload_paths` to include `storage` and `.env`; after deploy none exists inside the release directory itself (resolve symlinks: `shared/storage/secret.txt` must not exist).
  - `test_allowlist_limits_upload`: `dk_upload_paths = ['artisan','public']`, source also has `docs/`; release has no `docs/`.
  - `test_artisan_helper_prefixes_app_user`: with `dk_app_user = 'app-x'` the string starts with `sudo -u app-x `; with null it has no `sudo`.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter LaravelRecipeTest`. Expected: FAIL.
- [ ] **Step 3:** Implement in `recipe/common.php` (`require 'recipe/common.php'` from Deployer first). Upload uses Deployer's `upload()` with rsync options built from the settings; the fixed excludes are appended last so nothing overrides them.
- [ ] **Step 4:** Run the same filter. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: release layout and rsync upload`.

### Task 4: Laravel recipe: prepare, migrate, cache, switch

**Files:**
- Create: `recipe/laravel.php`
- Modify: `tests/fixtures/deploy-laravel.php` (now just `require recipe/laravel.php` + host config)
- Test: `tests/Integration/LaravelRecipeTest.php`

**Interfaces:**
- Consumes: Task 2 `MigrationScanner::scan`, Task 3 `dk:upload`, `DeployKit\artisan()`.
- Produces config: `dk_preflight_command` (string|null, null), `dk_seeders` (list<string>, `[]`), `dk_queue` (bool, true), `dk_composer_options` (string, `--no-dev --optimize-autoloader --no-interaction --no-progress`).
- Produces CLI option `--allow-breaking` (bool) on `dep deploy`.
- Produces tasks, run by `deploy` in this order: `deploy:setup`, `deploy:lock`, `deploy:release`, `dk:upload`, `deploy:shared`, `dk:vendors`, `dk:handover` (chown the release's `bootstrap/cache` to `dk_app_user`; no-op when null), `dk:preflight`, `dk:migrations:check`, `dk:migrate`, `dk:seed`, `dk:cache` (`config:cache`, `route:cache`, `view:cache`, `event:cache`), `deploy:symlink`, `dk:queue:restart`, `deploy:unlock`, `deploy:cleanup`. `fail('deploy', 'deploy:unlock')`.
- `dk:migrations:check`: runs `migrate:status --pending` in the release, maps each pending name to `{{dk_source_path}}/database/migrations/<name>.php` (a name with no local file is skipped), scans it, prints `file:line  text` per finding. With findings: continues if `--allow-breaking` was given; otherwise asks "These migrations may break the code that is running. Continue?" and aborts on no. In a non-interactive run without the option it aborts.

- [ ] **Step 1:** Write tests:
  - `test_runs_artisan_steps_in_order`: `artisanLog()` equals `['migrate:status --pending', 'migrate --force', 'config:cache', 'route:cache', 'view:cache', 'event:cache', 'queue:restart']` (fixture has no preflight, no seeders).
  - `test_preflight_and_seeders_run_when_configured`: with `dk_preflight_command = 'deploy:requirements --preflight'` and `dk_seeders = ['ASeeder']`, the log has the preflight before `migrate:status` and `db:seed --class=ASeeder --force` right after `migrate --force`.
  - `test_failed_migrate_leaves_current_untouched`: deploy once; deploy again with `DK_FAIL_ON=migrate`; exit ≠ 0; `readlink(current)` unchanged; `queue:restart` not logged for the second run; a third deploy without the failure succeeds (lock was released).
  - `test_failed_composer_leaves_current_untouched`: same shape, with a fixture `composer.json` made invalid in `sourcePath()`.
  - `test_breaking_pending_migration_aborts_without_flag`: `DK_PENDING=2026_01_01_000000_drop_x`, fixture migration with `dropColumn` in `up()`; exit ≠ 0; output contains the file name and the line; `migrate --force` not logged.
  - `test_breaking_pending_migration_proceeds_with_flag`: same with `--allow-breaking`; exit 0; `migrate --force` logged.
  - `test_keeps_five_releases`: deploy 7 times; `releases/` has 5 entries.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter LaravelRecipeTest`. Expected: FAIL.
- [ ] **Step 3:** Implement `recipe/laravel.php`. Every artisan call goes through `DeployKit\artisan()`. Do not build on Deployer's own `recipe/laravel.php`: its task set differs (git clone, `artisan:*` tasks run as the SSH user).
- [ ] **Step 4:** Run the same filter. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: laravel recipe`.

### Task 5: Health check, automatic rollback, opcache clear

**Files:**
- Modify: `recipe/common.php`, `recipe/laravel.php`
- Test: `tests/Integration/LaravelRecipeTest.php`

**Interfaces:**
- Produces config: `dk_health_url` (string|null, null), `dk_health_resolve_local` (bool, true: `curl --resolve <host>:443:127.0.0.1 -k`, skipped for non-https URLs), `dk_fpm_socket` (string|null, null), `dk_cachetool` (string, `/usr/local/bin/cachetool`).
- Produces tasks: `dk:health` (after `dk:queue:restart`; `curl -fsS --max-time 20`; skipped when the URL is null), `dk:opcache:clear` (after a passing `dk:health`; `sudo {{dk_cachetool}} opcache:reset --fcgi={{dk_fpm_socket}}`; skipped when the socket is null; a failure here warns and does not fail the deploy).
- On `dk:health` failure: if a previous release exists, repoint `current` to it atomically, run `queue:restart` from it, print `ROLLED BACK to <release>: health check failed`, exit ≠ 0. If none exists: print `Health check failed and there is no previous release to roll back to`, change nothing, exit ≠ 0. Either way the lock is released.
- `dep rollback` (Deployer's task) gains `after('rollback', 'dk:queue:restart')` and `dk:opcache:clear`.

- [ ] **Step 1:** Write tests (health URL in the fixture: `file://{{deploy_path}}/current/public/up.txt`):
  - `test_healthy_deploy_passes`: exit 0.
  - `test_failed_health_rolls_back`: deploy once; remove `public/up.txt` from `sourcePath()`; deploy; exit ≠ 0; `current` points to the first release; output contains `ROLLED BACK`; `queue:restart` logged twice in the second run; next deploy (file restored) succeeds.
  - `test_failed_health_on_first_deploy_changes_nothing`: first deploy without `up.txt`; exit ≠ 0; output contains `no previous release`; `current` still points to that only release.
  - `test_manual_rollback_restarts_queue`: two deploys, then `dep rollback`; `current` is the first release; last log line is `queue:restart`.
- [ ] **Step 2:** Run the filter. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run `vendor/bin/phpunit`. Expected: PASS (all).
- [ ] **Step 5:** Commit: `feat: health check with automatic rollback`.

### Task 6: Static-site recipe

**Files:**
- Create: `recipe/static-site.php`, `tests/fixtures/deploy-static.php`, `tests/fixtures/dist/` (`index.html`, `assets/app.aaa.js`)
- Test: `tests/Integration/StaticSiteRecipeTest.php`

**Interfaces:**
- Consumes: Task 3 `dk:upload`, Task 5 `dk:health`.
- Produces config: `dk_assets_dir` (string, `assets`). `shared_dirs`/`shared_files` are empty for this recipe.
- Produces task `dk:static:carry-assets`: copies into the new release's `dk_assets_dir` the files of the **previous release's own build** that the new build does not have. One generation only: files a release itself carried over are not carried again (keep a manifest file, `.dk-own-assets`, listing each release's own files; it is not served problematically since it holds only file names).
- `deploy` order: `deploy:setup`, `deploy:lock`, `deploy:release`, `dk:upload`, `dk:static:carry-assets`, `deploy:symlink`, `dk:health`, `deploy:unlock`, `deploy:cleanup`.

- [ ] **Step 1:** Write tests:
  - `test_deploys_dist_behind_current`: `current/index.html` exists.
  - `test_previous_assets_survive_one_deploy`: deploy with `app.aaa.js`; replace by `app.bbb.js`; deploy; `current/assets` has both.
  - `test_assets_do_not_accumulate`: third deploy with `app.ccc.js`; `current/assets` has `bbb` and `ccc`, not `aaa`.
  - `test_index_is_always_the_new_one`: `current/index.html` content is the last build's.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter StaticSiteRecipeTest`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run `vendor/bin/phpunit`. Expected: PASS (all).
- [ ] **Step 5:** Commit: `feat: static-site recipe`.

### Task 7: Scheduler cron template, Caddy snippet, runbook, README

**Files:**
- Create: `templates/scheduler.cron`, `templates/scheduler-run.sh`, `templates/Caddyfile.snippet`, `docs/cutover-runbook.md`
- Modify: `README.md`
- Test: `tests/Unit/SchedulerRunScriptTest.php`

**Interfaces:**
- Produces `templates/scheduler-run.sh <base-path>`: exits 0 doing nothing when `<base>/current/artisan` is missing or `<base>/shared/scheduler.paused` exists; otherwise `cd <base>/current && php artisan schedule:run`, then on success writes the UTC time to `<base>/shared/scheduler.heartbeat`. Output is appended by cron to `<base>/shared/storage/logs/scheduler.log`.
- `templates/scheduler.cron`: `* * * * * <app-user> /usr/local/bin/dk-scheduler-run <base-path> >> <base-path>/shared/storage/logs/scheduler.log 2>&1`.
- `templates/Caddyfile.snippet`: the block of spec section 4.3.

- [ ] **Step 1:** Write tests running the script with a temp base path and the fake artisan: `test_noop_without_current`, `test_noop_when_paused` (artisan log empty, exit 0, no heartbeat), `test_runs_and_writes_heartbeat` (log is `['schedule:run']`, heartbeat file holds an ISO-8601 time), `test_failed_run_writes_no_heartbeat` (`DK_FAIL_ON=schedule:run`).
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter SchedulerRunScriptTest`. Expected: FAIL.
- [ ] **Step 3:** Write the script and templates. Run the filter. Expected: PASS.
- [ ] **Step 4:** Write `docs/cutover-runbook.md` from spec section 7, as numbered commands with a "check" after each, and a blank per-app worksheet (base path, app account, socket, worker unit, scheduler unit, DB, files naming the old path). It includes: installing `cachetool` (phar, checksum verified) and `dk-scheduler-run` in `/usr/local/bin`, a logrotate entry for `scheduler.log`, and the back-out for every step.
- [ ] **Step 5:** Write `README.md`: install (Composer VCS repository + `require --dev`; global install for static sites), every `dk_*` key with default, the task order, `dep rollback`, the migration rule and `--allow-breaking`, pausing the scheduler.
- [ ] **Step 6:** Commit: `docs: templates, cutover runbook, readme`.

### Task 8: Open Brain: read the server (read-only, JL's go-ahead)

**Files:**
- Modify: `docs/cutover-runbook.md` (fill the Open Brain worksheet; no secrets)

- [ ] **Step 1:** Over `ssh deploy-hetzner`, read only: `ls -la /var/www/openbrain.pellicer.org`; owner and mode of `.env`, `storage`, `bootstrap/cache`, `storage/oauth-*.key`; `sudo supervisorctl status`; whether `/etc/supervisor/conf.d/openbrain-scheduler.conf` exists; the `openbrain.pellicer.org` block of `/etc/caddy/Caddyfile`; `caddy version`; `php-fpm8.4 -i | grep -E 'opcache\.(memory_consumption|max_accelerated_files|validate_timestamps|revalidate_freq)|process_control_timeout'`; `df -h /var/www`; `grep -rl openbrain.pellicer.org /etc /home/deploy --include='*.conf' --include='*.sh' --include='*.service' --include='*.crontab' 2>/dev/null`.
- [ ] **Step 2:** Compare with the `hetzner-server` repo. Record every difference in the worksheet. If the code tree, `.env` or `storage` ownership differs from spec section 3, stop and tell JL before Task 10.
- [ ] **Step 3:** If opcache is at PHP defaults (128 MB, 10000 files), add to the worksheet the proposed values (256 MB, 30000 files) as a change needing JL's yes; it requires one FPM reload, done off-hours, outside any deploy.

### Task 9: Open Brain: `deploy.php` and deploy script

**Files (in `~/Documents/Github/open-brain`):**
- Create: `deploy.php`
- Modify: `composer.json` (VCS repository + dev requirement), `deploy.sh`, `README.md` (section "Recovery and the scheduler": cron replaces the supervisor program)

**Interfaces:**
- Consumes: `recipe/laravel.php` and every `dk_*` key.

- [ ] **Step 1:** `composer.json`: add repository `{"type":"vcs","url":"git@github.com:transistorized-cmd/deploy-kit.git"}` and `require-dev` `transistorized-cmd/deploy-kit`. Until the kit is pushed, use a `path` repository pointing at `../deploy-kit` and say so in the commit message.
- [ ] **Step 2:** `deploy.php`: host `deploy-hetzner`, `deploy_path` `/var/www/openbrain.pellicer.org`, `dk_app_user` `app-openbrain`, `dk_source_path` `__DIR__`, `dk_upload_excludes` = the excludes of the current `deploy.sh` plus `tests/`, `docs/`, `deploy.sh`, `deploy.php`, `sqlite_vec.db`, `claude-plugin/`, `*.md`; `dk_health_url` `https://openbrain.pellicer.org/up`; `dk_fpm_socket` `/run/php/php8.4-fpm-app-openbrain.sock`; `dk_queue` true.
- [ ] **Step 3:** `deploy.sh`: keep the `master` branch gate and the local `npm ci` + `npm run build`; replace everything from the first `ssh` to the end with `vendor/bin/dep deploy "$@"`. Add a test gate before the build: `php artisan test --compact` (Open Brain has none today; ask JL before adding if it lengthens his deploys).
- [ ] **Step 4:** Verify without touching the server: `vendor/bin/dep deploy --dry-run` (or the pinned version's equivalent plan output) lists the tasks in the order of Task 4 and names only `/var/www/openbrain.pellicer.org` paths.
- [ ] **Step 5:** Commit in open-brain: `chore(deploy): zero-downtime deploy with deploy-kit`.

### Task 10: Open Brain: one-time move (JL's go-ahead, agreed time)

Follow `docs/cutover-runbook.md` with the Open Brain worksheet. The order, each with its check and back-out:

- [ ] **Step 1:** Take `.bak-20YYMMDD` copies of the Caddyfile and `openbrain-worker.conf`. Install `cachetool` and `dk-scheduler-run`.
- [ ] **Step 2:** Create `releases/` and `shared/` beside the live tree (the live tree keeps serving). Copy `.env` to `shared/.env` (`deploy:app-openbrain` 640).
- [ ] **Step 3:** Stop the worker; move `storage/` to `shared/storage` and leave a symlink `storage -> shared/storage` in the live tree in the same command; start the worker. Check: the site answers 200 on `/up`, a new log line lands in `shared/storage/logs`. This is the few-second pause the spec allows.
- [ ] **Step 4:** Run `./deploy.sh` from the workstation. With Caddy still on the old root, `dk:health` hits the old tree; that is expected for this one deploy. Check: `current` exists, `current/storage` and `current/.env` resolve into `shared/`, `sudo -u app-openbrain php current/artisan about` works.
- [ ] **Step 5:** Edit the Caddy site block: `root * /var/www/openbrain.pellicer.org/current/public` and `resolve_root_symlink` inside `php_fastcgi`. `sudo caddy validate --config /etc/caddy/Caddyfile`, then `sudo caddy reload --config /etc/caddy/Caddyfile`. Check: `/up` is 200 and `current/storage/logs` shows the request served from the release path.
- [ ] **Step 6:** Worker: point `command`, `directory` to `/var/www/openbrain.pellicer.org/current`, log to `shared/storage/logs/worker.log`; `supervisorctl reread && update`. Check: `RUNNING`, and a test job is processed.
- [ ] **Step 7:** Scheduler: install `/etc/cron.d/openbrain-scheduler` from the template; remove the `openbrain-scheduler` supervisor program if Task 8 found one. Check: within two minutes `shared/scheduler.heartbeat` is fresh.
- [ ] **Step 8:** Update the backup config (`/home/deploy/backups/conf.d/openbrain.conf` reads `.env`: point it at `shared/.env`) and anything else Task 8's grep listed. Run the backup script once by hand and check a dump lands.
- [ ] **Step 9:** Copy every changed server file to `hetzner-server/rootfs/…`, update its README (layout, scheduler row), leave the commit for JL's word.
- [ ] **Step 10:** After Task 11 passes and 48 hours have gone by, remove the old in-place files (`app/`, `vendor/`, … at the base path), keeping `releases/`, `shared/`, `current`.

### Task 11: Open Brain: proof and go/no-go

- [ ] **Step 1: No failed request.** From the workstation run a loop of 10 requests/second against `https://openbrain.pellicer.org/up` and against one authenticated page, logging status codes, for the length of three consecutive deploys (each with a trivial visible change, e.g. a version string). Pass: zero non-2xx/3xx, and the new version string is served within one second of the switch line in the deploy output.
- [ ] **Step 2: Opcache.** Before and after the three deploys record `cachetool opcache:status` (used memory, cached scripts). Pass: after each deploy's clear, usage returns near the single-release level; no request failed during a clear (covered by Step 1's log).
- [ ] **Step 3: Views.** During one deploy keep loading a Blade page of the old release. Pass: no 500 while `view:cache` runs. If it fails, change `dk:cache` to skip `view:cache` and let views compile on demand, add a test, and repeat.
- [ ] **Step 4: Failed deploy.** Deploy a branch whose migration throws. Pass: exit ≠ 0, site unchanged, next deploy works.
- [ ] **Step 5: Rollback.** `vendor/bin/dep rollback`. Pass: the previous version string is served; the worker is on the previous release (`supervisorctl status` uptime reset, `readlink /proc/<pid>/cwd`).
- [ ] **Step 6: Queue.** Dispatch a job that sleeps 60 seconds, deploy while it runs. Pass: the job completes once; the worker restarts after it.
- [ ] **Step 7: Scheduler.** Deploy across a minute boundary. Pass: heartbeat never older than two minutes; create `shared/scheduler.paused`, see the heartbeat stop, remove it, see it resume.
- [ ] **Step 8:** Write the results in `docs/proof-open-brain.md` (numbers, dates, anything changed in the kit as a result). Tell JL the outcome and whether Belenos can start; tag the kit `v0.1.0` if JL agrees.
