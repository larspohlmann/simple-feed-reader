# Audio playlist — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Grow the reader's audio player (#915) from one track into an ordered, reorderable, persisted playlist with previous/next, artwork, and an "Add to playlist" control in the article view (#1429).

**Architecture:** A pure, immutable `Playlist` value (`reader/audio/playlist.ts`) holds the tracks and the current index; every queue rule (add, insert-next, move, remove, step) is a function on it. `AudioPlayerService` keeps one `signal<Playlist>` and drives the single `HTMLAudioElement` from it. Persistence goes through `PlaylistStore` (localStorage today), the one seam a server-side playlist would replace. The bar gains artwork, previous/next and a collapsible panel built on `cdkDropList`; the reader view gains an "Add to playlist" toggle.

**Tech Stack:** Angular 20 (signals, standalone), `@angular/cdk/drag-drop`, Jest (jsdom), Playwright.

**Spec:** GitHub issue #1429, with the owner's comment ("The player should also show the artwork. Both in the current track and in the playlist") and the mockup agreed in session (round 1: bar keeps its height; artwork left; prev/next around the ±15 s skips; playlist toggle with count; panel expands upward from the bar; rows = handle, thumbnail, title, up/down, remove; played rows stay, greyed; "Add to playlist" ↔ "In playlist" next to Listen).

## Rulings

- **Storage:** `localStorage` for now, behind `PlaylistStore`. No backend, no cross-device sync yet; a server-side playlist replaces only that class.
- **Storage format:** key `sfr.audio`, `{ tracks, index, position }`. The pre-#1429 shape `{ track, position }` restores as a one-track playlist.
- **Identity:** a track is identified by its `url` (the enclosure URL); one URL appears at most once.
- **Listen = play now:** the track goes right after the current one (moved there if already queued, so nothing between is skipped or marked played) and starts. The rest of the queue is kept.
- **Add to playlist:** appends without interrupting. Into an empty player it becomes the current track, paused.
- **History:** played tracks stay in the list (greyed) so previous has somewhere to go; the user removes them.
- **End of playlist:** playback stops on the last track, which stays current.
- **Previous:** restarts the current track when more than `RESTART_THRESHOLD_SECONDS` (3) in, or when there is no previous track; otherwise steps back.
- **Removing the current track:** the next track takes its place (the previous one when it was last), keeping the play/pause state; removing the last remaining track stops the player.
- **Close (✕):** stops and clears the whole playlist, as it cleared the single track before.
- **Media Session:** `previoustrack` / `nexttrack` bound to `previous()` / `next()` only while there is a track to step to: iOS replaces the lock screen's ±15 s buttons with track buttons once a handler is set.
- **A failed track** (an expired enclosure) is skipped like an ended one while playing; a paused one stays put.
- **Layering (owner, visual round):** the player and playlist win over every shell layer; the narrow drawer ends above the player; the bar publishes `--audio-player-height` so the reader's to-top button sits clear of it. No drop shadow above the player. Rows show the track length.
- **Drop lists:** the bar is a sibling of the shell, outside every other `cdkDropList`; the panel owns one flat list.
- **Touch drag:** only from the handle, with `[cdkDragStartDelay]="{ touch: 180, mouse: 0 }"` (the sidebar's value), plus up/down buttons for touch and keyboard.

## Global Constraints

- Standalone components, signals, OnPush; component styles in a sibling `.scss`.
- No hex colours, no ad-hoc `px` spacing or media-query literals outside the theme (Stylelint); tokens from `docs/design-language.md`.
- Prettier 100 columns; `npm run check` green inside the Docker frontend container.
- Comments only where a reader would get the code wrong without them.
- i18n keys in both `public/i18n/en.json` and `de.json`.
- Commit format `feat(#1429): lower-case summary`, no attribution lines.

---

### Task 1: The `Playlist` value

**Files:**
- Create: `frontend/src/app/reader/audio/playlist.ts`
- Test: `frontend/src/app/reader/audio/playlist.spec.ts`

**Interfaces — Produces:**

```ts
export interface Playlist { readonly tracks: readonly AudioTrack[]; readonly index: number } // index -1 = none
export const EMPTY_PLAYLIST: Playlist;
export function currentTrack(playlist: Playlist): AudioTrack | null;
export function indexOf(playlist: Playlist, url: string): number;
export function append(playlist: Playlist, track: AudioTrack): Playlist;      // no-op when queued; empty → index 0
export function insertNext(playlist: Playlist, track: AudioTrack): Playlist;  // queued → that index; else after current, becomes current
export function select(playlist: Playlist, index: number): Playlist;          // out of range → unchanged
export function move(playlist: Playlist, from: number, to: number): Playlist; // index follows the current track
export function remove(playlist: Playlist, url: string): Playlist;            // current removed → next, else previous, else EMPTY
```

**Tests:** append (empty becomes current; dedupe; keeps index), insertNext (after current; jumps to a queued one; into empty), select bounds, move keeps the current track current (moving it, moving across it both directions, clamped targets), remove (before current shifts index; current → next; current last → previous; only one → empty; unknown url unchanged).

### Task 2: `PlaylistStore` and the service

**Files:**
- Create: `frontend/src/app/reader/audio/playlist.store.ts`
- Modify: `frontend/src/app/reader/audio-player.service.ts`
- Test: `frontend/src/app/reader/audio/playlist.store.spec.ts`, `frontend/src/app/reader/audio-player.service.spec.ts`

**Interfaces — Produces (service):** `tracks`, `index`, `current` (computed), `hasPrevious`, `hasNext`, `isQueued(url): boolean`; methods `play(track)` (Listen), `enqueue(track)`, `dequeue(url)`, `playAt(index)`, `move(from, to)`, `next()`, `previous()`, `stop()`, plus the existing `toggle`, `seek`, `skip`.

`PlaylistStore`: `load(): SavedPlaylist | null`, `save(saved: SavedPlaylist): void`, `clear(): void`, where `SavedPlaylist = { playlist: Playlist; position: number }`. Reads the legacy `{ track, position }` shape; anything malformed reads as null and is cleared.

**Tests (service):** add, reorder, remove (incl. current while playing keeps playing the replacement), advance on `ended` (and stop at the end), previous restarts past 3 s / steps back under it / restarts at index 0, Listen inserts after current and keeps the queue, enqueue into empty loads paused, media session `nexttrack`/`previoustrack`, persistence round-trip of tracks + index + position, legacy shape restore, identity change clears.

### Task 3: The bar — artwork, previous/next, collapsible playlist

**Files:**
- Modify: `frontend/src/app/reader/shell/audio-player-bar/audio-player-bar.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/public/i18n/en.json`, `de.json` (`reader.audioPlayer.previous`, `next`, `playlist`, `playlistHeading`, `moveUp`, `moveDown`, `remove`, `playTrack`)

Artwork thumbnail (`imageUrl`, falling back to the favicon) at the left of the bar and on each row. `skip_previous` / `skip_next` around the skip controls; next disabled without a next track. A `queue_music` toggle with the count, `aria-expanded`, opens a panel *above* the controls inside the bar host, capped in height and scrolling with `overscroll-behavior: contain`. Rows: handle (`drag_indicator`, `cdkDragHandle`), thumbnail, title button (plays the row), up/down (`arrow_upward`/`arrow_downward`, disabled at the ends), remove (`close`). Current row marked `aria-current`; rows before it get `.played`.

**Tests:** prev/next call the service; next disabled at the end; toggle expands and collapses the panel; the panel lists every track with the current one marked; up/down/remove/row-click call the service; a `cdkDropListDropped` event calls `move(previousIndex, currentIndex)`; artwork shows the image, else the favicon.

### Task 4: "Add to playlist" in the article view

**Files:**
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.{ts,html,scss,spec.ts}`
- Modify: `en.json`, `de.json` (`reader.audioPlayer.addToPlaylist`, `inPlaylist`)

A toggle beside Listen, `aria-pressed` = queued: `playlist_add` "Add to playlist" ↔ `playlist_add_check` "In playlist"; clicking when queued dequeues.

**Tests:** shows for an audio entry only; adds via `enqueue`; shows "In playlist" when `isQueued`; clicking it then calls `dequeue`.

### Task 5: Playwright smoke

**Files:** Create `frontend/e2e/audio-playlist.spec.ts`

Stub three audio entries (list + detail + reader routes, each with an audio attachment served as silent WAV from a loopback server), add all three from their article views, reorder by the move buttons (and one drag), press next, assert the bar title follows the new order.

Commit after each task: `feat(#1429): …` / `test(#1429): …`.
