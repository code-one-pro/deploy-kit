#!/usr/bin/env bash
# deploy-kit scheduler runner. Called by cron once a minute, as the app account:
#   dk-scheduler-run <base-path>
# Does nothing when there is no release yet or when the pause flag <base>/shared/storage/scheduler.paused
# exists. The flag and the heartbeat live in shared/storage, which the app account owns, so the app
# account (and an admin page running as it) can create and remove the flag.
# After a successful run it writes the UTC time to <base>/shared/storage/scheduler.heartbeat.
set -eu

if [ "$#" -ne 1 ]; then
    echo "usage: dk-scheduler-run <base-path>" >&2
    exit 2
fi

base=$1

if [ ! -f "$base/current/artisan" ]; then
    exit 0
fi

if [ -e "$base/shared/storage/scheduler.paused" ]; then
    exit 0
fi

cd "$base/current"
php artisan schedule:run
date -u +%Y-%m-%dT%H:%M:%SZ > "$base/shared/storage/scheduler.heartbeat"
