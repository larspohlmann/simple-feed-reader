# Save-search Wait Overlay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clicking **Save search** on a direct search shows the list's loading overlay from the click until the POST answers, so a slow save (the server sweeps every entry for matches first) no longer looks ignored.

**Architecture:** `SavedSearchesStore` learns a `saving` signal, true while its create request is out. `EntryListComponent` gets a `busy` input that raises the same overlay a reload uses, bypassing the search-selection gate (that gate exists for per-keystroke reloads, which the search field already spins for). The shell binds the one to the other, and the toggle ignores a second click while a save is out.

**Tech Stack:** Angular 20 (standalone + signals), Jest (jsdom).

**Issue:** #1718

## Global Constraints

- The overlay keeps its 150 ms arming delay, so a quick save never flashes a veil.
- The rows stay neither dimmed nor inert during a save: they are current, unlike the stale rows a reload retains (#254).
- The overlay shows for a save with zero results too, so it no longer sits inside the `entries().length > 0` branch; the reload arming keeps that condition itself.
- Branch `fix/1718-save-search-spinner` off `develop`. Commit format `type(#1718): …`.
- `npm run check` is the gate. The worktree path carries a `+`, so run Jest through `./node_modules/.bin/jest`, never `npx jest`.

---

### Task 1: `SavedSearchesStore.saving`

**Files:**
- Modify: `frontend/src/app/reader/state/saved-searches.store.ts`
- Test: `frontend/src/app/reader/state/saved-searches.store.spec.ts`

- [ ] **Step 1: Tests.** `saving()` is false at rest, true while the create request is pending (drive `createSavedSearch` with a `Subject`), false again after it answers, and false again after it errors.
- [ ] **Step 2: Implement.** `readonly saving = signal(false)`; `createSavedSearch` sets it true before subscribing and clears it through `finalize`.

### Task 2: `EntryListComponent.busy`

**Files:**
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.ts`, `.html`
- Test: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts`

- [ ] **Step 1: Tests.** With `busy: true` the overlay is up after the delay (a) on a search selection and (b) with no entries at all; with `busy: false` it stays down. A busy list leaves the rows undimmed and clickable.
- [ ] **Step 2: Implement.** `readonly busy = input(false)`. Rename `reloadSpinner` to `waitOverlay`; its effect arms on `busy() || (reloadCue() && entries().length > 0)`. Move the `<app-loading-overlay>` out of the `@if (entries().length > 0)` block.

### Task 3: Shell wiring

**Files:**
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html`, `frontend/src/app/reader/shell/saved-search-toggle.service.ts`
- Test: `frontend/src/app/reader/shell/reader-shell.component.spec.ts`

- [ ] **Step 1: Test.** Boot on `?q=angular`, click `.save-search`, leave the POST pending: after the delay the overlay is shown; a second click sends no second POST; flushing the POST drops the overlay.
- [ ] **Step 2: Implement.** `[busy]="savedSearchesStore.saving()"` on `<app-entry-list>`; `toggle()` returns early while `saving()`.

### Task 4: Gate and ship

- [ ] `npm run check` green in the worktree.
- [ ] Commit, push, open the PR against `develop` with `Closes #1718`.
- [ ] Visual check happens after the branch reaches the main checkout (a worktree cannot run the Docker stack): save a slow search and watch the overlay come up over the result list and go away with the toast.
