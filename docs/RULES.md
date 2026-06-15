# RULES.md — Coding Standards & Definition of Done

Binding rules for all code in this repo. The workflow/branch/PR rules live in `AGENTS.md`; this file is about *how code is written and what "done" means*. When a rule here conflicts with a direct user instruction, the user wins.

## Coding Standards (PHP)

- `declare(strict_types=1)` in every PHP file.
- Fully typed signatures: every parameter and return type declared (including `void`, `self`, union/nullable types). No untyped params.
- Systematic early return. Aim for a single level of indentation per method; guard clauses over nested `if`.
- `try/catch` always logs with structured context (`Log::error('msg', [...])`). Never swallow exceptions silently.
- **No `array_merge`/`+=` array building inside loops.** Use collections, spread, or chunked bulk inserts.
- Actions-oriented: one invokable/handle action = one responsibility (`app/Actions/`).
- Validation + authorization live in FormRequests, not controllers.
- Serialization for API/JSON responses goes through API Resources.
- Closed value sets are typed Enums (`app/Enums/`), backed by string.
- Mass-assignment protection: explicit `$fillable` (preferred) on every model.
- Config over magic literals: role names, cache keys, TTLs, host whitelists come from `config/*.php` (e.g. `config('openapi.admin_role')`), never hardcoded.
- Comments only where non-obvious — the OpenAPI filter and component pruning get verbose explanatory comments; trivial code gets none.

## Coding Standards (TS/React)

- TypeScript strict; no `any` without a written reason.
- UI language is **English** (labels, menus, toasts, validation messages).
- Reusable presentational/logic components in `resources/js/components/`; Inertia pages in `resources/js/pages/`.
- Authorization is never decided client-side. The frontend only renders what the server already authorized (e.g. role-conditional nav is cosmetic; the route/proxy enforces).

## Security Rules

- The OpenAPI spec is filtered **server-side per user**; the browser never receives operations the user isn't granted.
- Granted tags/endpoints submitted from forms are re-validated server-side against the real spec before persistence — only values present in the current spec are stored (anti-tampering).
- Never return secrets (upstream auth token, password hashes) in JSON, UI, or logs.
- Proxy responses carrying per-user content set `Cache-Control: private, no-store`.
- Upstream spec URL is validated (scheme + host whitelist) before any fetch (SSRF guard).
- Audit log rows are immutable: no application update/delete routes.

## Testing Rules

Guardrails are part of the deliverable, not an afterthought. Every subtask defines its tests up front.

- **Pest (PHP)** — feature tests for routes/RBAC/proxy/validation/audit; unit/golden tests for `OpenApiSpecService` against `tests/Fixtures/*.json`.
- **Vitest (TS)** — component logic (MultiSelect, DataTable, role-conditional nav, form behavior).
- **Playwright (E2E)** — **mandatory for every UI/UX interaction introduced** (forms, dialogs, toasts, filters, pagination, role-based visibility, keyboard/a11y). A purely backend subtask skips Playwright; a subtask that touches any rendered interaction does not.
- Tests run on SQLite `:memory:` + `CACHE_STORE=array`. Code must stay db/cache driver-agnostic (facades only; no Redis-only calls).
- On auth/security-focused changes, lock in a regression baseline for:
  - mutation CSRF failures (419),
  - auth-related throttling/rate-limit behavior,
  - auth event persistence (`login`, `logout`, `failed`) at route/action level.

## Definition of Done (per subtask)

A subtask is **done** only when all of the following hold:

1. **Objective** is stated precisely (what + why).
2. **Implementation** matches the standards above.
3. **Guardrails** exist and pass: Pest + Vitest + (Playwright if any UI interaction).
4. **Local gates green:** `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --level=max`, `php artisan test`, `npm run test`, `npm run build`, and `npx playwright test` (when UI).
5. **Local Copilot review** (pre-push pre-filter) returns zero actionable comments — or, if the local `copilot` CLI is unavailable (quota/outage), the blocker is recorded in `docs/PROGRESS.md` (loop + fallback in `AGENTS.md` step 3).
6. **PR opened** into the macro branch; **Copilot requested as reviewer** and confirmed started. The repo's Codex connector auto-reviews on PR open — no manual request needed; both are binding **when available**.
7. **CI green** and **all available bot review threads resolved** — both Copilot **and** Codex when both are up. It must not be possible to pass DoD while silently skipping an *available* reviewer; a bot that is unavailable **org-wide** (documented outage in `docs/PROGRESS.md`, e.g. Copilot spend limit) does not block — review is carried by the remaining reviewer(s) + CI + local gates (`AGENTS.md` step 6 reviewer-outage exception).
8. **Docs updated:** `docs/PROGRESS.md` after the step; `docs/LESSON.md` for anything learned.
9. **Merged.**

## DoD Template (copy into each subtask's PR description)

```
### Objective
<what and why>

### Implementation
<key files + approach>

### Guardrails
- Pest: <tests>
- Vitest: <tests>           (or N/A)
- Playwright: <scenarios>   (or N/A — backend only)

### Gates
- [ ] vendor/bin/pint --test
- [ ] vendor/bin/phpstan analyse --level=max
- [ ] php artisan test
- [ ] npm run test
- [ ] npm run build
- [ ] npx playwright test    (or N/A)
- [ ] local Copilot review: zero comments
- [ ] CI green
- [ ] Copilot + Codex review threads resolved
```
