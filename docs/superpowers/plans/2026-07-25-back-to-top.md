# Back to Top Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the entry list two ways back to the top — tapping the empty middle of the app bar on mobile, and a back-to-top button in the list's bottom-right corner, reusing the affordance the article view already has.

**Architecture:** The back-to-top circle becomes a shared presentational component (`app-to-top-button`) owning its look and fade-in; each consumer positions its own host element, which is what lets the article keep `position: fixed` while the list anchors to its own pane. `EntryListComponent` grows a public `scrollToTop()` that both new entry points call — the corner button directly, the app-bar tap via an output on `ReaderHeaderComponent` that `ReaderShellComponent` forwards through a `viewChild`.

**Tech Stack:** Angular 20 (standalone components, signals, `input()`/`output()`), Transloco for i18n, Jest + `jest-preset-angular` for unit tests, Playwright for e2e.

**Ticket:** [#95](https://github.com/larspohlmann/simple-feed-reader/issues/95)

**Branch:** `feature/95-back-to-top` (already created off `origin/develop`).

---

## File Structure

**Created:**

- `frontend/src/app/shared/to-top-button/to-top-button.component.ts` — the shared affordance: an accent circle with an up arrow, an `activate` output, and the exported `BACK_TO_TOP_AFTER_PX` threshold. No scrolling logic of its own; consumers decide what "top" means.
- `frontend/src/app/shared/to-top-button/to-top-button.component.html`
- `frontend/src/app/shared/to-top-button/to-top-button.component.scss` — circle, shadow, fade-in keyframes, reduced-motion opt-out. Positioning deliberately excluded.
- `frontend/src/app/shared/to-top-button/to-top-button.component.spec.ts`

**Modified:**

- `frontend/src/app/reader/reader-view/reader-view.component.{ts,html,scss,spec.ts}` — swap the inline `.to-top` button for the shared component; keep `position: fixed` by styling the host element. Behaviour and pixels unchanged.
- `frontend/src/app/reader/entry-list/entry-list.component.{ts,html,scss,spec.ts}` — a `showToTop` signal fed by the existing scroll handler, a public `scrollToTop()`, and the shared button anchored to the component's own box.
- `frontend/src/app/reader/header/reader-header.component.{ts,html,scss,spec.ts}` — the tappable middle spacer and a `scrollTop` output.
- `frontend/src/app/reader/reader-shell.component.{html,ts,spec.ts}` — forward the header's `scrollTop` to the mounted entry list.
- `frontend/e2e/header-scroll-mobile.spec.ts` — one phone-viewport test covering both entry points end to end.

**Not modified:** `public/i18n/{en,de}.json`. The corner `app-to-top-button` reuses the existing `reader.backToTop` key ("Back to top" / "Zurück nach oben"), which describes it exactly. This is a deliberate deviation from the ticket's sketch of a separate `reader.scrollToTop` key — a second key with identical text would be dead weight for translators. The app-bar spacer carries no label at all (see Task 4): it is `aria-hidden`, a pointer-only duplicate of the corner button, so the `reader.backToTop` decision applies to that one control only.

---

## Task 1: Extract the shared back-to-top button

Pure extraction. The article view must come out pixel- and behaviour-identical; the only visible change is which element carries the styles.

**Files:**

- Create: `frontend/src/app/shared/to-top-button/to-top-button.component.ts`
- Create: `frontend/src/app/shared/to-top-button/to-top-button.component.html`
- Create: `frontend/src/app/shared/to-top-button/to-top-button.component.scss`
- Test: `frontend/src/app/shared/to-top-button/to-top-button.component.spec.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts` (lines 49-50, imports, `@Component` imports array)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.html` (lines 147-156)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.scss` (lines 46-78)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts` (lines 120-152)

All commands below run from `frontend/`.

- [ ] **Step 1: Write the failing test for the shared component**

Create `frontend/src/app/shared/to-top-button/to-top-button.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { ToTopButtonComponent } from './to-top-button.component';

describe('ToTopButtonComponent', () => {
  function mount() {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [ToTopButtonComponent, provideTranslocoTesting()],
    });
    const f = TestBed.createComponent(ToTopButtonComponent);
    f.detectChanges();
    return f;
  }

  it('renders a labelled button with the up arrow', () => {
    const el = mount().nativeElement as HTMLElement;
    const btn = el.querySelector('button') as HTMLButtonElement;
    expect(btn.getAttribute('aria-label')).toBe('Back to top');
    expect(btn.getAttribute('type')).toBe('button');
    expect(el.querySelector('app-icon')).not.toBeNull();
  });

  it('emits activate when clicked', () => {
    const f = mount();
    const fired = jest.fn();
    f.componentInstance.activate.subscribe(fired);
    (f.nativeElement as HTMLElement).querySelector('button')!.click();
    expect(fired).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx jest src/app/shared/to-top-button --silent`
Expected: FAIL — `Cannot find module './to-top-button.component'`.

- [ ] **Step 3: Write the component**

Create `frontend/src/app/shared/to-top-button/to-top-button.component.ts`:

```ts
// src/app/shared/to-top-button/to-top-button.component.ts
import { Component, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../icon/icon.component';

/** How far a scroller must travel before the back-to-top button appears. */
export const BACK_TO_TOP_AFTER_PX = 500;

/**
 * The back-to-top circle, shared by the article view and the entry list. Purely
 * presentational: it reports a click and nothing else, because "the top" means a
 * different scroller in each place.
 *
 * Placement is the consumer's job too — the article pins its copy to the viewport
 * (`position: fixed`), the list to its own pane — so this stylesheet defines the
 * button's appearance and leaves the host element's offsets alone.
 */
@Component({
  selector: 'app-to-top-button',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './to-top-button.component.html',
  styleUrl: './to-top-button.component.scss',
})
export class ToTopButtonComponent {
  readonly activate = output<void>();
}
```

Create `frontend/src/app/shared/to-top-button/to-top-button.component.html`:

```html
<button type="button" [attr.aria-label]="'reader.backToTop' | transloco" (click)="activate.emit()">
  <app-icon name="arrow_upward" [size]="20" />
</button>
```

Create `frontend/src/app/shared/to-top-button/to-top-button.component.scss`:

```scss
/* Out of flow by default so it can hang in a corner; the consumer supplies the
   offsets, the stacking order, and whether it pins to the viewport instead. */
:host {
  position: absolute;
  display: inline-flex;
}

button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border: none;
  border-radius: 50%;
  background: var(--accent);
  color: var(--on-accent);
  cursor: pointer;
  box-shadow: 0 4px 14px rgb(0 0 0 / 25%);
  animation: to-top-in 0.15s ease-out;
}

@keyframes to-top-in {
  from {
    opacity: 0;
    transform: translateY(6px);
  }
}

@media (prefers-reduced-motion: reduce) {
  button {
    animation: none;
  }
}
```

- [ ] **Step 4: Run the test again**

Run: `npx jest src/app/shared/to-top-button --silent`
Expected: PASS, 2 tests.

- [ ] **Step 5: Point the article view's spec at the new element**

In `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`, replace all four `'.to-top'` selectors with `'app-to-top-button'`, and take the button from inside it. The `describe('back-to-top button')` block becomes:

```ts
  describe('back-to-top button', () => {
    function scrollHostTo(host: HTMLElement, top: number): void {
      Object.defineProperty(host, 'scrollTop', { configurable: true, value: top });
      host.dispatchEvent(new Event('scroll'));
    }

    it('appears only after scrolling down and jumps back to the top on click', () => {
      const f = mount(entry());
      const host = f.nativeElement as HTMLElement;
      expect(host.querySelector('app-to-top-button')).toBeNull(); // hidden at the top

      scrollHostTo(host, 900);
      f.detectChanges();
      const btn = host.querySelector('app-to-top-button button') as HTMLButtonElement;
      expect(btn).not.toBeNull();

      const scrollTo = jest.fn();
      host.scrollTo = scrollTo as unknown as typeof host.scrollTo;
      btn.click();
      expect(scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 0 }));
    });

    it('hides again when scrolled back near the top', () => {
      const f = mount(entry());
      const host = f.nativeElement as HTMLElement;
      scrollHostTo(host, 900);
      f.detectChanges();
      expect(host.querySelector('app-to-top-button')).not.toBeNull();

      scrollHostTo(host, 100);
      f.detectChanges();
      expect(host.querySelector('app-to-top-button')).toBeNull();
    });
  });
```

- [ ] **Step 6: Run it and watch it fail**

Run: `npx jest src/app/reader/reader-view --silent`
Expected: FAIL — the spec now looks for `app-to-top-button`, which the article view does not render yet.

- [ ] **Step 7: Switch the article view to the shared component**

In `frontend/src/app/reader/reader-view/reader-view.component.ts`:

Delete the local threshold constant (lines 49-50):

```ts
/** How far the reader must scroll before the back-to-top button appears. */
const BACK_TO_TOP_AFTER_PX = 500;
```

Add the import next to the other shared-component imports (after the `SpinnerComponent` import on line 19):

```ts
import {
  BACK_TO_TOP_AFTER_PX,
  ToTopButtonComponent,
} from '../../shared/to-top-button/to-top-button.component';
```

Add `ToTopButtonComponent` to the `@Component({ imports: [...] })` array.

In `frontend/src/app/reader/reader-view/reader-view.component.html`, replace lines 147-156:

```html
  @if (showToTop()) {
    <button
      class="to-top"
      type="button"
      [attr.aria-label]="'reader.backToTop' | transloco"
      (click)="scrollToTop()"
    >
      <app-icon name="arrow_upward" [size]="20" />
    </button>
  }
```

with:

```html
  @if (showToTop()) {
    <app-to-top-button (activate)="scrollToTop()" />
  }
```

In `frontend/src/app/reader/reader-view/reader-view.component.scss`, replace lines 46-78 (the `.to-top` rule, the `to-top-in` keyframes and the reduced-motion block — all three now live in the shared stylesheet) with:

```scss
/* Back-to-top affordance, pinned to the viewport corner (fixed, so it sits
         above the transformed .reader rather than scrolling with the article). */
app-to-top-button {
  position: fixed;
  right: 24px;
  bottom: 24px;
  z-index: 20;
}
```

- [ ] **Step 8: Run the article view's tests**

Run: `npx jest src/app/reader/reader-view --silent`
Expected: PASS — the whole file, not just the back-to-top block.

- [ ] **Step 9: Run the full unit suite to catch anything else that referenced `.to-top`**

Run: `npx jest --silent`
Expected: PASS, no failures.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/shared/to-top-button frontend/src/app/reader/reader-view
git commit -m "refactor(reader): extract the back-to-top button into a shared component (#95)"
```

---

## Task 2: `scrollToTop()` and the corner button on the entry list

**Files:**

- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts` (imports, `_resetCollapse` effect at 153-158, `onRowsScroll` at 160-171, new method after it)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html` (append at end of file)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss` (append at end of file)
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`

Note on the scroll container: `#rows` appears in two template branches (flat list and magazine) but only one renders at a time, so `this.rows()` resolves to whichever is live. `scrollToTop()` must therefore read it at call time rather than caching it.

- [ ] **Step 1: Write the failing tests**

Append to the top-level `describe('EntryListComponent', ...)` in `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`:

```ts
  describe('back to top', () => {
    /** Stub the scroller: jsdom implements neither scrollTo nor real scrolling. */
    function stubScroller(f: ReturnType<typeof mount>) {
      const rows = (f.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      const scrollTo = jest.fn();
      rows.scrollTo = scrollTo as unknown as typeof rows.scrollTo;
      return { rows, scrollTo };
    }

    it('shows the button only once the list is scrolled well down', () => {
      const f = mount();
      const el = f.nativeElement as HTMLElement;
      expect(el.querySelector('app-to-top-button')).toBeNull();

      f.componentInstance.onRowsScroll({ target: { scrollTop: 900 } } as unknown as Event);
      f.detectChanges();
      expect(el.querySelector('app-to-top-button')).not.toBeNull();

      f.componentInstance.onRowsScroll({ target: { scrollTop: 100 } } as unknown as Event);
      f.detectChanges();
      expect(el.querySelector('app-to-top-button')).toBeNull();
    });

    it('scrolls the container to the top, expands the bar and forgets the offset', () => {
      const f = mount();
      const { scrollTo } = stubScroller(f);
      f.componentInstance.collapsed.set(true);
      memory.save.mockClear();

      f.componentInstance.scrollToTop();

      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
      expect(f.componentInstance.collapsed()).toBe(false);
      expect(memory.save).toHaveBeenCalledWith({ kind: 'all', id: null, unread: true }, 0);
    });

    it('clicking the button scrolls to the top', () => {
      const f = mount();
      const { scrollTo } = stubScroller(f);
      f.componentInstance.onRowsScroll({ target: { scrollTop: 900 } } as unknown as Event);
      f.detectChanges();

      (
        (f.nativeElement as HTMLElement).querySelector(
          'app-to-top-button button',
        ) as HTMLButtonElement
      ).click();
      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    it('scrolls the magazine layout’s own container too', () => {
      // The magazine branch renders a different #rows element; scrollToTop has to
      // resolve the live one at call time rather than caching it.
      const f = mount({ layout: 'magazine' });
      const rows = (f.nativeElement as HTMLElement).querySelector('.rows.magazine') as HTMLElement;
      const scrollTo = jest.fn();
      rows.scrollTo = scrollTo as unknown as typeof rows.scrollTo;

      f.componentInstance.scrollToTop();
      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    it('hides the button again when the selection changes', () => {
      const f = mount();
      f.componentInstance.onRowsScroll({ target: { scrollTop: 900 } } as unknown as Event);
      f.detectChanges();
      expect((f.nativeElement as HTMLElement).querySelector('app-to-top-button')).not.toBeNull();

      f.componentRef.setInput('selection', { kind: 'tag', id: 3, unread: true });
      f.detectChanges();
      expect(f.componentInstance.showToTop()).toBe(false);
    });
  });
```

The reduced-motion path is covered by the dedicated spec in Task 3, which needs a different `matchMedia` stub in place before the component is constructed.

- [ ] **Step 2: Run them and watch them fail**

Run: `npx jest src/app/reader/entry-list --silent`
Expected: FAIL — `showToTop` and `scrollToTop` do not exist, and no `app-to-top-button` is rendered.

- [ ] **Step 3: Implement**

In `frontend/src/app/reader/entry-list/entry-list.component.ts`, add the import alongside the other shared-component imports (after the `SpinnerComponent` import on line 17):

```ts
import {
  BACK_TO_TOP_AFTER_PX,
  ToTopButtonComponent,
} from '../../shared/to-top-button/to-top-button.component';
```

Add `ToTopButtonComponent` to the `@Component({ imports: [...] })` array.

Declare the signal next to `collapsed` (after line 127, `private lastScrollTop = 0;`):

```ts
  /** Drives the corner back-to-top button; set from the scroll handler. */
  readonly showToTop = signal(false);
```

Extend the `_resetCollapse` effect (lines 153-158) so a new selection also drops the button — the list reloads from the top:

```ts
  // A new selection reloads the list from the top, and a resize past the wide
  // breakpoint restores the full-size header — expand the bar in both cases.
  private readonly _resetCollapse = effect(() => {
    this.selection();
    this.screen.isWide();
    this.collapsed.set(false);
    this.showToTop.set(false);
    this.lastScrollTop = 0;
  });
```

Add the threshold check to `onRowsScroll` (after the `collapsed.set(...)` call on lines 164-166):

```ts
    this.showToTop.set(top > BACK_TO_TOP_AFTER_PX);
```

Add the public method immediately after `onRowsScroll` (i.e. after line 171):

```ts
  /**
   * Jump the list back to the top. Shared by the corner button and by the tap on
   * the empty middle of the app bar.
   */
  scrollToTop(): void {
    const el = this.rows()?.nativeElement;
    if (!el) return;
    // A scroll restore in flight re-asserts its own target every frame; the
    // user's jump has to win.
    this.cancelSettle();
    el.scrollTo({ top: 0, behavior: this.reduceMotion ? 'auto' : 'smooth' });
    // Say the bar is expanded now rather than waiting for the scroll events: a
    // smooth scroll may not deliver one close enough to 0 to trigger it.
    this.collapsed.set(false);
    // `lastScrollTop` deliberately keeps its pre-jump value. Zeroing it would
    // make the smooth scroll's own first event (still far down the list) read
    // as a large scroll *down* and immediately re-collapse the bar.
    // `showToTop` is likewise left to the scroll events: clearing it here would
    // only make the button blink out and back in as the animation passes the
    // threshold, which is how the article view behaves too.
    this.scroll.save(this.selection(), 0);
  }
```

Note that `rows` is declared at line 195, *below* this method. That is fine — it is a class field read at call time, matching how `onPullStart` and the effects already use it.

In `frontend/src/app/reader/entry-list/entry-list.component.html`, append at the very end of the file:

```html
@if (showToTop()) {
  <app-to-top-button (activate)="scrollToTop()" />
}
```

In `frontend/src/app/reader/entry-list/entry-list.component.scss`, append at the very end of the file:

```scss
/* Anchored to this component's own box (`:host` is already relative), not to the
   viewport: in split-pane mode a fixed button would hang over the reader pane
   instead of the list it scrolls. Above the floating list header (z-index 3). */
app-to-top-button {
  right: 24px;
  bottom: 24px;
  z-index: 4;
}
```

- [ ] **Step 4: Run the tests**

Run: `npx jest src/app/reader/entry-list --silent`
Expected: PASS, including the four new tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/entry-list
git commit -m "feat(reader): add a back-to-top button to the entry list (#95)"
```

---

## Task 3: Reduced-motion path

Separate task because the flag is read once at construction time (`entry-list.component.ts:101`), so the `matchMedia` stub has to be swapped before the component is created — which means its own spec file rather than a case inside the existing one.

**Files:**

- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts` (new `describe` block at the top level, with its own `matchMedia` override)

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`, after the `describe('back to top', ...)` block:

```ts
  describe('back to top under prefers-reduced-motion', () => {
    const realMatchMedia = window.matchMedia;

    afterEach(() => {
      Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: realMatchMedia,
      });
    });

    it('jumps instead of animating', () => {
      // The component reads the flag once, in a field initialiser — so the stub
      // has to be in place before mount(), not before the click.
      Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: (query: string) => ({
          matches: query.includes('prefers-reduced-motion'),
          media: query,
          onchange: null,
          addEventListener: () => undefined,
          removeEventListener: () => undefined,
          addListener: () => undefined,
          removeListener: () => undefined,
          dispatchEvent: () => false,
        }),
      });

      const f = mount();
      const rows = (f.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      const scrollTo = jest.fn();
      rows.scrollTo = scrollTo as unknown as typeof rows.scrollTo;

      f.componentInstance.scrollToTop();
      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
    });
  });
```

- [ ] **Step 2: Run it**

Run: `npx jest src/app/reader/entry-list --silent -t 'prefers-reduced-motion'`
Expected: PASS — Task 2 already implemented the branch. If it FAILS with `behavior: 'smooth'`, the `reduceMotion` field is not being consulted; fix `scrollToTop()` before continuing.

- [ ] **Step 3: Run the whole file to confirm the stub is properly restored**

Run: `npx jest src/app/reader/entry-list --silent`
Expected: PASS — every test, in file order. A leaked `matchMedia` stub would break the tests that follow.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/app/reader/entry-list/entry-list.component.spec.ts
git commit -m "test(reader): cover the reduced-motion back-to-top path (#95)"
```

---

## Task 4: The tappable middle of the app bar

**Files:**

- Modify: `frontend/src/app/reader/header/reader-header.component.ts`
- Modify: `frontend/src/app/reader/header/reader-header.component.html` (between `.left` and `.right`, i.e. after line 24)
- Modify: `frontend/src/app/reader/header/reader-header.component.scss`
- Test: `frontend/src/app/reader/header/reader-header.component.spec.ts`

The narrow gate uses `LayoutService.isNarrow()`, whose `(max-width: 720px)` query is the same breakpoint the bar's mobile styles already use. In tests the global `matchMedia` stub reports `matches: false` for everything, so the real service would always say "wide" — the spec provides a stub with plain signals instead.

- [ ] **Step 1: Write the failing tests**

In `frontend/src/app/reader/header/reader-header.component.spec.ts`, add the import and a controllable layout stub to the existing setup:

```ts
import { LayoutService } from '../layout.service';
```

Inside `describe('ReaderHeaderComponent', ...)`, next to the existing `auth` stub:

```ts
  const layout = { isWide: signal(false), isNarrow: signal(true) } satisfies Pick<
    LayoutService,
    'isWide' | 'isNarrow'
  >;
```

Typed with `satisfies Pick<LayoutService, 'isWide' | 'isNarrow'>` so a rename on `LayoutService` fails the build instead of surfacing as a runtime `undefined is not a function`.

Add it to the `providers` array in `beforeEach`, and reset it there so cases cannot leak into each other:

```ts
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: auth },
        { provide: LayoutService, useValue: layout },
      ],
```

and, as the first statements of `beforeEach` alongside `localStorage.clear()`:

```ts
    layout.isNarrow.set(true);
    layout.isWide.set(false);
```

Then append these tests to the describe block:

```ts
  describe('tap the empty middle to scroll the list to the top', () => {
    it('emits scrollTop when the middle of the bar is tapped on mobile', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;
      const fired = jest.fn();
      f.componentInstance.scrollTop.subscribe(fired);

      const spacer = el.querySelector('.tap-to-top') as HTMLButtonElement;
      expect(spacer).not.toBeNull();
      expect(spacer.getAttribute('aria-hidden')).toBe('true');
      expect(spacer.getAttribute('tabindex')).toBe('-1');
      spacer.click();
      expect(fired).toHaveBeenCalledTimes(1);
    });

    it('does not fire when the controls beside it are tapped', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;
      const fired = jest.fn();
      f.componentInstance.scrollTop.subscribe(fired);

      (el.querySelector('.menu-btn') as HTMLButtonElement).click();
      (el.querySelector('[aria-haspopup="menu"]') as HTMLButtonElement).click();
      expect(fired).not.toHaveBeenCalled();
    });

    it('is absent on a wide layout', () => {
      layout.isNarrow.set(false);
      const el = create().nativeElement as HTMLElement;
      expect(el.querySelector('.tap-to-top')).toBeNull();
    });

    it('is absent while an article is open', () => {
      const f = create();
      f.componentRef.setInput('articleOpen', true);
      f.detectChanges();
      expect((f.nativeElement as HTMLElement).querySelector('.tap-to-top')).toBeNull();
    });
  });
```

- [ ] **Step 2: Run them and watch them fail**

Run: `npx jest src/app/reader/header --silent`
Expected: FAIL — `scrollTop` is not a property of the component and `.tap-to-top` does not exist.

- [ ] **Step 3: Implement**

In `frontend/src/app/reader/header/reader-header.component.ts`, add the import:

```ts
import { LayoutService } from '../layout.service';
```

Add the output next to the existing ones (after line 32, `readonly next = output<void>();`):

```ts
  /** The empty middle of the bar was tapped — scroll the list back to the top. */
  readonly scrollTop = output<void>();
```

Add the injection next to the other injected services (after line 36):

```ts
  readonly screen = inject(LayoutService);
```

In `frontend/src/app/reader/header/reader-header.component.html`, insert between the closing `</div>` of `.left` (line 24) and the opening `<div class="right">` (line 25):

```html
  <!-- Pointer-only duplicate of the corner back-to-top button (iOS status-bar-tap
       convention). Kept out of both the accessibility tree and the tab order:
       keyboard and screen-reader users already have the corner button, which is
       visible and labelled, so this element would only add a second, identically
       named, invisible control between the brand link and the account button. -->
  @if (!articleOpen() && screen.isNarrow()) {
    <button
      class="tap-to-top"
      type="button"
      aria-hidden="true"
      tabindex="-1"
      (click)="scrollTop.emit()"
    ></button>
  }
```

The spacer carries no `aria-label` and no `transloco` binding — it is `aria-hidden`, so a label would never be read anyway. `reader.backToTop` remains reused, but only by the corner `app-to-top-button`. Both `aria-hidden="true"` and `tabindex="-1"` are required together: an `aria-hidden` element that is still focusable is an `aria-hidden-focus` violation.

In `frontend/src/app/reader/header/reader-header.component.scss`, add after the `.brand .name` rule (line 87):

```scss
/* The empty middle of the bar, made tappable — the mobile convention for "back
   to top". A dedicated element rather than a click handler on the bar itself,
   so a tap that lands on the padding beside the menu button or the avatar still
   does nothing. Mobile only, and never while an article is open: there, the
   entry list is not the visible scroller, so scrolling it to the top would do
   nothing observable. */
.tap-to-top {
  flex: 1;
  align-self: stretch;
  padding: 0;
  background: none;
  border: none;
  cursor: pointer;

  /* A ~190×55px target would otherwise flash the default translucent tap
     highlight across the middle of the bar on iOS Safari / Android Chrome —
     visible on an element meant to be invisible. No other button in src/ needs
     this because every other one is icon-sized. */
  -webkit-tap-highlight-color: transparent;
}
```

- [ ] **Step 4: Run the tests**

Run: `npx jest src/app/reader/header --silent`
Expected: PASS — the three new tests plus every pre-existing one in the file.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/header
git commit -m "feat(reader): make the empty middle of the mobile app bar scroll the list to the top (#95)"
```

---

## Task 5: Wire the header's tap through the shell

**Files:**

- Modify: `frontend/src/app/reader/reader-shell.component.ts` (near the `hdr` viewChild at line 124)
- Modify: `frontend/src/app/reader/reader-shell.component.html` (the `app-reader-header` bindings, lines 1-12)
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`

Both template branches render an `app-entry-list`, but only one is live at a time, so a single `viewChild(EntryListComponent)` resolves whichever is mounted.

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/app/reader/reader-shell.component.spec.ts`, inside the top-level `describe('ReaderShellComponent', ...)`. It uses the file's existing `boot()` helper, which flushes the subscriptions/tags/entries requests and leaves one entry rendered — so an `app-entry-list` is mounted:

```ts
  it('forwards the header tap to the entry list', () => {
    const f = boot();
    const list = f.debugElement.query(By.directive(EntryListComponent))
      .componentInstance as EntryListComponent;
    const jump = jest.spyOn(list, 'scrollToTop').mockImplementation(() => undefined);

    const header = f.debugElement.query(By.directive(ReaderHeaderComponent))
      .componentInstance as ReaderHeaderComponent;
    header.scrollTop.emit();

    expect(jump).toHaveBeenCalledTimes(1);
  });
```

Add these imports at the top of the file:

```ts
import { By } from '@angular/platform-browser';
import { EntryListComponent } from './entry-list/entry-list.component';
import { ReaderHeaderComponent } from './header/reader-header.component';
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx jest src/app/reader/reader-shell --silent`
Expected: FAIL — the header's `scrollTop` output is not bound, so `scrollToTop` is never called.

- [ ] **Step 3: Implement**

In `frontend/src/app/reader/reader-shell.component.ts`, add next to the `hdr` viewChild (line 124):

```ts
  /** Only one of the two template branches renders a list at a time. */
  private readonly list = viewChild(EntryListComponent);
```

And a handler, next to `setSidebarOpen` (after line 258):

```ts
  /** The top bar's empty middle was tapped: send the list back to the top. */
  onScrollListTop(): void {
    this.list()?.scrollToTop();
  }
```

In `frontend/src/app/reader/reader-shell.component.html`, add the binding to `app-reader-header` (after the `(next)` binding on line 11):

```html
  (scrollTop)="onScrollListTop()"
```

- [ ] **Step 4: Run the test**

Run: `npx jest src/app/reader/reader-shell --silent`
Expected: PASS.

- [ ] **Step 5: Run the full unit suite**

Run: `npx jest --silent`
Expected: PASS, no failures.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.html frontend/src/app/reader/reader-shell.component.spec.ts
git commit -m "feat(reader): forward the app-bar tap to the entry list (#95)"
```

---

## Task 6: End-to-end coverage on a phone viewport

Adds to the existing phone-viewport file, which already stubs 30 entries, pins the flat list layout and signs in as the seeded admin. Reuse its helpers (`signInAsAdmin`, `stubEntries`, `settle`, `ROWS`, `PHONE`) — do not duplicate them.

Per the project's e2e convention, these run against the Docker stack, not a local `ng serve`.

**Files:**

- Modify: `frontend/e2e/header-scroll-mobile.spec.ts` (append inside the existing `test.describe('Hide-on-scroll header on a phone', ...)`)

- [ ] **Step 1: Write the test**

```ts
  test('the bar’s empty middle and the corner button both return the list to the top', async ({
    page,
  }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();

    const rows = page.locator(ROWS);
    await expect(rows).toBeVisible();
    await settle(page);

    // Well past the 500px threshold, so the corner button is showing.
    await rows.evaluate((el) => el.scrollTo({ top: 1500 }));
    await page.waitForTimeout(400);
    expect(await rows.evaluate((el) => el.scrollTop)).toBeGreaterThan(1000);

    const corner = page.locator('app-entry-list app-to-top-button');
    await expect(corner).toBeVisible();
    await corner.click();
    await expect.poll(() => rows.evaluate((el) => el.scrollTop)).toBe(0);
    await expect(corner).toBeHidden();

    // And again via the empty middle of the app bar. Scrolling down retracts the
    // bar, so scroll back up a little first to bring it into reach — exactly what
    // the user does.
    await rows.evaluate((el) => el.scrollTo({ top: 1500 }));
    await page.waitForTimeout(400);
    await rows.evaluate((el) => el.scrollBy(0, -100));
    await page.waitForTimeout(400);

    await page.locator('app-reader-header .tap-to-top').click();
    await expect.poll(() => rows.evaluate((el) => el.scrollTop)).toBe(0);
  });
```

- [ ] **Step 2: Bring up the stack and run it**

```bash
docker compose up -d
```

Then, from `frontend/`:

```bash
npx playwright test e2e/header-scroll-mobile.spec.ts
```

Expected: PASS, all four tests in the file. A `test.skip` on the seeded admin means the stack is up but unseeded — run `docker compose exec php bin/console app:e2e:seed-admin` and retry rather than treating a skip as a pass.

- [ ] **Step 3: Check the backend log for anything the run stirred up**

```bash
docker compose exec php tail -n 50 var/log/dev.log
```

Expected: no new deprecations or errors attributable to this run.

- [ ] **Step 4: Commit**

```bash
git add frontend/e2e/header-scroll-mobile.spec.ts
git commit -m "test(e2e): cover both back-to-top paths on a phone (#95)"
```

---

## Task 7: Quality gate and pull request

- [ ] **Step 1: Run the full frontend gate**

From `frontend/`:

```bash
npm run check
```

Expected: PASS for lint, `format:check`, stylelint and Jest. If `format:check` fails, run `npm run format` and amend the affected commit rather than adding a formatting-only commit.

- [ ] **Step 2: Verify the article view is visually unchanged**

The extraction in Task 1 is the one change that could regress something the tests do not assert. With the stack up, open an article on a phone viewport, scroll past 500px, and confirm the button sits in the same place with the same size, colour and fade-in — and that it still floats above the article rather than scrolling with it.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feature/95-back-to-top
```

```bash
gh pr create --base develop --title "feat(reader): two ways back to the top of the entry list (#95)" --body "Closes #95

- Extracts the article view's back-to-top button into a shared \`app-to-top-button\`; each consumer positions its own host, so the article keeps its viewport-fixed placement and the list anchors to its own pane.
- Adds that button to the entry list, appearing past 500px of scroll.
- Makes the empty middle of the app bar tappable on mobile (narrow layout, no article open) to send the list back to the top.
- Smooth scroll, instant under \`prefers-reduced-motion\`; the remembered scroll offset resets to 0 so a resume-reload also lands at the top."
```

- [ ] **Step 4: Close the ticket once the PR merges**

PRs merge into `develop`, so GitHub will not auto-close the issue. After the merge:

```bash
gh issue close 95 --comment "Shipped to develop."
```

---

## Acceptance criteria (from the ticket)

Check each off against the built app before opening the PR:

- [ ] Narrow viewport, list view: tapping the empty middle of the top bar scrolls the entry list to the top.
- [ ] That tap does nothing when an article is open, or on a wide viewport (the element is not rendered).
- [ ] Taps on the menu button, brand link and account button are unaffected.
- [ ] A back-to-top button appears in the list's bottom-right past 500px of scroll and scrolls it to the top.
- [ ] In split-pane mode that button sits in the list pane's corner, not over the reader pane.
- [ ] The article view's button is unchanged in look, placement and behaviour.
- [ ] Both paths work in the list and magazine layouts.
- [ ] The list header expands again once back at the top.
- [ ] Under `prefers-reduced-motion` the scroll is instant and the button does not animate in.
- [ ] The remembered scroll offset resets to 0, so a resume-reload also lands at the top.
- [ ] Unit tests cover the header spacer's `aria-hidden`/`tabindex` and emit, both absence cases, that taps beside it (menu button, account button) do not fire it, `scrollToTop()`'s scroll and collapse reset, the `showToTop` threshold, and the reduced-motion branch.
