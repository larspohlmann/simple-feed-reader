# Shared List Frame Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The entry list and the notification list draw their chrome through one `ListFrameComponent` instead of two copies of it.

**Architecture:** `app-list-frame` (in `reader/list/list-frame/`) renders the lead frame (the floating `<header class="list-header">`, a lead slot, and the error banner), a default slot for the rows, and the tail frame. It publishes `--list-bar-h` on its own host, which is `display: contents`, so every consumer (rows, pull indicator) is still a DOM descendant and layout stays the same. Each list projects its own header content, rows and corner cluster, and keeps the styles for what it projects.

**Tech Stack:** Angular 20 standalone components and signals, Transloco, SCSS, Jest in the Docker `frontend` container, Playwright.

**Spec:** issue #2290 (follow-up from #2240). Branch `refactor/2290-shared-list-frame`, which also carries the #2289 tab-title fix.

## Global Constraints

- No visual or behaviour change. The DOM class names `.list-frame.lead`, `.list-frame.tail`, `.list-header`, `.list-header.collapsed`, `.list-error` and `app-error-banner` stay, so existing specs and e2e selectors keep matching.
- Component styles live in a sibling `.scss`; tokens only; no hex and no px spacing.
- Jest runs only inside the Docker `frontend` container.
- Commits use `refactor(#2290): …`.

## Review Focus

1. **Collapsed header on narrow screens:** the bar height must stay frozen while collapsed (`isPublishing: () => !collapsed()`), or the rows jump (#87). Covered by a test in Task 1.
2. **Playlist strip:** with the list squeezed to a strip the corner cluster still hides. The container query name changes from `entry-list`/`notification-list` to the frame's `list-tail`. Covered in Tasks 2 and 3 by the existing container-query styles, renamed.
3. **Pull-to-refresh indicator:** it reads `--list-bar-h` from inside the lead frame and must still sit under the header. Checked live in Task 3.
4. **Entry-list reading focus and above-fold:** these measure the header through `listHdr`. That member must still return the header element, now taken from the frame. Covered by the existing entry-list spec.
5. **Error banner retry and dismiss** must reach each list's own handler. Covered by a test in Task 1 and by the existing list specs.

---

### Task 1: `ListFrameComponent`

**Files:**
- Create: `frontend/src/app/reader/list/list-frame/list-frame.component.{ts,html,scss,spec.ts}`

**Interfaces:**
- Produces: selector `app-list-frame`.
  - Inputs: `problem = input<Problem | null>(null)`, `collapsed = input(false)`.
  - Outputs: `retry = output<void>()`, `dismiss = output<void>()`.
  - Public signal: `header = viewChild.required<ElementRef<HTMLElement>>('header')`.
  - Slots: `[frameHeader]` (inside `<header class="list-header">`), `[frameLead]` (after the header, before the banner), default (between the two frames), `[frameTail]` (inside `.list-frame.tail`).
  - Styles: the frame box, the z-indexes (frames 1, lead 2), `pointer-events` (none on the frames, auto on `.list-header` and `.list-error`), `floating-header`, the narrow `.collapsed` padding, `error-overlay`, `.list-tail` as `container: list-tail / size`, and the reduced-motion `transition: none`. All of these move out of `entry-list.component.scss`.

- [ ] **Step 1: Write the failing spec** in `list-frame.component.spec.ts`. Mount a test host that projects `<h2 frameHeader>`, `<p class="row">` and `<span frameTail>`, then check:
  - `projects the header into the floating header`: `.list-frame.lead > header.list-header > h2` exists.
  - `projects rows between the frames and the tail into the tail frame`: `.list-frame.tail > span` exists, and `p.row` is not inside a `.list-frame`.
  - `shows the offline message for a status-0 problem`: `problem: { status: 0 }` makes `app-error-banner` contain the English `reader.errorOffline` text.
  - `shows the server message with the status`: `problem: { status: 503 }` makes the banner contain `503`.
  - `emits retry and dismiss from the banner`: clicking `.action` and `.dismiss` fires the outputs once each.
  - `marks the header collapsed`: `collapsed: true` gives `.list-header.collapsed`.
  - `publishes the header height only while expanded`: stub `offsetHeight` at 40 and trigger the resize. The host's `--list-bar-h` becomes `40px`. Then set `collapsed: true`, change the height to 20 and trigger again; the value stays `40px`. Use the resize-trigger helper the `list-bar-height` spec already uses.
- [ ] **Step 2:** Run `docker compose exec -T frontend npx jest src/app/reader/list/list-frame`. Expected: FAIL (module missing).
- [ ] **Step 3: Implement the component.**
  - The host is `display: contents`.
  - The bar height is published with `publishListBarHeight(zone, { host, header, isPublishing: () => !this.collapsed() })` in an effect with cleanup.
  - Copy the banner markup verbatim from `entry-list.component.html`.
- [ ] **Step 4:** Rerun the spec. Expected: PASS.
- [ ] **Step 5:** Commit: `refactor(#2290): add the shared list frame`.

### Task 2: Entry list adopts the frame

**Files:** Modify `reader/list/entry-list/entry-list.component.{ts,html,scss}`, and the spec only where it reaches into a removed member.

**Interfaces:** Consumes Task 1.

- [ ] **Step 1: Wrap the template.**
  - Use `<app-list-frame [problem]="error()" [collapsed]="collapsed()" (retry)="retry.emit()" (dismiss)="dismiss.emit()">`.
  - `<app-list-header>` takes the `frameHeader` attribute.
  - The `@if (revealVisible())` pull indicator's root `div` takes `frameLead`.
  - The tail content goes in `<ng-container ngProjectAs="[frameTail]">`, which keeps its `@let`s.
  - Drop the header's `[class.is-search]`: nothing styles `.list-header.is-search`, because `app-list-header` sets its own host class.
- [ ] **Step 2: Update the component class.**
  - Replace the `listHdr` viewChild with `private readonly frame = viewChild.required(ListFrameComponent)` and `private readonly listHdr = computed(() => this.frame().header())`. The name stays because the spec spies on it.
  - Delete `_publishBarHeight` and its comment block.
- [ ] **Step 3: Update the styles.**
  - Delete the moved frame, header, error and reduced-motion `.list-header` rules.
  - Rename the container queries to `list-tail`: `hide-corner-cluster-in-strip(list-tail)` and `@container list-tail (…)`.
  - Keep `.corner-actions { pointer-events: auto }`.
- [ ] **Step 4:** Run `docker compose exec -T frontend npx jest src/app/reader/list`. Expected: PASS. A spec that broke only because the DOM moved is fixed at its selector, never by loosening the assertion.
- [ ] **Step 5:** Commit: `refactor(#2290): draw the entry list in the shared frame`.

### Task 3: Notification list adopts the frame, then the gates

**Files:** Modify `reader/notifications/notification-list/notification-list.component.{ts,html,scss}`.

- [ ] **Step 1: Switch the template and class to the frame**, the same way as Task 2:
  - `[problem]="store.error()"`, `(retry)="store.reload()"`, `(dismiss)="store.dismissError()"`.
  - The corner cluster goes in `ngProjectAs="[frameTail]"`.
  - Delete `listHdr`, `_publishBarHeight`, the `host`/`zone` injections if they become unused, and the moved styles.
  - Rename the strip query to `list-tail`.
- [ ] **Step 2:** Run `docker compose exec -T frontend npm run check`. Expected: exit 0.
- [ ] **Step 3:** Run `cd frontend && npx playwright test e2e/social-notifications.spec.ts e2e/list-header.spec.ts e2e/sidebar.spec.ts e2e/sidebar-mobile.spec.ts`. Expected: all pass.
- [ ] **Step 4: Live check at :4200, before and after,** on desktop and phone (375×812):
  - The header sits under the app bar and collapses on scroll (phone).
  - The pull indicator sits under the header.
  - The offline banner position is unchanged.
  - The corner buttons are unchanged.
  - The notifications list is unchanged.
- [ ] **Step 5:** Commit: `refactor(#2290): draw the notification list in the shared frame`.
