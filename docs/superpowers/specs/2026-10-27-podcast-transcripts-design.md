# Podcasts: transcripts as body, seekable and searchable

Refs #1809 (the transcripts half of #1496; chapters shipped as #1808). Reads `podcast:transcript`,
keeps one transcript per episode, shows it in the article view with timestamps that seek the player,
and makes what was said searchable on both search engines.

## Decisions

| Question | Decision |
|---|---|
| When a transcript is fetched | At ingest, for every new entry that declares one — queued, not inline |
| How the fetch runs | A pending row per entry, drained by a budgeted sweep in `MaintenanceTick`; opening a still-pending episode fetches it on the spot |
| Search engines | Meilisearch gets a `transcript` field **and** the LIKE fallback matches transcript text (Strato has no Meilisearch) |
| Strato measurement before building the LIKE half | None; build it, tune if it proves slow |
| Size | Stored and indexed in full; a file over 1 MB is not fetched (the row fails) |
| Retention | None of its own: the transcript cascades with its entry |
| Reader display | The body when the entry has no article; otherwise a collapsed "Transcript" toggle below the body |
| Search hit inside a transcript | The entry is listed like any match; no snippet, no jump-to-match |
| Out of scope (v1) | Speech-to-text, live highlight of the playing paragraph, a transcript search box, snippets |

## Facts this rests on

- **Transcripts are big and common on independent hosts.** VTT per episode: 81 KB (Logbuch), 115 KB
  (Darknet Diaries), 175 KB (Lage der Nation). Declared by Lage der Nation 105/500, Logbuch 534/565,
  Changelog 777/1015 (`text/html`), Darknet Diaries 175/181; big commercial hosts declare none
  (measured 2026-10-10, #1496).
- **Production search is the LIKE fallback**, over `headline.title` and `summary` only: Strato has no
  `MEILISEARCH_URL`. Meilisearch indexes `content` too, but runs only on the Docker stack.
- **The subscribe path runs in a web request** (`FirstFetchRecorder`, up to 200 entries), so fetching
  transcripts inline there would breach Strato's request budget and the ~200 MB web-worker heap.
- **Retention bounds storage**: 90 days, a floor of 20 entries per feed — at most ~20 episodes per
  podcast feed held past the window.

## Backend

### Parsing — `Service/Parser`

`Support/DeclaredTranscripts`, beside `DeclaredChapters`, reads every
`<podcast:transcript url type language rel>` of an item and picks one: by type
(`text/vtt` → `application/json` → `application/x-subrip`/`application/srt` → `text/html`), then a
language matching the feed's `<language>`, then document order. `rel="captions"` is treated like any
other. Unknown types are ignored. The pick travels on the parsed media bundle as a
`ParsedTranscriptModel(url, format, language)`; `TranscriptFormat` is an enum in `App\Enum`, since the
entity stores it.

### Storage — `App\Entity\EntryTranscript`

One row per entry that declared a transcript; one-to-one with `Entry`, owning side on the transcript,
FK `ON DELETE CASCADE`. It is the queue and the store at once:

| Field | Type | Note |
|---|---|---|
| `entry` | FK, unique | cascade delete |
| `url`, `format`, `language` | string, enum, ?string | the declaration |
| `status` | enum `pending` / `filled` / `failed` | |
| `attempts`, `nextAttemptAt` | int, ?datetime (naive UTC) | transient-failure backoff |
| `paragraphs` | JSON `list<{start: ?float, speaker: ?string, text: string}>` | what the reader renders |
| `searchText` | LONGTEXT, nullable | speakers and text as plain prose, for both search engines |

A separate table keeps 100–200 KB per episode off `entry`, which every list reads, and confines the
LIKE scan to transcripts. Indexed on `(status, nextAttemptAt)` for the sweep's claim.

`IngestedEntryFactory` attaches a `pending` row when the parsed bundle carries a transcript, so
subscribe, refresh and restore all queue without a hook of their own.

### Module `Service/Transcript`

- `TranscriptFormat/TranscriptFormatInterface` — one normaliser per format, tagged and served through a
  keyed locator (the `FeedBodyParser` pattern): `VttTranscriptFormat` (speaker from `<v Name>`),
  `JsonTranscriptFormat` (podcast-namespace JSON: `segments[].startTime/speaker/body`),
  `SrtTranscriptFormat` (no speaker), `HtmlTranscriptFormat` (the existing sanitiser to plain
  paragraphs, no timestamps). Each yields `Model/TranscriptCueModel(start, speaker, text)`.
- `Support/TranscriptParagraphs` — merges consecutive cues of the same speaker into a paragraph that
  starts at its first cue; builds `searchText` from the result.
- `TranscriptSource/GuardedTranscriptSource` — fetches through `GuardedApiClient` with a new
  `ResponseSizeLimit::TranscriptFile` (1 MB). Separates permanent failures (SSRF-blocked, too large,
  4xx, unparseable → `TranscriptUnusableException`) from transient ones (timeout, 5xx, 429 →
  `TranscriptUnreachableException`).
- `TranscriptFiller` — fills one row from the source; a permanent failure marks it `failed`, a
  transient one increments `attempts` and pushes `nextAttemptAt` out (exponential), failing it after 5
  attempts. Each outcome logs once at `info`.
- `TranscriptSweep` — runs in `MaintenanceTick` beside `LinkCardSweep`, with its own
  `TranscriptBudgetModel` (fetch count, seconds). Claims due `pending` rows, newest entry first
  (`EntryTranscriptRepository::findClaimable(now, limit)`), fills them, flushes, then hands the filled
  entries to `EntryIndexer::index()`. Its report joins `MaintenanceSweepsModel` and
  `MaintenanceTickJson`.
- `TranscriptOnOpen` — when the opened entry's row is still `pending`, fills it within a 3 s budget
  (like `ChaptersOnOpen`); on failure the row stays for the sweep and the response has no transcript.

### Search

- **Meilisearch**: `IndexedEntryModel` gains `transcript` (`searchText` or empty); the index settings
  list it as searchable, ranked after `content`. `EntryIndexer::toIndexedEntry` reads it through the
  lazy relation, so only indexing loads it. `app:search:reindex` needs nothing else.
- **LIKE fallback**: `SearchTermsPredicateBuilder` ORs a third alternative per term —
  `EXISTS (SELECT 1 FROM EntryTranscript t WHERE t.entry = e AND <searchText predicate>)` — in both the
  substring and the whole-word form, keeping the cheap-`%term%`-first rule. It sits inside the existing
  scope predicates, so it scans only the user's entries; `DatabaseSavedSearchMatcher` uses the same
  builder and so matches transcripts too.

### API

The entry detail response gains `transcript: null | {language: ?string, paragraphs: [...]}`, set only
when the row is `filled`, shaped by `src/Http/EntryTranscriptJson`. List responses never carry it.
Plain JSON, no browser-only input: an iOS client can render it as is.

## Frontend

- `reader/article/reader-view/article-transcript` (standalone component, sibling `.scss`, shared
  tokens): a paragraph is a timestamp button, a bold speaker when present, then the text. Paragraphs
  without a start render as text only.
- **Body or section**: when the article body is empty or unavailable (the audio-first case, #1428), the
  transcript is the body; otherwise a collapsed "Transcript" toggle below the body and chapters, styled
  like the #1817 chapter details toggle.
- **Seeking** goes through the `ChapterPlayer` interface (`seek(seconds)`) by the path
  `reader-chapters.ts` uses, so audio, YouTube and SoundCloud players all work. With no player,
  timestamps render as plain text.

## Testing

- Normaliser tests on fixture files trimmed from the measured feeds (Logbuch, Darknet Diaries, Lage der
  Nation VTT; Changelog HTML) plus JSON and SRT samples; speaker merging; `searchText` shape.
- Parser tests: type preference, language tie-break, unknown types ignored.
- Filler/sweep tests: every permanent and transient class, backoff, the 5-attempt ceiling, newest-first
  claim, reindex of filled entries only.
- Functional test of the entry detail response (filled, pending, failed, none); `TranscriptOnOpen`.
- Search: substring and whole-word over transcript text on both engines, saved-search membership; both
  the SQLite and the MySQL leg.
- Migration from empty on SQLite and MySQL, `doctrine:schema:validate`; FK cascade on prune.
- Jest: body-vs-section rule, seek wiring, no-player fallback. Live check on :4200 with a real Logbuch
  episode.
