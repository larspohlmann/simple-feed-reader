# Split account backups into parts — design (#1073)

Date: 2026-09-18. Decided in a grilling session with Lars; every decision below
is settled unless it is listed under *Deviations to confirm*.

## 1. Problem

A grown backup (real case: 36,129,862 bytes gzipped, 136,542,442 bytes inflated,
47,775 lines of which 44,844 `entry` and 2,501 `entryState`) cannot be restored.
The restore is one synchronous request whose duration and memory grow with the
account: the prod stack answers 504 at nginx's 60 s default, the dev stack
segfaults ~31 s into the load pass. #1065 raised the upload ceiling and #1064
(streamed upload, reverted in `adff9ee3`) did not remove the growth.

All non-entry kinds together are 430 lines. Only the entry phase is large.

## 2. Decision in one paragraph

The export becomes a **zip of independent parts**. The **client** opens the zip
and posts **one part per request**, sequentially. The server keeps **no restore
session** and stages **no files**. The version 2 single-file format, its
endpoint and its fixtures are **removed** — no backwards compatibility.

## 3. Container

- File: `simplefeedreader-<version>-<account>-<Ymd>.zip`, `Content-Type: application/zip`.
- Zip method **store**; every member is a gzipped NDJSON document (`.ndjson.gz`).
  The client posts member bytes verbatim as `application/gzip`; the server keeps
  reading them with `GzipLineReader`.
- Written with `maennchen/zipstream-php` into the existing `StreamedResponse`.
  No `ext-zip` at runtime, no temp file. One gzipped part (~2 MiB) is buffered
  in memory so its size and CRC are known before its data is written.
- Members:
  - `001-entries.ndjson.gz` … `NNN-entries.ndjson.gz` — written first.
  - `000-foundation.ndjson.gz` — written **last** (see §4, *why last*).
  Zip member order carries no meaning; the client reads the central directory.

## 4. Part grammar (`schemaVersion = 3`)

Every part is a complete small backup document: `header` → records → `footer`
whose counts cover **that part only** and are verified by `BackupReader`. Line
fields of every record kind are unchanged from version 2.

Header fields: the version 2 fields plus

| field | parts | meaning |
|---|---|---|
| `backupId` | all | random id, identical in every part of one export |
| `part` | all | `0` for the foundation, `1..n` for entry parts |
| `parts` | part 0 only, else `null` | `n + 1`, the member count |
| `totals` | part 0 only, else `null` | `{entries, entryStates}` over the whole export |

- **Part 0 (foundation):** `account`, `tag`*, `savedSearch`*, `feed`*,
  `subscription`*. No `entry`, no `entryState`.
- **Parts 1..n (entries):** `entry`* then the `entryState` lines **of exactly
  those entries**. No other kind; no `account` line (the "account line
  required" rule applies to part 0 only).
- **Budget:** a part closes once it holds ≥ 8 MiB of inflated line bytes or
  2,000 entries, whichever comes first. The real file yields ~17 parts of
  ~2 MiB gzipped.
- **Why the foundation is written last:** the exporter streams, so `parts` and
  `totals` are only known after the last entry part is closed. Writing part 0
  last lets it carry both without a counting pre-pass.

## 5. Endpoints

All under `/api/account`, bearer JWT, raw `application/gzip` body, JSON out,
`application/problem+json` on failure. Native-iOS checklist
(`docs/architecture.md` §6) holds: stateless, no browser-only input.

| route | body | behaviour |
|---|---|---|
| `GET /backup` | — | the zip |
| `POST /restore/preview` | part 0 | validate + fit-check; returns provenance, `backupId`, `parts`, `toLoad`, `toDelete`. Deletes nothing. |
| `POST /restore/start?confirm=REPLACE` | part 0 | validate + fit-check, **wipe**, load the foundation |
| `POST /restore/entries` | one entry part | additive; no confirm phrase |

`POST /restore` (version 2) is deleted. There is no finish call.

`toLoad` = part 0's real counts for tags/savedSearches/feeds/subscriptions and
`totals` for entries/entryStates. The fit check applies the existing ceilings
to the same numbers. `totals` is a claim, not proof — hence the account
ceiling in §6.

Response of `start` and `entries`: `{loaded: {tags, savedSearches, feeds,
subscriptions, entries, entryStates}}` — rows written by **that request**.
(`savedSearches` was counted but never emitted before; it is now.)

## 6. `POST /restore/entries` rules

Two passes over the ≤ 64 MiB-inflated part, like the restore always had:

1. **Inspect, writes nothing:** grammar + footer; `header.part ≥ 1`; at most
   **5,000** `entry` lines and **64 MiB** of inflated bytes, refused while
   streaming (`invalid_backup`, 422); every `feedUrl` names a feed the user
   subscribes to **in the database** (`invalid_backup`); the account's current
   entry count across subscribed feeds plus this part's entries ≤ 500,000
   (`backup_does_not_fit`, 409).
2. **Load:** per named feed, a `RestoreFeedTarget` is built lazily from the
   database (feed id, `acceptsNewEntries`, guid-hash ⇒ id map).
   - An entry whose `(feed, guidHash)` exists is skipped (dedupe as today).
     This covers a retried part and the scheduler refreshing the feed
     mid-restore.
   - `acceptsNewEntries = false` (another account subscribes) keeps its
     meaning: the part's entries for that feed are not inserted.
   - An `entryState` attaches to the entry with that `(feed, guidHash)`,
     whether this request created it or not. **If the user already has a state
     row for that entry, the existing row is left untouched** — that makes a
     retried part idempotent and lets a click made mid-restore win.
   - Created entries go to the search index at the end of the request.

Parts are order-free among themselves and safe to retry.

## 7. Client

- `@zip.js/zip.js` reads the central directory from the `File` by random
  access; the file is never loaded whole.
- **Pre-wipe verification**, in the browser, one member at a time: part 0
  exists; member names are exactly `000-foundation` + `001..n` contiguous;
  every member's CRC verifies (zip.js `checkSignature`); every member's header
  line (first line, inflated with `DecompressionStream`) carries the same
  `backupId` and the `part` its name says; `parts` from part 0 equals the
  member count. Any failure stops before the wipe with the invalid-file message.
- Selecting a `.json.gz` shows one message, frontend only, by extension:
  "This backup uses an old format. Make a new export."
- Flow: pick → verify → `preview` (part 0) → user types `REPLACE` → `start`
  (part 0) → `entries` × n **sequentially** → sum the `loaded` counts →
  reload subscriptions and run the refresh, as today.
- **Failure:** a part is retried 3 times with backoff (1 s, 2 s, 4 s). Then the
  run stops, `failedOnce` is set (any failure after a successful `start` is
  post-wipe), and a **Continue** button resumes at the failed part while the
  page still holds the `File`. After a reload the recovery is a full re-run.
- **Progress:** `app-progress-hairline` with `done / total` requests (start
  counts as one) and the text "Part 9 of 17".

## 8. Removed

`AccountRestorer::restore` and the `/restore` route, the version 2 reader
path, `tests/Fixtures/backup/version-2.ndjson` and `oldest-supported.ndjson`
(replaced by version 3 fixtures; the *oldest supported* fixture restarts at
version 3), `restoreAccount` in `reader-api.ts`.

## 9. Unchanged

The 64M request body limit (five files, `RequestBodyLimitAgreementTest`). The
export stays one streamed GET. The non-transactional wipe-then-load contract.
§6/§7 of `docs/backup.md` (what is dropped, what a restore must never write).
#1066 stays open and independent.

## 10. Deviations to confirm with Lars

Two details were settled while reading the code, after the grilling answers:

1. Q8 said every header carries `parts`. Streaming makes that unknowable
   before the last part closes, so only part 0 carries `parts`/`totals` and it
   is written last (§4).
2. §6 "existing state row is left untouched" — the grilling only fixed the
   entry side of the mid-restore race.
