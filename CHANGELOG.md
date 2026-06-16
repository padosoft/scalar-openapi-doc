# Changelog

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
