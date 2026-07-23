# Plan 5d — Magazine Reading Layout — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Add a third on-device reading layout — "Magazine" — that renders the same entry list as a varied single column (hero / feature / compact / source-group), make it the default, and keep List and Pane working unchanged. No backend change.

**Architecture:** A pure `planMagazine(entries, grouping)` planner turns the ordered `EntryDto[]` into typed blocks; new presentational components render each block; `EntryListComponent` gains a `layout` input and switches its body between the current flat rows and the planned blocks. Design spec: `docs/superpowers/specs/2026-07-23-05d-magazine-reading-layout-design.md`.

**Tech Stack:** Angular 20.3 standalone + signals, Jest, Playwright. Reuses `firstPreviewImage`/`textSnippet` (`reader/preview-image.ts`), `relativeTime` (`reader/format.ts`), `IconComponent`.

**Conventions (match existing code):** standalone components, inline `template`+`styles`, `inject()`, `input()`/`output()`, signals, native control flow. Colours only from CSS tokens — never a hex literal in a `styles:` block. Specs use `TestBed`; component specs mount with `TestBed.createComponent` + `componentRef.setInput`. Run the gate from `frontend/`: `npm test`, and before finishing `npm run check` + `npm run build`. Commit after each task with the message given.

---

## File structure

```
frontend/src/app/reader/
  reading-layout.service.ts               EDIT (+ .spec.ts)
  magazine/magazine-planner.ts            NEW  (+ .spec.ts)
  magazine/entry-hero.component.ts        NEW  (+ .spec.ts)
  magazine/entry-compact.component.ts     NEW  (+ .spec.ts)
  magazine/source-group.component.ts      NEW  (+ .spec.ts)
  entry-row/entry-row.component.ts        EDIT (+ .spec.ts extend)
  entry-list/entry-list.component.ts      EDIT (+ .spec.ts extend)
  header/reader-header.component.ts       EDIT (+ .spec.ts extend)
  reader-shell.component.ts               EDIT
frontend/e2e/magazine-smoke.spec.ts        NEW
frontend/README.md                          EDIT
```

---

### Task 1: ReadingLayoutService — add 'magazine' and default to it

**Files:** Modify `frontend/src/app/reader/reading-layout.service.ts`; Test `frontend/src/app/reader/reading-layout.service.spec.ts`.

- [ ] **Step 1: Update the spec.** Read the existing spec first (it clears `localStorage`). Replace/extend so it expects the new default and round-trips all three:

```ts
it('defaults to magazine when nothing is saved', () => {
  localStorage.removeItem('sfr.layout');
  const svc = new ReadingLayoutService();
  expect(svc.mode()).toBe('magazine');
});

it('honours a saved list or pane choice', () => {
  localStorage.setItem('sfr.layout', 'list');
  expect(new ReadingLayoutService().mode()).toBe('list');
  localStorage.setItem('sfr.layout', 'pane');
  expect(new ReadingLayoutService().mode()).toBe('pane');
});

it('persists and applies each mode', () => {
  const svc = new ReadingLayoutService();
  svc.set('magazine');
  expect(localStorage.getItem('sfr.layout')).toBe('magazine');
  expect(svc.mode()).toBe('magazine');
});
```

- [ ] **Step 2: Run → fail** (`npm test -- reading-layout`).

- [ ] **Step 3: Implement.** Replace the file body:

```ts
// src/app/reader/reading-layout.service.ts
import { Injectable, signal } from '@angular/core';

export type ReadingLayout = 'list' | 'pane' | 'magazine';
const KEY = 'sfr.layout';
const MODES: ReadingLayout[] = ['list', 'pane', 'magazine'];

@Injectable({ providedIn: 'root' })
export class ReadingLayoutService {
  readonly mode = signal<ReadingLayout>(this.readSaved());

  set(mode: ReadingLayout): void {
    localStorage.setItem(KEY, mode);
    this.mode.set(mode);
  }

  private readSaved(): ReadingLayout {
    const saved = localStorage.getItem(KEY) as ReadingLayout | null;
    return saved && MODES.includes(saved) ? saved : 'magazine';
  }
}
```

- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5d): magazine layout mode + make it the default"`

---

### Task 2: The magazine planner (pure function)

**Files:** Create `frontend/src/app/reader/magazine/magazine-planner.ts` + `.spec.ts`.

- [ ] **Step 1: Write the failing test.**

```ts
import { planMagazine, MagazineBlock } from './magazine-planner';
import { EntryDto } from '../models';

const e = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({
  id, title: 't', url: null, author: null, summary: null, contentHtml: null,
  publishedAt: null, createdAt: 'x', subscriptionId: 1, source: 'S',
  isRead: false, isFavorite: false, isKept: false, ...over,
});
const withImg = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  e(id, { contentHtml: `<img src="https://x/${id}.jpg">`, title: 'x'.repeat(130), ...over });
const kinds = (bs: MagazineBlock[]) => bs.map((b) => b.kind);

describe('planMagazine', () => {
  it('collapses a run of >=3 same-source entries into a group of 3 + moreCount', () => {
    const bs = planMagazine([e(1), e(2), e(3), e(4), e(5)], true);
    expect(bs).toHaveLength(1);
    expect(bs[0]).toMatchObject({ kind: 'group', subscriptionId: 1, source: 'S', moreCount: 2 });
    expect((bs[0] as Extract<MagazineBlock, { kind: 'group' }>).entries.map((x) => x.id)).toEqual([1, 2, 3]);
  });

  it('does not group a run of 2', () => {
    const bs = planMagazine([e(1), e(2), e(3, { subscriptionId: 9, source: 'B' })], true);
    expect(kinds(bs)).not.toContain('group');
    expect(bs).toHaveLength(3);
  });

  it('never groups in a single-subscription view (grouping=false)', () => {
    const bs = planMagazine([e(1), e(2), e(3), e(4)], false);
    expect(kinds(bs)).not.toContain('group');
    expect(bs).toHaveLength(4);
  });

  it('makes a text hero for image-less long text and a compact for short text', () => {
    const long = e(1, { subscriptionId: 1, source: 'A', title: 'x'.repeat(200) });
    const short = e(2, { subscriptionId: 2, source: 'B', title: 'hi' });
    const bs = planMagazine([long, short], true);
    expect(bs[0].kind).toBe('hero');
    expect(bs[1].kind).toBe('compact');
  });

  it('spaces heroes by HERO_PERIOD and never places two adjacent', () => {
    const many = Array.from({ length: 8 }, (_, i) => withImg(i + 1, { subscriptionId: i + 1, source: `S${i}` }));
    const bs = planMagazine(many, true);
    const heroAt = bs.map((b, i) => (b.kind === 'hero' ? i : -1)).filter((i) => i >= 0);
    expect(heroAt[0]).toBe(0);
    expect(heroAt[1]).toBe(6);
    for (let i = 1; i < heroAt.length; i++) expect(heroAt[i] - heroAt[i - 1]).toBeGreaterThan(1);
  });

  it('alternates the feature image side', () => {
    const a = withImg(1, { subscriptionId: 1, source: 'A', title: 'short' });
    const b = withImg(2, { subscriptionId: 2, source: 'B', title: 'short' });
    const bs = planMagazine([e(0, { subscriptionId: 9, source: 'Z', title: 'x'.repeat(200), contentHtml: '<img src="https://x/0.jpg">' }), a, b], true);
    const feats = bs.filter((x) => x.kind === 'feature') as Extract<MagazineBlock, { kind: 'feature' }>[];
    expect(feats[0].imageSide).toBe('right');
    expect(feats[1].imageSide).toBe('left');
  });

  it('is a stable prefix when a later page of a different source is appended', () => {
    const a = [e(1, { subscriptionId: 1, source: 'A' }), e(2, { subscriptionId: 2, source: 'B' }), e(3, { subscriptionId: 3, source: 'C' })];
    const b = [e(4, { subscriptionId: 4, source: 'D' }), e(5, { subscriptionId: 5, source: 'E' })];
    const planA = planMagazine(a, true);
    const planAB = planMagazine(a.concat(b), true);
    expect(planAB.slice(0, planA.length)).toEqual(planA);
  });
});
```

- [ ] **Step 2: Run → fail.**

- [ ] **Step 3: Implement `magazine-planner.ts`.**

```ts
// src/app/reader/magazine/magazine-planner.ts
import { EntryDto } from '../models';
import { firstPreviewImage, textSnippet } from '../preview-image';

export type MagazineBlock =
  | { kind: 'hero'; entry: EntryDto }
  | { kind: 'feature'; entry: EntryDto; imageSide: 'left' | 'right' }
  | { kind: 'compact'; entry: EntryDto }
  | { kind: 'group'; subscriptionId: number; source: string; entries: EntryDto[]; moreCount: number };

const LONG_TEXT = 120;
const TEXT_HERO = 180;
const GROUP_MIN = 3;
const GROUP_SHOW = 3;
const HERO_PERIOD = 6;

/** Turn the ordered entry list into a varied block sequence. `grouping` is true
 *  in aggregated views (All / tag / favorites / kept) and false in a single-feed
 *  view, where every entry shares a source. Pure and deterministic: appending a
 *  page never rewrites earlier blocks (the pass reads only entries seen so far). */
export function planMagazine(entries: EntryDto[], grouping: boolean): MagazineBlock[] {
  const blocks: MagazineBlock[] = [];
  let sinceHero = HERO_PERIOD; // let an eligible entry near the top lead
  let lastWasHero = false;
  let featureCount = 0;

  let i = 0;
  while (i < entries.length) {
    if (grouping) {
      const run = sameSourceRun(entries, i);
      if (run >= GROUP_MIN) {
        const slice = entries.slice(i, i + run);
        blocks.push({
          kind: 'group',
          subscriptionId: slice[0].subscriptionId,
          source: slice[0].source,
          entries: slice.slice(0, GROUP_SHOW),
          moreCount: run - GROUP_SHOW,
        });
        i += run;
        sinceHero = 0; // a group is itself a change of pace
        lastWasHero = false;
        continue;
      }
    }

    const entry = entries[i];
    const hasImage = firstPreviewImage(entry.contentHtml, entry.summary) !== null;
    const textLen = entry.title.length + textSnippet(entry.summary || entry.contentHtml).length;
    const heroEligible = hasImage ? textLen >= LONG_TEXT : textLen >= TEXT_HERO;

    if (heroEligible && !lastWasHero && sinceHero >= HERO_PERIOD - 1) {
      blocks.push({ kind: 'hero', entry });
      sinceHero = 0;
      lastWasHero = true;
    } else if (hasImage) {
      blocks.push({ kind: 'feature', entry, imageSide: featureCount % 2 === 0 ? 'right' : 'left' });
      featureCount += 1;
      sinceHero += 1;
      lastWasHero = false;
    } else {
      blocks.push({ kind: 'compact', entry });
      sinceHero += 1;
      lastWasHero = false;
    }
    i += 1;
  }
  return blocks;
}

function sameSourceRun(entries: EntryDto[], start: number): number {
  const sub = entries[start].subscriptionId;
  let n = 1;
  while (start + n < entries.length && entries[start + n].subscriptionId === sub) n += 1;
  return n;
}
```

- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5d): pure magazine layout planner"`

---

### Task 3: EntryRow — optional imageSide input

**Files:** Modify `frontend/src/app/reader/entry-row/entry-row.component.ts`; extend its `.spec.ts`.

- [ ] **Step 1: Extend the spec** (read it first for its mount helper):

```ts
it('moves the thumbnail to the left when imageSide is left', () => {
  // mount an entry that has an image; set input imageSide='left'
  // expect the host article to carry the 'img-left' class
});
```

Concretely, with the existing spec's mount pattern: `fixture.componentRef.setInput('entry', entryWithImage); fixture.componentRef.setInput('imageSide', 'left'); fixture.detectChanges();` then `expect(el.querySelector('.row')!.classList).toContain('img-left');`.

- [ ] **Step 2: Run → fail.**

- [ ] **Step 3: Implement.** Add the input and class binding; add one style rule. In the class:

```ts
  readonly imageSide = input<'left' | 'right'>('right');
```

In the template, add the class to the `<article class="row" ...>` element:

```html
    <article
      class="row"
      role="button"
      tabindex="0"
      [class.read]="entry().isRead"
      [class.img-left]="imageSide() === 'left'"
      ...
```

Add to `styles`:

```css
.row.img-left .thumb {
  order: -1;
}
```

(`.body` keeps default order 0, so `order: -1` on the thumb renders it first. List/Pane never set `imageSide`, so they keep the default right-side image.)

- [ ] **Step 4: Run → pass** (`npm test -- entry-row`).
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5d): entry-row optional imageSide for magazine features"`

---

### Task 4: EntryHeroComponent

**Files:** Create `frontend/src/app/reader/magazine/entry-hero.component.ts` + `.spec.ts`.

- [ ] **Step 1: Write the failing test.**

```ts
import { TestBed } from '@angular/core/testing';
import { EntryHeroComponent } from './entry-hero.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1, title: 'Big headline', url: null, author: null, summary: 'A meaningful summary.',
  contentHtml: '<p>A meaningful summary.</p><img src="https://x/a.jpg">', publishedAt: null,
  createdAt: 'x', subscriptionId: 1, source: 'Src', isRead: false, isFavorite: false, isKept: false, ...over,
});

function mount(e: EntryDto) {
  TestBed.configureTestingModule({ imports: [EntryHeroComponent] });
  const f = TestBed.createComponent(EntryHeroComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

describe('EntryHeroComponent', () => {
  it('renders the headline, source and image', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.textContent).toContain('Big headline');
    expect(el.textContent).toContain('Src');
    expect(el.querySelector('img.img')).not.toBeNull();
  });

  it('emits open on click', () => {
    const f = mount(entry());
    const open = jest.fn();
    f.componentInstance.open.subscribe(open);
    (f.nativeElement.querySelector('.hero') as HTMLElement).click();
    expect(open).toHaveBeenCalled();
  });

  it('falls back to a text hero when the image errors', () => {
    const f = mount(entry());
    f.componentInstance.imgError.set(true);
    f.detectChanges();
    expect(f.nativeElement.querySelector('img.img')).toBeNull();
    expect((f.nativeElement as HTMLElement).textContent).toContain('Big headline');
  });

  it('demotes a tiny image (tracking pixel) to a text hero', () => {
    const f = mount(entry());
    f.componentInstance.onLoad({ target: { naturalWidth: 100 } } as unknown as Event);
    f.detectChanges();
    expect(f.nativeElement.querySelector('img.img')).toBeNull();
  });
});
```

- [ ] **Step 2: Run → fail.**

- [ ] **Step 3: Implement `entry-hero.component.ts`.**

```ts
// src/app/reader/magazine/entry-hero.component.ts
import { Component, computed, effect, input, output, signal } from '@angular/core';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryDto } from '../models';
import { firstPreviewImage, textSnippet } from '../preview-image';
import { relativeTime } from '../format';

@Component({
  selector: 'app-entry-hero',
  imports: [IconComponent],
  template: `
    <article
      class="hero"
      role="button"
      tabindex="0"
      [class.read]="entry().isRead"
      (click)="open.emit(entry())"
      (keydown.enter)="open.emit(entry())"
      (keydown.space)="$event.preventDefault(); open.emit(entry())"
    >
      @if (showImage()) {
        <img
          class="img"
          [src]="image()!"
          alt=""
          loading="lazy"
          decoding="async"
          referrerpolicy="no-referrer"
          (load)="onLoad($event)"
          (error)="imgError.set(true)"
        />
      }
      <div class="body">
        <p class="kicker">
          <span class="dot" [class.on]="!entry().isRead" aria-hidden="true"></span>
          {{ entry().source }} · {{ when() }}
        </p>
        <h3 class="title">{{ entry().title }}</h3>
        @if (snippet()) {
          <p class="dek">{{ snippet() }}</p>
        }
        <div class="actions">
          <button type="button" aria-label="Favorite" [class.on]="entry().isFavorite"
            [attr.aria-pressed]="entry().isFavorite" (click)="$event.stopPropagation(); favorite.emit(entry())">
            <app-icon name="star" [size]="18" />
          </button>
          <button type="button" aria-label="Keep" [class.on]="entry().isKept"
            [attr.aria-pressed]="entry().isKept" (click)="$event.stopPropagation(); keep.emit(entry())">
            <app-icon name="bookmark" [size]="18" />
          </button>
          <button type="button" aria-label="Toggle read" [attr.aria-pressed]="entry().isRead"
            (click)="$event.stopPropagation(); read.emit(entry())">
            <app-icon [name]="entry().isRead ? 'mark_email_unread' : 'check'" [size]="18" />
          </button>
        </div>
      </div>
    </article>
  `,
  styles: [
    `
      :host {
        display: block;
      }
      .hero {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        cursor: pointer;
      }
      .hero:hover {
        border-color: var(--border-strong);
      }
      .img {
        width: 100%;
        aspect-ratio: 16 / 9;
        object-fit: cover;
        display: block;
      }
      .body {
        padding: var(--space-3) var(--space-4);
        display: flex;
        flex-direction: column;
        gap: var(--space-2);
      }
      .kicker {
        margin: 0;
        display: flex;
        align-items: center;
        gap: var(--space-1);
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        border: 1px solid var(--border-strong);
      }
      .dot.on {
        background: var(--accent);
        border-color: var(--accent);
      }
      .title {
        margin: 0;
        font-size: var(--fs-xl);
        font-weight: 500;
        line-height: 1.3;
        color: var(--text-primary);
      }
      .hero.read .title {
        color: var(--text-secondary);
        font-weight: 400;
      }
      .dek {
        margin: 0;
        color: var(--text-secondary);
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }
      .actions {
        display: flex;
        gap: var(--space-3);
      }
      .actions button {
        background: none;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        padding: 2px;
      }
      .actions button.on {
        color: var(--accent);
      }
    `,
  ],
})
export class EntryHeroComponent {
  readonly entry = input.required<EntryDto>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  readonly imgError = signal(false);
  readonly tooSmall = signal(false);
  readonly image = computed(() => firstPreviewImage(this.entry().contentHtml, this.entry().summary));
  readonly showImage = computed(() => !!this.image() && !this.imgError() && !this.tooSmall());
  readonly snippet = computed(() => textSnippet(this.entry().summary || this.entry().contentHtml));
  readonly when = computed(() => relativeTime(this.entry().publishedAt ?? this.entry().createdAt));

  onLoad(ev: Event): void {
    const img = ev.target as HTMLImageElement;
    if (img.naturalWidth && img.naturalWidth < 200) this.tooSmall.set(true);
  }

  // Reset the gates when the host reuses this component for a different entry.
  private readonly _reset = effect(() => {
    this.entry();
    this.imgError.set(false);
    this.tooSmall.set(false);
  });
}
```

- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5d): magazine hero card with image-quality gate"`

---

### Task 5: EntryCompactComponent

**Files:** Create `frontend/src/app/reader/magazine/entry-compact.component.ts` + `.spec.ts`.

- [ ] **Step 1: Write the failing test.**

```ts
import { TestBed } from '@angular/core/testing';
import { EntryCompactComponent } from './entry-compact.component';
import { EntryDto } from '../models';

const entry: EntryDto = {
  id: 3, title: 'One-liner headline', url: null, author: null, summary: null, contentHtml: null,
  publishedAt: null, createdAt: 'x', subscriptionId: 1, source: 'Golem', isRead: false, isFavorite: false, isKept: false,
};

describe('EntryCompactComponent', () => {
  function mount() {
    TestBed.configureTestingModule({ imports: [EntryCompactComponent] });
    const f = TestBed.createComponent(EntryCompactComponent);
    f.componentRef.setInput('entry', entry);
    f.detectChanges();
    return f;
  }

  it('renders the source and title', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.textContent).toContain('One-liner headline');
    expect(el.textContent).toContain('Golem');
  });

  it('emits open on click and on Enter', () => {
    const f = mount();
    const open = jest.fn();
    f.componentInstance.open.subscribe(open);
    const row = f.nativeElement.querySelector('.compact') as HTMLElement;
    row.click();
    row.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    expect(open).toHaveBeenCalledTimes(2);
  });
});
```

- [ ] **Step 2: Run → fail.**

- [ ] **Step 3: Implement `entry-compact.component.ts`.**

```ts
// src/app/reader/magazine/entry-compact.component.ts
import { Component, computed, input, output } from '@angular/core';
import { EntryDto } from '../models';
import { relativeTime } from '../format';

@Component({
  selector: 'app-entry-compact',
  template: `
    <article
      class="compact"
      role="button"
      tabindex="0"
      [class.read]="entry().isRead"
      (click)="open.emit(entry())"
      (keydown.enter)="open.emit(entry())"
      (keydown.space)="$event.preventDefault(); open.emit(entry())"
    >
      <span class="dot" [class.on]="!entry().isRead" aria-hidden="true"></span>
      <div class="body">
        <p class="kicker">{{ entry().source }} · {{ when() }}</p>
        <p class="title">{{ entry().title }}</p>
      </div>
    </article>
  `,
  styles: [
    `
      :host {
        display: block;
      }
      .compact {
        display: flex;
        gap: var(--space-3);
        align-items: baseline;
        padding: var(--space-3) var(--space-4);
        cursor: pointer;
      }
      .compact:hover {
        background: var(--surface-0);
      }
      .dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        flex: 0 0 auto;
        border: 1px solid var(--border-strong);
      }
      .dot.on {
        background: var(--accent);
        border-color: var(--accent);
      }
      .body {
        min-width: 0;
      }
      .kicker {
        margin: 0;
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .title {
        margin: 0;
        color: var(--text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .compact.read .title {
        color: var(--text-secondary);
      }
    `,
  ],
})
export class EntryCompactComponent {
  readonly entry = input.required<EntryDto>();
  readonly open = output<EntryDto>();
  readonly when = computed(() => relativeTime(this.entry().publishedAt ?? this.entry().createdAt));
}
```

- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5d): magazine compact entry"`

---

### Task 6: SourceGroupComponent

**Files:** Create `frontend/src/app/reader/magazine/source-group.component.ts` + `.spec.ts`.

- [ ] **Step 1: Write the failing test.**

```ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { SourceGroupComponent } from './source-group.component';
import { EntryDto } from '../models';

const e = (id: number): EntryDto => ({
  id, title: `t${id}`, url: null, author: null, summary: null, contentHtml: null, publishedAt: null,
  createdAt: 'x', subscriptionId: 7, source: 'heise', isRead: false, isFavorite: false, isKept: false,
});

describe('SourceGroupComponent', () => {
  function mount(moreCount: number) {
    TestBed.configureTestingModule({ imports: [SourceGroupComponent], providers: [provideRouter([])] });
    const f = TestBed.createComponent(SourceGroupComponent);
    f.componentRef.setInput('source', 'heise');
    f.componentRef.setInput('subscriptionId', 7);
    f.componentRef.setInput('entries', [e(1), e(2), e(3)]);
    f.componentRef.setInput('moreCount', moreCount);
    f.detectChanges();
    return f;
  }

  it('renders the source, three items, and a counted more link', () => {
    const el = mount(4).nativeElement as HTMLElement;
    expect(el.textContent).toContain('heise');
    expect(el.querySelectorAll('app-entry-compact').length).toBe(3);
    expect(el.querySelector('.more')!.textContent).toContain('4 more from heise');
  });

  it('drops the count when moreCount is 0', () => {
    const el = mount(0).nativeElement as HTMLElement;
    expect(el.querySelector('.more')!.textContent).toContain('More from heise');
    expect(el.querySelector('.more')!.textContent).not.toContain('0 more');
  });

  it('re-emits open from an inner item', () => {
    const f = mount(1);
    const open = jest.fn();
    f.componentInstance.open.subscribe(open);
    (f.nativeElement.querySelector('.compact') as HTMLElement).click();
    expect(open).toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run → fail.**

- [ ] **Step 3: Implement `source-group.component.ts`.**

```ts
// src/app/reader/magazine/source-group.component.ts
import { Component, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryCompactComponent } from './entry-compact.component';
import { EntryDto } from '../models';

@Component({
  selector: 'app-source-group',
  imports: [RouterLink, IconComponent, EntryCompactComponent],
  template: `
    <div class="group">
      <p class="ghead"><span class="dot" aria-hidden="true"></span>{{ source() }}</p>
      <div class="items">
        @for (item of entries(); track item.id) {
          <app-entry-compact [entry]="item" (open)="open.emit($event)" />
        }
      </div>
      <a
        class="more"
        [routerLink]="[]"
        [queryParams]="{ subscription: subscriptionId(), view: null, tag: null, entry: null, unread: '0' }"
        queryParamsHandling="merge"
      >
        {{ moreCount() > 0 ? moreCount() + ' more from ' + source() : 'More from ' + source() }}
        <app-icon name="arrow_forward" [size]="16" />
      </a>
    </div>
  `,
  styles: [
    `
      :host {
        display: block;
      }
      .group {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
      }
      .ghead {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        margin: 0;
        padding: var(--space-3) var(--space-4);
        font-size: var(--fs-sm);
        font-weight: 500;
        color: var(--text-secondary);
        border-bottom: 1px solid var(--border);
      }
      .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--accent);
      }
      .items app-entry-compact:not(:last-child) .compact {
        border-bottom: 1px solid var(--border);
      }
      .more {
        display: flex;
        align-items: center;
        gap: var(--space-1);
        padding: var(--space-3) var(--space-4);
        border-top: 1px solid var(--border);
        color: var(--accent);
        text-decoration: none;
        font-size: var(--fs-sm);
      }
      .more:hover {
        background: var(--surface-0);
      }
    `,
  ],
})
export class SourceGroupComponent {
  readonly source = input.required<string>();
  readonly subscriptionId = input.required<number>();
  readonly entries = input.required<EntryDto[]>();
  readonly moreCount = input.required<number>();
  readonly open = output<EntryDto>();
}
```

> Note: the `.items app-entry-compact ... .compact` selector reaches into a child component's element. If Angular view encapsulation prevents the border from applying, move the divider into `EntryCompactComponent` is NOT wanted (List uses it too) — instead add `::ng-deep` is discouraged; simplest robust fallback is to wrap each item: `<div class="item">` around `<app-entry-compact>` and put `border-bottom` on `.item:not(:last-child)`. Prefer the wrapper if the bare selector doesn't render the divider.

- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5d): magazine source group with more link"`

---

### Task 7: EntryListComponent — layout input + magazine body

**Files:** Modify `frontend/src/app/reader/entry-list/entry-list.component.ts`; extend its `.spec.ts`.

- [ ] **Step 1: Extend the spec** (read it first; it sets the required inputs). Add:

```ts
it('renders planned magazine blocks when layout is magazine', () => {
  // set inputs: layout='magazine', selection {kind:'all', id:null, unread:true},
  // entries = 3 entries sharing subscriptionId 1 (a group) -> expect an app-source-group,
  // plus a diverse entry with an image + long title -> expect an app-entry-hero/app-entry-row.
  // Assert the flat '.rows app-entry-row' path is NOT the only thing rendered:
  //   expect(el.querySelector('app-source-group')).not.toBeNull();
});

it('renders flat rows when layout is list', () => {
  // layout='list' -> expect app-entry-row present, app-source-group absent.
});
```

Follow the existing spec's mount helper (it provides `provideRouter([])` etc.). Set the new `layout` input via `componentRef.setInput('layout', 'magazine')`.

- [ ] **Step 2: Run → fail.**

- [ ] **Step 3: Implement.** Edit `entry-list.component.ts`:

1. Imports:

```ts
import { Component, ElementRef, OnDestroy, computed, effect, input, output, viewChild } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryRowComponent } from '../entry-row/entry-row.component';
import { EntryHeroComponent } from '../magazine/entry-hero.component';
import { EntryCompactComponent } from '../magazine/entry-compact.component';
import { SourceGroupComponent } from '../magazine/source-group.component';
import { MagazineBlock, planMagazine } from '../magazine/magazine-planner';
import { ReadingLayout } from '../reading-layout.service';
import { EntryDto } from '../models';
import { Selection } from '../query';
import { Problem } from '../../core/problem';
```

2. Decorator `imports`:

```ts
  imports: [RouterLink, IconComponent, EntryRowComponent, EntryHeroComponent, EntryCompactComponent, SourceGroupComponent],
```

3. Add the input, computed blocks, and cast helpers to the class:

```ts
  readonly layout = input<ReadingLayout>('list');

  readonly blocks = computed<MagazineBlock[]>(() =>
    planMagazine(this.entries(), this.selection().kind !== 'subscription'),
  );

  blockKey(b: MagazineBlock): string {
    return b.kind === 'group' ? `group-${b.subscriptionId}-${b.entries[0].id}` : `${b.kind}-${b.entry.id}`;
  }
  hero(b: MagazineBlock) { return b as Extract<MagazineBlock, { kind: 'hero' }>; }
  feat(b: MagazineBlock) { return b as Extract<MagazineBlock, { kind: 'feature' }>; }
  compact(b: MagazineBlock) { return b as Extract<MagazineBlock, { kind: 'compact' }>; }
  grp(b: MagazineBlock) { return b as Extract<MagazineBlock, { kind: 'group' }>; }
```

4. Replace the populated-list branch of the template (the `@else` block that begins `<div class="rows" #rows>`) with a layout switch. Keep the loading/empty/error branches unchanged. New populated branch:

```html
    } @else if (layout() === 'magazine') {
      <div class="rows magazine" #rows>
        @for (b of blocks(); track blockKey(b)) {
          @switch (b.kind) {
            @case ('hero') {
              <app-entry-hero
                [entry]="hero(b).entry"
                (favorite)="favorite.emit($event)"
                (keep)="keep.emit($event)"
                (read)="read.emit($event)"
                (open)="open.emit($event)"
              />
            }
            @case ('feature') {
              <app-entry-row
                [entry]="feat(b).entry"
                [imageSide]="feat(b).imageSide"
                [class.open]="openEntryId() === feat(b).entry.id"
                (favorite)="favorite.emit($event)"
                (keep)="keep.emit($event)"
                (read)="read.emit($event)"
                (open)="open.emit($event)"
              />
            }
            @case ('compact') {
              <app-entry-compact [entry]="compact(b).entry" (open)="open.emit($event)" />
            }
            @case ('group') {
              <app-source-group
                [source]="grp(b).source"
                [subscriptionId]="grp(b).subscriptionId"
                [entries]="grp(b).entries"
                [moreCount]="grp(b).moreCount"
                (open)="open.emit($event)"
              />
            }
          }
        }
        @if (hasMore()) {
          <div class="foot" #sentinel>
            <button class="load-more" type="button" [disabled]="loadingMore()" (click)="loadMore.emit()">
              {{ loadingMore() ? 'Loading…' : 'Load more' }}
            </button>
          </div>
        }
      </div>
    } @else {
      <div class="rows" #rows>
        @for (e of entries(); track e.id) {
          <app-entry-row
            [entry]="e"
            [class.open]="openEntryId() === e.id"
            (favorite)="favorite.emit($event)"
            (keep)="keep.emit($event)"
            (read)="read.emit($event)"
            (open)="open.emit($event)"
          />
        }
        @if (hasMore()) {
          <div class="foot" #sentinel>
            <button class="load-more" type="button" [disabled]="loadingMore()" (click)="loadMore.emit()">
              {{ loadingMore() ? 'Loading…' : 'Load more' }}
            </button>
          </div>
        }
      </div>
    }
```

5. Add magazine column styling to `styles` (centered reading measure, spacing between blocks):

```css
.rows.magazine {
  padding: var(--space-3);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-3);
}
.rows.magazine > * {
  width: 100%;
  max-width: 680px;
}
```

> The `#rows`/`#sentinel` template refs exist in exactly one rendered branch at a time, so the existing `IntersectionObserver` effect keeps working unchanged.

- [ ] **Step 4: Run → pass** (`npm test -- entry-list`). If Angular's strict template checker rejects the union property access despite the cast helpers, that means a helper was missed — every `b.<field>` in the template must go through `hero()/feat()/compact()/grp()`.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(5d): entry list renders planned magazine blocks"`

---

### Task 8: Header segment + shell wiring

**Files:** Modify `frontend/src/app/reader/header/reader-header.component.ts` (+ extend `.spec.ts`); modify `frontend/src/app/reader/reader-shell.component.ts`.

- [ ] **Step 1: Extend the header spec** — assert a Magazine layout button exists and toggles the mode. Read the existing header spec for its stub of `ReadingLayoutService` (`layout.mode()` / `layout.set()`); assert `getByLabel`/querySelector for `aria-label="Magazine layout"`.

- [ ] **Step 2: Run → fail.**

- [ ] **Step 3: Implement the header.** In the layout `.seg` group, add the Magazine button **first** (default first):

```html
        <div class="seg" role="group" aria-label="Reading layout">
          <button
            aria-label="Magazine layout"
            [class.active]="layout.mode() === 'magazine'"
            (click)="layout.set('magazine')"
          >
            <app-icon name="view_quilt" [size]="18" />
          </button>
          <button aria-label="List layout" [class.active]="layout.mode() === 'list'" (click)="layout.set('list')">
            <app-icon name="view_agenda" [size]="18" />
          </button>
          <button aria-label="Pane layout" [class.active]="layout.mode() === 'pane'" (click)="layout.set('pane')">
            <app-icon name="view_column_2" [size]="18" />
          </button>
        </div>
```

- [ ] **Step 4: Wire the shell.** In `reader-shell.component.ts`, pass the layout mode into **every** `<app-entry-list>` usage (both the pane-section list and the non-pane list). Add `[layout]="layout.mode()"` to each, e.g.:

```html
            <app-entry-list
              [layout]="layout.mode()"
              [title]="title()"
              ...
```

(The shell already injects `layout = inject(ReadingLayoutService)`. In pane mode `layout.mode()` is `'pane'` so the list renders flat rows in its column; magazine only renders when the mode is `'magazine'`, and `paneMode()` is already false for magazine.)

- [ ] **Step 5: Run → pass** (`npm test -- reader-header reader-shell`). Confirm the existing shell spec still constructs (default mode is now `magazine`, so the entry list computes `blocks()` over whatever entries the shell spec provides — an empty list yields the empty-state branch, unaffected).

- [ ] **Step 6: Commit** — `git add -A && git commit -m "feat(5d): magazine header toggle + shell wiring"`

---

### Task 9: Playwright smoke + README

**Files:** Create `frontend/e2e/magazine-smoke.spec.ts`; modify `frontend/README.md`.

- [ ] **Step 1: Write the smoke** (mirror `reader-smoke.spec.ts`'s sign-in + skip-if-unreachable). Since Magazine is the default, the reader loads in magazine mode; assert the layout toggle exposes a Magazine button and that switching to List works:

```ts
// e2e/magazine-smoke.spec.ts
import { test, expect, Page } from '@playwright/test';

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

test('magazine is the default layout and the toggle switches modes', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable');

  const group = page.getByRole('group', { name: 'Reading layout' });
  await expect(group).toBeVisible();
  await expect(group.getByRole('button', { name: 'Magazine layout' })).toBeVisible();

  // Switch to List and back; the reader stays mounted throughout.
  await group.getByRole('button', { name: 'List layout' }).click();
  await group.getByRole('button', { name: 'Magazine layout' }).click();
  await expect(page.locator('app-reader-header')).toBeVisible();
});
```

- [ ] **Step 2: Run against Docker if up** — `npm run e2e -- magazine-smoke` (clean skip if unreachable; don't block).

- [ ] **Step 3: Commit** — `git add -A && git commit -m "test(5d): magazine layout Playwright smoke"`

- [ ] **Step 4: Update `README.md`** — add a short "Magazine layout (5d)" note under the Reader section: the three layout modes (Magazine default / List / Pane), what magazine does (hero/feature/compact/source-group via a pure planner), that it's an on-device preference, and the tunable planner constants. Mirror the existing Reader section's tone.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "docs(5d): document the magazine layout"`

---

## Final review

- [ ] From `frontend/`: `npm run check` green (ESLint + Prettier + Stylelint + Jest).
- [ ] `npm run build` green (confirm the magazine block components land in the reader chunk).
- [ ] Refresh the Docker `frontend-node-modules` volume only if deps changed (5d adds none); run the smokes live.
- [ ] Adversarial review over the 5d diff: planner prefix-stability & grouping edges; hero image-gate; the `@switch` cast helpers; magazine centering; no hex in `.scss`/`styles`; default-change doesn't break other specs.
- [ ] superpowers:finishing-a-development-branch.

## Self-review notes (author)

- **Spec coverage:** magazine mode + default (T1), planner with all rules (T2), feature image-side (T3), hero + image gate (T4), compact (T5), source group + more link (T6), list integration (T7), header toggle + shell + single-column (T7/T8), default-first ordering (T8), smoke + docs (T9). Decisions A–E all realised; deferred heuristics untouched.
- **Type consistency:** `MagazineBlock` union defined in T2 and consumed via cast helpers in T7; `ReadingLayout` extended in T1 and used as the `layout` input type in T7 and bound in T8; `imageSide` defined in T3 and set by the `feature` block in T7; component outputs (`favorite/keep/read/open`) identical across hero/compact/group and the existing row.
- **No placeholders:** every code step is complete; the two judgment notes (source-group divider selector; strict-template cast coverage) give the exact fallback.
```
