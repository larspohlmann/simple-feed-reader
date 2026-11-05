# Magazine "x more sources" footer (#2036) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In the magazine view, replace the "Also covered by" / "Also in" chip footer with one collapsed line, "x more sources", that expands in place into a grid of mini preview cards (tiny thumbnail, feed · time, one-line title); a mini card opens that copy.

**Architecture:** A new standalone `MoreSourcesComponent` (`app-more-sources`) in `reader/list/magazine/` takes the card's `entry`, reads its copies through the existing `alsoFootOf()`, and opens a copy through the injected `EntryActionHandler.open()` — the shell handler every magazine block already sees. Every magazine block swaps `<app-entry-duplicates>` for `<app-more-sources>`. The list view's `entry-row` keeps `app-entry-duplicates` (chips + popover) untouched.

**Tech Stack:** Angular 20 standalone components + signals, Transloco, Jest (jsdom), Playwright, SCSS with design tokens.

**Spec:** the design agreed in chat on 2026-11-05 (issue #2036 records it): collapsed one-liner with layers icon, count and chevron in accent, toggle styled like the source-group "more" button; expanded `auto-fill` grid of mini cards (`minmax(~12rem, 1fr)`), each a `--surface-2` box with a 32px square thumbnail (copy's `imageUrl`, `object-fit: cover`; favicon on a tinted square when none), byline `feed · relative time` (xs, muted) and the title clamped to one line; click opens the copy, no popover, no per-card actions; story vs copy both read "x more sources"; magazine only.

## Global Constraints

- Branch `feature/2036-magazine-more-sources` off `origin/develop`; PR into `develop`, body says `Closes #2036`.
- Commit format `type(#2036): …`; no Claude attribution anywhere.
- No hex colours, no ad-hoc `px` spacing, no media-query literals in component `.scss`; sizes that are not spacing go through a token in `src/app/theme/tokens.scss` and a row in `docs/design-language.md`.
- Component styles live in a sibling `.scss` (`styleUrl`), never inline.
- Pointer-only hover: wrap `:hover` in `@media (hover: hover)`.
- Comments only where a reader would otherwise get the code wrong; one line.
- Frontend tests run inside Docker: `docker compose exec -T frontend npm test -- <pattern>`; the gate is `docker compose exec -T frontend npm run check`.
- The list view must not change.

## Status at plan time

Tasks 1 and 2's code was drafted before this plan existed and sits uncommitted in the working tree (`git status`). Executing those tasks means checking the files against the code below, running the steps, fixing what fails, and committing. Nothing has been run yet.

## File map

| File | Responsibility |
|---|---|
| Create `frontend/src/app/reader/list/magazine/more-sources.component.ts` | count, expand state, per-copy view model, open |
| Create `…/magazine/more-sources.component.html` | toggle line + mini-card grid |
| Create `…/magazine/more-sources.component.scss` | footer, toggle, grid, mini card, thumb |
| Create `…/magazine/more-sources.component.spec.ts` | component behaviour |
| Modify `frontend/src/app/theme/tokens.scss` | `--mini-card-thumb`, `--mini-card-min-w` |
| Modify `docs/design-language.md` | token rows + catalog entry |
| Modify `frontend/public/i18n/en.json`, `de.json` | `reader.moreSourcesOne`, `reader.moreSources` |
| Modify the 11 `…/magazine/blocks/*/*.component.{ts,html}` that render `app-entry-duplicates` | swap to `app-more-sources` |
| Modify `…/blocks/entry-hero/entry-hero.component.spec.ts:195-208` | footer-open test against the new markup |
| Modify `frontend/e2e/trending-smoke.spec.ts:97-105` | magazine leg asserts the new footer |

Unchanged on purpose: `entry-duplicates.component.*`, `also-foot.ts` (the list view still needs its `label`), `entry-row.*`.

---

### Task 1: `MoreSourcesComponent`

**Files:**
- Create: `frontend/src/app/reader/list/magazine/more-sources.component.ts`
- Create: `frontend/src/app/reader/list/magazine/more-sources.component.html`
- Create: `frontend/src/app/reader/list/magazine/more-sources.component.scss`
- Modify: `frontend/src/app/theme/tokens.scss` (after `--footer-chip-name-max: 24ch;`)
- Modify: `docs/design-language.md` (Structural sizing table after the `--footer-chip-name-max` row; component catalog after `### <app-entry-actions>`)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (after `"alsoMore"`)
- Test: `frontend/src/app/reader/list/magazine/more-sources.component.spec.ts`

**Interfaces:**
- Consumes: `alsoFootOf(entry: EntryDto): AlsoFoot` (`./also-foot`), `EntryActionHandler.open(entry: EntryDto)`, `SubscriptionRows.find(id: number)`, `feedAvatarUrl(subscription)`, `relativeTime(iso, lang)`, `entryHeadline(entry)`, `entryDisplayTitle(entry)`.
- Produces: `MoreSourcesComponent`, selector `app-more-sources`, one required input `entry: EntryDto`. CSS hooks used by later tasks: `.more-sources`, `.toggle` (with `aria-expanded`), `.mini`, `.thumb`, `.feed`, `.title`.

- [ ] **Step 1: Write the failing spec** — `more-sources.component.spec.ts`:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { EntryDto } from '../../models';
import { MoreSourcesComponent } from './more-sources.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { anEntry } from '../../../../testing/entry-testing';

const open = jest.fn();
let fixture: ComponentFixture<MoreSourcesComponent>;

beforeEach(() => open.mockReset());

function mount(entry: EntryDto): HTMLElement {
  TestBed.configureTestingModule({
    imports: [MoreSourcesComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: { open } }, provideRouter([])],
  });
  fixture = TestBed.createComponent(MoreSourcesComponent);
  fixture.componentRef.setInput('entry', entry);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

function expand(element: HTMLElement): void {
  element.querySelector<HTMLButtonElement>('.toggle')!.click();
  fixture.detectChanges();
}

const copies = [
  anEntry({ id: 2, source: 'NDR', title: 'Storm hits the coast', imageUrl: 'https://img/2.jpg' }),
  anEntry({ id: 3, source: 'taz', title: 'Coast braces for storm', imageUrl: null }),
];

it('renders nothing without other sources', () => {
  expect(mount(anEntry()).querySelector('.more-sources')).toBeNull();
});

it('counts the other sources on one collapsed line', () => {
  const element = mount(anEntry({ duplicates: copies }));

  const toggle = element.querySelector('.toggle')!;
  expect(toggle.textContent).toContain('2');
  expect(toggle.getAttribute('aria-expanded')).toBe('false');
  expect(element.querySelector('.mini')).toBeNull();
});

it('expands to one mini card per source, naming its feed and title', () => {
  const element = mount(anEntry({ duplicates: copies }));
  expand(element);

  const minis = element.querySelectorAll('.mini');
  expect(minis.length).toBe(2);
  expect(minis[0].querySelector('.feed')!.textContent).toContain('NDR');
  expect(minis[0].querySelector('.title')!.textContent).toContain('Storm hits the coast');
  expect(element.querySelector('.toggle')!.getAttribute('aria-expanded')).toBe('true');
});

it("shows a source's picture as its thumbnail, its favicon when it has none", () => {
  const element = mount(anEntry({ duplicates: copies }));
  expand(element);

  const [withImage, withoutImage] = Array.from(element.querySelectorAll('.mini'));
  expect(withImage.querySelector('img.thumb')!.getAttribute('src')).toBe('https://img/2.jpg');
  expect(withoutImage.querySelector('img.thumb')).toBeNull();
  expect(withoutImage.querySelector('.thumb app-favicon')).not.toBeNull();
});

it('collapses again on a second click', () => {
  const element = mount(anEntry({ duplicates: copies }));
  expand(element);
  expand(element);

  expect(element.querySelector('.mini')).toBeNull();
});

it('opens the source a mini card shows, and not the card it sits on', () => {
  const card = document.createElement('div');
  const cardClick = jest.fn();
  card.addEventListener('click', cardClick);
  const element = mount(anEntry({ duplicates: copies }));
  card.appendChild(element);
  expand(element);
  cardClick.mockReset();

  element.querySelectorAll<HTMLButtonElement>('.mini')[1].click();

  expect(open).toHaveBeenCalledWith(copies[1]);
  expect(cardClick).not.toHaveBeenCalled();
});

it("keeps the toggle's click from opening the card it sits on", () => {
  const card = document.createElement('div');
  const cardClick = jest.fn();
  card.addEventListener('click', cardClick);
  const element = mount(anEntry({ duplicates: copies }));
  card.appendChild(element);

  expand(element);

  expect(cardClick).not.toHaveBeenCalled();
});

it.each(['Enter', ' '])('keeps a "%s" key on a mini card from reaching the card', (key) => {
  const card = document.createElement('div');
  const cardKey = jest.fn();
  card.addEventListener('keydown', cardKey);
  const element = mount(anEntry({ duplicates: copies }));
  card.appendChild(element);
  expand(element);

  element
    .querySelector('.mini')!
    .dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

  expect(cardKey).not.toHaveBeenCalled();
});
```

If `SubscriptionRows` needs providers the testing module lacks, add the same providers `entry-duplicates.component.spec.ts` uses (`provideAccountIdentity()`), not a mock of `SubscriptionRows`.

- [ ] **Step 2: Run it, expect FAIL** (module `./more-sources.component` not found)

Run: `docker compose exec -T frontend npm test -- more-sources`

- [ ] **Step 3: Add the tokens** — `tokens.scss`, after `--footer-chip-name-max: 24ch;`:

```scss
  --mini-card-thumb: 32px;
  --mini-card-min-w: 12rem;
```

and the two rows in `docs/design-language.md`'s structural-sizing table, after the `--footer-chip-name-max` row:

```markdown
| `--mini-card-thumb` | `32px` | the square thumbnail of a magazine card's "more sources" mini card (#2036) |
| `--mini-card-min-w` | `12rem` | the narrowest a mini card gets before the "more sources" grid drops a column (#2036) |
```

- [ ] **Step 4: Add the i18n keys** after `"alsoMore"` in both files:

```json
    "moreSourcesOne": "1 more source",
    "moreSources": "{{count}} more sources",
```

```json
    "moreSourcesOne": "1 weitere Quelle",
    "moreSources": "{{count}} weitere Quellen",
```

- [ ] **Step 5: Write the component** — `more-sources.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { LanguageService } from '../../../core/i18n/language.service';
import { relativeTime } from '../../format';
import { entryDisplayTitle, entryHeadline } from '../../entry/entry-text';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SubscriptionRows } from '../../state/subscription-rows';
import { feedAvatarUrl } from '../../state/social-sections';
import { alsoFootOf } from './also-foot';

let nextId = 0;

/** A magazine card's other sources for the same story: one line that opens into mini cards (#2036). */
@Component({
  selector: 'app-more-sources',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, FaviconComponent, IconComponent],
  templateUrl: './more-sources.component.html',
  styleUrl: './more-sources.component.scss',
})
export class MoreSourcesComponent {
  readonly entry = input.required<EntryDto>();

  private readonly actions = inject(EntryActionHandler);
  private readonly language = inject(LanguageService);
  private readonly rows = inject(SubscriptionRows);

  protected readonly copies = computed(() => alsoFootOf(this.entry()).copies);
  protected readonly countKey = computed(() =>
    this.copies().length === 1 ? 'reader.moreSourcesOne' : 'reader.moreSources',
  );
  protected readonly sources = computed(() =>
    this.copies().map((copy) => {
      const subscription = this.rows.find(copy.subscriptionId);
      return {
        copy,
        feed: subscription?.title ?? copy.source,
        avatarUrl: feedAvatarUrl(subscription),
        title: entryHeadline(copy) || entryDisplayTitle(copy),
        when: relativeTime(copy.publishedAt ?? copy.createdAt, this.language.lang()),
      };
    }),
  );
  protected readonly expanded = signal(false);
  protected readonly panelId = `more-sources-${nextId++}`;

  toggle(event: Event): void {
    event.stopPropagation();
    this.expanded.update((open) => !open);
  }

  open(copy: EntryDto, event: Event): void {
    event.stopPropagation();
    this.actions.open(copy);
  }
}
```

If `SubscriptionRows.find` returns `undefined` rather than `null`, pass `subscription ?? null` to `feedAvatarUrl` (it takes `SubscriptionDto | null`); `tsc` in `npm run check` tells you.

`more-sources.component.html`:

```html
@if (copies().length) {
  <div class="more-sources">
    <button
      type="button"
      class="toggle reads-as-text"
      [attr.aria-expanded]="expanded()"
      [attr.aria-controls]="panelId"
      (click)="toggle($event)"
      (keydown.enter)="$event.stopPropagation()"
      (keydown.space)="$event.stopPropagation()"
    >
      <app-icon name="layers" size="sm" aria-hidden="true" />
      {{ countKey() | transloco: { count: copies().length } }}
      <app-icon [name]="expanded() ? 'expand_less' : 'expand_more'" size="sm" aria-hidden="true" />
    </button>
    @if (expanded()) {
      <div class="minis" [id]="panelId">
        @for (source of sources(); track source.copy.id) {
          <button
            type="button"
            class="mini reads-as-text"
            [attr.title]="source.feed + ' · ' + source.title"
            (click)="open(source.copy, $event)"
            (keydown.enter)="$event.stopPropagation()"
            (keydown.space)="$event.stopPropagation()"
          >
            @if (source.copy.imageUrl) {
              <img class="thumb" [src]="source.copy.imageUrl" alt="" loading="lazy" />
            } @else {
              <span class="thumb">
                <app-favicon
                  [url]="source.copy.faviconUrl"
                  [avatarUrl]="source.avatarUrl"
                  [size]="16"
                />
              </span>
            }
            <span class="text">
              <span class="byline"
                ><span class="feed">{{ source.feed }}</span
                ><span class="when"> · {{ source.when }}</span></span
              >
              <span class="title">{{ source.title }}</span>
            </span>
          </button>
        }
      </div>
    }
  </div>
}
```

`more-sources.component.scss`:

```scss
// Like entry-duplicates: without other sources the template renders nothing, so
// the host must hold no box either, or the card's gap reserves dead space.
:host {
  display: contents;
}

.more-sources {
  margin-top: var(--space-2);
  padding-top: var(--space-1);
  border-top: var(--card-border-width) solid var(--border);
  font-size: var(--fs-xs);
}

.toggle {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: var(--space-1) var(--space-2);
  margin-inline-start: calc(-1 * var(--space-2));
  border: none;
  border-radius: var(--radius-pill);
  background: none;
  color: var(--accent);
  font: inherit;
  cursor: pointer;
}

.minis {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(var(--mini-card-min-w), 100%), 1fr));
  gap: var(--space-1);
  margin-top: var(--space-1);
}

.mini {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-width: 0;
  padding: var(--space-1);
  border: none;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
  color: var(--text-secondary);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

@media (hover: hover) {
  .toggle:hover,
  .mini:hover {
    background: var(--card-hover-bg);
  }
}

.thumb {
  display: grid;
  flex: none;
  place-items: center;
  width: var(--mini-card-thumb);
  height: var(--mini-card-thumb);
  border-radius: var(--radius-sm);
  background: var(--surface-1);
  object-fit: cover;
}

.text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.byline,
.title {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.byline {
  color: var(--text-muted);
}

.feed {
  color: var(--text-secondary);
  font-weight: 500;
}

.title {
  color: var(--text-primary);
}
```

- [ ] **Step 6: Run the spec, expect PASS**

Run: `docker compose exec -T frontend npm test -- more-sources`

- [ ] **Step 7: Catalog entry** — in `docs/design-language.md`, a new `### <app-more-sources>` after the `<app-entry-actions>` section:

```markdown
### `<app-more-sources>`

A magazine card's other sources for the same story or post (#2036): one accent line,
"x more sources", that opens in place into a grid of mini cards — the smallest card in
the app: a `--mini-card-thumb` square (the copy's picture, else its favicon on
`--surface-1`), the feed and relative time, and a one-line title. A mini card opens its
copy; it carries no actions. The grid fills `--mini-card-min-w` columns, so a narrow
card stacks them and a hero lays out two or three.

**Not for:** the list view, whose `entry-row` keeps `<app-entry-duplicates>` — chips that
open the copy's row in a popover.
```

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/list/magazine/more-sources.component.* frontend/src/app/theme/tokens.scss docs/design-language.md frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#2036): a magazine card's other sources open into mini cards"
```

---

### Task 2: Magazine blocks use `app-more-sources`

**Files:**
- Modify: `frontend/src/app/reader/list/magazine/blocks/{entry-compact,entry-hero,entry-kicker,entry-link,entry-media,entry-quote,entry-quoting,entry-split,entry-thread,entry-thumb,entry-wide}/*.component.{ts,html}`
- Test: `frontend/src/app/reader/list/magazine/blocks/entry-hero/entry-hero.component.spec.ts:195-208`

**Interfaces:**
- Consumes: `MoreSourcesComponent` (`../../more-sources.component`), `.toggle`, `.mini` from Task 1.

- [ ] **Step 1: Rewrite the hero's footer test** (replaces `'bubbles open for a duplicate copy through the footer'`; it must fail against the old markup):

```ts
  it('opens a duplicate copy from its mini card, and not the hero', () => {
    const dup = entry({ id: 9, source: 'NDR SH' });
    const fixture = mount(entry({ duplicates: [dup] }));
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector<HTMLButtonElement>('app-more-sources .toggle')!.click();
    fixture.detectChanges();

    element.querySelector<HTMLButtonElement>('app-more-sources .mini')!.click();

    expect(entryActions.open).toHaveBeenCalledWith(dup);
    expect(entryActions.open).toHaveBeenCalledTimes(1);
  });
```

Drop the `OverlayContainer` import if nothing else in the file uses it.

- [ ] **Step 2: Run it, expect FAIL** (`app-more-sources .toggle` is null)

Run: `docker compose exec -T frontend npm test -- entry-hero`

- [ ] **Step 3: Swap the component in every block** — in each listed `.html`, `<app-entry-duplicates [entry]="entry()" />` → `<app-more-sources [entry]="entry()" />`; in each `.ts`, `import { EntryDuplicatesComponent } from '../../entry-duplicates.component';` → `import { MoreSourcesComponent } from '../../more-sources.component';` and `EntryDuplicatesComponent` → `MoreSourcesComponent` in `imports: […]`. Then confirm nothing in `blocks/` still names the old one:

Run: `grep -rn "EntryDuplicates\|app-entry-duplicates" frontend/src/app/reader/list/magazine/blocks`
Expected: no output.

- [ ] **Step 4: Run the magazine specs, expect PASS**

Run: `docker compose exec -T frontend npm test -- reader/list`

Any other block spec that queried `.also-entry` or `app-entry-duplicates` gets the same rewrite as Step 1.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/list/magazine/blocks
git commit -m "feat(#2036): magazine blocks show their other sources as mini cards"
```

---

### Task 3: Trending smoke follows the magazine footer

**Files:**
- Modify: `frontend/e2e/trending-smoke.spec.ts:97-105`

The test loops `magazine` and `list`. The list leg keeps the chip assertions; the magazine leg asserts the new footer. The fixture's day story has 6 copies.

- [ ] **Step 1: Branch the footer assertions by layout** — replace lines 100–104:

```ts
    if (layout === 'magazine') {
      const toggle = story.locator('app-more-sources .toggle');
      await expect(toggle).toHaveText(/6 more sources/);
      await expect(story.locator('.mini')).toHaveCount(0);
      await toggle.click();
      await expect(story.locator('.mini')).toHaveCount(6);
    } else {
      await expect(story.locator('.also-foot .lead')).toHaveText('Also covered by');
      await expect(story.locator('.also-entry')).toHaveCount(4);
      await story.locator('.also-more').click();
      await expect(story.locator('.also-entry')).toHaveCount(6);
    }
```

and rename the test from `caps the footer` to `lists the other sources`.

- [ ] **Step 2: Run it against the Docker stack, expect PASS for both layouts**

Run (from `frontend/`, in the checkout that ran `docker compose up`): `npm run e2e -- trending-smoke`

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/trending-smoke.spec.ts
git commit -m "test(#2036): the Trending smoke opens the magazine's other sources"
```

---

### Task 4: Gate, live check, PR

- [ ] **Step 1: Full frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint, tsc and Jest all green. Fix Prettier with `npx prettier --write` on the reported files only.

- [ ] **Step 2: Live check on :4200** (magazine layout, an entry with duplicates — Trending works): collapsed line on a narrow card and on a hero; expand both; confirm the grid is one column on the narrow card and 2–3 on the hero; thumbnail crop, favicon fallback, ellipsis on long feed and title; click a mini card opens that copy and not the parent; airy and boxed density; light and dark. Screenshot collapsed + expanded for the PR.

- [ ] **Step 3: Confirm the list view is unchanged** — switch to list layout, same entry: chips and popover as before.

- [ ] **Step 4: `/simplify`, then push and open the PR** into `develop`, body `Closes #2036`, screenshots attached.
