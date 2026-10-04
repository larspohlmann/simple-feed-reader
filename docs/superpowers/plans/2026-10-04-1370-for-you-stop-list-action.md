# For You Stop as a list action (#1370) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** During a recommendation run, the For You header's Stop control is the same `appListAction` as idle Refresh, tinted `--danger`, instead of a filled danger `app-button`.

**Architecture:** One template swap in the shell's `#headerActions` and one SCSS rule replacing the bespoke compact-label rule. The compact icon-only form then comes from the global `_list-action.scss`, like every other list-header action (#1127).

**Tech Stack:** Angular 20 standalone, SCSS, Jest.

---

### Task 1: Stop is a list action

**Files:**
- Modify: `frontend/src/app/reader/shell/reader-shell.component.spec.ts` (the "stops the run" spec)
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html` (running branch of `.for-you-action`)
- Modify: `frontend/src/app/reader/shell/reader-shell.component.scss` (`.for-you-run .label` container rule)

- [ ] **Step 1: Failing test.** In "stops the run when the stop button is clicked", query `button.for-you-run` and assert it carries `list-action` before clicking it.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/shell/reader-shell.component.spec.ts -t "stops the run"`; expect FAIL (no `button.for-you-run` during a run).
- [ ] **Step 3: Template.** Replace the `<app-button variant="danger">` with
  `<button appListAction class="for-you-run stop" type="button" [disabled]="recs.stopping()" [attr.aria-label]=… [attr.title]=… (click)="recs.stop()">`, icon `stop_circle`, label in `<span class="txt">`.
- [ ] **Step 4: SCSS.** Delete the `@container` rule hiding `.for-you-run .label`; add `.for-you-run.stop:not(:disabled) { color: var(--danger); }` (`:not(:disabled)` so the shared disabled look still wins while stopping).
- [ ] **Step 5: Run** the spec file; expect PASS. Then `docker compose exec -T frontend npm run check`.
- [ ] **Step 6: Visual check** in the browser: wide and compact header, light and dark, during a run.
- [ ] **Step 7: Commit** `fix(#1370): for-you stop is a danger-tinted list action`.
