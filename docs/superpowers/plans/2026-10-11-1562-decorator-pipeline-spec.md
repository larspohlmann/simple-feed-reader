# #1562 Decorator pipeline: pin the hand-offs in one spec, one host-fixture helper

**Goal:** a `decorate-article.spec.ts` that fails when a decorator is dropped, reordered past a
step it depends on, or renames a class another step selects; and one shared host-fixture helper
in place of the copies across the decorator and `media-embeds` specs. Tests only.

## Rulings

- The pipeline spec drives the real `decorateArticle` with the real `TranslocoService`
  (`provideTranslocoTesting`), so nothing in the hand-off is stubbed.
- Hand-offs pinned: `upgradeMediaEmbeds` → `addCinemaToggles` (landscape embed in a cinema box,
  portrait not), and `markNarrationPlayers` → `expandFaqDisclosures` (the narration box stays a
  collapsed non-FAQ while a publisher `<details>` in the same body becomes a FAQ, so the FAQ step
  is proven to have run).
- `reader-cinema.spec.ts` keeps its hand-written landscape fixture: it is a unit spec of the
  cinema step; the pipeline spec is what catches a one-sided rename.
- Helper: `frontend/src/testing/article-host-testing.ts` with `articleHost(html, decorate?)` and
  `attachedArticleHost(html)` (code-highlight and hls specs attach to `document`).
  `reader-image-fit.spec.ts` builds an empty host with a stubbed width, not an `innerHTML`
  fixture, and stays as it is.

## Tasks

1. Write the helper; switch the decorator specs and `media-embeds.spec.ts` to it, inline copies
   included.
2. Write `decorate-article.spec.ts`; break-test it once (cinema before embeds; narration class
   renamed) and record the failures in the PR.
3. `docker compose exec -T frontend npm run check`.
