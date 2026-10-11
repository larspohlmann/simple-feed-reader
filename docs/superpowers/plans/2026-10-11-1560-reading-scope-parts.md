# #1560 ReadingScope: one ordered list of parts

**Goal:** `ReadingScope` takes the parts below the article body as one list in document
order, so the resize observer, the scope's bottom and the focus blocks derive from it
instead of each naming its own subset. Behaviour does not change.

## Rulings

- `ReadingScopeParts` becomes `{ scroller, content, trailing: readonly TrailingPart[] }`;
  a `TrailingPart` is `{ query: ElementQuery, carriesText: boolean }`. The issue sketched
  `trailing: ElementQuery[]`, but "the trailing parts that carry text" needs the part to
  say so; a field on the part keeps the next section a one-line change.
- **Link cards stay out of the focus dimming** (`carriesText: false`), as today: a card is
  one tappable unit with an image, not a paragraph of running text; dimming it reads as
  disabled. The comments carry text and stay in.
- All trailing parts are read untracked outside the resize-observer effect, through one
  helper with the one comment that says why.
- The bottom of the scope is the last trailing part present, else the content — the same
  as `comments ?? linkCards ?? content` for today's order.

## Files

- `frontend/src/app/reader/article/reading/reading-scope.service.ts`
- `frontend/src/app/reader/article/reading/reading-scope.service.spec.ts` (new)
- `frontend/src/app/reader/article/reader-view/reader-view.component.ts`

## Tasks

1. Add `reading-scope.service.spec.ts` against the current API pinning the three subsets:
   focus blocks = content + comments (no link cards); observed = content, link cards,
   comments; bottom = comments, else link cards, else content. Green on develop.
2. Refactor the service and the caller to the ordered list; update the spec's `connect`
   call only. Remove the stale "body and comments" comments.
3. Prove the pin: flip the link cards to `carriesText: true` once, see the spec fail, restore.
4. `docker compose exec -T frontend npm run check`.
