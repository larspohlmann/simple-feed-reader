# #1557 — "share" for the pinned blocks, "post" only for titleDerived

**Goal:** one meaning per word in the reader list. "Share" names the blocks `entry.share`
pins (link, media); "post" names only a `titleDerived` entry, through one `isPost(entry)`.
The `titleDerived` text rules live in one `reader/entry/entry-text.ts`.

## Rulings

- `isPost`, `entryHeadline`, `entryDek`, `entryDisplayTitle` move into
  `reader/entry/entry-text.ts`; `entry-display-title.ts` is deleted, `entrySnippet` is dropped.
- `isPost(entry)` is used at the six sites: `entryHeadline`, `entryDek`, `EntryBlockBase`,
  `EntryRowComponent`, `ArticleSourceService.open`, the reader-view title.
- The list row renders its snippet through `entryDek` and `@if (snippet())`, like the blocks:
  an article with an empty excerpt no longer renders an empty `<p class="snippet">` (the one
  DOM difference the issue accepts).
- The `.title.post` / `.caption.post` classes stay: they mean titleDerived (#1555 keeps them).
- Spec fixtures and descriptions in the planner/slot-fit specs follow the share vocabulary;
  no new specs for renamed identifiers (lean testing for a rename).

## Files

- `frontend/src/app/reader/entry/entry-text.ts` (+ `.spec.ts`, moved tests)
- `frontend/src/app/reader/list/preview-image.ts` (+ spec): text helpers removed
- `frontend/src/app/reader/list/magazine/magazine-slot-fit.ts` (+ spec): `ShareKind`,
  `shareKind`, `isShareKind`, `sharesLink`, `sharesMedia`, `sharedMedia`, `MAX_SHARED_MEDIA`
- `magazine-planner.ts` (+ spec), `entry-block-base.ts`, `entry-media` component (`.media-share`)
- `entry-row` component, `article-source.service.ts`, `reader-view` component,
  `reader-tab-title.service.ts`, `audio-attachment.ts`
- `frontend/src/app/theme/tokens.scss`: `--media-share-max-h`
- `docs/design-language.md`: Link/Media rows

## Tasks

1. Create `entry-text.ts`, move the tests, repoint imports, delete the old homes.
2. Rename the share identifiers, class and token; update the design-language rows.
3. `npm run check` in the Docker frontend container.
