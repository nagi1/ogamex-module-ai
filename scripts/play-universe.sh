#!/usr/bin/env bash
#
# Reseeds the `grand` play universe (WORK-ORDER, WORLD lane): a fresh schema, the old universe's settings
# (1000x speeds) restored, one administrator, one human player and 20 AI accounts (8 miners, 7 raiders, 5 savers) on
# three galaxies, then the full grand stack (app, scheduler, Horizon queue) started.
#
#   bash Modules/AI/scripts/play-universe.sh reseed [HUMAN_NAME] [HUMAN_EMAIL]   # wipes ogamex-grand
#   bash Modules/AI/scripts/play-universe.sh up | down | status
#
# Credentials are printed once and written to storage/play-universe-credentials.txt (gitignored storage).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
COMPOSE=(docker compose -f "$ROOT/local-docker-dev/docker-compose.grand.yml" --profile queue)
DB=ogamex-grand
MYSQL=(docker exec -i ogamex-shared-data-mysql-1 mysql -uroot)

app() { "${COMPOSE[@]}" exec -T ogamex-app sh -lc "cd /var/www && $*"; }

case "${1:-status}" in
    reseed)
        human="${2:-Orion}"
        email="${3:-orion@play.ogamex.test}"
        docker compose -f "$ROOT/local-docker-dev/docker-compose.data.yml" up -d mysql redis
        # Keep the universe's own settings (speeds, expedition weights), drop placement state.
        settings="$(mktemp)"
        docker exec ogamex-shared-data-mysql-1 mysqldump -uroot --no-create-info --skip-triggers --skip-extended-insert "$DB" settings \
            | grep -v -E "last_assigned_(galaxy|system)|planet_density_tier" >"$settings" || true
        # Nothing may play while the schema is rebuilt.
        "${COMPOSE[@]}" stop ogamex-scheduler ogamex-queue-worker 2>/dev/null || true
        "${COMPOSE[@]}" up -d ogamex-app
        sleep 5
        app php artisan migrate:fresh --force --no-interaction
        "${MYSQL[@]}" -e "DELETE FROM settings" "$DB"
        if [ -s "$settings" ]; then "${MYSQL[@]}" "$DB" <"$settings"; fi
        rm -f "$settings"
        # 1000x unless the restored settings already say otherwise.
        for key in economy_speed research_speed fleet_speed fleet_speed_war fleet_speed_holding fleet_speed_peaceful; do
            "${MYSQL[@]}" -e "INSERT IGNORE INTO settings (\`key\`, value) VALUES ('$key', '1000')" "$DB"
        done
        # Flush the universe's cache so no stale settings or locks survive the wipe.
        app php artisan cache:clear >/dev/null
        out="$ROOT/storage/play-universe-credentials.txt"
        app php Modules/AI/database/seeders/run-play-universe.php "$(printf %q "$human")" "$(printf %q "$email")" | tee "$out"
        grep -q "^HUMAN: " "$out" || { echo "seeding failed; the stack is left stopped" >&2; exit 1; }
        "${COMPOSE[@]}" up -d
        echo "credentials: $out"
        ;;
    up) "${COMPOSE[@]}" up -d ;;
    down) "${COMPOSE[@]}" stop ;;
    status) "${COMPOSE[@]}" ps ;;
    *) echo "usage: $0 reseed [HUMAN_NAME] [HUMAN_EMAIL] | up | down | status" >&2; exit 2 ;;
esac
