# Rail without the native scroll indicator (#1336) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a phone, the article's reading-progress rail no longer has iOS's scroll indicator drawn over it while the article scrolls.

**Architecture:** Below the wide breakpoint the rail stands in for the scrollbar (#238), so the article's `.scroller` hides its native one there. It uses the idiom the header's tag row already uses: `scrollbar-width: none` plus `::-webkit-scrollbar { display: none }`. The wide layout keeps its scrollbar, because its bottom hairline doesn't replace it.

**Tech Stack:** SCSS + Stylelint.

**Spec:** GitHub issue #1336.

## Global Constraints

- Branch `fix/1336-rail-native-scroll-indicator` off `develop`; PR body `Closes #1336`.
- Use the breakpoint from `theme/breakpoints` (`bp.$bp-lg`, the 900px wide switch that `LayoutService.isWide` uses). No media-query literal.
- Gate: `docker compose exec -T frontend npm run check`.

---

### Task 1: The phone's scroller hides its native scrollbar

**Files:** Modify `frontend/src/app/reader/article/reader-view/reader-view.component.scss`, after `.scroller`.

- [ ] **Step 1:** Add:

```scss
/* The rail is the phone's scrollbar (#238); iOS would draw its own over it (#1336). */
@media (width < bp.$bp-lg) {
  .scroller {
    scrollbar-width: none;
  }

  .scroller::-webkit-scrollbar {
    display: none;
  }
}
```

- [ ] **Step 2:** Run `docker compose exec -T frontend npm run check` and expect green. jsdom has no scrollbars, so there is no unit test to write. Confirm in the Mobile viewport that the rule applies (`getComputedStyle(scroller).scrollbarWidth === 'none'`) and that the desktop keeps `auto`.
- [ ] **Step 3:** Commit `fix(#1336): the phone's article scroller leaves the scrollbar to the rail`, push, and open the PR.
