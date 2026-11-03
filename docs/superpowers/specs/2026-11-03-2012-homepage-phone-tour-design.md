# Homepage phone tour video — design (#2012)

A short, silent screen recording of the app on a phone, shown on the project homepage
(`docs/homepage/index.html`) inside a CSS-drawn phone frame. Two recordings, one per theme;
the page shows the one that matches its own theme.

## Recording

- Script: `frontend/scripts/screenshots/tour.mjs`, run from `frontend/` with the same
  `.env.screenshots` file as the screenshots, one command for both themes:
  `node --env-file=.env.screenshots scripts/screenshots/tour.mjs [light|dark]` (default both).
  It borrows the podcasts itself through `podcast-setup.mjs` and returns them in a `finally`.
  `TOUR_OUT` overrides the output folder (default `docs/homepage`). The helpers it shares with
  `shots.mjs` (sign-in, passkey offer, airy-style stub) move into `lib.mjs` next to the
  scripts.
- Context: Playwright Chromium launched with `--force-device-scale-factor=2`, viewport 390×760,
  deviceScaleFactor 2, `isMobile`, `hasTouch`, `recordVideo` with `size: { width: 780, height:
  1520 }` at the default 25 fps. The launch flag is what makes the recorder deliver real 2x
  frames: without it, both the recorder and a CDP screencast emit 1x frames padded onto the
  2x canvas (verified 2026-11-03). `reducedMotion: 'no-preference'` so every app transition
  plays.
- The recording starts with the page, so sign-in happens in a first, unrecorded context whose
  storage state seeds the recording context. That first context also warms the reader (below).
  The tour page waits for the first cards and the fonts before the first scene starts; the
  encode trims to 0.4 s before scene 1.
- The dev server's live-reload socket is blocked (`page.routeWebSocket`): a file change
  elsewhere would otherwise blank the page mid-recording.
- Settings: `sfr.lang=en`, `sfr.readingFocus=false`, `sfr.layout=magazine`, the airy magazine
  style stubbed on `GET /api/me`, `sfr.theme` set to the version's starting theme.
- Touch feedback: an init script injects a fixed, pointer-events-none overlay. Before each tap
  a ripple (a 40 px circle, dark translucent fill with a white ring so it reads on both themes)
  scales up and fades at the tap point. A scroll is a CDP touch drag (start, eased moves over
  26 to 40 steps, end) with a dot following the finger; never one jump.
- Every tap picks the match whose centre is inside the viewport: a scrolled-away header keeps
  its buttons in the DOM ("Back to list" is then the floating button; the sidebar opener is
  "Toggle sidebar" in the header or "Show sidebar" floating), and so does the drawer while it
  sits beside the viewport (the drawer's state is read from `aside.sidebar`'s rect).
- Controls at the drawer's foot must sit at least 100 px from the top and 70 px from the bottom
  before a tap: a tap on a half-cut control at the edge is not read as a press. The drawer is
  swiped until they do.

### Reader warm-up

The reader scene must land on an article that renders a real body, not a fallback or a
toast. Before recording, the unrecorded context opens the first ten cards (skipping episodes,
which open show notes, and Polygon, whose extract starts with a share toast) and keeps the
titles whose reader shows a body of at least 1200 characters. The wait is for the reader's
heading to carry the tapped title and then for either `app-article-content` to hold that much
text or a fallback (`.reader-fallback`, `.reader-fallback-quiet`, `.body-error`) to appear:
`aria-busy` alone is not enough, because the article is briefly not busy before the new
entry's load starts, and the previous measurement read the title alone (208 characters for an
article measured at 31,866 in the run before). The reader cache keeps the warmed bodies, so the
recorded tap renders at once.

### Scenes

Roughly 40 s, in order. Each scene's start time is recorded and written to
`docs/homepage/tour-scenes.json` as `{ light: [{ name, label, copy, seconds }], dark: [...] }`.

1. **Magazine** (~5 s): hold, eased scroll down about two screens, hold. The borrowed podcast
   episode shows near the top; accepted.
2. **Reader** (~10 s): tap the first warmed title that is on screen (scrolling on if none is),
   wait for the body as above, hold, eased scroll down twice, tap "Back to list".
3. **Sidebar** (~5 s): open the drawer, hold, swipe it, tap the Podcasts tag, wait for the
   tag column and the closed drawer, hold.
4. **Podcast** (~10 s): tap "Play" on the first episode, wait for `app-audio-player-bar`,
   hold, tap "Open player", hold 4 s on `app-big-player`, tap "Minimise player", hold.
5. **Theme** (~8 s): tap "Close player" (the collapsed bar covers the drawer's foot), open the
   drawer, swipe until the theme buttons sit comfortably, tap "Dark" (light version) or
   "Light" (dark version) — the view controls with that title, read back through
   `aria-pressed`; "Darker" is the screen dimmer, not the theme — then close the drawer by
   tapping the scrim at (378, 420), since its own close button has scrolled away, and hold
   3 s in the new theme. The video ends there.

- Nothing is written to the demo account beyond the borrowed Podcasts tag, returned afterwards
  (the account is back at 19 feeds; `podcast-setup.mjs teardown` repairs an aborted run).
  Playback state and the theme live in the recording context only.
- Encoding with ffmpeg from the raw WebM, into `docs/homepage/`: `tour-light.mp4` /
  `tour-dark.mp4` (H.264, `-preset slow`, CRF 30, yuv420p, `-movflags +faststart`),
  `tour-light.webm` / `tour-dark.webm` (VP9, CRF 33, `-row-mt 1`), and
  `tour-light-poster.webp` / `tour-dark-poster.webp` (the first frame, `cwebp -q 85`). Budget:
  each MP4 under 4.5 MB; if an encode is larger, raise the CRF before anything else.
- The raw recordings stay under the system temp directory like the raw screenshots.
- Operational: the frontend container has been OOM-killed once during a run (exit 137);
  `docker compose up -d frontend` and wait for :4200.

## Homepage

- A new section `#tour`, "See it in action", between the hero and `#layouts`. Two columns on
  desktop (phone left, scene list right), stacked on a phone.
- The phone frame is CSS modelled on `frame.mjs`: dark bezel, rounded screen, dynamic island.
  The `<video>` is the screen: `width="780" height="1520"`, `muted playsinline
  preload="metadata" controls`, `poster`, two `<source>` elements (WebM first, MP4 second).
  One `<video>` per theme with the hero's `light-only` / `dark-only` classes; the hidden one
  has `preload="none"` so only the shown version loads metadata. No autoplay.
- The scene list is an `<ol>` with one `<li>` per scene (label plus one line of copy, from
  `tour-scenes.json` baked into the markup, not fetched). The page script listens to
  `timeupdate` on the visible video and sets `aria-current="true"` on the scene whose start
  time is the latest one not after `currentTime`; a tap on a scene seeks the video there and
  plays it. With no JS the list is still a readable caption list.
- The section's lede: one sentence, in the page's descriptive register.
- The JSON-LD `screenshot` list is unchanged; `video` is not added to the schema.

## Docs

- `frontend/scripts/screenshots/README.md`: a "Tour video" paragraph with the command.
- `docs/homepage/README.md`: a paragraph naming the video files and posters as an exception
  to "screenshots have one source", and that `tour.mjs` regenerates them.

## Out of scope

Audio, captions burned into the video, a desktop recording, autoplay, and showing the tour in
`docs/screenshots.md`.
