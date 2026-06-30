# Changelog

## v1.0.8 — 2026-06-30

### Fixed
- **The portal no longer 500s when its own infrastructure hiccups.** Production hit a dropped Aurora connection during a DB-backed cache write (`SQLSTATE[HY000] 2013 Lost connection ... 'handshake'`), and the log socket was down too (`Could not write to socket`) — so opening the API docs **or** a user edit page returned a raw 500. Root cause was infra (DB + logging), not the external Scalar API. Hardened end-to-end:
  - **Spec loading never throws.** `OpenApiSpecService::tryFetchRaw()` returns a categorized, **redacted** result (`Database` / `ExternalApi` / `InvalidSpec` / `Unknown`) instead of an exception. A successful upstream fetch is no longer discarded when persisting it to the cache fails.
  - **Docs page degrades to a clear message.** When the spec can't be loaded, `/api-docs/openapi.json` returns a valid HTTP 200 "⚠️ API documentation temporarily unavailable" OpenAPI document (reason in the description) so Scalar renders a clean page instead of breaking.
  - **User create/edit degrades, never crashes.** The form shows a non-blocking warning banner when the grant catalog is unavailable; name/email/role/password stay editable and existing grants remain selectable (grants are still re-validated server-side).
  - **A dead log channel can't crash a request.** The production log `stack` now sets `ignore_exceptions=true` (Monolog `WhatFailureGroupHandler`).
  - **A lost DB connection renders one clean 503 page** app-wide (shown at login and everywhere) instead of a stack trace.
- **Security:** failure detail surfaced to users is always redacted — never the DB host, SQL, upstream URL, or auth token. Only the category label, HTTP status, and framework exception class are shown.

### Changed
- **Spec cache can live off the database.** New `OPENAPI_CACHE_STORE` (default: app cache store). Set it to `file` in production to keep the large OpenAPI document (hundreds of KB) off a DB-backed store at no extra infrastructure cost — the copy is rebuildable, so a cache miss simply re-fetches from upstream.

## v1.0.7 — 2026-06-16

### Fixed
- **Auth logs only ever showed failed logins.** The `Login`/`Logout` listeners implemented `ShouldQueue`, but production runs `QUEUE_CONNECTION=database` with no always-on worker, so those audit rows were queued and never written — only the (synchronous) `failed` listener persisted. Filtering by `login`/`logout` then returned an empty result that read as a blank page. The two listeners now persist **synchronously** (a single lightweight insert), so every login, logout and failed attempt is recorded even without a queue worker. Regression-guarded by a Pest test that forces `queue.default=database`.
- **Auth logs filter UX.** The page now exposes the **From/To date range** inputs (previously the date filter params were sent in camelCase and silently ignored by the controller), resets pagination when filters change so a narrower filter never lands on an out-of-range empty page, makes the `end_date` bound inclusive of the whole day, and shows an explicit empty-state message instead of a bare table.

### Confirmed (already correct)
- The "Auth Logs" sidebar link and the `/auth-logs` route are **admin-only** (gated by `auth.isAdmin` and the `role:admin` middleware).
- Admins see authentication events for **all users**, not just their own (the controller applies no `user_id` scope). Added test coverage for both.

## v1.0.6 — 2026-06-16

### Changed
- **CI now installs with `npm ci` (strict).** All workflow jobs (lint, frontend, e2e) switched from `npm install` (tolerant — silently repairs the lockfile) to `npm ci --no-audit --no-fund`, the same strict install the deploy uses. Any `package.json` ↔ `package-lock.json` drift now fails fast in PR instead of reaching deploy. Verified the regenerated lockfile is `npm ci`-clean on Linux (node:22 container) before enabling.

## v1.0.5 — 2026-06-16

### Fixed
- **Deploy-breaking lockfile.** The `package-lock.json` regenerated in v1.0.4 was internally inconsistent — `npm ci` (used by the deploy) aborted with `Missing: @emnapi/wasi-threads@1.2.1 from lock file`. CI uses `npm install` (tolerant) so it stayed green, but the deploy uses `npm ci` (strict). Regenerated the lockfile from a clean install; `npm ci` now resolves cleanly. **Deploy from v1.0.4 will fail — use v1.0.5.**

### Changed
- **API Reference opens in a new tab.** The sidebar "API Reference" link now opens the Scalar docs in a new browser tab (`target="_blank"` + `rel="noopener noreferrer"`) instead of replacing the portal, so the docs load alongside the admin portal.

## v1.0.4 — 2026-06-16

### Security
- **Resolved all open npm audit advisories (2 critical, 5 high).** All were dev/build transitive dependencies (`composer audit` was already clean):
  - **esbuild** (`GHSA-gv7w-rqvm-qjhr`, `GHSA-g7r4-m6w7-qqqr`) — a stale `package-lock.json` still encoded vitest 3.x's bundled vite + esbuild tree; bumped `vitest` to `^4.1.9` (vite 8 / rolldown, esbuild no longer in the tree).
  - **shell-quote** (`GHSA-w7jw-789q-3m8p`) via `concurrently` — bumped `concurrently` to `^10.0.3` (patched `shell-quote` 1.8.4).
- `npm audit` now reports **0 vulnerabilities**.

### Changed
- Refreshed all remaining in-range minor/patch dependencies (`@playwright/test` 1.61, Radix UI, `typescript-eslint`, …) and regenerated the lockfile. Major upgrades (eslint 10, TypeScript 6, `@vitejs/plugin-react` 6, `lucide-react` 1.x) are intentionally deferred to dedicated migration work.

## v1.0.3 — 2026-06-16

### Added
- **Granted server count on the admin users list.** The `/admin/users` grants column now shows `Servers: X selected` alongside Tags and Endpoints, so an admin can tell at a glance whether a user is restricted to specific playground servers — without opening the edit form. Covered by Pest (index serialization) and Playwright (list-row display).

## v1.0.2 — 2026-06-16

### Added
- **Per-user server grants.** Admins can now assign specific playground servers to each user via a new "Granted servers" picker on the user form. Visibility is **deny-by-default**: admins see all active servers in the spec's `servers` list, a user sees only the active servers granted to them, and a user with no grants sees none. Backed by a `user_allowed_servers` pivot with server-side anti-tampering (only active or already-assigned servers are grantable).

## v1.0.1 — 2026-06-16

### Fixed
- **API Reference opened a blank modal.** The sidebar linked to `/scalar` with an Inertia client-side visit, but `/scalar` is a non-Inertia page (rendered by `scalar/laravel`). Inertia displayed it inside its sandboxed error-modal iframe (opaque origin), so Scalar's spec fetch was blocked and the panel stayed blank. The link is now a native full-page navigation (`NavItem.external`), so Scalar loads on the real origin.

### Changed
- **Rebranded to "API DOCS":** sidebar header + app name, custom `</>` logo and favicon; removed the Laravel `favicon.ico`/`apple-touch-icon.png`.
- Removed the "Repository" and "Documentation" links from the sidebar footer.
- The root URL `/` now redirects to the dashboard (authenticated) or login (guest); the marketing welcome page is no longer reachable.

### Tests
- Vitest coverage that external nav items render a native anchor; Playwright coverage that the API Reference link performs a real full-page navigation to the Scalar page; `HomeRedirectTest` for the root redirect both ways.

## v1.0.0 — 2026-06-15

### Added
- Bootstrap with Laravel 13 + Inertia 3 + React 19 + TypeScript + Tailwind 4 + shadcn/ui.
- Authentication and RBAC foundation with Fortify + Spatie roles (`admin`, `user`) and seeded admin bootstrap.
- Multi-tenant OpenAPI docs gateway:
  - `/api-docs/openapi.json` proxy with server-side filtering.
  - Union-style grants: users see operations by OR-logic over tags and endpoint IDs.
  - `admin` can see full spec, regular users only granted operations.
- Scalar docs page at `/scalar` behind role-based authorization.
- Metadata endpoints for tag/endpoint administration:
  - `/api-docs/meta/tags`
  - `/api-docs/meta/endpoints`
  - `/api-docs/flush-cache` (admin only).

### Added Admin features
- User management UI/API:
  - create, edit, delete users.
  - assign roles.
  - assign per-user grants with anti-tampering validation (values must exist in the upstream spec).
  - protect against removing the last admin / self-delete.
- Server catalog CRUD (admin):
  - add/edit/delete playground server entries.
  - activate/deactivate servers injected into user-specific specs.
- Auth logs panel (admin):
  - immutable log rows for `login`, `logout`, `failed` events.
  - filters and date-range exploration.
- Cache control actions and maintenance command:
  - UI flush cache action.
  - `openapi:refresh` and cache eviction in app controller paths.

### Security and hardening
- Upstream spec fetch hardened:
  - SSRF protections (`OPENAPI_ALLOWED_SCHEMES`, `OPENAPI_ALLOWED_HOSTS`).
  - strict fetch result validation before cache write.
  - stale-on-error fallback handling.
- Spec filtering hardening:
  - transitive component pruning for 3.1 structures (`paths`, `webhooks`, callbacks, schemas, refs).
  - `Cache-Control: private, no-store` on filtered proxy output.
  - no client-side authorization decisions (all enforced server-side).
- Global auth hardening:
  - CSRF baseline coverage on protected mutations.
  - login rate limiting.
  - mass-assignment protection on mutable models.
  - auth audit trail kept immutable.
- Playwright hardening polish:
  - role-matrix checks.
  - keyboard/a11y smoke (`Esc` dialogs, focus behavior).
  - responsive/empty-state smoke tests.

### Quality and release readiness
- Full automated quality stack in place:
  - `vendor/bin/pint --test`
  - `vendor/bin/phpstan analyse --level=max`
  - `php artisan test`
  - `npm run test`
  - `npm run build`
  - `npx playwright test` (including full auth-flow scenarios)
- GitHub release created: **v1.0.0**
- Roadmap completed to 100% with T1–T9 delivered.

### Notes
- `npm audit` currently reports 7 issues in the transitive `esbuild`/`shell-quote` graph of the installed lockfile.
  The project remains functional; remediation is planned for a future dependency refresh cycle.
