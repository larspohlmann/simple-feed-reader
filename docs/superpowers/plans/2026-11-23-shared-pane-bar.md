# Shared Pane Bar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The list header and the article toolbar become one shared bar component, so the article view's bar is the same box as the list's: 56px from `bp-lg` up, `surface-0` at 80% over a blur.

**Architecture:** A new `app-pane-bar` in `frontend/src/app/shared/pane-bar/` owns the bar's box (flex row, padding, `--bar-h` minimum from `bp-lg`, bottom rule, frosted fill) and reports its own height. `list-frame` and `article-layer` each render one instance, keep their class names (`list-header`, `bar`) and keep owning *where* it sits and *how it moves* (floating under the app bar and riding `--app-bar-shift` for the list; under the nameplate and retracting for the full-screen article). The frosted fill, already copied three times, moves into one theme mixin first.

**Tech Stack:** Angular 20 standalone components, signals, SCSS, Jest (jsdom, in the Docker `frontend` container), Playwright.

**Spec:** GitHub issue #2509 (body = agreed design).

## Global Constraints

- One DOM instance per layer; never a single node shared across layers (swipe-back, split view and the story layer need their own).
- Phone behaviour unchanged: full-screen article keeps the 28px nameplate (`.mini`) and the toolbar that retracts (`.bar.hidden`); the list header keeps riding `--app-bar-shift` and going slim when `collapsed`.
- Fill for both bars: `var(--surface-0)` at `80%`, `backdrop-filter: blur(12px) saturate(1.4)`.
- Keep the host class names `list-header` and `bar`: e2e specs select on them (`.list-header`, `app-reader-view .bar .close`, `.bar-title`).
- No hex colours or ad-hoc px spacing outside `src/app/theme/`; component styles in a sibling `.scss`; Prettier 100 columns.
- Comments only for an invariant the code cannot express; one line.
- Every Jest-running command goes through `docker compose exec -T frontend …` from the repo root.

## Review Focus

1. Full-screen article on a phone: `.bar.hidden` must still slide up under the nameplate — the pane bar sets no `transform`/`transition` on its host.
2. Narrow list scrolled down: `.list-header.collapsed`'s `--space-1` padding must still beat the pane bar's own padding (selector specificity).
3. The frosted fill really blurs scrolled article text in the split pane and the wide overlay (no ancestor that is a backdrop root swallowing it) — live check, scrolled.
4. `--list-bar-h` is published only while the list header is expanded; a collapse never shrinks the rows' reservation (#87).
5. Article height reservation: `--reader-bar-h` and `barResized` still follow the bar (the article page re-measures its scope on it).

---

### Task 1: One frosted-fill mixin

**Files:**
- Create: `frontend/src/app/theme/_frost.scss` (already drafted on the branch)
- Modify: `frontend/src/app/theme/_list-chrome.scss` (`floating-header`'s two `background` lines + `backdrop-filter`)
- Modify: `frontend/src/app/settings/settings-shell.component.scss` (`.bar`'s fill, 82% of `--surface-1`)
- Modify: `frontend/src/app/theme/_corner-circle-button.scss` (35% of `var(--corner-button-tone, var(--text-muted))`)

**Interfaces:**
- Produces: `@mixin frosted($fill, $opacity)` in `theme/_frost.scss` — emits the solid `$fill`, then `color-mix(in srgb, $fill $opacity, transparent)`, then `backdrop-filter: blur(12px) saturate(1.4)`.

- [ ] **Step 1:** Replace each of the three copies with `@include frost.frosted(<fill>, <opacity>)`, same fill and opacity as today; drop the comments the mixin now carries.
- [ ] **Step 2:** `npm run build` (from `frontend/`) succeeds, and in the browser the computed `background-color`/`backdrop-filter` of `.list-header`, the settings bar and a corner disc are unchanged.
- [ ] **Step 3:** Commit `refactor(#2509): one frosted-fill mixin for the translucent chrome`.

### Task 2: `PaneBarComponent`

**Files:**
- Create: `frontend/src/app/shared/pane-bar/pane-bar.component.ts`, `.scss`, `.spec.ts` (template inline: `<ng-content />`)
- Modify: `docs/design-language.md` §2 (catalog entry: what it owns, what the host positions)

**Interfaces:**
- Produces: `selector: 'app-pane-bar'`, `OnPush`; `readonly resized = output<number>()` — the host's `offsetHeight` on every resize while it is above 0, observed with `observeResize` from `shared/observe-resize.ts`.
- `:host` styles: `display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--border);` `frost.frosted(var(--surface-0), 80%)`; at `width >= bp.$bp-lg`: `min-block-size: var(--bar-h); padding-block: 0`. No position, transform, transition or z-index.

- [ ] **Step 1: Failing spec** `pane-bar.component.spec.ts`:
  - `it('projects its content')` — host template `<app-pane-bar><b class="probe"></b></app-pane-bar>`; `expect(element.querySelector('app-pane-bar > .probe')).not.toBeNull()`.
  - `it('reports its height on resize')` — stub `offsetHeight` to 56, `fireResizeOf(bar)` (`src/testing/resize-observer-testing.ts`); `expect(heights).toEqual([56])`.
  - `it('reports nothing while it has no height')` — `offsetHeight` 0, fire; `expect(heights).toEqual([])`.
- [ ] **Step 2:** `docker compose exec -T frontend npx jest src/app/shared/pane-bar` → FAIL (module not found).
- [ ] **Step 3:** Implement the component and styles above; add the catalog entry.
- [ ] **Step 4:** Same command → PASS.
- [ ] **Step 5:** Commit `feat(#2509): a shared pane bar for the list header and the article toolbar`.

### Task 3: The list header is a pane bar

**Files:**
- Modify: `frontend/src/app/reader/list/list-frame/list-frame.component.{html,ts,scss,spec.ts}`
- Modify: `frontend/src/app/theme/_list-chrome.scss` (`floating-header` keeps only position, `top`, insets, `z-index`, transform + transition, `flex-wrap: wrap`, `container: list-header / inline-size`)
- Delete: `frontend/src/app/reader/list/list-bar-height.ts`

**Interfaces:**
- Consumes: `app-pane-bar` and its `resized` output (Task 2).
- `list-frame.component.html`: `<app-pane-bar class="list-header" [class.collapsed]="collapsed()" (resized)="publishBarHeight($event)">`.
- `ListFrameComponent.publishBarHeight(height: number): void` — sets `--list-bar-h` to `${height}px` on the host unless `collapsed()`. The `header` viewChild and `_publishBarHeight` effect go.

- [ ] **Step 1:** Update `list-frame.component.spec.ts`: the projection test selects `.list-frame.lead > app-pane-bar.list-header > h2`; the existing `it('publishes the header height only while expanded')` keeps its assertions (`'40px'` stays after collapsing) and fires the resize on `app-pane-bar.list-header`. Run `docker compose exec -T frontend npx jest src/app/reader/list/list-frame` → FAIL.
- [ ] **Step 2:** Implement; delete `list-bar-height.ts`.
- [ ] **Step 3:** Same command → PASS; also `npx jest src/app/reader/list src/app/reader/notifications src/app/reader/shell/story-layer` → PASS.
- [ ] **Step 4:** Commit `refactor(#2509): the list header is a pane bar`.

### Task 4: The article toolbar is a pane bar

**Files:**
- Modify: `frontend/src/app/reader/article/article-layer/article-layer.component.{html,ts,scss,spec.ts}`
- Modify: `frontend/e2e/article-back-desktop.spec.ts`

**Interfaces:**
- Consumes: `app-pane-bar` and `resized` (Task 2).
- `article-layer.component.html`: `<app-pane-bar #bar class="bar" [class.hidden]="toolbarHidden()" (resized)="reserveToolbarHeight($event)">`, back button and `<ng-content select="[articleBar]" />` inside unchanged.
- `ArticleLayerComponent.reserveToolbarHeight(height: number): void` — sets `--reader-bar-h` on the host and emits `barResized`. The `bar` viewChild and the effect-based method go.
- `.bar` in the layer's scss keeps only `position`, `top`, insets, `z-index`, `transition` and the `.hidden` / `.with-bar` / reduced-motion rules; its `display`, `align-items`, `justify-content`, `padding`, `border-bottom`, `background` lines go.

- [ ] **Step 1:** Spec: `it('reserves the toolbar height')` — fire a resize on `app-pane-bar.bar` with `offsetHeight` 56; `expect(host.style.getPropertyValue('--reader-bar-h')).toBe('56px')` and one `barResized` emission. Existing `.bar` / `.bar .close` / `hidden` tests stay green unchanged. Run `docker compose exec -T frontend npx jest src/app/reader/article` → FAIL.
- [ ] **Step 2:** e2e in `article-back-desktop.spec.ts`, `test('the article toolbar is the list header\'s box')` in the magazine layout at `DESKTOP`: open `Entry number 1`, `settle`; `bottomBorderEdge('app-reader-view .bar')` within 0.5px of `bottomBorderEdge('.search-card')`; computed `backgroundColor` and `backdropFilter` of `app-reader-view .bar` equal those of `.list-header` (read before opening the article). Copy `bottomBorderEdge` into `e2e/support/` and import it from both specs rather than duplicating.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Jest command → PASS; `npm run e2e -- article-back-desktop article-mini-header header-scroll-mobile link-card-reader sidebar-airy-style list-header` → PASS.
- [ ] **Step 5:** Commit `fix(#2509): the article toolbar is a pane bar, aligned and frosted like the list header`.

### Finally

- [ ] `docker compose exec -T frontend npm run check` and `npm run build` → green.
- [ ] Live check on `localhost:4200`: desktop list vs article (rules aligned, blur over scrolled text), split pane, link reader; phone width: list header slims, article bar retracts under the nameplate. Screenshots to Lars before the PR.
