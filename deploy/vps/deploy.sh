#!/usr/bin/env sh
set -eu

if [ -z "${TILBUDSFINDER_IMAGE:-}" ]; then
    echo "TILBUDSFINDER_IMAGE is required" >&2
    exit 1
fi

if [ -z "${SCRAPER_AGENT_VERSION:-}" ]; then
    echo "SCRAPER_AGENT_VERSION is required" >&2
    exit 1
fi

APP_DIR="${APP_DIR:-$HOME/tilbudsfinder}"

cd "$APP_DIR"

if [ ! -f .env ]; then
    echo "$APP_DIR/.env does not exist. Create it from deploy/vps/.env.example before deploying." >&2
    exit 1
fi

set_env_value() {
    key="$1"
    value="$2"

    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '\n%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env_value TILBUDSFINDER_IMAGE "$TILBUDSFINDER_IMAGE"
set_env_value SCRAPER_AGENT_VERSION "$SCRAPER_AGENT_VERSION"

mkdir -p backups nginx

docker compose pull app queue scheduler

if docker compose ps --status running postgres | grep -q postgres; then
    timestamp="$(date -u +%Y%m%d%H%M%S)"
    docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"' > "backups/postgres-${timestamp}.sql"
fi

docker compose up -d postgres redis
docker compose run --rm app php artisan migrate --force --no-interaction
docker compose up -d app web queue scheduler
docker compose ps
