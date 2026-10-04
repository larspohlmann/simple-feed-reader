# For You "Show progress" as a list action (#1372) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** The For You header's "Show progress" control (offered while a run is going and its pill is closed) is a labelled `appListAction` like its neighbours, not an icon-only ghost `app-button`.

**Architecture:** One template swap in the shell's `#headerActions`; the compact icon-only form then comes from the global `_list-action.scss` (#1127). `pillHidden()` is only ever true on a narrow layout, so the issue's open point about a long wide-header row does not arise on desktop; the label shows only in the tablet range where the header is still wide, which is exactly where #1127 wants it.

**Tech Stack:** Angular 20 standalone, SCSS, Jest.

---

### Task 1: Show progress is a list action

**Files:**
- Modify: `frontend/src/app/reader/shell/reader-shell.component.spec.ts` ("offers a way back to the pill only once the pill has been closed")
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html` (`.for-you-show`)
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts` (drop `ButtonComponent` if it has no other use)

- [ ] **Step 1: Failing test.** Query `button.for-you-show`, assert it carries `list-action` and its `.txt` reads "Show progress", then click it.
- [ ] **Step 2: Run** `npx jest src/app/reader/shell/reader-shell.component.spec.ts -t "way back to the pill"`; expect FAIL.
- [ ] **Step 3: Template.** Replace the ghost `app-button` with `<button appListAction class="for-you-show" type="button" [attr.aria-label] [attr.title] (click)="recs.showRunPill()">`, `visibility` icon, `<span class="txt">` label.
- [ ] **Step 4:** Remove the now-unused `ButtonComponent` import.
- [ ] **Step 5: Run** the spec file, then `npm run check`, `npm run build`, `npx tsc -p tsconfig.spec.json --noEmit`.
- [ ] **Step 6: Visual check** of the header row with Show progress + Stop, wide and compact, against the built stylesheet.
- [ ] **Step 7: Commit** `fix(#1372): for-you show progress is a list action`.
