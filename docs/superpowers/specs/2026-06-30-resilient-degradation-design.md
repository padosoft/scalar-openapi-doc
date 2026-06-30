# Resilient Degradation — Design

**Date:** 2026-06-30
**Macro task:** `task/resilient-degradation`
**Status:** Approved

## Problem

When infrastructure or the upstream OpenAPI source fails, the portal returns raw
HTTP 500s instead of a usable message. Two reported symptoms, both reproduced
from production logs (2026-06-30):

1. **API docs page** (`/scalar`): the Scalar UI fetches `/api-docs/openapi.json`
   (`OpenApiDocsController::show`). `fetchRaw()` reads the DB-backed cache, then
   on miss refreshes from upstream and reads the stale copy — when the DB/cache
   is unreachable every branch throws `QueryException`, the exception is
   uncaught, the endpoint 500s, and Scalar renders nothing.
2. **User create/edit** (`/admin/users/{user}/edit`): the page *already* degrades
   the OpenAPI catalog gracefully (`UserController::catalogOrEmpty` try/catch),
   but the `Log::warning` **inside that catch** threw
   `RuntimeException: Could not write to socket` because the production log
   channel (Monolog `SocketHandler`) was itself down. The safety net crashed
   inside its own catch block → 500.

### Root cause (corrected)

The logs do **not** show the external Scalar API failing. The message
`"OpenAPI upstream spec fetch failed"` was logged with
`exception: Illuminate\Database\QueryException` — i.e. the HTTP fetch to the
upstream very likely succeeded, but the subsequent `Cache::put()` /
`Cache::forever()` (DB-backed cache) threw because the Laravel Cloud MySQL
connection dropped (`SQLSTATE[HY000] 2013 Lost connection ... 'handshake'`). The
log socket was down too (`Could not write to socket`).

So the portal is not resilient to **its own** infrastructure (DB + logging)
being unavailable — independent of the external API.

## Principles

- No single failure (external API, DB/cache blip, dead log channel) becomes a
  raw 500.
- Any detail surfaced in the UI is **redacted** — never the DB host, upstream
  URL, or auth token (the logs prove these are present in raw messages). Reuse
  the existing `redactUrl()` / `redactMessage()` helpers in `OpenApiSpecService`.
- A hard, total DB outage is treated as a drastic, rare event: one clean
  app-wide 503 page (shown at login and everywhere) — not a per-page recovery UI.

## Components

### 1. Logging can never crash a request — `config/logging.php`

Wrap the production log channel in Monolog's `WhatFailureGroupHandler` so a
handler write failure (`Could not write to socket`) is swallowed instead of
thrown. Implemented via a `tap` class (`App\Logging\NeverFailTap`) applied to the
production stack/channel. Test channels (`array`, `single`) are untouched, so the
test suite stays driver-agnostic.

### 2. Categorized, non-throwing spec load — `OpenApiSpecService`

- New value objects:
  - `App\Support\OpenApi\SpecFailureCategory` (enum): `Database`, `ExternalApi`,
    `InvalidSpec`, `Unknown`.
  - `App\Support\OpenApi\SpecFailure` (readonly): `category`, `httpStatus: ?int`,
    `exceptionClass: string`, `message: string` (already redacted), plus a
    `label(): string` human category label.
  - `App\Support\OpenApi\SpecFetchResult` (readonly): `spec: ?array`,
    `failure: ?SpecFailure`, `ok(): bool`.
- New method `tryFetchRaw(): SpecFetchResult` wrapping cache-read → upstream
  refresh → stale-read. It never throws; it categorizes the throwable:
  - `Illuminate\Database\QueryException` / `PDOException` → `Database`.
  - `Illuminate\Http\Client\RequestException` → `ExternalApi` (with
    `httpStatus`); `ConnectionException` → `ExternalApi` (no status).
  - `App\Exceptions\InvalidOpenApiSpecException` → `InvalidSpec`.
  - anything else → `Unknown`.
- Existing throwing `fetchRaw()` is retained for internal callers; it may be
  reimplemented on top of the shared internal logic. Stale-on-error behaviour is
  preserved (a present stale copy still yields a successful result).

### 3. Docs endpoint degrades to a valid "unavailable" document — `OpenApiDocsController::show`

On a failed `SpecFetchResult`, return **HTTP 200** with a minimal valid OpenAPI
3.1 document:

```jsonc
{
  "openapi": "3.1.0",
  "info": {
    "title": "⚠️ API documentation temporarily unavailable",
    "version": "0",
    "description": "<friendly sentence> + redacted detail: category label, HTTP status (if any), exception class, redacted message"
  },
  "paths": {}
}
```

200 (not 503) is required so Scalar renders the document instead of its own
generic fetch error. Header stays `Cache-Control: private,no-store`. No frontend
changes needed; this also literally "shows what the fetch returned".

### 4. User create/edit → degraded mode + warning banner

- `catalogOrEmpty` uses `tryFetchRaw` (no throw). The controller passes an
  `openapiStatus` prop to the Inertia `admin/users/form` page:
  `{ ok: boolean, failure?: { category, label, httpStatus, exceptionClass, message } }`.
  On success `{ ok: true }`.
- The React form renders a non-blocking warning banner (with the redacted
  detail) above the tag/endpoint grant pickers when `openapiStatus.ok === false`.
  Name/email/role/password remain fully editable; existing grants stay visible
  and removable. Grants are still validated server-side on save (unchanged).
- `metaTags` / `metaEndpoints` admin JSON endpoints degrade to an empty array on
  failure (secondary; they back optional admin tooling).

### 5. DB fully down → one clean app-wide message — `bootstrap/app.php` + `resources/views/errors/503.blade.php`

Map a lost-connection `QueryException` / `PDOException` on web requests to a
friendly **503** rendered via `errors/503.blade.php`: "Service temporarily
unavailable — please try again shortly." This is what the login screen shows when
the DB is unreachable; inside the app it is the same clean page (no stack trace,
no secrets). Only connection-level failures map to 503 — ordinary query errors
keep their normal handling so real bugs are not masked.

## Error handling & security

- Every UI-facing string passes through `redactMessage()` / `redactUrl()`.
- The docs document returns 200 with the failure detail in `info.description`.
- The 503 page is static text — no exception detail.

## Testing

- **Pest**
  - `tryFetchRaw` categorization: `QueryException` → `Database`; `Http::fake`
    500/timeout → `ExternalApi` with/without status; invalid payload →
    `InvalidSpec`; present stale copy → `ok()` true.
  - Docs endpoint: success → 200 + real (filtered) spec; failure → 200 + the
    "unavailable" document shape; never 500.
  - User edit/create: spec unavailable → page renders (no 500), `openapiStatus.ok`
    is `false`, catalog falls back to the user's existing grants.
  - 503 mapping: a simulated lost-connection `QueryException` renders the 503
    view (not a 500 / stack trace).
  - Logging: a deliberately throwing handler does not bubble out of a `Log` call
    (request still succeeds).
- **Vitest**: form warning banner renders when `openapiStatus.ok === false`,
  hidden when `true`.
- **Playwright**: edit-user form with the spec unavailable shows the banner and
  the base fields stay usable/submittable.

## Workflow

Macro branch `task/resilient-degradation`. Two subtasks, each a child branch with
a PR into the macro branch, following the AGENTS.md Branch & PR loop (local gates
→ local Copilot report-only → push → PR → Copilot + Codex reviews + CI green →
merge):

- **2-1 backend**: components 1, 2, 3, 5 + `UserController` `openapiStatus` prop
  (Pest only).
- **2-2 frontend**: form warning banner (Vitest + Playwright).

Then macro PR → `main`, merge, and cut a release (tag `vX.Y.Z` + `gh release
create`, changelog from the merged macro PR). Update `docs/PROGRESS.md` and
`docs/LESSON.md` throughout.
