#!/usr/bin/env bash
#
# A persistent canary universe: the cheapest honest answer to "do the accounts actually play?".
#
# Booting a universe and waiting out a window inside every pass made the verification the bottleneck —
# minutes of sleep for a check that only needs the last few minutes of work. So the universe stays up:
# it plays continuously on its own scheduler and worker, the harness only restarts it onto new code and
# then asks what it did *since the last check*. That is a second or two, not four minutes.
#
# DEV TOOLING. Its own `ogamex-cap-*` database: never grand, never pve, never the dev stack.
#
#   canary.sh up        boot it if it is not already up (idempotent), then leave it playing
#   canary.sh restart   reload it onto the code on disk (the workers hold classes in memory)
#   canary.sh check     assert what it did since the last check; exit code is the verdict
#   canary.sh status    print the numbers without judging them
#   canary.sh down      stop the stack (the database is kept)
#   canary.sh full      up, play for a window, check, then stop — for an ad-hoc run
#
set -uo pipefail

MODULE="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(cd "$MODULE/../.." && pwd)"
PLAYERS="${CANARY_PLAYERS:-2}"
DB="ogamex-cap-${PLAYERS}"
COMPOSE=(docker compose -f "$ROOT/local-docker-dev/docker-compose.capacity.yml")
export CAP_DB="$DB" CAP_PREFIX="ogamex_cap${PLAYERS}" CAP_HORIZON="ogamex_cap${PLAYERS}_horizon:"
export CAP_PORT="$((9100 + PLAYERS))" CAP_MODE="hybrid"

# How far back a check looks. A rolling window that can never shrink: an interval measured from the
# previous check collapsed to one second when checks landed back to back, and then reported "nothing
# happened" about a universe that was playing fine. Ten minutes always contains a session, costs
# nothing to read, and still attributes the work to the code the canary was reloaded onto.
WINDOW_MINUTES="${CANARY_WINDOW_MINUTES:-10}"

q() {
    MYSQL_PWD="" mysql -h 127.0.0.1 -P 3306 -u root --batch --skip-column-names -e "$1" 2>/dev/null
}

up() {
    if docker ps --format '{{.Names}}' | grep -q "ogamex-capacity-ogamex-queue-worker"; then
        printf '[canary] already playing\n'
        return 0
    fi

    # Reuses the capacity harness for the boot, the bootstrap and the seed; a five second window is
    # enough to get it up and registered, and `keep` leaves it running.
    "$ROOT/local-docker-dev/capacity-run.sh" "$PLAYERS" 5 keep || return 1
}

restart() {
    "${COMPOSE[@]}" restart ogamex-app ogamex-queue-worker >/dev/null 2>&1 || true
    printf '[canary] reloaded onto the code on disk\n'
}

# What it did in the last few minutes. No sleep and no marker: the canary has been working the whole
# time the harness was writing code, so the check only has to read.
check() {
    local since accepted rejected failed orders completed
    since="$(date -u -d "-$WINDOW_MINUTES minutes" '+%Y-%m-%d %H:%M:%S')"

    accepted=$(q "SELECT COUNT(*) FROM \`$DB\`.ai_action_receipts WHERE state = 4 AND created_at >= '$since'")
    rejected=$(q "SELECT COUNT(*) FROM \`$DB\`.ai_action_receipts WHERE state = 3 AND created_at >= '$since'")
    failed=$(q "SELECT COUNT(*) FROM \`$DB\`.ai_work_items WHERE state = 5 AND created_at >= '$since'")
    completed=$(q "SELECT COUNT(*) FROM \`$DB\`.ai_work_items WHERE state = 4 AND created_at >= '$since'")
    orders=$(( $(q "SELECT COUNT(*) FROM \`$DB\`.building_queues WHERE created_at >= '$since'") + \
               $(q "SELECT COUNT(*) FROM \`$DB\`.research_queues WHERE created_at >= '$since'") + \
               $(q "SELECT COUNT(*) FROM \`$DB\`.unit_queues WHERE created_at >= '$since'") ))

    printf '[canary] last %s min: sessions %s, accepted %s, rejected %s, orders %s, failed %s\n' \
        "$WINDOW_MINUTES" "${completed:-0}" "${accepted:-0}" "${rejected:-0}" "${orders:-0}" "${failed:-0}"

    if [ "${rejected:-0}" -gt 0 ] || [ "${failed:-0}" -gt 0 ]; then
        printf '[canary] FAIL the accounts were refused or broke: %s rejected, %s failed\n' "$rejected" "$failed"
        return 1
    fi
    if [ "${completed:-0}" -lt 1 ] || [ "${orders:-0}" -lt 1 ]; then
        printf '[canary] FAIL nothing happened: no session completed and no order reached a queue\n'
        return 1
    fi

    printf '[canary] VERDICT passed — the accounts played and the host accepted their orders\n'
    return 0
}

status() {
    printf '[canary] receipts accepted %s, rejected %s, orders in flight %s, work items failed %s\n' \
        "$(q "SELECT COUNT(*) FROM \`$DB\`.ai_action_receipts WHERE state = 4")" \
        "$(q "SELECT COUNT(*) FROM \`$DB\`.ai_action_receipts WHERE state = 3")" \
        "$(( $(q "SELECT COUNT(*) FROM \`$DB\`.unit_queues WHERE processed = 0") + $(q "SELECT COUNT(*) FROM \`$DB\`.building_queues WHERE processed = 0") ))" \
        "$(q "SELECT COUNT(*) FROM \`$DB\`.ai_work_items WHERE state = 5")"
}

case "${1:-check}" in
    up) up ;;
    restart) restart ;;
    check) check ;;
    status) status ;;
    down) "${COMPOSE[@]}" --profile queue down >/dev/null 2>&1 && printf '[canary] stopped (database kept)\n' ;;
    full)
        up || exit 1
        printf '[canary] playing for %ss before the check\n' "${2:-150}"
        sleep "${2:-150}"
        check
        ;;
    *)
        printf 'usage: canary.sh {up|restart|check|status|down|full [seconds]}\n' >&2
        exit 2
        ;;
esac
