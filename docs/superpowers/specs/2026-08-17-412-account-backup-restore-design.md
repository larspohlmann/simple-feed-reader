# Back up and restore a whole account between instances (#412)

## Problem

There is no way to move an account from one instance to another. OPML carries
feed URLs and one folder level, and nothing else: no tags with their colours and
order, no custom titles, no read watermarks, no favourites, no kept articles, no
recommendation settings, and none of the articles themselves. A user moving from
the Docker install to Strato — or to a new server — starts over.

## Goal

One file holds everything an account owns. Downloading it on the source instance
and uploading it on the target instance reproduces the account there.

The file keys on **natural values only** — feed URL, entry GUID, tag name — never
on database ids, because the two instances share no id space.

The existing OPML export/import pair stays untouched. OPML is additive and safe;
a restore is destructive, so it gets its own section, its own warning and its own
dry run.

**Guiding principle: KISS.** The measurements in the appendix removed the
justification for most of the machinery an earlier draft proposed. What is left
is one request in each direction.

## Non-goals

Elasticsearch-style incremental sync, a merge mode, a worker job, chunked
transfer, resume after a crash, a schema-upgrade path, and an "export the last N
days" convenience. Each is argued down in *Rejected alternatives*.

---

## Design

### 1. What the file holds

**Account-owned rows:**

- Subscriptions: feed URL, `customTitle`, `position`, `markedReadUntil`,
  `createdAt`.
- Tags: `name`, `color`, `icon`, `position`.
- Subscription↔tag joins: tag name plus the per-tag `position`.
- Entry states: read / favourite / kept / viewed and their timestamps, keyed by
  feed URL plus entry GUID.
- `Preferences` (`scrapeFallbackEnabled`).
- `RecommendationSettings` — serialized through the existing
  `RecommendationSettings::values()` / `update(RecommendationSettingsValues)`
  pair, so the field list lives in one place and cannot drift.
- `User.locale`.

**Feeds and their articles:**

- Feed: `url`, `siteUrl`, `title`, `description`, `faviconUrl`, `sourceFormat`.
- Every entry of every subscribed feed, with bodies: `guid`, `guidHash`, `url`,
  `title`, `author`, `summary`, `contentHtml`, `imageUrl`, `imageWidth`,
  `imageHeight`, `publishedAt`, `createdAt`, `effectiveDate`.

`EntryPruner` already bounds the article set: 90 days by fetch date, plus a
per-feed count cap of 2000.

#### `guidHash` is written to the file

An earlier draft dropped it and recomputed it on restore, on the grounds that
`hash('sha256', $guid)` in the `Entry` constructor is the only write site. That
plan contradicts the batched inserts below — a multi-row `INSERT` goes through
DBAL, so no constructor runs, and recomputing would have meant a second hash call
site or a 14× slower row-by-row load.

Writing the column instead costs 1.25 MiB raw, which compresses away. It also
means the restore **trusts the hash from the file**: a hand-edited file could
carry a hash that does not match its `guid`, and the restore would store it.
Accepted deliberately. This is the user's own migration file, and a verification
pass is machinery KISS rejects.

#### Body fields stay in

Dropping `contentHtml` was considered and rejected. It is 66.6% of the payload,
so the saving is real, but two facts kill the idea:

- `contentHtml` is written **at ingest, from the feed**. 95.1% of entries have a
  body (only 1,004 of 20,412 are empty). No user click is involved.
- The click-to-extract path (`GET /api/entries/{id}/reader`) **never persists its
  result**. It returns JSON, cached client-side in IndexedDB, successes only, 100
  entries, LRU.

So a bodiless restored entry would become a fresh outbound fetch on every viewing,
forever, for articles that still exist online — and a permanent 500-character
snippet for those that do not. The file is about 4 MiB gzipped with bodies in.
That is not a size worth engineering against.

#### Excluded on purpose

- **AI connections** (`AiProviderSettings`, the active connection, `ProviderUsage`).
  The key ciphertext is sealed against the source instance's `APP_SECRET` and
  cannot decrypt elsewhere. Half-configured connections that fail on first use are
  worse than none.
- **`roles`, `status`, `maxSubscriptions`, `trialEndsAt`, OAuth identities
  (`UserIdentity`), `ActionToken` rows.** These are account data an admin or a
  login provider owns. **A backup file must never raise its own limits.**
- **`Feed.etag`, `Feed.lastModified`, `Feed.status`, the whole `FetchSchedule`.**
  These describe the source instance's fetch history and would make the new
  instance misjudge its first fetch.
- **Recommendation runs and their children.** History of work done on the source
  instance; the settings come across, the runs do not.

### 2. Format

Gzipped NDJSON, `.json.gz`, streamed line by line. A header line carries
`schemaVersion` (1), backup date, source instance URL and source account email.

One line per row, tagged by kind. Sketch:

```
{"kind":"header","schemaVersion":1,"createdAt":"…","sourceUrl":"…","sourceEmail":"…"}
{"kind":"account","locale":"de","preferences":{…},"recommendationSettings":{…}}
{"kind":"tag","name":"Tech","color":"…","icon":"…","position":0}
{"kind":"feed","url":"https://…","siteUrl":"…","title":"…","description":"…","faviconUrl":"…","sourceFormat":"xml"}
{"kind":"subscription","feedUrl":"https://…","customTitle":null,"position":3,"markedReadUntil":"…","createdAt":"…","tags":[{"name":"Tech","position":1}]}
{"kind":"entry","feedUrl":"https://…","guid":"…","guidHash":"…", …}
{"kind":"entryState","feedUrl":"https://…","guid":"…","isRead":true,"readAt":"…", …}
```

Order is significant: header, account, tags, feeds, subscriptions, entries, entry
states. The reader may then resolve every reference forward without a second pass.

### 3. The export must stream server-side

**This is the one place the design can still run out of memory, and it is not the
obvious one.** Reading 102,060 entries with a plain buffered query peaked at
**349.6 MiB against a 512M limit**. The same run's streaming *import* held flat at
127 MiB and the streaming *export* at 7.0 MiB.

Therefore:

- Read entries with `toIterable()`, an unbuffered query, or keyset batches —
  **never a buffered `SELECT` over the whole set.**
- `clear()` the entity manager per batch.
- Write each NDJSON line into an incremental deflate context (`deflate_init` /
  `deflate_add`). The uncompressed document is never materialised.
- Emit through a Symfony `StreamedResponse`.

A test pins this property. A buffered read is the one failure mode the design
cannot absorb, and it fails only on a large corpus — which no ordinary test has.

### 4. Restore — replace only

There is no merge mode. A restore wipes, then loads.

**Wiped:** subscriptions, subscription tags, tags, entry states, preferences,
recommendation settings, recommendation runs and their children (items, logs,
progress).

**Survives:** email, password hash, AI connections and the active AI connection,
provider usage, roles, status, `maxSubscriptions`, `trialEndsAt`, OAuth
identities.

**Refuses before any deletion** when the backup does not fit — more subscriptions
than `maxSubscriptions` allows (via `SubscriptionLimitResolver`), or more than
**500,000 entries**. **It refuses; it never truncates.** Never wipe unless the
whole backup fits.

500,000 is a sanity ceiling, not a tuned limit: the 240 s budget permits roughly
two million entries, and the largest real corpus measured is 102,060. A file above
it is corrupt or hostile, not a large account.

**Refuses** a backup whose `schemaVersion` is newer than the running instance.

**Ignores** a mismatch between the backup's email and the target account. The
purpose is migration, and the target account is whoever is logged in.

#### Read the upload as a stream

- The client POSTs the gzip bytes as the **raw request body**, `Content-Type:
  application/gzip`. Not `multipart/form-data`: PHP spools every multipart upload
  to `upload_tmp_dir` before user code runs, and Strato provides no writable temp
  directory. Raw body is also the shape a native iOS client sends most easily, and
  it matches how `OpmlController` already reads `$request->getContent()`.
- The server reads `fopen('compress.zlib://php://input')` plus `fgets()`, line by
  line. No temp file, no separate gunzip step.
- Decode one line at a time; buffer into batched multi-row inserts.
- **Split lines with a carry, not with repeated `substr()` on a shared buffer.**
  That is O(n²) and cost 8.7 ms/row in the probe versus 0.09 ms/row once fixed — a
  100× difference that looked exactly like a slow database.

#### No schema-upgrade machinery

Keep the `schemaVersion` field and keep the refusal on a newer version. Do **not**
build a named upgrade step: there is no v0 to upgrade from. Write it when v2
exists and there is a real v1 file to convert.

### 5. Rules for the shared rows

`feed` and `entry` are global. `feed.url` is unique instance-wide and `entry` is
unique per `(feed_id, guid_hash)`. **A restore must not change what other users
see.**

| Situation | Action |
|---|---|
| Feed URL unknown | Create it: url, siteUrl, title, description, faviconUrl, sourceFormat. Never etag, lastModified, status or the FetchSchedule. |
| Feed URL known | Change nothing on the feed row. Subscribe only. |
| Entry unknown, no other user subscribes to that feed | Create it. |
| Entry unknown, feed has another subscriber | **Do not create it.** Restore only the states that match rows already present. |
| Entry known | Change nothing; attach the state to the existing row. |

The "another subscriber" rule stops a restore from pushing thousands of old
articles into a stranger's unread list. On a single-user target — the migration
case — it behaves identically to "create everything". It costs one
subscriber-count query per feed, about 115 cheap queries. **Kept deliberately:
KISS means removing machinery, not removing safety.**

A feed URL out of a backup file enters the global feed table and the refresh
worker fetches it later. The SSRF boundary must still hold at fetch time. **Do not
add a second, weaker fetch path for restore.**

### 6. Restored timestamps

Restored entries keep the backup's `createdAt` and `effectiveDate`.

`EntryPruner` measures age from `createdAt`, the fetch instant (#384), and prunes
instance-wide. Therefore **a source instance that runs its pruner cannot hold an
entry older than 90 days in the first place**, and the backup file cannot contain
one. Restore it the same day and nothing is pruned.

Loss appears in three cases only:

1. The source instance never ran its pruner — a dead cron, or an install where
   the sweep never fired.
2. The 2000-per-feed cap did the deleting, not age. Then age is not involved.
3. **The backup file is old when it is restored.** Back up today, restore in four
   months, and the first refresh deletes nearly everything.

Case 3 is the real hazard. The dry run carries **a general warning that an old
backup file loses articles on the first refresh** — deliberately general, with no
per-entry analysis and no computed numbers.

### 7. Confirmation

1. The user uploads the backup to a **dry run**. The server validates it and
   reports the true cost: what will be deleted and what will be loaded, counted
   per kind, plus the age warning above.
2. The user types `REPLACE` and confirms.
3. The client **uploads the file a second time**, with the confirmation. The
   server validates that file from scratch — the "refuses before any deletion"
   check runs on the real bytes — then wipes and loads.

**No single-use token, no TTL, no SHA-256 binding.** Dropped as overengineering:
it is a token store, an expiry and a hash on both sides, guarding against one case
— the user reading the report for file A and confirming with file B — that a
single person migrating their own account will not hit. The guard that matters is
the fit check, and it runs on the real file either way.

The double upload stays, because Strato has no writable temp directory to park the
file in between the two steps.

The confirm dialog offers a one-click OPML export as a safety net, and states
plainly that a failed restore leaves the account empty and that the fix is to run
the same file again.

### 8. Failure and recovery — accepted cost

- **No transaction.** The wipe and the load are not atomic. A crash in the middle
  leaves the account wiped and partly loaded. Accepted: the feature targets fresh
  accounts on a new instance.
- **Recovery is a re-run of the same file.** The wipe is idempotent, so the second
  run starts from a clean account. The UI keeps a "run the same file again"
  message after any failed restore.
- Both halves run **inside a single HTTP request**. No worker job, no chunk
  protocol, no run state, no progress polling, no resume. Strato has no worker
  anyway.

### 9. No size cap on either side

- **An import-side cap is unsafe by construction.** The wipe happens first, so a
  cap truncates *after* the source instance is already gone, and the server
  silently decides which articles to discard.
- **An export-side article limit is honest but unnecessary.** 102,060 entries
  import in 9.2 s, and Strato's `upload_max_filesize` is 128M against a real file
  of about 4 MiB.

The refusing entry-count ceiling in the restore fit check is the whole defence.

---

## Structure

### `AccountReset` — its own service

The wipe is **not** a private method in the restore service. It is the most
destructive code in the repository, so it gets one name, one home and its own
tests. An admin "reset user" action or a CLI command can call it later.

`entry_state` has no scalar id and holds tens of thousands of rows: delete with
bulk DQL. **Every test asserting a row is gone must `clear()` first** — after a
bulk delete, `find()` serves the stale identity map and the assertion passes when
it should fail.

### A restore-specific loader for tags and subscriptions

`SubscriptionCreator` and `BulkSubscriber` are **not** reused for the load.
Verified against both:

- `SubscriptionCreator` assigns a fresh position from `nextPositionForUser`,
  stamps `createdAt` from the clock, and creates the `Feed` row with `url` and
  `sourceFormat` only.
- `BulkSubscriber` accepts exactly one tag per item and also renumbers positions.

A backup stores exact positions, several tags per subscription, `customTitle`,
`markedReadUntil`, the original `createdAt`, and full feed metadata. Calling
either service would mean overwriting nearly every field it just set — more code
than writing the rows directly, and the patching would silently drift from the
service it patches.

Reused instead:

- `SubscriptionLimitResolver`, for the pre-wipe fit check. The cap rule stays in
  one place.
- `SubscriptionCreator`'s **`sourceFormat` trust rule**, restated explicitly in
  the loader: a scraped, user-asserted value never overwrites a stronger fact on a
  shared feed row. Since a known feed row is never modified at all, this reduces to
  "only set `sourceFormat` on a feed this restore creates" — which is stricter, and
  is the intent.

### Inserts

Batched multi-row inserts, **500 rows per statement**. 100 and 500 measured within
noise of each other (0.093 vs 0.085 ms/row); 500 peaked at 8.0 MiB.

`EntryState.isViewed` has no setter by design (#307). The loader uses the existing
one-way `markViewed($viewedAt)`, which sets both the flag and the timestamp — no
new setter, and the one-way invariant is untouched.

### API

Three endpoints, one new controller.

```
GET  /api/account/backup                  → application/gzip, streamed download
POST /api/account/restore/preview         → dry run; body = raw gzip
POST /api/account/restore                 → the real thing; body = raw gzip
```

The confirmation travels as an explicit request field on the second call, not as
an implicit "you already previewed" server state.

**Architecture §6 checklist**, recorded here and to be repeated in the PR:

| Check | Result |
|---|---|
| Auth by bearer token | Yes |
| Stateless | Yes — no server-side state between preview and restore |
| JSON in, JSON out | **Partly.** The transfer is `application/gzip` in both directions; every error and the preview report are `application/problem+json` / JSON. Consciously accepted: the payload is a file, and OPML export already sets this precedent. |
| No browser-only inputs | Yes — raw body, no multipart, no CSRF, no form |
| No redirect-to-web handoff | Yes |
| Links client-agnostic | N/A |

### Limits that must move

Corrected against the live host:

- `client_max_body_size` → **25m**, on the restore route only. The real file is
  about 4 MiB gzipped; 25m is 6× headroom. Strato's own `post_max_size` and
  `upload_max_filesize` are 128M, so it fits underneath. The current global value
  in `docker/nginx/default.conf` is 10m.
- `fastcgi_read_timeout` → the existing global 180s already covers this; the work
  takes seconds. No change needed in Docker.
- **An earlier draft claimed a 256 MB prod `memory_limit`. It is 512M**, verified
  through a real web request.
- Strato's 240s `max_execution_time` cannot be changed from this repository, and
  does not need to be.

### UI

A separate section in settings, below the OPML pair, with its own heading and
warning. Two actions: download a backup, and restore this account from a backup.

Both `public/i18n/en.json` and `de.json` get every new key. Hex colours and raw
`px` values are forbidden outside `src/app/theme/`; styles live in a sibling
`.scss` file.

---

## Testing

- **Unit** tests for the NDJSON line codec, the header/version check, the fit
  check, and the O(n²)-free line splitting with a carry.
- **Integration** round trip: seed an account, back it up, wipe it, restore it,
  assert field-for-field equality. This belongs in the phpunit suite, not in e2e —
  `composer infection:diff` gates the changed files, and an e2e-only proof scores
  0% MSI.
- **A test that pins the streaming property of the export.**
- **`AccountReset` gets its own tests**, each `clear()`ing before asserting a row
  is gone, and each proven falsifiable.
- Shared-row safety: a test where a second user subscribes to the same feed,
  proving the restore creates no entries and modifies no feed row.
- Frontend Jest tests for the section component and the confirm flow.

---

## Rejected alternatives

**Merge mode.** Two accounts' states would have to reconcile per entry, and the
result is neither the backup nor what was there. Replace-only is one rule the user
can hold in their head.

**Multiple processes writing batch files, then gzip.** Does not save RAM — a
streamed write is flat at 7.0 MiB regardless of file size — and it adds partial
files, an orphan-cleanup path on crash, a merge step, and a writable temp
directory Strato does not provide.

**Frontend-orchestrated chunked import.** Sound in principle: each chunk is its
own request, so each gets a fresh 240 s. But there is nothing to spend it on. A 5×
corpus imports in 9.2 s of the 240 s allowed, so the cap permits roughly two
million entries. Chunking would buy a `restore_run` state row, a resume path and
line-aligned client-side slicing, for no gain.

**A single-use confirmation token.** See §7.

**Recomputing `guidHash` on restore.** See §1.

**Reusing `SubscriptionCreator` / `BulkSubscriber` for the load.** See *Structure*.

---

## Appendix — measurements

All figures measured 2026-08-17. Strato figures come from a real HTTPS request
against the live host; the probe created one throwaway table and dropped it, and
left no trace.

**Host (Strato, real web request)**

| | |
|---|---|
| PHP | 8.4.22, SAPI `cgi-fcgi` |
| `memory_limit` | 512M |
| `max_execution_time` | 240 s |
| `post_max_size` / `upload_max_filesize` | 128M |
| MySQL | 8.0.36, remote host, 1.75 ms round trip |
| `max_allowed_packet` | 64 MiB |
| Live data at probe time | 17,836 entries, 115 feeds, 120 subscriptions, 257 entry states, 28.3 MB of body, avg 1,620 bytes |

**Payload composition (Docker instance, 20,412 entries, 36.68 MiB raw)**

| Column | MiB | Share |
|---|---:|---:|
| `content_html` | 24.43 | 66.6% |
| `summary` | 3.72 | 10.1% |
| `image_url` + `url` | 3.62 | 9.9% |
| `guid` + `guid_hash` | 2.22 | 6.0% |
| `title` | 1.28 | 3.5% |
| scalars | 1.23 | 3.4% |
| `author` | 0.17 | 0.5% |

Real compression is about 9× — roughly 4 MiB gzipped. Only 195 entries carry an
`entry_state` row (23 favourite, 13 kept); bulk read state lives in the
subscription `markedReadUntil` watermark.

**Insert cost, extrapolated to 20,412 entries**

| Method | ms/row | Total | Peak RAM |
|---|---:|---:|---:|
| Row by row, no transaction | 2.261 | 46.2 s | — |
| Row by row, one transaction | 1.201 | 24.5 s | — |
| Batched, 100 per statement | 0.093 | 1.9 s | 2.0 MiB |
| Batched, 500 per statement | 0.085 | 1.7 s | 8.0 MiB |

**Full round trip at 5× scale — 102,060 entries, real web request**

| Phase | Time | Peak RAM |
|---|---:|---:|
| Export: build NDJSON, streamed gzip | 6.20 s | 7.0 MiB |
| Import: gunzip, decode, batched insert of 500 | 9.20 s (0.090 ms/row) | 127 MiB |
| Read back with a **buffered** query | 0.56 s | **349.6 MiB** |
| **Total** | **15.41 s of 240 allowed** | 349.6 MiB of 512M |

Import cost is linear: 20k rows at 2.1 s, 100k at 9.1 s.
