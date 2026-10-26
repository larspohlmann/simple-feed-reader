# Chapter Toggle as Native Details Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The chapter list's hand-rolled toggle button becomes a native `<details>`/`<summary>` (#1817).

**Architecture:** `addChapterList` builds `nav.reader-chapters > details > summary.reader-chapters__toggle + ol.reader-chapters__list`. The browser owns open state, keyboard and ARIA; the chevron is a fixed `chevron_right` glyph rotated by `details[open]`, as `<app-disclosure>` does. The narration's bare `<details>` is the precedent (design-language.md §6).

**Tech Stack:** Angular 20, plain DOM, SCSS, Jest, Playwright.

**Spec:** issue #1817.

## Global Constraints

- The list looks unchanged: same padding, chevron size and position; collapsed by default.
- No hex colours, no ad-hoc px in `.scss`.
- Frontend Jest runs in Docker: `docker compose exec -T frontend npm test`.

---

### Task 1: Toggle becomes a summary

**Files:**
- Modify: `frontend/src/app/reader/article/decorators/reader-chapters.ts` (`addChapterList`, `toggle`)
- Modify: `frontend/src/app/reader/article/article-content/article-chapters.scss` (`.reader-chapters__toggle` rules)
- Test: `frontend/src/app/reader/article/decorators/reader-chapters.spec.ts`, `frontend/e2e/chapters.spec.ts`

- [ ] **Step 1: Rewrite the spec** "starts collapsed and opens from its toggle": the toggle is `details > summary.reader-chapters__toggle`, the details starts without `open`, clicking the summary sets `open`, clicking again clears it. The list carries no `hidden`.
- [ ] **Step 2: Run it, expect FAIL.**
- [ ] **Step 3: Implement.** `toggle(heading)` returns a `summary` with a `chevron_right` icon and the label; `addChapterList` wraps summary and `ol` in a `details` inside the `nav`. Delete the click handler, `aria-expanded` and `hidden` handling.
- [ ] **Step 4: Styles.** On `.reader-chapters__toggle`: `list-style: none`; hide `::-webkit-details-marker`; drop the button resets (`background`, `border`, `font: inherit`). Add `.reader-chapters details[open] > .reader-chapters__toggle .material-symbols-outlined { transform: rotate(90deg) }`.
- [ ] **Step 5: e2e.** A `summary` is not a `button` role, so open the list via `.reader-chapters__toggle`.
- [ ] **Step 6: Verify:** Jest in Docker, `npm run check`, `ng build`, live check on :4200 (open/close, keyboard, chevron, a row tap still plays).
- [ ] **Step 7: Commit** `fix(#1817): the chapter list opens as a native details`.
