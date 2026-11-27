# Cutover runbook: move one app to the deploy-kit layout

Use this once for each app. Do the apps in the agreed order, and finish one before you start the next.

A pause of a few seconds is acceptable during this one-time move. The zero-downtime promise starts
with the first normal deploy after it.

## How to read it

- `<app>` is the app name. `<short-name>` is a short name for file names: letters, digits and hyphens only, no dot (see step 7).
- `<base>` is its base path on the server, for example `/var/www/<app>`.
- `<app-user>` is the app's system account, for example `app-<name>`.
- `<socket>` is its PHP-FPM socket, for example `/run/php/php8.4-fpm-app-<name>.sock`.
- `<domain>` is the site's host name.
- Every step has the command, a **Check**, and a **Back out**.
- Run server commands as the `deploy` user, over SSH, with `sudo` where shown.
- Never print `.env`. The commands below only list file names, owners and modes.
- Fill in the worksheet at the end before step 1. Every value you are not sure about must be
  confirmed on the server first.

Keep two notes open while you work: the list of changed server files (for step 9) and the time
you started.

---

## Step 1. Confirm the facts on the server

Command:

```
ls -la <base>
stat -c '%U:%G %a %n' <base> <base>/.env <base>/storage <base>/storage/*.key
sudo supervisorctl status
systemctl list-units --type=service --no-legend | grep -i '<app>'
ls /etc/cron.d
php8.4 -v
umask
sudo -u <app-user> sh -c umask
id deploy
getent group <app-user>
sudo -n -u <app-user> env true && echo env-ok
(cd / && sudo -n -u <app-user> find /dev/null -prune) && echo find-ok
```

Check:

- You can fill every "confirm" line of the worksheet.
- `<base>` is owned by `deploy`, so `deploy` can create `releases/` and `shared/` in it.
- `.env` is `deploy:<app-user>` mode 640. `storage` belongs to `<app-user>`. Passport keys, if the
  app has them, are owned by `<app-user>` with mode 600 (or 660 with an ACL: never run `chmod 600`
  on a key that has an ACL).
- `env-ok` and `find-ok` are printed: the kit runs `env` and `find` as the app account. (`find` is run
  from `/`: it fails from a directory the app account cannot enter, such as the home of `deploy`.)
- The pool file `/etc/php/8.4/fpm/pool.d/<app-user>.conf` has the `listen` socket you wrote down.
- Write down both umask values (for example `0002` or `0022`) and whether `deploy` is a member of the
  `<app-user>` group (`id deploy` lists it, or `getent group <app-user>` names `deploy`). Later steps
  depend on them: who can read files the other account creates (the cached config, the logs), and
  whether `deploy` can read `bootstrap/cache` when it removes an old release.

Back out: nothing has changed yet.

## Step 2. Install the server tools (once per server)

2a. `cachetool` (clears the opcache without restarting PHP-FPM).

1. Open the official releases page of the project: https://github.com/gordalina/cachetool/releases
2. Choose the newest stable release. Write down its version and read the published checksum for
   the phar on that same page. Do not use a version or a checksum from anywhere else.
3. Download the phar and check it:

```
cd "$(mktemp -d)"
curl -fsSLO <phar-url-from-the-release-page>
sha256sum <phar-file>
```

Compare the printed hash with the published checksum. They must be identical. If they differ,
delete the file and stop.

4. Install it:

```
sudo install -m 0755 -o root -g root <phar-file> /usr/local/bin/cachetool
```

Check:

```
/usr/local/bin/cachetool --version
sudo /usr/local/bin/cachetool opcache:status --fcgi=<socket>
```

The second command prints opcache numbers. Look at the memory and file limits. If they are at
PHP's defaults, raise them in the PHP-FPM configuration (a separate change, with its own reload
at a quiet time), because every release adds new file paths.

Back out: `sudo rm /usr/local/bin/cachetool`. Deploys then print a warning and skip the opcache clear.

2b. `dk-scheduler-run`.

```
tmp=$(ssh deploy-hetzner 'mktemp')
scp templates/scheduler-run.sh deploy-hetzner:"$tmp"
ssh deploy-hetzner "sudo install -m 0755 -o root -g root '$tmp' /usr/local/bin/dk-scheduler-run && rm '$tmp'"
```

Check: `/usr/local/bin/dk-scheduler-run` with no argument prints a usage line and exits with
code 2 (`/usr/local/bin/dk-scheduler-run; echo $?`).

Back out: `sudo rm /usr/local/bin/dk-scheduler-run`.

2c. Log rotation for the scheduler log. Create `/etc/logrotate.d/<short-name>-scheduler`:

```
<base>/shared/storage/logs/scheduler.log {
    su <app-user> <app-user>
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

`copytruncate` is used because cron opens the log again every minute, and the file belongs to
`<app-user>`. `su <app-user> <app-user>` is required: the log directory is writable by a group other
than root, and logrotate refuses to rotate there without it.

Check: `sudo logrotate --debug /etc/logrotate.d/<short-name>-scheduler` prints no error. (It may say the
file does not exist yet. That is fine: `missingok` is set. Run the same check again after step 7, when
the log exists.)

Back out: `sudo rm /etc/logrotate.d/<short-name>-scheduler`.

## Step 3. Create `releases/` and `shared/`, move `.env` and `storage/`

The live tree keeps working during this step. `mv` inside one file system keeps owners, modes and
ACLs. A symlink is left at the old place, so the old code still finds both.

Between each `mv` and its `ln -s` the old code cannot find that item, so a request in those few
seconds can fail: this is the short pause the move accepts. Run each command on its own, and run its
check before the next one. Do not paste the block at once: if one command fails, stop there.

`storage` belongs to `<app-user>`, and moving a directory to another parent needs write access to the
directory itself, so `deploy` cannot move it: use `sudo mv` (it keeps owners, modes and ACLs too).

```
cd <base>
mkdir releases shared
```

Check: `ls -ld releases shared` lists both, owned by `deploy`.

```
mv .env shared/.env
```

Check: `test -s shared/.env && echo moved` prints `moved` (it never prints the contents).

```
ln -s shared/.env .env
```

Check: `readlink .env` prints `shared/.env`.

```
sudo mv storage shared/storage
```

Check: `stat -c '%U:%G %a %n' shared/storage` shows the owner and mode from step 1.

```
ln -s shared/storage storage
```

Check: `readlink storage` prints `shared/storage`.

The deploys check this layout: `dk:shared:check` stops a deploy, before anything is created, when
`shared/.env` is missing or empty or `shared/storage` is missing.

Then check the whole step:

```
stat -c '%U:%G %a %n' shared/.env shared/storage shared/storage/*.key
getfacl shared/storage/oauth-private.key 2>/dev/null | head -5
curl -fsS -o /dev/null -w '%{http_code}\n' https://<domain><health-path>
```

- Owners and modes are the same as in step 1.
- The site still answers 200 on its health path.
- Check the application log for new errors: `tail -n 20 <base>/shared/storage/logs/laravel.log`.
- For a static site there is no `.env` and no `storage`: skip the two moves and only create
  `releases`.

Back out:

```
cd <base>
rm .env storage
mv shared/.env .env
sudo mv shared/storage storage
sudo rm -rf shared releases
```

## Step 4. Deploy the first release beside the live tree

On the workstation, add the kit and the app's `deploy.php` (see the README). Then:

```
vendor/bin/dep deploy
```

Caddy still serves the old tree, so the health check here only proves the old site still works. It
does not prove the new release. That comes in step 5.

Check:

```
ssh deploy-hetzner 'ls -la <base> <base>/releases; readlink <base>/current'
ssh deploy-hetzner 'sudo -u <app-user> php <base>/current/artisan --version'
ssh deploy-hetzner 'stat -c "%U:%G %a" <base>/current/.env <base>/current/storage'
```

- `current` points to the new release.
- Artisan runs as `<app-user>`.
- Do not run a second deploy yet.

Back out (the live tree is untouched; the `.dep/` directory can stay). Each release's `bootstrap/cache`
belongs to `<app-user>`, so empty it as that account first, then remove the release as `deploy`:

```
rm <base>/current
for r in <base>/releases/*/; do
    sudo -u <app-user> find "$r"bootstrap/cache -mindepth 1 -delete
    rm -rf "$r"
done
```

For a static site there is no `bootstrap/cache` and no app account: `rm -rf <base>/releases/*` is enough.

## Step 5. Point Caddy at `current`

Command: edit the site block in `/etc/caddy/Caddyfile` using `templates/Caddyfile.snippet`.

```
sudo cp /etc/caddy/Caddyfile /etc/caddy/Caddyfile.pre-kit
sudoedit /etc/caddy/Caddyfile
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

Laravel site: `root * <base>/current/public`, and `resolve_root_symlink` inside `php_fastcgi`.
Static site: `root * <base>/current`, with the marker file rules from the snippet.

Check:

```
curl -fsS -o /dev/null -w '%{http_code}\n' https://<domain><health-path>
```

- It answers 200, and the page shows the new release.
- Static site only. These checks prove the Caddy rules, because the snippet could not be run
  through `caddy validate` when it was written. Every line must match:
  - Marker files and any dotfile answer 404:
    `for p in /BAD_RELEASE /bad_release /.dk_live /.dk-own-assets /.anything; do curl -s -o /dev/null -w "$p %{http_code}\n" https://<domain>$p; done`
    Expected, line by line: `/BAD_RELEASE 404` (its own matcher), `/bad_release 200` (no such file:
    the SPA's index.html, through `try_files`), `/.dk_live 404`, `/.dk-own-assets 404`, `/.anything 404`
    (the dotfile rule).
  - Optional: a path that climbs out of `/.well-known/` is still blocked:
    `curl -s -o /dev/null -w '%{http_code}\n' --path-as-is https://<domain>/.well-known/../.dk_live`
    expect `404`.
  - `/.well-known/` is not blocked by the dotfile rule. The status can be 200 or the SPA's own answer,
    but the body must not be the empty 404 of the rule:
    `curl -si https://<domain>/.well-known/x | head -n 12`
  - A deep SPA path returns the HTML with the no-cache header:
    `curl -sI https://<domain>/some/deep/path | grep -i -e '^HTTP' -e '^cache-control'`
    expect `200` and `Cache-Control: no-cache, no-store, must-revalidate`.
  - A hashed asset returns the immutable header:
    `curl -sI https://<domain>/assets/<one-hashed-file>.js | grep -i -e '^HTTP' -e '^cache-control'`
    expect `Cache-Control: public, max-age=31536000, immutable`.
- Now run a second deploy: `vendor/bin/dep deploy`. This health check goes through the new layout,
  so the release it checks is a proven rollback target.

Back out:

```
sudo cp /etc/caddy/Caddyfile.pre-kit /etc/caddy/Caddyfile
sudo caddy validate --config /etc/caddy/Caddyfile && sudo systemctl reload caddy
```

## Step 6. Move the queue worker to `current`

Skip for a static site and for an app with no queue.

Supervisor: in `/etc/supervisor/conf.d/<app>-worker.conf` change `command` and `directory` to
`<base>/current`, and change `stdout_logfile` to `<base>/shared/storage/logs/worker.log`
(the old `<base>/storage` link goes away in step 10).

```
sudo cp /etc/supervisor/conf.d/<app>-worker.conf /root/<app>-worker.conf.pre-kit
sudoedit /etc/supervisor/conf.d/<app>-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
```

Systemd: change `WorkingDirectory` and `ExecStart` the same way, then
`sudo systemctl daemon-reload && sudo systemctl restart <worker-unit>`.

The worker is stopped and started again. Supervisor waits up to `stopwaitsecs` for a job in hand.
A program with `stopwaitsecs=3600` can make `supervisorctl update` wait up to an hour while a job
finishes. Run the command in a second terminal, or in `tmux`, and do not interrupt it.

Check:

```
sudo supervisorctl status
ps -o user,args -C php | grep queue:work
```

- Every worker process is `RUNNING`, runs as `<app-user>`, and its command names `<base>/current`.
- Set `set('dk_queue', true)` in `deploy.php` (the `laravel` recipe already does).

Back out: restore the saved unit file, `sudo supervisorctl reread && sudo supervisorctl update`
(or the systemd equivalent).

## Step 7. Replace the scheduler with cron

Skip when the app has no scheduled tasks (`sudo -u <app-user> php <base>/current/artisan schedule:list`
prints none). Choose `<short-name>` first: letters, digits and hyphens only (for example `openbrain`). Do not use the
domain or the base-path name if it has a dot. **cron silently ignores any file in `/etc/cron.d` whose name
contains a dot**, so the scheduler would never run and nothing would say why. Do the three parts in this order, so the scheduler never runs twice at once.

7a. Stop the old scheduler (do not remove it yet).

```
sudo supervisorctl stop <app>-scheduler        # or: sudo systemctl stop <scheduler-unit>
```

If the old unit does not exist, there is nothing to stop. Write that down in the worksheet.

7b. Install the cron file from `templates/scheduler.cron`:

```
sudo tee /etc/cron.d/<short-name>-scheduler > /dev/null <<'CRON'
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * <app-user> /usr/local/bin/dk-scheduler-run <base> >> <base>/shared/storage/logs/scheduler.log 2>&1
CRON
sudo chmod 644 /etc/cron.d/<short-name>-scheduler
```

The file name must have no dot (see above).

7c. After two minutes, check:

```
cat <base>/shared/storage/scheduler.heartbeat
tail -n 5 <base>/shared/storage/logs/scheduler.log
```

- The job really fired: the heartbeat holds a time less than two minutes old, like `2026-11-10T09:30:01Z`.
  If the file is missing or old, check the file name (no dot), its mode (644, owner root), and the log.
  The heartbeat is in `shared/storage` because that directory belongs to `<app-user>`; `shared/` itself
  belongs to `deploy`.
- Test the pause switch: `sudo -u <app-user> touch <base>/shared/storage/scheduler.paused`, wait two
  minutes, see that the heartbeat does not change, then `sudo -u <app-user> rm <base>/shared/storage/scheduler.paused`.
- Only now remove the old unit: delete its supervisor or systemd file, then `sudo supervisorctl update`
  or `sudo systemctl disable <scheduler-unit> && sudo systemctl daemon-reload`.

Back out: `sudo rm /etc/cron.d/<short-name>-scheduler`, then start the old unit again
(`sudo supervisorctl start <app>-scheduler`).

## Step 8. Update everything else that names the old paths

Command:

```
sudo grep -rIl '<base>' /etc /home/deploy 2>/dev/null | grep -v '^/etc/ssl'
```

Check each file the command lists. Typical ones:

- Database backup config (`/home/deploy/backups/conf.d/<app>.conf`) reads `<base>/.env`. Change it to
  `<base>/shared/.env`, because the `<base>/.env` link goes away in step 10. Test it:
  `/home/deploy/backups/backup-database.sh /home/deploy/backups/conf.d/<app>.conf` (or the dry-run
  option of that script, if it has one) and check that it finishes without a login error.
- Supervisor and systemd log paths (done in steps 6 and 7).
- Log rotation (done in step 2c).
- Sudoers and polkit files that name `<base>/...`.

Back out: restore each file from its saved copy. Save a copy before every edit.

## Step 9. Copy every changed server file to the `hetzner-server` repo

Copy each file you changed or created on the server into the matching place under `rootfs/` in the
`hetzner-server` repository: the Caddyfile, the supervisor or systemd units, the cron file, the
logrotate file, the backup config. Update its README tables (worker and scheduler rows). Commit.

Check: for each file, `ssh deploy-hetzner 'sudo cat <path>' | diff - rootfs/<path>` prints nothing.

Back out: `git revert` the commit in `hetzner-server`.

## Step 10. Prove it, then remove the old tree

Prove each of these on this app before you delete anything:

1. A request loop shows no failed request during a deploy:
   `while true; do curl -s -o /dev/null -w '%{http_code}\n' https://<domain><health-path>; sleep 0.2; done | sort | uniq -c`
   Run it in one terminal, run `dep deploy` in another, stop the loop, and read the counts. Only
   200 should appear.
2. The new code is served right after the switch.
3. A rollback works: `dep rollback`, then check the site and `readlink <base>/current`. Deploy again to
   go forward.
4. A queued job in progress survives a deploy.
5. The scheduler ran across a deploy: the heartbeat (`<base>/shared/storage/scheduler.heartbeat`) kept moving.
   Skip this for an app with no scheduled tasks (step 7 was skipped).
6. Old releases are removed with two accounts: once five releases exist, a sixth deploy exits 0 and
   `ls <base>/releases` lists five.
7. Compiled views belong to each release. Run `ls <base>/shared/storage/framework/views | wc -l`
   before and after a deploy: the count does not grow. Then change the markup of one Blade page, deploy,
   see the new markup, run `dep rollback`, and see the OLD markup again.
8. Uploaded files are served: a `storage/` image URL of the app (`https://<domain>/storage/<file>`)
   answers 200.

Then move the old tree aside. Leave it for some days. Delete it only when nothing needed it.

```
cd <base>
mkdir old-tree
find . -mindepth 1 -maxdepth 1 ! -name releases ! -name shared ! -name current ! -name .dep ! -name old-tree -exec mv {} old-tree/ \;
```

This also moves the old `.env` and `storage` links into `old-tree/`. Step 8 must be finished first.

Check: the site, the worker, the scheduler heartbeat and the backup job still work. `ls -A <base>` shows
only `releases`, `shared`, `current`, `.dep` and `old-tree`.

Final removal, after a few quiet days: `sudo rm -rf <base>/old-tree` (some files belong to `<app-user>`).

Back out (before the final removal): move the items back with
`find <base>/old-tree -mindepth 1 -maxdepth 1 -exec mv {} <base>/ \;`, then undo steps 5 to 7 in
reverse order.

---

## Worksheet (blank)

Copy this for each app.

| Item | Value |
|---|---|
| App | |
| Domain | |
| Short name (letters, digits, hyphen only; no dot) | |
| Base path | |
| App account | |
| PHP-FPM socket | |
| Worker unit (manager, name, processes) | |
| Scheduler unit today (or "none") | |
| Database | |
| Health URL | |
| Files that name the old path (backup config, units, sudoers, polkit, logrotate) | |
| Passport keys (yes/no, mode) | |
| umask of `deploy` and of `<app-user>` | |
| `deploy` in the `<app-user>` group (yes/no) | |
| Has scheduled tasks (yes/no) | |
| Local gates kept in the app's own script | |
| Preflight command, seeders | |
| Started / finished | |

## Worksheet: Open Brain

Read from the server on 2026-11-10 (read-only). Nothing here is a guess unless it says so.

| Item | Value |
|---|---|
| App | Open Brain (Laravel 12.71) |
| Domain | `openbrain.pellicer.org` |
| Short name (letters, digits, hyphen only; no dot) | `openbrain` (cron file `/etc/cron.d/openbrain-scheduler`; never the domain) |
| Base path | `/var/www/openbrain.pellicer.org` (67 MB: vendor 53 MB, storage 8.4 MB; 26 GB free on the disk) |
| Base path owner and mode | `deploy:www-data` 755. The code tree is owned by `deploy`; it is not a git clone. |
| App account | `app-openbrain` (its group has no other members) |
| PHP | CLI and FPM both 8.4.27 |
| PHP-FPM socket | `/run/php/php8.4-fpm-app-openbrain.sock` (pool `app-openbrain`, `pm = ondemand`, 4 children) |
| Worker unit | supervisor `openbrain-worker`, 2 processes, `queue:work --sleep=3 --tries=3 --max-time=3600` as `app-openbrain`, `stopwaitsecs=3600` |
| Scheduler unit today | **none**: no supervisor program, no systemd unit or timer, no cron entry. `schedule:list` has one task, `recovery:run` daily at 04:00, so it is not running today. Step 7 turns it on. |
| Database | PostgreSQL on `127.0.0.1:5432` |
| Queue, cache and sessions | all `database`; nothing in `storage/` to lose besides logs, uploads and the items below |
| Health URL | `https://openbrain.pellicer.org/up` answers 200 (asked on the server with the host pinned to 127.0.0.1) |
| Caddy site block today | `root * <base>/public`, plain `php_fastcgi` (no `resolve_root_symlink`), `file_server`, `encode gzip`, a `not file` rewrite to `/index.php`, log `/var/log/caddy/openbrain.pellicer.org.log` |
| Files that name the old path | `/etc/caddy/Caddyfile` (`root`); `/etc/supervisor/conf.d/openbrain-worker.conf` (`command`, `directory`, `stdout_logfile`); `/home/deploy/backups/conf.d/openbrain.conf` line 7 (the `.env` path: change to `<base>/shared/.env`); `/etc/php/8.4/fpm/pool.d/app-openbrain.conf` (a comment only, no change needed). Nothing in sudoers, polkit, logrotate or `/etc/cron.d`. |
| `.env` owner and mode | `deploy:app-openbrain` 640 |
| `storage` owner and mode | `app-openbrain:www-data` 775, with an ACL (`deploy` rwx, `caddy` x; default ACL for `deploy` and `app-openbrain`). Moved whole with `sudo mv`, the ACL travels with it. |
| Passport keys | yes: `storage/oauth-private.key` and `oauth-public.key`, `app-openbrain:www-data` 660 **with an ACL**. Never `chmod` them. |
| Other state in `storage/` | `storage/app/sqlite_vec.db` (live, with `-wal` and `-shm` files); `storage/app/gcp/` (a service-account key file); `storage/app/public/thoughts/` (images served through `public/storage`) |
| `bootstrap/cache` today | `deploy:www-data` 775 with an ACL; each new release gets its own, owned by `app-openbrain` |
| `config/view.php` | not present, so Laravel's default reads `VIEW_COMPILED_PATH`; `.env` does not set it |
| umask of `deploy` and of `app-openbrain` | `0002` and `0002` |
| `deploy` in the `app-openbrain` group | no. Files the app account creates are readable by others (umask `0002`), so `deploy` can read them. |
| sudo as the app account | `env` works. `find` works from `/` and exits 1 from `/home/deploy` (mode 750); the kit's cleanup runs it from `/`. `artisan` works from either. |
| Opcache | PHP defaults: 128 MB, 10000 files, `validate_timestamps` on, `revalidate_freq` 2. **Proposed, needs JL's yes:** 256 MB and 30000 files. It needs one PHP-FPM reload, off-hours, outside any deploy. |
| Server tools | Caddy 2.11.7, composer 2.9.3. `cachetool` is not installed (step 2a). |
| Has scheduled tasks | yes (`recovery:run`); step 7 applies |
| Branch and local gates | branch `master`. The app's `deploy.sh` checks the branch and runs `npm ci` and `npm run build`. It runs no tests. |
| Preflight command, seeders | none |
| Left behind at the base path after the move | a stale `sqlite_vec.db` from April 2026 in the code root (the live one is in `storage/app/`), `.superpowers/`, `.DS_Store`, `docs/`, `tests/`: all part of the old tree, kept in the backup of step 3 and not uploaded again |
| Started / finished | 2026-11-11 09:47 / 10:26 server time (CET). `old-tree/` (59 MB) is still at the base path: delete it after a few quiet days with `sudo rm -rf <base>/old-tree`. |

Differences from the `hetzner-server` repo and the design: the group of the code tree and of `storage`
is still `www-data` (access is by owner and ACL, so nothing depends on it); `storage` and the Passport
keys carry ACLs; there is no scheduler of any kind.

### What the Open Brain move showed (2026-11-11)

Proven on the server: 348 requests during six deploys, all 200; `resolve_root_symlink` makes PHP use the
real release path (the opcache held scripts of one release only after each deploy); rollback, twice;
compiled views per release (a changed Blade page showed the old markup after a rollback, and
`shared/storage/framework/views` did not grow); cleanup across the two accounts (five releases left
after the sixth, exit 0); the scheduler heartbeat moved through every deploy; an uploaded image answered
200. Not proven: a long job in progress during a deploy.

Things to know for the next app:

- Deployer numbers releases 1, 2, 3, not by timestamp.
- After `dep rollback`, Deployer marks the release you left as bad, and the next deploy's cleanup deletes
  it. To go forward again, deploy again.
- The first deploy (step 4) is marked live although Caddy did not serve it yet. Do the second deploy of
  step 5 before you rely on a rollback.
- The opcache is shared by every pool, so `dk:opcache:clear` empties it for every app on the server.
  The other apps compile their scripts again on their next requests. No request fails.
- The kit repository was private until 2026-11-27, which needed `preferred-install: source` in the app's
  `composer.json` and broke an app's CI (GitHub Actions cannot read another private repository). It is
  public now, at `code-one-pro/deploy-kit` (the old `transistorized-cmd/deploy-kit` URL redirects).
- Opcache on this server is 256 MB, 30,000 files and a 32 MB strings buffer since 2026-11-11. The
  strings buffer was full at the default 8 MB.

## Worksheet: Code One Pro API

Read from the server on 2026-11-27 (read-only), moved the same day. Only the API moved; the SPA in
`front/dist` keeps its own rsync deploy (`bug-tracker-front/scripts/deploy.sh`).

| Item | Value |
|---|---|
| App | Code One Pro API (Laravel 13.40, Passport, MCP) |
| Domain | `cop.pellicer.dev` (public, behind Cloudflare) and the tailnet site `ubuntu-8gb-nbg1-1.giraffa-velociraptor.ts.net`; both serve the API and the SPA from the same paths |
| Short name (letters, digits, hyphen only; no dot) | `cop` (cron file `/etc/cron.d/cop-scheduler`) |
| Base path | `/var/www/cop.pellicer.dev/api` (65 MB). The SPA lives beside it in `/var/www/cop.pellicer.dev/front/dist` |
| Base path owner and mode | `deploy:www-data` 755; not a git clone |
| App account | `app-cop` (its group has no other members) |
| PHP | CLI and FPM both 8.4.28 |
| PHP-FPM socket | `/run/php/php8.4-fpm-app-cop.sock` (pool `app-cop`, `pm = ondemand`, 4 children) |
| Worker unit | **systemd** `cop-queue-worker`, 1 process, `php artisan queue:work database --sleep=3 --tries=3 --max-time=3600` as `app-cop`, `Restart=always`, log `/var/log/cop/queue-worker.log`, drop-in `cop-queue-worker.service.d/ir-user.conf`. `ExecStart` is relative, so only `WorkingDirectory` changed |
| Scheduler unit today | **systemd** `cop-scheduler` (`schedule:work`), log `/var/log/cop/scheduler.log`. Three tasks: `cop:security-digest` daily 06:00, `cop:security-review` Mondays 06:00, `cop:intakes:abandon` daily 02:30 |
| Database | PostgreSQL `codeonepro` on `127.0.0.1:5432` |
| Health URL | `https://cop.pellicer.dev/.well-known/oauth-authorization-server`. Not `/up`: the public site does not route `/up` to PHP, so the SPA catch-all answers 200 even with PHP down. Caddy's `@not_cloudflare` matcher lets 127.0.0.0/8 through, so the kit's loopback probe works |
| Caddy site blocks today | Two blocks, 11 `handle` sections between them, each `root * <base>/public` + plain `php_fastcgi`. All 11 changed to `current/public` + `resolve_root_symlink`; the SPA handles were left alone |
| Files that name the old path | `/etc/caddy/Caddyfile` (11 roots); `/etc/systemd/system/cop-queue-worker.service` and `cop-scheduler.service` (`WorkingDirectory`); `/etc/php/8.4/fpm/pool.d/app-cop.conf` (a comment only). No backup config, sudoers, polkit or logrotate |
| Backups | **none before the move**: no conf.d file and no cron line. Added `/home/deploy/backups/conf.d/cop.conf` (GCS `gs://database_backups_jlpellicerm/cop`, config `obp`) and two cron lines at minute 16; a dump was taken before step 3 |
| `.env` owner and mode | `deploy:app-cop` 640 |
| `storage` owner and mode | `app-cop:app-cop` 775 with an ACL (`deploy` rwx, `caddy` x; default ACL for `deploy` and `app-cop`) |
| Passport keys | yes, `app-cop:app-cop` **600 with an ACL whose mask is `---`** (a past `chmod 600`). Never `chmod` them. The old deploy script chmodded them on every deploy; the kit never touches `shared/storage` |
| umask of `deploy` and of `app-cop` | `0002` and `0002` |
| `deploy` in the `app-cop` group | no |
| sudo as the app account | `env` and `find` (from `/`) work |
| Has scheduled tasks | yes; step 7 applies |
| Branch and local gates | branch `main`. `scripts/deploy.sh` keeps its gates (main, clean tree, `run-tests-safely.sh --serial`), writes `version.json`, runs `dep deploy`, then `deploy/scripts/check-permissions.sh` against `current` and a public health check through Cloudflare |
| Preflight command, seeders | no preflight. `scripts/deploy.sh --seed` sets `COP_SEED=1`, and `deploy.php` then lists `Database\Seeders\DatabaseSeeder` (idempotent) |
| Started / finished | 2026-11-27 17:05 / 17:20 server time (CET). `old-tree/` (64 MB) is still at the base path: delete it after a few quiet days with `sudo rm -rf <base>/old-tree`. Saved copies: `/etc/caddy/Caddyfile.pre-kit-cop`, `/root/cop-queue-worker.service.pre-kit`, `/root/cop-scheduler.service.pre-kit`, `/home/deploy/backups/crontab.pre-cop`, `conf.d/cop.conf.pre-kit` |

### What the Code One Pro move showed (2026-11-27)

Proven on the server: 142 requests during five deploys and a rollback, all 200 on the health path and
401 on an API path without a token; the opcache held one release only; rollback to release 2 and forward
again; cleanup left five releases, every deploy exit 0; the shared compiled-views count did not grow
(41 before and after); the scheduler heartbeat moved through every deploy and the pause flag stopped it;
the worker restarted into the new release each time (`queue:restart`, then systemd `Restart=always`).
Not proven: a long job in progress during a deploy. Not applicable: uploaded files (`storage/app/public`
is empty and the public site does not route `/storage`).

Things to know for the next app:

- **Keep the request loop under the app's rate limits.** The first loop hammered a throttled endpoint
  from 127.0.0.1, the same address as the kit's loopback probe; the probe got 429 and the kit rolled back
  twice. That was the safety net working, not a fault, but read the app's `RateLimiter::for` limits
  first and pace the loop well below them (here 60 per minute per IP: one request every 2 s).
- In step 10, `pkill -f <pattern>` also matches the SSH command that runs it when the pattern appears
  anywhere in that command. Stop a loop in its own SSH call.
- A systemd worker with `Restart=always` needs no change besides `WorkingDirectory`: `queue:restart`
  makes it exit, and systemd starts it again in the release `current` points to.
- An app check that `stat`s `.env` or `storage/` must follow links (`stat -L`): in a release they are
  symlinks into `shared/`, and a link's own mode is 777.
- Check that the app's database is in the backup jobs before step 3. Code One Pro's was not.
- Compare the `hetzner-server` copy with the server before step 9; commit any older drift separately.
