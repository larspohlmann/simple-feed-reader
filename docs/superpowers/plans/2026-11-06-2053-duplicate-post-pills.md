# Duplicate posts keep their "Also in" pills (#2053) Implementation Plan

**Goal:** In the magazine, only a Trending story's other outlets open into "x more sources" mini cards (#2036); a post that appears in several feeds shows the old `app-entry-duplicates` pill footer ("Also in", feed pills, popover) exactly as before #2037.

**Architecture:** `MoreSourcesComponent` is the magazine's one footer slot (all 11 blocks render it). Its template branches on `entry().story`: a story renders the mini-card footer; any other entry renders `<app-entry-duplicates [entry]="entry()" />`, unchanged. No block template changes.

**Regression origin:** #2036's design merged the two cases ("story vs copy both read x more sources") — the request covered only stories.

## Task 1: branch the footer on `story`

**Files:** `frontend/src/app/reader/list/magazine/more-sources.component.{ts,html,spec.ts}`, `…/blocks/entry-hero/entry-hero.component.spec.ts`

- [ ] Spec first: mini-card tests mount a story (`story: { outletCount, viewed: false }`); new tests — a non-story entry with duplicates renders `app-entry-duplicates .also-entry` pills labelled "Also in" and no `.more-sources`; a story renders no `.also-foot`. Run, expect the non-story test to FAIL.
- [ ] Template: `@if (entry().story) { …mini-card footer… } @else { <app-entry-duplicates [entry]="entry()" /> }`; import `EntryDuplicatesComponent`.
- [ ] Hero spec: restore the pre-#2037 test that opens a duplicate copy through the pill's popover (non-story), and keep a story test through the mini card.
- [ ] `docker compose exec -T frontend npm run check` green; the Trending smoke (stories) still passes in both layouts.
- [ ] Update the `<app-more-sources>` catalog entry in `docs/design-language.md`: stories only; other duplicates keep the pill footer.
- [ ] Live check on :4200: a non-story duplicate in the magazine shows the pills; a Trending story shows the mini cards.
- [ ] `/simplify`, PR into `develop` with `Closes #2053`.
