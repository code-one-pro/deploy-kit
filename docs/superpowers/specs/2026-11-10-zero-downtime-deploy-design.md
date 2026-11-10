# deploy-kit: zero-downtime deployment for Laravel apps

Design, 2026-11-10. Status: awaiting approval by Jose Luis (JL).

Lines marked **(JL)** were decided by JL. Lines marked **(my call)** were chosen by the
author and can be overridden. Lines marked **(unverified)** were not checked against the
live server or the tool and must be proven during the build.

## 1. Why

Every Laravel app on the Hetzner VPS deploys the same way today: `rsync --delete` into the
live directory, then `composer install`, `migrate`, cache rebuilds and a PHP-FPM reload.
That causes:

- **A mixed-version window.** New files are served with the old `vendor/` and the old
  cached config and routes until the remote steps finish. A failed step leaves the site
  broken, with no way back.
- **A shared PHP-FPM reload.** `systemctl reload php8.4-fpm` restarts every app's pool,
  and with the default `process_control_timeout` requests in progress can be cut off.
- **Lost front-end files.** A static SPA's old hashed chunks are deleted, so open tabs
  fail when they lazy-load.
- **No rollback.** The previous version is gone from the server.

## 2. Goals, non-goals, anti-goals

**Goals**

1. A deploy causes no failed request and no visible interruption.
2. A deploy that fails before going live leaves the live site untouched.
3. Rolling back to the previous release is one command and takes seconds.
4. One mechanism for any Laravel app, whatever its front end: Blade, Livewire, Inertia,
   or an API with a separate static SPA.
5. Only free, open-source tools.

**Non-goals**

- Containers, an orchestrator, Octane or FrankenPHP. The stack stays Caddy + PHP-FPM.
- A CI server. Deploys keep starting from JL's workstation.
- More than one server, or load balancing.
- Provisioning a new server (that stays in the `hetzner-server` repo's README).
- Rolling back the database. Rollback is code only; section 6 is what makes that safe.

**Anti-goals** (failure even if it technically works)

- A deploy that can leave an app half old, half new.
- A change to one app's deployment that disturbs another app on the box.
- Secrets (`.env`, keys) in the repository, in logs or in deploy output.
- Weakening the per-app accounts and file ownership introduced on 2026-10-29.

## 3. Constraints and current state

Source: the `hetzner-server` repo (snapshot re-pulled 2026-11-02) and each app's deploy
script. **Live SSH was not available while writing this (unverified).** The builder's
first step is to confirm these facts on the server.

- Ubuntu 24.04, 4 vCPU, 7.6 GB RAM, no swap. SSH alias `deploy-hetzner`, user `deploy`
  (passwordless sudo).
- Caddy 2.11. PHP 8.4-FPM, one pool, socket and system account per app (`app-*`).
- Ownership rules every deploy must respect: the code tree belongs to `deploy` (755);
  `storage/` and `bootstrap/cache/` belong to the app account; `.env` is
  `deploy:app-x` 640; Passport keys are owned by the app account, 0600 (or 660 with ACLs;
  a `chmod 600` silently zeroes an ACL mask).
- Deploys ship the **working tree by rsync** from the workstation. The server never pulls
  from git: the apps' main branches are often not pushed. Front-end assets are built
  locally. `composer install --no-dev` runs on the server.
- Each app has local gates before upload (branch check, release notes, tests, lint). They
  differ per app and stay in each app.

| App | Path today | Stack notes | Worker | Scheduler today |
|---|---|---|---|---|
| Open Brain | `/var/www/openbrain.pellicer.org` | Laravel 12, Vite assets, PostgreSQL, branch `master`, cache/queue/session in DB | supervisor `openbrain-worker` ×2 | supervisor `openbrain-scheduler`, may not be installed (unverified) |
| Belenos | `/var/www/belenos` | Laravel 13, Blade + Alpine, Vite assets, behind Cloudflare, health `/api/health`. `.env.example` says SQLite; production DB unverified | systemd `belenos-worker` | systemd `belenos-scheduler` (`schedule:work`) |
| Code One Pro | `/var/www/cop.pellicer.dev/api` + `/front/dist` | Laravel 13 API + static SPA on one host, Passport keys, own `check-permissions.sh` and health check | systemd `cop-queue-worker` | systemd `cop-scheduler` (`schedule:work`) |
| Quote Master Pro | `/var/www/api.quotemaster.pro` + `/var/www/portal.quotemaster.pro` | Laravel 12 API + static SPA on separate hosts, file cache and sessions, Passport keys, Gotenberg container | supervisor `api-quotemaster-worker` | supervisor `api-quotemaster-scheduler` (`schedule:work`) |

## 4. The mechanism

### 4.1 Layout **(JL)**

Each app keeps its base path and gains three entries:

```
/var/www/<app>/
  releases/<timestamp>/   one directory per deploy, complete before it goes live
  shared/                 what must survive across releases
  current -> releases/<timestamp>
```

- `shared/` holds `.env` and `storage/` (sessions, uploads, logs, Passport keys, file
  cache). If an app keeps other state inside its tree (a SQLite file, for example), that
  goes in `shared/` too.
- Each release gets symlinks to `shared/.env` and `shared/storage`.
- The last 5 releases are kept **(my call)**.

### 4.2 Deploy sequence **(JL for the order; details my call)**

1. **Local gates**, unchanged, in the app's own script.
2. **Local build** of front-end assets where the app has them.
3. **Lock**, so two deploys of one app cannot overlap.
4. **Upload** the working tree by rsync into a new `releases/<timestamp>/`. What is sent
   is configured per app (QMP and Code One Pro use an allowlist; keep that).
5. **Prepare the release**: link `shared/`, `composer install --no-dev`, the app's own
   pre-flight command if it has one (QMP: `deploy:requirements --preflight`).
6. **Migration check** (section 6), then `migrate --force`, then any per-app idempotent
   seeders.
7. **Build caches** in the release: config, routes, views, events.
8. **Switch**: repoint `current` atomically (`ln` to a temporary name, then `mv -T`).
9. **After the switch**: `queue:restart`; per-app hooks.
10. **Health check** against the app's health URL on the box. On failure: point `current`
    back to the previous release, restart the queue again, fail loudly **(my call)**.
11. **Clean up** old releases and unlock.

A failure in steps 4 to 7 stops the deploy. `current` was never touched. One exception
must be stated in the output: if `migrate` already ran, the schema has moved; section 6
is why the old code keeps working.

**Who runs what (my call).** Artisan commands that write into `shared/storage` or the
release's `bootstrap/cache` run as the app account (`sudo -u app-x`), and the release's
`bootstrap/cache` is handed to that account once. This replaces the `chown -R` passes the
current scripts run after every deploy, and it must leave Passport key modes alone.

### 4.3 Web server

Per PHP site in the Caddyfile:

```
root * /var/www/<app>/current/public
php_fastcgi unix//run/php/php8.4-fpm-app-x.sock {
    resolve_root_symlink
}
```

`resolve_root_symlink` makes Caddy hand PHP the real release path instead of the symlink.
The intended result: a request in progress finishes on the release it started on, new
requests use the new release, and opcache treats each release's files as distinct, so
**no PHP-FPM reload is part of a deploy**.

**(unverified)** This behaviour must be proven on the first app before anything else is
moved: a request loop running through several deploys must show zero failures and must
show the new code immediately after the switch. Things to check while doing so:

- compiled Blade views live in shared `storage/framework/views`, and `view:cache` clears
  that directory first. Confirm the old release, still serving for a moment, is not
  disturbed; if it is, change how views are cached.

**Opcache (my call).** All `app-*` pools run under the one `php8.4-fpm` service and share
one opcache. Because every release is a new set of paths, entries of old releases are never
freed, and a full cache stops caching new files. So:

- after the health check passes, the deploy clears opcache **without restarting FPM**
  (`cachetool`, MIT, through the app's socket). No request is dropped; every app on the box
  recompiles its files on the next requests, which is a brief slowdown, not an outage. This
  is accepted as not "disturbing another app" in the sense of section 2;
- opcache memory and file limits are checked on the server and raised if they are at PHP's
  defaults;
- one FPM service per app would isolate the caches completely. It is a larger server change
  and is **not** part of this design; revisit only if the shared clear proves a problem.

**(unverified)** That an opcache clear under load drops no request must be part of the
proof on the first app.

### 4.4 Queue worker **(JL)**

The worker stays under supervisor or systemd. Its unit points at
`/var/www/<app>/current`. After the switch, `queue:restart` makes it finish the job in
hand and exit; the process manager starts it again on the new release. A job queued by
the old code may be run by the new code; apps keep job signatures compatible across one
deploy.

### 4.5 Scheduler **(JL)**

`schedule:work` under supervisor/systemd is replaced by cron, one file per app in
`/etc/cron.d/`, running as the app account every minute:

```
* * * * * app-x  cd /var/www/<app>/current && php artisan schedule:run >> <shared log> 2>&1
```

A run in progress finishes on the old release; the next minute uses the new one; a deploy
never restarts the scheduler. The old scheduler units are removed.

- **Pause switch (my call):** the cron line skips the run while a flag file exists in
  `shared/`, so the scheduler can be paused and resumed without editing cron.
- **Health:** there is no process to inspect, so health is a heartbeat (time of the last
  completed run).
- **Logs** need rotation.

### 4.6 Static SPA front ends **(my call)**

A separately deployed SPA (QMP portal, Code One Pro front) uses the same
`releases/` + `current` layout, with Caddy's `root` on `current`. A new release also
carries the previous release's hashed asset files, so a tab opened before the deploy can
still load its chunks. `index.html` is never cached (already the case).

## 5. The tool: Deployer **(JL)**

Deployer (deployer.org, MIT) provides the release layout, shared paths, locking, rollback
and cleanup, plus a Laravel recipe. It is used as the base rather than writing these again.

- `deploy-kit` is its own repository **(JL)**, installed in each app as a Composer dev
  dependency, `transistorized-cmd/deploy-kit`, straight from GitHub (not Packagist).
- It contains one shared recipe built on Deployer, plus the cron template, the Caddy
  snippet and the cutover runbook.
- Each app has a small `deploy.php`: host, base path, app account, PHP-FPM socket, upload
  list, health URL, worker unit and its manager, whether it has a scheduler, front-end
  build command, extra hooks.
- The kit has two recipes on the same Deployer base: `laravel` and `static-site`. Lock,
  rollback and cleanup behave the same in both.
- The SPA repositories (`quote-master-pro-front`, `bug-tracker-front`) have no Composer.
  They get their own `deploy.php` using the `static-site` recipe and run it with a
  globally installed kit (`composer global require`), so a front end still deploys and
  rolls back on its own, independently of its API **(my call)**.
- Deployer's default "clone from git on the server" step is replaced by the rsync upload.
- The app's existing deploy script stays as the entry point and keeps its gates; its
  rsync-and-ssh section is replaced by a call to the recipe **(my call)**.

**(unverified)** The current Deployer version (8.0.5 per a Packagist mirror) and its PHP
requirement. The builder pins a version after checking the official releases.

## 6. Migration rule and its check **(JL)**

Rule, adopted for every app: **a migration must never break the code that is already
running.**

- Additive changes (new table, new nullable column, new column with a default, new
  index) ship in one deploy with the code that uses them.
- Breaking changes (rename, drop, type change, making a column `NOT NULL`) are split over
  two deploys: the first adds the new shape and stops using the old one; a later one
  removes the old shape.

There is no maintenance-mode path.

**Check in the deploy:** before `migrate`, the recipe lists the pending migrations and
flags any whose source contains a drop, a rename or a column change. It shows the file and
the matching lines and asks for confirmation; a flag allows it explicitly for
non-interactive runs. It is a text heuristic: false positives are acceptable, and it is a
prompt to think, not a proof.

## 7. Moving an app to the new layout (one time per app)

Written as a runbook in `deploy-kit` and rehearsed before each app. It covers:

1. Create `releases/` and `shared/`; move `.env` and `storage/` into `shared/` with the
   ownership rules of section 3.
2. Deploy a first release beside the live tree.
3. Change the Caddyfile for the site and reload Caddy (a graceful reload).
4. Repoint the worker unit to `current`; install the scheduler cron file; remove the old
   scheduler unit.
5. Update everything else that names the old paths. Known: the database backup configs
   read `/var/www/<app>/.env`; supervisor and systemd log paths; log rotation.
6. Copy every changed server file back to the `hetzner-server` repo, as that repo
   requires.
7. Remove the old in-place tree once the app has run on the new layout.

A pause of a few seconds during this one-time move is acceptable **(my call)**; the
zero-downtime goal applies to deploys after it.

## 8. Rollout **(JL)**

In this order, each finished before the next starts:

1. **Open Brain**: proves the recipe and section 4.3.
2. **Belenos**: adds systemd units and Cloudflare in front.
3. **Code One Pro**: adds a static SPA and Passport keys.
4. **Quote Master Pro**: last.

An app is done when: a request loop running during a deploy shows no failed request; the
new code is served right after the switch; a rollback was performed and verified; a
queued job in progress survived a deploy; the scheduler ran across a deploy; the
`hetzner-server` repo matches the server.

### Known extra work for Quote Master Pro

Detailed when its turn comes; listed so nothing is a surprise:

- The System Health page controls the scheduler through supervisor. It changes to a
  heartbeat plus the pause switch, and the related sudoers entries go.
- `docker compose -f docker-compose.gotenberg.yml up -d` derives the container name
  (`apiquotemasterpro-gotenberg-1`, fixed in config and sudoers) from the directory name.
  From a release directory the project name must be set explicitly.
- Cache and sessions are files in `storage/`, so they are shared across releases;
  `queue:restart` relies on that cache.
- Document-template and company-logo files live in `storage/app/private`.
- The deploy script also provisions sudoers, polkit and cron files; those steps stay
  per-app hooks.
- `docs/claude/rollouts.md`, the release-notes gate and the `--recreate-databases` test
  run stay as they are.

- QMP's deploy script and sudoers files still name `www-data`, while the `hetzner-server`
  README says the app runs as `app-qmpapi`. Establish the real state on the server first.

## 9. Left to the builder

- The recipe's internal structure and task names; the Deployer version.
- How uploads are made fast (for example hard-linking against the previous release).
- The exact pattern list of the migration check.
- The format of the scheduler heartbeat and of the pause flag.
- How the kit itself is tested before it touches Open Brain.
