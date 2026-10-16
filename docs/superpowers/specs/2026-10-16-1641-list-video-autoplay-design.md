# List-view video autoplay (#1641)

## Goal

Videos in the reader list play on their own, muted, while they are on screen. A
per-device setting turns this off. Unmuting one video carries sound to the
following ones for the rest of the session.

## Scope

In: the list view's native `<video>` elements rendered by `PostMediaComponent`
(post media and quoted posts, including HLS) and the YouTube Short player in
`EntryMediaComponent`.

Out: the article view, and a Wi-Fi-only mode. `navigator.connection.type` exists
only in Chrome on Android, so on iPhone and every desktop browser a Wi-Fi-only
setting could never tell Wi-Fi from mobile data. It is deferred until a native
iOS client can read the network path. No backend change.

## The setting

`ListAutoplayService` (`core/preferences/`) holds `enabled: Signal<boolean>` and
`setEnabled(enabled)`.

- Stored through `UserDeviceStorage` under `listAutoplay` as `'on'` / `'off'`:
  per account, per device. A device chooses for itself (desktop on fibre, phone
  on a data plan), and logout's `forgetCurrentUser()` clears it.
- With nothing stored it is on, unless `prefers-reduced-motion: reduce` matches;
  then off. A stored choice always wins.
- Settings → Preferences gets a toggle row after reading focus:
  "Autoplay videos in the list" with the hint "Muted, one at a time. Saved on
  this device." (en + de).

## Choosing what plays

`ListAutoplayCoordinator` (`reader/media/autoplay/`) owns one
`IntersectionObserver` and the session's sound state. `EntryListComponent`
provides it, so only the list autoplays: `PostMediaComponent` also renders
quoted posts in the article view, and there it finds no coordinator and stays
click-to-play.

Players register and unregister themselves. A player is the coordinator's
interface, implemented once for a native video and once for a Short:

```ts
interface AutoplayPlayer {
  readonly element: Element;            // what visibility is measured on
  start(withSound: boolean): void;
  stop(): void;
}
```

Rules:

- While the setting is off the coordinator starts nothing. Turning it off stops
  whatever it started.
- The candidate is the registered player with the largest visible ratio, and at
  least 0.6. Only the candidate plays; when another player becomes the
  candidate, the current one is stopped first. No candidate stops the current
  one.
- A video the user starts by hand stops whichever list video is playing, so two
  never play together — with the setting off too. Hand-started playback
  otherwise keeps today's behaviour (pauses on scroll-away).
- While an entry is open (the article beside or over the list) autoplay is
  suspended, as `pauseMedia` already pauses the list's media then; closing the
  entry resumes it.
- Re-rendering a list unregisters removed players; a removed playing player is
  stopped.

## Native video

- `start(withSound)`: `muted = !withSound`, then `play()`. If `play()` rejects
  with sound, retry muted; if muted also rejects (iOS Low Power Mode, autoplay
  blocked), leave the poster. Never surface an error.
- HLS works unchanged: `armHlsStreams` already attaches hls.js on the first
  `play` event.
- `stop()`: `pause()`.
- Sound state: a `volumechange` on a registered video reports `!muted` to the
  coordinator, which sets "sound on" or "sound off". Changes the coordinator
  makes itself (the muted start) do not count, so autoplay does not reset the
  user's choice.
- Sound state lives in memory only and starts muted on each load and after
  logout.

## YouTube Short

- `start()` ignores sound and sets the playing state with the player URL carrying
  `autoplay=1`, `mute=1` and `playsinline=1` (`mutedShortPlayer`). A tap on the
  play button keeps today's unmuted URL. The frame is
  cross-origin: we can neither see an unmute inside it nor learn that an
  unmuted start was blocked (on iOS that is a frozen frame), so Shorts always
  start muted and neither set nor read the sound state.
- `stop()` returns to the thumbnail, as scrolling away does today. Scrolling
  back reloads the YouTube player; keeping several YouTube players alive costs
  more.
- With autoplay off, the existing click-to-play stays as it is.

## Error handling

Every autoplay attempt is best-effort: a rejected `play()` leaves the poster, a
missing `IntersectionObserver` (jsdom, old browsers) means nothing autoplays and
click-to-play is unaffected.

## Testing

Jest:

- `ListAutoplayService`: default on, default off under reduced motion, stored
  choice beats the media query, per-account key, cleared by
  `forgetCurrentUser()`.
- `ListAutoplayCoordinator` with a fake `IntersectionObserver`: most-visible
  wins at ≥ 0.6, handover stops the previous player, nothing starts while off,
  turning off stops the player, hand-started play stops the autoplaying one,
  sound state follows user `volumechange` but not the coordinator's own mute.
- Native video player: sound start falls back to muted on rejection; a muted
  rejection leaves the video paused.
- Short: `autoplayingShortPlayer` adds `mute=1`; the block registers and its
  `start`/`stop` toggle the playing state.
- Preferences section renders the toggle and writes through the service.

Playwright: one smoke on a stubbed list route (the spec owns its data) — a
visible post video is playing and muted; with the setting off it is not.
