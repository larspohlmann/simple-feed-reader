# #501 List Scroll Off the Angular Zone — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop every scroll event on the entry list from ending in a tree-wide change-detection tick, so a scroll frame on a long list fits the frame budget and iOS WebKit no longer shows unpainted tiles as a blink to the background colour (#501).

**Architecture:** A small `ScrollOutsideZoneDirective` attaches a passive `scroll` listener outside `NgZone` and forwards the event to a handler input; both `#rows` scrollers in `EntryListComponent` use it in place of the template `(scroll)` binding. The scroll path calls `scheduleFocus()` directly instead of bumping the `focusPulse` signal, and `scheduleFocus()` requests its animation frame outside the zone, so neither the scroll event nor the reading-focus frame ticks. Signals the handler writes (`collapsed`, `showToTop`) still render through Angular's hybrid scheduler when their value changes.

**Tech Stack:** Angular 20 standalone + signals, zone.js with `provideZoneChangeDetection({ eventCoalescing: true })`, Jest/jsdom via `jest-preset-angular` (zone test env), Playwright for the real-browser check.

**Spec:** `docs/superpowers/plans/2026-09-05-501-list-scroll-tick-spec.md` — read it first; it holds the measurements and the reasoning, and the appendix script is the before/after proof.

## Global Constraints

- **Frontend conventions (CLAUDE.md):** standalone components + signals, no NgModules. No new `.scss` is needed; if any is added it lives in a sibling file, never inline.
- **Comments:** one line, three at the absolute most; write the *why*, never a restatement of the code. Delete on sight any comment the change makes stale (there are three, named in Task 3).
- **ESLint:** directive selectors are `attribute`, prefix `app`, `camelCase` (`eslint.config.js:20`). Prettier is 100 columns.
- **Run frontend tests inside Docker:** `docker compose exec -T frontend npx jest <path>` for one spec, `docker compose exec -T frontend npm run check` for the gate. Native `npx jest` skips the type check (`native-jest-skips-typecheck`).
- **Branch:** `fix/501-list-scroll-outside-zone` off `develop`. Commit messages `type(#501): summary`, no attribution lines. Check `git status` before any checkout — a concurrent session may share the checkout.
- **Scope:** no `OnPush` migration, no `reader-view` change, no `applyFocus()` trimming, no CSS change. Each is a deferred item in the spec.

---

### Task 1: `ScrollOutsideZoneDirective`

**Files:**
- Create: `frontend/src/app/reader/scroll-outside-zone.directive.ts`
- Test: `frontend/src/app/reader/scroll-outside-zone.directive.spec.ts`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `ScrollOutsideZoneDirective`, selector `[appScrollOutsideZone]`, one required input `handler: (event: Event) => void` aliased to the selector, so a template writes `[appScrollOutsideZone]="onRowsScroll"`. Task 2 relies on the alias and the input type.

- [ ] **Step 1: Create the branch**

```bash
git status --short
git checkout develop && git pull --ff-only
git checkout -b fix/501-list-scroll-outside-zone
```

- [ ] **Step 2: Write the failing spec** at `frontend/src/app/reader/scroll-outside-zone.directive.spec.ts`:

```ts
import { Component, NgZone } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ScrollOutsideZoneDirective } from './scroll-outside-zone.directive';

@Component({
  imports: [ScrollOutsideZoneDirective],
  template: `<div class="scroller" [appScrollOutsideZone]="onScroll"></div>`,
})
class HostComponent {
  readonly seen: { event: Event; inAngularZone: boolean }[] = [];
  readonly onScroll = (event: Event): void => {
    this.seen.push({ event, inAngularZone: NgZone.isInAngularZone() });
  };
}

function mount() {
  TestBed.configureTestingModule({ imports: [HostComponent] });
  const fixture = TestBed.createComponent(HostComponent);
  fixture.detectChanges();
  const scroller = (fixture.nativeElement as HTMLElement).querySelector('.scroller') as HTMLElement;
  return { fixture, scroller, host: fixture.componentInstance };
}

describe('ScrollOutsideZoneDirective', () => {
  it('hands every scroll event to the handler, outside the Angular zone', () => {
    const { scroller, host } = mount();
    const event = new Event('scroll');

    scroller.dispatchEvent(event);

    expect(host.seen).toHaveLength(1);
    expect(host.seen[0].event).toBe(event);
    // A template `(scroll)` listener would report true here: that tick per
    // scroll event is the whole reason the directive exists (#501).
    expect(host.seen[0].inAngularZone).toBe(false);
  });

  it('registers a passive listener, so it can never hold up the scroll', () => {
    const addEventListener = jest.spyOn(HTMLElement.prototype, 'addEventListener');
    try {
      mount();
      expect(addEventListener).toHaveBeenCalledWith('scroll', expect.any(Function), {
        passive: true,
      });
    } finally {
      addEventListener.mockRestore();
    }
  });

  it('stops listening once the host is destroyed', () => {
    const { fixture, scroller, host } = mount();
    fixture.destroy();

    scroller.dispatchEvent(new Event('scroll'));

    expect(host.seen).toHaveLength(0);
  });
});
```

- [ ] **Step 3: Run it to verify it fails**

```bash
docker compose exec -T frontend npx jest src/app/reader/scroll-outside-zone.directive.spec.ts
```

Expected: FAIL — `Cannot find module './scroll-outside-zone.directive'`.

- [ ] **Step 4: Write the directive** at `frontend/src/app/reader/scroll-outside-zone.directive.ts`:

```ts
import { DestroyRef, Directive, ElementRef, NgZone, inject, input } from '@angular/core';

/**
 * A `scroll` listener outside the Angular zone. A template `(scroll)` ends every
 * scroll event in a tree-wide change-detection tick; on a long list that tick
 * misses the frame and iOS WebKit shows unpainted tiles as a blink (#501).
 */
@Directive({
  selector: '[appScrollOutsideZone]',
})
export class ScrollOutsideZoneDirective {
  readonly handler = input.required<(event: Event) => void>({ alias: 'appScrollOutsideZone' });

  constructor() {
    const host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    const listener = (event: Event): void => this.handler()(event);
    inject(NgZone).runOutsideAngular(() =>
      host.addEventListener('scroll', listener, { passive: true }),
    );
    inject(DestroyRef).onDestroy(() => host.removeEventListener('scroll', listener));
  }
}
```

- [ ] **Step 5: Run the spec to verify it passes**

```bash
docker compose exec -T frontend npx jest src/app/reader/scroll-outside-zone.directive.spec.ts
```

Expected: PASS, 3 tests.

- [ ] **Step 6: Lint the two files**

```bash
cd frontend && npx eslint src/app/reader/scroll-outside-zone.directive.ts src/app/reader/scroll-outside-zone.directive.spec.ts && npx prettier --check src/app/reader/scroll-outside-zone.directive.ts src/app/reader/scroll-outside-zone.directive.spec.ts
```

Expected: no findings. If Prettier reports the long `input.required<...>` line, run `npx prettier --write` on the file and re-check.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/scroll-outside-zone.directive.ts frontend/src/app/reader/scroll-outside-zone.directive.spec.ts
git commit -m "feat(#501): add a scroll listener directive that stays outside the Angular zone"
```

---

### Task 2: Wire both list scrollers through the directive

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html:247` and `:381` (the two `(scroll)="onRowsScroll($event)"` bindings on the `#rows` elements)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts` — the `imports` array (`:118-140`), `onRowsScroll` (`:515-527`)
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `ScrollOutsideZoneDirective` from Task 1, bound as `[appScrollOutsideZone]="onRowsScroll"`.
- Produces: `onRowsScroll` is now a `readonly` arrow property of type `(e: Event) => void`. The existing spec calls `f.componentInstance.onRowsScroll({ target: { scrollTop: 480 } } as unknown as Event)` in nine places (`:1144,1246,1261,1281,1285,1306,1322,1348,1360,1364`); those keep working unchanged. Task 3 changes the body of this same property.

- [ ] **Step 1: Write the failing wiring test.** In `entry-list.component.spec.ts`, add `NgZone` to the `@angular/core` import on line 2:

```ts
import { Component, NgZone, TemplateRef, ViewChild, signal } from '@angular/core';
```

Then add a new `describe` block directly after the `it('remembers the scroll offset per selection as the list is scrolled', ...)` test (around line 1148):

```ts
  // #501: a template `(scroll)` binding ran every scroll event inside the zone,
  // and with it a change-detection tick over every loaded block. Drive the real
  // element, not the handler — the wiring is the fix.
  describe('scroll events from the rows element', () => {
    function dispatchScroll(f: ReturnType<typeof mount>, scrollTop: number): void {
      const rows = (f.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      Object.defineProperty(rows, 'scrollTop', { value: scrollTop, configurable: true });
      rows.dispatchEvent(new Event('scroll'));
    }

    it('reach the handler outside the Angular zone', () => {
      const f = mount();
      const zones: boolean[] = [];
      memory.save.mockImplementationOnce(() => zones.push(NgZone.isInAngularZone()));

      dispatchScroll(f, 480);

      expect(memory.save).toHaveBeenCalledWith(expect.objectContaining({ kind: 'all' }), 480);
      expect(zones).toEqual([false]);
    });

    it('still drive the back-to-top state the template reads', () => {
      const f = mount();

      dispatchScroll(f, 900);

      expect(f.componentInstance.showToTop()).toBe(true);
    });
  });
```

- [ ] **Step 2: Run it to verify the zone test fails**

```bash
docker compose exec -T frontend npx jest src/app/reader/entry-list/entry-list.component.spec.ts -t 'scroll events from the rows element'
```

Expected: `reach the handler outside the Angular zone` FAILS with `Expected: [false] Received: [true]` (the template listener runs in the zone). `still drive the back-to-top state` PASSES already — it is the guard that the rewiring keeps the signal path alive.

- [ ] **Step 3: Replace both template bindings.** In `entry-list.component.html`, at line 247 and line 381, change

```html
    (scroll)="onRowsScroll($event)"
```

to

```html
    [appScrollOutsideZone]="onRowsScroll"
```

Both `#rows` elements keep every other attribute (`[class.reloading]`, `[attr.inert]`, `[style.transform]`, `#rows`, `(animationend)`) exactly as they are.

- [ ] **Step 4: Import the directive and turn the handler into an arrow property.** In `entry-list.component.ts`:

Add the import next to the other reader imports (after line 43, `import { planMagazine } from '../magazine/magazine-planner';`):

```ts
import { ScrollOutsideZoneDirective } from '../scroll-outside-zone.directive';
```

Add `ScrollOutsideZoneDirective,` to the `imports` array after `ToTopButtonComponent,` (line 139).

Change the method signature at line 515 from

```ts
  onRowsScroll(e: Event): void {
```

to

```ts
  readonly onRowsScroll = (e: Event): void => {
```

and its closing brace at line 527 from `  }` to `  };`. The body stays as it is in this task (Task 3 changes one line of it).

- [ ] **Step 5: Run the whole entry-list spec**

```bash
docker compose exec -T frontend npx jest src/app/reader/entry-list/entry-list.component.spec.ts
```

Expected: PASS, including the two new tests and every existing `onRowsScroll(...)` caller. If `errorOnUnknownProperties` reports `appScrollOutsideZone`, the directive is missing from the `imports` array.

- [ ] **Step 6: Lint**

```bash
cd frontend && npx eslint src/app/reader/entry-list/entry-list.component.ts src/app/reader/entry-list/entry-list.component.spec.ts && npx prettier --check src/app/reader/entry-list/entry-list.component.ts src/app/reader/entry-list/entry-list.component.spec.ts src/app/reader/entry-list/entry-list.component.html
```

Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/entry-list/entry-list.component.html frontend/src/app/reader/entry-list/entry-list.component.ts frontend/src/app/reader/entry-list/entry-list.component.spec.ts
git commit -m "fix(#501): listen to list scrolls outside the Angular zone"
```

---

### Task 3: Keep the reading-focus frame out of the zone too

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts` — the `@angular/core` import (`:1-15`), the injected services near `:373-378`, `focusPulse` docblock (`:414-416`), the `_readingFocus` docblock's last bullet (`:497`), `onRowsScroll` body (`this.pulseFocus()` at `:524`), `scheduleFocus` (`:538-547`)
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `onRowsScroll` arrow property from Task 2; `scheduleFocus()` and `pulseFocus()` already exist.
- Produces: nothing new for other tasks. `pulseFocus()` remains the entry point for resize (`:400`) and the row-collapse animation (`:553`).

- [ ] **Step 1: Write the failing test.** In the same new `describe('scroll events from the rows element')` block from Task 2, add:

```ts
    it('request the reading-focus frame outside the Angular zone', () => {
      const requestedInZone: boolean[] = [];
      const raf = jest.spyOn(window, 'requestAnimationFrame').mockImplementation(() => {
        requestedInZone.push(NgZone.isInAngularZone());
        return 1;
      });
      try {
        // The focus effect schedules the first pass during the initial render. It is
        // the only frame requested at mount: the scroll-restore loop (`settleTo`)
        // needs a remembered offset, and `memory.read` returns 0 here.
        mount();
        expect(requestedInZone.length).toBeGreaterThan(0);
        expect(requestedInZone).not.toContain(true);
      } finally {
        raf.mockRestore();
      }
    });
```

- [ ] **Step 2: Run it to verify it fails**

```bash
docker compose exec -T frontend npx jest src/app/reader/entry-list/entry-list.component.spec.ts -t 'request the reading-focus frame outside the Angular zone'
```

Expected: FAIL — `expect(received).not.toContain(true)`: the effect runs inside `detectChanges()`, which runs in the zone, so the frame is requested in the zone. If it fails instead with `length` 0, reading focus is off in the test environment: check that `localStorage.clear()` in the top-level `beforeEach` still runs and that `jest-global-mocks.ts` gives `matchMedia` a `matches: false` result for `prefers-reduced-motion`.

- [ ] **Step 3: Inject `NgZone` and schedule the frame outside it.** In `entry-list.component.ts`:

Add `NgZone,` to the `@angular/core` import list (alphabetically after `ElementRef,`).

Add the injection next to the other injected services (after `private readonly readingFocus = inject(ReadingFocusService);` around line 374):

```ts
  private readonly zone = inject(NgZone);
```

Replace `scheduleFocus` (lines 538-547) with:

```ts
  /** The reading-focus recompute, coalesced to one pass per animation frame.
   *  Called by the `_readingFocus` subscriber and straight from the scroll handler. */
  private scheduleFocus(): void {
    if (this.reduceMotion || !this.readingFocus.enabled() || this.focusRaf) return;
    // Outside the zone: the pass writes inline styles and no signal, so its frame
    // must not end in a tick over every loaded block (#501).
    this.zone.runOutsideAngular(() => {
      this.focusRaf = requestAnimationFrame(() => {
        this.focusRaf = 0;
        this.applyFocus();
      });
    });
  }
```

- [ ] **Step 4: Route the scroll path straight to `scheduleFocus()`.** In the `onRowsScroll` body, change line 524

```ts
    this.pulseFocus();
```

to

```ts
    this.scheduleFocus();
```

- [ ] **Step 5: Fix the two comments the change makes stale.**

`focusPulse` docblock (lines 414-416) becomes:

```ts
  /** Bumped by the rare imperative events that move rows under the reading centre
   *  without a signal changing: resize and the row-collapse animation. Scroll goes
   *  straight to `scheduleFocus()` — a signal per scroll event is a tick per frame. */
```

The last bullet of the `_readingFocus` docblock (line 497) becomes:

```ts
   *  - `focusPulse()` — the imperative events: resize, row collapse.
```

- [ ] **Step 6: Run the whole entry-list spec**

```bash
docker compose exec -T frontend npx jest src/app/reader/entry-list/entry-list.component.spec.ts
```

Expected: PASS. The existing reading-focus tests that `await frames()` still pass: jsdom fires the frame whichever zone requested it.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/entry-list/entry-list.component.ts frontend/src/app/reader/entry-list/entry-list.component.spec.ts
git commit -m "fix(#501): schedule the reading-focus frame outside the Angular zone"
```

---

### Task 4: Gate, real-browser check, measurement, PR

**Files:**
- No source changes. Reads `docs/superpowers/plans/2026-09-05-501-list-scroll-tick-spec.md` (appendix script).

**Interfaces:**
- Consumes: the branch as left by Task 3.
- Produces: a PR against `develop` with the before/after numbers in its body.

- [ ] **Step 1: Confirm the dev container serves the branch.** The Docker frontend container bind-mounts the checkout, but a stale chunk is a known trap (`frontend-dev-container-serves-stale-chunk`):

```bash
docker compose exec -T frontend grep -c appScrollOutsideZone src/app/reader/entry-list/entry-list.component.html
docker compose logs frontend --tail 3
```

Expected: the count is 2 (the container sees the branch through the `./frontend:/app` bind mount) and the log's last `Application bundle generation complete` is later than your last edit. If the count is 0 the mount is broken (`editing-a-bind-mounted-file-breaks-the-mount`); if the rebuild is missing, `docker compose restart frontend` and re-check.

- [ ] **Step 2: Run the CI gate**

```bash
docker compose exec -T frontend npm run check
```

Expected: ESLint, Prettier, Stylelint and Jest all green.

- [ ] **Step 3: Run the scroll-driving Playwright specs** (Docker stack up; these sign in with the seeded e2e admin themselves and skip if it is missing):

```bash
cd frontend && npm run e2e -- header-scroll-mobile pull-to-refresh-mobile list-scroll-reset
```

Expected: all pass. `header-scroll-mobile` is the proof that a signal written from the outside-zone handler (`collapsed`) still renders through the hybrid scheduler.

- [ ] **Step 4: Measure before and after.** Copy the appendix script from the spec to a scratch directory as `measure-501.mjs`. Run on this branch:

```bash
THROTTLE=6 PAGES=6 FOCUS=on node measure-501.mjs
THROTTLE=6 PAGES=1 FOCUS=on node measure-501.mjs
```

Baseline on `develop` (2026-09-05, this Mac): at 601 blocks `tickMs.median` ≈ 32, `frameMs.mean` ≈ 77, `over16` = 88 of 89 frames; at 101 blocks `frameMs.mean` ≈ 20.6.

Expected on the branch: `tickMs` unchanged (it times one tick, not the scroll); `frameMs.mean` at 601 blocks well below one tick (the focus pass ≈ 8 ms plus the handler), and `over16` far below 88. If `frameMs` is unchanged, a tick is still being scheduled per scroll: check for a signal written with a *changing* value in `onRowsScroll` (only `collapsed`/`showToTop`, which change rarely) and that `scheduleFocus` is the one called from the scroll path.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin fix/501-list-scroll-outside-zone
gh pr create --base develop --title "fix(#501): keep list scroll handling out of the Angular zone" --body-file - <<'PR'
Closes #501

Every scroll event on the entry list ran a change-detection tick over every loaded block (the template `(scroll)` binding runs in the zone), and the reading-focus frame it scheduled ran a second one. On a long list on the phone that misses the frame budget; iOS WebKit then shows unpainted tiles as a blink to the background colour.

- `ScrollOutsideZoneDirective`: passive `scroll` listener attached outside `NgZone`, used by both `#rows` scrollers.
- The scroll path calls `scheduleFocus()` directly instead of bumping the `focusPulse` signal; `scheduleFocus()` requests its frame outside the zone.
- `collapsed` / `showToTop` still render: the hybrid scheduler ticks when their value changes.

Measured (headless Chromium, 375x812, CPU throttle 6x, 601 blocks, focus on):

| | develop | this branch |
|---|---|---|
| frame time during scroll, mean | 77 ms | <fill in> |
| frames over 16.7 ms (of 89) | 88 | <fill in> |

Findings and script: `docs/superpowers/plans/2026-09-05-501-list-scroll-tick-spec.md`.

Deferred (own issues): `OnPush` on the magazine block components, the same change for `reader-view`'s `@HostListener('scroll')`, trimming `applyFocus()`.
PR
```

Fill in the two `<fill in>` cells from Step 4 before submitting (edit the body with `gh pr edit`).

- [ ] **Step 6: After the merge deploys**, scroll far down a long list on the iPhone and, if the blink is gone, leave a closing comment on #501 with the device and build; if it recurs, reopen with the answers to the issue's three "confirm next time" questions.
