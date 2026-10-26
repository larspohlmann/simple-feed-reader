# Listen to a YouTube or SoundCloud embed in the audio player (#1815) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An article that embeds a landscape YouTube video or a SoundCloud track offers **Listen** and **Add to playlist** under the player. Listen sends the media to the reader's audio player, which plays its sound like a podcast episode: mini bar, big player, playlist, saved position, playback speed, lock-screen controls. The visible embed pauses when its sound moves to the player.

**Architecture:** The sound comes from the provider's own player, not from an extracted stream: production is Strato shared hosting (no Python, no yt-dlp, no proxying of 50 MB streams through PHP), and YouTube's bot detection breaks datacenter extraction anyway. So `AudioPlayerService` gets a second playback backend. Today it drives `HTMLAudioElement`s through `AudioDeck`; after this plan it drives a `PlaybackElement`, the slice of `HTMLMediaElement` it actually uses, and `HTMLAudioElement` satisfies that slice unchanged. Two adapters implement the same slice over a hidden provider iframe driven by `postMessage`, the channel `YouTubeChapterPlayer` already uses (#1808): `YouTubePlayback` (YouTube iframe API) and `SoundCloudPlayback` (SoundCloud Widget API). A track stays an ordinary `AudioTrack`; its url is the media's canonical URL, and the deck picks the backend from that url (`playbackKindOf`). Nothing above the deck changes, so #1810 (chapters on the track, previous/next chapter, scrub marks, Media Session title) applies to these tracks unchanged.

**Tech Stack:** Angular 20 (standalone, signals, OnPush), Jest (jsdom), Playwright, SCSS with theme tokens. No backend change.

**Spec:** GitHub issue #1815. Decisions taken there:

1. Hidden-iframe backend with its limitations accepted: the video is downloaded though not shown; iOS Safari pauses an embedded YouTube player when the screen locks or the tab leaves the foreground; the length is unknown until playback starts; a video whose owner disallows embedding (player errors 101/150) is skipped like a dead enclosure; SoundCloud's widget has no playback-rate control, the speed applies to the other tracks.
2. Scope: landscape YouTube videos and SoundCloud tracks, the two shapes the embed allow-list carries. Shorts are excluded. Vimeo and Dailymotion are further adapters later.
3. Every YouTube frame in an article loads with the iframe API flag (today only chapter pages do), so a playing embed can be paused when its sound moves to the player.

## Alignment with #1810 (chapters in the audio player)

The two plans are independent and may merge in either order. The seams they share, named the same way in `docs/superpowers/plans/2026-10-26-1810-chapters-in-the-player.md`:

- `AudioTrack` is the one shape both build. #1810 adds `chapters?: ChapterDto[]` to it; this plan adds nothing. `toEmbedTrack(entry, media, chapters = [])` takes the same trailing `chapters` argument `toAudioTrack` gets in #1810; whichever plan lands second adds the parameter to the other's builder and passes `source.chapters()` from the article view.
- The `entry` block of a track is built once, by `audioTrackEntry(entry)` in `audio-attachment.ts` (Task 1 here, Task 1 there); the second plan to land reuses it.
- #1810's `AudioPlayerService.describeChapters(url, chapters)` back-fills chapters onto a queued track. Once both are in, `ArticleBody` calls it for the chaptered player's url: the enclosure's, else the embed url of the frame `ArticleChapters` drives (`embedMediaOfFrame`, Task 1 here).
- Chapter steps and scrub marks in #1810 seek through `AudioPlayerService.seek`, which reaches `FramePlayback.currentTime`: immediate once started, kept as `startAt` before (Task 3 here). No provider-specific code is needed for chapters.
- `ArticleChapters.followTrack(track)` (Task 8 here) hands the article's chapter list to the audio player after Listen; with #1810 the big player shows the same list for the same track, from `track.chapters`.
- Media Session: #1810 puts the chapter title into the metadata the top frame sets; Chrome and Safari show the top frame's metadata for audio a child frame produces, so a video track's lock-screen title follows its chapters too.

## Global Constraints

- CLAUDE.md applies: Clean Code names, no boolean flag parameters, guard clauses, comments only where a reader would otherwise get the code wrong, one line each.
- Component styles in the sibling `.scss`; no hex colours there, no ad-hoc `px` (Stylelint `declaration-property-unit-allowed-list`). The hidden-frame stylesheet lives in `src/styles/`, where those rules are relaxed, but still uses tokens.
- Every provider frame this plan creates is built through `embedFrame(source, title)` (`media-embeds.ts`), so it carries `EMBED_FRAME_ATTRIBUTES` (`sandbox="allow-scripts allow-same-origin allow-presentation"`, `referrerpolicy`) and `allow="autoplay"` from its `EmbedSource`: a cross-origin player ignores a `playVideo` the page sends without that permission (reproduced in Brave, #1814). The hidden frame then overrides `loading` to `eager` (a lazily loaded frame that is never scrolled into view never loads).
- YouTube drops any command that reaches the embed before its API has sent its first message, and `initialDelivery` arrives before `onReady` (#1814, `docs/superpowers/plans/2026-10-26-chapter-progress.md`, Task 6). So an adapter counts the first message of any kind as ready, holds a play or seek asked for before that, and never posts to a frame before its `load` event (a frame that has not loaded logs a postMessage origin error).
- Only `https://www.youtube-nocookie.com` and `https://w.soundcloud.com` are ever posted to or accepted from; a message is accepted only when `event.origin` is the provider's and `event.source` is the adapter's own frame.
- The deck and the service never learn a provider's name: they see `PlaybackElement`s and `PlaybackKind`s.
- Frontend tests: `docker compose exec -T frontend npx jest <paths>` in the container; the gate is `cd frontend && npm run check` plus `npm run build` (template errors surface only there). Never two Jest processes at once in the container. Playwright smokes need the Docker stack and are outside the CI gate.
- Commits: `feat(#1815): …` / `refactor(#1815): …` / `test(#1815): …`, no attribution lines. Branch `feature/1815-listen-to-embeds`, or the branch the session was given. Merge `develop` in regularly.

## File Structure

| File | Responsibility |
|---|---|
| Create `frontend/src/app/reader/audio/playback/playback-element.ts` | `PlaybackElement`: the `HTMLMediaElement` slice the player drives; `PlaybackEvent`; `PlaybackSlot` (element + `dispose`) |
| Create `frontend/src/app/reader/audio/playback/playback-kind.ts` | `PlaybackKind` (`file` / `youtube` / `soundcloud`) and `playbackKindOf(url)` |
| Create `frontend/src/app/reader/audio/playback/frame-playback.ts` | `FramePlayback`: the abstract adapter over a hidden provider frame — state, events, seek-before-start, buffered ranges, failure |
| Create `frontend/src/app/reader/audio/playback/hidden-frame.ts` | `hiddenPlaybackFrame(url, title)` and `hiddenPlaybackHost()`: the detached frame built through `embedFrame`, and the one `document.body` container |
| Create `frontend/src/app/reader/audio/playback/youtube-playback.ts` | `YouTubePlayback extends FramePlayback`: the iframe API protocol |
| Create `frontend/src/app/reader/audio/playback/soundcloud-playback.ts` | `SoundCloudPlayback extends FramePlayback`: the Widget API protocol |
| Create `frontend/src/app/reader/audio/playback/playback-slots.ts` | `audioSlot(element)`, `frameSlot(playback)`, `PlaybackSlotFactory` |
| Create `frontend/src/app/reader/audio/embed-media.ts` | `EmbedMedia` (kind, canonical url, poster) read from a provider frame or built from a video id; `youTubeVideoId`, `youTubeWatchUrl`, `youTubePosterUrl` |
| Create `frontend/src/app/reader/audio/embed-track.ts` | `toEmbedTrack(entry, media)`: the `AudioTrack` of an embed, mirroring `toAudioTrack` |
| Modify `frontend/src/app/reader/audio/audio-deck.ts` | Two slots that take the element kind from the url, disposing and recreating a slot when the kind changes |
| Modify `frontend/src/app/reader/audio-player.service.ts` | `FRAME_PLAYBACK_FACTORY` token; `PlaybackElement` types; otherwise unchanged |
| Create `frontend/src/styles/_hidden-playback.scss`, modify `frontend/src/styles.scss` | The hidden frame container: fixed, invisible, inert, never `display: none` |
| Create `frontend/src/app/reader/media/embed-frame-commands.ts` | `pauseEmbedFrame(frame)`: pauses a visible YouTube API frame or SoundCloud widget |
| Modify `frontend/src/app/reader/article/decorators/reader-cinema.ts` | The bar under a player becomes the shared `.reader-player-bar`; `isOffered` reads the toggle's own display |
| Create `frontend/src/app/reader/article/decorators/reader-listen.ts` | `addEmbedListenControls(host, labels, actions)`: Listen and queue buttons in the player bar of every supported embed |
| Create `frontend/src/app/reader/article/reader-view/article-embed-audio.ts` | Wires the controls to `AudioPlayerService` for the open entry, keeps the queue state live, pauses the visible embed |
| Modify `frontend/src/app/reader/article/reader-view/article-chapters.ts` | `followTrack(track)`: the chapter list drives the audio player once the video is its current track |
| Modify `frontend/src/app/reader/article/reader-view/article-body.service.ts` | `entry` in `ArticleBodyParts`; API flag on every YouTube frame; the embed-audio wiring per render |
| Modify `frontend/src/app/reader/article/article-content/article-content.component.ts`, `reader-view.component.html` | `entry` input, passed from the reader view |
| Modify `frontend/src/app/reader/article/article-content/article-content.component.scss` | Player bar always shown; cinema toggle alone hides below the breakpoint; listen buttons |
| Modify `frontend/public/i18n/en.json`, `de.json` | `reader.audioPlayer.listenEmbed` |
| Modify `frontend/src/app/reader/article/decorators/decorate-article.ts` | Runs `addEmbedListenControls` after `addCinemaToggles` |
| Modify `docs/design-language.md` | §3 Conventions: the player bar under embeds |
| Create `frontend/e2e/listen-to-embed.spec.ts` | Smoke: Listen under a stubbed YouTube embed fills the player bar |

Specs beside every created or changed `.ts` file.

---

### Task 1: Embed media and its track

**Files:**
- Create: `frontend/src/app/reader/audio/playback/playback-kind.ts`
- Create: `frontend/src/app/reader/audio/embed-media.ts`
- Create: `frontend/src/app/reader/audio/embed-track.ts`
- Test: `frontend/src/app/reader/audio/playback/playback-kind.spec.ts`, `frontend/src/app/reader/audio/embed-media.spec.ts`, `frontend/src/app/reader/audio/embed-track.spec.ts`

**Interfaces:**

```ts
// playback-kind.ts
export type PlaybackKind = 'file' | 'youtube' | 'soundcloud';
export function playbackKindOf(url: string): PlaybackKind;

// embed-media.ts
export interface EmbedMedia {
  readonly kind: Exclude<PlaybackKind, 'file'>;
  /** The track's identity in the playlist and the url the deck picks the backend from. */
  readonly url: string;
  readonly posterUrl: string | null;
}
export function youTubeVideoId(url: string): string | null;   // watch?v=, youtu.be/, nocookie embed/<id> (a `#shorts` fragment is not a video)
export function youTubeWatchUrl(videoId: string): string;     // https://www.youtube.com/watch?v=<id>
export function youTubePosterUrl(videoId: string): string;    // https://i.ytimg.com/vi/<id>/hqdefault.jpg
export function isSoundCloudPlayerUrl(url: string): boolean;  // the allow-listed widget url, query and all
/** The media a player frame in the article body shows, or null for a frame this feature does not take. */
export function embedMediaOfFrame(frame: HTMLIFrameElement): EmbedMedia | null;

// embed-track.ts
export function toEmbedTrack(entry: EntryDto, media: EmbedMedia): AudioTrack;
```

- `playbackKindOf`: `youTubeVideoId(url)` → `'youtube'`; `isSoundCloudPlayerUrl(url)` → `'soundcloud'`; else `'file'`. It must accept a stored playlist url from any earlier version, so a plain `.mp3` url and an unknown url are both `'file'`.
- `embedMediaOfFrame` reads `frame.getAttribute('src')`, strips the `?enablejsapi…` suffix `withPlayerApi` appends, and answers `{ kind: 'youtube', url: youTubeWatchUrl(id), posterUrl: youTubePosterUrl(id) }` for a nocookie embed or `{ kind: 'soundcloud', url, posterUrl: null }` for a widget. The SoundCloud url is the widget url exactly as allow-listed (`isAllowedEmbed` from `media-embeds.ts` validates it; do not copy the pattern).
- `toEmbedTrack`: `url: media.url`, `title: entryDisplayTitle(entry)`, `faviconUrl: entry.faviconUrl`, `imageUrl: media.posterUrl ?? entry.imageUrl`, `durationInSeconds: null`, `entry: { id, title, feedTitle: entry.poster?.name ?? entry.source, publishedAt: entry.publishedAt ?? entry.createdAt, excerpt }`. Extract the `entry` block `toAudioTrack` builds into a shared `audioTrackEntry(entry)` in `audio-attachment.ts` rather than copying it.

- [ ] **Step 1: Write the failing specs** — `playbackKindOf` for a watch url, a `youtu.be` share link, a nocookie embed with and without the API suffix, a `#shorts` embed (`file`, by design), a widget url, an `.mp3`, an empty string. `embedMediaOfFrame` for a YouTube frame with the API suffix, a SoundCloud frame, a Vimeo frame (null), a frame without `src` (null). `toEmbedTrack` with the poster falling back to the entry image and the entry block equal to `toAudioTrack`'s.
- [ ] **Step 2: Run them to verify they fail** — `docker compose exec -T frontend npx jest src/app/reader/audio`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run the specs green; run `npm run check:static`**
- [ ] **Step 5: Commit** — `feat(#1815): the track an embedded video or sound becomes in the player`

---

### Task 2: `PlaybackElement`, slots, and a deck that picks the element by kind

**Files:**
- Create: `frontend/src/app/reader/audio/playback/playback-element.ts`
- Create: `frontend/src/app/reader/audio/playback/playback-slots.ts`
- Modify: `frontend/src/app/reader/audio/audio-deck.ts`
- Modify: `frontend/src/app/reader/audio-player.service.ts` (types and the factory only)
- Test: `frontend/src/app/reader/audio/audio-deck.spec.ts` (new), `frontend/src/app/reader/audio-player.service.spec.ts` (unchanged, must stay green)

**Interfaces:**

```ts
// playback-element.ts
export type PlaybackEvent =
  | 'progress' | 'timeupdate' | 'loadedmetadata' | 'durationchange'
  | 'play' | 'pause' | 'ended' | 'error';

/** The slice of HTMLMediaElement the player drives; HTMLAudioElement satisfies it as is. */
export interface PlaybackElement {
  src: string;
  preload: '' | 'none' | 'metadata' | 'auto';
  currentTime: number;
  readonly duration: number;
  readonly buffered: TimeRanges;
  readonly readyState: number;
  readonly error: MediaError | null;
  defaultPlaybackRate: number;
  playbackRate: number;
  play(): Promise<void>;
  pause(): void;
  load(): void;
  addEventListener(type: PlaybackEvent, listener: () => void): void;
}

/** One element the deck can hold, with the way to let go of it. */
export interface PlaybackSlot {
  readonly element: PlaybackElement;
  dispose(): void;
}

// playback-slots.ts
export type PlaybackSlotFactory = (kind: PlaybackKind) => PlaybackSlot;
export function audioSlot(element: HTMLAudioElement): PlaybackSlot;   // dispose: pause, removeAttribute('src'), load()
export function frameSlot(playback: FramePlayback): PlaybackSlot;      // dispose: playback.dispose()
```

The deck keeps its public surface (`element`, `load`, `cacheNearTheEnd`, `setRate`, `release`) and its two-slot swap. It takes a `PlaybackSlotFactory` instead of an element factory and remembers the kind and url of each slot:

```ts
interface HeldSlot extends PlaybackSlot { readonly kind: PlaybackKind; url: string | null; }

export class AudioDeck {
  private playing: HeldSlot;
  private standby: HeldSlot;
  private rate = 1;

  constructor(
    private readonly createSlot: PlaybackSlotFactory,
    private readonly bind: (element: PlaybackElement, isPlaying: () => boolean) => void,
  ) {
    this.playing = this.hold('file');
    this.standby = this.hold('file');
  }

  get element(): PlaybackElement { return this.playing.element; }

  load(url: string): boolean {
    const outgoingUrl = this.playing.url;
    if (this.standby.url !== url) {
      this.playing = this.fitted(this.playing, url);
      this.playing.url = url;
      this.playing.element.src = url;
      return false;
    }
    const outgoing = this.playing;
    this.playing = this.standby;
    this.standby = outgoing;
    this.standby.url = outgoingUrl;
    outgoing.element.pause();
    return true;
  }

  cacheNearTheEnd(url: string, duration: number, buffered: number): void {
    if (url === this.standby.url || duration <= 0) return;
    const remaining = duration - this.playing.element.currentTime;
    if (buffered < duration - 1 && remaining > PRECACHE_LEAD_SECONDS) return;
    this.standby = this.fitted(this.standby, url);
    this.standby.url = url;
    this.standby.element.src = url;
  }

  setRate(rate: number): void {
    this.rate = rate;
    for (const slot of [this.playing, this.standby]) this.applyRate(slot.element);
  }

  release(): void {
    this.playing.url = null;
    this.standby.dispose();
    this.standby = this.hold('file');
  }

  /** The slot itself when it plays `url`'s kind of media, else a fresh one of that kind in its place. */
  private fitted(slot: HeldSlot, url: string): HeldSlot { … }
  private hold(kind: PlaybackKind): HeldSlot { … preload = 'auto', bind, applyRate … }
  private applyRate(element: PlaybackElement): void { element.defaultPlaybackRate = this.rate; element.playbackRate = this.rate; }
}
```

Note the `bind` closure compares elements, not slots: `this.bind(element, () => element === this.playing.element)`.

In the service, `AUDIO_ELEMENT_FACTORY` stays as it is (the specs provide it) and a second token joins it:

```ts
/** Injected so tests supply a stub: a provider frame cannot play in jsdom. */
export const FRAME_PLAYBACK_FACTORY = new InjectionToken<(kind: Exclude<PlaybackKind, 'file'>) => FramePlayback>(
  'FRAME_PLAYBACK_FACTORY',
  { providedIn: 'root', factory: () => (kind) => (kind === 'youtube' ? new YouTubePlayback(hiddenPlaybackHost()) : new SoundCloudPlayback(hiddenPlaybackHost())) },
);
```

and the deck is built with `(kind) => kind === 'file' ? audioSlot(createAudio()) : frameSlot(createFrame(kind))`. `bindElement` and `get element()` are typed `PlaybackElement`; nothing else in the service changes. (`HTMLMediaElement.HAVE_METADATA` and `error.MEDIA_ERR_SRC_NOT_SUPPORTED` stay: they are the shared vocabulary the adapters speak too.)

- [ ] **Step 1: Write `audio-deck.spec.ts`** with a recording slot factory: the deck starts with two `file` slots; loading a `youtube` url disposes the playing `file` slot and holds a `youtube` one in its place, bound and at the current rate; the standby keeps its kind; caching a `soundcloud` url next replaces the standby's kind; swapping into a cached frame slot works like today; `release` disposes the standby and holds a fresh `file` slot; `setRate` reaches a slot created afterwards.
- [ ] **Step 2: Run it to verify it fails**
- [ ] **Step 3: Implement** the three new files and the deck; adjust the service's construction and types.
- [ ] **Step 4: Run `src/app/reader/audio` and `src/app/reader/audio-player.service.spec.ts` green** — every existing service spec passes without edits (the `FakeAudio` stub already matches the slice).
- [ ] **Step 5: Commit** — `refactor(#1815): the deck holds playback elements picked by the track's kind`

---

### Task 3: The hidden frame and `FramePlayback`

**Files:**
- Create: `frontend/src/app/reader/audio/playback/hidden-frame.ts`
- Create: `frontend/src/app/reader/audio/playback/frame-playback.ts`
- Create: `frontend/src/styles/_hidden-playback.scss`; modify `frontend/src/styles.scss`
- Test: `frontend/src/app/reader/audio/playback/hidden-frame.spec.ts`, `frame-playback.spec.ts` (through a minimal test subclass)

**`hidden-frame.ts`:**

```ts
/** The one container for provider frames the player drives, created on first use. */
export function hiddenPlaybackHost(): HTMLElement;   // <div class="hidden-playback" aria-hidden="true"> on document.body

/** A provider frame, still detached (iOS honours referrerpolicy only on a frame whose src precedes insertion, #1594). */
export function hiddenPlaybackFrame(url: string, title: string): HTMLIFrameElement;
// embedFrame({ url, allow: 'autoplay' }, title), then loading="eager" and tabindex="-1".
```

Because `embedFrame` wants the url before insertion, `FramePlayback` creates its frame on the first `src` it is given and replaces it on a later one, instead of holding an empty frame from construction.

Stylesheet (`src/styles/_hidden-playback.scss`): fixed at the bottom end corner, `width: 16rem; aspect-ratio: 16 / 9`, `opacity: 0`, `pointer-events: none`, `z-index: -1`, `overflow: hidden`; the frame fills it, `border: 0`. Never `display: none` or a zero size: browsers throttle or refuse media in a frame that does not render.

**`frame-playback.ts`:**

```ts
/** A player frame the audio player drives as if it were an audio element (#1815). */
export abstract class FramePlayback implements PlaybackElement {
  preload: PlaybackElement['preload'] = 'auto';
  defaultPlaybackRate = 1;
  protected frame: HTMLIFrameElement | null = null;
  private readonly listeners = new Map<PlaybackEvent, (() => void)[]>();
  private source = '';
  private time = 0;
  private length = 0;
  private loadedFraction = 0;
  private rate = 1;
  private ready = false;
  private started = false;
  private paused = true;
  private failed = false;
  private pendingPlay = false;
  private startAt: number | null = null;

  constructor(private readonly host: HTMLElement, private readonly origin: string, private readonly title: string) {
    window.addEventListener('message', this.onMessage);
  }
  // `set src` builds the frame with hiddenPlaybackFrame(frameUrlFor(url), title), listens for its `load`, appends it to `host`;
  // a frame already held is removed first. `frame` is null until then.

  // PlaybackElement — the same contract as the element the service already handles:
  get src(): string;                      set src(url: string);     // resets state, loads frameUrlFor(url)
  get currentTime(): number;              set currentTime(seconds)  // seeks once started, else remembers startAt
  get duration(): number;                 // NaN until known, like the element
  get buffered(): TimeRanges;             // one range [0, loadedFraction * length], none until known
  get readyState(): number;               // HAVE_METADATA once the length is known, else HAVE_NOTHING
  get error(): MediaError | null;         // UNPLAYABLE (code MEDIA_ERR_SRC_NOT_SUPPORTED) after a provider error
  get playbackRate(); set playbackRate(rate);   // applies once ready; re-applied on every ready (a fresh frame starts at 1)
  play(): Promise<void>;                  // marks playing and emits 'play' at once, like the element; starts when ready
  pause(): void;
  load(): void;                           // reloads the frame at the same source, clearing the error
  addEventListener(type, listener): void;
  dispose(): void;                        // drops the window listener and the frame

  // What a provider implements:
  protected abstract frameUrlFor(url: string): string;
  protected abstract onFrameLoaded(): void;                 // YouTube: send `listening`; SoundCloud: nothing, `ready` arrives on its own
  protected abstract isReadyMessage(data: unknown): boolean; // YouTube: any parsable widget message (initialDelivery precedes onReady, #1814); SoundCloud: `ready`
  protected abstract readMessage(data: unknown): void;      // parse; call the report* methods
  protected abstract start(seconds: number | null): void;   // play, from `seconds` when given
  protected abstract stop(): void;
  protected abstract seekTo(seconds: number): void;
  protected abstract applyRate(rate: number): void;

  // What a provider reports:
  protected post(message: object): void;    // JSON to the frame at `origin`
  protected becameReady(): void;            // called by the base on the first ready message; applies the rate; starts a pending play from startAt
  protected reportTime(seconds: number): void;
  protected reportDuration(seconds: number): void;   // emits loadedmetadata + durationchange on a change
  protected reportLoaded(fraction: number): void;    // emits progress
  protected reportPlaying(): void;          // sets started; emits play on a transition
  protected reportPaused(): void;
  protected reportEnded(): void;            // pause, then ended
  protected reportFailure(): void;          // failed; emits error
}
```

The `message` listener accepts only `event.origin === this.origin && event.source === this.frame.contentWindow`. `UNPLAYABLE` is a frozen object satisfying `MediaError` with `code: 4`, so `sourceIsDead()` in the service skips the track the way it skips an expired enclosure.

Why `startAt`: YouTube's `seekTo` on a cued video starts it, and the service sets `currentTime = 0` right after every load and seeks a restored position once metadata arrives. A seek before `started` is therefore kept and handed to `start()`; the first `reportPlaying` makes later seeks immediate. It is the same hold `YouTubeChapterPlayer.heldStart` keeps for a chapter tapped before the player is ready (#1814), generalised to both providers. `post()` is a no-op while no frame is held or loaded.

- [ ] **Step 1: Write the specs** with a `TestPlayback` subclass that records `post` calls and exposes the report methods. Cover: the frame carries the security attributes, `allow="autoplay"` and `loading="eager"`; messages from another origin or another window are ignored; `play` before ready emits `play` and starts once `becameReady`; a seek before start is applied through `start(seconds)`, after start through `seekTo`; `reportDuration` flips `readyState` and emits both metadata events once; `reportEnded` emits `pause` then `ended`; `reportFailure` yields an error with `code === MEDIA_ERR_SRC_NOT_SUPPORTED` and `load()` clears it; `dispose` removes the frame and stops listening. Send messages with `window.dispatchEvent(new MessageEvent('message', { data, origin, source: frame.contentWindow }))` — see `youtube-chapter-player.spec.ts` for the pattern.
- [ ] **Step 2: Run them to verify they fail**
- [ ] **Step 3: Implement**, add the stylesheet and its `@use` in `styles.scss`.
- [ ] **Step 4: Specs green; `npm run stylelint`**
- [ ] **Step 5: Commit** — `feat(#1815): a hidden provider frame the audio player drives like an element`

---

### Task 4: `YouTubePlayback`

**Files:**
- Create: `frontend/src/app/reader/audio/playback/youtube-playback.ts`
- Test: `frontend/src/app/reader/audio/playback/youtube-playback.spec.ts`

Protocol (the one `YouTubeChapterPlayer` speaks, widened):

- Frame url: `https://www.youtube-nocookie.com/embed/<id>?enablejsapi=1&origin=<encoded page origin>&playsinline=1&controls=0`, the id from `youTubeVideoId(url)`. Origin posted to and accepted from: `https://www.youtube-nocookie.com`.
- On frame load: `{ event: 'listening', id: 1, channel: 'widget' }`, and once more when `play()` is asked before the first message arrived, the prompt `YouTubeChapterPlayer` sends for a held tap (a paused player may otherwise send nothing).
- Ready: the first message the frame sends, whatever its `event`; YouTube's `initialDelivery` precedes `onReady`, and a command sent before either is dropped (#1814).
- Commands: `{ event: 'command', func, args, id: 1, channel: 'widget' }` with `playVideo`, `pauseVideo`, `seekTo` (`[seconds, true]`), `setPlaybackRate` (`[rate]`). `start(seconds)`: `seekTo` when `seconds` is given and greater than zero, then `playVideo`.
- Messages (JSON strings): `infoDelivery` / `initialDelivery` with `info.currentTime` → `reportTime`, `info.duration > 0` → `reportDuration`, `info.videoLoadedFraction` → `reportLoaded`, `info.playerState` → as below; `onStateChange` with `info` as the state; `onError` → `reportFailure`.
- Player states: `1` playing → `reportPlaying`; `2` paused → `reportPaused`; `0` ended → `reportEnded`; `3` buffering, `5` cued, `-1` unstarted → nothing.
- `applyRate`: `setPlaybackRate`; the app's rates (1, 1.25, 1.5, 2) are all ones YouTube offers.

- [ ] **Step 1: Write the spec**: the frame url for a watch url and for a `youtu.be` link; `listening` on load; a `play` before ready posts `listening` again and becomes `playVideo` after the first message, be it `initialDelivery` or `onReady`; a restored position becomes `seekTo` then `playVideo`; `infoDelivery` drives time, duration, loaded fraction and the playing state; `onStateChange` 2 and 0; `onError` 150 marks the element dead; the rate is sent on ready and on change; nothing is accepted from `https://www.youtube.com`.
- [ ] **Step 2: Run it to verify it fails**
- [ ] **Step 3: Implement** (reuse `currentTimeOf`'s parsing approach: a tolerant `JSON.parse` of a string, every field read defensively).
- [ ] **Step 4: Spec green**
- [ ] **Step 5: Commit** — `feat(#1815): play a YouTube video's sound through the hidden player frame`

---

### Task 5: `SoundCloudPlayback`

**Files:**
- Create: `frontend/src/app/reader/audio/playback/soundcloud-playback.ts`
- Test: `frontend/src/app/reader/audio/playback/soundcloud-playback.spec.ts`

Protocol, verified against `https://w.soundcloud.com/player/api.js` (the wrapper is a thin `postMessage` shim):

- Frame url: the widget url as stored, plus `&auto_play=false&show_artwork=false&visual=false` (the widget reads `url` first and ignores what it does not know). Origin: `https://w.soundcloud.com`.
- Every message both ways is `JSON.stringify({ method, value })`. The widget posts `{ method: 'ready' }` on its own after load; no handshake. Other events arrive only once subscribed, one `{ method: 'addEventListener', value: '<event>' }` each.
- On `ready`: subscribe to `play`, `pause`, `finish`, `playProgress`, `loadProgress`, `error`; post `{ method: 'getDuration' }`; `becameReady`.
- Replies and events: `{ method: 'getDuration', value: <ms> }` → `reportDuration(ms / 1000)`; `playProgress` `value.currentPosition` (ms) → `reportTime`; `loadProgress` `value.loadedProgress` (0–1) → `reportLoaded`; `play` → `reportPlaying`; `pause` → `reportPaused`; `finish` → `reportEnded`; `error` → `reportFailure`.
- Commands: `{ method: 'play' }`, `{ method: 'pause' }`, `{ method: 'seekTo', value: <ms> }`. `start(seconds)`: `play`, and a `seekTo` on the first `play` event when `seconds` is given (the widget seeks reliably only once the sound is loaded).
- `applyRate`: nothing; the widget has no rate control.

- [ ] **Step 1: Write the spec** mirroring Task 4's, plus: the subscriptions and `getDuration` go out on `ready`; a position in milliseconds reads back in seconds; a start position is sent after the first `play` event.
- [ ] **Step 2: Run it to verify it fails**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Spec green**
- [ ] **Step 5: Commit** — `feat(#1815): play a SoundCloud track through the hidden widget frame`

---

### Task 6: The player plays an embed track end to end

**Files:**
- Modify: `frontend/src/app/reader/audio-player.service.ts` (the factory composition from Task 2 lands here if not yet)
- Test: `frontend/src/app/reader/audio-player.service.spec.ts`

- [ ] **Step 1: Add specs** that provide a `FRAME_PLAYBACK_FACTORY` stub returning a recording fake with the `PlaybackElement` surface: playing a watch url holds a `youtube` playback and calls `play` on it; a restored playlist whose current track is a watch url restores paused with its position; a dead video (`error.code === 4` after the fake fires `error`) is skipped to the next track; `cycleRate` reaches the frame playback; a `.mp3` queued after a video pre-caches on a `file` standby.
- [ ] **Step 2: Run, fix, green** — the whole `src/app/reader` suite.
- [ ] **Step 3: Commit** — `test(#1815): the player drives an embed track like an enclosure`

---

### Task 7: The player bar under an embed: Listen and Add to playlist

**Files:**
- Modify: `frontend/src/app/reader/article/decorators/reader-cinema.ts`, `.spec.ts`
- Create: `frontend/src/app/reader/article/decorators/reader-listen.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/article/decorators/decorate-article.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/article/article-content/article-content.component.scss`
- Modify: `frontend/public/i18n/en.json`, `de.json`

**The bar.** `.reader-cinema__bar` becomes `.reader-player-bar`, a bar either decorator creates under a player and both fill: `addCinemaToggles` puts its toggle there (landscape video, as today); `addEmbedListenControls` puts Listen and the queue toggle at its start, creating the bar after a SoundCloud box that has none. Grep `reader-cinema__bar` across `src`, `e2e` and `docs` and rename every use. The bar shows at every width now; below `bp.$container-reader-cinema` only the cinema toggle hides, so `isOffered` in `reader-cinema.ts` reads `getComputedStyle(toggle).display`, and the spec "does nothing where cinema is not offered" hides the toggles instead of the bars.

**Interfaces:**

```ts
// reader-listen.ts
export interface ListenLabels {
  listen: string;          // reader.audioPlayer.listen
  listenEmbed: string;     // reader.audioPlayer.listenEmbed — the buttons' title
  addToPlaylist: string;   // reader.audioPlayer.addToPlaylist
  inPlaylist: string;      // reader.audioPlayer.inPlaylist
}
export interface ListenActions {
  listen(media: EmbedMedia, frame: HTMLIFrameElement): void;
  toggleQueued(media: EmbedMedia): void;
}
/** One embed's queue toggle, for whoever keeps its state current. */
export interface EmbedListenControl {
  readonly media: EmbedMedia;
  showQueued(queued: boolean): void;   // icon playlist_add / playlist_add_check, label, aria-pressed
}
/** Listen and queue controls in the player bar of every supported embed in `host`; idempotent. */
export function addEmbedListenControls(host: HTMLElement, labels: ListenLabels, actions: ListenActions): EmbedListenControl[];
```

Supported embeds: every `.reader-embed > iframe` for which `embedMediaOfFrame(frame)` is not null. Idempotent through a `.reader-listen` marker on the bar. Buttons: `button.reader-listen__button` with the `material-symbols-outlined` span and a label span, like the cinema toggle; the listen button's icon is `play_circle`.

**Styles** (`article-content.component.scss`): the bar `display: flex; gap: var(--space-2); align-items: center; margin-top: var(--space-1)`; the cinema toggle `margin-inline-start: auto` and `display: none` outside the container query that today shows the bar; the listen buttons share the toggle's look (move the toggle's rules to `:is(.reader-cinema__toggle, .reader-listen__button)`); a pressed queue button takes `--rim-fill: var(--accent-soft)` like the listen row's.

**i18n:** `reader.audioPlayer.listenEmbed`: en "Play the sound in the reader's audio player", de "Den Ton im Audio-Player der App abspielen".

- [ ] **Step 1: Write the specs**: YouTube landscape gets the two buttons before the cinema toggle in the same bar; a SoundCloud box gets a bar of its own; a Short, a Vimeo frame and a native video get none; clicks reach `listen` with the media and the frame and `toggleQueued` with the media; `showQueued` flips icon, label and `aria-pressed`; idempotent. Adjust the cinema and decorate-article specs for the bar class.
- [ ] **Step 2: Run to verify they fail**
- [ ] **Step 3: Implement**, run `addEmbedListenControls` right after `addCinemaToggles` in `decorateArticle` (its labels and actions arrive through `ArticleDecorations`).
- [ ] **Step 4: Specs green; `npm run stylelint`; translation-key spec (`src/testing/translation-keys.ts`) green**
- [ ] **Step 5: Commit** — `feat(#1815): Listen and Add to playlist under an embedded video or sound`

---

### Task 8: Wiring in the article view

**Files:**
- Create: `frontend/src/app/reader/media/embed-frame-commands.ts`, `.spec.ts`
- Create: `frontend/src/app/reader/article/reader-view/article-embed-audio.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/article-chapters.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/article-body.service.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/article/article-content/article-content.component.ts`, `reader-view.component.html`

**`embed-frame-commands.ts`:** `pauseEmbedFrame(frame: HTMLIFrameElement): void` posts `pauseVideo` (widget protocol, origin `https://www.youtube-nocookie.com`) to a YouTube API frame and `{ method: 'pause' }` to a SoundCloud widget; anything else is left alone. A YouTube frame that has not yet answered drops the command (#1814), which is fine: such a frame is not playing. `YouTubeChapterPlayer` keeps its own posting; a shared `postToYouTubeFrame` helper is welcome if it falls out naturally, not required.

**`article-embed-audio.ts`:**

```ts
/** One rendered body's embed controls, wired to the player for the open entry (#1815). */
export class ArticleEmbedAudio {
  constructor(private readonly audio: AudioPlayerService, private readonly injector: Injector) {}

  /** Decorates `host` for `entry`; `onListened` learns the track that went to the player. */
  attach(host: HTMLElement, entry: EntryDto | null, labels: ListenLabels, onListened: (track: AudioTrack) => void): void;
  release(): void;   // destroys the queue-state effect
}
```

`listen(media, frame)`: `pauseEmbedFrame(frame)`, `audio.play(toEmbedTrack(entry, media))`, `onListened(track)`. `toggleQueued`: `dequeue(url)` when queued, else `enqueue(track)`. An `effect` (with the injector) calls `showQueued(audio.isQueued(control.media.url))` on every control; `isQueued` reads the playlist signal, so the effect follows the queue.

**Chapters follow the player (#1810 hook):** `ArticleChapters.followTrack(track: AudioTrack): void` replaces the current chapter player with an `AudioChapterPlayer` for `track` and re-adds the list at the same place (`addChapterList` already replaces an earlier list). `AudioChapterPlayer` already reports `PlaybackPosition` with the player's duration (#1814), so the list's row progress and time left keep working on the audio player. `ArticleBody` passes `(track) => this.chapters.followTrack(track)` as `onListened`, so once the video is the player's current track its chapter list seeks the player, not the paused visible frame.

**`ArticleBody`:** `ArticleBodyParts.entry: () => EntryDto | null`; every render passes `embedSource: (url) => withPlayerApi(url, window.location.origin)` (decision 3; the chapters condition goes), so every landscape YouTube frame in an article loads with the API suffix and `allow="autoplay"` (`withPlayerApi` returns an `EmbedSource` since #1814; `media-embeds.spec.ts` keeps pinning that `upgradeMediaEmbeds` without a source mapper grants no autoplay, #748). It also passes the listen labels and actions into `decorateArticle`, and attaches `ArticleEmbedAudio` beside `ArticleChapters` inside `runOutsideAngular`; `release` both on cleanup and destroy. `ArticleContentComponent` gains `entry = input<EntryDto | null>(null)`; the reader view passes `[entry]="e"`.

- [ ] **Step 1: Write the specs**: `pauseEmbedFrame` posts to each provider frame and to nothing else; `ArticleEmbedAudio` plays the track built from the entry, pauses the frame, reports the track, toggles the queue, and its controls follow `isQueued` (use `StubAudioPlayer` from `src/testing/stub-audio-player.ts`, extended with a `tracks` signal if `isQueued` needs it); `ArticleChapters.followTrack` swaps a video list onto the audio player; `ArticleBody` passes `withPlayerApi` as the embed source on every render, chapters or not (adjust `article-body.service.spec.ts`, which today expects the API suffix only with chapters).
- [ ] **Step 2: Run to verify they fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: `npm run check` and `npm run build` green**
- [ ] **Step 5: Commit** — `feat(#1815): send an embedded video or sound to the audio player from the article`

---

### Task 9: Docs and a Playwright smoke

**Files:**
- Modify: `docs/design-language.md` (§3 Conventions)
- Create: `frontend/e2e/listen-to-embed.spec.ts`

- [ ] **Step 1: Design language** — a short "Player bar under embeds" entry: the bar under a landscape video or a SoundCloud box, what goes in it (Listen, queue toggle, cinema toggle), that it shows at every width while the cinema toggle hides below `$container-reader-cinema`, and that the hidden playback frame is the one sanctioned invisible iframe (`src/styles/_hidden-playback.scss`), never `display: none`.
- [ ] **Step 2: Smoke** — modelled on `e2e/chapters.spec.ts`: stub the feed, the entry detail with a nocookie link body, and `https://www.youtube-nocookie.com/**` with a stub page; open the article; expect the Listen button in the player bar; click it; expect the audio player bar to show the entry's title and the queue toggle to read "In playlist". The stub frame answers no messages, so assert on the bar, not on playback.
- [ ] **Step 3: Run it** against the Docker stack (`npm run e2e -- listen-to-embed`); it is not in the CI gate.
- [ ] **Step 4: Commit** — `docs(#1815): the player bar under embeds; e2e smoke for Listen`

---

### Task 10: Gate

- [ ] `cd frontend && npm run check && npm run build`
- [ ] Manual pass in the Docker stack, desktop and a phone: a YouTube article (sound continues after navigating to another article and after a reload; speed; lock-screen controls; previous/next between an episode and a video); a SoundCloud article; an embedding-disabled video is skipped with the next track starting; the visible embed pauses on Listen; the chapter list seeks the player after Listen on a video with chapters.
- [ ] Merge `develop`, open the PR with `Closes #1815`, and note the accepted limitations from the issue in the PR body.
