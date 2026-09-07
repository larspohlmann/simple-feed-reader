# Podcast/audio player for entry attachments[] (#915) — design

**Status:** approved scope, pre-implementation (frontend only).
**Foundation:** #906 (PR #912) persists `attachments[]` (url, mimeType,
durationInSeconds, sizeInBytes, title) on the entry and sends them on the entry
API. `EntryAttachmentDto` already exists in `reader/models.ts`. No view plays
them yet.

## Scope decision

**Audio only.** An entry may declare a video enclosure, but the reader already
plays video inline through the media pipeline; the point of this ticket is
podcast/audio enclosures. The player picks the entry's first *audio* attachment
(mimeType `audio/*`, or an audio file extension when the feed omits the type).
Video attachments are left for a follow-up if a use case appears.

## The mechanism that makes playback survive navigation

`AudioPlayerService` owns **one `HTMLAudioElement` created in code** (`new
Audio()`), never placed in a template. A headless media element is not tied to
any component's lifecycle, so it keeps playing when `ReaderViewComponent`
unmounts, when the entry list changes, and across route changes. The visible
mini-player is only a *reflection* of the service's signals: it can unmount and
remount with no effect on playback.

This is the whole reason the player is a root service plus a dumb bar, not a
component that owns an `<audio>` tag.

## Components

### 1. `AudioPlayerService` (`reader/audio-player.service.ts`, root)

- Owns the headless element through an injected factory
  (`AUDIO_ELEMENT_FACTORY` token, default `() => new Audio()`) so tests supply a
  stub — jsdom does not implement `HTMLMediaElement.play`.
- Signals (read-only outward): `current` (the playing track: url, title,
  faviconUrl, imageUrl, seeded duration), `playing`, `position` (s), `duration`
  (s — seeded from `attachment.durationInSeconds`, corrected on `loadedmetadata`).
- Commands: `play(track)`, `toggle()`, `seek(seconds)` (clamped), `skip(delta)`,
  `stop()`.
- `navigator.mediaSession`: metadata (title, feed source, artwork from entry
  image when present) and action handlers (play/pause/seekbackward/seekforward/
  seekto); `setPositionState` on time updates. All guarded — the API is absent on
  some browsers and must never throw.
- **Persistence:** the current track plus position is written to `localStorage`
  (throttled — on pause, on `pagehide`, and at most every few seconds while
  playing, never on every `timeupdate`). On construct it rehydrates `current`
  **paused** at the saved position, so the bar reappears offering resume after a
  reload. Never autoplays (browsers block it anyway).
- **Reset on identity change:** on token clear it stops and clears persisted
  state, so one account's podcast never bleeds into the next session
  (singleton-service-state-survives-logout).

### 2. `AudioPlayerBarComponent` (`reader/audio-player-bar/`)

Presentational mini-player bound to the service. Renders only when
`service.current()` is set: favicon + title, play/pause, a scrubber (`<input
type=range>` over position/duration), current/total time, ±15s skip, close.
Lives in `reader-shell` pinned to the bottom (persistent within the reader). The
scrubber's max comes from the seeded duration, so it renders and is draggable
before the element's metadata loads (the ticket asks for this).

### 3. Reader-view "Listen" card

A compact card at the top of the reader body, shown when the entry has an audio
attachment: title + duration + a play button calling `service.play(...)`. Entry
enclosures are entry-level metadata, not body HTML, so this is a template block
in `reader-view`, driven by `entry.attachments`, not a body injection.

## Native-iOS viability

The bar and service are a pure web presentation over the already-structured
`attachments[]` JSON. The `<audio>` element and `mediaSession` are the browser
client's implementation of playback — exactly the layer a Swift client replaces
with `AVPlayer`. No new endpoint, no browser-only field on the contract.

## Testing (TDD)

- `AudioPlayerServiceTest`: play sets current/playing; toggle; seek clamps;
  duration seeded from the attachment then overwritten by `loadedmetadata`;
  persists and rehydrates paused on construct; mediaSession metadata set; stop
  and clear on token change. Stub element via the factory token.
- `AudioPlayerBarComponent`: renders current; play/pause toggles; scrubber
  reflects position and seeks on input; close stops.
- `reader-view`: renders the Listen card for an audio attachment, none for a
  video-only or empty entry, plays on click.
- `audio-attachment` selection helper: picks the first audio attachment by
  mime/extension; unit-tested in isolation.
- Full `npm run check` (Docker) green.
