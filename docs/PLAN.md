# Implementation Plan — API Docs Portal (scalar-openapi-doc)

## Context

Repo `padosoft/scalar-openapi-doc` (empty at kickoff: only README+LICENSE, branch `main`). Goal: a **Laravel 13 + PHP 8.5 (Herd)** app that renders OpenAPI documentation via **Scalar**, adding what Scalar doesn't offer: **login (Fortify)**, **admin/user roles (spatie/laravel-permission)**, and **server-side per-user spec filtering** (user sees only granted tags/endpoints, UNION semantics; admin sees all). External spec fetched with Redis cache + stale-on-error fallback. Admin: user CRUD with anti-tampering grants, playground servers injected into `servers`, auth audit log, cache flush.

Sources: the technical spec `SPEC_Portale_API_Docs.md` + ready PHP skeletons (OpenApiSpecService, Pest golden tests, openapi.json fixture, openapi.php config, CacheController, seeders), provided by the owner in a local folder outside the repo. Workflow conventions reused from the internal reference repo `product_image_discovery_admin` (AGENTS.md, RULES.md, PROGRESS.md, LESSON.md, PR/Copilot loop). The local paths of these sources are machine-specific and are not versioned.

**User decisions:** local dev with **MySQL via Herd** + **Redis via Herd** (tests: SQLite `:memory:` + `array` cache); **UI in English**; starter kit stack `laravel/react-starter-kit` (Inertia 3 + React 19 + TS + Tailwind 4 + shadcn/ui), Pest, Pint, Larastan max, Vitest, Playwright.

**Verified environment:** Herd PHP 8.5.7, Node 25.2.1, composer (Herd), gh CLI, copilot CLI — all available.

---

## Deep Analysis — bugs and issues found in the skeletons

To be fixed as explicit subtasks (references to `OpenApiSpecService.php` skeleton):

| # | Issue | Where | Planned fix |
|---|---|---|---|
| B1 | **Hidden `Auth::` dependency** inside the service (`Auth::user()` in `filterForUser` line 199, `Auth::id()` in `flushCache` line 65). Violates DI, complicates tests (golden tests declare "no authenticated user" but the service calls Auth) | Service | Refactor: `filterForUser(..., bool $isAdmin = false)` and `flushCache(bool $includeStale, ?int $actorId)`; controller decides (T4.2) |
| B2 | **Cache poisoning**: any valid upstream JSON is cached for 1h without validating it is an OpenAPI spec (lines 92-95) | Service | Validate minimum shape (`openapi`+`info`+`paths`) before cache put; `InvalidOpenApiSpecException` + stale fallback (T4.3) |
| B3 | **SSRF**: no validation of `upstream_url` (scheme/host) before fetch | Service | Whitelist `allowed_schemes`/`allowed_hosts` in config, `parse_url` check pre-fetch (T4.4) |
| B4 | **Incomplete component pruning**: drops `securitySchemes` (referenced by name via `security`, not via `$ref`), `$ref` at path-item level, `webhooks`/`pathItems` OpenAPI 3.1 | Service | Extended reachability seed + dedicated 3.1 fixture (T4.5) |
| B5 | Hardcoded `'admin'` role | Service/gate | `config('openapi.admin_role')` (T4.6) |
| B6 | Stale cache `Cache::forever` with no TTL (by design but undocumented) | Service | Docblock + note in RULES.md; `flushCache(true)` removes it (T4.6) |
| B7 | `injectServers` accepts any array without validating shape `{url, description?}` | Service | Entry validation, skip+warning on malformed entries (T4.6) |
| B8 | **Test gap**: no tests for upstream errors (down/malformed), `prune_components=false`, empty grants, endpoint key case normalisation, cache-hit without HTTP, securitySchemes | Tests | Extended suite (T4.7) |
| B9 | Fixture path hardcoded as `tests/Fixtures/openapi.json` in test but file provided in plan folder root | Tests | Copy to `tests/Fixtures/` (T4.1) |

**Improvements/missing features included in the plan:** "last admin" guard (no self-delete/demote), `Cache-Control: private, no-store` on the proxy (per-user content), login rate limiting, `Failed` event in audit (spec marks it optional → included), `auth-logs:prune` command, URL validation in playground servers, empty/loading/error UI states, dark/light mode.

**Out of scope for v1 (documented in README as roadmap):** multi-spec support, cache invalidation via upstream webhook/ETag, 2FA, audit CSV export.

---

## Workflow Contract (applies to EVERY macro task — codified in AGENTS.md)

- Branch `task/<name>` from `main` for each macro task. Each subtask = child branch + **PR into the macro branch**. Macro task complete = PR macro branch → `main`.
- **Definition of Done for each subtask:**
  1. Precise objective + implementation details + guardrails: **Pest** (PHP), **Vitest** (JS), **Playwright** for every UI/UX interaction (skip if backend-only).
  2. Local loop: all tests green + `pint --test` + Larastan max clean → **local Copilot review (report-only)**: `copilot --autopilot --yolo -p "/review ... DO NOT modify or commit any files - report findings only"` passing the diff of the branch **vs the PR base** (macro branch for subtasks; `origin/main` only for the macro PR), saved to a temp file if large → repeat until **zero comments**. Note: `--yolo` can modify files; the prompt must enforce report-only and you apply the fixes yourself.
  3. Push → open PR (gh CLI) → **add Copilot as reviewer** (`gh pr edit <PR> --add-reviewer copilot`; in practice fails on this repo → use the GraphQL fallback `requestReviewsByLogin` with `copilot-pull-request-reviewer[bot]`, see AGENTS.md) → verify the review has started.
  4. Wait for **CI fully green** + comments from **all configured bot reviewers** (this repo has two: **Copilot** and the **Codex** connector — both equally binding) → fix broken tests and every comment → re-push and new review in a loop (nudge Codex with a `@codex review` comment).
  5. Only when everything is ok: **merge** and proceed to the next task.
- `docs/PROGRESS.md` updated after every step (so an interrupted session resumes exactly where it left off); `docs/LESSON.md` updated on every discovery/Copilot fix. Both passed in every subagent context and re-read at session start.
- Machine rules: **always Herd PHP/composer, never XAMPP**; `.gitattributes` eol=lf.
- This plan is copied into the repo as `docs/PLAN.md` (user request: "save the plan in a markdown file").

---

## Macro Task 1 — `task/project-conventions` (FIRST, before any line of code)

Goal: codify rules/skill/agents in Claude format so the procedure survives session end. Adapted from the reference repo (Operating Rules, Branch & PR Loop, Copilot GraphQL fallback, Herd-not-XAMPP rule, LESSON/PROGRESS templates).

- **1.1 AGENTS.md + CLAUDE.md** — AGENTS.md: Operating Rules (strict_types, early return, no array_merge in loop, Actions-oriented, FormRequest/Resource/Enum), Branch & PR Loop with exact commands, test gates, local copilot review command + GraphQL fallback, Herd rule. CLAUDE.md: pointer to AGENTS.md + quick facts (stack, test commands, key paths). DoD: files committed and merged (docs only, no tests).
- **1.2 docs/RULES.md + docs/PROGRESS.md + docs/LESSON.md + docs/PLAN.md** — RULES.md: standards §14 spec + DoD template + "Playwright mandatory for UI" rule. PROGRESS.md: macro task table pre-populated from this plan. LESSON.md: dated template. PLAN.md: this plan.
- **1.3 Resume skill `.claude/skills/scalar-openapi-doc-plan/SKILL.md`** — frontmatter with description like "Continue or resume the scalar-openapi-doc implementation… enforce branch/PR/Copilot/test rules"; Start Here → AGENTS.md, RULES.md, PROGRESS.md, LESSON.md. Plus `.claude/settings.json` with project command allowlist.

Dependencies: none. No Playwright (docs only).

## Macro Task 2 — `task/bootstrap` (scaffold + quality tooling + CI)

Goal: working Laravel 13 + react-starter-kit app on Herd, with Pint/Larastan/Pest/Vitest/Playwright and green CI.

- **2.1 Starter kit in non-empty repo** — `laravel new` rejects non-empty dirs: scaffold in a temp sibling dir (`laravel new` or `composer create-project laravel/react-starter-kit`), copy into the repo preserving `.git` and LICENSE (starter README set aside, rewritten in T9). `.gitattributes` eol=lf. Verify auth wiring of the kit (Fortify expected in L13) and annotate in LESSON.md. DoD: `php artisan about` ok on Herd, `npm run build` ok, login page renders, kit Pest tests green. `.env` dev: MySQL Herd (`scalar_openapi_doc` db) + `CACHE_STORE=redis` (Redis Herd).
- **2.2 Quality tooling** — larastan (level max) + `phpstan.neon.dist`; `pint.json`; `phpunit.xml`: sqlite `:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`. Vitest (jsdom, Windows-safe `pool`) + sample component test. Playwright config with `webServer: php artisan serve` + `.env.testing.e2e` (sqlite file, dedicated seed). DoD: 5 commands green locally (pint, larastan, pest, vitest+build, playwright hello-world).
- **2.3 CI `.github/workflows/ci.yml`** — ubuntu-latest, `shivammathur/setup-php` PHP 8.5 (fallback 8.4 documented, Laravel 13 requires `^8.3`). Tests on sqlite+array cache (no MySQL/Redis services in CI: code is driver-agnostic via facades). Parallel jobs: pint --test, larastan, pest, tsc+vitest+vite build, playwright (trace artifacts on failure). DoD: CI green on macro PR.

Dependencies: T1. No real Playwright (stub e2e ok).

## Macro Task 3 — `task/rbac-data-model` (DB, roles, models, seeders) — backend only

- **3.1 spatie/laravel-permission + roles** — install, `HasRoles` on User, `config/openapi.php` with `admin_role` key (fix B5), integrate `RoleSeeder`/`AdminUserSeeder` (env `ADMIN_EMAIL`/`ADMIN_PASSWORD`)/`DatabaseSeeder` from skeletons. Pest guardrails: seeder creates roles+admin; `role:admin` middleware registered and 403 for plain user.
- **3.2 Custom migrations + models + enums** — `user_allowed_tags` (UNIQUE user_id+tag, CASCADE), `user_allowed_endpoints` (UNIQUE user_id+method+path, CASCADE), `scalar_servers`, `auth_logs` (user_id nullable SET NULL, email snapshot, event, ip 45, ua 512, created_at only, indexes). Enums `AuthEvent`/`HttpVerb`. Models + relationships + casts + `$fillable`. Pest guardrails: unique constraints, cascade, SET NULL, no updated_at on auth_logs.
- **3.3 `ReplaceUserAccessAction`** — transaction: delete user rows → chunked bulk insert (atomic delete-then-bulk-insert, no array_merge in loop). Pest guardrails: correct replace, empty arrays clear, atomic rollback on failure.

Dependencies: T2. Can run in parallel with T4.

## Macro Task 4 — `task/openapi-service` (core + golden tests + hardening) — backend only

Security core: small slices, one review per fix.

- **4.1 Baseline integration** — copy skeletons: `app/Services/OpenApiSpecService.php`, `config/openapi.php`, golden tests in `tests/Feature/`, fixture in `tests/Fixtures/openapi.json` (fix B9). DoD: 9 golden tests green as-is, Larastan max clean.
- **4.2 Remove Auth dependency (B1)** — `filterForUser(array $spec, Collection $tags, Collection $endpoints, bool $isAdmin = false)`; `flushCache(bool $includeStale = false, ?int $actorId = null)`. Guardrails: parameterised admin-bypass tests (`isAdmin=true + admin_sees_all=true` → full spec; `admin_sees_all=false` → still filters).
- **4.3 Upstream response validation (B2)** — minimum shape before cache put; `InvalidOpenApiSpecException`; stale fallback. Pest guardrails with `Http::fake`: HTML/`{"foo":1}`/empty body → nothing cached, stale served if it exists, throw if not.
- **4.4 SSRF guard (B3)** — config `openapi.allowed_schemes` (default `['https']`) + `openapi.allowed_hosts` (env, csv); check pre-fetch. Guardrails: `file://`, metadata IP `169.254.169.254`, host outside whitelist → rejected, `Http::assertNothingSent()`.
- **4.5 Complete pruning (B4)** — reachability seed with: securitySchemes by name from root+operation `security`; `$ref` at path-item level; `webhooks`/`components.pathItems` 3.1. Additional `openapi31.json` fixture. Golden guardrails: used scheme preserved, unused pruned, schema referenced from webhook survives.
- **4.6 Remaining hardening (B5, B6, B7)** — `admin_role` configurable at role check points; stale semantics docblock; `injectServers` validates `{url: valid URL, description?: string}` with skip+warning. Guardrails: malformed injectServers tests; `flushCache(true)` removes stale.
- **4.7 Missing coverage (B8)** — upstream down→stale; down+no stale→throw; `prune_components=false`; empty grants; `get`→`GET` endpoint key normalisation (canonical `UPPER(method) path` normalised in service); cache-hit→`Http::assertNothingSent`.

Dependencies: T2 (T3 not required: after 4.2 the service is user-agnostic).

## Macro Task 5 — `task/scalar-proxy` (Scalar + proxy + cache flush + dashboard)

- **5.1 scalar/laravel + auth wrapping** *(at-risk subtask)* — install, publish config; `'url' => '/api-docs/openapi.json'`, purple/modern theme. Verify whether the package route accepts middleware via config; if not: disable the package route and register `GET /scalar` directly in the `web,auth` group rendering the published Blade (`scalar-views`). Gate `viewScalar` (hasAnyRole with configurable role names). Pest guardrails: guest→redirect login; no role→403; user/admin→200.
- **5.2 Spec proxy + meta endpoints** — `ApiDocsController`: `spec()` = fetchRaw → filterForUser (grants from DB, isAdmin from role config) → injectServers (active servers by sort_order) → JSON with `Cache-Control: private, no-store`. `metaTags()`/`metaEndpoints()` under `auth, role:admin`. Routes §9. Pest guardrails: two users with different grants receive different specs; ungranted operation absent; admin full spec; guest 401; meta 403 for user; servers injected only when active.
- **5.3 Cache flush: action + command** — `CacheController` adapted (explicit actorId) → `DELETE /admin/openapi-cache`; `openapi:refresh --include-stale` command. Pest guardrails: 403 for user, flush+flash for admin; command empties the key.
- **5.4 Dashboard + nav (first real UI)** — dashboard/sidebar starter kit adapted: "API Documentation" link (full page → `/scalar`), admin-only entries (Users, Servers, Auth Logs, Flush Cache with ConfirmDialog+toast). Vitest: conditional nav rendering by role. **Playwright:** login user → sidebar shows docs only, `/scalar` loads and Scalar renders; login admin → admin entries visible; Flush Cache → dialog → success toast; direct nav `/admin/users` from user → 403.

Dependencies: T3+T4.

## Macro Task 6 — `task/admin-users` (user CRUD + anti-tampering grants)

- **6.1 FormRequest + controller (backend)** — `StoreUserRequest`/`UpdateUserRequest` (§6.5): name/email/password (confirmed on create, nullable on update), `roles` in configured names, `allowed_tags` `Rule::in(extractTags(fetchRaw()))`, `allowed_endpoints` `{method,path}` validated vs `extractEndpoints()` (anti-tampering: only spec-existing values accepted). `Admin\UserController` Inertia CRUD; store/update: `syncRoles` + `ReplaceUserAccessAction` in transaction. "Last admin" guard: forbidden to delete yourself / remove the last admin. Pest guardrails: tampered tags/endpoints → 422; correct replace; optional password on update; last admin guard; index with pagination+search.
- **6.2 Shared UI components** — `data-table.tsx` (TanStack+shadcn, sort, server-side pagination), `multi-select.tsx` (multi combobox with search), `confirm-dialog.tsx`, `role-badge.tsx`. **Vitest:** MultiSelect select/deselect/search/pre-selection; DataTable rows+pagination callback.
- **6.3 Users pages (Inertia)** — `admin/users/index.tsx` (email/name/roles grid, text search, role filter, server-side pagination, row actions) and `form.tsx` create/edit with MultiSelect fed from `/api-docs/meta/*`, **pre-selection on edit**. **Playwright:** create user (form, role, 2 tags + 1 endpoint via search multiselect, submit, toast, appears in grid); edit (pre-selections visible, remove one, save, reopen → persisted); delete with confirm; search filters; role filter; pagination.

Dependencies: T5.

## Macro Task 7 — `task/admin-servers-audit` (playground servers + audit)

- **7.1 Servers CRUD** — `ServerRequest` (url valid URL required, description nullable, sort_order int, is_active bool), `Admin\ServerController`, `admin/servers/index.tsx` page. Pest guardrails: CRUD + integration "only active servers injected by proxy". **Playwright:** add server → appears; toggle inactive → disappears from proxy spec; edit/delete with confirm.
- **7.2 Audit listener** *(backend-only)* — `LogAuthEventAction` + listeners for `Illuminate\Auth\Events\{Login,Logout,Failed}` (resilient to Fortify wiring); `auth-logs:prune --days=N` command + scheduler. Pest guardrails: successful login → `login` row (user_id, email, ip, UA); wrong password → `failed` row (email snapshot, user_id null); logout → `logout` row; rows survive user deletion. Immutable audit (no update/delete routes).
- **7.3 Auth Logs page** — `Admin\AuthLogController@index` (filters by user/email, event, date range; server-side pagination, most recent first) + `admin/auth-logs/index.tsx` read-only with event badge. **Playwright:** real login/logout then verify rows from admin; filter event=failed after a failed attempt; empty-state on date filter.

Dependencies: T6 (shared components). 7.1 ∥ 7.2.

## Macro Task 8 — `task/hardening-polish` (security pass + full E2E + UX)

- **8.1 Security pass** — login rate limiting (429 after N attempts), CSRF audit on all mutations, mass-assignment audit, `Cache-Control: private, no-store` header verified on proxy, Larastan max on full app, `composer audit` + `npm audit`. Pest guardrails: 429 on brute force; proxy header.
- **8.2 Authorization matrix E2E** — Playwright spec of the §4.2 matrix (user vs admin on all routes) + key scenario: **two users seeded with disjoint grants → Scalar sidebar shows each user only their own tags; admin sees all**.
- **8.3 UX/a11y polish** — dark/light, loading/empty/error states everywhere, focus management in dialogs, responsive. Playwright smoke keyboard-nav (tab in user form, Esc closes dialog).

Dependencies: T5–T7.

## Macro Task 9 — `task/release` (WOW README + knowledge consolidation + tag)

- **9.1 WOW README** (modelled on lopadova/AskMyDocs) — badges (CI, license, PHP/Laravel), TOC, "what's innovative" (per-user server-side filtering, UNION grants, transitive component pruning, stale-on-error, anti-tampering), junior-proof Quick Start (Herd, `.env` table §18.1, seed admin, step-by-step commands verified on clean clone), testing matrix, security notes. **No banner/screenshot:** `resources/banner.png` not provided (re-check at end of work; if it exists, include it).
- **9.2 LESSON.md consolidation → rules/skills/AGENTS.md** *(final required task)* — re-read LESSON.md and all learnings; promote every recurring lesson into a rule/skill/AGENTS.md+CLAUDE.md update; archive promoted lessons.
- **9.3 Tag + GitHub release** — `git tag v1.0.0` + `gh release create v1.0.0` with changelog assembled from merged macro PRs.

Dependencies: all previous.

---

## Execution order and parallelism

`1 → 2 → {3 ∥ 4} → 5 → 6 → 7 → 8 → 9`. Within T7: 7.1 ∥ 7.2. Parallel subagents always receive `docs/LESSON.md` content + AGENTS.md rules in their prompt.

## CI (summary)

Single `ci.yml`: ubuntu-latest, setup-php 8.5 (fallback 8.4), parallel jobs pint/larastan/pest(sqlite+array)/tsc+vitest+build/playwright(sqlite file, seed, `php artisan serve` as webServer, trace artifacts). Composer+npm cache. Required checks on `main` and macro branches.

## Risks

| Risk | Mitigation |
|---|---|
| Starter kit rejects non-empty dir | Scaffold in temp sibling, copy preserving `.git`/LICENSE (T2.1) |
| Scalar route not wrappable with middleware via config | Check vendor; fallback: own `/scalar` route with published Blade (T5.1) |
| Fortify wiring different in L13 kit | Verify in T2.1; listeners on `Illuminate\Auth\Events\*` (resilient) (T7.2) |
| Windows quirks (Vitest pool, Playwright+Herd, CRLF) | Windows-safe pool, webServer `php artisan serve` Herd, `.gitattributes` eol=lf, never-XAMPP rule in AGENTS.md |
| PHP 8.5 unstable on CI | Pin setup-php, fallback 8.4 documented (Laravel 13 = `^8.3`) |
| Copilot CLI/review temporarily unavailable | Record blocker in PROGRESS.md, do not skip the gate silently |

## Verification (for the entire project)

- Every subtask: `vendor/bin/pint --test` + `vendor/bin/phpstan analyse` + `php artisan test` (Pest) + `npm run test` (Vitest) + `npm run build` + `npx playwright test` (if UI) all green locally (Herd PHP 8.5) and on CI.
- Final E2E: full authorization matrix + "two users, disjoint grants, different Scalar sidebars" scenario (T8.2).
- README Quick Start verified on a clean clone before tagging (T9.1).
