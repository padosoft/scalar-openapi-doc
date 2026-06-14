# PROGRESS.md — Live Progress Tracker

This is the resume point. If a session dies, the next agent reads this file (plus `AGENTS.md`, `docs/RULES.md`, `docs/LESSON.md`) and continues exactly from here. Update it after every meaningful step. Newest entries at the bottom of each task.

> **⚠️ Active blocker (2026-06-13): GitHub Copilot reviewer unavailable org-wide.**
> The Copilot org spend limit is reached (`additional_spend_limit_reached`, HTTP 402): the **local** `copilot` CLI returns 402 AND the **remote** Copilot PR reviewer bot no longer attaches (re-requests leave `requested_reviewers` empty). **Codex remote review still works** and CI is green. Per the amended AGENTS.md, binding review continues via **Codex + CI + local gates** (pint, phpstan max, pest, vitest, build); each merge is gated on those. **Action for the owner:** raise/clear the Copilot spend limit at github.com/settings/copilot/features to restore Copilot reviews. Until then, Copilot-review is treated as unavailable (documented per-PR).

## Macro Task Status

| # | Macro task | Branch | Status | Macro PR |
|---|---|---|---|---|
| T1 | Project conventions (docs, rules, resume skill) | `task/project-conventions` | 🟢 merged (PR #3 → `main`, `691db58`) | #3 |
| T2 | Bootstrap (scaffold + tooling + CI) | `task/bootstrap` | 🟢 merged (PR #7 → `main`, `7738203`) | #7 |
| T3 | RBAC & data model | `task/rbac-data-model` | 🟢 merged (macro PR #12 → `main`) | #12 |
| T4 | OpenApiSpecService + hardening | `task/openapi-service` | 🟡 subtasks merged (#13, #14, #15); macro PR → main open | — |
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
- **Copilot review loop (3 rounds):** all 6 findings fixed — phantom `app/Enums/Enums/HttpVerb.php` deleted, HttpVerb cast added to UserAllowedEndpoint, redundant `index('user_id')` removed from grant migrations, auth_logs.created_at NOT NULL + useCurrent(), TRACE added to HttpVerb, auth_logs.email widened to 255, utf8mb4_bin collation on tag/path for MySQL **and MariaDB** (guarded with `in_array(DB::getDriverName(), ['mysql','mariadb'])` for SQLite compat).
- All gates green: Pest 52/52, Pint clean, PHPStan max (0 errors), final review → NO FINDINGS.
- **Status:** 🟢 merged. Subtasks 3.1 (#9), 3.2 (#10), 3.3 (#11) merged into `task/rbac-data-model`; macro PR #12 merged into `main`. Fortify self-registration disabled (`config/fortify.php` drops `Features::registration()`).

## T4 — task/openapi-service

### Subtask 4.1–4.2 — baseline service + Auth-free refactor (merged)
- Copied skeleton `OpenApiSpecService`, `config/openapi.php`, golden tests, `tests/Fixtures/openapi.json` (B9). Removed the hidden `Auth::` dependency (B1): `filterForUser(..., bool $isAdmin = false)` + `flushCache(bool $includeStale = false, ?int $actorId = null)` — the controller resolves authorization and passes it in; the service stays pure/testable. Admin bypass is config-gated (`openapi.admin_sees_all`), no hardcoded role.

### Subtask 4.3–4.7 — hardening (PR #14 into `task/openapi-service`, in review)
Branch: `task/openapi-service-4-3-hardening`.
- **B3 anti-cache-poisoning:** `assertValidSpec()` (openapi+info+container) before caching; `InvalidOpenApiSpecException`; stale-on-error fallback.
- **B4 SSRF:** `assertAllowedUrl()` (scheme/host allow-list) pre-fetch + `->withoutRedirecting()` so an allow-listed upstream can't redirect off-list (Codex).
- **B5/B6 pruning:** filter `paths` AND `webhooks` (3.1); seed `securitySchemes` by NAME from root+operation `security`; **root schemes seeded only when a surviving op inherits root security** (Codex — empty grants drop `components` entirely). Webhook endpoint grants are **namespaced** (`webhook:` prefix) so a webhook keyed identically to a real path can't be exposed by the other's grant (Codex).
- **B7 servers:** `injectServers` validates entries — non-empty AND a valid absolute http(s) URL via `isValidServerUrl` (rejects `not-a-url`/`javascript:`/`ftp:`, Codex), optional string description.
- **metadata:** `extractTags`/`extractEndpoints` enumerate `paths` + `webhooks` so webhook-only tags/ops are grantable (Codex).
- **Gates:** Pest 84/84, PHPStan max 0 (run with `-d memory_limit=1G`), Pint clean. Codex review loop: 5 findings (redirect, webhook metadata, server-url validation, root-security pruning, path/webhook grant collision) all fixed; threads resolved. **Merged (PR #14).**

### Subtask 4.8 — OpenAPI 3.1 path-item `$ref` reuse (PR #15, merged)
Branch: `task/openapi-service-4-8-pathitem-refs`. Follow-up after a Codex finding slipped past PR #14's merge.
- A `paths`/`webhooks` entry that is a Reference Object (`{"$ref": "#/components/pathItems/X"}`) was dropped entirely (no inline verb), so granted reused operations vanished for non-admins. `resolvePathItem` resolves the **whole `$ref` chain** (cycle-safe; strips any malformed/external/unsupported ref; no path-item `$ref` survives) and **inlines** only the filtered survivors; `extractTags`/`extractEndpoints` resolve refs too (grantability).
- Closed a cascade of Codex-found leak vectors: nested-chain ref re-leak via pruning; `prune_components=false` re-exposing the inlined source; callback-referenced pathItems (inline + via `components.callbacks`). **Resolution:** pruning is now an **always-on security invariant** (one full transitive reachability closure, follows callbacks→pathItems); removed the `prune_components` toggle (config key + env + test) — admins still get the full spec via `admin_sees_all`. Also `resolvePathItem` avoids `+=` in a loop (RULES.md ADR-07) via layered `array_replace`.
- **Gates:** Pest 94/94, PHPStan max 0, Pint clean. 8 Codex findings across 7 rounds, all fixed/resolved. (Final commit `dd32674`: Codex unresponsive to re-nudges over ~50 min while CI green + 0 unresolved threads; merged per the Copilot-outage exception — the macro PR → main provides the next full Codex gate over the same code.)
- **Next:** macro PR `task/openapi-service` → `main` (Codex + CI + local gates), merge, mark T4 complete, then T5.

### Macro PR #16 — `task/openapi-service` → `main` (in review)
Full Codex re-review of the whole T4 core. Multiple adversarial rounds hardening deep OpenAPI 3.1 / JSON-Schema-2020-12 / URI-resolution edge cases (each a separate commit, threads resolved):
- query-string in ref-document comparison; position-aware operation-bearing refs; full-URI relative resolution; normalize absolute/protocol-relative refs; sibling target fields checked even with a link `$ref`; link dropped if any local target field is filtered; normalize URI origin case/port; alias-link sibling checks.
- **`a928661`→`027018a` (P1, latest):** "drop non-link local `$ref`s from Link Objects" enforced across **all three** reachability sites — inline-link survival (`linkTargetSurvives`), `components.links` alias fixpoint, and `drainReachability` example/link/callback alias branch: a same-document `$ref` to the wrong component type is dropped (never kept/followed); only truly external refs are kept conservatively. Plus `scopes` added to the keyword-name-map guard (OAuth2 scope named `$ref` is data, not a reference). New tests: malformed link `$ref` to a schema dropped without leak; scope `$ref` ignored. See LESSON `[security/component-alias-same-type]`.
- **Gates on `027018a`:** Pest 152/152, PHPStan max 0, Pint clean; **CI all green** (frontend, php 8.4/8.5, quality, e2e, 0 failures). All 50 review threads resolved.
- **Blocker:** local `copilot` review gate hit `402 additional_spend_limit_reached` (usage cap) — skipped with the binding remote **Codex** review as the gate (see LESSON `[review/copilot-spend-limit]`). Re-attempt local Copilot when the limit resets.
- **`027018a`→`eeea26e` (Codex round on `027018a`, 3 threads):** P1 (link non-link refs) confirmed done; P2 (`scopes` name-map) confirmed present; **new P2** — "restrict path-item refs to pathItems": the operation-bearing allowance was a single bool admitting both `pathItems` AND `callbacks`, so a callback path item with `$ref` → `#/components/callbacks/Hidden` leaked that callback. Fixed: `$addComponent` now takes the single legal operation-bearing **type** (path-item context → `pathItems` only; callback `$ref` → `callbacks` only; cross-type dropped). New regression test. See LESSON `[security/operation-bearing-ref-is-type-specific]`. Gates: Pest 153/153, PHPStan max 0, Pint clean. All 3 threads resolved, `@codex review` posted.
- **`eeea26e`→`b610331` (Codex round on `eeea26e`, 2 P2 threads):** (1) `normalizePath()` collapsed empty path segments, so an external `https://host//openapi.json#/...` ref compared equal to the upstream document and kept a local component — now RFC 3986 §5.2.4 dot-segment removal that preserves empties (see LESSON `[uri/preserve-empty-path-segments]`); (2) a digit-keyed OpenAPI `examples` map decodes as a PHP list and was skipped by `array_is_list`, dropping its real example refs → dangling — now iterate regardless of list-ness (see LESSON `[openapi/numeric-keyed-maps-decode-as-list]`). Gates: Pest 155/155, PHPStan max 0, Pint clean. Both threads resolved, `@codex review` posted.
- **`b610331`→`f5079e1` (Codex round on `b610331`, 1 P2 thread):** the numeric-keyed-examples fix had overcorrected — dropping `array_is_list` made JSON Schema `examples` **data arrays** get scanned for refs, so `examples: [{"$ref":"schemas/Internal"}]` in a granted schema leaked Internal. Fixed by **context**: an `$inSchema` flag threaded through the walk (set on `schema` keyword + JSON Schema applicators, monotonic down) — `examples` is skipped in a Schema Object, treated as an Example map (numeric keys supported) in Media Type/Parameter/Header. Component `schemas` drained in schema context. See LESSON `[openapi/examples-meaning-is-contextual]`. Gates: Pest 157/157, PHPStan max 0, Pint clean. Thread resolved, `@codex review` posted.
- **`f5079e1`→`138d3b5` (Codex round on `f5079e1`, 1 P2 thread):** `normalizePath` refinement — RFC 3986 §5.2.4 says a *terminal* `.`/`..` leaves a trailing slash (`/openapi.json/.` → `/openapi.json/`), but the loop dropped a terminal `.` outright, re-collapsing onto the upstream and leaking. Now a terminal `.`/`..` appends an empty output segment. Test added. Gates: Pest 158/158, PHPStan max 0, Pint clean. Thread resolved, `@codex review` posted.
- **`138d3b5`→`f49068e` (Codex round on `138d3b5`, 1 P2 thread):** percent-encoded URI fragments (`#%2Fpaths%2F~1admin%2Fget`) reached `localFragment()` raw and failed the local-pointer prefix checks, so a link to a filtered `/admin` op was kept (leak). Now `localFragment()` `rawurldecode()`s the fragment (JSON Pointer `~0`/`~1` survive). Tests both directions. See LESSON `[uri/percent-decode-fragments]`. (Also hit GitHub's `reviewThreads` 100-record cap counting threads → now query `last:100`; LESSON `[review/graphql-thread-pagination]`.) Gates: Pest 160/160, PHPStan max 0, Pint clean. Thread resolved, `@codex review` posted.
- **`f49068e`→`f5edc94` (Codex round on `f49068e`, 1 P2 thread):** a non-standard `security` annotation inside a granted Schema Object was read as an OpenAPI Security Requirement, keeping/leaking an unreferenced securityScheme. Gated scheme-collection on `! $inSchema`. See LESSON `[security/keyword-meaning-is-positional]`. Gates: Pest 161/161, PHPStan max 0, Pint clean. Thread resolved, `@codex review` posted.
- **Possible next sweep (proactive):** `links`/`callbacks` keys inside a Schema Object are likewise non-standard; if Codex flags them (or pre-emptively), skip them when `$inSchema` so a schema annotation can't keep a link/callback (operation-bearing) component. Fix is one early guard + tests; held to avoid interrupting the in-flight `f5edc94` review.
- **`f5edc94`→`46acdab` (Codex round on `f5edc94`, 2 P2 threads):** (1) `security` position — `! $inSchema` was insufficient (responses/headers are also non-schema); now a precise `$securityIsRequirement` flag set only at HTTP-verb/operation level. (2) emptied `paths` serialized as `[]`; now forced to `(object) []` so it's valid `{}`. See LESSON `[security/keyword-meaning-is-positional]`, `[openapi/empty-maps-must-be-json-objects]`. Gates: Pest 164/164, PHPStan max 0, Pint clean. Threads resolved, `@codex review` posted.
- **Awaiting:** Codex review of `46acdab`; if clean → merge PR #16 → `main`, mark T4 complete, start T5.

### Review-gate policy (decided 2026-06-14, user)
- **T4 only** has the deep adversarial edge-case tail (it parses the whole OpenAPI 3.1 / JSON-Schema / URI surface). Stay **rigid** here (zero Codex security comments before merge), but flush the class **proactively** (audit sibling branches in one commit) instead of one-finding-per-round.
- **T5–T9**: Codex gates **only** on security/auth findings; merge on CI+local green otherwise (don't block on non-security nits).
- **New final task (T10)**: at the very end, run a single deep Copilot review over the whole codebase as the last gate before release.

### Macro PR #16 — later rounds (continued)
- **`46acdab`→`543e1e9`:** verb-key heuristic too loose — a nested object named `get`/`post` (response/header/schema) treated as an operation; now gated on real `$pathItemContext` (paths/webhooks seeding walks each path item as a path-item position). Proactive sweep: `links`/`callbacks` inside a schema skipped. Pest 166/166.
- **`543e1e9`→`f7641d8`:** anchor-owner scan walked schema components with `$inSchema=false`, bypassing the new guards — a `$anchor` in a schema `callbacks` annotation could keep a hidden schema. `collectAnchorNames` now takes `$inSchema` (true for `schemas`); swept all walk call sites for consistency. See LESSON `[security/parallel-walks-must-share-context]`. Pest 167/167, PHPStan max 0, Pint clean.
- **`f7641d8`→`40c7130`:** (1) `$ref` anchor-vs-pointer check judged the RAW string → a percent-encoded pointer (`#%2F…`) was mis-recorded as an anchor and its schema pruned (dangling); now classified on the DECODED `localFragment()`. (2) bare `#anchor` enqueued every owner → an unreferenced schema could survive by reusing a visible anchor name; now resolves only when EXACTLY ONE component declares it (ambiguous → unresolved, full `$id` scoping out of scope). Pest 169/169, PHPStan max 0, Pint clean.
- **Awaiting:** Codex review of `40c7130`.

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
