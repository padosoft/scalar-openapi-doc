# AGENTS.md — Operating Contract for scalar-openapi-doc

Canonical plan: `docs/PLAN.md`. Progress tracker: `docs/PROGRESS.md`. Lessons learned: `docs/LESSON.md`. Coding rules: `docs/RULES.md`.

Every agent (main session or subagent) MUST read this file, `docs/RULES.md`, `docs/PROGRESS.md` and `docs/LESSON.md` before doing any work. Subagents launched in parallel MUST receive the content of `docs/LESSON.md` and the rules below in their prompt context.

## Project Facts

- App: API documentation portal — Laravel 13, PHP 8.5, Inertia 3 + React 19 + TypeScript + Tailwind 4 + shadcn/ui (laravel/react-starter-kit), Scalar for OpenAPI rendering, spatie/laravel-permission for RBAC, Fortify auth.
- Core security principle: the OpenAPI spec is filtered **server-side per user** (UNION of granted tags + granted endpoints; admin sees all). The browser never receives the full spec for non-admins. The client never participates in authorization decisions.
- Local dev: **MySQL via Herd** (db `scalar_openapi_doc`) + **Redis via Herd** (`CACHE_STORE=redis`). Tests: SQLite `:memory:` + `CACHE_STORE=array`. UI language: **English**.
- Repo: `github.com:padosoft/scalar-openapi-doc`, default branch `main`.

## Machine Rules (Windows)

- **Always use Herd PHP/Composer, never XAMPP PHP.** `php` and `composer` resolve to `C:\Users\lopad\.config\herd\bin\` shims (PHP 8.5.x). Do not invoke anything under `C:\xampp\php`.
- Keep `.gitattributes` with `* text=auto eol=lf` to avoid CRLF churn.
- Playwright `webServer` uses `php artisan serve` (Herd PHP on PATH).
- If Vitest workers hang on Windows, prefer a Windows-safe `pool` setting (document the working choice in `docs/LESSON.md`).

## Operating Rules

- `declare(strict_types=1)` in every PHP file; fully typed signatures (params + returns).
- Systematic early return; minimize nesting (ideally one indentation level per method).
- `try/catch` with structured logging and context — never silent catches.
- **No `array_merge` inside loops**; use spread/collections and chunked bulk inserts.
- Actions-oriented architecture: one action = one responsibility.
- FormRequest for validation/authorization; API Resource for serialization; typed Enums for closed value sets.
- Never trust client input for authorization. Granted tags/endpoints are validated server-side against the real spec (anti-tampering, ADR-05).
- Never expose secrets in JSON, UI, or logs (upstream auth token stays server-side).
- Verbose comments only on non-obvious parts (especially the OpenAPI filter/pruning).
- Update `docs/PROGRESS.md` after every meaningful step (it is the resume point if the session dies).
- Update `docs/LESSON.md` whenever you discover anything that would save the next agent time — including every fix that comes out of a Copilot review comment.

## Branch & PR Loop (mandatory, per subtask)

One branch per macro task: `task/<macro-name>` (created from `main`, pushed immediately). Each subtask is a child branch `task/<macro-name>-<n-n-slug>` and gets a PR **into the macro branch**. When the macro task is complete, open the macro PR `task/<macro-name>` → `main` and run the same validation loop.

Definition of Done for every subtask:

1. Precise objective + implementation details + guardrails: **Pest** tests (PHP), **Vitest** tests (TS/JS), and **Playwright scenarios for every UI/UX interaction** introduced (backend-only subtasks skip Playwright).
2. Local gates all green (run the relevant subset):
   ```
   vendor/bin/pint --test
   vendor/bin/phpstan analyse
   php artisan test
   npm run test
   npm run build
   npx playwright test
   ```
3. Local Copilot review loop — zero comments required before push:
   ```
   git diff origin/main...HEAD > %TEMP%\pr-diff.txt
   copilot --autopilot --yolo -p "/review review the attached diff of my branch vs origin/main: %TEMP%\pr-diff.txt"
   ```
   Pass the **full branch diff vs origin/main** (not just unstaged files). If the diff is small it can be inlined in the prompt; otherwise always go through the temp file. Fix every finding, re-run gates, re-review, until the review returns zero actionable comments.
4. Push and open the PR with `gh`:
   ```
   git push -u origin <subtask-branch>
   gh pr create --base task/<macro-name> --title "..." --body "..."
   ```
5. Add GitHub Copilot as reviewer and verify the review actually started:
   ```
   gh pr edit <PR> --add-reviewer @copilot
   gh api repos/padosoft/scalar-openapi-doc/pulls/<PR>/requested_reviewers
   ```
   Fallback when the token lacks scopes (`--add-reviewer @copilot` fails):
   ```
   gh api graphql -f query='mutation($pr: ID!) { requestReviewsByLogin(input: {pullRequestId: $pr, botLogins: ["copilot-pull-request-reviewer[bot]"], union: true}) { clientMutationId } }' -F pr='<PR_NODE_ID>'
   ```
6. Wait for **both** CI fully green **and** Copilot review comments. Fix broken tests and every Copilot comment, push again, request a fresh Copilot review, and repeat the loop.
7. Only when CI is green and all review threads are resolved: **merge** the PR, update `docs/PROGRESS.md` (and `docs/LESSON.md` for anything learned), then move to the next subtask.

Never skip a gate silently. If a tool is unavailable (Copilot quota, CI outage, network), record the exact blocker in `docs/PROGRESS.md` and stop or ask the user — do not pretend the gate passed.

## Testing Rules

- PHP: Pest. Feature tests cover RBAC (403 matrix), proxy filtering (two users with different grants receive different specs), anti-tampering validation, audit rows, transactional grant replacement. Unit/golden tests cover `OpenApiSpecService` (filter, extract, pruning) against fixtures in `tests/Fixtures/`.
- TS: Vitest for component logic (MultiSelect, DataTable, role-conditional nav).
- E2E: Playwright for every UI interaction (forms, dialogs, toasts, filters, pagination, role-based visibility). Backend-only changes don't need Playwright.
- Tests run on SQLite `:memory:` with `CACHE_STORE=array` — code must stay cache/db driver-agnostic (use facades, no Redis-only APIs).

## Subagent Rules

- Every subagent prompt MUST include: the relevant subtask goal + DoD, the Operating Rules above, and the current content of `docs/LESSON.md`.
- One main integrator (this session) reviews subagent output, resolves conflicts, and runs the final gates — subagents never push or merge on their own.

## Release Rules

- Final README must follow the "WOW" template (badges, TOC, innovations, junior-proof quick start) modeled on https://github.com/lopadova/AskMyDocs.
- Before tagging: re-check `docs/LESSON.md` and promote every durable lesson into this file, `docs/RULES.md`, or the resume skill.
- Release: `git tag vX.Y.Z` + `gh release create` with changelog assembled from merged macro PRs.
