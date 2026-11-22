# Covered page layer is not painted (#2459) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A page layer that a layer above fully covers is not painted, so a scroll that outruns the paint of the layer above can no longer flash the list through.

**Architecture:** Since #2038 a covered layer is pinned (`.page-pinned`, `position: fixed`) beneath the layer that owns the page. The shell's stylesheet hides a pinned layer with a zero-length `visibility` animation delayed by the slide-in time, so it stays painted while the layer above slides in. A layer whose touch gesture moves it (back-swipe, pull, the leave slide) carries `.page-moving`; while one does, the pinned layers beside it in `.main` are painted again. The leaver (`.article-leave`) is never hidden.

**Tech Stack:** Angular 20 (signals, `effect`), SCSS, Jest, Playwright.

**Spec:** issue #2459.

## Global Constraints

- No hex colours, no ad-hoc `px` in `.scss` outside the theme (Stylelint).
- Comments: one line, only where a reader would otherwise get it wrong.
- The slide time is `0.22s` (`LEAVE_ANIM_MS = 220`); the hide delay must equal it.

## Review Focus

- A back-swipe on a full-screen article (touch): the list must be visible from the first pixel the frame moves — `.page-moving` is set as soon as `dragX` leaves 0.
- A pull past the article's end reveals the area under the lifted article: painted while `pull > 0`.
- Article opened from a story over the list: both the story and the list beneath are hidden; a swipe on the article reveals the story.
- The leaving layer (pinned by `PageScrollOwner.leave`, carrying `.article-leave`) keeps playing its leave animation visibly.
- `prefers-reduced-motion`: no enter slide, the layer hides after the same delay — harmless.

---

### Task 1: The layer above marks itself moving

**Files:**
- Modify: `frontend/src/app/shared/scroll-host/page-layer.ts`
- Modify: `frontend/src/app/reader/article/article-layer/article-gestures.service.ts`
- Test: `frontend/src/app/shared/scroll-host/page-layer.spec.ts`
- Test: `frontend/src/app/reader/article/article-layer/article-layer.component.spec.ts` (the "on the page (#2038)" describe)

**Interfaces:**
- Produces: `export const PAGE_MOVING_CLASS = 'page-moving'` in `page-layer.ts`; `PageLayer.startMoving(): void` and `PageLayer.stopMoving(): void` add/remove it on `element`.
- `ArticleGestures` injects `PageLayer` optionally and, in an `effect`, calls `startMoving()` while `swipeTransform() !== 'none' || pullTransform() !== 'none'`, else `stopMoving()`.

- [ ] **Step 1: Failing tests**
  - `page-layer.spec.ts`, `it('marks itself moving until it settles (#2459)')`: `startMoving()` → `element.classList.contains(PAGE_MOVING_CLASS)` true; `stopMoving()` → false.
  - `article-layer.component.spec.ts` on-page describe, `it('marks its page layer moving while a swipe moves it (#2459)')`: full-screen; touchstart at (10,100), touchmove to (60,102) → the provided `PageLayer`'s element has `page-moving`; touchend (short swipe, snaps back) → it has not.
- [ ] **Step 2:** Run `docker compose exec -T frontend npx jest page-layer article-layer` — expect the new tests to fail.
- [ ] **Step 3:** Implement as in Interfaces.
- [ ] **Step 4:** Rerun — pass.
- [ ] **Step 5:** Commit `fix(#2459): a page layer a gesture moves marks itself moving`.

### Task 2: The shell stops painting a covered layer

**Files:**
- Modify: `frontend/src/app/reader/shell/reader-shell.component.scss` (after the split `.story-overlay` rules, so it wins over their `animation`)
- Test: `frontend/e2e/article-page-scroll.spec.ts`

- [ ] **Step 1: Failing Playwright test** `the list beneath an open article is not painted once the article has slid in (#2459)`: open an article; `expect.poll` of `getComputedStyle(section.list).visibility` is `'hidden'`; back → `'visible'`.
- [ ] **Step 2:** Run `cd frontend && npx playwright test article-page-scroll` — fails (`visible`).
- [ ] **Step 3:** Add to the shell stylesheet:
  - `.main > .page-pinned:not(.article-leave)` → `animation: page-covered 0s 0.22s forwards`
  - `.main:has(> .page-moving) > .page-pinned` → `animation: none`
  - `@keyframes page-covered { to { visibility: hidden; } }`
  - One comment line naming why (#2459).
- [ ] **Step 4:** Rerun — pass. Then the frontend gate: `docker compose exec -T frontend npm run check`.
- [ ] **Step 5:** Commit `fix(#2459): a page layer the one above covers is not painted`.

### Task 3: Live check

- [ ] In Brave at `localhost:4200`: open an article; the list's computed visibility is `hidden` after the slide; close it — the list slides back into view with no blank frame; with a story open, open an article from it, back out — story visible.
