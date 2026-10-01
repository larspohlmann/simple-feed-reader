# Article-view actions ignore a tap during scroll momentum — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline). Steps use checkbox (`- [ ]`) syntax.

**Goal:** On iPhone, a tap on an article-view action (the favourite star, keep, the toolbar buttons) registers while the article still coasts from a scroll, as it already does in the list (#1332).

**Architecture:** The buttons and their handler are shared with the list; the scroller around them is not. Bring the article's scroller in line with the list's on the two points #501 settled there:

1. `ReaderViewComponent` listens to `scroll` with `@HostListener`, inside the Angular zone, so every momentum frame ends in a tree-wide tick. Listen outside the zone, as `ScrollOutsideZoneDirective` does for the list. The handler only writes signals, which schedule their own refresh.
2. `ArticleGestures.readerTransform` is `translate3d(0px, 0px, 0)` at rest, so the whole article is a GPU layer, and every touchend, a plain tap included, swaps `.reader`'s `transition`. Return `none` at rest, like the list's `revealTransform`, and only settle (`snapping`) when a drag or pull actually moved the article. `.reader` gets `isolation: isolate` so its stacking context, which the transform used to create, survives: its `.bar` (z 2) and `.mini` (z 3) must not start competing with the split pane's divider (z 1).

The device is the only real proof: the simulator does not reproduce WebKit's momentum or compositing behaviour (memory: ios-simulator-webkit-debugging). Unit tests pin the two mechanisms.

**Tech Stack:** Angular 20 (zone change detection with signals), Jest in the frontend container.

## Global Constraints

- Branch `fix/1332-article-tap-during-momentum` off `develop`. Commit format `type(#1332): summary`. First commit carries this plan.
- Gate: `docker compose exec -T frontend npm run check`.
- No deploy without Lars asking.

---

### Task 1: The article's scroll listener runs outside the zone

**Files:**
- Create: `frontend/src/app/reader/scroll/scroll-outside-zone.ts` — `listenToScrollOutsideZone(element, listener)`, the directive's constructor body, run in an injection context (NgZone, DestroyRef).
- Modify: `frontend/src/app/reader/scroll/scroll-outside-zone.directive.ts` — delegates to it.
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts` — drop `@HostListener('scroll')`; register `onScroll` through the helper in the constructor.
- Test: `reader-view.component.spec.ts`, `describe('back-to-top button'`.

- [ ] **Step 1: Failing test.** Spy on the component's `ReadingScope.trackScroll`, recording `NgZone.isInAngularZone()`; dispatch `scroll` on the host from inside `NgZone.run`; expect `false`.
- [ ] **Step 2:** Run it, see it fail (the HostListener runs in the zone).
- [ ] **Step 3:** Implement as above.
- [ ] **Step 4:** Run the reader-view and directive specs; green.

### Task 2: No transform and no transition swap at rest

**Files:**
- Modify: `frontend/src/app/reader/article/reader-view/article-gestures.service.ts` — `readerTransform` is `none` when `dragX` and `pull` are 0; `onTouchEnd` returns before `snapping` when neither moved.
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.scss` — `isolation: isolate` on `.reader`.
- Test: `reader-view.component.spec.ts`, `describe('return-to-list gestures (full-screen)'`.

- [ ] **Step 1: Failing tests.** (a) A fresh full-screen article's `.reader` has `transform: none`. (b) After a tap (touchstart, touchend, no move) `.reader`'s `transition` is still `none`. (c) A short swipe snaps back to `transform: none`.
- [ ] **Step 2:** Run, see (a) and (b) fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Whole gate green.

### Task 3: Verify on the iPhone

- [ ] Lars taps the star while an article coasts, on a dev deploy. If it still fails, the next suspect is the toolbar's place inside the scroller (the list header sits outside its scroller) — a new hypothesis, not a fourth patch.
