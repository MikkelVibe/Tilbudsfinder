#!/usr/bin/env sh
set -eu

AGENT_DIR="${AGENT_DIR:-${HOME:-/root}/tilbudsfinder-agent}"
ENV_FILE="$AGENT_DIR/.env"
COMPOSE_FILE="$AGENT_DIR/docker-compose.scraper-agent.yml"

if [ ! -f "$ENV_FILE" ]; then
    echo "$ENV_FILE does not exist." >&2
    exit 1
fi

if [ ! -f "$COMPOSE_FILE" ]; then
    echo "$COMPOSE_FILE does not exist." >&2
    exit 1
fi

env_value() {
    key="$1"
    grep "^${key}=" "$ENV_FILE" | tail -n 1 | cut -d= -f2-
}

set_env_value() {
    key="$1"
    value="$2"

    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else
        printf '\n%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

server="$(env_value SCRAPER_AGENT_SERVER)"
token="$(env_value SCRAPER_AGENT_TOKEN)"
current_version="$(env_value SCRAPER_AGENT_VERSION)"

if [ -z "$server" ] || [ -z "$token" ]; then
    echo "SCRAPER_AGENT_SERVER and SCRAPER_AGENT_TOKEN are required." >&2
    exit 1
fi

desired_version="$(curl -fsS -H "Authorization: Bearer ${token}" -H 'Accept: application/json' "${server%/}/api/scraper-agent/version" \
    | sed -n 's/.*"desired_version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"

if [ -z "$desired_version" ]; then
    echo "Server did not return a desired scraper agent version." >&2
    exit 1
fi

if [ "$desired_version" = "$current_version" ]; then
    echo "Scraper agent already current: ${current_version}."
    exit 0
fi

desired_image="ghcr.io/mikkelvibe/tilbudsfinder:${desired_version}"
tmp_env="$(mktemp)"
cp "$ENV_FILE" "$tmp_env"
sed -i "s|^TILBUDSFINDER_IMAGE=.*|TILBUDSFINDER_IMAGE=${desired_image}|" "$tmp_env"
sed -i "s|^SCRAPER_AGENT_VERSION=.*|SCRAPER_AGENT_VERSION=${desired_version}|" "$tmp_env"

cleanup() {
    rm -f "$tmp_env"
}
trap cleanup EXIT

echo "Pulling scraper agent image before updating env: ${desired_image}"

if ! docker compose --env-file "$tmp_env" -f "$COMPOSE_FILE" pull scraper-agent; then
    echo "Image pull failed. Keeping current scraper agent version: ${current_version}." >&2
    exit 1
fi

set_env_value TILBUDSFINDER_IMAGE "$desired_image"
set_env_value SCRAPER_AGENT_VERSION "$desired_version"

echo "Updated scraper agent from ${current_version} to ${desired_version}."
