# For You Recommendation Strip Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the For You recommendation strip (reason + optional debug score) show in every reading layout, with the markup living in one shared component.

**Architecture:** A single `RecommendationStripComponent` owns the strip markup and styles and self-gates on `recommendationReason`. `entry-list.component.html` wraps both the list/pane row and the magazine card `@switch` with it, so no card template knows about recommendations. For You stops grouping so every entry is its own card and the strip always attaches.

**Tech Stack:** Angular 20 (standalone components, signals), SCSS with design tokens, Jest (jsdom).

## Global Constraints

- Standalone components and signals; no NgModules.
- Component styles live in a sibling `.scss` file via `styleUrl`, never inline.
- No hex colours and no raw `px` in `.scss` outside `src/app/theme/` — use tokens (`var(--space-*)`, `var(--fs-*)`, `var(--accent*)`, `var(--radius-pill)`).
- Prettier 100-column limit.
- The CI gate is `npm run check` (ESLint + Prettier + Stylelint + Jest), run from `frontend/`.
- Reply/prose language is ASD-STE100; code, comments, and commit messages are unaffected.

---

### Task 1: The shared RecommendationStripComponent

**Files:**
- Create: `frontend/src/app/reader/recommendation-strip/recommendation-strip.component.ts`
- Create: `frontend/src/app/reader/recommendation-strip/recommendation-strip.component.html`
- Create: `frontend/src/app/reader/recommendation-strip/recommendation-strip.component.scss`
- Test: `frontend/src/app/reader/recommendation-strip/recommendation-strip.component.spec.ts`

**Interfaces:**
- Produces: `RecommendationStripComponent`, selector `app-recommendation-strip`, one input `entry = input<EntryDto | null>(null)`. Projects its content via `<ng-content />` and renders a `.reason` line (with an optional `.score` span) below the projected content when `entry.recommendationReason` is set.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/reader/recommendation-strip/recommendation-strip.component.spec.ts`:

```typescript
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { RecommendationStripComponent } from './recommendation-strip.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Hello',
  url: null,
  author: null,
  summary: 's',
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 5,
  source: 'heise',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

@Component({
  standalone: true,
  imports: [RecommendationStripComponent],
  template: `<app-recommendation-strip [entry]="e"
    ><p class="card">card</p></app-recommendation-strip
  >`,
})
class Host {
  e: EntryDto | null = null;
}

function mount(e: EntryDto | null) {
  const f = TestBed.createComponent(Host);
  f.componentInstance.e = e;
  f.detectChanges();
  return f.nativeElement as HTMLElement;
}

describe('RecommendationStripComponent', () => {
  beforeEach(() => TestBed.configureTestingModule({ imports: [Host] }));

  it('always projects its card content', () => {
    expect(mount(null).querySelector('.card')!.textContent).toContain('card');
  });

  it('renders nothing extra without a reason', () => {
    expect(mount(entry()).querySelector('.reason')).toBeNull();
    expect(mount(entry({ recommendationReason: null })).querySelector('.reason')).toBeNull();
    expect(mount(null).querySelector('.reason')).toBeNull();
  });

  it('renders the reason below the card when present', () => {
    const el = mount(entry({ recommendationReason: 'because you read heise' }));
    expect(el.querySelector('.reason')!.textContent).toContain('because you read heise');
  });

  it('renders the score pill only when the score is a number', () => {
    const withScore = mount(
      entry({ recommendationReason: 'r', recommendationScore: 82 }),
    );
    expect(withScore.querySelector('.reason .score')!.textContent).toContain('82');

    const noScore = mount(entry({ recommendationReason: 'r' }));
    expect(noScore.querySelector('.reason .score')).toBeNull();

    const nullScore = mount(entry({ recommendationReason: 'r', recommendationScore: null }));
    expect(nullScore.querySelector('.reason .score')).toBeNull();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx jest src/app/reader/recommendation-strip`
Expected: FAIL — cannot resolve `./recommendation-strip.component`.

- [ ] **Step 3: Write the component**

Create `frontend/src/app/reader/recommendation-strip/recommendation-strip.component.ts`:

```typescript
// src/app/reader/recommendation-strip/recommendation-strip.component.ts
import { Component, input } from '@angular/core';
import { EntryDto } from '../models';

/** Wraps one entry's card and, only for a for-you result, renders the
 *  recommender's reason beneath it — plus the 0-100 score when the user's
 *  debug setting is on and the backend therefore sent it. The card is
 *  projected, so no card component knows about recommendations. The input is
 *  nullable so the magazine layout can wrap group blocks, which carry no single
 *  entry, with the same wrapper. */
@Component({
  selector: 'app-recommendation-strip',
  templateUrl: './recommendation-strip.component.html',
  styleUrl: './recommendation-strip.component.scss',
})
export class RecommendationStripComponent {
  readonly entry = input<EntryDto | null>(null);
}
```

Create `frontend/src/app/reader/recommendation-strip/recommendation-strip.component.html`:

```html
<ng-content />
@if (entry(); as e) {
  @if (e.recommendationReason) {
    <p class="reason">
      @if (e.recommendationScore !== null && e.recommendationScore !== undefined) {
        <span class="score">{{ e.recommendationScore }}</span>
      }
      {{ e.recommendationReason }}
    </p>
  }
}
```

Create `frontend/src/app/reader/recommendation-strip/recommendation-strip.component.scss` (moved verbatim from `entry-row.component.scss`, plus a block host so card and strip stay one flex item):

```scss
:host {
  display: block;
  width: 100%;
}

.reason {
  margin: var(--space-1) 0 0;
  color: var(--text-muted);
  font-size: var(--fs-sm);
  font-style: italic;
}

.reason .score {
  display: inline-block;
  margin-right: var(--space-1);
  padding: 0 var(--space-2);
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent);
  font-size: var(--fs-xs);
  font-style: normal;
  font-weight: 700;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx jest src/app/reader/recommendation-strip`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/recommendation-strip
git commit -m "feat(#331): add a shared recommendation strip component"
```

---

### Task 2: Wire both layouts through the strip; remove it from entry-row

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts` (imports array; add `strippableEntry` helper)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html:122-256` (both branches)
- Modify: `frontend/src/app/reader/entry-row/entry-row.component.html:18-25` (delete)
- Modify: `frontend/src/app/reader/entry-row/entry-row.component.scss` (delete `.reason` and `.reason .score`)
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts` (add), `frontend/src/app/reader/entry-row/entry-row.component.spec.ts` (remove moved cases)

**Interfaces:**
- Consumes: `RecommendationStripComponent` from Task 1.
- Produces: `strippableEntry(block: MagazineBlock): EntryDto | null` on `EntryListComponent` — the entry for a single-entry block, `null` for a `group` block.

- [ ] **Step 1: Write the failing tests**

In `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`, add a new `describe` block (place it after the existing `topBlock` describe). It mounts a For You list in each layout and asserts the strip is present per entry:

```typescript
describe('recommendation strip', () => {
  const recommended = [
    entry(1, { recommendationReason: 'because you read src', recommendationScore: 91 }),
    entry(2, { recommendationReason: 'similar to your favorites' }),
  ];
  const forYou = { kind: 'for-you', id: null, unread: false };

  it('shows the reason on each for-you entry in the list layout', () => {
    const el = mount({ entries: recommended, selection: forYou, layout: 'list' })
      .nativeElement as HTMLElement;
    const reasons = el.querySelectorAll('app-recommendation-strip .reason');
    expect(reasons.length).toBe(2);
    expect(reasons[0].textContent).toContain('because you read src');
    expect(el.querySelector('app-recommendation-strip .reason .score')!.textContent).toContain(
      '91',
    );
  });

  it('shows the reason on each for-you entry in the magazine layout', () => {
    const el = mount({ entries: recommended, selection: forYou, layout: 'magazine' })
      .nativeElement as HTMLElement;
    const reasons = el.querySelectorAll('app-recommendation-strip .reason');
    expect(reasons.length).toBe(2);
    expect(reasons[0].textContent).toContain('because you read src');
  });

  it('stays inert on a non-for-you view', () => {
    const el = mount({ entries: [entry(1), entry(2)], selection: { kind: 'all', id: null, unread: true } })
      .nativeElement as HTMLElement;
    expect(el.querySelector('.reason')).toBeNull();
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npx jest src/app/reader/entry-list --t "recommendation strip"`
Expected: FAIL — no `app-recommendation-strip` in the DOM yet.

- [ ] **Step 3: Add the component to the imports and the helper**

In `frontend/src/app/reader/entry-list/entry-list.component.ts`, add the import near the other component imports:

```typescript
import { RecommendationStripComponent } from '../recommendation-strip/recommendation-strip.component';
```

Add `RecommendationStripComponent` to the component's `imports` array.

Add the helper next to `entryOf` (around line 388):

```typescript
  /** The entry a recommendation strip should read, or null for a group block
   *  (which carries several entries and no single reason to show). */
  strippableEntry(block: MagazineBlock): EntryDto | null {
    return block.kind === 'group' ? null : block.entry;
  }
```

- [ ] **Step 4: Wrap both branches in the template**

In `frontend/src/app/reader/entry-list/entry-list.component.html`, wrap the magazine `@for` body (lines 137-203) so the whole `@switch` is projected:

```html
    @for (b of blocks(); track blockKey(b)) {
      <app-recommendation-strip [entry]="strippableEntry(b)">
        @switch (b.kind) {
          @case ('hero') {
            <app-entry-hero
              [entry]="entryOf(b)"
              [tags]="tagsFor(entryOf(b).subscriptionId)"
              (favorite)="favorite.emit($event)"
              (keep)="keep.emit($event)"
              (read)="read.emit($event)"
              (open)="open.emit($event)"
            />
          }
          @case ('wide') {
            <app-entry-wide
              [entry]="entryOf(b)"
              [tags]="tagsFor(entryOf(b).subscriptionId)"
              (open)="open.emit($event)"
            />
          }
          @case ('quote') {
            <app-entry-quote
              [entry]="entryOf(b)"
              [tags]="tagsFor(entryOf(b).subscriptionId)"
              (open)="open.emit($event)"
            />
          }
          @case ('split') {
            <app-entry-split
              [entry]="entryOf(b)"
              [imageSide]="side(b)"
              [tags]="tagsFor(entryOf(b).subscriptionId)"
              (open)="open.emit($event)"
            />
          }
          @case ('kicker') {
            <app-entry-kicker
              [entry]="entryOf(b)"
              [tags]="tagsFor(entryOf(b).subscriptionId)"
              (open)="open.emit($event)"
            />
          }
          @case ('thumb') {
            <app-entry-thumb
              [entry]="entryOf(b)"
              [tags]="tagsFor(entryOf(b).subscriptionId)"
              (open)="open.emit($event)"
            />
          }
          @case ('compact') {
            <app-entry-compact
              [entry]="entryOf(b)"
              [tags]="tagsFor(entryOf(b).subscriptionId)"
              (open)="open.emit($event)"
            />
          }
          @case ('group') {
            <app-source-group
              [source]="grp(b).source"
              [subscriptionId]="grp(b).subscriptionId"
              [entries]="grp(b).entries"
              [previewCount]="grp(b).previewCount"
              [tags]="tagsFor(grp(b).subscriptionId)"
              (open)="open.emit($event)"
            />
          }
        }
      </app-recommendation-strip>
    }
```

Wrap the list/pane `@for` body (lines 232-242) too:

```html
    @for (e of entries(); track e.id) {
      <app-recommendation-strip [entry]="e">
        <app-entry-row
          [entry]="e"
          [tags]="tagsFor(e.subscriptionId)"
          [class.open]="openEntryId() === e.id"
          (favorite)="favorite.emit($event)"
          (keep)="keep.emit($event)"
          (read)="read.emit($event)"
          (open)="open.emit($event)"
        />
      </app-recommendation-strip>
    }
```

- [ ] **Step 5: Remove the strip from entry-row**

Delete lines 18-25 (the `@if (entry().recommendationReason) { ... }` block) from `frontend/src/app/reader/entry-row/entry-row.component.html`.

Delete the `.reason` and `.reason .score` rules from `frontend/src/app/reader/entry-row/entry-row.component.scss` (they now live in the strip component).

In `frontend/src/app/reader/entry-row/entry-row.component.spec.ts`, remove the four cases that assert the moved markup: `renders the recommendation reason when present`, `omits the reason line when recommendationReason is null or absent`, `renders the recommendation score as a pill`, and `omits the score pill when recommendationScore is absent`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd frontend && npx jest src/app/reader/entry-list src/app/reader/entry-row src/app/reader/recommendation-strip`
Expected: PASS — the new strip tests pass and the trimmed entry-row/entry-list suites stay green.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/entry-list frontend/src/app/reader/entry-row
git commit -m "feat(#331): render the recommendation strip in every reading layout"
```

---

### Task 3: Stop grouping the For You list

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts:186-192` (the `grouping` argument)
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts` (add)

**Interfaces:**
- Consumes: the `blocks` computed and `mount` helper.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/app/reader/entry-list/entry-list.component.spec.ts` a `describe` that proves the flip. The fixture is three active sources with an eight-long same-source run, which collapses under an aggregated view but must not under For You:

```typescript
describe('for-you grouping', () => {
  const now = '2026-07-22T11:00:00Z';
  const run = [
    ...Array.from({ length: 8 }, (_, i) =>
      entry(i + 1, { subscriptionId: 1, source: 'a', publishedAt: now }),
    ),
    entry(9, { subscriptionId: 2, source: 'b', publishedAt: now }),
    entry(10, { subscriptionId: 2, source: 'b', publishedAt: now }),
    entry(11, { subscriptionId: 3, source: 'c', publishedAt: now }),
    entry(12, { subscriptionId: 3, source: 'c', publishedAt: now }),
  ];

  it('collapses a same-source run in an aggregated view', () => {
    const f = mount({ entries: run, selection: { kind: 'all', id: null, unread: false }, layout: 'magazine' });
    expect(f.componentInstance.blocks().some((b) => b.kind === 'group')).toBe(true);
  });

  it('never collapses the for-you list', () => {
    const f = mount({ entries: run, selection: { kind: 'for-you', id: null, unread: false }, layout: 'magazine' });
    expect(f.componentInstance.blocks().some((b) => b.kind === 'group')).toBe(false);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx jest src/app/reader/entry-list --t "for-you grouping"`
Expected: FAIL on `never collapses the for-you list` — For You still groups.

- [ ] **Step 3: Flip the grouping flag**

In `frontend/src/app/reader/entry-list/entry-list.component.ts`, change the `blocks` computed so For You is non-grouping. Read the selection kind once for clarity:

```typescript
  readonly blocks = computed<MagazineBlock[]>(() => {
    const kind = this.selection().kind;
    return planMagazine({
      entries: this.entries(),
      // A subscription is one source, and the for-you list is a ranked stream:
      // neither should collapse same-source runs into a widget that hides
      // entries and disrupts their order.
      grouping: kind !== 'subscription' && kind !== 'for-you',
      complete: !this.hasMore(),
    });
  });
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx jest src/app/reader/entry-list --t "for-you grouping"`
Expected: PASS (both cases).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/entry-list/entry-list.component.ts frontend/src/app/reader/entry-list/entry-list.component.spec.ts
git commit -m "fix(#331): stop collapsing the for-you list into source groups"
```

---

### Task 4: Gate and real-render verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full frontend gate**

Run: `cd frontend && npm run check`
Expected: PASS — ESLint, Prettier, Stylelint, and the whole Jest suite are green. Fix any Prettier/Stylelint findings before continuing (100-column wraps, token-only values).

- [ ] **Step 2: Verify on a real render**

Start the stack and the dev server, sign in, and open the For You list. Confirm in **both** the magazine layout (the default) and the list layout, in **both** light and dark:

- Each For You entry shows the reason as a tight caption directly below its card.
- With the recommendation debug setting **on**, the score pill shows before the reason; with it **off**, the reason shows without the pill.
- Magazine cards keep their measure. `.rows.magazine > *` now applies its `width: 100%` and `max-width: 680px` to the wrapper (the new direct child), and the card fills the wrapper via the `:host` block rule — so the centered 680px content measure is unchanged. Confirm no card shrank or shifted.
- A non-For-You view (All / a tag / a subscription) looks exactly as before — no stray caption.

- [ ] **Step 3: Update the memory note if needed**

If the real render revealed a layout nuance worth keeping, record it. Otherwise skip.

- [ ] **Step 4: Final commit if the render forced a tweak**

```bash
git add -A
git commit -m "fix(#331): <describe the render tweak>"
```

Skip this step if Step 2 needed no change.

---

## Notes for the implementer

- The strip must NOT depend on Transloco — the reason and score are plain data.
- Do not touch the backend, the DTOs, or how the debug setting is stored. The data already arrives correctly (`RecommendationFeedJson` sends `recommendationReason` always and `recommendationScore` only when `debugEnabled`).
- The existing `topBlock` tests assert `rows.firstElementChild` is the top marker; the strip wrappers are later siblings, so those tests stay valid. Run them to confirm.
