---
name: scalar-openapi-doc-plan
description: Continue or resume the scalar-openapi-doc implementation (Laravel 13 OpenAPI docs portal with Scalar, per-user server-side spec filtering, RBAC). Use when working in the scalar-openapi-doc repo, when context was compacted or lost, when following the saved plan, or when enforcing the branch / PR / Copilot+Codex review / documentation / testing / security rules for this project.
---

# Resume: scalar-openapi-doc

This skill rebuilds full working context after a new session, a context compaction, or a handoff. **Read these four files first, in order** — they are the source of truth and override anything you remember:

1. **`AGENTS.md`** (repo root) — the binding operating contract: machine rules (Herd PHP, never XAMPP), coding rules, the mandatory Branch & PR Loop, testing gates, subagent rules, release rules.
2. **`docs/RULES.md`** — coding standards + the per-subtask Definition of Done + DoD template.
3. **`docs/PROGRESS.md`** — the live resume point: which macro task (T1–T9) and subtask is in flight, what's merged, what's next. **Start work from the first unfinished item here.**
4. **`docs/LESSON.md`** — accumulated discoveries; never re-learn these the hard way. Pass its content into every subagent prompt.

The full plan (macro tasks, analysis findings B1–B9, ordering) is in **`docs/PLAN.md`**.

## How to resume

1. Read the four files above. Identify the current macro task + subtask from `docs/PROGRESS.md`.
2. Confirm the environment: `(Get-Command php).Source` and `php -v` must show the **Herd** PHP shim (8.5 on this machine; see AGENTS.md/PLAN.md for currently supported versions, ≥8.3), **not** XAMPP. `gh auth status` and `copilot` must be available.
3. Check git: `git branch --show-current` and `git status`. Macro work lives on `task/<macro-name>`; each subtask is a child branch `task/<macro-name>-<n-n>-<slug>` with a PR into the macro branch.
4. Continue the Branch & PR Loop (AGENTS.md) from where PROGRESS.md left off. Do not skip gates.

## The non-negotiable loop (summary — full version in AGENTS.md)

Per subtask: write code + guardrails (Pest / Vitest / Playwright-if-UI) → local gates green (`vendor/bin/pint --test`, `vendor/bin/phpstan analyse --level=max`, `php artisan test`, `npm run test`, `npm run build`, `npx playwright test` if UI) → **local Copilot review, report-only**, diffing against the **PR base** (macro branch for subtasks; `origin/main` only for the macro PR), loop to zero comments → push → open PR → request Copilot review (`gh pr edit <PR> --add-reviewer copilot`; on failure use the GraphQL `requestReviewsByLogin` fallback in AGENTS.md) → wait for CI green **and** both bot reviewers (Copilot **and** Codex) → fix every real comment, re-review → merge → update `docs/PROGRESS.md` and `docs/LESSON.md`.

## Project-specific gotchas (see docs/LESSON.md for the full list)

- This repo has **two** auto-reviewers: GitHub **Copilot** and the **Codex** connector. Both are binding.
- `gh pr edit --add-reviewer copilot` fails (`gh` can't resolve the `copilot` login) → use the GraphQL fallback.
- Both bots evaluate file existence against the **PR base / merge-base**, so files *added by the PR* get flagged as "missing." On a subtask PR into a fresh macro branch these are false positives — verify with `git diff --name-status <base>...HEAD`, then resolve the threads.
- `copilot --autopilot --yolo` **edits and commits files** unless the prompt says "report findings only".
- Windows/Herd: PowerShell parse errors abort the *entire* chained command before anything runs — verify pushes landed (`git rev-parse origin/<branch>`).
- On Windows, Claude Code's **PowerShell** tool is a separate permission namespace from **Bash** — `.claude/settings.json` mirrors every rule under both so the allowlist and force-push/`.env` guards actually apply to the shell in use.
