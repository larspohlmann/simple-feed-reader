# Pull-to-Refresh Reveal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a refresh open a gap at the top of the entry list that shows a spinner and a "Refreshing…" label, hold it open while the refresh runs, and snap it shut when the refresh finishes — driven by the mobile pull gesture and by both Refresh buttons (list header + sidebar).

**Architecture:** A single `revealOffset` computed signal on `EntryListComponent` translates the `#rows` scroller and a reveal tray downward. It reads the finger during an active drag, a fixed `REFRESH_REVEAL` while `refreshing()` is true (any trigger sets that), and 0 otherwise; a CSS `transition` on the transform produces the snap-back and the button-driven slide, disabled during the live drag so it tracks 1:1. Reduced motion returns 0, suppressing the reveal entirely.

**Tech Stack:** Angular 20 standalone component with signals, Transloco i18n, component-scoped SCSS with design tokens, Jest (jsdom) unit tests.

**Reference spec:** [docs/superpowers/specs/2026-07-28-pull-to-refresh-reveal-design.md](../specs/2026-07-28-pull-to-refresh-reveal-design.md)

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `frontend/public/i18n/en.json` | English strings | Add `reader.refreshing` |
| `frontend/public/i18n/de.json` | German strings | Add `reader.refreshing` |
| `frontend/src/app/reader/entry-list/entry-list.component.ts` | List component logic + gesture wiring | Replace the `pull` signal with `revealOffset` + `dragging`; publish `--refresh-reveal`; add `REFRESH_REVEAL` |
| `frontend/src/app/reader/entry-list/entry-list.component.html` | List template | Turn the chip into a pill tray with a label; translate the `#rows` scroller |
| `frontend/src/app/reader/entry-list/entry-list.component.scss` | List styles | Pill tray, scroller transform transition, reduced-motion off-switch |
| `frontend/src/app/reader/entry-list/entry-list.component.spec.ts` | Unit tests | New reveal tests; existing pull tests stay green |

All work is on branch `feature/158-pull-refresh-reveal` (already created off `develop`). Run all commands from `frontend/`.

---

### Task 1: Add the "Refreshing…" i18n string

**Files:**
- Modify: `frontend/public/i18n/en.json:198`
- Modify: `frontend/public/i18n/de.json:198`

- [ ] **Step 1: Add the English key**

In `frontend/public/i18n/en.json`, the `reader` block has `"refresh": "Refresh",` on line 198. Insert a new line directly after it:

```json
    "refresh": "Refresh",
    "refreshing": "Refreshing…",
```

- [ ] **Step 2: Add the German key**

In `frontend/public/i18n/de.json`, the `reader` block has `"refresh": "Aktualisieren",` on line 198. Insert a new line directly after it:

```json
    "refresh": "Aktualisieren",
    "refreshing": "Aktualisiere…",
```

- [ ] **Step 3: Verify both files are still valid JSON**

Run: `node -e "JSON.parse(require('fs').readFileSync('public/i18n/en.json')); JSON.parse(require('fs').readFileSync('public/i18n/de.json')); console.log('ok')"`
Expected: `ok`

- [ ] **Step 4: Commit**

```bash
git add public/i18n/en.json public/i18n/de.json
git commit -m "i18n(reader): add reader.refreshing string (#158)"
```

---

### Task 2: Reveal behaviour — signals, template, styles

Do the whole task test-first: write the new tests, watch them fail, then change the component, template and styles together (they are one tightly-coupled unit) and watch everything go green.

**Files:**
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss`

- [ ] **Step 1: Write the failing tests**

In `entry-list.component.spec.ts`, change the component import on line 5 to also import the constant:

```ts
import { EntryListComponent, REFRESH_REVEAL } from './entry-list.component';
```

Inside the existing `describe('pull-to-refresh (mobile)', ...)` block, after the `it('ignores the gesture in the cross-feed saved views', ...)` case, add:

```ts
    it('shows the spinner but no label during the pull (the label is for the running refresh)', () => {
      const f = mount();
      pullBy(f, 140, false);
      const chip = (f.nativeElement as HTMLElement).querySelector('.pull-indicator')!;
      expect(chip).not.toBeNull();
      expect(chip.querySelector('.label')).toBeNull();
    });
```

Then, as a new top-level block just before the final closing `});` of the outer `describe('EntryListComponent', ...)`, add:

```ts
  describe('refresh reveal', () => {
    it('holds the reveal open while a refresh runs and closes it after', () => {
      const f = mount({ refreshing: true });
      expect(f.componentInstance.revealOffset()).toBe(REFRESH_REVEAL);

      f.componentRef.setInput('refreshing', false);
      f.detectChanges();
      expect(f.componentInstance.revealOffset()).toBe(0);
    });

    it('opens the reveal from a button refresh with no pull, and labels it', () => {
      // The list-header button and the sidebar button both just flip refreshing();
      // the reveal reads that, not the gesture, so no pull is involved here.
      const el = mount({ refreshing: true }).nativeElement as HTMLElement;
      expect(el.querySelector('.pull-indicator')).not.toBeNull();
      expect(el.querySelector('.pull-indicator .label')).not.toBeNull();
    });
  });

  describe('refresh reveal under prefers-reduced-motion', () => {
    const realMatchMedia = window.matchMedia;

    beforeEach(() => {
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
    });

    afterEach(() => {
      Object.defineProperty(window, 'matchMedia', { writable: true, value: realMatchMedia });
    });

    it('does not reveal, even while refreshing', () => {
      const f = mount({ refreshing: true });
      expect(f.componentInstance.revealOffset()).toBe(0);
      expect((f.nativeElement as HTMLElement).querySelector('.pull-indicator')).toBeNull();
    });
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx jest entry-list --silent`
Expected: FAIL — the new cases reference `REFRESH_REVEAL` (not exported yet) and `revealOffset` (does not exist), so compilation fails.

- [ ] **Step 3: Rework the component signals and gesture handlers**

In `entry-list.component.ts`:

**(a)** Add the reveal constant next to `MAX_PULL` (around line 51):

```ts
// Ceiling the rubber-banded pull-to-refresh indicator approaches but never reaches.
const MAX_PULL = 100;
// How far (px) the content slides to reveal the spinner while a refresh runs — the
// held-open offset shared by the mobile pull and the header/sidebar Refresh buttons.
// Matches --space-7; published as --refresh-reveal so the stylesheet sizes the tray
// and its park offset from the same number.
export const REFRESH_REVEAL = 48;
```

**(b)** Replace the `pull`/`pulled`/`pullArmed` declarations (currently around lines 129–131):

```ts
  readonly pull = signal(0);
  private readonly pulled = signal(0);
  readonly pullArmed = computed(() => pullTriggersRefresh(this.pulled()));
```

with:

```ts
  private readonly pulled = signal(0);
  /** True only during an active downward drag. Drives the no-transition class so
   *  the content tracks the finger, and gates the pull branch of revealOffset. */
  readonly dragging = signal(false);
  readonly pullArmed = computed(() => pullTriggersRefresh(this.pulled()));
  /** How far the content and the reveal tray are pushed down, in px. One source
   *  for three states: the finger during a drag, a fixed reveal while a refresh
   *  runs (from ANY trigger — pull, header button, or sidebar button, all of
   *  which set `refreshing()`), and 0 at rest. Suppressed under reduced motion. */
  readonly revealOffset = computed(() => {
    if (this.reduceMotion) return 0;
    if (this.dragging()) return rubberBand(this.pulled(), MAX_PULL);
    return this.refreshing() ? REFRESH_REVEAL : 0;
  });
```

**(c)** Publish the reveal height as a CSS custom property. In the constructor (currently around lines 154–160), add one line after the two `addEventListener` calls:

```ts
    host.style.setProperty('--refresh-reveal', `${REFRESH_REVEAL}px`);
```

**(d)** Replace `onPullMove` (currently around lines 342–356) with:

```ts
  onPullMove(e: TouchEvent, el: HTMLElement): void {
    if (!this.pullTracking || e.touches.length !== 1) return;
    const dy = e.touches[0].clientY - this.pullStartY;
    // A downward pull that is still anchored at the top rubber-bands the content;
    // anything else (upward, or the list has since scrolled) releases it and hands
    // the gesture back to normal scrolling.
    if (dy <= 0 || !atTop(el.scrollTop)) {
      if (this.dragging()) this.dragging.set(false);
      if (this.pulled() !== 0) this.pulled.set(0);
      return;
    }
    this.pulled.set(dy);
    this.dragging.set(true);
    e.preventDefault();
  }
```

**(e)** Replace `onPullEnd` (currently around lines 358–365) with:

```ts
  onPullEnd(): void {
    if (!this.pullTracking) return;
    this.pullTracking = false;
    const trigger = pullTriggersRefresh(this.pulled());
    // Drop the drag: revealOffset now follows refreshing(). On an armed release the
    // emit below flips refreshing() true synchronously (RefreshService.run sets
    // running immediately, and the shell binds it as a plain signal), so the offset
    // hands straight off from the pull value to REFRESH_REVEAL with no 0-frame.
    this.dragging.set(false);
    this.pulled.set(0);
    if (trigger) this.refresh.emit();
  }
```

`onPullStart` is unchanged.

- [ ] **Step 4: Update the template**

In `entry-list.component.html`:

**(a)** Replace the pull-indicator block (currently lines 73–82) with the pill tray:

```html
@if (revealOffset() > 0) {
  <div
    class="pull-indicator"
    [class.armed]="pullArmed()"
    [class.dragging]="dragging()"
    [style.transform]="'translateY(' + revealOffset() + 'px)'"
    aria-hidden="true"
  >
    <div class="pill">
      <app-spinner [size]="20" [decorative]="true" [animate]="pullArmed() || refreshing()" />
      @if (refreshing()) {
        <span class="label">{{ 'reader.refreshing' | transloco }}</span>
      }
    </div>
  </div>
}
```

**(b)** Give the magazine scroller the reveal transform. Replace its opening tag (currently line 104):

```html
  <div class="rows magazine" [class.after-banner]="!!error()" #rows (scroll)="onRowsScroll($event)">
```

with:

```html
  <div
    class="rows magazine"
    [class.after-banner]="!!error()"
    [class.dragging]="dragging()"
    [style.transform]="'translateY(' + revealOffset() + 'px)'"
    #rows
    (scroll)="onRowsScroll($event)"
  >
```

**(c)** Give the list scroller the same. Replace its opening tag (currently line 186):

```html
  <div class="rows" [class.after-banner]="!!error()" #rows (scroll)="onRowsScroll($event)">
```

with:

```html
  <div
    class="rows"
    [class.after-banner]="!!error()"
    [class.dragging]="dragging()"
    [style.transform]="'translateY(' + revealOffset() + 'px)'"
    #rows
    (scroll)="onRowsScroll($event)"
  >
```

(The skeleton `.rows` on line 91 is left alone — the initial load never refreshes.)

- [ ] **Step 5: Update the styles**

In `entry-list.component.scss`:

**(a)** Add a transform transition to the scroller. Replace the `.rows` rule (currently lines 231–241) — keep its body and add the transition, then add a `.dragging` off-switch right after it:

```scss
.rows {
  flex: 1;
  min-height: 0;
  overflow: auto;

  /* Animates the reveal open/closed (snap-back, and the button-driven slide);
     removed under .dragging so the content tracks the finger 1:1. */
  transition: transform 0.2s ease;

  /* Reserves both floating bars. Constant: it tracks neither the app bar's
     hidden state nor this list's collapsed one, so the rows never move (#87).
     They pass behind the bars instead, which is what makes retracting them
     actually give the reader more to look at. */
  padding-top: calc(var(--app-bar-h, var(--bar-h)) + var(--list-bar-h) + var(--bar-gap));
}

.rows.dragging {
  transition: none;
}
```

**(b)** Replace the whole `.pull-indicator` block (currently lines 175–210, its comment through `.pull-indicator.armed`) with the pill tray:

```scss
/* Pull-to-refresh / refresh reveal tray. A full-width row centring a pill
   (spinner + "Refreshing…" label). Parked one --refresh-reveal above the bars and
   drawn down by the inline translateY as the content slides — on a mobile pull, and
   on a header/sidebar Refresh (both drive revealOffset off `refreshing()`). It
   emerges from *behind* the list header, hence the z-index below that bar's. The
   offset is measured from the bars, not the host's top edge: since #87 the bars
   float and the host starts at the viewport top, so a plain negative top would park
   the tray behind the app bar and clearing it would take more pull than the rubber
   band allows (#105). */
.pull-indicator {
  position: absolute;
  top: calc(var(--app-bar-h, var(--bar-h)) + var(--list-bar-h) - var(--refresh-reveal));
  left: 0;
  right: 0;
  height: var(--refresh-reveal);
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  transition: transform 0.2s ease;
}

/* No transition while the finger is down: the tray tracks the pull 1:1. */
.pull-indicator.dragging {
  transition: none;
}

.pull-indicator .pill {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-pill);
  background: var(--surface-1);
  border: 1px solid var(--border);
  box-shadow: var(--shadow-1, 0 1px 4px rgb(0 0 0 / 15%));
  color: var(--text-secondary);
  font-size: var(--fs-sm);
}

.pull-indicator .label {
  white-space: nowrap;
}

.pull-indicator.armed .pill {
  color: var(--accent);
  border-color: var(--accent);
}
```

**(c)** Extend the reduced-motion block (currently lines 316–328) so the scroller and tray never transition. Replace it with:

```scss
@media (prefers-reduced-motion: reduce) {
  .list-header {
    transition: none;
  }

  .skeleton {
    animation: none;
  }

  .refresh.spinning app-icon {
    animation: none;
  }

  .rows,
  .pull-indicator {
    transition: none;
  }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npx jest entry-list --silent`
Expected: PASS — all cases, including the pre-existing pull-to-refresh and back-to-top suites.

- [ ] **Step 7: Commit**

```bash
git add src/app/reader/entry-list/entry-list.component.ts \
        src/app/reader/entry-list/entry-list.component.html \
        src/app/reader/entry-list/entry-list.component.scss \
        src/app/reader/entry-list/entry-list.component.spec.ts
git commit -m "feat(reader): reveal a held spinner on refresh and snap back (#158)"
```

---

### Task 3: Full frontend gate

**Files:** none (verification only).

- [ ] **Step 1: Run the CI gate**

Run: `npm run check`
Expected: PASS — ESLint, Prettier, Stylelint (`color-no-hex`, spacing/media-query literals), and the full Jest suite all green.

- [ ] **Step 2: Fix and re-run if needed**

If Prettier reports formatting, run `npx prettier --write` on the changed files, re-run `npm run check`, and amend:

```bash
git add -A && git commit --amend --no-edit
```

Expected final state: `npm run check` exits 0.

---

## Manual verification (optional, not a gate)

With the Docker stack up and `npm start` running:

- **Mobile (narrow viewport, touch):** pull the list down — the content follows and a spinner pill appears; release past the threshold — it holds open showing "Refreshing…", then snaps back when the refresh lands. A short pull snaps back with no refresh.
- **Desktop (wide viewport):** click the list header **Refresh** — the content slides down revealing the "Refreshing…" pill, then snaps back on completion. Click the sidebar **Refresh** — the same reveal opens on the visible list.
- **Reduced motion:** enable "Reduce motion" in the OS — no reveal on either platform; the Refresh button's own state remains the signal.
