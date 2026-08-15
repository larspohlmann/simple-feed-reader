# Magazine Card Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the favorite / keep / mark-read actions on every magazine card, right-aligned on the tag row, instead of only on the hero.

**Architecture:** Two new components in `src/app/reader/`. `app-entry-actions` owns the three buttons and nothing else. `app-entry-meta` is the row: `app-source-tags` on the left, `app-entry-actions` on the right. Six card kinds replace their bare `<app-source-tags>` with `<app-entry-meta>`; the three outputs move onto `EntryBlockBase` so every block carries them. `entry-compact` is the one exception — it projects `app-entry-actions` into the kicker line instead, so a source group does not grow by one row per item.

**Tech Stack:** Angular 20 standalone components, signal `input()`/`output()`, Transloco, Jest + jsdom, SCSS with the `theme/` token set.

## Global Constraints

- Issue #414. Branch `feature/414-magazine-card-actions`, already created off `develop`.
- **No hex colours in `.scss` outside `src/app/theme/`**, no ad-hoc `px` spacing, no media-query literals. Stylelint fails the build otherwise.
- **Component styles live in a sibling `.scss` file** (`styleUrl`), never inline in the `.ts`.
- Standalone components and signals. No NgModules.
- Icon sizes are named (`sm`), never a px value.
- Reuse the existing i18n keys `reader.favorite`, `reader.keep`, `reader.toggleRead`. **No new translation keys.**
- Prettier at 100 columns. Run `npm run check` from `frontend/`.
- Commit messages: `feat(#414): …` / `fix(#414): …`, imperative, lower case after the prefix.
- `entry-row` in the list layout is **out of scope** and must not be touched.

## Agreed Design (decided in a reviewed visual round — do not re-litigate)

- The row is always rendered, also when the entry has no tags, so the icons keep a constant position.
- Actions never shrink; `align-items: flex-end` keeps them level with the **last** line of a wrapping pill list.
- The row carries `margin-top: auto`, so in a card whose image is taller than its text (`split`, `thumb`) the **whole row** — pills and icons together — drops to the bottom of the card. It is inert in a card with no spare height.
- Gap `--space-3`, no separator, order star / bookmark / read.
- Always visible. `sm` icons, `--text-muted`, `--accent` when active, `--text-primary` on hover.
- The read button swaps `check` and `mark_email_unread` and **never** takes the accent.
- Nothing dims on a read card.
- On `pointer: coarse` each button becomes 32×44. Vertical padding is fully compensated by a negative margin so no card gets taller. Inline padding is `--space-2` against a `--space-4` gap — exactly twice the padding — so the hit boxes tile edge to edge. A symmetric 44px box overlaps its neighbour by 16px and the wrong button wins the tap.
- `entry-compact` puts the actions on the kicker line in both variants; its tag row stays pills-only.

## File Structure

**Create**

| File | Responsibility |
|---|---|
| `frontend/src/app/reader/entry-actions/entry-actions.component.ts` | The three buttons: state, labels, `stopPropagation`, outputs |
| `frontend/src/app/reader/entry-actions/entry-actions.component.html` | Three `<button>`s, no wrapper element |
| `frontend/src/app/reader/entry-actions/entry-actions.component.scss` | Button appearance and the coarse-pointer hit box |
| `frontend/src/app/reader/entry-actions/entry-actions.component.spec.ts` | Behaviour, incl. the card must not open |
| `frontend/src/app/reader/entry-meta/entry-meta.component.ts` | The row: tags + actions, forwards the outputs |
| `frontend/src/app/reader/entry-meta/entry-meta.component.html` | `app-source-tags` + `app-entry-actions` |
| `frontend/src/app/reader/entry-meta/entry-meta.component.scss` | Row geometry, bottom alignment, `margin-top: auto` |
| `frontend/src/app/reader/entry-meta/entry-meta.component.spec.ts` | Renders with and without tags, forwards |

**Modify**

| File | Change |
|---|---|
| `magazine/entry-block-base.ts` | Add `favorite` / `keep` / `read` outputs |
| `magazine/entry-hero.component.{ts,html,scss}` | Drop the bespoke action block, use `app-entry-meta` |
| `magazine/entry-wide.component.{ts,html}` | Use `app-entry-meta` |
| `magazine/entry-kicker.component.{ts,html}` | Use `app-entry-meta` |
| `magazine/entry-quote.component.{ts,html}` | Use `app-entry-meta` |
| `magazine/entry-split.component.{ts,html,scss}` | Use `app-entry-meta`; stretch the body so the row drops |
| `magazine/entry-thumb.component.{ts,html,scss}` | Same as split |
| `magazine/entry-kicker-line.component.html` | Add `<ng-content />` inside the `<p>` |
| `magazine/entry-compact.component.{ts,html,scss}` | Project `app-entry-actions` onto the kicker line |
| `magazine/source-group.component.{ts,html}` | Add and forward the three outputs |
| `entry-list/entry-list.component.html` | Bind the three outputs on all seven remaining blocks |
| `docs/design-language.md` | Catalog entry + the compact exception |

---

### Task 1: `app-entry-actions`

**Files:**
- Create: `frontend/src/app/reader/entry-actions/entry-actions.component.ts`
- Create: `frontend/src/app/reader/entry-actions/entry-actions.component.html`
- Create: `frontend/src/app/reader/entry-actions/entry-actions.component.scss`
- Test: `frontend/src/app/reader/entry-actions/entry-actions.component.spec.ts`

**Interfaces:**
- Consumes: `EntryDto` from `src/app/reader/models.ts`; `IconComponent` from `src/app/shared/icon/icon.component`.
- Produces: `EntryActionsComponent`, selector `app-entry-actions`. Input `entry: EntryDto` (required). Outputs `favorite`, `keep`, `read`, each `output<EntryDto>()` emitting the current entry.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/reader/entry-actions/entry-actions.component.spec.ts`:

```ts
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryActionsComponent } from './entry-actions.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A title',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 7,
  source: 'Src',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

/** A stand-in for the card: clickable, and it must NOT open when an action is
 *  pressed. Testing that through a real parent is the only way to prove the
 *  click never reaches the card. */
@Component({
  imports: [EntryActionsComponent],
  template: `<article class="card" (click)="cardOpened = true">
    <app-entry-actions
      [entry]="entry"
      (favorite)="favorited = $event"
      (keep)="kept = $event"
      (read)="marked = $event"
    />
  </article>`,
})
class HostComponent {
  entry: EntryDto = entry();
  cardOpened = false;
  favorited: EntryDto | null = null;
  kept: EntryDto | null = null;
  marked: EntryDto | null = null;
}

function mount(e: EntryDto = entry()) {
  TestBed.configureTestingModule({ imports: [HostComponent, provideTranslocoTesting()] });
  const f = TestBed.createComponent(HostComponent);
  f.componentInstance.entry = e;
  f.detectChanges();
  return f;
}

const buttons = (f: { nativeElement: HTMLElement }) =>
  Array.from(f.nativeElement.querySelectorAll('button'));

describe('EntryActionsComponent', () => {
  it('renders the three actions with their labels', () => {
    const labels = buttons(mount()).map((b) => b.getAttribute('aria-label'));
    expect(labels).toEqual(['Favorite', 'Keep', 'Toggle read']);
  });

  it('reports each action state through aria-pressed', () => {
    const f = mount(entry({ isFavorite: true, isKept: false, isRead: true }));
    const pressed = buttons(f).map((b) => b.getAttribute('aria-pressed'));
    expect(pressed).toEqual(['true', 'false', 'true']);
  });

  it('marks an active favorite and keep, but never the read button', () => {
    const f = mount(entry({ isFavorite: true, isKept: true, isRead: true }));
    const on = buttons(f).map((b) => b.classList.contains('on'));
    expect(on).toEqual([true, true, false]);
  });

  it('offers to mark read while unread, and to unread once read', () => {
    expect(mount(entry({ isRead: false })).nativeElement.textContent).toContain('check');
    expect(mount(entry({ isRead: true })).nativeElement.textContent).toContain(
      'mark_email_unread',
    );
  });

  it('emits the entry and does not open the card', () => {
    const f = mount();
    const [favorite, keep, read] = buttons(f);

    favorite.click();
    keep.click();
    read.click();
    f.detectChanges();

    expect(f.componentInstance.favorited).toBe(f.componentInstance.entry);
    expect(f.componentInstance.kept).toBe(f.componentInstance.entry);
    expect(f.componentInstance.marked).toBe(f.componentInstance.entry);
    expect(f.componentInstance.cardOpened).toBe(false);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run from `frontend/`:

```bash
npx jest src/app/reader/entry-actions --silent
```

Expected: FAIL — `Cannot find module './entry-actions.component'`.

- [ ] **Step 3: Write the component**

Create `frontend/src/app/reader/entry-actions/entry-actions.component.ts`:

```ts
// src/app/reader/entry-actions/entry-actions.component.ts
import { Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryDto } from '../models';

/**
 * The three per-entry actions — favorite, keep, mark read — as one control
 * cluster. Every magazine card carries it, so it lives here rather than being
 * repeated per block: it used to exist twice (hero and entry-row) and the
 * second copy is what made the actions read as unreliable across the view
 * (#414).
 *
 * Clicks stop propagating, because the card around it is itself clickable and
 * would otherwise open the entry instead of toggling the flag.
 */
@Component({
  selector: 'app-entry-actions',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './entry-actions.component.html',
  styleUrl: './entry-actions.component.scss',
})
export class EntryActionsComponent {
  readonly entry = input.required<EntryDto>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
}
```

Create `frontend/src/app/reader/entry-actions/entry-actions.component.html`:

```html
<button
  type="button"
  [attr.aria-label]="'reader.favorite' | transloco"
  [class.on]="entry().isFavorite"
  [attr.aria-pressed]="entry().isFavorite"
  (click)="$event.stopPropagation(); favorite.emit(entry())"
>
  <app-icon name="star" size="sm" />
</button>
<button
  type="button"
  [attr.aria-label]="'reader.keep' | transloco"
  [class.on]="entry().isKept"
  [attr.aria-pressed]="entry().isKept"
  (click)="$event.stopPropagation(); keep.emit(entry())"
>
  <app-icon name="bookmark" size="sm" />
</button>
<button
  type="button"
  [attr.aria-label]="'reader.toggleRead' | transloco"
  [attr.aria-pressed]="entry().isRead"
  (click)="$event.stopPropagation(); read.emit(entry())"
>
  <app-icon [name]="entry().isRead ? 'mark_email_unread' : 'check'" size="sm" />
</button>
```

Create `frontend/src/app/reader/entry-actions/entry-actions.component.scss`:

```scss
/* The host IS the row of buttons — there is deliberately no wrapper element.
   `entry-compact` projects this component into the kicker's <p>, and the HTML
   parser closes a paragraph at a block-level child, which would silently drop
   the buttons onto a line of their own. */
:host {
  display: inline-flex;
  align-items: center;
  gap: var(--space-3);
  flex: none;
}

button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-0);
  border: none;
  border-radius: var(--radius-sm);
  background: none;
  color: var(--text-muted);
  cursor: pointer;
}

button:hover {
  color: var(--text-primary);
}

/* Favorite and keep only. The read button reports its state by swapping its
   icon: "read" is the normal state of most cards, so accenting it would light
   up the whole page and leave the accent meaning nothing. */
button.on {
  color: var(--accent);
}

/* Touch density is a pointer decision, applied locally.
   Vertical: full padding to reach --tap-target, fully cancelled by a negative
   margin, so a card never gets taller on a phone.
   Horizontal: --space-2 of padding against a --space-4 gap — exactly twice the
   padding — so the hit boxes tile edge to edge. Deriving both from the scale is
   what keeps that exact: a symmetric 44px box needs 14px of inline padding and
   overlaps its neighbour by 16px, and since the later button paints on top, a
   tap on the star's right half toggles keep instead. */
@media (pointer: coarse) {
  :host {
    gap: var(--space-4);
  }

  button {
    padding-block: calc((var(--tap-target) - var(--icon-sm)) / 2);
    margin-block: calc((var(--tap-target) - var(--icon-sm)) / -2);
    padding-inline: var(--space-2);
    margin-inline: calc(var(--space-2) * -1);
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npx jest src/app/reader/entry-actions --silent
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/entry-actions
git commit -m "feat(#414): add the shared entry actions cluster"
```

---

### Task 2: `app-entry-meta`

**Files:**
- Create: `frontend/src/app/reader/entry-meta/entry-meta.component.ts`
- Create: `frontend/src/app/reader/entry-meta/entry-meta.component.html`
- Create: `frontend/src/app/reader/entry-meta/entry-meta.component.scss`
- Test: `frontend/src/app/reader/entry-meta/entry-meta.component.spec.ts`

**Interfaces:**
- Consumes: `EntryActionsComponent` from Task 1; `SourceTagsComponent` from `src/app/reader/source-tags/source-tags.component`; `EntryDto` and `SubscriptionTagDto` from `src/app/reader/models.ts`.
- Produces: `EntryMetaComponent`, selector `app-entry-meta`. Inputs `entry: EntryDto` (required) and `tags: SubscriptionTagDto[]` (defaults to `[]`). Outputs `favorite`, `keep`, `read`, each `output<EntryDto>()`.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/reader/entry-meta/entry-meta.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryMetaComponent } from './entry-meta.component';
import { EntryDto, SubscriptionTagDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A title',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 7,
  source: 'Src',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

const tag = (id: number, name: string): SubscriptionTagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
});

function mount(tags: SubscriptionTagDto[]) {
  TestBed.configureTestingModule({
    imports: [EntryMetaComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  const f = TestBed.createComponent(EntryMetaComponent);
  f.componentRef.setInput('entry', entry());
  f.componentRef.setInput('tags', tags);
  f.detectChanges();
  return f;
}

describe('EntryMetaComponent', () => {
  it('renders the tag pills beside the actions', () => {
    const el = mount([tag(1, 'Tech')]).nativeElement as HTMLElement;
    expect(el.textContent).toContain('Tech');
    expect(el.querySelectorAll('app-entry-actions button').length).toBe(3);
  });

  it('still renders the actions when the entry has no tags', () => {
    const el = mount([]).nativeElement as HTMLElement;
    expect(el.querySelector('app-source-tags .pills')).toBeNull();
    expect(el.querySelectorAll('app-entry-actions button').length).toBe(3);
  });

  it('forwards each action to its own output', () => {
    const f = mount([]);
    const favorite = jest.fn();
    const keep = jest.fn();
    const read = jest.fn();
    f.componentInstance.favorite.subscribe(favorite);
    f.componentInstance.keep.subscribe(keep);
    f.componentInstance.read.subscribe(read);

    const buttons = f.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[0] as HTMLElement).click();
    (buttons[1] as HTMLElement).click();
    (buttons[2] as HTMLElement).click();

    expect(favorite).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(keep).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(read).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npx jest src/app/reader/entry-meta --silent
```

Expected: FAIL — `Cannot find module './entry-meta.component'`.

- [ ] **Step 3: Write the component**

Create `frontend/src/app/reader/entry-meta/entry-meta.component.ts`:

```ts
// src/app/reader/entry-meta/entry-meta.component.ts
import { Component, input, output } from '@angular/core';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryActionsComponent } from '../entry-actions/entry-actions.component';
import { EntryDto, SubscriptionTagDto } from '../models';

/**
 * The line a magazine card ends on: the feed's tag pills, and the entry's own
 * actions right-aligned against them. One component rather than a row assembled
 * in each block, so the geometry — where the icons sit when the pills wrap, and
 * where they sit when the card has spare height — has exactly one definition
 * for the six blocks that use it.
 *
 * `entry-compact` deliberately does NOT use it: see its own template.
 */
@Component({
  selector: 'app-entry-meta',
  imports: [SourceTagsComponent, EntryActionsComponent],
  templateUrl: './entry-meta.component.html',
  styleUrl: './entry-meta.component.scss',
})
export class EntryMetaComponent {
  readonly entry = input.required<EntryDto>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
}
```

Create `frontend/src/app/reader/entry-meta/entry-meta.component.html`:

```html
<app-source-tags [tags]="tags()" />
<app-entry-actions
  [entry]="entry()"
  (favorite)="favorite.emit($event)"
  (keep)="keep.emit($event)"
  (read)="read.emit($event)"
/>
```

Create `frontend/src/app/reader/entry-meta/entry-meta.component.scss`:

```scss
/* The host IS the row.

   `width: 100%` is load-bearing, not belt-and-braces: `kicker` and `quote` set
   `align-items: flex-start` on their column, so without it this row takes its
   content width and the actions stop being right-aligned.

   `align-items: flex-end` keeps the actions level with the LAST line of a
   wrapping pill list — the bottom edge of the card is the stable reference.

   `margin-top: auto` is what drops the whole row to the bottom of a card whose
   image is taller than its text (split, thumb). It is inert in a card with no
   spare height, which is every other block. */
:host {
  display: flex;
  align-items: flex-end;
  gap: var(--space-3);
  width: 100%;
  min-width: 0;
  margin-top: auto;
}

/* The pills are the row's only elastic item; the actions never shrink. The
   min-width:0 chain into the pill list is what stops an unbreakable tag name
   from widening the card. */
app-source-tags {
  flex: 1 1 auto;
  min-width: 0;
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npx jest src/app/reader/entry-meta --silent
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/entry-meta
git commit -m "feat(#414): add the entry meta row"
```

---

### Task 3: Outputs on `EntryBlockBase`, and the hero adopts the row

**Files:**
- Modify: `frontend/src/app/reader/magazine/entry-block-base.ts`
- Modify: `frontend/src/app/reader/magazine/entry-hero.component.ts`
- Modify: `frontend/src/app/reader/magazine/entry-hero.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-hero.component.scss`
- Test: `frontend/src/app/reader/magazine/entry-hero.component.spec.ts`

**Interfaces:**
- Consumes: `EntryMetaComponent` from Task 2.
- Produces: `EntryBlockBase` now declares `favorite`, `keep`, `read`, each `output<EntryDto>()`. Every block that extends it — hero, wide, split, thumb, kicker, quote, compact — inherits them, so Tasks 4–6 bind them without redeclaring. `EntryHeroComponent` no longer declares its own three outputs.

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/app/reader/magazine/entry-hero.component.spec.ts`, inside the existing `describe`:

```ts
  it('carries the actions on the meta row, not on a row of its own', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.actions')).toBeNull();
    expect(el.querySelectorAll('app-entry-meta app-entry-actions button').length).toBe(3);
  });

  it('emits favorite, keep and read from the meta row', () => {
    const f = mount(entry());
    const favorite = jest.fn();
    const keep = jest.fn();
    const read = jest.fn();
    f.componentInstance.favorite.subscribe(favorite);
    f.componentInstance.keep.subscribe(keep);
    f.componentInstance.read.subscribe(read);

    const buttons = f.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[0] as HTMLElement).click();
    (buttons[1] as HTMLElement).click();
    (buttons[2] as HTMLElement).click();

    expect(favorite).toHaveBeenCalled();
    expect(keep).toHaveBeenCalled();
    expect(read).toHaveBeenCalled();
  });
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npx jest src/app/reader/magazine/entry-hero --silent
```

Expected: FAIL — `.actions` is still present and `app-entry-meta` renders nothing.

- [ ] **Step 3: Add the outputs to the base**

In `frontend/src/app/reader/magazine/entry-block-base.ts`, add to the class body, directly under `readonly open = output<EntryDto>();`:

```ts
  /** Every block carries the three per-entry actions. They live here rather
   *  than on each block so a new block cannot forget them, and so the host
   *  binds the same four outputs for every kind. */
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
```

- [ ] **Step 4: Switch the hero to the meta row**

In `frontend/src/app/reader/magazine/entry-hero.component.html`, replace this block:

```html
    <app-source-tags [tags]="tags()" />
    <div class="actions">
```

…through its closing `</div>` (the whole `.actions` element, all three buttons) with:

```html
    <app-entry-meta
      [entry]="entry()"
      [tags]="tags()"
      (favorite)="favorite.emit($event)"
      (keep)="keep.emit($event)"
      (read)="read.emit($event)"
    />
```

In `frontend/src/app/reader/magazine/entry-hero.component.ts`:
- Delete the three `output<EntryDto>()` declarations (`favorite`, `keep`, `read`) — they now come from the base.
- Replace the `IconComponent`, `SourceTagsComponent` and `TranslocoPipe` imports with `EntryMetaComponent`, and update the `imports` array to `[EntryMetaComponent, EntryKickerLineComponent]`.
- Remove the now-unused `output` symbol from the `@angular/core` import and the now-unused `EntryDto` import.

The resulting header:

```ts
// src/app/reader/magazine/entry-hero.component.ts
import { Component, computed, effect, signal } from '@angular/core';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { EntryMetaComponent } from '../entry-meta/entry-meta.component';
import { entryImage } from '../preview-image';
import { EntryBlockBase } from './entry-block-base';

@Component({
  selector: 'app-entry-hero',
  imports: [EntryKickerLineComponent, EntryMetaComponent],
  templateUrl: './entry-hero.component.html',
  styleUrl: './entry-hero.component.scss',
})
export class EntryHeroComponent extends EntryBlockBase {
```

In `frontend/src/app/reader/magazine/entry-hero.component.scss`, delete the three now-dead rules `.actions`, `.actions button` and `.actions button.on`.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
npx jest src/app/reader/magazine/entry-hero --silent
```

Expected: PASS, the existing hero tests plus the two new ones.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/magazine/entry-block-base.ts frontend/src/app/reader/magazine/entry-hero.component.ts frontend/src/app/reader/magazine/entry-hero.component.html frontend/src/app/reader/magazine/entry-hero.component.scss frontend/src/app/reader/magazine/entry-hero.component.spec.ts
git commit -m "feat(#414): move the hero actions onto the shared meta row"
```

---

### Task 4: `wide`, `kicker` and `quote` adopt the row

**Files:**
- Modify: `frontend/src/app/reader/magazine/entry-wide.component.{ts,html}`
- Modify: `frontend/src/app/reader/magazine/entry-kicker.component.{ts,html}`
- Modify: `frontend/src/app/reader/magazine/entry-quote.component.{ts,html}`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html`
- Test: `frontend/src/app/reader/magazine/entry-wide.component.spec.ts`, `entry-kicker.component.spec.ts`, `entry-quote.component.spec.ts`

**Interfaces:**
- Consumes: `EntryMetaComponent` (Task 2); the inherited outputs from Task 3.
- Produces: nothing new. These three blocks render `<app-entry-meta>` in place of `<app-source-tags>`.

- [ ] **Step 1: Write the failing tests**

Add this test to **each** of the three specs, inside its existing `describe`. Adjust nothing but the file it goes in — the assertion is identical because the contract is identical.

```ts
  it('carries the three actions on its meta row', () => {
    const f = mount(entry());
    expect(f.nativeElement.querySelectorAll('app-entry-meta app-entry-actions button').length).toBe(
      3,
    );

    const read = jest.fn();
    f.componentInstance.read.subscribe(read);
    const buttons = f.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[2] as HTMLElement).click();
    expect(read).toHaveBeenCalled();
  });
```

All three specs already have a module-level `const entry = (over: Partial<EntryDto> = {}): EntryDto => ({…})` factory and a `function mount(e: EntryDto)`, so `mount(entry())` compiles as written. Do not restructure these files.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npx jest src/app/reader/magazine/entry-wide src/app/reader/magazine/entry-kicker.component src/app/reader/magazine/entry-quote --silent
```

Expected: FAIL — `app-entry-meta` is not rendered in any of the three.

- [ ] **Step 3: Switch the three templates**

In each of `entry-wide.component.html`, `entry-kicker.component.html` and `entry-quote.component.html`, replace:

```html
<app-source-tags [tags]="tags()" />
```

with:

```html
<app-entry-meta
  [entry]="entry()"
  [tags]="tags()"
  (favorite)="favorite.emit($event)"
  (keep)="keep.emit($event)"
  (read)="read.emit($event)"
/>
```

In each of `entry-wide.component.ts`, `entry-kicker.component.ts` and `entry-quote.component.ts`, replace the `SourceTagsComponent` import with:

```ts
import { EntryMetaComponent } from '../entry-meta/entry-meta.component';
```

and swap `SourceTagsComponent` for `EntryMetaComponent` in the `imports` array.

- [ ] **Step 4: Bind the outputs in the host**

In `frontend/src/app/reader/entry-list/entry-list.component.html`, add these three lines to the `<app-entry-wide>`, `<app-entry-quote>` and `<app-entry-kicker>` elements, directly above their existing `(open)` binding:

```html
                (favorite)="favorite.emit($event)"
                (keep)="keep.emit($event)"
                (read)="read.emit($event)"
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
npx jest src/app/reader/magazine src/app/reader/entry-list --silent
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/magazine frontend/src/app/reader/entry-list
git commit -m "feat(#414): put the actions on the wide, kicker and quote cards"
```

---

### Task 5: `split` and `thumb` — the row, and the bottom of a card with spare height

**Files:**
- Modify: `frontend/src/app/reader/magazine/entry-split.component.{ts,html,scss}`
- Modify: `frontend/src/app/reader/magazine/entry-thumb.component.{ts,html,scss}`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html`
- Test: `frontend/src/app/reader/magazine/entry-split.component.spec.ts`, `entry-thumb.component.spec.ts`

**Interfaces:**
- Consumes: `EntryMetaComponent` (Task 2); the inherited outputs from Task 3.
- Produces: nothing new.

**Why the SCSS changes:** both cards put the image beside the text. When the image is taller than the text, the card has spare height. `align-items: flex-start` makes the body hug its text, so `margin-top: auto` on the meta row has no free space to consume and the row stays up next to the dek, leaving a gap under it. Stretching the body gives the row that free space; the image is then pinned back to the top with `align-self`, because stretching it would break its bound `aspect-ratio` (split) and its fixed box (thumb).

- [ ] **Step 1: Write the failing tests**

Add to **each** of `entry-split.component.spec.ts` and `entry-thumb.component.spec.ts`, inside the existing `describe`:

```ts
  it('carries the three actions on its meta row', () => {
    const f = mount(entry());
    expect(f.nativeElement.querySelectorAll('app-entry-meta app-entry-actions button').length).toBe(
      3,
    );

    const favorite = jest.fn();
    f.componentInstance.favorite.subscribe(favorite);
    const buttons = f.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[0] as HTMLElement).click();
    expect(favorite).toHaveBeenCalled();
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npx jest src/app/reader/magazine/entry-split src/app/reader/magazine/entry-thumb --silent
```

Expected: FAIL — `app-entry-meta` is not rendered.

- [ ] **Step 3: Switch both templates**

In `entry-split.component.html` and `entry-thumb.component.html`, replace:

```html
    <app-source-tags [tags]="tags()" />
```

with:

```html
    <app-entry-meta
      [entry]="entry()"
      [tags]="tags()"
      (favorite)="favorite.emit($event)"
      (keep)="keep.emit($event)"
      (read)="read.emit($event)"
    />
```

In `entry-split.component.ts` and `entry-thumb.component.ts`, replace the `SourceTagsComponent` import with:

```ts
import { EntryMetaComponent } from '../entry-meta/entry-meta.component';
```

and swap it in the `imports` array.

- [ ] **Step 4: Let the row reach the bottom**

In `frontend/src/app/reader/magazine/entry-split.component.scss`, change the `.split` rule's `align-items: flex-start;` to:

```scss
  /* Stretch, so the text column fills a taller image's height and the meta row
     can drop to the bottom of the card. The image is pinned back to the top
     below — stretching it would override its bound aspect-ratio. */
  align-items: stretch;
```

and add to the `.img` rule, directly above its `margin-top`:

```scss
  align-self: flex-start;
```

Make the identical two edits in `frontend/src/app/reader/magazine/entry-thumb.component.scss` (`.thumb` and its `.img`), with the same comment except that the last clause reads `stretching it would override its fixed 88x66 box`.

- [ ] **Step 5: Bind the outputs in the host**

In `frontend/src/app/reader/entry-list/entry-list.component.html`, add to `<app-entry-split>` and `<app-entry-thumb>`, above their `(open)` binding:

```html
                (favorite)="favorite.emit($event)"
                (keep)="keep.emit($event)"
                (read)="read.emit($event)"
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
npx jest src/app/reader/magazine --silent
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/magazine frontend/src/app/reader/entry-list
git commit -m "feat(#414): drop the split and thumb meta row to the card bottom"
```

---

### Task 6: `compact` — the actions on the kicker line

**Files:**
- Modify: `frontend/src/app/reader/magazine/entry-kicker-line.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-compact.component.{ts,html,scss}`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html`
- Test: `frontend/src/app/reader/magazine/entry-compact.component.spec.ts`

**Interfaces:**
- Consumes: `EntryActionsComponent` (Task 1); the inherited outputs from Task 3.
- Produces: `EntryKickerLineComponent` now projects content: anything placed between its tags renders as the last item **inside** the kicker `<p>`.

**Why compact is different:** a source group shows up to five compacts. Giving each one a meta row of its own would add a full row per item and make groups much taller, while the right-hand side of the kicker line — which always renders, because it holds the time — is empty. So compact hangs the actions there and leaves its tag row pills-only.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/app/reader/magazine/entry-compact.component.spec.ts`, inside the existing `describe`.

**Note the local conventions in this file, which differ from the other block specs:** `entry` is a module-level `const` of type `EntryDto`, **not** a factory, and `mount()` takes **no** arguments. The tests below follow that. Do not restructure the file.

```ts
  it('hangs the actions on the kicker line, not on a row of their own', () => {
    const el = mount().nativeElement as HTMLElement;
    const actions = el.querySelector('app-entry-actions');
    expect(actions).not.toBeNull();
    // Inside the kicker's own <p>: a block-level wrapper would close the
    // paragraph and drop the icons onto a second line.
    expect(actions!.closest('p.kicker')).not.toBeNull();
    expect(el.querySelector('app-entry-meta')).toBeNull();
  });

  it('keeps the actions inside a source group, where there are no tags', () => {
    const f = mount();
    f.componentRef.setInput('showSource', false);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('app-source-tags')).toBeNull();
    expect(el.querySelectorAll('app-entry-actions button').length).toBe(3);
  });

  it('emits keep without opening the entry', () => {
    const f = mount();
    const keep = jest.fn();
    const open = jest.fn();
    f.componentInstance.keep.subscribe(keep);
    f.componentInstance.open.subscribe(open);

    const buttons = f.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[1] as HTMLElement).click();

    expect(keep).toHaveBeenCalled();
    expect(open).not.toHaveBeenCalled();
  });
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npx jest src/app/reader/magazine/entry-compact --silent
```

Expected: FAIL — no `app-entry-actions` in the compact card.

- [ ] **Step 3: Let the kicker line take projected content**

In `frontend/src/app/reader/magazine/entry-kicker-line.component.html`, add `<ng-content />` as the last child of the `<p class="kicker">`, directly after the `<span class="when">` element and before `</p>`:

```html
  <span class="when">{{ when() }}</span>
  <ng-content />
</p>
```

- [ ] **Step 4: Project the actions from compact**

In `frontend/src/app/reader/magazine/entry-compact.component.html`, replace the self-closing kicker line:

```html
    <app-entry-kicker-line [entry]="entry()" [showDot]="false" [showSource]="showSource()" />
```

with:

```html
    <app-entry-kicker-line [entry]="entry()" [showDot]="false" [showSource]="showSource()">
      <app-entry-actions
        [entry]="entry()"
        (favorite)="favorite.emit($event)"
        (keep)="keep.emit($event)"
        (read)="read.emit($event)"
      />
    </app-entry-kicker-line>
```

In `frontend/src/app/reader/magazine/entry-compact.component.ts`, add the import and register it:

```ts
import { EntryActionsComponent } from '../entry-actions/entry-actions.component';
```

```ts
  imports: [EntryKickerLineComponent, SourceTagsComponent, EntryActionsComponent],
```

In `frontend/src/app/reader/magazine/entry-compact.component.scss`, append:

```scss
/* The actions ride the kicker line rather than taking a row of their own: a
   source group shows up to five of these, and a row each would inflate the
   group by that many lines. `margin-inline-start: auto` is what pushes them
   past the time to the right edge. Styled from here, not from the kicker line,
   because the element is projected and so belongs to this component's scope. */
.compact app-entry-actions {
  flex: none;
  margin-inline-start: auto;
}
```

- [ ] **Step 5: Bind the outputs in the host**

In `frontend/src/app/reader/entry-list/entry-list.component.html`, add to `<app-entry-compact>`, above its `(open)` binding:

```html
                (favorite)="favorite.emit($event)"
                (keep)="keep.emit($event)"
                (read)="read.emit($event)"
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
npx jest src/app/reader/magazine --silent
```

Expected: PASS. The existing kicker-line tests must still pass — `<ng-content />` with nothing projected renders nothing.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/magazine frontend/src/app/reader/entry-list
git commit -m "feat(#414): hang the compact card's actions on its kicker line"
```

---

### Task 7: `source-group` forwards the actions

**Files:**
- Modify: `frontend/src/app/reader/magazine/source-group.component.ts`
- Modify: `frontend/src/app/reader/magazine/source-group.component.html`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html`
- Test: `frontend/src/app/reader/magazine/source-group.component.spec.ts`

**Interfaces:**
- Consumes: the compact card's outputs from Task 6.
- Produces: `SourceGroupComponent` gains `favorite`, `keep`, `read`, each `output<EntryDto>()`, emitting the entry of the row whose button was pressed — **not** the group's first entry.

**Why this task exists separately:** `source-group` is not an `EntryBlockBase`, so it inherits nothing. Its compacts are inside a `@for`, and a forwarding chain that compiles, renders and silently emits the wrong entry is exactly the failure this test has to rule out.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/app/reader/magazine/source-group.component.spec.ts`, inside the existing `describe`:

```ts
  it('forwards an action from the row it was pressed on', () => {
    const f = mount([e(1), e(2), e(3)], 3);
    const favorite = jest.fn();
    f.componentInstance.favorite.subscribe(favorite);

    const rows = f.nativeElement.querySelectorAll('app-entry-compact');
    const secondRowStar = rows[1].querySelectorAll('app-entry-actions button')[0] as HTMLElement;
    secondRowStar.click();

    expect(favorite).toHaveBeenCalledWith(expect.objectContaining({ id: 2 }));
  });

  it('forwards keep and read as well', () => {
    const f = mount([e(1)], 1);
    const keep = jest.fn();
    const read = jest.fn();
    f.componentInstance.keep.subscribe(keep);
    f.componentInstance.read.subscribe(read);

    const buttons = f.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[1] as HTMLElement).click();
    (buttons[2] as HTMLElement).click();

    expect(keep).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(read).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
  });
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npx jest src/app/reader/magazine/source-group --silent
```

Expected: FAIL — `f.componentInstance.favorite` is undefined.

- [ ] **Step 3: Add and forward the outputs**

In `frontend/src/app/reader/magazine/source-group.component.ts`, add under `readonly open = output<EntryDto>();`:

```ts
  /** Forwarded from the rows. The group is not an `EntryBlockBase`, so unlike
   *  every block it has to declare these itself. */
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
```

In `frontend/src/app/reader/magazine/source-group.component.html`, extend the compact element:

```html
        <app-entry-compact
          [entry]="item"
          [showSource]="false"
          (favorite)="favorite.emit($event)"
          (keep)="keep.emit($event)"
          (read)="read.emit($event)"
          (open)="open.emit($event)"
        />
```

- [ ] **Step 4: Bind the outputs in the host**

In `frontend/src/app/reader/entry-list/entry-list.component.html`, add to `<app-source-group>`, above its `(open)` binding:

```html
                (favorite)="favorite.emit($event)"
                (keep)="keep.emit($event)"
                (read)="read.emit($event)"
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
npx jest src/app/reader --silent
```

Expected: PASS across the whole reader suite.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/magazine frontend/src/app/reader/entry-list
git commit -m "feat(#414): forward the actions out of a source group"
```

---

### Task 8: Documentation and the full gate

**Files:**
- Modify: `docs/design-language.md`

**Interfaces:**
- Consumes: everything above.
- Produces: the catalog entry and the recorded exception.

- [ ] **Step 1: Add the catalog entry**

In `docs/design-language.md`, in the component catalog (§2), add an entry in the file's existing style for the catalog:

```markdown
### `app-entry-meta`

The line a magazine card ends on: `app-source-tags` on the left, `app-entry-actions`
(favorite, keep, mark read) right-aligned against them. Six of the seven magazine
blocks use it — hero, wide, split, thumb, kicker, quote.

Two pieces of its geometry are load-bearing and are why this is a component rather
than a row assembled per block. `align-items: flex-end` keeps the icons level with
the **last** line of a wrapping pill list, so the card's bottom edge stays the
reference. `margin-top: auto` drops the whole row to the bottom of a card whose
image is taller than its text — `split` and `thumb`, which stretch their body for
exactly this — and is inert everywhere else.

`app-entry-actions` is separately usable, and `entry-compact` uses it on its own:
see the exception below. It renders three buttons with no wrapper element, because
it is projected into a `<p>`.
```

- [ ] **Step 2: Record the exception**

In the recorded-exceptions section of `docs/design-language.md`, add:

```markdown
**`entry-compact` hangs its actions on the kicker line, not on an `app-entry-meta`
row.** A source group shows up to five compact rows; a meta row each would add a
full line per item and inflate the group, while the right-hand end of the kicker
line is already empty — it only holds the time. So compact projects
`app-entry-actions` into the kicker line and leaves its tag row pills-only. The
projected element must stay inline: the kicker is a `<p>`, and the HTML parser
closes a paragraph at a block-level child, which drops the icons onto a second
line without any error.
```

- [ ] **Step 3: Run the full gate**

```bash
npm run check
```

Run from `frontend/`. Expected: ESLint, Prettier, Stylelint and Jest all pass. Fix anything it reports — in particular Prettier's 100-column rule on the new templates.

- [ ] **Step 4: Verify the build**

```bash
npm run build
```

Expected: success. This catches an unused import or a template type error that Jest can miss.

- [ ] **Step 5: Commit**

```bash
git add docs/design-language.md
git commit -m "docs(#414): record the entry meta row and the compact exception"
```

---

## Verification before the PR

These are **not** optional, and green unit tests do not stand in for them.

- [ ] **Real render.** Bring the Docker stack up from the repo root with `docker compose up -d`, open the reader in the magazine layout, and confirm on real entries: every card kind shows the three icons; the icons sit at the right of the tag row; a `split` card with a tall image shows the row at the card's bottom; a source group's rows carry the icons on the "x ago" line and the group has not grown taller.
- [ ] **The actions work.** Press each of the three on a card of each kind and confirm the entry does not open and the state sticks after a refresh.
- [ ] **Touch.** With the browser's device emulation on a phone profile, confirm the hit boxes do not overlap — a tap on the star must never toggle keep — and that no card is taller than it is with a mouse.
- [ ] **Screenshots** of the magazine view in light and dark for the PR body.
