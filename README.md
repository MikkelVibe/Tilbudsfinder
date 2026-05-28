# Tilbudsfinder

Tilbudsfinder v2 is a Laravel-based grocery offer discovery platform. It is planned as a focused rewrite of the original .NET/Blazor project with reliable JSON-based grocer imports, normalized unit prices, price history, cached public pages, and a minimal operations/admin surface.

## Stack

- Laravel 13
- PostgreSQL
- Redis
- Vite + Tailwind CSS
- Docker Compose for local/production-style runtime
- GitHub Actions/GHCR for CI/CD later

## Local Setup

This repository includes project-local wrappers for running Laravel on this machine without system PHP/Composer. They use a downloaded FrankenPHP binary in `.tools`, which is intentionally ignored by Git.

Run locally on this machine:

```bash
./scripts/serve-local
```

Then open `http://127.0.0.1:8080`.

Useful local commands:

```bash
./scripts/local-php artisan about
./scripts/local-php artisan migrate
./scripts/local-php vendor/bin/phpunit
./scripts/local-composer install --no-scripts
npm install
npm run build
```

Note: `php artisan test` does not work with the local FrankenPHP wrapper because this runtime reports an empty `PHP_BINARY`, which prevents Laravel from spawning PHPUnit. Use `./scripts/local-php vendor/bin/phpunit` instead.

Standard setup with host PHP/Composer:

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
php artisan migrate
```

With Docker Compose:

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
npm install
npm run build
```

## Planning

The initial technical recommendation is stored in [`v2-technical-recommendation.md`](v2-technical-recommendation.md).
