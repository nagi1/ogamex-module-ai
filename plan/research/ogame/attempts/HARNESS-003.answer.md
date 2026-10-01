### FILE: resources/docker/entrypoint.sh
```sh
#!/bin/sh
set -eu

# One entrypoint for every cohort container; the role decides what it runs. The host
# image copies this file in from the module (COPY modules/AI/resources/docker/entrypoint.sh
# /usr/local/bin/entrypoint.sh) and every role execs it, so the scheduler loop is fixed
# here once instead of per deployment.
#
# The scheduler role runs `schedule:work`, never the old
# `php artisan schedule:run --verbose; sleep 60` loop. That loop's phase drifts through
# the minute: an event due at minute 0 or minute 10 only ran when an invocation happened
# to start inside that minute, and the fixed 60 second sleep then left a dead window after
# every run. Measured 30 Sep 2026 on grand, both `ai:record-score-samples` and the two
# everyTenMinutes passes (`ai:advance-alliance-life`, `ai:reconcile-language-requests`)
# went a day without dispatch while every '*'-minute and sub-minute event kept running,
# which killed the growth curve and stopped alliance life advancing. `schedule:work`
# sleeps to the next due event instead of a fixed minute, so an aligned event can no
# longer be skipped.
#
# This file lives in the image, so the loop only changes after a rebuild
# (`docker compose build`); a container restart keeps running the old entrypoint.

role="${CONTAINER_ROLE:-web}"

case "$role" in
    scheduler)
        # Straight into the schedule worker: the first due event is waited for, not slept past.
        exec php artisan schedule:work
        ;;
    horizon)
        exec php artisan horizon
        ;;
    worker)
        exec php artisan queue:work --tries=3 --timeout=90
        ;;
    web)
        exec php-fpm
        ;;
    *)
        echo "unknown CONTAINER_ROLE: $role" >&2
        exit 1
        ;;
esac
```