# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Full setup (install deps, generate key, migrate, build assets)
composer setup

# Run development server (concurrent: artisan serve, queue:listen, pail, vite dev)
composer dev

# Run all tests
composer test

# Run a single test file or method
php artisan test tests/Feature/ExampleTest.php
php artisan test --filter=test_method_name

# Build frontend assets
npm run build
npm run dev

# Lint PHP code
./vendor/bin/pint

# Database operations
php artisan migrate
php artisan db:seed
php artisan migrate:fresh --seed
```

## Architecture

This is a **Laravel 13** application (PHP 8.3+) using SQLite by default, with database-backed sessions, cache, and queue.

**Bootstrap:** Application routing, middleware, and exception handling are configured in `bootstrap/app.php` using Laravel 13's fluent API — not `app/Http/Kernel.php`.

**Models:** Use PHP 8 attributes (`#[Fillable]`, `#[Hidden]`) instead of class property arrays. See `app/Models/User.php` for the pattern.

**Frontend:** Vite + Tailwind CSS v4. Assets entry points are `resources/css/app.css` and `resources/js/app.js`. The dev server (HMR) is started as part of `composer dev`.

**Testing:** PHPUnit with SQLite in-memory database. Unit tests in `tests/Unit/`, HTTP/feature tests in `tests/Feature/`. Test environment configured in `phpunit.xml`.

**Queue/Cache/Sessions:** All database-backed. Migrations for `jobs`, `cache`, `sessions` tables are in `database/migrations/`.
