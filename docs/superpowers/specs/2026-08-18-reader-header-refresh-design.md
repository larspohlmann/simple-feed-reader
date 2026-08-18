# Reader header: kept/favorite on mobile + per-article refresh

Issue: #470

## Problem

The article-view toolbar (`.bar > .nav` in `reader-view.component`) shows the
**favorite** (star) and **keep** (bookmark) buttons only in the desktop split
pane. They are wrapped in `@if (!fullscreen())`, so on mobile — where the reader
runs full-screen — the toolbar shows only the reader/original mode toggle, and
those two actions are absent from the header.

Separately, an extracted article is cached in IndexedDB (`sfr-reader` store,
keyed by entry id) with no TTL. The schema VERSION is the only cache-buster
(#467). There is no way to force a fresh copy of a single article — when a page
lazy-loaded its images or the extraction was poor — short of dropping the whole
store.

## Change

All changes are in the frontend `reader-view` area. No backend change, no cache
VERSION bump.

### 1. Unify the toolbar across layouts

Remove the `@if (!fullscreen())` guard around the favorite and keep buttons in
`.nav`, so the toolbar reads the same in both layouts:

```
[favorite ★] [keep 🔖] [refresh ⟳] [reader/original toggle]
```

The favorite/keep buttons keep their existing `favorite.emit()` / `keep.emit()`
outputs, `aria-pressed`, and `.on` accent styling. Nothing new is wired — they
just stop being hidden on mobile. The inline `.actions` row inside the article
body is unchanged.

### 2. New refresh button (icon-only)

A new button sits between keep and the mode toggle:

- Icon `refresh` (already present in the loaded font — used by the sidebar and
  entry list), size `md`, no text label.
- `aria-label` from a new i18n key `reader.refreshArticle` ("Reload article" /
  German equivalent). It does **not** reuse `reader.refresh`, which is the
  whole-feed refresh.
- Shown whenever an entry is open — not gated on `readerMode.canToggle()` — so
  it also retries a failed extraction.

### 3. Refresh behavior — drop the cache, refetch, show loading

- **`ReaderCacheService.delete(entryId)`** — new method; deletes the one
  IndexedDB record. Deleting an absent id is a no-op.
- **`ReaderContentService.reload(entryId)`** — deletes the cache record, then
  calls the API and re-caches a successful result. Mirrors `load()` but is
  cache-busting instead of cache-first.
- **`ReaderViewComponent.refreshArticle()`** — cancels any in-flight `loadSub`,
  sets `state` to `loading` (this shows the existing "Loading reader view…"
  message), then subscribes to `reload(entryId)` with the same
  `next`/`error`/timeout handling as the initial load. The reader/original
  **mode is preserved** — refresh reloads the underlying extraction; it does not
  reset the toggle.

The success/failure/timeout subscribe block is currently inline in the
constructor's load effect. Extract it into one private helper both the effect
and `refreshArticle()` call, rather than duplicating it (DRY — the house rule).
The helper takes the observable to subscribe to (`load` vs `reload`) so the
mode-reset that belongs only to a genuine entry change stays in the effect.

## Testing

- `ReaderCacheService`: `delete` removes the record; delete of an absent id is a
  no-op (falsifiable — prove the record is gone via a subsequent `get`).
- `ReaderContentService`: `reload` deletes then fetches from the API even when a
  cached copy existed, and re-caches the fresh `ok` result. A failed result is
  not cached.
- `ReaderViewComponent`:
  - the favorite and keep buttons are present in fullscreen (mobile), not only
    in the split pane;
  - the refresh button is present in both layouts and while an extraction has
    failed;
  - clicking refresh enters the loading state and renders the refetched article;
  - clicking refresh does not reset the reader/original mode.

## Out of scope

- No backend change.
- No shell/header (list chrome) change.
- No change to the inline `.actions` row inside the article.
- No cache VERSION bump — the global cache-bust shipped in #467; this is the
  per-article manual bust that complements it.
