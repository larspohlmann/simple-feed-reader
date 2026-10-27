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
| Reader display | The section renders open when the feed body has no text, collapsed otherwise, as a "Transcript" toggle below the body |
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
(`text/vtt` → `application/json` → `application/x-subrip`/`application/srt` → `text/html`), then document
order; there is no language tie-break, since the feed's `<language>` is not stored on `Feed` and the item
parser never sees it. `rel="captions"` is treated like any
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
| `paragraphs` | JSON `list<{startSeconds: ?int, speaker: ?string, text: string}>` | what the reader renders |
| `searchText` | LONGTEXT, nullable | speakers and text as plain prose, for both search engines |

A separate table keeps 100–200 KB per episode off `entry`, which every list reads, and confines the
LIKE scan to transcripts. Indexed on `(status, nextAttemptAt)` for the sweep's claim.

`EntryTranscriptWriter` (`Service/Ingest`) persists a `pending` row for each newly created entry whose parsed
bundle carries a transcript, so subscribe and refresh queue it. Restore queues nothing: backups carry no
transcript declaration and restore does not go through `EntryIngestor`, so a restored episode has no
transcript (accepted for v1).

### Module `Service/Transcript`

- `TranscriptReader` picks the cue reader with a `match` on the format, not a keyed locator: the readers
  are pure static helpers in `Support/` (`TimedCues` for VTT and SRT, which share one cue reader, with the
  speaker from `<v Name>`; `JsonCues` for podcast-namespace JSON `segments[].startTime/speaker/body`), and
  HTML goes through the existing sanitiser to plain paragraphs without timestamps. Each yields
  `Model/TranscriptCueModel(startSeconds, speaker, text)`.
- Reading is defensive: bodies are scrubbed to valid UTF-8 first; timings accept 1–3 digit hours and
  optional milliseconds and spaces around `-->`; JSON starts are kept only when finite and within
  [0, 1000 h).
- `Support/TranscriptParagraphs` — merges consecutive cues of the same speaker into a paragraph that
  starts at its first cue (an `int` `startSeconds`, as `EntryChapter` has); builds `searchText` from the result.
- `TranscriptSource/GuardedTranscriptSource` — fetches through `GuardedApiClient` with a new
  `ResponseSizeLimit::TranscriptFile` (1 MB). Separates permanent failures (SSRF-blocked, too large,
  4xx, unparseable → `TranscriptUnusableException`) from transient ones (timeout, 5xx, 429 →
  `TranscriptUnreachableException`). A DNS failure (`UnresolvableHostException`, a subclass of
  `SsrfBlockedException`) is transient: it backs the row off instead of failing it.
- `TranscriptFiller` — fills one row from the source; a permanent failure marks it `failed`, a
  transient one increments `attempts` and pushes `nextAttemptAt` out (exponential), failing it after 5
  attempts. Each outcome logs once at `info`. Any unexpected failure while filling also backs the row off
  (logged at `error`) and fails it after 5 attempts, so one bad row never aborts the sweep.
- `TranscriptSweep` — runs in `MaintenanceTick` beside the link-card sweep, with its own
  `TranscriptBudgetModel` (fetch count, seconds). `MaintenanceTick` gains no tenth constructor parameter
  (PHPMD `ExcessiveParameterList` fires at 10): link cards and transcripts sit behind one
  `FollowUpFetchSweeps` service and one report model. Claims due `pending` rows, newest entry first
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
  `EXISTS (SELECT 1 FROM EntryTranscript t WHERE t.entry = e AND <searchText predicate>)`, its subquery
  alias built from the entry alias too, so the nested duplicate-collapse query stays unambiguous, — in both the
  substring and the whole-word form, keeping the cheap-`%term%`-first rule. It sits inside the existing
  scope predicates, so it scans only the user's entries; `DatabaseSavedSearchMatcher` uses the same
  builder and so matches transcripts too.

### API

The entry detail response gains `transcript: null | {language: ?string, paragraphs: [...]}`, set only
when the row is `filled`, shaped by `src/Http/EntryTranscriptJson`. List responses never carry it.
Plain JSON, no browser-only input: an iOS client can render it as is.

## Frontend

- `reader/article/decorators/reader-transcript.ts` is a DOM decorator, as chapters are, not a component: a
  paragraph is a timestamp button, a bold speaker when present, then the text. Paragraphs without a start
  render as text only. `ChapterDto` and the transcript DTOs live in `reader/episode-detail.ts`.
- **Open or collapsed**: the section renders open when the feed body has no text (the audio-first case,
  #1428), collapsed otherwise, below the body and chapters, styled like the #1817 chapter details toggle.
- **Seeking** goes through the `ChapterPlayer` interface (`seek(seconds)`) by the path
  `reader-chapters.ts` uses, so audio, YouTube and SoundCloud players all work. With no player,
  timestamps render as plain text.

## Testing

- Normaliser tests on fixture files trimmed from the measured feeds (Logbuch, Darknet Diaries, Lage der
  Nation VTT; Changelog HTML) plus JSON and SRT samples; speaker merging; `searchText` shape.
- Parser tests: type preference, document-order tie, unknown types ignored.
- Filler/sweep tests: every permanent and transient class, backoff, the 5-attempt ceiling, newest-first
  claim, reindex of filled entries only.
- Functional test of the entry detail response (filled, pending, failed, none); `TranscriptOnOpen`.
- Search: substring and whole-word over transcript text on both engines, saved-search membership; both
  the SQLite and the MySQL leg.
- Migration from empty on SQLite and MySQL, `doctrine:schema:validate`; FK cascade on prune.
- Jest: open-vs-collapsed rule, seek wiring, no-player fallback. Live check on :4200 with a real Logbuch
  episode.
