# Dedicated Pictures / Videos / Audios views (#916)

Last of the #906 (`media[]`/`attachments[]`) follow-ons. Frontend only.

## Problem

Every entry now carries a structured `media[]` (visual) and `attachments[]`
(playable) list. The magazine, list and pane layouts still show one lead image
per entry, so the richer media is invisible. #916 adds media-first browsing.

## Approach

Pictures, Videos and Audios are **projections over the entry collection the
store already loads** for the current selection (feed, folder, view, search).
There is no new endpoint: `media[]`/`attachments[]` arrive on every `EntryDto`
already. The views re-scope nothing — they render whatever the current
selection holds, empty-state included.

They become three new `ReadingLayout` values
(`'pictures' | 'videos' | 'audios'`) alongside `magazine | list | pane`, chosen
in a second segmented group in `ViewControlsComponent`. Layout stays a
localStorage UI-state concern (`sfr.layout`), not a route param — matching the
existing precedent. Magazine stays the default and the primary layout.

### Reuse of list chrome

`EntryListComponent` already owns the title, the item count, refresh, the
pull-to-refresh gesture, scroll memory and the infinite-scroll sentinel that
calls `loadMore`. The media views keep all of it and swap only the **body**:
the list template gains one branch that delegates to a new presentational
`MediaViewComponent`, while `EntryListComponent` stays the scroll owner and the
`#sentinel`/`loadMore` machinery is untouched.

### The projection core (pure, TDD-first)

`reader/media-view-projection.ts`:

- `pictureTiles(entries)` — every `media[]` item with `kind: 'image'`, carrying
  its owning entry id; deduped by exact URL (see Dedupe).
- `videoTiles(entries)` — every `media[]` item with `kind: 'video'`, with its
  `previewImageUrl` poster and owning entry.
- `audioItems(entries)` — each entry that has a first audio attachment
  (`firstAudioAttachment`), paired with that attachment.

Pure functions over `EntryDto[]`, no Angular — unit-tested in isolation.

### Rendering — `MediaViewComponent`

Presentational. Inputs `mode` and `entries`; output `open` (entry id).

- **Pictures / Videos**: a responsive tile grid. A video tile lays a play badge
  over its poster. Tapping a tile emits `open` → the shell's reader overlay;
  video plays through the existing reader media pipeline.
- **Audios**: a row list. Each row has a play button wired to the #915
  `AudioPlayerService` (`play(toAudioTrack(entry, attachment))`), so the shared
  mini-bar drives playback; the title opens the entry.
- Each mode renders its own empty state when the projection is empty.

### Dedupe

Exact-URL dedupe within a projection. The backend already collapses CDN
renditions inside an entry's `media[]` via `ImageIdentity` (PHP); porting that
identity logic to TypeScript is a separate, larger effort and is **out of
scope** here — noted as a possible follow-up if cross-entry rendition
duplicates prove visible in practice.

## Non-goals

- No new API, no `EntryDto` change, no query-param/deep-link surface.
- No TypeScript port of `ImageIdentity`.
- Magazine remains primary; these are additional views, never replacements.

## Native iOS

Pure frontend over the existing DTOs. The Bearer/JSON contract is untouched; a
Swift client can build the same projections from the same fields.

## Testing

- `media-view-projection.spec.ts` — the three projectors: kind filtering, entry
  id carry-through, URL dedupe, empty input, missing-poster videos.
- `media-view.component.spec.ts` — mode switch renders the right shape, tile
  click emits `open`, audio play calls the service, empty states.
- `view-controls.component.spec.ts` — the media buttons set the layout.
- `entry-list` body delegates to `<app-media-view>` on a media layout.
