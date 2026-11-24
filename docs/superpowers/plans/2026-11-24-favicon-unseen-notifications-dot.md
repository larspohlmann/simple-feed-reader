# Favicon Dot for Unseen Social Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** While any social network has an unseen notification, the reader's favicon carries a red dot. A background tab keeps that dot current.

**Architecture:** Frontend only. The reader's `SidebarCountsPoll` stopped polling while the tab was hidden. It now keeps a slow tick there that fetches counts directly every 5 minutes, the backend's own network-poll period. A new reader-provided `FaviconUnseenDot` swaps the `<link rel="icon">` hrefs to a static badged icon set while `SubscriptionsStore.unseenNotificationsTotal()` is above zero. The backend and the change marker stay as they are: the marker is global because feed content is shared, while notifications belong to one user. A visible tab already gets fresh notification counts from the poll's existing 5-minute floor tick.

**Tech Stack:** Angular 20 signals + Jest, `rsvg-convert` for the PNG.

**Spec:** GitHub issue #2515 (`gh issue view 2515`). Every decision in it is settled; this plan does not reopen them.

## Global Constraints

- Every rule in `CLAUDE.md` applies, frontend conventions included. Component styles go in a sibling `.scss`, and there are no hex colours in `.scss`. Neither applies here, since there is no new SCSS.
- Commit format: `type(#2515): lower-case summary`, no attribution lines.
- Foreground commands only; no background shells, no `sleep`/`until` loops.
- Jest runs only inside Docker: `docker compose exec -T frontend npx jest <path>` from the repo root. Never two Jest runs at once.
- Dot colours: danger red `#b3403a` (light) / `#d98a86` (dark), outline in surface-0 `#f5f5f4` (light) / `#161616` (dark). These are the graphite theme's values; the favicon is not themeable.
- The favicon set is static files. No canvas drawing and no fallback for browsers that ignore a runtime favicon change.
- The dot is reader-only. Leaving the reader restores the plain icons.
- No backend change, and the change marker gets no new writer.

## Review Focus

1. **Leaving the reader with an unseen notification** (opening Settings): the plain favicon must come back. Task 2's destroy test pins it.
2. **Logout**: `SubscriptionsStore` resets its counts to empty, so the dot must clear. Task 2's drop-to-zero test covers the mechanism.
3. **A tab left hidden for longer than the 2-hour idle limit** must keep fetching counts. Task 1's idle-limit test pins it.
4. **A page the idle limit already wrote off, then hidden**: the slow hidden tick must start anyway. Task 1 pins it.
5. **A hidden tick must never read the marker**: it says nothing about notifications, so gating on it would hide a new one. Task 1 pins it.

---

### Task 1: The counts poll keeps a slow counts fetch while the tab is hidden

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar-counts-poll.service.ts`
- Test: `frontend/src/app/reader/shell/sidebar-counts-poll.service.spec.ts`

**Interfaces:**
- Produces: `export const SIDEBAR_HIDDEN_POLL_INTERVAL_MS = 5 * 60 * 1000;` from `sidebar-counts-poll.service.ts`.
- Behaviour:
  - **Cadence:** while `isTabHidden()`, the poll ticks every `SIDEBAR_HIDDEN_POLL_INTERVAL_MS` instead of `SIDEBAR_RELOAD_INTERVAL_MS`.
  - **What a hidden tick does:** it runs `refreshCounts()` unconditionally, with no marker read. The marker tracks shared feed content, not one user's notifications.
  - **Idle limit:** it never stops a hidden tab. `givenUp()` becomes "visible and idle". Hiding the tab starts the hidden cadence even on a page the idle limit had written off.
  - **Showing the tab again:** today's `regainTab()` stays (full reload, back to 30 s).
  - **Cadence check:** the tick also checks its cadence against `isTabHidden()`, because `visibilitychange` doesn't fire on every path; the existing comment in `tick()` says so.
- Rewrite the class docblock's "a hidden tab doesn't poll" rule so it describes the slow hidden counts fetch and why it exists (the favicon dot, #2515).

- [ ] **Step 1: Write the failing specs.** Replace `'sends nothing at all while the tab is hidden'` with these:
  - `'keeps a slow counts fetch while the tab is hidden'`: `start('a')`, hide, `elapse(SIDEBAR_RELOAD_INTERVAL_MS * 9)` → `refreshes() === 0`. Then `elapse(SIDEBAR_HIDDEN_POLL_INTERVAL_MS - SIDEBAR_RELOAD_INTERVAL_MS * 9)` → `reloadCounts` called once and `reloadFull` never.
  - `'a hidden tick fetches counts without reading the marker'`: `start('a')`, record `fetchMock.mock.calls.length`, hide, `elapse(SIDEBAR_HIDDEN_POLL_INTERVAL_MS * 2)` → `reloadCounts` called twice, and `fetchMock` has no new call.
  - `'the idle limit never stops a hidden tab'`: `start('a')`, hide, `elapse(SIDEBAR_POLL_IDLE_LIMIT_MS)`, record `reloadCounts.mock.calls.length`, `elapse(SIDEBAR_HIDDEN_POLL_INTERVAL_MS)` → one more call than recorded.
  - `'hiding a page the idle limit wrote off starts the slow fetch'`: `start('a')`, `elapse(SIDEBAR_POLL_IDLE_LIMIT_MS)`, record `refreshes()`, hide, `elapse(SIDEBAR_HIDDEN_POLL_INTERVAL_MS)` → `refreshes()` is one more than recorded.

  Shorten the hidden stretch in `'does a full reload the moment a hidden tab is shown again'` from `SIDEBAR_RELOAD_INTERVAL_MS * 10` to `SIDEBAR_RELOAD_INTERVAL_MS * 5`. That keeps it under one hidden interval, so its `refreshes() === 1` still asserts the regain alone.
- [ ] **Step 2: Run them and watch them fail.** `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar-counts-poll.service.spec.ts` → the four new specs fail; the rest pass.
- [ ] **Step 3: Implement the hidden cadence** in `SidebarCountsPoll`.
- [ ] **Step 4: Run the spec.** Same command → all pass.
- [ ] **Step 5: Commit.** `feat(#2515): a hidden reader tab keeps fetching counts every five minutes`

### Task 2: The badged favicon and the service that swaps it in

**Files:**
- Create: `frontend/public/favicon-unseen.svg` (exact content below)
- Create: `frontend/public/favicon-unseen-32.png`, generated from the SVG: `rsvg-convert -w 32 -h 32 frontend/public/favicon-unseen.svg -o frontend/public/favicon-unseen-32.png`
- Create: `frontend/src/app/reader/shell/favicon-unseen-dot.service.ts`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts`: add `FaviconUnseenDot` to `providers` next to `ReaderTabTitle`, and inject it for its effect the same way (`private readonly faviconDot = inject(FaviconUnseenDot);`)
- Test: `frontend/src/app/reader/shell/favicon-unseen-dot.service.spec.ts`

`frontend/public/favicon-unseen.svg`: the current `favicon.svg` path unchanged, plus the dot in the icon's empty top-right corner:

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 -960 960 960">
  <style>
    path { fill: #3f8676; }
    circle { fill: #b3403a; stroke: #f5f5f4; }
    @media (prefers-color-scheme: dark) {
      path { fill: #5aa694; }
      circle { fill: #d98a86; stroke: #161616; }
    }
  </style>
  <path d="<copy the d attribute of frontend/public/favicon.svg verbatim>"/>
  <circle cx="740" cy="-740" r="180" stroke-width="40"/>
</svg>
```

**Interfaces:**
- Consumes: `SubscriptionsStore.unseenNotificationsTotal: Signal<number>`.
- Produces: `@Injectable() export class FaviconUnseenDot`, reader-provided and injected for its effect.
  - At construction it captures each `link[rel="icon"]` in `DOCUMENT.head` with its original `href` attribute.
  - An effect sets the badged hrefs while the total is above 0 and restores the originals otherwise. `DestroyRef.onDestroy` restores the originals.
  - Badged hrefs: the `type="image/svg+xml"` link gets `favicon-unseen.svg?v=1`; every other icon link (the `.ico` and the PNG) gets `favicon-unseen-32.png?v=1`.
  - Set them with `setAttribute('href', …)` so they stay relative, like the originals in `index.html`.
- No new translation keys and no SCSS.

- [ ] **Step 1: Write the failing spec.** In `beforeEach`, put the three `<link rel="icon">` elements from `frontend/src/index.html` into `document.head` (same `type`, `sizes` and `href` attributes), and remove them in `afterEach`. Provide `{ provide: SubscriptionsStore, useValue: { unseenNotificationsTotal: total } }`, where `total = signal(0)`. Flush effects with `TestBed.tick()`.
  - `'leaves the icons alone while nothing is unseen'` → hrefs are exactly `favicon.ico?v=7`, `favicon-32.png?v=7`, `favicon.svg?v=7`.
  - `'shows the badged icons while a notification is unseen'`: `total.set(2)` → hrefs `favicon-unseen-32.png?v=1`, `favicon-unseen-32.png?v=1`, `favicon-unseen.svg?v=1`, in that link order.
  - `'restores the plain icons once everything is seen'`: set 2, tick, set 0, tick → the original hrefs.
  - `'restores the plain icons when the reader goes away'`: set 2, tick, `TestBed.resetTestingModule()` → the original hrefs.
- [ ] **Step 2: Run it and watch it fail.** `docker compose exec -T frontend npx jest src/app/reader/shell/favicon-unseen-dot.service.spec.ts` → it fails because `favicon-unseen-dot.service` can't be resolved.
- [ ] **Step 3: Create the SVG and PNG, implement `FaviconUnseenDot`, and wire it into `ReaderShellComponent`.**
- [ ] **Step 4: Run the spec.** Same command → 4 pass. Open `frontend/public/favicon-unseen-32.png` with the Read tool: a green RSS mark with a red dot in the top-right corner, clearly apart from the arcs.
- [ ] **Step 5: Commit.** `feat(#2515): the reader's favicon carries a dot while a notification is unseen`

### Task 3: Whole-branch verification

- [ ] `docker compose exec -T frontend npm run check`, then `cd frontend && npx ng build` (`npm run check` misses template errors). Both green.
- [ ] Live check on http://localhost:4200 with a seeded account that has an unseen notification: the tab shows the dot; marking everything seen clears it; opening Settings restores the plain icon. Hide the tab and confirm in the network panel that a counts request still goes out after 5 minutes.
