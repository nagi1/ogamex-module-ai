#!/usr/bin/env bash
#
# Real end-to-end trial for the AI module's queue, Horizon, supervisor and container
# wiring. Runs inside the local-docker-dev application container:
#
#   docker compose exec -T ogamex-app bash Modules/AI/scripts/e2e-queue-horizon.sh
#
# It never edits the tracked modules_statuses.json: every command runs against a
# throwaway status file, and the shared test database and Redis are restored on exit.
set -uo pipefail

APP_ROOT="${OGAMEX_ROOT:-/var/www}"
STATUSES="${APP_ROOT}/modules_statuses.json"
BACKUP="/tmp/e2e-statuses.backup.json"
ON="/tmp/e2e-ai-on.json"
OFF="/tmp/e2e-ai-off.json"
QUEUE_CONF="/tmp/e2e-queue-worker.conf"
SUPERVISORD_CONF="/tmp/e2e-supervisord.conf"
SCRATCH="/tmp/e2e-modules"
SCRATCH_ON="/tmp/e2e-scratch-on.json"
SCRATCH_OFF="/tmp/e2e-scratch-off.json"
SCRATCH_OUT="/tmp/e2e-scratch-queue.conf"
CYCLE="/tmp/e2e-cycle.json"
DROP_STATUS="/tmp/e2e-drop.json"
HORIZON_PID=""

FAILS=0
pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILS=$((FAILS + 1)); }
step() { printf '\n== %s ==\n' "$1"; }

# Long command output is captured and matched with bash patterns instead of `grep -q`:
# a pipeline ending in a fast-exiting grep makes the upstream command report a pipe
# error under pipefail even when the pattern matched.
assert_contains() { if [[ "$2" == *"$3"* ]]; then pass "$1"; else fail "$1 (missing: $3)"; fi; }
assert_absent() { if [[ "$2" != *"$3"* ]]; then pass "$1"; else fail "$1 (unexpected: $3)"; fi; }
assert_empty() { if [ -z "$2" ]; then pass "$1"; else fail "$1 (got: $2)"; fi; }
assert_nonempty() { if [ -n "$2" ]; then pass "$1"; else fail "$1 (nothing found)"; fi; }
assert_ok() { if [ "$2" = 0 ]; then pass "$1"; else fail "$1 (failed)"; fi; }
assert_failed() { if [ "$2" != 0 ]; then pass "$1"; else fail "$1 (unexpectedly succeeded)"; fi; }
assert_enabled() { if module_enabled "$2" "$3"; then pass "$1"; else fail "$1 (module is disabled)"; fi; }
assert_disabled() { if module_enabled "$2" "$3"; then fail "$1 (module is still enabled)"; else pass "$1"; fi; }

artisan() { MODULES_STATUSES_FILE="$1" php "${APP_ROOT}/artisan" "${@:2}" 2>&1; }
artisan_redis() { MODULES_STATUSES_FILE="$1" QUEUE_CONNECTION=redis php "${APP_ROOT}/artisan" "${@:2}" 2>&1; }
module_enabled() { php -r 'exit((json_decode((string) file_get_contents($argv[1]), true)[$argv[2]] ?? false) ? 0 : 1);' "$1" "$2"; }

# Drive the real host loader with an explicit module root and status file.
loader_append() {
    local target="$1" root="$2" statuses="$3"
    : > "$target"
    MODULES_ROOT="$root" MODULES_STATUSES_FILE="$statuses" \
        sh -c ". '${APP_ROOT}'/docker/module-hooks.sh; append_module_supervisor_config \"\$1\"" e2e "$target"
}

loader_hooks() {
    MODULES_ROOT="$1" MODULES_STATUSES_FILE="$2" sh -c ". '${APP_ROOT}'/docker/module-hooks.sh; run_module_entrypoint_hooks queue"
}

# Module tables in the connected database, so a drop-data run can be proven to remove
# and a re-install to recreate the real schema.
ai_table_count() {
    mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME}" ${DB_PASSWORD:+-p${DB_PASSWORD}} -N \
        -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}' AND MID(table_name, 1, 3) = 'ai_';" 2>/dev/null
}

cleanup() {
    for pid in "$HORIZON_PID" "${JOB_HORIZON_PID:-}"; do
        [ -n "$pid" ] && kill "$pid" >/dev/null 2>&1
    done

    php "${APP_ROOT}/artisan" horizon:terminate >/dev/null 2>&1 || true
    [ -f "$SUPERVISORD_CONF" ] && supervisorctl -c "$SUPERVISORD_CONF" shutdown >/dev/null 2>&1
    php "${APP_ROOT}/artisan" config:clear >/dev/null 2>&1 || true
    [ -f "$BACKUP" ] && cp "$BACKUP" "$STATUSES"

    # A drop-data run must never leave the shared test database without the module
    # schema, even when the trial is interrupted half way through.
    MODULES_STATUSES_FILE="$DROP_STATUS" php "${APP_ROOT}/artisan" module:migrate AI --force >/dev/null 2>&1 || true

    # The job-throughput step creates one work item and, when needed, one profile.
    if [ -n "${WORK_ITEM_ID:-}" ]; then
        QUEUE_CONNECTION=redis php "${APP_ROOT}/Modules/AI/scripts/e2e-dispatch-ai-job.php" cleanup "$WORK_ITEM_ID" "${PROFILE_ID:-0}" >/dev/null 2>&1 || true
    fi

    rm -rf "$ON" "$OFF" "$BACKUP" "$QUEUE_CONF" "$SUPERVISORD_CONF" "$SCRATCH" "$CYCLE" "$DROP_STATUS" "$SCRATCH_ON" "$SCRATCH_OFF" "$SCRATCH_OUT" /tmp/e2e-modules /tmp/e2e-scratch-* /tmp/e2e-*.log /tmp/e2e-supervisord.*
}
trap cleanup EXIT

cp "$STATUSES" "$BACKUP"
printf '{"HelloWorld": false}' > "$OFF"
printf '{"HelloWorld": false, "AI": true}' > "$ON"
php "${APP_ROOT}/artisan" config:clear >/dev/null 2>&1 || true

echo "AI module E2E — queue / Horizon / supervisor / loader"

step "1. Runtime readiness"
assert_ok "phpredis extension is loaded" "$(php -m | grep -qx redis && echo 0 || echo 1)"
assert_contains "Horizon reaches Redis" "$(artisan_redis "$OFF" horizon:status)" "Horizon is"

step "2. Disabled module leaves no trace"
assert_absent "horizon config has no AI lanes while disabled" \
    "$(MODULES_STATUSES_FILE="$OFF" APP_ENV=production php "${APP_ROOT}/artisan" config:show horizon 2>&1)" "supervisor-ai"
assert_ok "loader accepts a disabled module" "$(loader_append "$QUEUE_CONF" "${APP_ROOT}/Modules" "$OFF" && echo 0 || echo 1)"
assert_empty "disabled module contributes no worker pool" "$(cat "$QUEUE_CONF")"

step "3. Enabled module registers redis Horizon lanes"
for environment in production staging local qa; do
    assert_contains "AI lanes present for APP_ENV=${environment}" \
        "$(MODULES_STATUSES_FILE="$ON" APP_ENV="$environment" php "${APP_ROOT}/artisan" config:show horizon 2>&1)" "supervisor-ai-language"
done

step "4. Generated supervisor pool actually starts the AI worker"
loader_append "$QUEUE_CONF" "${APP_ROOT}/Modules" "$ON"
assert_contains "enabled module contributed its worker pool" "$(cat "$QUEUE_CONF")" "program:ai-queue-worker"

cat > "$SUPERVISORD_CONF" <<EOF
[supervisord]
logfile=/tmp/e2e-supervisord.log
pidfile=/tmp/e2e-supervisord.pid
childlogdir=/tmp

[unix_http_server]
file=/tmp/e2e-supervisor.sock

[supervisorctl]
serverurl=unix:///tmp/e2e-supervisor.sock

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface
EOF
cat "$QUEUE_CONF" >> "$SUPERVISORD_CONF"

supervisord -c "$SUPERVISORD_CONF" >/tmp/e2e-supervisord.out 2>&1
sleep 3
STATUS_OUT="$(supervisorctl -c "$SUPERVISORD_CONF" status 2>&1)"
assert_contains "generated supervisor config starts ai-queue-worker" "$STATUS_OUT" "ai-queue-worker"
[ -n "$STATUS_OUT" ] || printf '%s\n' "    see /tmp/e2e-supervisord.log"
supervisorctl -c "$SUPERVISORD_CONF" shutdown >/dev/null 2>&1 || true

step "5. Horizon provisions the AI supervisors for real"
MODULES_STATUSES_FILE="$ON" php "${APP_ROOT}/artisan" horizon >/tmp/e2e-horizon.log 2>&1 &
HORIZON_PID=$!
sleep 12
SUPERVISORS="$(artisan_redis "$ON" horizon:supervisors)"
assert_contains "Horizon provisioned supervisor-ai" "$SUPERVISORS" "supervisor-ai"
assert_contains "Horizon provisioned supervisor-ai-language" "$SUPERVISORS" "supervisor-ai-language"
php "${APP_ROOT}/artisan" horizon:terminate >/dev/null 2>&1 || true
sleep 2
kill "$HORIZON_PID" >/dev/null 2>&1 || true
HORIZON_PID=""

step "6. Loader edge cases (scratch module root)"
rm -rf "$SCRATCH"
mkdir -p "$SCRATCH/Fake/docker/supervisor/dir-fragment.conf" "$SCRATCH/Fake/docker/entrypoint.d"
printf '[program:fake-worker]\ncommand=sleep 3600\n' > "$SCRATCH/Fake/docker/supervisor/fake.conf"
printf 'echo "entrypoint hook ran for role=$role"\n' > "$SCRATCH/Fake/docker/entrypoint.d/10-fake.sh"
printf '{"Fake": true}' > "$SCRATCH_ON"
printf '{"Fake": false}' > "$SCRATCH_OFF"
printf 'not json at all' > /tmp/e2e-scratch-bad.json
mkdir -p /tmp/e2e-scratch-dir

assert_ok "loader accepts an enabled scratch module" "$(loader_append "$SCRATCH_OUT" "$SCRATCH" "$SCRATCH_ON" && echo 0 || echo 1)"
assert_contains "enabled scratch module contributed its fragment" "$(cat "$SCRATCH_OUT")" "program:fake-worker"
assert_contains "the contribution is attributed to the module" "$(cat "$SCRATCH_OUT")" "contributed by module Fake"
assert_absent "a directory named *.conf is skipped" "$(cat "$SCRATCH_OUT")" "dir-fragment"

assert_ok "loader accepts a disabled scratch module" "$(loader_append "$SCRATCH_OUT" "$SCRATCH" "$SCRATCH_OFF" && echo 0 || echo 1)"
assert_empty "disabled scratch module contributes nothing" "$(cat "$SCRATCH_OUT")"

assert_ok "loader survives a missing status file" "$(loader_append "$SCRATCH_OUT" "$SCRATCH" /tmp/e2e-does-not-exist.json && echo 0 || echo 1)"
assert_empty "a missing status file contributes nothing" "$(cat "$SCRATCH_OUT")"

assert_ok "loader survives a malformed status file" "$(loader_append "$SCRATCH_OUT" "$SCRATCH" /tmp/e2e-scratch-bad.json && echo 0 || echo 1)"
assert_empty "a malformed status file contributes nothing" "$(cat "$SCRATCH_OUT")"

printf '{"Other": true}' > /tmp/e2e-scratch-other.json
assert_ok "loader ignores a module without a status entry" "$(loader_append "$SCRATCH_OUT" "$SCRATCH" /tmp/e2e-scratch-other.json && echo 0 || echo 1)"
assert_empty "a module without a status entry contributes nothing" "$(cat "$SCRATCH_OUT")"

assert_ok "loader survives a status path that is a directory" "$(loader_append "$SCRATCH_OUT" "$SCRATCH" /tmp/e2e-scratch-dir && echo 0 || echo 1)"
assert_empty "a directory status path contributes nothing" "$(cat "$SCRATCH_OUT")"

LOADER_ERR="$(loader_append /tmp/e2e-missing-dir/queue.conf "$SCRATCH" "$SCRATCH_ON" 2>&1)"; LOADER_STATUS=$?
assert_failed "loader fails when the supervisor target cannot be written" "$LOADER_STATUS"
assert_contains "the failure names the unwritable target" "$LOADER_ERR" "not writable"

assert_contains "entrypoint hook runs for an enabled module" "$(loader_hooks "$SCRATCH" "$SCRATCH_ON")" "entrypoint hook ran for role=queue"
assert_empty "entrypoint hook is skipped while disabled" "$(loader_hooks "$SCRATCH" "$SCRATCH_OFF")"

: > "$SCRATCH_OUT"
MODULES_ROOT="$SCRATCH" MODULES_STATUSES_FILE="$SCRATCH_ON" sh -c "PATH=/nonexistent; . ${APP_ROOT}/docker/module-hooks.sh; append_module_supervisor_config '$SCRATCH_OUT'" 2>/tmp/e2e-scratch-php.err
assert_empty "a missing php binary degrades to no contribution" "$(cat "$SCRATCH_OUT")"
assert_contains "the missing php binary is explained on stderr" "$(cat /tmp/e2e-scratch-php.err)" "php is not available"

step "7. Failure paths explain themselves"
assert_contains "install fails for an unknown module by name" "$(artisan "$OFF" ogamex:module:install NotARealModule)" "was not found"
assert_contains "the failure points at module:list" "$(artisan "$OFF" ogamex:module:install NotARealModule)" "module:list"

DEAD_REDIS="$(MODULES_STATUSES_FILE="$OFF" QUEUE_CONNECTION=redis REDIS_HOST=127.0.0.1 REDIS_PORT=1 php "${APP_ROOT}/artisan" ogamex:module:install AI --dry-run 2>&1)"; DEAD_REDIS_STATUS=$?
assert_failed "install fails when Redis cannot be reached" "$DEAD_REDIS_STATUS"
assert_contains "the failure names the Redis connection" "$DEAD_REDIS" "Redis connection failed"
assert_contains "the failure says that nothing was changed" "$DEAD_REDIS" "could not be verified"
assert_contains "the failure points at the doctor command" "$DEAD_REDIS" "ogamex:module:doctor"

DOCTOR_DEAD="$(MODULES_STATUSES_FILE="$OFF" QUEUE_CONNECTION=redis REDIS_HOST=127.0.0.1 REDIS_PORT=1 php "${APP_ROOT}/artisan" ogamex:module:doctor AI 2>&1)"; DOCTOR_DEAD_STATUS=$?
assert_failed "the doctor exits non-zero for a broken environment" "$DOCTOR_DEAD_STATUS"
assert_contains "the doctor summarises the blocking problems" "$DOCTOR_DEAD" "blocking problem"

DOCTOR_OK="$(artisan "$OFF" ogamex:module:doctor AI)"; DOCTOR_OK_STATUS=$?
assert_ok "the doctor exits zero for a healthy environment" "$DOCTOR_OK_STATUS"
assert_contains "the doctor explains that the module is disabled" "$DOCTOR_OK" "disabled"

printf '{"HelloWorld": false}' > "$CYCLE"
MODULES_STATUSES_FILE="$CYCLE" php "${APP_ROOT}/artisan" ogamex:module:install AI --dry-run >/dev/null 2>&1
assert_disabled "a dry run does not enable the module" "$CYCLE" AI
assert_absent "a dry run leaves the status file untouched" "$(cat "$CYCLE")" '"AI": true'

step "8. Install/uninstall dry runs verify docker, shell and redis"
INSTALL_OUT="$(artisan "$OFF" ogamex:module:install AI --dry-run)"
assert_contains "install verifies the runtime and container wiring" "$INSTALL_OUT" "Verify the runtime and container wiring"
assert_contains "install reports the discovered supervisor fragment" "$INSTALL_OUT" "Supervisor fragment: docker/supervisor"
assert_contains "install reports the database queue driver" "$INSTALL_OUT" "Queue driver: database"

REDIS_OUT="$(artisan_redis "$OFF" ogamex:module:install AI --dry-run)"
assert_contains "install detects the redis queue driver" "$REDIS_OUT" "Queue driver: redis"
assert_contains "install detects phpredis" "$REDIS_OUT" "phpredis extension: loaded"
assert_contains "install verifies Redis is reachable" "$REDIS_OUT" "Redis connection: reachable"

UNINSTALL_OUT="$(artisan "$ON" ogamex:module:uninstall AI --dry-run)"
assert_contains "uninstall reports the container cleanup step" "$UNINSTALL_OUT" "Report container cleanup"
assert_contains "uninstall reports the discovered supervisor fragment" "$UNINSTALL_OUT" "Supervisor fragment: docker/supervisor"
assert_contains "uninstall explains that data is retained" "$UNINSTALL_OUT" "keeps its data"

step "9. Real install/uninstall cycle on a throwaway status file"
CACHE="${APP_ROOT}/bootstrap/cache/config.php"

MODULES_STATUSES_FILE="$CYCLE" php "${APP_ROOT}/artisan" config:cache >/dev/null 2>&1
assert_ok "config cache created for the trial" "$([ -f "$CACHE" ] && echo 0 || echo 1)"

INSTALL_REAL="$(artisan "$CYCLE" ogamex:module:install AI)"
assert_contains "real install runs the module migrations" "$INSTALL_REAL" "Run the module migrations"
assert_contains "real install reports success" "$INSTALL_REAL" "is installed and enabled"
assert_enabled "real install enabled the module in the status file" "$CYCLE" AI
assert_ok "real install cleared the config cache" "$([ -f "$CACHE" ] && echo 1 || echo 0)"

MODULES_STATUSES_FILE="$CYCLE" php "${APP_ROOT}/artisan" config:cache >/dev/null 2>&1
assert_ok "config cache recreated before uninstall" "$([ -f "$CACHE" ] && echo 0 || echo 1)"

UNINSTALL_REAL="$(artisan "$CYCLE" ogamex:module:uninstall AI)"
assert_contains "real uninstall reports success" "$UNINSTALL_REAL" "is uninstalled and disabled"
assert_disabled "real uninstall disabled the module in the status file" "$CYCLE" AI
assert_ok "real uninstall cleared the config cache" "$([ -f "$CACHE" ] && echo 1 || echo 0)"

assert_ok "loader accepts the uninstalled module" "$(loader_append "$QUEUE_CONF" "${APP_ROOT}/Modules" "$CYCLE" && echo 0 || echo 1)"
assert_empty "uninstalled module contributes no worker pool" "$(cat "$QUEUE_CONF")"
assert_absent "uninstalled module owns no Horizon lanes" "$(artisan "$CYCLE" config:show horizon)" "supervisor-ai"

step "10. Drop-data and a fresh install rebuild the module schema"
# The module is disabled here on purpose: migrating and resetting must both work
# without the module being enabled first, otherwise a fresh install silently applies
# nothing and a drop-data run silently keeps its tables.
printf '{"HelloWorld": false}' > "$DROP_STATUS"

BEFORE_TABLES="$(ai_table_count)"
assert_ok "module tables exist before the drop-data run (${BEFORE_TABLES})" "$([ "${BEFORE_TABLES:-0}" -gt 0 ] && echo 0 || echo 1)"

DROP_OUT="$(artisan "$DROP_STATUS" ogamex:module:uninstall AI --drop-data --force)"
assert_contains "drop-data rolls every module migration back" "$DROP_OUT" "Roll back every module migration"
assert_contains "drop-data reports success" "$DROP_OUT" "is uninstalled and disabled"
assert_disabled "drop-data disables the module" "$DROP_STATUS" AI

AFTER_TABLES="$(ai_table_count)"
assert_ok "drop-data removed every module table" "$([ "${AFTER_TABLES:-1}" -eq 0 ] && echo 0 || echo 1)"

RESTORE_OUT="$(artisan "$DROP_STATUS" ogamex:module:install AI)"
assert_contains "a fresh install of the disabled module succeeds" "$RESTORE_OUT" "is installed and enabled"
RESTORED_TABLES="$(ai_table_count)"
assert_ok "a fresh install recreated the module schema (${RESTORED_TABLES} tables)" \
    "$([ "${RESTORED_TABLES:-0}" -ge "${BEFORE_TABLES:-1}" ] && echo 0 || echo 1)"

step "11. A real AI job runs through the module Horizon lane"
# The job is queued on the module's own "ai" lane, which exists only while the module
# is enabled, so a state change proves the whole chain: Redis, the Horizon supervisor
# of the module, the module's job class and its tables.
JOB_DISPATCH="$(MODULES_STATUSES_FILE="$ON" QUEUE_CONNECTION=redis php "${APP_ROOT}/Modules/AI/scripts/e2e-dispatch-ai-job.php" dispatch "$ON" 2>&1)"
WORK_ITEM_ID="$(sed -n 's/.*work_item_id=\([0-9]*\).*/\1/p' <<<"$JOB_DISPATCH")"
PROFILE_ID="$(sed -n 's/.*profile_id=\([0-9]*\).*/\1/p' <<<"$JOB_DISPATCH")"
assert_nonempty "a real ProcessAiWork job was queued on the module ai lane" "$WORK_ITEM_ID"

if [ -n "$WORK_ITEM_ID" ]; then
    MODULES_STATUSES_FILE="$ON" QUEUE_CONNECTION=redis php "${APP_ROOT}/artisan" horizon >/tmp/e2e-job-horizon.log 2>&1 &
    JOB_HORIZON_PID=$!

    JOB_STATE=""
    for _ in $(seq 1 12); do
        sleep 2
        JOB_STATE="$(MODULES_STATUSES_FILE="$ON" QUEUE_CONNECTION=redis php "${APP_ROOT}/Modules/AI/scripts/e2e-dispatch-ai-job.php" state "$WORK_ITEM_ID" 2>/dev/null)"

        [[ "$JOB_STATE" == *"state=1"* || "$JOB_STATE" == *"state=2"* ]] || break
    done

    case "$JOB_STATE" in
        *"state=4"*) pass "the module ai lane executed the job end to end (${JOB_STATE})" ;;
        *"state=3"*|*"state=5"*) pass "the module ai lane ran the job and the module recorded the outcome (${JOB_STATE})" ;;
        *"state=1"*) fail "the ai lane never picked the job up (${JOB_STATE})" ;;
        *) fail "the job did not reach a terminal state: ${JOB_STATE:-no state}" ;;
    esac

    php "${APP_ROOT}/artisan" horizon:terminate >/dev/null 2>&1 || true
    sleep 2
    kill "$JOB_HORIZON_PID" >/dev/null 2>&1 || true
    JOB_HORIZON_PID=""

    assert_contains "the trial removed its own work item and profile" \
        "$(QUEUE_CONNECTION=redis php "${APP_ROOT}/Modules/AI/scripts/e2e-dispatch-ai-job.php" cleanup "$WORK_ITEM_ID" "${PROFILE_ID:-0}" 2>&1)" "cleaned"
    WORK_ITEM_ID=""
fi

printf '\n---------------------------------------------\n'
if [ "$FAILS" -ne 0 ]; then
    printf '\033[31mE2E FAILED\033[0m — %s check(s) failed\n' "$FAILS"

    exit 1
fi

printf '\033[32mE2E PASSED\033[0m — all scenarios verified\n'
