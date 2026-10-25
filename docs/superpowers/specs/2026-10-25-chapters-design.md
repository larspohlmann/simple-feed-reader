# Chapters: YouTube descriptions and podcast chapters in the article view

Refs #1496 (its chapters half; transcripts move to their own issue). Inspired by Feedler's YouTube
layout: a tappable chapter list under the player that jumps the video and follows playback.

## Decisions

| Question | Decision |
|---|---|
| What a tap does | Seeks and plays; the list highlights the current chapter live |
| Placement (YouTube) | Under the player; the timestamp block leaves the description |
| First tap on iPhone before playback | Seek first, then play; if iOS refuses play, the video waits at the chapter for one ▶ tap |
| Where YouTube chapters are parsed | Backend, when the entry detail is served — works for every stored entry, no migration |
| Scope | YouTube description chapters **and** podcast chapters (inline `psc:chapters`, `podcast:chapters` JSON) |
| Podcast surfaces | The article view's chapter list only; big player, Media Session, prev/next and scrub marks are a follow-up |
| `podcast:chapters` JSON fetch | Lazily, when the episode's article is first opened; stored afterwards |
| Transcripts | Out of scope; a new issue takes them over from #1496 |

## Measured before writing

- **Description rule on real data.** Of 67 YouTube video entries in the dev DB, the 6 with timestamps
  in the body all match the rule below; none is a false positive, none is missed. Chapter lines sit in one
  `<p>` separated by `<br>` (`LinkedPlainText` output), sometimes after a decorative line (`▀▀▀`).
- **YouTube iframe API under our sandbox.** `youtube-nocookie.com/embed/<id>?enablejsapi=1&origin=<origin>`
  with `sandbox="allow-scripts allow-same-origin allow-presentation"`: the `listening` handshake answers
  with `onReady` and `initialDelivery`; `infoDelivery.currentTime` arrives about four times a second while
  playing; `seekTo` + `playVideo` from a page tap plays at the chapter. Checked in Chromium (built-in
  browser) and iOS 26.3 Safari (simulator).
- **First tap on iOS.** In the iOS 26.3 simulator, ≥15 s after any tap, a page tap's `seekTo` +
  `playVideo` started playback with sound. A real device may differ; the seek-first order makes the
  refusal case degrade to "waits at the chapter" without extra code.

## Backend

### Module `Service/Chapter`

A chapter is the entity value `App\Entity\EntryChapter(startSeconds, title)`: the entity stores it,
so it lives with the entity. A chapter list is a plain `list<EntryChapter>`; `Support/ChapterSequence`
orders it and keeps it only with at least two strictly ascending starts. A podcast list need not
start at 0; before its first chapter nothing is highlighted. A source that finds nothing returns
`[]` rather than throwing.

### YouTube source: `Support/DescriptionChapters`

Runs only when `YouTubeUrl::video($entry->getUrl())` is a video and not a Short.

The rule is YouTube's own: split the body into lines at `<br>`, `<p>` and `<li>` boundaries; a chapter
line is an optional bracket, `H:MM:SS` or `M:SS`, an optional bracket, an optional separator
(`-` `–` `—` `:` `|`), then a title. The **first** run of consecutive chapter lines that starts at `0:00`,
has at least three lines and strictly ascends is the chapter list. Anything else means no chapters.

It returns a `DescribedChaptersModel`: the chapters and the body with exactly those lines (and their
`<br>` separators) removed; a `p`, `li`, `ul` or `ol` left empty is dropped. A later repeat of the
block stays in the text.

### Podcast source: stored on the entry

Chapters belong to the item, so they live on `Entry` in an `EntryChapters` embeddable nested in
`EntryMedia` (one migration) with two columns:

- `chapters`: `list<{startSeconds, title}>` (JSON) — written at ingest from inline Podlove `psc:chapters`
  (versions 1.1 and 1.2, `start` as `HH:MM:SS(.mmm)`), or later by the lazy fill.
- `chapters_url`: the `podcast:chapters` `url`, kept only when there are no inline chapters. It is a TEXT
  column (the entry table has no MySQL row width left for a VARCHAR(2048)); the embeddable bounds it.
  A backup restore applies the same rule and reads it as an absolute http(s) URL.

`ChapterFiller::fillOnOpen(Entry)`: for an entry with `chapters_url`, it reads the file through
`ChapterFileSourceInterface` (`GuardedChapterFileSource`), parses the Podcasting 2.0 JSON chapters
format (`Support/JsonChapters`: `startTime`, `title`; entries with `toc: false` dropped) and stores
the result, clearing the URL. The fetch runs inside the detail request, so it has its own budget on
`GuardedApiClient::sendWithin`: 3 s timeout and max duration, a 64 kB cap
(`ResponseSizeLimit::ChapterFile`), the SSRF guard, no redirects.

- Final, stored as no chapters: a malformed or empty file, an over-cap body, a 4xx, a 3xx (redirects
  are refused — a known limit for hosts that redirect their chapter files), a blocked host.
- Retried on the next open, nothing stored: a network failure, a timeout, a 5xx
  (`ChapterFileUnreachableException`, logged at info).

The name says it writes: this is not a getter.

### Serving

`ChaptersOnOpen::serve(Entry)` returns a `ServedChaptersModel`: the YouTube result for a video,
else the entry's stored chapters after the lazy fill, plus the body to serve. The entry detail
action calls it; the `ChaptersJson` mapper in `src/Http` adds:

```json
"chapters": [{ "startSeconds": 0, "title": "Who invented Enigma?" }, …]
```

or `null`. Detail only — list rows do not carry chapters. `contentHtml` is the trimmed body for a
YouTube entry with chapters. `EntryMedia::toJsonList` is the one place a chapter becomes an array,
for storage, backup and the API alike.

### Frame allowlist

The allowlist is unchanged: when the entry has chapters, the SPA appends the constant
`?enablejsapi=1&origin=…` to an already validated URL.

## Frontend

### `addChapterList` and `ArticleChapters`

A DOM decorator, `addChapterList`, because the body is `innerHTML`: it draws a
`<nav class="reader-chapters">` (styles in `article-content.component.scss`, design-language tokens)
and replaces any earlier list in the host. Each row is a `<button>` with the start time and the
title; the current one gets `aria-current="true"` and the accent mark. A tap calls
`player.playFrom(startSeconds)` and highlights that row at once. The list never scrolls itself.

`ArticleChapters` owns one rendered body's list and its player: `attach` picks the video's API
player, else the episode, places the list under the YouTube player (outside the cinema box, so
widening the player leaves it alone) or at the top of the body for an episode; `release` releases
the player and removes the list. The article body service releases on every re-render and on
destroy.

### `ChapterPlayer`

```ts
interface ChapterPlayer {
  playFrom(seconds: number): void;
  watch(onPosition: (seconds: number | null) => void): void;
  release(): void;
}
```

The current chapter is a binary search over the starts (`currentChapterIndex(starts, position)`), a
pure helper.

**`YouTubeChapterPlayer`**: after the frame loads it posts `{"event":"listening"}`; it accepts
messages only from `https://www.youtube-nocookie.com` whose `source` is that frame's
`contentWindow`, and reports `info.currentTime` to the watcher. `playFrom` posts
`seekTo(seconds, true)` then `playVideo`. `release` removes the listeners.

**`AudioChapterPlayer`** wraps `AudioPlayerService`: it reports the service's position while its
current track is this entry's attachment, else `null`. `playFrom` goes through the service's
`playFrom(track, seconds)`, which queues the episode next when it is not current and seeks once the
element's metadata is known (at once when it already is, as for a cached track).

## Testing

- **PHPUnit**: the description rule (the six real descriptions as fixtures, a missing `0:00`, fewer than
  three lines, out-of-order stamps, timestamps inside prose, a repeated block, a Short); body trimming;
  `psc` 1.1 and 1.2 parsing from the feeds measured in #1496; JSON chapters parsing (`toc: false`,
  malformed, empty); the chapter file source (budget, cap, 4xx/3xx final, 5xx and network failure
  unreachable); a functional test of the detail endpoint covering YouTube, inline podcast chapters
  and the lazy fill with a stubbed fetcher.
- **Jest**: `currentChapterIndex` boundaries; `addChapterList` (rows, tap, `aria-current`,
  replacement); `ArticleChapters` (placement, release, re-attach); `YouTubeChapterPlayer` with a fake
  `contentWindow` (handshake, origin and source checks, commands); `AudioChapterPlayer` and the
  service's `playFrom` against the existing element stub.
- **Playwright smoke**: a stubbed entry with chapters; a tap highlights the row and posts the seek. The
  spec owns its data.
- **Live check** on :4200 with a real YouTube entry and a Podlove episode, plus a real iPhone pass on the
  first-tap behaviour.

## Follow-ups (separate issues)

- Transcripts, the second half of #1496, with its open storage and indexing questions.
- Player extras: chapter list in the big player, current chapter in the mini-player and Media Session,
  previous/next chapter, scrub-bar marks.
