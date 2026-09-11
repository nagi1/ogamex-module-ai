#!/usr/bin/env bash
#
# Real end-to-end trial for the AI module's queue, Horizon, supervisor and
# container wiring. Runs inside the local-docker-dev application container:
#
#   docker compose exec -T ogamex-app bash Modules/AI/scripts/e2e-queue-horizon.sh
#
# It never edits the tracked modules_statuses.json: it points the module activator
# at throwaway status files and restores everything on exit.
set -uo pipefail

APP_ROOT="${OGAMEX_ROOT:-/var/www}"
ARTISAN="php ${APP_ROOT}/artisan"
STATUSES="${APP_ROOT}/modules_statuses.json"
BACKUP="/tmp/e2e-statuses.backup.json"
ON="/tmp/e2e-ai-on.json"
OFF="/tmp/e2e-ai-off.json"
QUEUE_CONF="/tmp/e2e-queue-worker.conf"
SUPERVISORD_CONF="/tmp/e2e-supervisord.conf"
HORIZON_PID=""

FAILS=0
pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILS=$((FAILS + 1)); }
step() { printf '\n== %s ==\n' "$1"; }
check() { if [ "$1" = "0" ]; then pass "$2"; else fail "$2"; fi; }
expect_failure() { if [ "$1" = "0" ]; then fail "$2"; else pass "$2"; fi; }

# Drive the real host loader with an explicit module root and status file.
loader_append_config() {
    MODULES_ROOT="$2" MODULES_STATUSES_FILE="$3" sh -c '. '"${APP_ROOT}"'/docker/module-hooks.sh; append_module_supervisor_config "$1"' e2e-target "$1"
}

loader_entrypoint_hooks() {
    MODULES_ROOT="$1" MODULES_STATUSES_FILE="$2" sh -c '. '"${APP_ROOT}"'/docker/module-hooks.sh; run_module_entrypoint_hooks queue'
}

status_enabled() {
    php -r 'exit((json_decode((string) file_get_contents($argv[1]), true)[$argv[2]] ?? false) ? 0 : 1);' "$1" "$2"
}

# Number of module tables in the connected database, so a drop-data run can be
# proven to remove (and a re-install to recreate) the real schema.
ai_table_count() {
    mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME}" ${DB_PASSWORD:+-p${DB_PASSWORD}} -N \
        -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}' AND MID(table_name, 1, 3) = 'ai_';" 2>/dev/null
}

cleanup() {
    if [ -n "$HORIZON_PID" ]; then kill "$HORIZON_PID" >/dev/null 2>&1 || true; fi
    if [ -n "${JOB_HORIZON_PID:-}" ]; then kill "$JOB_HORIZON_PID" >/dev/null 2>&1 || true; fi
    php "${APP_ROOT}/artisan" horizon:terminate >/dev/null 2>&1 || true
    if [ -f "$SUPERVISORD_CONF" ]; then supervisorctl -c "$SUPERVISORD_CONF" shutdown >/dev/null 2>&1 || true; fi
    php "${APP_ROOT}/artisan" config:clear >/dev/null 2>&1 || true
    if [ -f "$BACKUP" ]; then cp "$BACKUP" "$STATUSES"; fi

    # A drop-data run must never leave the shared test database without the module
    # schema, even when the trial is interrupted half way through.
    if [ "${DROPPED:-0}" = "1" ]; then
        MODULES_STATUSES_FILE="${DROP_STATUS:-/tmp/e2e-drop.json}" php "${APP_ROOT}/artisan" module:migrate AI --force >/dev/null 2>&1 || true
    fi

    # The job-throughput step creates one work item and, when needed, one profile.
    if [ -n "${WORK_ITEM_ID:-}" ]; then
        QUEUE_CONNECTION=redis php "${APP_ROOT}/Modules/AI/scripts/e2e-dispatch-ai-job.php" cleanup "$WORK_ITEM_ID" "${PROFILE_ID:-0}" >/dev/null 2>&1 || true
    fi

    rm -rf "$ON" "$OFF" "$BACKUP" "$QUEUE_CONF" "$SUPERVISORD_CONF" /tmp/e2e-modules /tmp/e2e-cycle.json /tmp/e2e-cycle-queue.conf /tmp/e2e-missing-dir /tmp/e2e-scratch-on.json /tmp/e2e-scratch-off.json /tmp/e2e-scratch-queue.conf /tmp/e2e-scratch-bad.json /tmp/e2e-scratch-dir /tmp/e2e-scratch-other.json /tmp/e2e-scratch-php.err /tmp/e2e-dry.json /tmp/e2e-drop.json
    rm -f /tmp/e2e-*.log
}
trap cleanup EXIT

cp "$STATUSES" "$BACKUP"
printf '{"HelloWorld": false}' > "$OFF"
printf '{"HelloWorld": false, "AI": true}' > "$ON"
php "${APP_ROOT}/artisan" config:clear >/dev/null 2>&1 || true

echo "AI module E2E — queue / Horizon / supervisor / loader"

step "1. Runtime readiness"
php -m | grep -qx redis; check $? "phpredis extension is loaded"
HORIZON_STATUS="$(php "${APP_ROOT}/artisan" horizon:status 2>&1 || true)"
if echo "$HORIZON_STATUS" | grep -Eq "Horizon is (in)?active"; then
    pass "Horizon reaches Redis"
else
    fail "Horizon cannot reach Redis: $(echo "$HORIZON_STATUS" | head -2 | tr '\n' ' ')"
fi

step "2. Disabled module leaves no trace"
if MODULES_STATUSES_FILE="$OFF" APP_ENV=production php "${APP_ROOT}/artisan" config:show horizon 2>&1 | grep -q "supervisor-ai"; then
    fail "horizon config has AI lanes while disabled"
else
    pass "horizon config has no AI lanes while disabled"
fi

MODULES_STATUSES_FILE="$OFF" MODULES_ROOT="${APP_ROOT}/Modules" sh -c ". ${APP_ROOT}/docker/module-hooks.sh; : > '${QUEUE_CONF}'; append_module_supervisor_config '${QUEUE_CONF}'"
if grep -q "program:ai-queue-worker" "$QUEUE_CONF"; then fail "disabled module contributed a worker pool"; else pass "disabled module contributes no worker pool"; fi

step "3. Enabled module registers redis Horizon lanes"
for environment in production staging local qa; do
    if MODULES_STATUSES_FILE="$ON" APP_ENV="$environment" php "${APP_ROOT}/artisan" config:show horizon 2>&1 | grep -q "supervisor-ai-language"; then
        pass "AI lanes present for APP_ENV=$environment"
    else
        fail "AI lanes missing for APP_ENV=$environment"
    fi
done

step "4. Generated supervisor pool actually starts the AI worker"
MODULES_STATUSES_FILE="$ON" MODULES_ROOT="${APP_ROOT}/Modules" sh -c ". ${APP_ROOT}/docker/module-hooks.sh; : > '${QUEUE_CONF}'; append_module_supervisor_config '${QUEUE_CONF}'"
if grep -q "program:ai-queue-worker" "$QUEUE_CONF"; then pass "enabled module contributed its worker pool"; else fail "enabled module did not contribute a worker pool"; fi

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
if supervisorctl -c "$SUPERVISORD_CONF" status 2>&1 | grep -q "ai-queue-worker.*RUNNING"; then
    pass "generated supervisor config starts ai-queue-worker"
else
    fail "ai-queue-worker did not start; see /tmp/e2e-supervisord.log"
    supervisorctl -c "$SUPERVISORD_CONF" status 2>&1 | sed 's/^/    /'
fi
supervisorctl -c "$SUPERVISORD_CONF" shutdown >/dev/null 2>&1 || true

step "5. Horizon provisions the AI supervisors for real"
MODULES_STATUSES_FILE="$ON" php "${APP_ROOT}/artisan" horizon >/tmp/e2e-horizon.log 2>&1 &
HORIZON_PID=$!
sleep 12
SUPERVISORS="$(php "${APP_ROOT}/artisan" horizon:supervisors 2>&1 || true)"
if echo "$SUPERVISORS" | grep -q "supervisor-ai"; then pass "Horizon provisioned supervisor-ai"; else fail "Horizon did not provision supervisor-ai"; echo "$SUPERVISORS" | sed 's/^/    /'; fi
if echo "$SUPERVISORS" | grep -q "supervisor-ai-language"; then pass "Horizon provisioned supervisor-ai-language"; else fail "Horizon did not provision supervisor-ai-language"; fi
php "${APP_ROOT}/artisan" horizon:terminate >/dev/null 2>&1 || true
sleep 2
kill "$HORIZON_PID" >/dev/null 2>&1 || true
HORIZON_PID=""

step "6. Loader edge cases (scratch module root)"
SCRATCH="/tmp/e2e-modules"
SCRATCH_ON="/tmp/e2e-scratch-on.json"
SCRATCH_OFF="/tmp/e2e-scratch-off.json"
SCRATCH_OUT="/tmp/e2e-scratch-queue.conf"
rm -rf "$SCRATCH"
mkdir -p "$SCRATCH/Fake/docker/supervisor/dir-fragment.conf" "$SCRATCH/Fake/docker/entrypoint.d"
printf '[program:fake-worker]\ncommand=sleep 3600\n' > "$SCRATCH/Fake/docker/supervisor/fake.conf"
printf 'echo "entrypoint hook ran for role=$role"\n' > "$SCRATCH/Fake/docker/entrypoint.d/10-fake.sh"
printf '{"Fake": true}' > "$SCRATCH_ON"
printf '{"Fake": false}' > "$SCRATCH_OFF"

: > "$SCRATCH_OUT"
loader_append_config "$SCRATCH_OUT" "$SCRATCH" "$SCRATCH_ON"; check $? "loader accepts an enabled scratch module"
if grep -q "program:fake-worker" "$SCRATCH_OUT"; then pass "enabled scratch module contributed its fragment"; else fail "enabled scratch module contributed nothing"; fi
if grep -q "contributed by module Fake" "$SCRATCH_OUT"; then pass "contribution is attributed to the module"; else fail "contribution marker missing"; fi
if grep -q "dir-fragment" "$SCRATCH_OUT"; then fail "a directory named *.conf was read as a fragment"; else pass "a directory named *.conf is skipped"; fi

: > "$SCRATCH_OUT"
loader_append_config "$SCRATCH_OUT" "$SCRATCH" "$SCRATCH_OFF"; check $? "loader accepts a disabled scratch module"
if [ -s "$SCRATCH_OUT" ]; then fail "disabled scratch module contributed config"; else pass "disabled scratch module contributes nothing"; fi

: > "$SCRATCH_OUT"
loader_append_config "$SCRATCH_OUT" "$SCRATCH" "/tmp/e2e-does-not-exist.json"; check $? "loader survives a missing status file"
if [ -s "$SCRATCH_OUT" ]; then fail "missing status file still contributed config"; else pass "missing status file contributes nothing"; fi

LOADER_ERR="$(loader_append_config "/tmp/e2e-missing-dir/queue.conf" "$SCRATCH" "$SCRATCH_ON" 2>&1)"
LOADER_STATUS=$?
expect_failure $LOADER_STATUS "loader fails when the supervisor target cannot be written"
if echo "$LOADER_ERR" | grep -q "not writable"; then pass "the failure names the unwritable target"; else fail "the failure does not explain the unwritable target"; fi

HOOKS_ON="$(loader_entrypoint_hooks "$SCRATCH" "$SCRATCH_ON")"
if echo "$HOOKS_ON" | grep -q "entrypoint hook ran for role=queue"; then pass "entrypoint hook runs for an enabled module"; else fail "entrypoint hook did not run for an enabled module"; fi
HOOKS_OFF="$(loader_entrypoint_hooks "$SCRATCH" "$SCRATCH_OFF")"
if echo "$HOOKS_OFF" | grep -q "entrypoint hook ran"; then fail "entrypoint hook ran for a disabled module"; else pass "entrypoint hook is skipped while disabled"; fi

SCRATCH_BAD="/tmp/e2e-scratch-bad.json"
SCRATCH_OTHER="/tmp/e2e-scratch-other.json"
SCRATCH_DIR="/tmp/e2e-scratch-dir"
SCRATCH_PHP_ERR="/tmp/e2e-scratch-php.err"
printf 'not json at all' > "$SCRATCH_BAD"
printf '{"Other": true}' > "$SCRATCH_OTHER"
mkdir -p "$SCRATCH_DIR"

: > "$SCRATCH_OUT"
loader_append_config "$SCRATCH_OUT" "$SCRATCH" "$SCRATCH_BAD"; check $? "loader survives a malformed status file"
if [ -s "$SCRATCH_OUT" ]; then fail "a malformed status file still contributed config"; else pass "a malformed status file contributes nothing"; fi

: > "$SCRATCH_OUT"
loader_append_config "$SCRATCH_OUT" "$SCRATCH" "$SCRATCH_OTHER"; check $? "loader ignores modules that are absent from the status file"
if [ -s "$SCRATCH_OUT" ]; then fail "a module without a status entry still contributed config"; else pass "a module without a status entry contributes nothing"; fi

: > "$SCRATCH_OUT"
loader_append_config "$SCRATCH_OUT" "$SCRATCH" "$SCRATCH_DIR"; check $? "loader survives a status path that is a directory"
if [ -s "$SCRATCH_OUT" ]; then fail "a directory status path still contributed config"; else pass "a directory status path contributes nothing"; fi

: > "$SCRATCH_OUT"
MODULES_ROOT="$SCRATCH" MODULES_STATUSES_FILE="$SCRATCH_ON" sh -c "PATH=/nonexistent; . ${APP_ROOT}/docker/module-hooks.sh; append_module_supervisor_config '$SCRATCH_OUT'" 2>"$SCRATCH_PHP_ERR"
if [ -s "$SCRATCH_OUT" ]; then fail "a module was applied without php available"; else pass "a missing php binary degrades to no contribution"; fi
if grep -q "php is not available" "$SCRATCH_PHP_ERR"; then pass "the missing php binary is explained on stderr"; else fail "the missing php binary is not explained: $(head -2 "$SCRATCH_PHP_ERR" | tr '\n' ' ')"; fi

step "7. Failure paths explain themselves"
MISSING_OUT="$(MODULES_STATUSES_FILE="$OFF" php "${APP_ROOT}/artisan" ogamex:module:install NotARealModule 2>&1)"
MISSING_STATUS=$?
expect_failure $MISSING_STATUS "install fails for an unknown module"
echo "$MISSING_OUT" | grep -q "was not found"; check $? "the failure names the unknown module"
echo "$MISSING_OUT" | grep -q "module:list"; check $? "the failure points at module:list"

DEAD_REDIS_OUT="$(MODULES_STATUSES_FILE="$OFF" QUEUE_CONNECTION=redis REDIS_HOST=127.0.0.1 REDIS_PORT=1 php "${APP_ROOT}/artisan" ogamex:module:install AI --dry-run 2>&1)"
DEAD_REDIS_STATUS=$?
expect_failure $DEAD_REDIS_STATUS "install fails when Redis cannot be reached"
echo "$DEAD_REDIS_OUT" | grep -qi "Redis connection failed"; check $? "the failure names the Redis connection"
echo "$DEAD_REDIS_OUT" | grep -q "could not be verified"; check $? "the failure says that nothing was changed"
echo "$DEAD_REDIS_OUT" | grep -q "ogamex:module:doctor"; check $? "the failure points at the doctor command"

DOCTOR_DEAD="$(MODULES_STATUSES_FILE="$OFF" QUEUE_CONNECTION=redis REDIS_HOST=127.0.0.1 REDIS_PORT=1 php "${APP_ROOT}/artisan" ogamex:module:doctor AI 2>&1)"
DOCTOR_DEAD_STATUS=$?
expect_failure $DOCTOR_DEAD_STATUS "the doctor exits non-zero for a broken environment"
echo "$DOCTOR_DEAD" | grep -q "blocking problem"; check $? "the doctor summarises the blocking problems"

DOCTOR_OK="$(MODULES_STATUSES_FILE="$OFF" php "${APP_ROOT}/artisan" ogamex:module:doctor AI 2>&1)"
DOCTOR_OK_STATUS=$?
check $DOCTOR_OK_STATUS "the doctor exits zero for a healthy environment"
echo "$DOCTOR_OK" | grep -q "disabled"; check $? "the doctor explains that the module is disabled"

DRY_STATUS_FILE="/tmp/e2e-dry.json"
printf '{"HelloWorld": false}' > "$DRY_STATUS_FILE"
MODULES_STATUSES_FILE="$DRY_STATUS_FILE" php "${APP_ROOT}/artisan" ogamex:module:install AI --dry-run >/dev/null 2>&1
status_enabled "$DRY_STATUS_FILE" AI; expect_failure $? "a dry run does not enable the module"
if grep -q '"AI": true' "$DRY_STATUS_FILE"; then fail "a dry run rewrote the status file"; else pass "a dry run leaves the status file untouched"; fi

step "8. Install/uninstall dry runs verify docker + shell + redis"
INSTALL_OUT="$(MODULES_STATUSES_FILE="$OFF" php "${APP_ROOT}/artisan" ogamex:module:install AI --dry-run 2>&1)"
echo "$INSTALL_OUT" | grep -q "Verify the runtime and container wiring"; check $? "install verifies the runtime and container wiring"
echo "$INSTALL_OUT" | grep -q "Supervisor fragment: docker/supervisor"; check $? "install reports the discovered supervisor fragment"
echo "$INSTALL_OUT" | grep -q "Queue driver: database"; check $? "install reports the database queue driver"

REDIS_OUT="$(MODULES_STATUSES_FILE="$OFF" QUEUE_CONNECTION=redis php "${APP_ROOT}/artisan" ogamex:module:install AI --dry-run 2>&1)"
echo "$REDIS_OUT" | grep -q "Queue driver: redis"; check $? "install detects the redis queue driver"
echo "$REDIS_OUT" | grep -q "phpredis extension: loaded"; check $? "install detects phpredis"
echo "$REDIS_OUT" | grep -q "Redis connection: reachable"; check $? "install verifies Redis is reachable"

UNINSTALL_OUT="$(MODULES_STATUSES_FILE="$ON" php "${APP_ROOT}/artisan" ogamex:module:uninstall AI --dry-run 2>&1)"
echo "$UNINSTALL_OUT" | grep -q "Report container cleanup"; check $? "uninstall reports the container cleanup step"
echo "$UNINSTALL_OUT" | grep -q "Supervisor fragment: docker/supervisor"; check $? "uninstall reports the discovered supervisor fragment"
echo "$UNINSTALL_OUT" | grep -q "keeps its data"; check $? "uninstall explains that data is retained"
step "9. Real install/uninstall cycle on a throwaway status file"
CYCLE="/tmp/e2e-cycle.json"
CACHE="${APP_ROOT}/bootstrap/cache/config.php"
printf '{"HelloWorld": false}' > "$CYCLE"

MODULES_STATUSES_FILE="$CYCLE" php "${APP_ROOT}/artisan" config:cache >/dev/null 2>&1
[ -f "$CACHE" ] && pass "config cache created for the trial" || fail "could not cache the config for the trial"

INSTALL_REAL="$(MODULES_STATUSES_FILE="$CYCLE" php "${APP_ROOT}/artisan" ogamex:module:install AI 2>&1)"
echo "$INSTALL_REAL" | grep -q "Run the module migrations"; check $? "real install runs the module migrations"
echo "$INSTALL_REAL" | grep -q "is installed and enabled"; check $? "real install reports success"
status_enabled "$CYCLE" AI; check $? "real install enabled the module in the status file"
if [ -f "$CACHE" ]; then fail "real install left the config cache in place"; else pass "real install cleared the config cache"; fi

MODULES_STATUSES_FILE="$CYCLE" php "${APP_ROOT}/artisan" config:cache >/dev/null 2>&1
[ -f "$CACHE" ] && pass "config cache recreated before uninstall" || fail "could not re-cache the config"

UNINSTALL_REAL="$(MODULES_STATUSES_FILE="$CYCLE" php "${APP_ROOT}/artisan" ogamex:module:uninstall AI 2>&1)"
echo "$UNINSTALL_REAL" | grep -q "is uninstalled and disabled"; check $? "real uninstall reports success"
status_enabled "$CYCLE" AI; expect_failure $? "real uninstall disabled the module in the status file"
if [ -f "$CACHE" ]; then fail "real uninstall left the config cache in place"; else pass "real uninstall cleared the config cache"; fi

: > "$QUEUE_CONF"
loader_append_config "$QUEUE_CONF" "${APP_ROOT}/Modules" "$CYCLE"
if grep -q "program:ai-queue-worker" "$QUEUE_CONF"; then fail "uninstalled module still contributes a worker pool"; else pass "uninstalled module contributes no worker pool"; fi

CYCLE_HORIZON="$(MODULES_STATUSES_FILE="$CYCLE" php "${APP_ROOT}/artisan" config:show horizon 2>&1)"
if echo "$CYCLE_HORIZON" | grep -q "supervisor-ai"; then fail "uninstalled module still owns Horizon lanes"; else pass "uninstalled module owns no Horizon lanes"; fi

step "10. Drop-data and a fresh install rebuild the module schema"
DROP_STATUS="/tmp/e2e-drop.json"
DROPPED=0
# The module is disabled here on purpose: migrating and resetting must both work
# without the module being enabled first, otherwise a fresh install silently
# applies nothing and a drop-data run silently keeps its tables.
printf '{"HelloWorld": false}' > "$DROP_STATUS"

BEFORE_TABLES="$(ai_table_count)"
if [ "${BEFORE_TABLES:-0}" -gt 0 ]; then pass "module tables exist before the drop-data run (${BEFORE_TABLES})"; else fail "module tables are missing before the drop-data run; run [php artisan module:migrate AI] first"; fi

DROP_OUT="$(MODULES_STATUSES_FILE="$DROP_STATUS" php "${APP_ROOT}/artisan" ogamex:module:uninstall AI --drop-data --force 2>&1)"
DROPPED=1
echo "$DROP_OUT" | grep -q "Roll back every module migration"; check $? "drop-data rolls every module migration back"
echo "$DROP_OUT" | grep -q "is uninstalled and disabled"; check $? "drop-data reports success"
status_enabled "$DROP_STATUS" AI; expect_failure $? "drop-data disables the module"

AFTER_TABLES="$(ai_table_count)"
if [ "${AFTER_TABLES:-1}" -eq 0 ]; then pass "drop-data removed every module table"; else fail "drop-data left ${AFTER_TABLES} module table(s) behind"; fi

RESTORE_OUT="$(MODULES_STATUSES_FILE="$DROP_STATUS" php "${APP_ROOT}/artisan" ogamex:module:install AI 2>&1)"
echo "$RESTORE_OUT" | grep -q "is installed and enabled"; check $? "a fresh install of the disabled module succeeds"
RESTORED_TABLES="$(ai_table_count)"
if [ "${RESTORED_TABLES:-0}" -ge "${BEFORE_TABLES:-1}" ]; then pass "a fresh install recreated the module schema (${RESTORED_TABLES} tables)"; else fail "a fresh install only created ${RESTORED_TABLES} of ${BEFORE_TABLES} tables"; fi
DROPPED=0

step "11. A real AI job runs through the module Horizon lane"
# The job is queued on the module's own "ai" lane, which exists only while the module
# is enabled, so a state change proves the whole chain: Redis -> Horizon supervisor-ai
# -> the module's job class -> the module's tables.
JOB_DISPATCH="$(MODULES_STATUSES_FILE="$ON" QUEUE_CONNECTION=redis php "${APP_ROOT}/Modules/AI/scripts/e2e-dispatch-ai-job.php" dispatch "$ON" 2>&1)"
WORK_ITEM_ID="$(echo "$JOB_DISPATCH" | sed -n 's/.*work_item_id=\([0-9]*\).*/\1/p')"
PROFILE_ID="$(echo "$JOB_DISPATCH" | sed -n 's/.*profile_id=\([0-9]*\).*/\1/p')"

if [ -n "$WORK_ITEM_ID" ]; then
    pass "a real ProcessAiWork job was queued on the module ai lane (item ${WORK_ITEM_ID})"
else
    fail "the job could not be queued: $(echo "$JOB_DISPATCH" | tail -2 | tr '\n' ' ')"
fi

if [ -n "$WORK_ITEM_ID" ]; then
    MODULES_STATUSES_FILE="$ON" QUEUE_CONNECTION=redis php "${APP_ROOT}/artisan" horizon >/tmp/e2e-job-horizon.log 2>&1 &
    JOB_HORIZON_PID=$!
    sleep 12

    JOB_STATE=""
    for _ in 1 2 3 4 5 6 7 8 9 10 11 12; do
        JOB_STATE="$(MODULES_STATUSES_FILE="$ON" QUEUE_CONNECTION=redis php "${APP_ROOT}/Modules/AI/scripts/e2e-dispatch-ai-job.php" state "$WORK_ITEM_ID" 2>/dev/null)"
        case "$JOB_STATE" in
            *"state=1"*|*"state=2"*) sleep 2 ;;
            *) break ;;
        esac
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

    JOB_CLEANUP="$(QUEUE_CONNECTION=redis php "${APP_ROOT}/Modules/AI/scripts/e2e-dispatch-ai-job.php" cleanup "$WORK_ITEM_ID" "${PROFILE_ID:-0}" 2>&1)"
    if echo "$JOB_CLEANUP" | grep -q "cleaned"; then pass "the trial removed its own work item and profile"; else fail "the trial left its job fixtures behind"; fi

    WORK_ITEM_ID=""
fi

printf '\n%s\n' "---------------------------------------------"
if [ "$FAILS" -eq 0 ]; then
    printf '\033[32mE2E PASSED\033[0m — all scenarios verified\n'
    exit 0
fi
printf '\033[31mE2E FAILED\033[0m — %s check(s) failed\n' "$FAILS"
exit 1
