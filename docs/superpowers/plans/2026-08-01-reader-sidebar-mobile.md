# Reader Sidebar Mobile Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the reader sidebar for touch: navigation-first drawer with 44px targets on coarse pointers, an Organise mode for drag/menus, a bottom action sheet replacing the clipping row popover, and one source of truth for the drawer breakpoint.

**Architecture:** One adaptive `SidebarComponent` branches on `LayoutService.isCoarse()` (pointer) for density/behaviour and on a new `organising` model signal for mode. The shell drives the drawer presentation from `LayoutService.isNarrow()` via an `is-narrow` class instead of a duplicate `@media` block. A new shared `ActionSheet` (CDK Dialog, bottom-pinned) serves coarse-pointer row menus; the desktop keeps today's inline drag and `.pop` popover untouched.

**Tech Stack:** Angular 20 standalone + signals, `@angular/cdk` (drag-drop, dialog, overlay, layout), Transloco, Jest (jsdom), Playwright.

**Spec:** `docs/superpowers/specs/2026-08-01-reader-sidebar-mobile-design.md`. Branch: `feature/185-reader-sidebar-mobile` (already created; work in this checkout — no worktree, the Docker stack is the e2e target).

## Global Constraints

- All frontend commands run from `frontend/`. The gate is `npm run check` (ESLint + Prettier + Stylelint + Jest) — green before every commit.
- Prettier: 100-column limit — break long test chains up front.
- Stylelint: no hex colours, no raw `px` for padding/margin/gap/font-size/border-radius outside `src/app/theme/`; `@media (width …)` takes no literal — only `bp.$bp-*`. (`@media (pointer: coarse)` is allowed: the rule governs `width` only.) `var(--space-*)` / `var(--tap-target)` always pass — a `var()` is not a unit.
- Component styles live in a sibling `.scss` (`styleUrl`), never inline in the `.ts`.
- Shared components (`src/app/shared/`) are standalone, `OnPush`, signal inputs, and take **already-translated strings** — never i18n keys.
- New i18n keys go to **both** `public/i18n/en.json` and `public/i18n/de.json`.
- Tests must fail under mutation: every assertion should pin a concrete value that the implementation could get wrong. No "renders without error" tests.
- Desktop (fine pointer) behaviour is out of scope: today's leading chevron, always-on drag and `.pop` popover stay byte-identical in behaviour. Only coarse-pointer behaviour and the breakpoint source change.
- Commits: conventional style referencing the issue, e.g. `feat(#185): …`.

---

### Task 1: `LayoutService.isCoarse`

**Files:**
- Modify: `frontend/src/app/reader/layout.service.ts`
- Test: `frontend/src/app/reader/layout.service.spec.ts`

**Interfaces:**
- Consumes: nothing new.
- Produces: `COARSE_QUERY = '(pointer: coarse)'` (exported const) and `LayoutService.isCoarse: Signal<boolean>` — Tasks 4–7 and the sidebar read it.

- [ ] **Step 1: Write the failing test**

Append to the existing `describe` in `layout.service.spec.ts` (it already stubs `BreakpointObserver` with a shared `Subject`; the service subscribes each signal to the same stub, so pushing to `changes` drives them all — mirror the `isWide` test exactly):

```ts
it('tracks the coarse-pointer capability', () => {
  const svc = TestBed.inject(LayoutService);
  changes.next({ matches: true, breakpoints: {} });
  expect(svc.isCoarse()).toBe(true);
  changes.next({ matches: false, breakpoints: {} });
  expect(svc.isCoarse()).toBe(false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx jest src/app/reader/layout.service.spec.ts`
Expected: FAIL — `svc.isCoarse is not a function`.

- [ ] **Step 3: Implement**

In `layout.service.ts`, add below `NARROW_QUERY`:

```ts
/** True on devices whose primary pointer is a finger — drives touch density
 *  and the Organise mode; presentation (drawer vs column) stays a width call. */
export const COARSE_QUERY = '(pointer: coarse)';
```

and inside the class, mirroring `isNarrow` (jsdom's mocked `matchMedia` returns `matches: false`, so the initial-value guard works unchanged):

```ts
readonly isCoarse = toSignal(this.bp.observe(COARSE_QUERY).pipe(map((s) => s.matches)), {
  initialValue: typeof window !== 'undefined' ? window.matchMedia(COARSE_QUERY).matches : false,
});
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx jest src/app/reader/layout.service.spec.ts`
Expected: PASS (all tests in the file).

- [ ] **Step 5: Commit**

```bash
git add src/app/reader/layout.service.ts src/app/reader/layout.service.spec.ts
git commit -m "feat(#185): expose the coarse-pointer capability from LayoutService"
```

---

### Task 2: Shared bottom action sheet

**Files:**
- Create: `frontend/src/app/shared/action-sheet/action-sheet.component.ts`
- Create: `frontend/src/app/shared/action-sheet/action-sheet.component.html`
- Create: `frontend/src/app/shared/action-sheet/action-sheet.component.scss`
- Create: `frontend/src/app/shared/action-sheet/action-sheet.service.ts`
- Test: `frontend/src/app/shared/action-sheet/action-sheet.service.spec.ts`
- Modify: `docs/design-language.md` (component catalog entry)

**Interfaces:**
- Consumes: `@angular/cdk/dialog` (`Dialog`, `DialogRef`, `DIALOG_DATA`), `@angular/cdk/overlay` (`Overlay` for the bottom position strategy).
- Produces:
  - `interface ActionSheetAction { id: string; label: string; danger?: boolean }`
  - `interface ActionSheetData { title: string; actions: ActionSheetAction[] }`
  - `class ActionSheet { open(data: ActionSheetData): Observable<string | undefined> }` — emits the chosen action `id`, or `undefined` on backdrop/Escape/swipe dismiss. Task 5 calls this.

- [ ] **Step 1: Write the failing tests**

`action-sheet.service.spec.ts`:

```ts
import { ApplicationRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { OverlayContainer } from '@angular/cdk/overlay';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { ActionSheet } from './action-sheet.service';
import { ActionSheetComponent, ActionSheetData } from './action-sheet.component';

const DATA: ActionSheetData = {
  title: 'News',
  actions: [
    { id: 'edit', label: 'Edit tag' },
    { id: 'delete', label: 'Delete tag', danger: true },
  ],
};

describe('ActionSheet', () => {
  let sheet: ActionSheet;
  let container: HTMLElement;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    sheet = TestBed.inject(ActionSheet);
    container = TestBed.inject(OverlayContainer).getContainerElement();
  });

  const open = () => {
    const closed = sheet.open(DATA);
    TestBed.inject(ApplicationRef).tick(); // flush the portal's initial render
    return closed;
  };

  const items = () => container.querySelectorAll<HTMLElement>('[role=menuitem]');

  it('renders the title and one menu item per action, flagging danger', () => {
    open().subscribe();
    expect(container.querySelector('[role=menu]')!.getAttribute('aria-label')).toBe('News');
    expect(items()).toHaveLength(2);
    expect(items()[0].textContent).toContain('Edit tag');
    expect(items()[1].textContent).toContain('Delete tag');
    expect(items()[1].classList).toContain('danger');
    expect(items()[0].classList).not.toContain('danger');
  });

  it('resolves with the chosen action id', (done) => {
    open().subscribe((choice) => {
      expect(choice).toBe('delete');
      done();
    });
    items()[1].click();
  });

  it('pins the pane to the sheet panel class', () => {
    open().subscribe();
    expect(container.querySelector('.cdk-overlay-pane.app-action-sheet')).not.toBeNull();
  });
});

describe('ActionSheetComponent swipe dismiss', () => {
  const mount = () => {
    const ref = { close: jest.fn() };
    TestBed.configureTestingModule({
      providers: [
        { provide: DIALOG_DATA, useValue: DATA },
        { provide: DialogRef, useValue: ref },
      ],
    });
    return { cmp: TestBed.createComponent(ActionSheetComponent).componentInstance, ref };
  };

  const touch = (clientY: number) => ({ touches: [{ clientY }] }) as unknown as TouchEvent;

  it('closes on a downward swipe past the threshold', () => {
    const { cmp, ref } = mount();
    cmp.onTouchStart(touch(100));
    cmp.onTouchMove(touch(180));
    cmp.onTouchEnd();
    expect(ref.close).toHaveBeenCalledWith();
  });

  it('ignores a swipe under the threshold and an upward swipe', () => {
    const { cmp, ref } = mount();
    cmp.onTouchStart(touch(100));
    cmp.onTouchMove(touch(140));
    cmp.onTouchEnd();
    cmp.onTouchStart(touch(100));
    cmp.onTouchMove(touch(20));
    cmp.onTouchEnd();
    expect(ref.close).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx jest src/app/shared/action-sheet`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the component**

`action-sheet.component.ts`:

```ts
// src/app/shared/action-sheet/action-sheet.component.ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';

/** One choice on the sheet. `label` arrives already translated — this lives in
 *  shared/ and must not know any feature's i18n keys. */
export interface ActionSheetAction {
  id: string;
  label: string;
  danger?: boolean;
}

/** What the sheet shows: the row it acts on, and that row's actions. */
export interface ActionSheetData {
  title: string;
  actions: ActionSheetAction[];
}

@Component({
  selector: 'app-action-sheet',
  templateUrl: './action-sheet.component.html',
  styleUrl: './action-sheet.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '(touchstart)': 'onTouchStart($event)',
    '(touchmove)': 'onTouchMove($event)',
    '(touchend)': 'onTouchEnd()',
  },
})
export class ActionSheetComponent {
  readonly data = inject<ActionSheetData>(DIALOG_DATA);
  readonly ref = inject<DialogRef<string>>(DialogRef);

  private startY = 0;
  private dy = 0;

  onTouchStart(event: TouchEvent): void {
    if (event.touches.length !== 1) return;
    this.startY = event.touches[0].clientY;
    this.dy = 0;
  }

  onTouchMove(event: TouchEvent): void {
    if (event.touches.length === 1) this.dy = event.touches[0].clientY - this.startY;
  }

  /** A decisive downward pull dismisses, mirroring the sheet's slide-up entry.
   *  60px keeps a scroll-ish wobble from closing it. */
  onTouchEnd(): void {
    if (this.dy > 60) this.ref.close();
  }
}
```

`action-sheet.component.html`:

```html
<div class="grabber" aria-hidden="true"></div>
<nav role="menu" [attr.aria-label]="data.title">
  <p class="title">{{ data.title }}</p>
  @for (action of data.actions; track action.id) {
    <button
      role="menuitem"
      type="button"
      [class.danger]="action.danger"
      (click)="ref.close(action.id)"
    >
      {{ action.label }}
    </button>
  }
</nav>
```

`action-sheet.component.scss`:

```scss
:host {
  display: block;
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-bottom: none;
  border-top-left-radius: var(--radius-lg);
  border-top-right-radius: var(--radius-lg);
  padding: var(--space-2) var(--space-2) var(--space-4);
  animation: sheet-enter 0.2s ease-out;
}

.grabber {
  width: var(--space-6);
  height: var(--space-1);
  border-radius: var(--radius-pill);
  background: var(--border-strong);
  margin: 0 auto var(--space-2);
}

.title {
  margin: 0 var(--space-3) var(--space-1);
  font-size: var(--fs-sm);
  color: var(--text-muted);
}

nav button {
  display: flex;
  align-items: center;
  width: 100%;
  min-height: var(--tap-target);
  padding: var(--row-pad-y) var(--row-pad-x);
  background: none;
  border: none;
  border-radius: var(--radius);
  color: var(--text-primary);
  font-size: var(--fs-base);
  text-align: left;
  cursor: pointer;
}

nav button:hover,
nav button:focus-visible {
  background: var(--surface-0);
}

nav button.danger {
  color: var(--danger);
}

@keyframes sheet-enter {
  from {
    transform: translateY(100%);
  }

  to {
    transform: translateY(0);
  }
}

@media (prefers-reduced-motion: reduce) {
  :host {
    animation: none;
  }
}
```

`action-sheet.service.ts`:

```ts
// src/app/shared/action-sheet/action-sheet.service.ts
import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Overlay } from '@angular/cdk/overlay';
import { Observable } from 'rxjs';
import { ActionSheetComponent, ActionSheetData } from './action-sheet.component';

/**
 * The one row-menu surface for coarse pointers: a sheet pinned to the bottom of
 * the VIEWPORT, so it can never clip inside a drawer the way the old
 * right-anchored popover did (#185). Rendered through the CDK overlay because
 * the open drawer carries a transform, which would turn any position: fixed
 * descendant into a drawer-relative box.
 */
@Injectable({ providedIn: 'root' })
export class ActionSheet {
  private readonly dialog = inject(Dialog);
  private readonly overlay = inject(Overlay);

  /** Emits the chosen action id, or undefined when dismissed. */
  open(data: ActionSheetData): Observable<string | undefined> {
    return this.dialog.open<string>(ActionSheetComponent, {
      panelClass: 'app-action-sheet',
      positionStrategy: this.overlay.position().global().centerHorizontally().bottom('0'),
      width: 'min(100%, 30rem)',
      data,
    }).closed;
  }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npx jest src/app/shared/action-sheet`
Expected: PASS (5 tests).

- [ ] **Step 5: Document in the design language**

In `docs/design-language.md` §2 (component catalog), after `<app-settings-card>`, add:

```markdown
### `<app-action-sheet>` (via the `ActionSheet` service)

The row-menu surface for coarse pointers: a sheet pinned to the bottom of the
viewport, titled with the row it acts on. Opened through the `ActionSheet`
service — never instantiated in a template — because the open drawer carries a
transform, which would re-anchor any `position: fixed` child; the CDK overlay
escapes that.

```ts
this.sheet
  .open({ title: tag.name, actions: [{ id: 'edit', label: editLabel }] })
  .subscribe((choice) => { /* undefined on dismiss */ });
```

Labels and title are **already-translated strings** (shared component, no
feature keys). `danger: true` renders the action in the danger colour.
Dismissed by backdrop tap, Escape, or a downward swipe — all resolve
`undefined`.

**Not for:** fine-pointer surfaces (the sidebar keeps its inline `.pop`
popover on desktop) or anything with form controls — that is a dialog in
`<app-overlay-panel>`.
```

- [ ] **Step 6: Commit**

```bash
git add src/app/shared/action-sheet ../docs/design-language.md
git commit -m "feat(#185): shared bottom action sheet for coarse-pointer row menus"
```

---

### Task 3: One breakpoint source for the drawer

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.html` (the `.body` div)
- Modify: `frontend/src/app/reader/reader-shell.component.scss` (replace the `bp.$bp-md` media block)
- Modify: `frontend/src/app/reader/layout.service.ts` (doc comment only)
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`
- Modify: `docs/design-language.md` (breakpoints section note)

**Interfaces:**
- Consumes: `LayoutService.isNarrow` (existing) and `isCoarse` (Task 1, only for the mock shape).
- Produces: the `.body.is-narrow` class contract — the stylesheet's drawer rules key on it. Task 8's smoke exercises it end-to-end.

- [ ] **Step 1: Write the failing test**

In `reader-shell.component.spec.ts`, add (inside the main `describe`, after the existing tests — the file's `beforeEach` already configures the TestBed, so only override the provider before creating the component; reuse the spec's existing request-flushing helper the other tests use after `createComponent`):

```ts
it('drives the drawer presentation from LayoutService.isNarrow, not a media query', () => {
  const narrow = signal(true);
  TestBed.overrideProvider(LayoutService, {
    useValue: { isNarrow: narrow, isWide: signal(false), isCoarse: signal(true) },
  });
  const f = TestBed.createComponent(ReaderShellComponent);
  f.detectChanges();
  ctrl.match(() => true).forEach((req) =>
    req.flush({ subscriptions: [], tags: [], entries: [], favoritesCount: 0, keptCount: 0, nextCursor: null }),
  );
  f.detectChanges();

  const body = (f.nativeElement as HTMLElement).querySelector('.body')!;
  expect(body.classList).toContain('is-narrow');
  narrow.set(false);
  f.detectChanges();
  expect(body.classList).not.toContain('is-narrow');
});
```

Add the imports the file lacks: `import { LayoutService } from './layout.service';` (and `signal` is already imported).

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx jest src/app/reader/reader-shell.component.spec.ts -t "drives the drawer"`
Expected: FAIL — `.body` classList does not contain `is-narrow`.

- [ ] **Step 3: Bind the class**

In `reader-shell.component.html`, the `.body` div gains one binding:

```html
<div
  class="body"
  [class.is-narrow]="screen.isNarrow()"
  appDrawerSwipe
  [appDrawerSwipeOpen]="sidebarOpen()"
  [appDrawerSwipeDisabled]="!screen.isNarrow() || articleFullscreen()"
  (appDrawerSwipeOpenDrawer)="setSidebarOpen(true)"
  (appDrawerSwipeCloseDrawer)="setSidebarOpen(false)"
>
```

- [ ] **Step 4: Re-key the drawer styles to the class**

In `reader-shell.component.scss`, delete the entire `@media (width <= bp.$bp-md) { … }` block at the bottom and replace it with:

```scss
/* The drawer presentation. Keyed to the `is-narrow` class the shell binds from
   LayoutService.isNarrow() — NOT to a media query — so the 720px boundary is
   declared exactly once, in NARROW_QUERY (#185). The comments from the old
   media block still apply: `top` is the pre-measurement fallback; opening the
   drawer forces the header visible, so the offset never meets a hidden bar. */
.body.is-narrow .sidebar {
  position: fixed;
  top: var(--bar-h);
  bottom: 0;
  padding-top: 0; /* the fixed `top` above places it; see the column rule */
  left: 0;
  z-index: 20;

  /* stylelint-disable-next-line declaration-property-unit-allowed-list --
     tuned drawer measure, not a spacing value. */
  width: 260px;
  max-width: 82vw;
  transform: translateX(-100%);
  transition: transform 0.2s ease;
}

.body.is-narrow .sidebar.open {
  transform: translateX(0);
}

.body.is-narrow .backdrop {
  display: block;
  position: fixed;
  inset: var(--bar-h) 0 0;
  z-index: 15;
  background: rgb(0 0 0 / 40%);
}
```

(The `@use '../theme/breakpoints' as bp;` line stays — `bp.$bp-lg` is still used by the article overlay.)

- [ ] **Step 5: Declare the single source**

In `layout.service.ts`, replace the `NARROW_QUERY` doc comment:

```ts
/** True below this width, where the sidebar is a swipe-in drawer, not a column.
 *  THE single source of the drawer boundary: the shell binds `.is-narrow` from
 *  this signal and the stylesheet keys every drawer rule to that class — no
 *  media query may re-declare this width (#185). */
export const NARROW_QUERY = '(max-width: 720px)';
```

- [ ] **Step 6: Run the tests**

Run: `npx jest src/app/reader/reader-shell.component.spec.ts`
Expected: PASS (whole file — the pre-existing tests keep passing because the real `LayoutService` under jsdom's `matchMedia` mock reports narrow=false, same as before).

- [ ] **Step 7: Note the pattern in the design language**

In `docs/design-language.md` §1 Breakpoints, append after the Stylelint paragraph:

```markdown
**The reader drawer's 720px boundary is class-driven, not media-driven.**
`LayoutService.NARROW_QUERY` is its single declaration; the shell binds
`.is-narrow` from that signal and `reader-shell.component.scss` keys the drawer
rules to the class. Do not add a `bp.$bp-md` media block for the drawer — that
would restore the two-sources drift #185 removed. `bp.$bp-*` remains correct
for purely presentational media queries that have no TS twin.
```

- [ ] **Step 8: Commit**

```bash
git add src/app/reader/reader-shell.component.html src/app/reader/reader-shell.component.scss \
  src/app/reader/reader-shell.component.spec.ts src/app/reader/layout.service.ts ../docs/design-language.md
git commit -m "refactor(#185): drive the drawer breakpoint from LayoutService alone"
```

---

### Task 4: Sidebar foot grouping, Organise model and mode visibility

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.html`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `LayoutService.isCoarse` (Task 1).
- Produces: `SidebarComponent.organising: ModelSignal<boolean>` (bound by the shell in Task 6); i18n key `reader.organise`; CSS classes `.foot`, `.organise`. Task 5 builds the row anatomy on top of `organising()`.

- [ ] **Step 1: Write the failing tests**

In `sidebar.component.spec.ts`, first extend the `mount` helper: add `coarse` and `organising` to the `over` type, provide a LayoutService stub, and set the model input:

```ts
// added to the Partial<…> type parameter of `over`:
//   coarse: boolean;
//   organising: boolean;
// added to the providers array:
{
  provide: LayoutService,
  useValue: { isCoarse: signal(over.coarse ?? false) },
},
// added after the other setInput calls:
f.componentRef.setInput('organising', over.organising ?? false);
```

with `import { LayoutService } from '../layout.service';` at the top. Then add a describe block:

```ts
describe('organise mode', () => {
  const tag: TagDto = { id: 1, name: 'News', color: null, icon: null, position: 0 };
  const tree: TagNode[] = [{ tag, subscriptions: [sub(5)], unreadCount: 3 }];

  it('offers the Organise switch on coarse pointers only', () => {
    const coarse = mount({ coarse: true }).nativeElement as HTMLElement;
    const fine = mount({ coarse: false }).nativeElement as HTMLElement;
    const organiseSwitch = coarse.querySelector('.organise')!;
    expect(organiseSwitch.getAttribute('role')).toBe('switch');
    expect(organiseSwitch.getAttribute('aria-checked')).toBe('false');
    expect(organiseSwitch.textContent).toContain('Organise');
    expect(fine.querySelector('.organise')).toBeNull();
  });

  it('clicking the switch flips the organising model', () => {
    const f = mount({ coarse: true });
    (f.nativeElement as HTMLElement).querySelector<HTMLElement>('.organise')!.click();
    f.detectChanges();
    expect(f.componentInstance.organising()).toBe(true);
    expect(
      (f.nativeElement as HTMLElement).querySelector('.organise')!.getAttribute('aria-checked'),
    ).toBe('true');
  });

  it('organising hides the actions, global views, view controls and trial line', () => {
    const el = mount({
      coarse: true,
      organising: true,
      tagTree: tree,
      user: account(inDays(5)),
    }).nativeElement as HTMLElement;
    expect(el.querySelector('.actions')).toBeNull();
    expect(el.querySelector('.nav.all')).toBeNull();
    expect(el.querySelector('app-view-controls')).toBeNull();
    expect(el.querySelector('.trial')).toBeNull();
    expect(el.querySelector('.version')).not.toBeNull();
    expect(el.querySelector('.tags')).not.toBeNull();
  });

  it('navigation mode keeps all of them', () => {
    const el = mount({
      coarse: true,
      tagTree: tree,
      user: account(inDays(5)),
    }).nativeElement as HTMLElement;
    expect(el.querySelector('.actions')).not.toBeNull();
    expect(el.querySelector('.nav.all')).not.toBeNull();
    expect(el.querySelector('app-view-controls')).not.toBeNull();
    expect(el.querySelector('.trial')).not.toBeNull();
  });

  it('organising always shows the Feeds label as the untag drop target', () => {
    const el = mount({ coarse: true, organising: true, untagged: [] })
      .nativeElement as HTMLElement;
    expect(el.textContent).toContain('Feeds');
  });
});
```

(`TagDto`, `TagNode`, `account`, `inDays`, `sub` already exist in the file.)

- [ ] **Step 2: Run to verify they fail**

Run: `npx jest src/app/reader/sidebar/sidebar.component.spec.ts -t "organise"`
Expected: FAIL — required input `organising`… actually the model has a default, so: FAIL on `.organise` being null / `.actions` still present.

- [ ] **Step 3: Add the i18n keys**

In `public/i18n/en.json`, inside `"reader"`, after `"feeds"`:

```json
"organise": "Organise",
```

In `public/i18n/de.json`, same position:

```json
"organise": "Organisieren",
```

- [ ] **Step 4: Component — model + service**

In `sidebar.component.ts`:

- Add `model` to the `@angular/core` import.
- Add `import { LayoutService } from '../layout.service';`
- In the class, next to the other injections and inputs:

```ts
readonly screen = inject(LayoutService);
/** Structural-editing mode for coarse pointers: hides navigation and actions,
 *  reveals drag handles and row menus. A model so the shell can reset it when
 *  the drawer closes. Only ever set on coarse pointers — the switch does not
 *  render elsewhere. */
readonly organising = model(false);
```

- [ ] **Step 5: Template — wrap the top, group the foot**

In `sidebar.component.html`:

1. Wrap the actions row, the progress bar and the three global `<a class="nav">` links (everything from `<div class="actions">` through the Kept link) in `@if (!organising()) { … }`.
2. Change the Feeds label condition to include organising:

```html
@if (untagged().length || dragging() || organising()) {
  <p class="label">{{ 'reader.feeds' | transloco }}</p>
}
```

3. Replace the tail of the template — from `<app-view-controls class="controls" />` down to the closing `</nav>` — with a grouped foot:

```html
  <div class="foot">
    @if (screen.isCoarse()) {
      <button
        class="organise"
        type="button"
        role="switch"
        [attr.aria-checked]="organising()"
        (click)="organising.set(!organising())"
      >
        <span>{{ 'reader.organise' | transloco }}</span>
        <span class="track" aria-hidden="true"><span class="knob"></span></span>
      </button>
    }
    @if (!organising()) {
      <app-view-controls class="controls" />
      @if (trialDaysLeft(); as days) {
        <p class="trial" [class.soon]="trialEndingSoon()">
          <app-icon name="schedule" size="sm" />
          <span>{{
            (days === 1 ? 'reader.trialDayLeft' : 'reader.trialDaysLeft') | transloco: { days }
          }}</span>
        </p>
      }
    }
    <a
      class="version"
      routerLink="/settings"
      [attr.aria-label]="'reader.versionLabel' | transloco: { version }"
      >{{ version }}</a
    >
  </div>
</nav>
```

- [ ] **Step 6: Styles — foot and switch**

In `sidebar.component.scss`, replace the `.controls` rule (`margin-top: auto; padding-top: var(--space-3);`) with:

```scss
/* Meta controls grouped at the drawer's foot: Organise, then layout/theme,
   then trial and version. The wrapper takes the old margin-top: auto. */
.foot {
  margin-top: auto;
  display: flex;
  flex-direction: column;
}

.controls {
  padding-top: var(--space-3);
}

.organise {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  margin-top: var(--space-3);
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface-2);
  color: var(--text-primary);
  font-size: var(--fs-sm);
  cursor: pointer;
}

.organise .track {
  position: relative;
  display: inline-block;

  /* stylelint-disable-next-line declaration-property-unit-allowed-list --
     tuned switch-track measure, not a spacing value. */
  width: 32px;
  height: var(--space-4);
  border-radius: var(--radius-pill);
  background: var(--border-strong);
  transition: background 0.15s;
}

.organise .knob {
  position: absolute;
  top: var(--space-0);
  left: var(--space-0);
  width: var(--space-3);
  height: var(--space-3);
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  transition: transform 0.15s;
}

.organise[aria-checked='true'] {
  border-color: var(--accent);
  color: var(--accent);
}

.organise[aria-checked='true'] .track {
  background: var(--accent);
}

.organise[aria-checked='true'] .knob {
  transform: translateX(100%);
}

@media (prefers-reduced-motion: reduce) {
  .organise .track,
  .organise .knob {
    transition: none;
  }
}
```

(`.knob` travel: the track is 32px wide with a 12px knob inset 2px — `translateX(100%)` moves it by its own 12px width; verify visually in Task 8 and nudge to `translateX(var(--space-4))` if it under-travels. Keep whichever lands the knob flush right.)

- [ ] **Step 7: Run the sidebar suite**

Run: `npx jest src/app/reader/sidebar/sidebar.component.spec.ts`
Expected: PASS — new tests green, existing tests untouched (fine-pointer default keeps today's DOM).

- [ ] **Step 8: Commit**

```bash
git add src/app/reader/sidebar public/i18n/en.json public/i18n/de.json
git commit -m "feat(#185): organise mode scaffold and grouped sidebar foot"
```

---

### Task 5: Row anatomy — coarse navigation and organise rows

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.html`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.scss`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `ActionSheet.open(data): Observable<string | undefined>` (Task 2), `organising` model (Task 4), `screen.isCoarse` (Task 1).
- Produces: CSS classes `.chevzone`, `.handle`, `.rowbody`, `.feedname` (Task 7 sizes them; Task 8 asserts them); `dragLocked()` and `dragDelay()` computed signals.

- [ ] **Step 1: Write the failing tests**

Append to the `organise mode` describe (Task 4) in `sidebar.component.spec.ts`. Add imports: `import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';`, `import { of } from 'rxjs';`, and `import { CdkDrag } from '@angular/cdk/drag-drop';` (extend the existing cdk import), `import { By } from '@angular/platform-browser';`. Extend `mount`'s `over` with `sheetChoice?: string` and add the provider:

```ts
{ provide: ActionSheet, useValue: { open: jest.fn(() => of(over.sheetChoice)) } },
```

Tests:

```ts
it('coarse navigation trades the leading chevron for a 44px trailing zone', () => {
  const el = mount({ coarse: true, tagTree: tree }).nativeElement as HTMLElement;
  expect(el.querySelector('.expand')).toBeNull();
  const zone = el.querySelector('.chevzone')!;
  expect(zone.getAttribute('aria-expanded')).toBe('false');
  expect(el.querySelector('.tag .nav.grow')).not.toBeNull(); // name still navigates
  expect(el.querySelector('.dots')).toBeNull(); // read-only: no row menu
});

it('the chevron zone expands the tag without navigating', () => {
  const f = mount({ coarse: true, tagTree: tree });
  const el = f.nativeElement as HTMLElement;
  el.querySelector<HTMLElement>('.chevzone')!.click();
  f.detectChanges();
  expect(el.querySelector('.tagfeeds')).not.toBeNull();
  expect(el.querySelector('.chevzone')!.getAttribute('aria-expanded')).toBe('true');
});

it('organise rows carry a drag handle and expand via the row body', () => {
  const f = mount({ coarse: true, organising: true, tagTree: tree });
  const el = f.nativeElement as HTMLElement;
  expect(el.querySelector('.tag .handle')).not.toBeNull();
  expect(el.querySelector('.tag .nav.grow')).toBeNull(); // navigation is off
  expect(el.querySelector('.chevzone')).toBeNull();
  el.querySelector<HTMLElement>('.tag .rowbody')!.click();
  f.detectChanges();
  expect(el.querySelector('.tagfeeds')).not.toBeNull();
  expect(el.querySelector('.tagfeeds .handle')).not.toBeNull(); // feeds get handles too
});

it('the tag dots open the action sheet and route the choice', () => {
  const f = mount({ coarse: true, organising: true, tagTree: tree, sheetChoice: 'delete' });
  const deleted = jest.fn();
  f.componentInstance.deleteTag.subscribe(deleted);
  f.nativeElement.querySelector('.tag .dots').click();
  const sheet = TestBed.inject(ActionSheet);
  expect(sheet.open).toHaveBeenCalledWith({
    title: 'News',
    actions: [
      { id: 'edit', label: 'Edit tag' },
      { id: 'delete', label: 'Delete tag', danger: true },
    ],
  });
  expect(deleted).toHaveBeenCalledWith(tag);
});

it('the feed dots offer edit and unsubscribe', () => {
  const f = mount({ coarse: true, organising: true, untagged: [sub(9)], sheetChoice: 'edit' });
  const edited = jest.fn();
  f.componentInstance.editFeed.subscribe(edited);
  f.nativeElement.querySelector('.feedrow .dots').click();
  const sheet = TestBed.inject(ActionSheet);
  expect(sheet.open).toHaveBeenCalledWith({
    title: 's9',
    actions: [
      { id: 'edit', label: 'Edit feed' },
      { id: 'unsubscribe', label: 'Unsubscribe', danger: true },
    ],
  });
  expect(edited).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }));
});

it('locks dragging in coarse navigation mode and frees it while organising', () => {
  const nav = mount({ coarse: true, tagTree: tree });
  expect(nav.debugElement.query(By.directive(CdkDrag)).injector.get(CdkDrag).disabled).toBe(true);
  const org = mount({ coarse: true, organising: true, tagTree: tree });
  expect(org.debugElement.query(By.directive(CdkDrag)).injector.get(CdkDrag).disabled).toBe(false);
  expect(org.componentInstance.dragDelay()).toBe(0); // explicit handle, no long-press
  const desktop = mount({ tagTree: tree });
  expect(desktop.debugElement.query(By.directive(CdkDrag)).injector.get(CdkDrag).disabled).toBe(
    false,
  );
  expect(desktop.componentInstance.dragDelay()).toEqual({ touch: 180, mouse: 0 });
});

it('desktop keeps the leading chevron, inline menu and popover', () => {
  const el = mount({ tagTree: tree }).nativeElement as HTMLElement;
  expect(el.querySelector('.expand')).not.toBeNull();
  expect(el.querySelector('.chevzone')).toBeNull();
  expect(el.querySelector('.handle')).toBeNull();
  expect(el.querySelector('.rowmenu .dots')).not.toBeNull();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `npx jest src/app/reader/sidebar/sidebar.component.spec.ts -t "organise"`
Expected: FAIL — `.chevzone` null, `.handle` null, etc.

- [ ] **Step 3: Component — drag state and sheet openers**

In `sidebar.component.ts`:

- Extend imports: `computed` is already there; add `CdkDragHandle` to the `@angular/cdk/drag-drop` import and to the component `imports` array; add `import { TranslocoService } from '@jsverse/transloco';` (alongside the existing `TranslocoPipe`); add `import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';`.
- **Delete** the line `readonly dragDelay = { touch: 180, mouse: 0 };` and add:

```ts
private readonly sheet = inject(ActionSheet);
private readonly transloco = inject(TranslocoService);

/** Coarse pointers may drag only in Organise mode; navigation is read-only. */
readonly dragLocked = computed(() => this.screen.isCoarse() && !this.organising());
/** Organise drags start from an explicit handle, so no long-press guard; the
 *  desktop keeps the hold-to-drag that lets a touch swipe still scroll. */
readonly dragDelay = computed(() => (this.organising() ? 0 : { touch: 180, mouse: 0 }));

/** ⋯ on a tag row (coarse): sheet with the tag's actions. */
openTagSheet(tag: TagDto): void {
  this.sheet
    .open({
      title: tag.name,
      actions: [
        { id: 'edit', label: this.transloco.translate('reader.editTag') },
        { id: 'delete', label: this.transloco.translate('reader.deleteTag'), danger: true },
      ],
    })
    .subscribe((choice) => {
      if (choice === 'edit') this.editTag.emit(tag);
      if (choice === 'delete') this.deleteTag.emit(tag);
    });
}

/** ⋯ on a feed row (coarse): sheet with the subscription's actions. */
openFeedSheet(subscription: SubscriptionDto): void {
  this.sheet
    .open({
      title: subscription.title,
      actions: [
        { id: 'edit', label: this.transloco.translate('reader.editFeed') },
        { id: 'unsubscribe', label: this.transloco.translate('reader.unsubscribe'), danger: true },
      ],
    })
    .subscribe((choice) => {
      if (choice === 'edit') this.editFeed.emit(subscription);
      if (choice === 'unsubscribe') this.unsubscribe.emit(subscription);
    });
}
```

- [ ] **Step 4: Template — the three row variants**

In `sidebar.component.html`, three structurally identical edits. **Tag row** (`div.tag`): add the two drag bindings and replace the children:

```html
<div
  class="tag"
  cdkDrag
  [cdkDragData]="node.tag"
  [cdkDragDisabled]="dragLocked()"
  [cdkDragStartDelay]="dragDelay()"
  (cdkDragStarted)="onDragStart('tag')"
  (cdkDragEnded)="onDragEnd()"
>
  @if (organising()) {
    <span class="handle" cdkDragHandle aria-hidden="true">
      <app-icon name="drag_indicator" size="sm" />
    </span>
    <button
      class="rowbody"
      type="button"
      [attr.aria-expanded]="expanded().has(node.tag.id)"
      [attr.aria-label]="'reader.toggleTag' | transloco: { name: node.tag.name }"
      (click)="toggle(node.tag.id)"
    >
      <span class="lead">
        <app-tag-glyph [name]="node.tag.icon" [color]="node.tag.color" size="sm" />
      </span>
      <span class="name">{{ node.tag.name }}</span>
    </button>
    <button
      class="dots"
      type="button"
      [attr.aria-label]="'reader.manage' | transloco: { name: node.tag.name }"
      (click)="openTagSheet(node.tag)"
    >
      <app-icon name="more_horiz" size="sm" />
    </button>
  } @else {
    @if (!screen.isCoarse()) {
      <button
        class="expand"
        type="button"
        [attr.aria-expanded]="expanded().has(node.tag.id)"
        [attr.aria-label]="'reader.toggleTag' | transloco: { name: node.tag.name }"
        (click)="toggle(node.tag.id)"
      >
        <app-icon
          [name]="expanded().has(node.tag.id) ? 'expand_more' : 'chevron_right'"
          size="sm"
        />
      </button>
    }
    <a
      class="nav grow"
      [class.active]="selection().kind === 'tag' && selection().id === node.tag.id"
      [routerLink]="[]"
      [queryParams]="{ tag: node.tag.id, view: null, subscription: null, entry: null }"
      queryParamsHandling="merge"
    >
      <span class="lead">
        <app-tag-glyph [name]="node.tag.icon" [color]="node.tag.color" size="sm" />
      </span>
      <span>{{ node.tag.name }}</span>
      @if (node.unreadCount > 0) {
        <span class="count">{{ node.unreadCount }}</span>
      }
    </a>
    @if (screen.isCoarse()) {
      <button
        class="chevzone"
        type="button"
        [attr.aria-expanded]="expanded().has(node.tag.id)"
        [attr.aria-label]="'reader.toggleTag' | transloco: { name: node.tag.name }"
        (click)="toggle(node.tag.id)"
      >
        <app-icon
          [name]="expanded().has(node.tag.id) ? 'expand_more' : 'chevron_right'"
          size="sm"
        />
      </button>
    } @else {
      @let tagMenuKey = 'tag-' + node.tag.id;
      <div
        class="rowmenu"
        [appDismissOnOutside]="menuFor() === tagMenuKey"
        (dismiss)="closeMenu()"
      >
        <!-- unchanged: today's .dots button and .pop menu markup -->
      </div>
    }
  }
</div>
```

(The `.rowmenu` block content is today's markup, moved unedited inside the `@else`.)

**Tagged feed row** (`div.feedrow` inside `.tagfeeds`) — same pattern:

```html
<div
  class="feedrow"
  cdkDrag
  [cdkDragData]="s"
  [cdkDragDisabled]="dragLocked()"
  [cdkDragStartDelay]="dragDelay()"
  (cdkDragStarted)="onDragStart('feed')"
  (cdkDragEnded)="onDragEnd()"
>
  @if (organising()) {
    <span class="handle" cdkDragHandle aria-hidden="true">
      <app-icon name="drag_indicator" size="sm" />
    </span>
    <span class="feedname"><app-favicon [url]="s.faviconUrl" /><span>{{ s.title }}</span></span>
    <button
      class="dots"
      type="button"
      [attr.aria-label]="'reader.manage' | transloco: { name: s.title }"
      (click)="openFeedSheet(s)"
    >
      <app-icon name="more_horiz" size="sm" />
    </button>
  } @else {
    <a class="nav tag-sub" …unchanged today's link…></a>
    @if (!screen.isCoarse()) {
      <div class="rowmenu" …unchanged today's menu…></div>
    }
  }
</div>
```

**Untagged feed row** (`div.feedrow` inside `.feedlist`): identical to the tagged variant except the `@else` link keeps class `nav` (no `tag-sub`) and the menu key stays `'sub-' + s.id`. Repeat the full organise branch — do not factor a partial; three repetitions of a seven-line block is within the template's idiom, and the drop-list wiring around each stays untouched.

- [ ] **Step 5: Styles**

In `sidebar.component.scss`, add after the `.feedrow` rules:

```scss
/* Organise-mode drag handle: the ONLY drag origin there (cdkDragHandle), so a
   plain touch on the row body scrolls or expands without competing. */
.handle {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--tap-target);
  align-self: stretch;
  color: var(--text-muted);
  cursor: grab;
}

/* Organise-mode row body: the expand/collapse target across its whole width. */
.rowbody {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--row-pad-y) 0;
  background: none;
  border: none;
  color: var(--text-primary);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.rowbody .name {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Coarse navigation: the dedicated expand zone at the row's trailing edge. */
.chevzone {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--tap-target);
  height: var(--tap-target);
  background: none;
  border: none;
  color: var(--text-secondary);
  cursor: pointer;
}

/* Organise-mode feed name: inert (navigation is off), but still truncates. */
.feedname {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--row-pad-y) 0;
}

.feedname > span {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
```

- [ ] **Step 6: Run the sidebar suite**

Run: `npx jest src/app/reader/sidebar/sidebar.component.spec.ts`
Expected: PASS — all new tests and every pre-existing test (desktop DOM unchanged; the drag/drop handler tests never touch the row internals).

- [ ] **Step 7: Commit**

```bash
git add src/app/reader/sidebar
git commit -m "feat(#185): model-2 tag rows, organise handles and action-sheet menus"
```

---

### Task 6: Shell wiring — organising binding, swipe pause, reset on close

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts`
- Modify: `frontend/src/app/reader/reader-shell.component.html`
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`

**Interfaces:**
- Consumes: `SidebarComponent.organising` model (Task 4), `DrawerSwipeDirective.disabled` input (existing — no directive change needed; composing into the existing expression *is* the spec's "swipe paused while organising").
- Produces: `ReaderShellComponent.sidebarOrganising: WritableSignal<boolean>`.

- [ ] **Step 1: Write the failing tests**

In `reader-shell.component.spec.ts` (add `import { DrawerSwipeDirective } from './drawer-swipe.directive';` and `import { By } from '@angular/platform-browser';` if absent):

```ts
it('pauses the drawer swipe while organising', () => {
  TestBed.overrideProvider(LayoutService, {
    useValue: { isNarrow: signal(true), isWide: signal(false), isCoarse: signal(true) },
  });
  const f = TestBed.createComponent(ReaderShellComponent);
  f.detectChanges();
  ctrl.match(() => true).forEach((req) =>
    req.flush({ subscriptions: [], tags: [], entries: [], favoritesCount: 0, keptCount: 0, nextCursor: null }),
  );
  f.detectChanges();

  const swipe = f.debugElement
    .query(By.directive(DrawerSwipeDirective))
    .injector.get(DrawerSwipeDirective);
  expect(swipe.disabled()).toBe(false); // narrow, no article, not organising
  f.componentInstance.sidebarOrganising.set(true);
  f.detectChanges();
  expect(swipe.disabled()).toBe(true);
});

it('resets organising when the drawer closes', () => {
  const f = TestBed.createComponent(ReaderShellComponent);
  f.detectChanges();
  ctrl.match(() => true).forEach((req) =>
    req.flush({ subscriptions: [], tags: [], entries: [], favoritesCount: 0, keptCount: 0, nextCursor: null }),
  );
  f.componentInstance.setSidebarOpen(true);
  f.componentInstance.sidebarOrganising.set(true);
  f.componentInstance.setSidebarOpen(false);
  expect(f.componentInstance.sidebarOrganising()).toBe(false);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `npx jest src/app/reader/reader-shell.component.spec.ts -t "organising"`
Expected: FAIL — `sidebarOrganising` does not exist.

- [ ] **Step 3: Implement**

In `reader-shell.component.ts`, next to `sidebarOpen`:

```ts
/** Mirror of the sidebar's Organise model. Owned here so closing the drawer
 *  can reset it, and so the close-swipe pauses while a drag is possible. */
readonly sidebarOrganising = signal(false);
```

In `setSidebarOpen`, reset on close (Organise is drawer-scoped state):

```ts
setSidebarOpen(open: boolean): void {
  this.headerHidden.set(open ? false : this.restingHeaderHidden());
  if (!open) this.sidebarOrganising.set(false);
  this.sidebarOpen.set(open);
}
```

In `reader-shell.component.html`: extend the swipe guard on `.body` —

```html
[appDrawerSwipeDisabled]="!screen.isNarrow() || articleFullscreen() || sidebarOrganising()"
```

— and bind the model on `<app-sidebar>` (first attribute line):

```html
<app-sidebar
  [(organising)]="sidebarOrganising"
  [tagTree]="subs.tagTree()"
  …
```

- [ ] **Step 4: Run the shell suite**

Run: `npx jest src/app/reader/reader-shell.component.spec.ts`
Expected: PASS (whole file).

- [ ] **Step 5: Commit**

```bash
git add src/app/reader/reader-shell.component.ts src/app/reader/reader-shell.component.html \
  src/app/reader/reader-shell.component.spec.ts
git commit -m "feat(#185): shell owns organise state; swipe-to-close pauses while organising"
```

---

### Task 7: Coarse-pointer density

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.scss`

**Interfaces:**
- Consumes: `--tap-target` (44px, `theme/tokens.scss`), the classes from Tasks 4–5.
- Produces: the 44px geometry Task 8's smoke asserts.

No unit test can see computed CSS in jsdom — the verification is Stylelint now and the Playwright bounding-box assertions in Task 8.

- [ ] **Step 1: Add the density block**

At the end of `sidebar.component.scss`:

```scss
/* Touch density: 44px targets for coarse pointers only (#185). Scoped here
   deliberately — the compact row tokens are shared by the discover rails and
   the admin catalog and must not grow (design-language §1). The desktop keeps
   compact rows; a coarse-pointer tablet gets this even in the column layout,
   which is the point of keying on pointer, not width. */
@media (pointer: coarse) {
  .nav,
  .rowbody,
  .feedname {
    min-height: var(--tap-target);
  }

  .act,
  .organise {
    min-height: var(--tap-target);
  }

  .dots {
    width: var(--tap-target);
    height: var(--tap-target);
    align-items: center;
    justify-content: center;
    padding: 0;
  }

  .pop button {
    min-height: var(--tap-target);
  }
}
```

(`.chevzone` and `.handle` already carry `--tap-target` unconditionally — they only render on coarse pointers. `.pop` is unreachable on coarse in the sidebar, but the rule is one line of safety for a fine-pointer touchscreen laptop.)

- [ ] **Step 2: Verify the linters accept it**

Run: `npm run check`
Expected: PASS — `pointer` is not a governed media feature; every size is a `var()`.

- [ ] **Step 3: Commit**

```bash
git add src/app/reader/sidebar/sidebar.component.scss
git commit -m "feat(#185): 44px sidebar touch targets under pointer: coarse"
```

---

### Task 8: Playwright mobile smoke

**Files:**
- Create: `frontend/e2e/sidebar-mobile.spec.ts`

**Interfaces:**
- Consumes: everything shipped in Tasks 1–7; the seeded e2e admin (`bin/console app:e2e:seed-admin`), same as `reader-smoke.spec.ts`.

Needs the Docker stack up (`docker compose up -d` at the repo root). Deliberately outside the CI gate like every other smoke; run it before the PR.

- [ ] **Step 1: Write the smoke**

`e2e/sidebar-mobile.spec.ts` — copy the sign-in helper and the entries-list route stub **verbatim** from `e2e/header-scroll-mobile.spec.ts` (it already fulfils GET `**/api/entries**` with the envelope the store expects), then:

```ts
// e2e/sidebar-mobile.spec.ts
import { test, expect, Page } from '@playwright/test';

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

// isMobile + hasTouch make `(pointer: coarse)` match, which is what switches
// the sidebar to touch density and reveals the Organise switch.
test.use({ viewport: { width: 375, height: 667 }, isMobile: true, hasTouch: true });

const NEWS_TAG = { id: 1, name: 'News', color: null, icon: null, position: 0 };
const SUBS = {
  favoritesCount: 0,
  keptCount: 0,
  subscriptions: [
    {
      id: 5, feedId: 55, title: 'The Verge', customTitle: null,
      lastFetchedAt: '2026-07-25T10:00:00Z', feedUrl: 'https://f/5', siteUrl: null,
      status: 'active', sourceFormat: 'xml', createdAt: 'x', position: 0,
      unreadCount: 3, tags: [NEWS_TAG],
    },
    {
      id: 6, feedId: 66, title: 'Daring Fireball', customTitle: null,
      lastFetchedAt: '2026-07-25T10:00:00Z', feedUrl: 'https://f/6', siteUrl: null,
      status: 'active', sourceFormat: 'xml', createdAt: 'x', position: 1,
      unreadCount: 1, tags: [],
    },
  ],
};

async function stubSidebarData(page: Page): Promise<void> {
  await page.route('**/api/subscriptions', (route) =>
    route.request().method() === 'GET'
      ? route.fulfill({ status: 200, json: SUBS })
      : route.continue(),
  );
  await page.route('**/api/tags', (route) =>
    route.request().method() === 'GET'
      ? route.fulfill({ status: 200, json: { tags: [NEWS_TAG] } })
      : route.continue(),
  );
  // + the entries-list stub copied from header-scroll-mobile.spec.ts
}

// + signInAsAdmin copied from header-scroll-mobile.spec.ts

test('drawer navigation, organise mode and the action sheet stay on-screen', async ({ page }) => {
  await stubSidebarData(page);
  test.skip(!(await signInAsAdmin(page)), 'seeded e2e admin not available');

  // Open the drawer.
  await page.getByRole('button', { name: 'Toggle sidebar' }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  await expect(sidebar).toBeVisible();

  // Navigation mode: 44px rows, chevron zone expands without navigating.
  const newsRow = sidebar.getByRole('link', { name: /News/ });
  expect((await newsRow.boundingBox())!.height).toBeGreaterThanOrEqual(44);
  await sidebar.getByRole('button', { name: 'Toggle News' }).click();
  await expect(sidebar.getByRole('link', { name: /The Verge/ })).toBeVisible();

  // Organise mode strips navigation down to the organisable structure.
  await sidebar.getByRole('switch', { name: 'Organise' }).click();
  await expect(sidebar.getByRole('link', { name: /All items/ })).toBeHidden();
  await expect(sidebar.getByRole('button', { name: 'Refresh' })).toBeHidden();

  // The row menu is a bottom sheet, fully inside the viewport.
  await sidebar.getByRole('button', { name: 'Manage News' }).click();
  const sheet = page.getByRole('menu', { name: 'News' });
  await expect(sheet).toBeVisible();
  const box = (await sheet.boundingBox())!;
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(375 + 1);
  expect(box.y + box.height).toBeLessThanOrEqual(667 + 1);
  await expect(sheet.getByRole('menuitem', { name: 'Delete tag' })).toBeVisible();

  // Backdrop dismisses; toggling Organise off restores navigation.
  await page.locator('.cdk-overlay-backdrop').click();
  await expect(sheet).toBeHidden();
  await sidebar.getByRole('switch', { name: 'Organise' }).click();
  await expect(sidebar.getByRole('link', { name: /All items/ })).toBeVisible();
});
```

If a locator misses, check the rendered accessible names first (`Toggle sidebar` / `Toggle News` / `Manage News` / `Organise` come from `reader.toggleSidebar`, `reader.toggleTag`, `reader.manage`, `reader.organise` in `public/i18n/en.json`) — fix the app or the locator, never loosen an assertion to a broader match that would pass against the old UI.

- [ ] **Step 2: Run it against the stack**

From the repo root: `docker compose up -d`, then from `frontend/`:

Run: `npm run e2e -- sidebar-mobile.spec.ts`
Expected: PASS. If the login times out, remember: a seeded admin with zero subscriptions plus a populated catalog redirects to onboarding — but the subscriptions stub above prevents that.

- [ ] **Step 3: Commit**

```bash
git add e2e/sidebar-mobile.spec.ts
git commit -m "test(#185): mobile smoke for drawer navigation, organise mode and the action sheet"
```

---

### Task 9: Full verification sweep

**Files:**
- Modify: `docs/design-language.md` (only if gaps emerge)

- [ ] **Step 1: The full frontend gate**

From `frontend/`:

Run: `npm run check`
Expected: PASS — ESLint, Prettier, Stylelint, and the whole Jest suite.

- [ ] **Step 2: Visual pass on the dev server**

Run `npm start` and open `http://localhost:4200` (the dev server talks to the API at `https://localhost:8443`, so the Docker stack must be up). In responsive dev-tools mode (mobile emulation on, touch enabled):

- Drawer rows and the chevron zone are 44px; the Organise switch knob lands flush right when checked (Task 4 Step 6's note — nudge the `translateX` if not).
- Organise on: actions, global views, view controls, trial all gone; handles and ⋯ present; drag by handle reorders; the sheet rises from the bottom edge.
- Desktop width + mouse: pixel-identical to `develop` (leading chevron, hover dots, `.pop`).

- [ ] **Step 3: Check the quality bars from the issue**

- [ ] 44px targets on touch — Task 7 + smoke assertion.
- [ ] No overlay leaves the viewport — sheet bounding-box assertion.
- [ ] One breakpoint source — Task 3 (grep `bp-md` in `reader-shell.component.scss` returns nothing).
- [ ] Tokens only — Stylelint green.
- [ ] `npm run check` green.
- [ ] Playwright smoke — Task 8.
- [ ] `docs/design-language.md` updated — Tasks 2, 3.

- [ ] **Step 4: Commit any stragglers and hand off**

```bash
git status   # should be clean; commit any doc fixes as docs(#185): …
```

Then use superpowers:finishing-a-development-branch — PR targets `develop`, body includes `Closes #185` (develop is the default branch, so the issue auto-closes on merge).
