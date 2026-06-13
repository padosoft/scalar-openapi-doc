# CLAUDE.md

**Read `AGENTS.md` first — it is the binding operating contract** (branch/PR loop, Copilot review gates, testing rules, machine rules). Then check `docs/PROGRESS.md` for where work currently stands and `docs/LESSON.md` for accumulated discoveries.

## Quick Facts

- **Stack:** Laravel 13 · PHP 8.5 (Herd) · Inertia 3 + React 19 + TS + Tailwind 4 + shadcn/ui · Scalar (`scalar/laravel`) · spatie/laravel-permission · Pest · Vitest · Playwright · Pint · Larastan (max).
- **What it does:** authenticated portal rendering external OpenAPI docs via Scalar, with per-user **server-side** spec filtering (granted tags ∪ granted endpoints; admin sees all), admin CRUD for users/grants/playground servers, auth audit log, Redis-cached upstream spec with stale-on-error fallback.
- **Local dev:** MySQL via Herd (`scalar_openapi_doc`), Redis via Herd. **Tests:** SQLite `:memory:`, array cache. **UI language:** English.
- **Never use XAMPP PHP** — only Herd shims (`php`, `composer` on PATH).

## Key Paths

- `docs/PLAN.md` — full implementation plan (macro tasks T1–T9 + analysis findings B1–B9).
- `docs/PROGRESS.md` — live progress tracker (resume point after interruption).
- `docs/LESSON.md` — lessons learned (update on every discovery / Copilot fix).
- `docs/RULES.md` — coding standards + subtask Definition-of-Done template.
- `app/Services/OpenApiSpecService.php` — security core (fetch/cache/filter/prune).
- `.claude/skills/scalar-openapi-doc-plan/` — resume skill for new sessions _(forthcoming — subtask 1.3)_.

## Test Commands

```
vendor/bin/pint --test
vendor/bin/phpstan analyse --level=max
php artisan test
npm run test
npm run build
npx playwright test
```

## Workflow (summary — full version in AGENTS.md)

Macro task = branch `task/<name>`; subtask = child branch + PR into macro branch; local gates green → local `copilot --autopilot --yolo -p "/review ..."` with zero comments → push → PR → add `copilot` reviewer → CI green + comments resolved → merge. Macro done → PR to `main`, same loop.
