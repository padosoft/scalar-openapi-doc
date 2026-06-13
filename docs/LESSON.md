# LESSON.md — Lessons Learned

Append a dated entry whenever you discover something that would save the next agent (or a parallel subagent) time — setup quirks, API contracts, test workarounds, and **every fix that came out of a review comment**. Subagents must receive this file's content in their prompt. At release (T9) durable lessons are promoted into `AGENTS.md` / `docs/RULES.md` / the resume skill.

Format per entry: `- **[area]** lesson. **Why:** … **How to apply:** …`

---

## 2026-06-13

- **[ci/review]** This repo has **two** automated PR reviewers configured: GitHub **Copilot** and a **Codex connector** (`chatgpt-codex-connector[bot]`). Codex auto-triggers on PR open / ready-for-review / `@codex review`. **Why:** the workflow contract said "Copilot review" but both bots comment, and their findings must both be resolved. **How to apply:** when waiting for reviews, poll for both bots; treat Codex findings as equally binding. You can nudge Codex with a `@codex review` PR comment.

- **[gh/copilot]** `gh pr edit <PR> --add-reviewer copilot` fails with `Could not resolve user with login 'copilot'`. **Why:** Copilot isn't a regular user login resolvable by `gh`. **How to apply:** use the GraphQL fallback (verified working on this repo):
  ```
  $prId = gh pr view <PR> --json id -q .id
  gh api graphql -f query='mutation RequestReviewsByLogin($pullRequestId: ID!, $botLogins: [String!], $union: Boolean!) { requestReviewsByLogin(input: {pullRequestId: $pullRequestId, botLogins: $botLogins, union: $union}) { clientMutationId } }' -F pullRequestId=$prId -F 'botLogins[]=copilot-pull-request-reviewer[bot]' -F union=true
  ```
  Then confirm with `gh api repos/padosoft/scalar-openapi-doc/pulls/<PR>/requested_reviewers` (Copilot should appear under `users`).

- **[git/windows]** Commits warn `LF will be replaced by CRLF` until a `.gitattributes` exists. **Why:** Git's autocrlf default on Windows. **How to apply:** `.gitattributes` with `* text=auto eol=lf` (+ binary rules) landed in PR #1; the warning is harmless once it's committed.

- **[docs/review]** A PR that *mandates reading files which don't exist yet* is internally inconsistent and both bots will flag it. **Why:** a fresh agent following the contract hits missing required files. **How to apply:** when a subtask defines rules that reference other deliverables, ship the referenced files in the same PR (or explicitly mark them forthcoming). Here, subtask 1.2's `docs/*` were folded into PR #1.

- **[copilot/autopilot]** `copilot --autopilot --yolo -p "/review …"` will *edit and commit fixes itself* unless told not to. Its first pass on the AGENTS.md diff auto-applied 6 fixes — most correct, but one rewrote a working GraphQL mutation into a broken `requestReviews` with empty `userIds`. **Why:** autopilot acts, doesn't just report. **How to apply:** for a report-only review, add "DO NOT modify or commit any files - report findings only" to the prompt; always re-verify any auto-applied change before trusting it.
