# Unified Keep/Favourite Toggles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep and favourite look the same on every surface: grey hollow glyph while off, accent-green filled glyph while on.

**Architecture:** One owner for the off/on look of an entry-state toggle: a `button[appFlagToggle]` directive that stamps `flag-toggle`, `on` and `aria-pressed`, styled by one global sheet `styles/_flag-toggle.scss` (global for the same reason `_list-action.scss` is, and so it can override the list-action accent colour in the reader toolbar). The fill comes from the existing `app-icon [fill]` input on the star and bookmark glyphs. The reader view's duplicate favourite/keep/read row is deleted in favour of `<app-entry-actions size="md">` (the #414 rule: one copy of the per-entry actions).

**Tech Stack:** Angular 20 standalone components and directives, signals, SCSS, Jest (jsdom), manual render check in the browser.

**Spec:** Chat decision on 2026-09-25: colour is `--accent` (not `--success`); the approach is the architectural one (shared owner + reuse `app-entry-actions` in the reader view).

## Global Constraints

- Issue: #1152.
- Branch: `fix/1152-unified-flag-toggles` off `develop`. PR into `develop`, body says `Closes #1152`.
- Commit format: `type(#1152): lower-case summary`.
- Off = `var(--text-muted)` + hollow glyph. On = `var(--accent)` + filled glyph (`app-icon [fill]="true"`). Hover (off only, `@media (hover: hover)` only) = `var(--text-primary)`.
- On mobile (`width <= bp.$bp-sm`) the reader toolbar's Keep/Favorite keep their bordered icon box from `_list-action.scss` (`.list-action.mobile-icon-only app-icon`), off and on alike. `_flag-toggle.scss` sets `color` only, never `border`, `padding` or the `app-icon` box, and the toolbar buttons keep both `appListAction` and `class="mobile-icon-only"`.
- The read (tick) toggle shares the colour language but never fills — `check` has no hollow form.
- Sidebar nav icons (`star`/`bookmark` for the Favourites/Kept lists) are navigation, not toggles: out of scope.
- No hex colours, no ad-hoc `px` in `.scss`. Component styles stay in sibling `.scss` files.
- Comments: default to none, one line at most (CLAUDE.md). Moved comments that no longer clear the bar stay gone.
- Frontend tests run inside Docker: `docker compose exec -T frontend npm test -- <path>`; the gate is `docker compose exec -T frontend npm run check`. Never run two Jest runs at once (container OOM).

---

### Task 1: The `appFlagToggle` directive and its global look

**Files:**
- Create: `frontend/src/app/shared/flag-toggle/flag-toggle.directive.ts`
- Create: `frontend/src/app/shared/flag-toggle/flag-toggle.directive.spec.ts`
- Create: `frontend/src/styles/_flag-toggle.scss`
- Modify: `frontend/src/styles.scss` (add `@use './styles/flag-toggle';` after `@use './styles/list-action';`, line 10)

**Interfaces:**
- Produces: `FlagToggleDirective`, selector `button[appFlagToggle]`, required input `appFlagToggle: boolean`. Host effects: class `flag-toggle` always, class `on` when true, `aria-pressed="true"|"false"`.

- [ ] **Step 1: Write the failing test** — `flag-toggle.directive.spec.ts`:

```ts
import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FlagToggleDirective } from './flag-toggle.directive';

@Component({
  imports: [FlagToggleDirective],
  template: `<button type="button" [appFlagToggle]="active()">Keep</button>`,
})
class Host {
  readonly active = signal(false);
}

describe('FlagToggleDirective', () => {
  function mount() {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const button: HTMLButtonElement = fixture.nativeElement.querySelector('button');
    return { fixture, button };
  }

  it('stamps the shared class and reports an inactive flag as unpressed', () => {
    const { button } = mount();
    expect(button.classList).toContain('flag-toggle');
    expect(button.classList).not.toContain('on');
    expect(button.getAttribute('aria-pressed')).toBe('false');
  });

  it('marks an active flag on and pressed, and follows the state', () => {
    const { fixture, button } = mount();
    fixture.componentInstance.active.set(true);
    fixture.detectChanges();
    expect(button.classList).toContain('on');
    expect(button.getAttribute('aria-pressed')).toBe('true');

    fixture.componentInstance.active.set(false);
    fixture.detectChanges();
    expect(button.classList).not.toContain('on');
  });
});
```

- [ ] **Step 2: Run it, expect FAIL** (module not found)

Run: `docker compose exec -T frontend npm test -- src/app/shared/flag-toggle`

- [ ] **Step 3: Implement** — `flag-toggle.directive.ts`:

```ts
import { Directive, input } from '@angular/core';

/** An entry-state toggle (favourite, keep, read); the look lives in `styles/_flag-toggle.scss`. */
@Directive({
  selector: 'button[appFlagToggle]',
  host: {
    class: 'flag-toggle',
    '[class.on]': 'appFlagToggle()',
    '[attr.aria-pressed]': 'appFlagToggle()',
  },
})
export class FlagToggleDirective {
  readonly appFlagToggle = input.required<boolean>();
}
```

`_flag-toggle.scss` — the `button` type selector is load-bearing: `button.flag-toggle` (0,1,1) must outrank `.list-action` (0,1,0), which paints every toolbar action accent:

```scss
// Every entry-state toggle, one language: muted and hollow off, accent and filled on (#1152).
button.flag-toggle {
  color: var(--text-muted);
}

@media (hover: hover) {
  button.flag-toggle:not(.on):hover {
    color: var(--text-primary);
  }
}

button.flag-toggle.on {
  color: var(--accent);
}
```

Add `@use './styles/flag-toggle';` to `styles.scss` directly after the `list-action` line.

- [ ] **Step 4: Run it, expect PASS** (same command).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/shared/flag-toggle frontend/src/styles/_flag-toggle.scss frontend/src/styles.scss
git commit -m "feat(#1152): add a shared flag-toggle directive and look"
```

---

### Task 2: `app-entry-actions` adopts the directive and fills its glyphs

**Files:**
- Modify: `frontend/src/app/reader/entry-actions/entry-actions.component.html`
- Modify: `frontend/src/app/reader/entry-actions/entry-actions.component.ts` (imports)
- Modify: `frontend/src/app/reader/entry-actions/entry-actions.component.scss` (drop colour rules)
- Test: `frontend/src/app/reader/entry-actions/entry-actions.component.spec.ts`

**Interfaces:**
- Consumes: `FlagToggleDirective` (Task 1).
- Produces: unchanged public API (`entry`, `size`, `favorite`, `keep`, `read`); each button now carries `flag-toggle`.

- [ ] **Step 1: Write the failing tests** — add to the spec's `describe`:

```ts
  it('fills the star and bookmark only while they are on', () => {
    const fills = (e: EntryDto) =>
      mount(e)
        .debugElement.queryAll(By.directive(IconComponent))
        .map((d) => d.componentInstance.fill());

    expect(fills(entry({ isFavorite: false, isKept: false }))).toEqual([false, false, false]);
    expect(fills(entry({ isFavorite: true, isKept: true, isViewed: true }))).toEqual([
      true,
      true,
      false,
    ]);
  });

  it('styles every toggle through the shared flag-toggle look', () => {
    const shared = buttons(mount()).map((b) => b.classList.contains('flag-toggle'));
    expect(shared).toEqual([true, true, true]);
  });
```

The existing `aria-pressed` and `.on` tests stay as they are — they now prove the directive is wired.

- [ ] **Step 2: Run, expect the two new tests to FAIL**

Run: `docker compose exec -T frontend npm test -- src/app/reader/entry-actions`

- [ ] **Step 3: Implement.** Template — the directive replaces the `[class.on]`/`[attr.aria-pressed]` pair on each button; star and bookmark gain `[fill]`:

```html
<button
  type="button"
  [attr.aria-label]="'reader.favorite' | transloco"
  [appFlagToggle]="entry().isFavorite"
  (click)="$event.stopPropagation(); favorite.emit(entry())"
  (keydown.enter)="$event.stopPropagation()"
  (keydown.space)="$event.stopPropagation()"
>
  <app-icon name="star" [size]="size()" [fill]="entry().isFavorite" />
</button>
<button
  type="button"
  [attr.aria-label]="'reader.keep' | transloco"
  [appFlagToggle]="entry().isKept"
  (click)="$event.stopPropagation(); keep.emit(entry())"
  (keydown.enter)="$event.stopPropagation()"
  (keydown.space)="$event.stopPropagation()"
>
  <app-icon name="bookmark" [size]="size()" [fill]="entry().isKept" />
</button>
<button
  type="button"
  [attr.aria-label]="'reader.toggleRead' | transloco"
  [appFlagToggle]="entry().isViewed"
  (click)="$event.stopPropagation(); read.emit(entry())"
  (keydown.enter)="$event.stopPropagation()"
  (keydown.space)="$event.stopPropagation()"
>
  <app-icon name="check" [size]="size()" />
</button>
```

Component: `imports: [IconComponent, TranslocoPipe, FlagToggleDirective]` (import from `'../../shared/flag-toggle/flag-toggle.directive'`).

SCSS: delete `color: var(--text-muted);` from the `button` rule, and delete the `button:hover` rule and the `button.on` rule together with its three-line comment. The global sheet owns all three now. An encapsulated `button:hover` left behind would be `button[_ngcontent]:hover` (0,2,1) and would turn an active green star dark on hover.

- [ ] **Step 4: Run, expect PASS** (same command).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/entry-actions
git commit -m "fix(#1152): fill active favourite and keep glyphs on entry cards"
```

---

### Task 3: Reader view — reuse `app-entry-actions`, unify the toolbar pair

**Files:**
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.html` (toolbar keep/favourite buttons ~lines 75–98; the `.actions` block and its comment ~lines 138–167)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts` (imports array, ~line 118)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.scss` (`.close, .actions button` → `.close`, ~line 247; delete `.actions button.on`, ~line 347)
- Test: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`

**Interfaces:**
- Consumes: `FlagToggleDirective` (Task 1), `EntryActionsComponent` with `size="md"` (Task 2).
- Produces: unchanged outputs `favorite`, `keep`, `read` (`output<void>`).

- [ ] **Step 1: Write the failing tests** — add near `'emits favorite/keep/read/close'`:

```ts
  it('renders the article’s action row through the shared entry actions', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    const row = el.querySelector('app-entry-actions.actions');
    expect(row).not.toBeNull();
    expect(row!.classList).toContain('glyph-md');
  });
```

and inside the toolbar `describe`, after `'offers favourite and keep in the split pane’s toolbar'`:

```ts
    it('draws the toolbar pair in the shared toggle look, filled only while on', () => {
      const f = mount(entry({ isFavorite: true, isKept: false }));
      const el = f.nativeElement as HTMLElement;
      const favourite = el.querySelector<HTMLButtonElement>('.bar [aria-label="Favorite"]')!;
      const keep = el.querySelector<HTMLButtonElement>('.bar [aria-label="Keep"]')!;
      const fillOf = (button: HTMLButtonElement) =>
        f.debugElement
          .queryAll(By.directive(IconComponent))
          .find((d) => button.contains(d.nativeElement))!
          .componentInstance.fill();

      expect(favourite.classList).toContain('flag-toggle');
      expect(keep.classList).toContain('flag-toggle');
      expect(favourite.getAttribute('aria-pressed')).toBe('true');
      expect(keep.getAttribute('aria-pressed')).toBe('false');
      expect(fillOf(favourite)).toBe(true);
      expect(fillOf(keep)).toBe(false);
    });
```

(Add `import { By } from '@angular/platform-browser';` and `import { IconComponent } from '../../shared/icon/icon.component';` if the spec lacks them.) The existing `'emits favorite/keep/read/close'` test keeps working unchanged: `.actions [aria-label=…]` now resolves inside `app-entry-actions.actions`.

- [ ] **Step 2: Run, expect the two new tests to FAIL**

Run: `docker compose exec -T frontend npm test -- src/app/reader/reader-view/reader-view.component.spec.ts`

- [ ] **Step 3: Implement.**

Toolbar pair: replace `[class.on]` + `[attr.aria-pressed]` with the directive. Keep `[fill]`:

```html
        <button
          appListAction
          class="mobile-icon-only"
          type="button"
          [attr.aria-label]="'reader.keep' | transloco"
          [appFlagToggle]="e.isKept"
          (click)="keep.emit()"
        >
          <app-icon name="bookmark" size="sm" [fill]="e.isKept" />
          <span class="txt">{{ 'reader.keep' | transloco }}</span>
        </button>
        <button
          appListAction
          class="mobile-icon-only"
          type="button"
          [attr.aria-label]="'reader.favorite' | transloco"
          [appFlagToggle]="e.isFavorite"
          (click)="favorite.emit()"
        >
          <app-icon name="star" size="sm" [fill]="e.isFavorite" />
          <span class="txt">{{ 'reader.favorite' | transloco }}</span>
        </button>
```

Article row: delete the `<!-- One language for all three … -->` comment and the whole `<div class="actions">…</div>`, and put this in its place:

```html
      <app-entry-actions
        class="actions"
        size="md"
        [entry]="e"
        (favorite)="favorite.emit()"
        (keep)="keep.emit()"
        (read)="read.emit()"
      />
```

TS: add `EntryActionsComponent` (`'../entry-actions/entry-actions.component'`) and `FlagToggleDirective` (`'../../shared/flag-toggle/flag-toggle.directive'`) to `imports`.

SCSS: change the selector `.close,\n.actions button {` to `.close {` (the parent's encapsulated rule cannot reach the child's buttons anyway), and delete the `.actions button.on { … }` rule. Keep the `.actions { … }` rule: it lands on the `app-entry-actions` host and, being `.actions[_ngcontent]` (0,2,0), outranks the child's `:host` for `display: flex` and `gap: var(--space-4)`, so the row keeps its layout, padding and rule line.

- [ ] **Step 4: Run, expect PASS** (same command).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reader-view
git commit -m "fix(#1152): reuse entry actions in the reader view and unify its toolbar toggles"
```

---

### Task 4: Docs, gate, render check

**Files:**
- Modify: `docs/design-language.md` (`app-entry-actions` section, ~lines 1162–1197)

- [ ] **Step 1: Update the catalog.** In the `<app-entry-actions>` section: add `size` (`'sm' | 'md'`, default `'sm'`) to the input table; replace the stale paragraph sentence "Favorite and keep light up in the accent colour when on; the read button instead swaps its icon, because most cards are already read, and accenting that state would light up the whole page for no reason." with:

"Each button is a `button[appFlagToggle]`: muted while off, accent while on, with the look owned by `frontend/src/styles/_flag-toggle.scss`. Favourite and keep also fill their glyph while on; the tick has no hollow form. The reader view's article row is this component at `size="md"`, and its toolbar Keep/Favorite reuse the same directive, so every keep and favourite toggle in the app looks the same."

- [ ] **Step 2: Run the frontend gate.** Expect green.

Run: `docker compose exec -T frontend npm run check`

- [ ] **Step 3: Render check in the real app** (memory: reproduce visual bugs on the real render). Check the frontend container is serving current code, then use the built-in browser on `https://localhost:4200` at a Mobile viewport (the Claude UA is bot-blocked on desktop). For each surface — magazine card, standard list row, reader-view toolbar (split pane and full screen), reader-view article row — toggle keep and favourite. Confirm: off = grey hollow; on = green filled; hover on an active toggle stays green; the toolbar's Refresh and mode actions keep their accent colour. At the Mobile viewport, confirm the toolbar Keep and Favorite still sit in their bordered icon boxes, same border as Refresh, in both states. Read it off the render with `getComputedStyle(button.querySelector('app-icon')).borderTopWidth` (expect `1px`) for each toolbar action, not by eye alone. Screenshot before/after of the reader view and send it to the user.

- [ ] **Step 4: Commit**

```bash
git add docs/design-language.md
git commit -m "docs(#1152): record the shared flag-toggle look"
```
