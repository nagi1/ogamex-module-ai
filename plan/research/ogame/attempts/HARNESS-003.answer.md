### FILE: docker/entrypoint.sh
```bash
#!/usr/bin/env bash
#
# Container entrypoint: with no arguments it runs the Laravel scheduler in the
# foreground, with arguments it execs them (php-fpm, horizon, a queue worker).
#
# Laravel decides which passes are due by matching each schedule's cron
# expression against the wall-clock minute, so an hourly or every-ten-minutes
# event fires only when an invocation of `schedule:run` happens to start inside
# the minute the expression names. A loop of `schedule:run; sleep 60` drifts
# later by the runtime of every run; once the drift crosses a minute, part of
# each minute is spent inside `sleep`, the ten-minute and hourly passes never
# see their own minute again and silently stop firing while the every-minute
# passes keep running. Sleeping to the top of the next minute re-aligns every
# iteration with the boundaries the cron expressions are evaluated against.
#
# This file is baked into the image, so a change to it needs a rebuild, not a
# container restart.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html}"
PHP_BIN="${PHP_BIN:-php}"

log() {
    printf '[entrypoint] %s\n' "$*"
}

# Seconds left in the current minute, always 1..60: the loop never busy-spins
# and every `schedule:run` starts on the :00 second a cron expression matches.
seconds_until_next_minute() {
    local second
    second=$(( $(date +%s) % 60 ))
    printf '%s\n' "$((60 - second))"
}

run_scheduler() {
    while true; do
        sleep "$(seconds_until_next_minute)"
        "$PHP_BIN" artisan schedule:run --verbose --no-interaction || log 'schedule:run exited non-zero'
    done
}

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

if [ -d "$APP_DIR" ]; then
    cd "$APP_DIR"
fi

log "scheduler loop starting (${PHP_BIN} artisan schedule:run)"
run_scheduler
```