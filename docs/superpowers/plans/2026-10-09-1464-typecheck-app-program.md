# #1464 Type-check the App Program Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `npm run check` fails when the program `ng serve` / `npm run build` compile (`tsconfig.app.json`) has a TypeScript error.

**Architecture:** `check:static` already runs `tsc --noEmit` over the spec and e2e programs; add the same for the app program, first in the chain of type-checks. CI's `Frontend (static)` job runs `npm run check:static`, so it picks the step up with no workflow change.

**Tech Stack:** TypeScript `tsc`, npm scripts.

**Spec:** GitHub issue #1464.

## Global Constraints

- Script verbatim from the issue: `"typecheck:app": "tsc -p tsconfig.app.json --noEmit"`.
- `tsc` does not check Angular templates; that stays with `ng build` (CI's `Frontend (build)` job).

---

### Task 1: Add `typecheck:app` to the gate

**Files:**
- Modify: `frontend/package.json` (`scripts`)
- Modify: `frontend/README.md` (the gate list, which also lacks the e2e typecheck)

- [ ] **Step 1: Break-test first.** Add `describe('x', () => {});` to the end of a non-spec app file, e.g. `frontend/src/app/app.config.ts`, and run `docker compose exec -T frontend npx tsc -p tsconfig.app.json --noEmit`. Expected: `Cannot find name 'describe'`. Then confirm `npm run check:static` still PASSES with the break in place, which shows the gap.

- [ ] **Step 2: Add the script** and put it first in the chain of type-checks:

```json
"typecheck:app": "tsc -p tsconfig.app.json --noEmit",
"typecheck:spec": "tsc -p tsconfig.spec.json --noEmit",
"typecheck:e2e": "tsc -p tsconfig.e2e.json --noEmit",
"check:static": "npm run lint && npm run format:check && npm run stylelint && npm run typecheck:app && npm run typecheck:spec && npm run typecheck:e2e",
```

- [ ] **Step 3: Rerun with the break still in place.** `docker compose exec -T frontend npm run check:static` must FAIL with `Cannot find name 'describe'`. Remove the break with an editor edit (not `git checkout --`) and rerun: it must PASS.

- [ ] **Step 4: README.** Replace the single "Spec typecheck" bullet with:

```markdown
- **Typecheck** (`npm run typecheck:app`, `typecheck:spec`, `typecheck:e2e`) — `tsc`
  over `tsconfig.app.json`, `tsconfig.spec.json` and `tsconfig.e2e.json`. Angular
  template type-checking is left to `npm run build`.
```

- [ ] **Step 5: Commit**

```bash
git add frontend/package.json frontend/README.md docs/superpowers/plans/2026-10-09-1464-typecheck-app-program.md
git commit -m "fix(#1464): type-check the app program in npm run check"
```
