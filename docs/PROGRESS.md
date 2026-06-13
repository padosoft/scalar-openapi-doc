# PROGRESS.md — Live Progress Tracker

This is the resume point. If a session dies, the next agent reads this file (plus `AGENTS.md`, `docs/RULES.md`, `docs/LESSON.md`) and continues exactly from here. Update it after every meaningful step. Newest entries at the bottom of each task.

## Macro Task Status

| # | Macro task | Branch | Status | Macro PR |
|---|---|---|---|---|
| T1 | Project conventions (docs, rules, resume skill) | `task/project-conventions` | 🟢 merged (PR #3 → `main`, `691db58`) | #3 |
| T2 | Bootstrap (scaffold + tooling + CI) | `task/bootstrap` | 🟡 macro PR pending → `main` (2.1+2.2+2.3 merged) | — |
| T3 | RBAC & data model | `task/rbac-data-model` | ⚪ pending | — |
| T4 | OpenApiSpecService + hardening | `task/openapi-service` | ⚪ pending | — |
| T5 | Scalar proxy + dashboard | `task/scalar-proxy` | ⚪ pending | — |
| T6 | Admin users + grants | `task/admin-users` | ⚪ pending | — |
| T7 | Servers + audit | `task/admin-servers-audit` | ⚪ pending | — |
| T8 | Hardening & polish | `task/hardening-polish` | ⚪ pending | — |
| T9 | Release | `task/release` | ⚪ pending | — |

Legend: ⚪ pending · 🟡 in progress · 🟢 merged.

---

## T1 — task/project-conventions

### Subtask 1.1 + 1.2 — operating docs (AGENTS.md, CLAUDE.md, `docs/*`) + .gitattributes
Branch: `task/project-conventions-1-1-agents` → PR #1 into `task/project-conventions`.

- Created `AGENTS.md` (operating contract: branch/PR loop, Copilot review gates, testing rules, Herd machine rules, subagent rules, release rules) and `CLAUDE.md` (quick reference).
- Local Copilot review pass 1 → 6 findings fixed; pass 2 → NO FINDINGS.
- Opened PR #1. `gh pr edit --add-reviewer copilot` failed → used GraphQL `requestReviewsByLogin` fallback (works). Confirmed Copilot in requested_reviewers.
- **Both Copilot and the repo's Codex connector auto-reviewed.** Findings converged: docs referenced `docs/*` files and `.gitattributes` that didn't exist yet; one hardcoded Windows user path.
- **Decision:** folded subtask 1.2 (docs) into this PR to make the contract self-consistent rather than littering "(forthcoming)" notes. Added `docs/PLAN.md`, `docs/RULES.md`, `docs/PROGRESS.md`, `docs/LESSON.md`, `.gitattributes`; generalized the Herd path in AGENTS.md.
- Local review (pass 3 — report-only) found one gap: AGENTS.md step 6 only mentioned Copilot, not Codex. Fixed: step 6 now explicitly names both bots as equally binding and instructs nudging Codex with `@codex review`.
- **Outcome:** squash-merged into `task/project-conventions` as commit `1a504a4` (see "Resolution of PR #1" below for the full review-loop details).

### Subtask 1.3 — resume skill (.claude)
Branch: `task/project-conventions-1-3-skill` → PR into `task/project-conventions`.

- Added `.claude/skills/scalar-openapi-doc-plan/SKILL.md` (resume skill: read order AGENTS.md → RULES.md → PROGRESS.md → LESSON.md; how-to-resume; loop summary; project gotchas) and `.claude/settings.json` (command allowlist for git/gh/composer/php artisan/npm/playwright/copilot; deny force-push/hard-reset/.env reads).
- Recorded the review false-positive root cause in `docs/LESSON.md` (bots check existence vs PR base/merge-base) + thread-resolution + loop-termination lessons.

### Resolution of PR #1 (1.1+1.2)
- Ran 4 review rounds. Real findings fixed each round (review base, report-only command, machine paths, both-bots-binding, English PLAN.md, branch example, GraphQL quoting, inlined ADR intent, typo). Remaining threads were provably false positives (files added by the PR reported as "missing" — bots evaluate vs base). Resolved all threads via GraphQL with a proof comment; squash-merged into `task/project-conventions` as commit `1a504a4`.

---

## T2 — task/bootstrap

### Subtask 2.1 — scaffold Laravel 13 + react-starter-kit
Branch: `task/bootstrap-2-1-scaffold` → PR into `task/bootstrap`.

- Scaffolding `laravel new ... --react --pest --database=mysql` into a temp sibling dir `C:\xampp\htdocs\scalar-openapi-doc-tmp`, then copying app files into the repo preserving `.git`, LICENSE, AGENTS.md, CLAUDE.md, docs/, .claude/, .gitattributes (starter README set aside; rewritten in T9).
- `.env` dev target: MySQL via Herd (db `scalar_openapi_doc`), `CACHE_STORE=redis`.
- Verify: `php artisan about` on Herd, `npm run build`, login page renders, starter Pest tests pass.
- **Done:** scaffold committed `e36729b`, PR #4. Verified: artisan about (L13.15/PHP8.5/cache=redis/db=mysql), migrate OK, build OK, Pest 39/39, Pint clean, PHPStan L7 clean (needs `--memory-limit`).

#### Bot review backlog from PR #4 (scaffold) — to action in later subtasks
Bot findings on PR #4 mostly target **pristine starter-kit files** (out of scope for the faithful scaffold). Dispositions:
- **T2.3 (CI):** starter CI (`.github/workflows/{lint,tests}.yml`) `pull_request.branches` allowlist is `develop/main/master/workos` only → subtask PRs into `task/**` get **no CI** (why PR #1–#4 had no checks). Add `task/**`. Also: lint workflow runs write-mode `format`/`lint` (should use `format:check`/`lint:check` to fail on violations) and has `contents: write` (drop to `contents: read`). **These are the reason the "CI green" gate has been vacuous so far.**
- **T2.3 (PHPStan):** codify `--memory-limit` (default 128M crashes the parallel worker) and bump level 7 → max.
- **T8 (security pass):** consider hardening pristine starter code if we keep it — `app/Http/Middleware/HandleAppearance.php` appearance cookie (validate against allowlist + JSON-encode in `app.blade.php` inline script); `passkey-register.tsx` reads `navigator` in render (SSR-unsafe if SSR enabled).
- **Not-actionable / rejected:** `password-input.tsx` forwardRef — false positive under **React 19** (`ref` is a normal prop). `User.php` `MustVerifyEmail` — deliberately omitted: users are **admin-provisioned** (no self-registration), so email verification is not part of this product. `tests/Pest.php` placeholder helper — starter artifact, harmless; remove if it ever collides.

### Subtask 2.2 — quality tooling (merged, PR #5)
- PHPStan → **level max** + `phpstan-baseline.neon` (17 starter findings grandfathered) + `composer types:check` runs `--memory-limit=1G`.
- **Vitest** (`vitest.config.ts` jsdom + `@` alias via `import.meta.url` + `pool: forks`; `vitest.setup.ts`; sample tests; jest-dom type decl) → `npm run test` 6 green.
- **Playwright** (`playwright.config.ts` webServer `php artisan serve`; login smoke) → `npm run e2e` green.
- Regenerated `package-lock.json` for the `@emnapi/*` peers (Codex). `phpunit.xml` already SQLite+array — unchanged.

### Subtask 2.3 — CI (merged, PR #6)
- Workflows trigger on `pull_request` for `task/**` (push only on main/master/develop) + `concurrency`; `lint.yml` `contents: read` + Node 22 + check-mode (`lint:check`/`format:check`) + `wayfinder:generate --with-form`.
- `tests.yml` → 3 jobs: **php** (matrix 8.4/8.5: PHPStan max + Pest), **frontend** (PHP+Node, wayfinder, tsc + Vitest + build), **e2e** (`needs: [php, frontend]`, Playwright on SQLite + array + DB session).
- **CI fixes (false-green locally, red on clean CI):** `npm install` not `npm ci` (Windows lock on Linux); `withoutVite()` in `TestCase` (Pest needs no Vite manifest); `wayfinder:generate --with-form` before tsc/eslint (gitignored generated modules); PHP `^8.4` + matrix 8.4/8.5 (Symfony 8.1 needs ≥8.4.1). All in `docs/LESSON.md`.
- **All CI jobs green.** Merged into `task/bootstrap`.

## T3 — task/rbac-data-model
_Not started. Seeder skeletons reviewed: `RoleSeeder` (spatie `Role::findOrCreate` admin/user, guard `web`), `AdminUserSeeder` (`firstOrCreate` by `ADMIN_EMAIL`, assignRole admin — note: uses `env()` directly, route via config or read at runtime since `env()` returns null when config is cached), `DatabaseSeeder` (calls Role then AdminUser)._

**Carried into T3 (from PR #7 / Codex):** disable Fortify self-registration — `Features::registration()` in `config/fortify.php` lets anyone sign up, bypassing the admin-provisioned/RBAC model. Remove the feature and the register link/page (`resources/js/pages/auth/register.tsx`, route, `RegistrationTest`) since users are created by admins only.

### Subtask 3.2 — custom data model (migrations, enums, models)
Branch: `task/rbac-data-model-3-2-models`

- Migrations: `user_allowed_tags`, `user_allowed_endpoints`, `scalar_servers`, `auth_logs`.
- Enums: `AuthEvent` (login/logout/failed), `HttpVerb` (GET/POST/PUT/PATCH/DELETE/HEAD/OPTIONS/TRACE + `values()` helper).
- Models: `UserAllowedTag`, `UserAllowedEndpoint` (method cast to `HttpVerb`), `ScalarServer`, `AuthLog` (`UPDATED_AT=null`, `created_at` NOT NULL `useCurrent()`).
- `User` hasMany: `allowedTags`, `allowedEndpoints`, `authLogs`.
- `DataModelTest` (9 tests): unique constraints, cascade, SET NULL, casts, HttpVerb cast, values(), HasMany relations.
- **Copilot review loop (3 rounds):** all 6 findings fixed — phantom `app/Enums/Enums/HttpVerb.php` deleted, HttpVerb cast added to UserAllowedEndpoint, redundant `index('user_id')` removed from grant migrations, auth_logs.created_at NOT NULL + useCurrent(), TRACE added to HttpVerb, auth_logs.email widened to 255, utf8mb4_bin collation on tag/path for MySQL (guarded with `DB::getDriverName()` for SQLite compat).
- All gates green: Pest 52/52, Pint clean, PHPStan max (0 errors), final review → NO FINDINGS.
- **Status:** 🟡 in progress — PR not yet opened.

## T4 — task/openapi-service
_Not started._

## T5 — task/scalar-proxy
_Not started._

## T6 — task/admin-users
_Not started._

## T7 — task/admin-servers-audit
_Not started._

## T8 — task/hardening-polish
_Not started._

## T9 — task/release
_Not started._
