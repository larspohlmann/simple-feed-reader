# Richer full-width mini cards for a story's other sources (#2055) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Trending story's expanded "N more sources" list in the magazine renders one full-width mini card per outlet: picture (or favicon box) on the left, byline with favicon, the full title, one line of dek.

**Architecture:** Only `MoreSourcesComponent`'s story branch changes (template, styles, one computed field). The grid becomes a stacked column. The `else` branch (`<app-entry-duplicates>`, a post's own copies) is untouched (#2053). Two tokens replace the square `--mini-card-thumb` and the now-unused `--mini-card-min-w`.

**Tech Stack:** Angular 20 standalone + signals, SCSS tokens, Jest.

**Spec:** issue #2055 and mockup B, agreed in the visual round (rendered live on :4200 against the NYT "Nithya Raman" story).

## Global Constraints

- Scope: a story's other outlets only. A non-story entry with duplicates keeps its "Also in" pills, byte for byte.
- Match mockup B exactly — values below. No hex, no ad-hoc `px` outside `src/app/theme/`.
- Keep class names `.more-sources`, `.toggle`, `.minis`, `.mini`, `.thumb`, `.feed`, `.title` (the spec and `frontend/e2e/trending-smoke.spec.ts` select on them).
- Commit format `type(#2055): …`; no attribution lines.

### Mockup B → tokens

| Element | Mockup value | Built with |
|---|---|---|
| list `.minis` | flex column, gap 8px, margin-top 4px | `--space-2`, `--space-1` |
| card `.mini` | flex row, `align-items: center`, gap 12px, padding 8px, `--surface-sunken`, `--radius-sm` | `--space-3`, `--space-2` |
| card hover | `--border` background (unchanged) | unchanged |
| thumb | 104×68, `object-fit: cover`, `--radius-sm` | new `--mini-card-thumb-w: 104px`, `--mini-card-thumb-h: 68px` |
| no-image thumb | same box, `--surface-1`, favicon 20px centred | `<app-favicon [size]="20">` |
| text column | flex column, gap 2px | `--space-0` |
| byline | `--fs-xs`, `--text-muted`; favicon 14px; feed `--text-secondary` 500; gap 6px | `<app-favicon [size]="14">`, gap `calc(var(--space-1) + var(--space-0))` |
| title | `--fs-base`, `--text-primary`, line-height 1.3, wraps in full | line-height `--lh-tight` (1.25, nearest token) |
| dek | `--fs-sm`, `--text-secondary`, line-height 1.4, one line clamp | as mocked (1.4 is the magazine dek's value) |

---

### Task 1: mini card markup and styles

**Files:**
- Modify: `frontend/src/app/reader/list/magazine/more-sources.component.ts`
- Modify: `frontend/src/app/reader/list/magazine/more-sources.component.html`
- Modify: `frontend/src/app/reader/list/magazine/more-sources.component.scss`
- Modify: `frontend/src/app/theme/tokens.scss:122-123`
- Modify: `docs/design-language.md:64-65` and the `<app-more-sources>` entry (~line 1507)
- Test: `frontend/src/app/reader/list/magazine/more-sources.component.spec.ts`

**Interfaces:**
- Consumes: `entryDek(entry: EntryDto): string` from `../../entry/entry-text`.
- Produces: each `sources()` item gains `dek: string`.

- [ ] **Step 1: Write the failing tests** — append to the spec:

```ts
it("shows each source's dek under its title", () => {
  const withDek = anEntry({ id: 5, source: 'BBC', title: 'Raman wins', excerpt: 'A second big win.' });
  const element = mount(storyOf([withDek]));
  expand(element);

  expect(element.querySelector('.mini .dek')!.textContent).toContain('A second big win.');
});

it('leaves out the dek line when a source has none', () => {
  const bare = anEntry({ id: 6, source: 'CBC', title: 'New mayor', excerpt: '' });
  const element = mount(storyOf([bare]));
  expand(element);

  expect(element.querySelector('.mini .dek')).toBeNull();
});

it("puts the source's favicon in its byline", () => {
  const element = mount(storyOf(copies));
  expand(element);

  expect(element.querySelector('.mini .byline app-favicon')).not.toBeNull();
});
```

`entryDek` returns `entry.excerpt` (a string, `''` by default in `anEntry`), and `''` for a post (`titleDerived`), whose text is already its headline.

- [ ] **Step 2: Run, expect FAIL** (no `.dek`, no byline favicon):

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/more-sources.component.spec.ts`

- [ ] **Step 3: Component** — in `sources`, add the dek:

```ts
import { entryDek, entryDisplayTitle, entryHeadline } from '../../entry/entry-text';
// …
      title: entryHeadline(copy) || entryDisplayTitle(copy),
      dek: entryDek(copy),
      when: entryRelativeTime(copy, this.language.lang()),
```

- [ ] **Step 4: Template** — replace the `@for` body inside `.minis` (drop the `title` attribute: the full title is now visible):

```html
<button
  type="button"
  class="mini reads-as-text"
  (click)="open(source.copy, $event)"
  (keydown.enter)="$event.stopPropagation()"
  (keydown.space)="$event.stopPropagation()"
>
  @if (source.copy.imageUrl) {
    <img class="thumb" [src]="source.copy.imageUrl" alt="" loading="lazy" />
  } @else {
    <span class="thumb tile">
      <app-favicon [url]="source.copy.faviconUrl" [avatarUrl]="source.avatarUrl" [size]="20" />
    </span>
  }
  <span class="text">
    <span class="byline">
      <app-favicon [url]="source.copy.faviconUrl" [avatarUrl]="source.avatarUrl" [size]="14" />
      <span class="feed">{{ source.feed }}</span>
      <span class="when">· {{ source.when }}</span>
    </span>
    <span class="title">{{ source.title }}</span>
    @if (source.dek) {
      <span class="dek">{{ source.dek }}</span>
    }
  </span>
</button>
```

- [ ] **Step 5: Tokens** — in `tokens.scss` replace lines 122-123:

```scss
  --mini-card-thumb-w: 104px;
  --mini-card-thumb-h: 68px;
```

`grep -rn "mini-card-thumb\b\|mini-card-min-w" frontend/src` must then hit nothing.

- [ ] **Step 6: Styles** — in `more-sources.component.scss` replace `.minis` through the end of the file (keep `:host`, `.more-sources`, `.toggle` and the toggle's hover rule):

```scss
.minis {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin-top: var(--space-1);
}

.mini {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  min-width: 0;
  padding: var(--space-2);
  border: none;
  border-radius: var(--radius-sm);
  background: var(--surface-sunken);
  color: var(--text-secondary);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

@media (hover: hover) {
  .toggle:hover {
    background: var(--card-hover-bg);
  }

  .mini:hover {
    background: var(--border);
  }
}

.thumb {
  flex: none;
  width: var(--mini-card-thumb-w);
  height: var(--mini-card-thumb-h);
  border-radius: var(--radius-sm);
  object-fit: cover;
}

.thumb.tile {
  display: grid;
  place-items: center;
  background: var(--surface-1);
}

.text {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: var(--space-0);
  min-width: 0;
}

.byline {
  display: flex;
  align-items: center;
  gap: calc(var(--space-1) + var(--space-0));
  min-width: 0;
  color: var(--text-muted);
  white-space: nowrap;
}

.feed {
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--text-secondary);
  font-weight: 500;
}

.when {
  flex: none;
}

.title {
  color: var(--text-primary);
  font-size: var(--fs-base);
  line-height: var(--lh-tight);
}

.dek {
  font-size: var(--fs-sm);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
```

The `.more-sources` box stays at `--fs-xs`, which is the byline's size; title and dek set their own.

- [ ] **Step 7: Run the spec, expect PASS** (all existing tests too):

Run: `docker compose exec -T frontend npx jest src/app/reader/list/magazine/more-sources.component.spec.ts`

- [ ] **Step 8: Docs** — in `docs/design-language.md` replace the two token rows (64-65) with:

```
| `--mini-card-thumb-w` | `104px` | the picture width of a "more sources" mini card (#2055) |
| `--mini-card-thumb-h` | `68px` | the picture height of a "more sources" mini card (#2055) |
```

and the first paragraph of `### <app-more-sources>` with:

```
A magazine card's footer slot. A Trending story's other outlets (#2036) get one accent line,
"x more sources", that opens in place into a stack of full-width mini cards (#2055) — a smaller
compact card on `--surface-sunken`: the copy's picture at `--mini-card-thumb-w` × `--mini-card-thumb-h`
(else its favicon centred on `--surface-1` in the same box), a byline of favicon, feed and relative
time, the full title, and one line of dek. A mini card opens its copy; it carries no actions.
```

- [ ] **Step 9: Gate** — `docker compose exec -T frontend npm run check` green.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/reader/list/magazine/more-sources.component.* frontend/src/app/theme/tokens.scss docs/design-language.md docs/superpowers/plans/2026-11-06-2055-richer-source-mini-cards.md
git commit -m "feat(#2055): a story's other sources open as full-width mini cards with picture, title and dek"
```

### Task 2: verify against mockup B in the real render

- [ ] On :4200 → Trending (24 h), magazine layout: expand the top story's sources. Compare region by region with mockup B: card shade and radius, 104×68 picture left, favicon box for a source without picture, byline favicon, full wrapping title, one-line dek, 8px between cards. Measure computed sizes with `getComputedStyle` rather than eyeballing.
- [ ] Check the story footer in a narrow block (`split`/`thumb`) and at phone width (375px): the picture keeps its size, the title wraps, nothing overflows the card. Report what narrow looks like; don't change it without asking.
- [ ] A post (non-story) with duplicates outside Trending still shows its "Also in" pills unchanged.
- [ ] `npm run e2e -- trending-smoke` (the `.mini` count assertions) passes.
- [ ] `/simplify`, then PR into `develop` with `Closes #2055`; push with `-u origin feature/2055-richer-source-mini-cards` (the branch currently tracks `origin/develop`).
