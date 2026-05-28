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

This project uses Docker Compose for PostgreSQL and Redis locally so development matches the production data stores. The app can be run through the project-local FrankenPHP wrappers because this machine does not require host PHP/Composer.

Run locally:

```bash
docker compose up -d postgres redis
source scripts/activate
composer install --no-scripts
php artisan migrate
npm install
./scripts/serve-local
```

Then open `http://127.0.0.1:8080`.

Run the frontend dev server in a second terminal when needed:

```bash
npm run dev
```

Useful local commands:

```bash
php artisan about
php artisan migrate
php vendor/bin/phpunit
npm run build
```

Do not use `php artisan test` with the local FrankenPHP wrapper. Use `php vendor/bin/phpunit` after `source scripts/activate` instead.

Standard setup with host PHP/Composer:

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
php artisan migrate
```

Full Docker Compose app runtime:

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
