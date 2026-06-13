# PROGRESS.md — Live Progress Tracker

This is the resume point. If a session dies, the next agent reads this file (plus `AGENTS.md`, `docs/RULES.md`, `docs/LESSON.md`) and continues exactly from here. Update it after every meaningful step. Newest entries at the bottom of each task.

## Macro Task Status

| # | Macro task | Branch | Status | Macro PR |
|---|---|---|---|---|
| T1 | Project conventions (docs, rules, resume skill) | `task/project-conventions` | 🟡 in progress (1.1+1.2 merged, 1.3 in PR) | — |
| T2 | Bootstrap (scaffold + tooling + CI) | `task/bootstrap` | ⚪ pending | — |
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

### Subtask 1.1 + 1.2 — operating docs (AGENTS.md, CLAUDE.md, docs/*) + .gitattributes
Branch: `task/project-conventions-1-1-agents` → PR #1 into `task/project-conventions`.

- Created `AGENTS.md` (operating contract: branch/PR loop, Copilot review gates, testing rules, Herd machine rules, subagent rules, release rules) and `CLAUDE.md` (quick reference).
- Local Copilot review pass 1 → 6 findings fixed; pass 2 → NO FINDINGS.
- Opened PR #1. `gh pr edit --add-reviewer copilot` failed → used GraphQL `requestReviewsByLogin` fallback (works). Confirmed Copilot in requested_reviewers.
- **Both Copilot and the repo's Codex connector auto-reviewed.** Findings converged: docs referenced `docs/*` files and `.gitattributes` that didn't exist yet; one hardcoded Windows user path.
- **Decision:** folded subtask 1.2 (docs) into this PR to make the contract self-consistent rather than littering "(forthcoming)" notes. Added `docs/PLAN.md`, `docs/RULES.md`, `docs/PROGRESS.md`, `docs/LESSON.md`, `.gitattributes`; generalized the Herd path in AGENTS.md.
- Local review (pass 3 — report-only) found one gap: AGENTS.md step 6 only mentioned Copilot, not Codex. Fixed: step 6 now explicitly names both bots as equally binding and instructs nudging Codex with `@codex review`.
- Next: push updated diff → wait CI + both bot reviews resolved → merge.

### Subtask 1.3 — resume skill (.claude)
Branch: `task/project-conventions-1-3-skill` → PR into `task/project-conventions`.

- Added `.claude/skills/scalar-openapi-doc-plan/SKILL.md` (resume skill: read order AGENTS.md → RULES.md → PROGRESS.md → LESSON.md; how-to-resume; loop summary; project gotchas) and `.claude/settings.json` (command allowlist for git/gh/composer/php artisan/npm/playwright/copilot; deny force-push/hard-reset/.env reads).
- Recorded the review false-positive root cause in `docs/LESSON.md` (bots check existence vs PR base/merge-base) + thread-resolution + loop-termination lessons.

### Resolution of PR #1 (1.1+1.2)
- Ran 4 review rounds. Real findings fixed each round (review base, report-only command, machine paths, both-bots-binding, English PLAN.md, branch example, GraphQL quoting, inlined ADR intent, typo). Remaining threads were provably false positives (files added by the PR reported as "missing" — bots evaluate vs base). Resolved all threads via GraphQL with a proof comment; squash-merged into `task/project-conventions` as commit `1a504a4`.

---

## T2 — task/bootstrap
_Not started._

## T3 — task/rbac-data-model
_Not started._

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
