# Cutover runbook: move one app to the deploy-kit layout

Use this once for each app. Do the apps in the agreed order, and finish one before you start the next.

A pause of a few seconds is acceptable during this one-time move. The zero-downtime promise starts
with the first normal deploy after it.

## How to read it

- `<app>` is the app name. `<base>` is its base path on the server, for example `/var/www/<app>`.
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
```

Check:

- You can fill every "confirm" line of the worksheet.
- `<base>` is owned by `deploy`, so `deploy` can create `releases/` and `shared/` in it.
- `.env` is `deploy:<app-user>` mode 640. `storage` belongs to `<app-user>`. Passport keys, if the
  app has them, are owned by `<app-user>` with mode 600 (or 660 with an ACL: never run `chmod 600`
  on a key that has an ACL).
- The pool file `/etc/php/8.4/fpm/pool.d/<app-user>.conf` has the `listen` socket you wrote down.

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
scp templates/scheduler-run.sh deploy-hetzner:/tmp/dk-scheduler-run
ssh deploy-hetzner 'sudo install -m 0755 -o root -g root /tmp/dk-scheduler-run /usr/local/bin/dk-scheduler-run && rm /tmp/dk-scheduler-run'
```

Check: `/usr/local/bin/dk-scheduler-run` with no argument prints a usage line and exits with
code 2 (`/usr/local/bin/dk-scheduler-run; echo $?`).

Back out: `sudo rm /usr/local/bin/dk-scheduler-run`.

2c. Log rotation for the scheduler log. Create `/etc/logrotate.d/<app>-scheduler`:

```
<base>/shared/storage/logs/scheduler.log {
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
`<app-user>`.

Check: `sudo logrotate --debug /etc/logrotate.d/<app>-scheduler` prints no error. (It may say the
file does not exist yet. That is fine: `missingok` is set.)

Back out: `sudo rm /etc/logrotate.d/<app>-scheduler`.

## Step 3. Create `releases/` and `shared/`, move `.env` and `storage/`

The live tree keeps working during this step. `mv` inside one file system keeps owners, modes and
ACLs. A symlink is left at the old place, so the old code still finds both.

Command:

```
cd <base>
mkdir releases shared
mv .env shared/.env
mv storage shared/storage
ln -s shared/.env .env
ln -s shared/storage storage
```

Check:

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
mv shared/storage storage
rmdir shared releases
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

Back out: `rm <base>/current; rm -rf <base>/releases/*` (the live tree is untouched). The
`.dep/` directory can stay.

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
- A static site must answer 404 for `/BAD_RELEASE`, `/.dk_live` and `/.dk-own-assets`:
  `for p in /BAD_RELEASE /.dk_live /.dk-own-assets /.anything; do curl -s -o /dev/null -w "$p %{http_code}\n" https://<domain>$p; done`
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
prints none). Do the three parts in this order, so the scheduler never runs twice at once.

7a. Stop the old scheduler (do not remove it yet).

```
sudo supervisorctl stop <app>-scheduler        # or: sudo systemctl stop <scheduler-unit>
```

If the old unit does not exist, there is nothing to stop. Write that down in the worksheet.

7b. Install the cron file from `templates/scheduler.cron`:

```
sudo tee /etc/cron.d/<app>-scheduler > /dev/null <<'CRON'
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * <app-user> /usr/local/bin/dk-scheduler-run <base> >> <base>/shared/storage/logs/scheduler.log 2>&1
CRON
sudo chmod 644 /etc/cron.d/<app>-scheduler
```

The file name must have no dot. cron ignores names with a dot.

7c. After two minutes, check:

```
cat <base>/shared/scheduler.heartbeat
tail -n 5 <base>/shared/storage/logs/scheduler.log
```

- The heartbeat holds a time less than two minutes old, like `2026-11-10T09:30:01Z`.
- Test the pause switch: `touch <base>/shared/scheduler.paused`, wait two minutes, see that the
  heartbeat does not change, then `rm <base>/shared/scheduler.paused`.
- Only now remove the old unit: delete its supervisor or systemd file, then `sudo supervisorctl update`
  or `sudo systemctl disable <scheduler-unit> && sudo systemctl daemon-reload`.

Back out: `sudo rm /etc/cron.d/<app>-scheduler`, then start the old unit again
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
5. The scheduler ran across a deploy: the heartbeat kept moving.

Then move the old tree aside. Leave it for some days. Delete it only when nothing needed it.

```
cd <base>
mkdir old-tree
find . -mindepth 1 -maxdepth 1 ! -name releases ! -name shared ! -name current ! -name .dep ! -name old-tree -exec mv {} old-tree/ \;
```

This also moves the old `.env` and `storage` links into `old-tree/`. Step 8 must be finished first.

Check: the site, the worker, the scheduler heartbeat and the backup job still work. `ls -A <base>` shows
only `releases`, `shared`, `current`, `.dep` and `old-tree`.

Final removal, after a few quiet days: `rm -rf <base>/old-tree`.

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
| Base path | |
| App account | |
| PHP-FPM socket | |
| Worker unit (manager, name, processes) | |
| Scheduler unit today (or "none") | |
| Database | |
| Health URL | |
| Files that name the old path (backup config, units, sudoers, polkit, logrotate) | |
| Passport keys (yes/no, mode) | |
| Has scheduled tasks (yes/no) | |
| Local gates kept in the app's own script | |
| Preflight command, seeders | |
| Started / finished | |

## Worksheet (example): Open Brain

Taken from the design and the `hetzner-server` repo. "confirm" means: check it on the server in
step 1 before you rely on it.

| Item | Value |
|---|---|
| App | Open Brain (Laravel 12) |
| Domain | `openbrain.pellicer.org` |
| Base path | `/var/www/openbrain.pellicer.org` |
| App account | `app-openbrain` |
| PHP-FPM socket | `/run/php/php8.4-fpm-app-openbrain.sock` |
| Worker unit | supervisor `openbrain-worker`, 2 processes, runs `queue:work --sleep=3 --tries=3 --max-time=3600` as `app-openbrain` |
| Scheduler unit today | none in supervisor; confirm there is no other scheduler (systemd, cron) |
| Database | PostgreSQL, on the 18 cluster, port 5432 |
| Health URL | `/up` on the domain; confirm it answers 200 and is routed to the app in the Caddyfile |
| Files that name the old path | Caddyfile site block (`root`); `/etc/supervisor/conf.d/openbrain-worker.conf` (`command`, `directory`, `stdout_logfile`); backup config `/home/deploy/backups/conf.d/openbrain.conf` (reads `.env` at the base path: change to `<base>/shared/.env`); confirm sudoers, polkit and logrotate files |
| Passport keys | confirm (yes or no, and their mode) |
| Has scheduled tasks | confirm with `schedule:list`; if none, skip step 7 |
| Base path owner and mode | confirm `deploy`, 755 |
| `.env` owner and mode | confirm `deploy:app-openbrain`, 640 |
| Branch and local gates | branch `master`; confirm the gates in the app's deploy script |
| Preflight command, seeders | confirm (none expected) |
| Queue, cache and sessions | in the database (confirm); nothing in `storage/` to lose besides logs and uploads |
| Started / finished | |
