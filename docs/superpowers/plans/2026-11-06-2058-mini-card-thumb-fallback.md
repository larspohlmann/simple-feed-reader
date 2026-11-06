# Mini-card thumbnails: favicon fallback and renditions (#2058) Implementation Plan

**Goal:** A "more sources" mini card's thumbnail loads the smallest rendition covering 104px, and a picture that fails to load falls back first to its plain `src`, then to the favicon tile.

**Architecture:** Stays inside `MoreSourcesComponent`; no new component. The per-card error state is a `linkedSignal` set of failed copy ids, reset when the copies change. Each `<img>` uses the existing `RenditionsDirective` with a `#renditions` ref per `@for` iteration, and its `(error)` follows the magazine blocks: `renditions.fallBackToSrc() || markBroken(id)`. A new `MINI_CARD_THUMB_SIZES = '104px'` joins `rendition-sizes.ts`, matching `--mini-card-thumb-w`.

**Stacked on:** #2057 (branch `fix/2058-mini-card-thumb-fallback` is cut from `feature/2055-richer-source-mini-cards`). Merge #2057 first.

## Task 1: renditions + broken-picture fallback

**Files:** `frontend/src/app/reader/list/rendition-sizes.ts`, `frontend/src/app/reader/list/magazine/more-sources.component.{ts,html,spec.ts}`, `docs/design-language.md` (the `<app-more-sources>` entry)

- [ ] Spec first, in `more-sources.component.spec.ts`:
  - a copy with `imageRenditions` renders `img.thumb` with a `srcset` and `sizes="104px"`;
  - an `error` event on `img.thumb` of a copy with renditions drops the `srcset` and keeps the `<img>` (retry of the plain `src`);
  - a second `error` (or the first, without renditions) replaces the `<img>` with the `.thumb.tile` favicon box for that card only — the other card's picture stays.
  Run `docker compose exec -T frontend npx jest src/app/reader/list/magazine/more-sources.component.spec.ts`, expect the three to FAIL.
- [ ] `rendition-sizes.ts`: `export const MINI_CARD_THUMB_SIZES = '104px';`
- [ ] Component:

```ts
protected readonly thumbSizes = MINI_CARD_THUMB_SIZES;
private readonly brokenThumbs = linkedSignal<readonly EntryDto[], ReadonlySet<number>>({
  source: this.copies,
  computation: () => new Set(),
});
protected readonly sources = computed(() =>
  this.copies().map((copy) => ({
    …existing fields…,
    picture: this.brokenThumbs().has(copy.id) ? null : copy.imageUrl,
  })),
);

markBroken(copy: EntryDto): void {
  this.brokenThumbs.update((broken) => new Set(broken).add(copy.id));
}
```

- [ ] Template: `@if (source.picture) { <img class="thumb" [src]="source.picture" #renditions="appRenditions" [appRenditions]="source.copy.imageRenditions" [renditionSizes]="thumbSizes" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" (error)="renditions.fallBackToSrc() || markBroken(source.copy)" /> } @else { …favicon tile, unchanged… }`; import `RenditionsDirective`.
- [ ] Docs: in `<app-more-sources>`, "(else its favicon …)" becomes "(else, or when the picture fails to load, its favicon …)"; mention the 104px rendition.
- [ ] Spec green; `docker compose exec -T frontend npm run check` green.
- [ ] Commit `fix(#2058): a mini card loads a 104px rendition and falls back to its favicon when the picture breaks`.

## Task 2: verify and ship

- [ ] Live on :4200 Trending: expanded thumbnails carry `srcset`/`sizes="104px"` and `currentSrc` is a rendition where the entry has them; break one picture in DevTools (set its `src` to a 404) and see the favicon box.
- [ ] `/simplify`, push, PR into `develop` with `Closes #2058`, noting it is stacked on #2057.
