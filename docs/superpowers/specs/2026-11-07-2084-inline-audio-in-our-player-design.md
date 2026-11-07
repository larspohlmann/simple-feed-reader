# Inline article audio plays in our player (#2084)

## Problem

An article that carries its own audio clip — Deutschlandfunk's `data-audio-src` MP3, an `og:audio`, a
WordPress `<audio>` in the feed body — renders as a native `<audio controls>`. It cannot be played in the
app's player or queued. YouTube, SoundCloud and Mixcloud embeds already offer **Listen** / **Add to
playlist** through `ArticlePlayerBars`; plain audio gets nothing.

## Goal

Every `<audio>` in a rendered article body, narration excepted, is replaced in the SPA by the inline Listen
row — the same two buttons an entry with an audio enclosure shows (`▶ Listen 47:51` · `Add to playlist`).
The native element never shows in the SPA. Live streams are never offered.

Out of scope: narration (`audio.reader-narration` keeps its toggle), `<video>`, a live play/pause state on
the row, a DOM pass over feed `content_html` at feed ingest.

## Contract: `<audio>` stays the carrier

The body keeps a real, playable `<audio>`, so a client without the upgrade (a native iOS client, an export)
still plays it. Ingest emits exactly one shape:

```html
<audio controls preload="none" src="https://…/clip.mp3" title="Clip title"><time datetime="PT2871S">47:51</time></audio>
```

`title` and the `<time>` child (whole seconds as `PT…S`, the clock as fallback text) appear only when the
page stated them; narration adds `class="reader-narration"`. No `<source>` children. The duration rides in a
`<time>` rather than a `data-` attribute because Angular's `[innerHTML]` sanitizer strips every `data-*`.

## Backend

### `AudioPlayerMarkup` — the one builder

`Service/Reader/Media/AudioPlayerMarkup` is the only code that creates an `<audio>`. It takes the document
and an `AudioClipModel` (`url`, `?title`, `?durationSeconds`, `narrated`) and returns the element above.
Callers:

- `PageMediaInserter::player()` — its `MediaKind::Audio` arm; the `<video>` path is unchanged. A candidate's
  `mimeType` no longer yields a typed `<source>` for audio: the one-source contract wins, and the browser's
  sniffing covers the rest.
- `TeaserPlayerMarkup` — the audio branch of `appendPlayer()`.
- `AudioPlayerNormalizer` (below).

### Clip metadata on the candidate

`MediaCandidateModel` gains `?int $durationSeconds`; `label` is the clip title for audio. `completedBy()`
and the other copy methods carry the new field.

`AttributeMediaSource::bestCandidate()` reads, for an audio candidate, the origin element's attributes:
a title from the first attribute whose name ends in `title` (`data-audio-title`, `data-audiotitle`), a
duration from the first whose name ends in `duration` and whose value is a positive integer of seconds.
Host-agnostic: no host names in the rule.

### `AudioPlayerNormalizer` — pass-through audio

A new `BodyCleaningStep`, ordered after `PageMediaPlacement` and `TeaserPlayerInserter`, rebuilds every
`<audio>` already in the body (from the page or feed HTML) through `AudioPlayerMarkup`:

- the source is the element's `src`, else its first `<source>` with an audio or absent `type`;
- `title` and a direct-child `<time datetime="PT…S">` with positive whole seconds survive, everything else (inline `controls` variants,
  `autoplay`, `loop`, `<source>` lists) is rebuilt away;
- `reader-narration` survives;
- an `<audio>` with no usable source, or whose source is a live stream, is removed.

Players the inserter built already match and pass through unchanged (idempotent).

### Live streams

`LiveAudioStream::is(string $url): bool` in `Service/Reader/Media/Support/` — a static, host-agnostic
check: the URL's path, case-insensitively, ends in `/stream`, `/stream.mp3`, `/stream.aac`, `/live`,
`/live.mp3`, `.pls` or `.m3u` (not `.m3u8`: HLS is a `MediaKind::Stream`). It is applied twice:

- `AttributeMediaSource`, `MetaMediaSource`, `SemanticMediaSource` and `JsonLdMediaSource` drop an audio URL
  it matches before ranking, so a live stream never becomes a candidate (on DLF the live stream sits beside
  the episode);
- `AudioPlayerNormalizer` removes a pass-through `<audio>` it matches.

### Sanitizer

`EntrySanitizer`'s safe-element set already keeps `title` on `<audio>` and `<time datetime>` inside it; no
attribute is added. Angular's sanitizer keeps both too, which is why the duration is a `<time>` and not a
`data-` attribute. The existing `allowMediaSchemes(['http', 'https'])` still guards `src`.

### Cache

The reader output version is derived from the Reader module (#1561), so cached articles re-extract on their
own; no manual bump.

## Frontend

### `ListenOffer`

`ListenRowComponent` today takes an `EntryAudio`. It takes a narrower `ListenOffer` instead — `duration()`
(formatted or null), `queued()`, `play()`, `toggleQueued()` — which `EntryAudio` already satisfies
structurally. The row's template and styles are unchanged.

### `BodyAudio`

`reader/audio/body-audio.ts`: given an `<audio>` and the `EmbedTrackOrigin` of the entry, it builds the
`AudioTrack` —

- `url`: the element's `src`, else its first direct-child `<source>` with an audio or absent `type` (bare
  `<audio>` in older entries and feed bodies);
- `title`: the `title` attribute, else `origin.title`;
- `durationInSeconds`: the direct-child `<time datetime="PT…S">` as positive whole seconds, else null;
- `imageUrl`, `faviconUrl`, `entry`: from the origin;

and implements `ListenOffer` against `AudioPlayerService` and `ListenLauncher`, like `EntryAudio`.

### `ArticleBodyAudio`

`article-content/article-body-audio.ts`, called from `ArticleBodyService.render()` after
`decorateArticle()` (so `removeEnclosurePlayers` has already dropped the enclosure's duplicate):

```
for each audio:not(.reader-narration) in host
  if no source → leave it
  mount ListenRowComponent before it with a BodyAudio offer
  remove the <audio>
```

Without an origin (no entry), audio is left native. Islands are released with the render like every other
island.

## Testing

Backend (PHPUnit, SQLite and MySQL legs):

- `AudioPlayerMarkupTest`: the exact shape with and without title/duration, narration class.
- `AudioPlayerNormalizerTest`: `<source>` list collapses to the first playable; `src` wins over `<source>`;
  title/duration kept, junk attributes dropped; live stream removed; source-less removed; inserter output
  unchanged.
- `LiveAudioStreamTest`: each suffix, `.m3u8` and an ordinary `.mp3` not matched.
- `AttributeMediaSourceTest`: DLF-shaped fixture yields the episode MP3 with title and 2871 s, not the live
  stream.
- `EntrySanitizerTest`: `title` and `<time datetime>` survive on `<audio>`, an `onplay` does not.
- `PageMediaInserterTest`, `TeaserPlayerMarkupTest`: updated to the one-source shape.

Frontend (Jest in the Docker frontend container):

- `body-audio.spec.ts`: track fields from attributes; fallbacks to `<source>` and origin title.
- `article-body-audio.spec.ts`: audio replaced by a row; narration untouched; source-less untouched;
  no origin → untouched.
- `listen-row` spec still green against `ListenOffer`.
- `article-content.component.spec.ts`: the canonical markup rendered through the real `[innerHTML]` binding
  (Angular's sanitizer) still shows 47:51 on the row.

Live check on :4200 with entry 724939: the native player is gone, Listen plays the episode in the bottom
bar with its own title and 47:51, Add to playlist queues it.
