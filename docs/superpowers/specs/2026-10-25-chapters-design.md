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

- `Model/ChapterModel(int $startSeconds, string $title)`.
- `Model/ChaptersModel`: an ordered list, validated on construction — at least two chapters, strictly
  ascending starts. A podcast list need not start at 0; before its first chapter nothing is highlighted.
- Exceptions in `Exception/`; a source that finds nothing returns no model rather than throwing.

### YouTube source: `YouTubeDescriptionChapters`

Runs only when `YouTubeUrl::video($entry->getUrl())` is a video and not a Short.

The rule is YouTube's own: split the body into lines at `<br>`, `<p>` and `<li>` boundaries; a chapter
line is an optional bracket, `H:MM:SS` or `M:SS`, an optional bracket, an optional separator
(`-` `–` `—` `:` `|`), then a title. The **first** run of consecutive chapter lines that starts at `0:00`,
has at least three lines and strictly ascends is the chapter list. Anything else means no chapters.

It returns the `ChaptersModel` and the body with exactly those lines (and their `<br>` separators)
removed; a paragraph left empty is dropped. A later repeat of the block stays in the text.

### Podcast source: stored on the entry

Chapters belong to the item, and `App\Entity` may not import a Service model, so they live on
`Entry` in an `EntryChapters` embeddable nested in `EntryMedia` (one migration) with two columns:

- `chapters`: `list<{startSeconds, title}>` (JSON) — written at ingest from inline Podlove `psc:chapters`
  (versions 1.1 and 1.2, `start` as `HH:MM:SS(.mmm)`), or later by the lazy fill.
- `chapters_url`: the `podcast:chapters` `url`, kept only when there are no inline chapters. It is a TEXT
  column (the entry table has no MySQL row width left for a VARCHAR(2048)); the embeddable bounds it.

`ChapterFiller::fillOnOpen(Entry)` runs when an entry detail is served: for an entry with
`chapters_url` and no chapters, it fetches through the SSRF-guarded fetcher (64 KB cap, short
timeout), parses the Podcasting 2.0 JSON chapters format (`startTime`, `title`; entries with
`toc: false` dropped) and stores the result. A malformed or empty file stores an empty list so it is
not fetched again; a network failure stores nothing so the next open retries. The name says it writes:
this is not a getter.

### Serving

`EntryChapters` returns, for an entry, either the YouTube result or the entry's stored podcast chapters,
plus the body to serve. The entry detail action calls it; a `ChaptersJson` mapper in `src/Http` adds:

```json
"chapters": [{ "startSeconds": 0, "title": "Who invented Enigma?" }, …]
```

or `null`. Detail only — list rows do not carry chapters. `contentHtml` is the trimmed body for a
YouTube entry with chapters.

### Frame allowlist

The allowlist is unchanged: the SPA appends the constant `?enablejsapi=1&origin=…` to an already validated
URL.

## Frontend

### `ChapterListComponent`

A DOM decorator, `addChapterList`, because the body is `innerHTML`.
Standalone, signals, styles in `chapter-list.component.scss` with design-language tokens. Inputs:
`chapters` and a `ChapterPlayer`. Each row is a `<button>` with the start time and the title; the
current one gets `aria-current="true"` and the accent mark. A tap calls `player.playFrom(startSeconds)`
and highlights that row at once. The list never scrolls itself. Placed under the YouTube player
(outside the cinema box, so widening the player leaves it alone) and at the top of the body for an
episode.

### `ChapterPlayer`

```ts
interface ChapterPlayer {
  position: Signal<number | null>;
  playFrom(seconds: number): void;
}
```

The current chapter is a binary search over the starts (`currentChapterIndex(starts, position)`), a
pure helper.

**`YouTubeChapterPlayer`**: when the entry has chapters, the frame is built with
`?enablejsapi=1&origin=<location.origin>`. After the frame loads it posts `{"event":"listening"}`;
it accepts messages only from `https://www.youtube-nocookie.com` whose `source` is that frame's
`contentWindow`, and reads `info.currentTime` into `position`. `playFrom` posts `seekTo(seconds, true)`
then `playVideo`. The listener is removed when the article view is destroyed.

**`AudioChapterPlayer`** wraps `AudioPlayerService`: `position` is the service's position while its
current track is this entry's attachment, else `null`. `playFrom` seeks and plays when it is current;
otherwise it queues the episode next, selects it and plays from `seconds` (a small start-at path on the
service if `pendingSeek` does not already cover it).

## Testing

- **PHPUnit**: the description rule (the six real descriptions as fixtures, a missing `0:00`, fewer than
  three lines, out-of-order stamps, timestamps inside prose, a repeated block, a Short); body trimming;
  `psc` 1.1 and 1.2 parsing from the feeds measured in #1496; JSON chapters parsing (`toc: false`,
  malformed, empty); a functional test of the detail endpoint covering YouTube, inline podcast chapters
  and the lazy fill with a stubbed fetcher (success, malformed, network failure); the new frame pattern
  accepts the exact form and rejects extra or reordered parameters.
- **Jest**: `currentChapterIndex` boundaries; the component (rows, tap, `aria-current`);
  `YouTubeChapterPlayer` with a fake `contentWindow` (handshake, origin and source checks, commands);
  `AudioChapterPlayer` against the existing service stub.
- **Playwright smoke**: a stubbed entry with chapters; a tap highlights the row and posts the seek. The
  spec owns its data.
- **Live check** on :4200 with a real YouTube entry and a Podlove episode, plus a real iPhone pass on the
  first-tap behaviour.

## Follow-ups (separate issues)

- Transcripts, the second half of #1496, with its open storage and indexing questions.
- Player extras: chapter list in the big player, current chapter in the mini-player and Media Session,
  previous/next chapter, scrub-bar marks.
