# #1442 — Big player behind the mini-bar artwork

Settled in a visual brainstorming round on 2026-10-08 (mockups in
`.superpowers/brainstorm/`, not committed). The issue body carries the same
decisions; this file adds what the code survey found.

## Decisions

- **Trigger:** the mini bar's artwork (`app-audio-artwork.artwork` in
  `audio-player-bar.component.html`) becomes a button labelled "Open player".
  Nothing else in the bar changes.
- **Surface:** one component, opened through the CDK overlay (a `Dialog`), not
  instantiated in a template — the reader shell can carry a transform, which would
  re-anchor a `position: fixed` child (the `ActionSheet` rule, design-language.md).
  - Below `bp-sm`: a full-screen sheet that slides up, covering the reader and the
    mini bar.
  - From `bp-sm` up: a centered dialog, `max-inline-size` about 420px, over a dimmed
    reader. The mini bar stays visible under the backdrop.
  - Closed by ⌄ in its top bar, Escape, a backdrop click (wide), and a downward
    swipe on the sheet (narrow).
- **Playlist vs big player — mutually exclusive.** Today's playlist panel above the
  mini bar stays as it is. ☰ in the big player closes the big player and opens the
  panel; opening the big player closes the panel. The panel's open state moves out
  of `AudioPlayerBarComponent`'s local `playlistOpen` signal into a small root
  service (`AudioSurface`, next to the bar in `reader/shell/audio-player-bar/`, since it opens the big player) holding `'none' | 'playlist' | 'player'`, so
  both surfaces read and write one value.
- **Layout, top to bottom** (mockup "A" with "C"'s excerpt):
  1. Top bar: ⌄ (close) · "3 of 7" (queue position, `index + 1` of `tracks.length`).
  2. Square artwork, full content width — `AudioArtworkComponent` at a large
     `--artwork-size`; it already falls back to the favicon.
  3. Title (`--fs-lg`, semibold, up to two lines). A button: opens the article.
  4. "Feed · date" — `source` and the entry's `relativeTime`-formatted date, as the
     list row shows it.
  5. Excerpt, clamped to two lines.
  6. Scrubber (below).
  7. Transport: previous · back 15 · play/pause (accent disc) · forward 15 · next —
     the same `AudioPlayerService` calls the mini bar makes.
  8. Bottom row: open article · speed · playlist.
- **Scrubber ("variant 2", Apple-style):** an 8px rounded bar, no thumb, inset to
  the content gutter (not edge to edge). While the pointer is down it grows to 14px
  and the time labels brighten. The hit area is at least 44px tall. Elapsed time
  under the left end, remaining time (`−55:40`) under the right. Keeps the
  three-part fill (played in accent → cached ahead muted → rest hairline) that the
  mini-bar scrubber draws from `--played` / `--cached`. Still a native
  `input[type=range]` so keyboard and screen-reader seeking come for free; only the
  track and thumb styling differ.
- **Open article:** navigates with `queryParams: { entry: entryParam(id, title) }`,
  `queryParamsHandling: 'merge'` — what `EntryStateActions.open()` does — then
  closes the big player. Hidden when the track carries no entry id.
- **Speed:** a button cycling 1× → 1.25× → 1.5× → 2× → 1×, labelled with the
  current value. Kept across tracks and reloads. Big player only; the mini bar gets
  no speed control.

## Data: what `AudioTrack` gains

`AudioTrack` today is `{ url, title, faviconUrl, imageUrl, durationInSeconds }`.
It gains one optional field, `entry?: AudioTrackEntry`, holding the entry the
track was queued from:

| Field | From | Used for |
|---|---|---|
| `id` | `entry.id` | open article |
| `title` | `entry.title` | the slug in `entryParam` |
| `feedTitle` | `entry.source` | "feed · date" line |
| `publishedAt` | `entry.publishedAt ?? entry.createdAt` | "feed · date" line |
| `excerpt` | `entry.excerpt` | excerpt |

One optional object rather than five nullable fields: `toAudioTrack()`
(`reader/audio/audio-attachment.ts`) is the only builder and always fills all of
them, so they are present or absent together. Tracks already in a browser's
`sfr.audio` (`PlaylistStore`) predate the field: they render without the feed
line, excerpt and open-article link. No migration; `PlaylistStore.parse` needs no
change.

## Speed: where it lives

- `AudioPlayerService` gains a `rate` signal and a `cycleRate()` method; the
  list of steps is a constant next to `SKIP_SECONDS`.
- **Both** deck elements get the rate, and both `playbackRate` and
  `defaultPlaybackRate`: assigning `src` (a new track, or the standby taking over
  in `AudioDeck`) resets `playbackRate` to `defaultPlaybackRate`, so setting only
  the former loses the speed at the next track.
- `MediaSessionControls.setPositionState` passes `playbackRate`, or the OS
  lock-screen scrubber runs at 1×.
- Persisted under its own `localStorage` key (e.g. `sfr.audio.rate`), read on
  boot, wrapped in the same try/catch as `PlaylistStore`. Not inside `sfr.audio`:
  `stop()` clears that key, and stopping playback should not reset the speed.
  Device-wide, like the playlist: an identity change (`onIdentityChange → stop()`)
  leaves it alone.

## Lifecycle

- The big player closes itself when `player.current()` becomes null — the mini
  bar's ✕, removing the last track from the panel, or logout. A dialog left open
  over an empty player would otherwise outlive the bar that opened it.
- It holds no playback state, like the mini bar (#915): every readout is a
  signal of `AudioPlayerService`, so it can open on a paused, rehydrated track.

## Testing

- **Jest** (in the frontend container):
  - Artwork button opens the big player; ⌄ / Escape close it; `stop()` closes it.
  - Opening the big player closes the playlist panel; ☰ in it closes it and opens
    the panel.
  - Scrubber input seeks; remaining time renders as `−m:ss`.
  - Speed cycles through the four steps and wraps; the value is applied to both
    deck elements, survives a track change (the standby swap), and is read back
    from storage.
  - A track without the new fields renders with no feed line, excerpt or open
    article button.
  - Open article navigates with the right `entry` query param and closes.
- **Playwright smoke** at phone width, with stubbed routes so it owns its data:
  play → tap artwork → big player → ☰ → panel, and back.
- **By eye**, on a production build (`:4300`) in the browser pane at phone and
  desktop widths, and the swipe-down in the iOS simulator.

## Out of scope

- Chapters, sleep timer, AirPlay/cast.
- A speed control in the mini bar.
- Server-side persistence of speed (the playlist itself is device-local, #1429).

## Addendum: the playlist inside the phone sheet (2026-10-08)

Asked for after the first five tasks, from a look at the phone sheet, where the
space under the controls stood empty.

- **Phone sheet (below `bp-sm`):** ☰ shows and hides the full playlist (play, drag,
  up/down, remove) under the bottom row, inside the sheet. It no longer hands over
  to the bar's panel there. While the list shows, ☰ is accented and
  `aria-expanded="true"`. Showing it scrolls the sheet so the bottom row sits at the
  top, with the list beneath it; otherwise the tap would change nothing visible.
- **Centered card (from `bp-sm` up):** unchanged. ☰ closes the big player and opens
  the panel.
- **One scroller.** The list has no scroll area of its own in the sheet. It flows
  below the bottom row and the whole sheet scrolls; a scroller inside a scrolling
  sheet is hard to use on a phone, and iOS shows no indicator for it. The sheet is
  the drop list's scroll container (`CdkScrollable`), so a drag past the edge
  scrolls the sheet. A touch that starts inside the list never starts the
  swipe-to-close. The up/down buttons stay as the way to reorder without dragging.
  The bar's panel keeps its own `50dvh` scroller: it is a small panel, not a page.
- **One list component.** The rows move out of `AudioPlayerBarComponent` into
  `AudioPlaylistComponent` (`reader/shell/audio-player-bar/audio-playlist/`), which
  never scrolls itself; each host provides the scroller.
- **Remembered.** Whether the sheet shows the list is kept across openings, in
  `AudioSurface` (an `accountSignal`, so logout resets it), and handed to the big
  player as dialog data — the big player cannot inject `AudioSurface`, which imports it.
- **Which mode:** a `LayoutService.isPhone` signal on `(max-width: 559.98px)`, the
  same boundary as the stylesheet's `width < bp.$bp-sm`. Resizing across it switches
  ☰'s behaviour live.
