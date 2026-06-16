# scalar-openapi-doc

[![CI](https://github.com/padosoft/scalar-openapi-doc/actions/workflows/tests.yml/badge.svg)](https://github.com/padosoft/scalar-openapi-doc/actions/workflows/tests.yml)
[![Lint](https://github.com/padosoft/scalar-openapi-doc/actions/workflows/lint.yml/badge.svg)](https://github.com/padosoft/scalar-openapi-doc/actions/workflows/lint.yml)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](./LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4.svg?logo=php)](https://www.php.net/releases/8.5/en.php)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20.svg?logo=laravel)](https://laravel.com/)
[![React](https://img.shields.io/badge/React-19-61DAFB.svg?logo=react)](https://react.dev/)

Secure, role-aware API documentation portal built with **Laravel 13 + Inertia 3 + React 19 + TypeScript + Tailwind 4 + shadcn/ui**, using **Scalar** for OpenAPI rendering.

## Table of Contents

- [What this project solves](#what-this-project-solves)
- [Innovations](#innovations)
- [Key Features](#key-features)
- [Architecture](#architecture)
- [Quick Start (clean clone)](#quick-start-clean-clone)
- [Environment](#environment)
- [Testing & Quality Gates](#testing--quality-gates)
- [Security Notes](#security-notes)
- [Roadmap](#roadmap)
- [Contributing](#contributing)

## What this project solves

This app exposes internal APIs through a safe, filter-on-the-fly docs layer:

- authenticate users with Fortify (`admin`, `user` roles),
- define what each user can see (tags + operation grants),
- define which playground servers each user can use (per-user server grants, deny-by-default),
- serve only the granted operations and granted servers from `openapi.json`,
- and block direct access to unauthorized routes and actions in both backend and UI.

The **full spec is never sent to non-admin users**; filtering happens server-side.

## Innovations

- **Server-side per-user spec filtering**
  - A user receives only operations authorized by tags/endpoint grants.
- **UNION semantics for grants**
  - `tag` grant OR `endpoint` grant authorization is enough to keep an operation.
- **Per-user server grants (deny-by-default)**
  - Each user sees only the playground servers granted to them; admins see all active servers, and a user with no grants sees none.
- **Transitive component pruning**
  - Full `$ref`/callback/webhook/schema reachability pruning avoids hidden-component leaks and dangling references.
- **Anti-tampering grant validation**
  - User-submitted grants are checked against the live upstream spec before persistence.
- **Stale-on-error upstream fallback**
  - Proxy keeps serving last known-good spec if upstream is unavailable or malformed.
- **Audit and hardening by default**
  - Auth events (`login`, `logout`, `failed`) are persisted; cache headers, CSRF, rate limit and mass-assignment hardening are enforced.

## Key Features

- RBAC with `spatie/laravel-permission` and Fortify auth.
- `/scalar` docs page behind role checks.
- `/api-docs/openapi.json` proxy endpoint:
  - filters spec per viewer grants,
  - injects only the active playground servers granted to the viewer (admins see all active; users see only their grants; none ⇒ no servers),
  - always returns `Cache-Control: private, no-store`.
- Admin dashboard:
  - users CRUD + grant management (tags/endpoints/servers),
  - server catalog management,
  - authentication log viewer,
  - cache flush UI.
- OpenAPI hardening:
  - SSRF checks for upstream URL,
  - strict spec validation before cache,
  - stale cache controls.

## Architecture

- **Backend**: Laravel 13, PHP 8.5, Pest, Larastan, Fortify, Spatie Permission.
- **Frontend**: Inertia 3 + React 19 + TypeScript + Tailwind 4 + shadcn/ui.
- **Docs UI**: Scalar + custom auth-aware shell.
- **Cache**: Redis in dev (Herd), array in tests.
- **DB**: MySQL in dev (Herd), SQLite memory in tests.

## Quick Start (clean clone)

These steps are the verified local bootstrap:

1. Clone and enter the repo

```bash
git clone https://github.com/padosoft/scalar-openapi-doc.git
cd scalar-openapi-doc
```

2. Install backend/frontend deps

```bash
composer install
npm install
```

3. Prepare environment

```bash
cp .env.example .env        # Windows: copy .env.example .env
php artisan key:generate
```

4. Configure `.env`

- Confirm the following before first run:
  - `DB_DATABASE=scalar_openapi_doc`
  - `CACHE_STORE=redis`
  - `ADMIN_EMAIL`, `ADMIN_PASSWORD` for seeding/admin login
  - `OPENAPI_UPSTREAM_URL` points to your real spec source
  - `OPENAPI_ALLOWED_HOSTS` includes every approved upstream host

5. Migrate + seed

```bash
php artisan migrate
php artisan db:seed --class=DatabaseSeeder --force
```

6. Start services

```bash
php artisan serve
npm run dev
```

7. Open app

- Go to `http://127.0.0.1:8000`
- Login with `ADMIN_EMAIL` / `ADMIN_PASSWORD`
- Admin can open: dashboard, users, servers, auth logs, cache controls.
- Users can only access authorized docs and APIs.

> Optional default: `OPENAPI_LOGIN_RATE_LIMIT_ATTEMPTS` in `.env.example` is set to `5` for starter hardening.

## Environment

Copy and keep **`.env.example`** as your template (do not commit real secrets in `.env`).

- PHP: `8.5` recommended (Herd)
- Node: modern LTS (CI runs on latest stable for this repo)
- Redis: required for local caching (`CACHE_STORE=redis`)
- Queue: database queue (`QUEUE_CONNECTION=database`)
- Tests: Laravel config automatically uses SQLite `:memory:` and `CACHE_STORE=array`.

## Testing & Quality Gates

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --level=max
php artisan test
npm run test
npm run build
npx playwright test
```

For E2E on environments that can reuse an existing dev server:

```bash
CI=1 npx playwright test
```

## Security Notes

- `auth` and role checks are enforced in routes/controllers.
- The client never decides authorization; backend decides what to expose.
- Grants are normalized and validated against the current spec before save.
- Per-user server grants are deny-by-default and anti-tampered server-side (only active or already-assigned servers are grantable); injected servers are filtered per viewer.
- Spec proxy is hardened with host/scheme allow-lists, method allow-lists, and malformed-server filtering.
- Auth events are immutable and logged server-side.

## Roadmap

- [x] Core auth + RBAC + role-based permissions
- [x] Server-side filtering + scalar proxy
- [x] Admin user + server + auth-log management
- [x] Per-user server grants (deny-by-default)
- [x] Hardening + full E2E + security polish
- [x] WOW README and release knowledge consolidation

## Contributing

Follow the repository operating contract in [`AGENTS.md`](./AGENTS.md) before opening PRs:

- read `docs/PLAN.md`, `docs/PROGRESS.md`, `docs/LESSON.md`, `docs/RULES.md`
- keep quality gates green on every subtask
- use the PR review loop defined there
- release notes: [`CHANGELOG.md`](./CHANGELOG.md)
