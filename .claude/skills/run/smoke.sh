#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"

PORT="${PORT:-8000}"
HOST="0.0.0.0"
CHECK_HOST="127.0.0.1"
LOG="/tmp/weapon-master-serve.log"

if [ ! -d vendor ]; then
    composer install
fi

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate
fi

if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
    php artisan migrate --graceful --force
fi

# Free the port if a previous run is still holding it.
lsof -ti:"$PORT" -sTCP:LISTEN 2>/dev/null | xargs -r kill || true

php artisan serve --host="$HOST" --port="$PORT" &> "$LOG" &
SERVER_PID=$!

READY=0
for _ in $(seq 1 30); do
    if curl -sf "http://$CHECK_HOST:$PORT/" > /dev/null 2>&1; then
        READY=1
        break
    fi
    sleep 1
done

if [ "$READY" -ne 1 ]; then
    echo "Server failed to become ready. Log:" >&2
    cat "$LOG" >&2
    exit 1
fi

echo "Server up: http://$HOST:$PORT (pid $SERVER_PID, log $LOG)"
