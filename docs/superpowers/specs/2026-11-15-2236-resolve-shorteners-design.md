# Resolve shortened link-card URLs for Trending (#2236) — design

## Intent

A post often shares an article through a short link: `n.pr/4kcyxuV`, `trib.al/X9b8h3C`, `bit.ly/4iQI7RJ`,
`taz.de/!6227406`. Trending ties a post to an article by its card URL's hash, and a short link never hashes like the
article. So the post misses the outlet's own entry of that article, a story can show the same article twice (the
#2229 "duplicate rows": an `n.pr` share next to NPR's own row), and the "two distinct article sites" rule counts
`n.pr` apart from `npr.org` and every `trib.al` outlet as one site.

After this change a shared link that looks like a short link is followed once, in the background, to where it lands.
The landing is cleaned like every stored URL (#2237) and kept, with its hash, on the URL's `shared_link` row (#2239).
Trending's article key, its URL grouping and its site count read the landing, and fall back to the URL as posted. The
card keeps showing and linking the URL as posted.

Lars (2026-11-15), verbatim: "I want to run a backfill, but it should be a one-time script for localhost and strato.
The script can be deleted afterwards". Earlier rulings, binding: generic redirect resolution with no shortener list;
the resolution stored once per `shared_link` row; resolution through the outbound SSRF boundary, with no body
download, a hop limit, a short timeout and a fixed budget per tick; fail safe.

## Goals

- G1. A new `shared_link` row whose URL has the short-link shape (D1) is queued for resolution when it is written,
  whichever writer writes it.
- G2. A budgeted background pass follows each queued URL once through `RedirectFollower`, with HEAD first, and stores
  where it lands, cleaned, with its `UrlNormalizer::hash()` (D2–D4).
- G3. A row is settled once: resolved, unchanged, or unresolved. No row is ever resolved twice; a transient failure is
  retried at most twice, an hour apart; and a failure leaves Trending exactly as today for that row (D6).
- G4. Trending's window read and its shown-entries read take the resolved URL and hash when there is one, else the
  posted URL and hash (D8). That covers the article key (`TrendingStories`), the URL links between entries
  (`TrendingGrouping::urlHashesOf`), the story-card rule (`WindowLinkCardModel::namesAStory`) and `article_site`.
- G5. One-off backfill: `app:shared-links:resolve` queues every existing row of the short-link shape and drains the
  queue. It ships with the deploy, runs once on dev Docker and on Strato, and a follow-up commit deletes it (D9).
- G6. Ingest is not slower: no writer makes a request. The JSON of every endpoint is unchanged.

## Non-goals

- **Showing or linking the resolved URL.** The card shows and links the URL as posted (ruling).
- **A shortener host list**, or any table entry taken from the current news window.
- **Retrying a final failure.** A refusal, a loop or a hop overrun is final at once; only a transient failure is
  retried (D6).
- **Reusing the share sweep's page read** as a free resolution. Costed under *Optional scope*.
- **Resolving links of two or more path segments** (`threads.com/share/…`). Measured, they bring no outlet match
  (R2); costed under *Optional scope*.
- **Carrying the resolution in a backup.** A restored row is queued again (D7).

## Anti-goals

- A resolver that slows ingest. No request happens on a write path.
- An open redirect-following proxy. No endpoint takes a URL to resolve. The pass follows only URLs already stored as
  card URLs, every hop goes through `UrlGuard`, and the landing is stored, never fetched again and never sent to a
  client (D11).

## Research

Dev MySQL `feedreader`, read only, 2026-11-15 (6,148 `shared_link` rows; the week is 2026-11-08 12:00 to 11-15 by
the card's entry `created_at`: 4,461 rows behind 6,724 cards). Scripts and raw results are in the session scratchpad
under `2236/`: `dump.php`, `stats.py`, `sample.py`, `resolve.php`, `analyze.py`, `value.php`.

### R1. Path shapes

| Week rows by path segments | Rows |
|---|---|
| 0 (a front page) | 109 |
| 1 | 1,346 |
| 2 | 1,334 |
| 3 or more | 1,672 |

Among the one-segment rows, 1,026 have a segment with neither a `.` nor a `-` (an opaque token such as `4kcyxuV`,
`!6227406`, `watch`); the other 320 are slugs and files (`capy-reader-…`, `atlas_des_flux….htm`). Over all rows the
counts are 1,663 and 1,126.

The one-segment hosts include `trib.al`, `slate.trib.al`, `n.pr`, `bit.ly`, `vist.ly`, `buff.ly`, `youtu.be`,
`kurz.zdf.de`, `link.europa.eu`, `t1p.de`, `c.org`, `tinyurl.com` and `maps.app.goo.gl`, but also `taz.de`'s own short
form, `youtube.com/watch` and plain one-slug articles. They are listed to explain the measurement. No rule uses a host.

### R2. Where a sample lands

A stratified sample of 502 week rows, followed through the app's `RedirectFollower` (a kernel subclass making it
public, `UrlGuard` on every hop, the app's user agent, 3 s idle timeout, 5 hops, a 4 s chain budget), by HEAD and by
GET (`resolve.php`). It holds 20 front pages, 220 one-segment, 100 two-segment and 80 longer rows, plus every row a
French or Spanish post carries, which adds 56. "Outlet match" means the cleaned landing's hash equals an article
feed's `entry.url_hash` in the database. The policy is HEAD, with GET only when HEAD failed or landed unredirected on
a non-2xx status.

| Rows | n | Lands elsewhere, other site | Lands elsewhere, same site | Unchanged | Failed | New outlet matches |
|---|---|---|---|---|---|---|
| front page | 21 | 4 | 2 | 15 | 0 | 0 |
| one segment | 237 | 125 | 22 | 88 | 2 | **46** (30 other site, 16 same site) |
| two or more segments | 244 | 11 | 8 | 193 | 32 | **0** |

- **Two or more segments bring nothing.** All 19 landings are host moves or slug fixes with no outlet entry:
  `cnn.com` → `edition.cnn.com`, `twitter.com` → `x.com`, Substack → its custom domain, Bridgy, six
  `news.netasgard.com/a/<id>` → `us-es.news.netasgard.com/a/<slug>-<id>` (Spanish aggregator posts), `m.youtube.com`
  → `www.youtube.com`. Following every row would cost 4,461 requests a week for these.
- **The no-`.`/no-`-` refinement keeps every match.** Of the 237 one-segment rows, the 174 with an opaque segment
  hold all 46 outlet matches, 141 landings (81 %) and only 33 unchanged rows. The 63 slug or file rows hold 55
  unchanged rows and no match.

| Candidate rule on the one-segment sample | Selected | Landings | Unchanged | Outlet matches |
|---|---|---|---|---|
| one segment | 237 | 147 | 88 | 46 |
| one segment, no `-` | 182 | 141 | 41 | 46 |
| **one segment, no `.` and no `-` (chosen, D1)** | **174** | **141** | **33** | **46** |
| one segment, no `.`, no query | 198 | 140 | 57 | 46 |

- **Same-site landings matter, and they are German.** 16 of the 46 matches are `taz.de/!6227406` →
  `taz.de/Zehn-Jahre-Nein-heisst-Nein/!6227406/`: taz's article feed publishes the long form, its social accounts post
  the short one. Two more are Deutschlandfunk slug changes (`…klingbeil-100.html` → the retitled article), which the
  two-or-more-segment rows show but D1 does not select.
- **The final status does not matter.** All 20 sampled `bit.ly` links answer `301` to `theatlantic.com/…`, which then
  answers `403` to our user agent. The landing URL is the article, and it matches The Atlantic's feed entries. A rule
  that kept only 2xx landings would lose every one.
- **HEAD is enough.** On the 174 chosen rows, GET was needed twice: `link.europa.eu` answers HEAD with `429`, and GET
  lands on `commission.europa.eu`. HEAD and GET disagree on 4 of 502 landings (Google Maps' locale host, `twitter.com`
  → `x.com`), none of them a match.
- **Latency of the chosen policy** on the 174 rows: mean 0.49 s, median 0.38 s, p95 1.13 s, max 3.16 s; 85 s for all
  174.
- **Interstitials.** No sampled one-segment landing is a consent, login or challenge page. The only one in the sample
  is `derstandard.de/story/…` → `derstandard.de/consent/tcf/story/…`, a three-segment row that D1 does not select. No
  two short links land on one URL, and no landing is a front page.
- **French and Spanish.** French posts carry 22 one-segment cards a week and Spanish posts 9 (of 1,724). The sampled
  French ones are English articles behind `trib.al` and `slate.trib.al`. The Spanish ones are Google Maps places and
  a `tinyurl.com` blog. No French or Spanish outlet match was found: on the dev data the gain is English and German.

### R3. Who writes the candidates

The 1,026 week rows of D1's shape come from Bluesky 857 (84 %, payload cards, never fetched), Mastodon 67, Lemmy 67,
RSS 23 and both Bluesky and Mastodon 11. Only Mastodon and Lemmy cards built by the share sweep pass through a page
read, so the sweep's free landing would cover at most about 134 of them a week.

### R4. Volume

The 1,026 week rows come to about 150 new candidates a day, about 6 an hour. At the measured 0.49 s mean, resolving
them takes about 75 s of the worker's day. The backfill holds 1,126 rows, about 9 minutes at that mean.

## Approaches considered

1. **Resolve at card write.** Every writer would make a request in its own transaction: Bluesky ingest and restore
   included. This contradicts the anti-goal, and 84 % of candidates come from payload cards on the ingest path.
   Rejected.
2. **Piggyback on the share sweep's page read** (`PageHeadModel::finalUrl`). This is free where it applies, but it
   covers at most 13 % of candidates (R3), and Bluesky cards are never read. On its own it is insufficient; as an
   addition it is costed under *Optional scope*.
3. **A queued state on the row, drained by a budgeted background pass.** Writers only mark the row. A pass follows a
   fixed number of rows per tick, in id order. **Chosen** (D5).

## Design

### Schema

*(Revised in implementation; D16.)* The resolution is a 1:1 table, `shared_link_resolution`, mapped by the entity
`App\Entity\SharedLinkResolution`. A row exists only for a candidate: no row is the "not of the short-link shape, or
written before the backfill" case.

| Column | Type | Notes |
|---|---|---|
| `shared_link_id` | INT, PK, FK `shared_link (id)` ON DELETE CASCADE | the link; its GC takes the row with it |
| `status` | VARCHAR(10), `enumType: App\Enum\LinkResolution` | `pending`, `resolved`, `unchanged`, `unresolved` |
| `resolved_url` | VARCHAR(2048), null | the cleaned landing (`UrlNormalizer::withoutTracking`); set exactly when `resolved` |
| `resolved_url_hash` | VARCHAR(64), null | `UrlNormalizer::hash(resolved_url)`; set exactly when `resolved` |
| `attempts` | SMALLINT, default 0 | attempts made (D6) |
| `retry_at` | DATETIME, null | when a deferred row is due again (D6) |
| index `idx_shared_link_resolution_status` (`status`) | | serves the pending read |

One migration, `Version20261115220000`, platform-aware like `Version20261115140000`: a `CREATE TABLE` (plus the FK on
MySQL). On SQLite the key column is declared `INT`, not `INTEGER`, or the rowid alias makes `doctrine:schema:validate`
diff the table. No existing table is touched, so the `entry_search` triggers are untouched. `down()` drops the table.
No data step.

`App\Enum\LinkResolution: string { Pending = 'pending'; Resolved = 'resolved'; Unchanged = 'unchanged'; Unresolved =
'unresolved'; }`. It lives in `App\Enum` because the entity maps it (CLAUDE.md, *Shared values have one home*).

`SharedLink` is unchanged (11 fields). `SharedLinkResolution` has getters only: `SharedLinkResolutionRepository`
writes it in SQL.

### The short-link shape (D1)

`App\Service\Foundation\Url\Support\ShortLinkShape::fits(string $url): bool` is true when the URL's path, without
leading and trailing slashes, is exactly one segment, and that segment contains neither `.` nor `-`. The host and the
query are not read. It lives in `Foundation/Url` next to `TrackingFreeUrl`, so the LinkCard writer and the backfill can
both use it without a module cycle.

### Queuing (G1)

- `SharedLinkWriter::linksFor()`, after `insertMissing()`, calls
  `SharedLinkResolutionRepository::queueByUrlHashes()` with the hashes of the missing rows whose URL
  `ShortLinkShape::fits()`: one `INSERT IGNORE INTO shared_link_resolution … SELECT id, 'pending', 0 FROM shared_link
  WHERE url_hash IN (…)`.
- `NewSharedLinkModel` is unchanged.

Every writer creates rows through `linksFor()` → `insertMissing()`: Bluesky embeds, Mastodon and Lemmy payload cards,
the share sweep, quoted cards, thread posts and backup restore. So every new row is covered by this one change. A
known row is reused untouched, so a later share never queues or resolves it again (#2239's store-once rule). A row
another writer won the insert race for may be queued by both; the second insert is ignored.

### Resolving one URL (G2) — `Service/Content/LinkResolution/LinkLanding`

`LinkLanding::of(string $url): LinkLandingModel`, a root service of the new module `Content/LinkResolution`, injects
`RedirectFollower`, `UrlNormalizer` and the outbound `$userAgent`.

1. `followHead($url, options, new RedirectBudget(5, 3.0))`. Options: `timeout` 3.0 s, `buffer` false, headers
   `User-Agent` and `Accept: text/html,*/*;q=0.8`. The response is cancelled at once, so no body is read.
2. GET fallback, with a fresh budget and the same options, cancelled at once, only when HEAD threw
   `RedirectChainException` for a reason other than a transport error, or when HEAD landed on the URL it started from
   with a status outside 2xx (`405`, `429`, `403` on HEAD alone). A HEAD that timed out or lost its connection gets no
   GET: it would repeat the same wait (review I1).
3. The outcome:
   - the last attempt threw on a transport error (timeout, lost connection: a `TransportExceptionInterface` in the
     cause chain), or landed unredirected on the posted URL with `429` or `5xx` → `LinkLandingModel::retryable()`
     (resolution `Pending`; D6);
   - the last attempt threw otherwise (a blocked hop or DNS failure in `UrlGuard`, a loop, a missing `Location`, a
     spent chain budget) → `LinkLandingModel::unresolved()`;
   - the landing, cleaned, hashes like the URL → `LinkLandingModel::unchanged()`;
   - the cleaned landing is longer than `LinkCardContent::URL_MAX` (the `resolved_url` column) →
     `LinkLandingModel::unresolved()`: strict MySQL would refuse the row, and it would head the queue on every pass
     (review C1);
   - otherwise → `LinkLandingModel::resolved($cleanedLanding, $hash)`, whatever the landing's status (D3).

`LinkResolutionSweep` settles a row `unresolved` when its attempt throws anything else, logs
`Link resolution failed unexpectedly.` with the link id, URL and exception, and goes on with the next row, so no row
can wedge the head of the queue or, on Strato, the maintenance sweeps after it.

`LinkLandingModel` (`Model/`, `final readonly`): `LinkResolution $resolution`, `?string $url`, `?string $urlHash`, with
the four named constructors. `RedirectFollower` already runs `UrlGuard` on every hop, forces `max_redirects` to 0,
and turns a blocked hop, a loop past five hops, a missing `Location` or a timeout into `RedirectChainException`. So a
private address and a loop end as `unresolved`, and a timeout as `retryable`.

### The pass (G2, G3) — `LinkResolutionSweep`

`LinkResolutionSweep::resolvePending(LinkResolutionBudgetModel $budget): LinkResolutionReportModel`:
- reads `SharedLinkResolutionRepository::pendingAt($now, $budget->links)`, which returns `list<PendingLinkModel>`
  (link id, URL, attempts): `status = 'pending'` and `retry_at` null or due, the lowest ids first;
- for each row, while `$budget->fitsAfter(elapsed)`, resolves it with `LinkLanding::of()` and settles it at once:
  - a settled landing → `settle($link, $landing)`;
  - a retryable landing with fewer than 3 attempts → `defer($link, now + 1 h)`, which adds an attempt and sets
    `retry_at`;
  - the third retryable attempt → `settle($link, unresolved)`;
- reports considered, resolved, unchanged, unresolved, deferred and the seconds spent.

`settle()` and `defer()` are each one `UPDATE shared_link_resolution … WHERE shared_link_id = ? AND status =
'pending' AND attempts = ?` (the attempts read). A second pass that resolved the same row concurrently, or a stale
read, writes nothing (D14).

`LinkResolutionBudgetModel` (`Model/`) mirrors `LinkCardBudgetModel`:
- `links(int $count)`: a count of resolutions, each budgeted at `LinkLanding::MAX_SECONDS` = 6.0 (two attempts of
  3 s). This is the nominal cost, not a bound: see D4;
- `fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxLinks)`;
- `fitsAfter(float $elapsedSeconds): bool`, true while one more worst-case resolution fits;
- `isEmpty()`.

`LinkResolutionReportModel` (`Model/`): `considered`, `resolved`, `unchanged`, `unresolved`, `deferred`, `seconds`,
with `none()`, `counted()`, `plus()` for the backfill's sum, and `toLogContext()`.

### Running the pass (D5)

Both deployments get it, as the link-card sweep does:
- **Worker** (Docker): message `ResolvePendingLinks` and `ResolvePendingLinksHandler`, with
  `LinkResolutionBudgetModel::links(5)`, logging `'Worker link resolution finished.'` with the report. It is appended
  **last** to `WorkerSchedule` (`RecurringMessage::every('1 minute', …)`), because the stateful checkpoint stores a
  position in the list.
- **Maintenance tick** (Strato): `LinkResolutionsTickSweep`, `#[AsTaggedItem(index: 'linkResolutions', priority:
  65)]`, so it runs after `linkCards` (70) and the rows the sweep just wrote can resolve in the same tick. Its budget
  is `fittingBefore($deadline, now, 5)`. `idleReport()` is `none()`.
- `App\Http\SweepReportJson\LinkResolutionSweepJson` maps the report for `/maintenance/tick`: `considered`,
  `resolved`, `unchanged`, `unresolved`, `deferred`. The tick JSON is an admin surface, so clients are unaffected.

Five per minute is about 300 an hour, against about 6 candidates an hour arriving (R4). The nominal budget is 30 s a
pass and the measured mean is 2.5 s; a pass of timing-out links can run longer (D4).

### Trending reads the landing (G4, D8)

Two SQL reads change, and nothing else in Trending. Each adds `LEFT JOIN shared_link_resolution landing ON
landing.shared_link_id = link.id` (a primary-key lookup):
- `TrendingEntryRepository::chunkAfter`: `COALESCE(landing.resolved_url_hash, link.url_hash) AS card_url_hash` and
  `COALESCE(landing.resolved_url, link.url) AS card_url`.
- `TrendingStoryRepository::shownEntriesByStory`: `COALESCE(landing.resolved_url_hash, link.url_hash) AS url_hash`.

`WindowLinkCardModel` is unchanged, and now sees the landing:
- `urlHash` feeds `TrendingGrouping::urlHashesOf`, so an `n.pr` post and NPR's own entry share a URL and link
  directly;
- `site` (`ArticleSite::of`) feeds `EntryLinksModel::articleSite()` and the snapshot's `article_site`, so `n.pr` counts
  as `npr.org` and each `trib.al` link counts as its outlet;
- `namesAStory()` judges the landing, so a short link that lands on a front page is no story card.

`TrendingStories::articleKeyOf` keys a post's row by the snapshot's link hash, now the landing's. A post sharing an
outlet's article folds onto that outlet's row, which removes the #2229 duplicate rows. The card's preview, its
`url`, and every JSON mapper still read `shared_link.url`.

### Backfill (G5, D9) — one-off

- `app:shared-links:resolve` (`ResolveSharedLinksCommand`) → `Service/Operations/LinkResolutionBackfill/
  SharedLinkResolutionBackfill`.
- `count()` (`--dry-run`) walks every link without a resolution row by id, in batches of 500
  (`SharedLinkResolutionRepository::unqueuedUrlsAfterId`), and counts those that `ShortLinkShape::fits()`.
- `run()` queues them (`SharedLinkResolutionRepository::queue(list<int>)`, one `INSERT IGNORE … SELECT … WHERE id IN
  (…)` per batch). It then drains through `LinkResolutionSweep::resolvePending(LinkResolutionBudgetModel::links(50))`
  until a pass considers nothing due, and sums the reports. Rows deferred by a transient failure are left to the
  worker or the tick, which retry them an hour later (D6).
- Output: `Queued %d; resolved %d, unchanged %d, unresolved %d, deferred %d in %.1f s (%.2f s a link)`.
- Re-running is safe: queued and settled rows have a row, and a pending row left behind by an interrupted run is
  drained.
- Runs once on dev Docker and once on Strato after the deploy. The follow-up commit deletes the command, the service,
  its report model (if one is added) and the two repository methods used only by it.

### What the client sees

Nothing changes. Cards, Trending stories and backups serialise `shared_link.url` and its preview as before. Native
iOS needs nothing. A story can now show one row where it showed two, and a story can appear or leave because its site
count changed: that is the intended effect, and it is measured.

## Decisions

Lars delegated every decision. Each is "what — why — cost if wrong".

- **D1. Candidates are URLs whose path is one segment with neither `.` nor `-`; host and query are not read.** — R2:
  this selects 174 of 237 one-segment rows and keeps all 46 outlet matches, against 0 matches in 244 rows of two or
  more segments. A short-link token is an opaque id: no word separator, no file extension. — If wrong: a shortener
  with two segments (`threads.com/share/…`) or a hyphenated token is never resolved, and Trending treats it as today.
  Widening is one line in `ShortLinkShape`.
- **D2. A landing is stored whenever its cleaned hash differs from the posted URL's, same site included.** — 16 of the
  46 matches are `taz.de`'s short form landing on its own long form, the form taz's article feed publishes (German).
  — If wrong: a same-site consent or login wall stores a junk URL. Such a URL still carries the article's path, so it
  merges nothing, and a front-page landing stops being a story card. The cost is the article key of that row, which
  falls back to no match: today's behaviour.
- **D3. The landing counts whatever its status.** — The chain's last `Location` is where the link points. All 20
  sampled `bit.ly` links land on a `403` from The Atlantic at the article's URL, which matches its feed. — If wrong: a
  link to a removed article stores the removed URL. It matches nothing and harms nothing.
- **D4. HEAD, then GET only if HEAD failed for a reason other than a transport error, or landed unredirected on a
  non-2xx status; no body read; 3 s idle timeout, 5 hops, a 3 s `max_duration` budget per chain.** — R2: GET was
  needed for 2 of 174 rows (a `429` and a `405`, never a timeout), and the HEAD/GET landings differed on 4 of 502, none
  a match. `PageLanding::MAX_REDIRECTS` is also 5. **The 6 s of `MAX_SECONDS` is a budget, not a bound.**
  `max_duration` applies per request, and `FailoverRequestSender` re-sends a hop that fails on the transport over each
  route (the egress proxy, then each pinned address family), each with the chain's whole remaining time. A link that
  times out therefore costs up to about 3 s per route on its HEAD, plus a GET chain of the same shape when HEAD failed
  otherwise: the backfill measured 11.6–18 s for 3 of 1,128 links before the transport-error GET was dropped. The
  budget checks the real elapsed time before each link, so a pass overruns its deadline by at most one such link. A
  wall-clock bound per attempt would need the follower to stop a hop mid-failover, which its API does not offer; it is
  left out. — If wrong: a shortener slower than 3 s per chain stays unresolved, and on Strato's dead IPv4 family a tick
  can run one slow link past its window (watch `/maintenance/tick` during the Strato backfill).
- **D5. A pending state drained by a separate budgeted pass (worker every minute, and the maintenance tick), 5 links
  a pass.** — No write path makes a request (anti-goal), and 84 % of candidates are Bluesky payload cards with no
  fetch to piggyback (R3). A separate sweep gets its own budget against the tick deadline, as `imageVerification` and
  `linkCards` do. — If wrong: a card resolves up to a minute after it is written, which the hourly Trending rebuild
  absorbs. A spike above about 300 candidates an hour queues up and drains later.
- **D6. Up to three attempts for a transient failure, one for any other; `unresolved` is final; no row means not a
  candidate.** *(Controller ruling, 2026-11-15.)* A transient failure (a timeout or lost connection, or the posted URL
  itself answering `429` or `5xx`) leaves the row pending with `attempts + 1` and `retry_at` an hour on, at most twice;
  the third such attempt settles `unresolved`. A refusal (`UrlGuard`, DNS failure included), a loop, a missing
  `Location` or a hop overrun settles `unresolved` at once. A redirect elsewhere counts whatever its status (D3). — The
  backfill fires about 300 `trib.al` requests back to back; a `429` there would otherwise lose real matches for good.
  The four states keep the measurement countable. — If wrong: a shortener throttling for three hours running loses
  its rows (today's behaviour), and a host whose DNS fails once is never retried.
- **D7. The writer queues at insert, in `SharedLinkWriter::linksFor()`; restore re-queues.** — One place covers every
  writer (#2239's single writer). A backup does not carry the resolution, like the language (#2239 D10). — If wrong:
  a restored instance re-resolves its short links: about 1,100 requests, drained at 5 a minute.
- **D8. Trending reads `COALESCE(resolved, posted)` in its two SQL reads; no PHP in Trending changes.** — The window
  model already derives the hash, the site and the story-card rule from the URL it is given, so one source covers
  grouping, site count and article key alike. — If wrong: the story-card rule judges the landing, not the posted
  URL. That differs only when a short link lands on a front page, which is not a story.
- **D9. The backfill is a one-off command over every unqueued row, not only the week's: it queues and drains.** —
  This is Lars's ruling. All rows, because new posts reuse old rows (#2239 R2: 35 %); the week holds 1,026 of the
  1,126 rows anyway. The drain reuses the pass, so the backfill follows the same code the worker runs. — If wrong:
  the run takes about 9 minutes on dev, and a rate-limiting shortener answers `429` to some of 300 back-to-back
  requests. Those rows are deferred and retried (D6), and the run's report counts them.
- **D10. Placement: `Foundation/Url/Support/ShortLinkShape`; a new module `Content/LinkResolution` (`LinkLanding`,
  `LinkResolutionSweep`, four models); `App\Enum\LinkResolution`; `App\Entity\SharedLinkResolution` and its repository; the backfill in `Operations/LinkResolutionBackfill`.**
  — `LinkCard` (the writer) needs only the shape, and `LinkResolution` needs only the repository and `Outbound/Fetch`,
  so no module cycle forms. — If wrong: a later move of the module.
- **D11. Not an open proxy.** — No route accepts a URL, the pass reads only stored card URLs, and every hop passes
  `UrlGuard`. The landing is never fetched again and never reaches a client. — If wrong: none known. The SSRF tests
  pin a private-address hop as `unresolved`.
- **D12. The stored landing is `UrlNormalizer::withoutTracking()`, and its hash is `UrlNormalizer::hash()`.** — These
  are the same functions as `entry.url_hash` and `shared_link.url` (#2237), so a match is byte-exact. `utm_*` (and
  YouTube's `feature`/`si`) drop. **True Anthem's `link_source` and `taid` do not**: the #2237 cleaner keeps them, so
  101 measured landings keep them (95 The Atlantic via `bit.ly`) and 88 matches with The Atlantic's feed are missed.
  R2's claim that they drop was wrong. *(Controller ruling, 2026-11-15:)* the cleaner's tiers stay unchanged in this
  branch; a follow-up issue for the two keys goes to Lars. — If wrong: none beyond #2237's own cleaner.
- **D13. The share sweep's page read is not reused as a resolution.** — It covers at most about 134 candidates a week
  (R3) and needs `PageCardModel` plumbing. — If wrong: about 134 HEAD requests a week that could have been free.
- **D14. No claim lease; a conditional `UPDATE … WHERE status = 'pending' AND attempts = <read>` settles or defers a
  row once.** — Only the worker or the tick runs on one deployment. The backfill can overlap the worker on dev, which
  costs a duplicate request, never a second write. — If wrong: a few duplicate HEAD requests while the backfill runs.
- **D15. No `resolved_at` column; `retry_at` is set only while a row waits for a retry.** — Nothing reads a settle
  time, and the report and log give the per-pass figures. — If wrong: a later policy adds one column.
- **D16. The resolution is its own 1:1 table, `shared_link_resolution`, not three columns on `shared_link`.**
  *(Implementation ruling.)* D6 needs two more values (attempts, next attempt), which takes `SharedLink` to 16 fields
  against PHPMD's 15. An embeddable fold fails PHPStan (phpstan-doctrine reads no embeddable metadata, so SQL-only
  fields read as never written), and splitting the language off `SharedLink` would be a count-driven split (#2025).
  The table holds a row only for a candidate (about a fifth of the links), cascades with the link's GC, and leaves
  `SharedLink` with room for its next column. — If wrong: one primary-key join per card in the two Trending reads, and
  a second insert per writer batch that has a short link.

## Optional scope, costed and not built

- **Retry final failures** (a DNS failure, a refusal) later: drop the distinction in `LinkLanding::isTransient()`.
  Build it if the unresolved hosts show transient DNS failures.
- **The sweep's free landing** (D13): pass `PageIdentityModel::finalUrl` through `PageCardModel` into the writer, and
  store `resolved` or `unchanged` at insert. This saves about 134 requests a week.
- **Two-segment candidates:** widen `ShortLinkShape`. R2 found 0 outlet matches in 244 rows, and it would cost about
  1,300 requests a week.
- **An interstitial guard** (a landing whose query carries the posted URL). R2 found none among the candidates.
- **Linking the card to the landing**, or showing it in the reader overlay. This is a client change, against the
  ruling.

## Measurement plan

A = develop at 17edfbfeb on `feedreader_2236_a`. B = HEAD on `feedreader_2236_b`, migrated and backfilled. Both are
restored from one `mysqldump` of `feedreader`, so the worker cannot move the window between them. Pin
`2026-11-15 12:00:00` (or the run day's equivalent, the same for both). Scripts go in the scratchpad under
`2236/final/`.

- **Migration:** wall time on B; `doctrine:schema:validate` in sync; from empty on SQLite and MySQL; the six FTS5
  triggers still present on SQLite.
- **Backfill (ingest cost):** dry-run count (expected about 1,130), then the run: queued, resolved, unchanged,
  unresolved, wall time, mean and p95 seconds per link, peak RSS; a second run queues 0. Resolution outcomes are
  listed by the posted host of the failures, to spot rate limiting.
- **Value:** on B, the week rows whose resolved hash equals an article feed's `entry.url_hash` (R2 predicts about a
  quarter of the resolved rows), and the week's posts that now share a URL hash with an outlet entry in the window.
- **Trending A/B:** groups and grouped pairs, new / lost / net, for day and week, and French-only and Spanish-only day
  and week (the #2235 `run.sh` language filter), per language pair (de–de, en–en, fr–fr, es–es, mixed or none). Each
  run gets `/usr/bin/time -l`, and the headers must show equal entry counts. Every changed group is traced to a resolved
  row; a change with no named cause is a finding.
- **Site count:** stories whose distinct `article_site` count changes, A → B, with their sites, and stories that
  cross the two-sites threshold either way.
- **Duplicate rows:** for user 2, the stories whose B card shows fewer article rows than A's, each checked by eye
  (the `n.pr` / `trib.al` / `bit.ly` / `taz.de` cases).
- **Who is shown:** user 2's day and week top 15, A → B, rebuilt at the pin, by language.
- **Eye check:** 30 resolved rows (at least 10 same-site, and every French- or Spanish-post row) and every
  `unresolved` row's host, each called right or wrong.
- **Worker:** on the dev stack after Lars's deploy, today's dev log `Worker link resolution finished.` lines: links a
  pass, seconds a pass, failures.

## Risks

- **Live blogs merge unrelated posts.** The hash drops the fragment (#2237), so short links to one live blog's
  entries (`…/live-updates/#id_1330182`) share one URL, and unrelated posts link through it: 9 `vist.ly` links to one
  POLITICO Europe live blog in the measurement. Two direct links to one live blog already merge today; a
  fragment-identity rule belongs in its own issue.
- **`MAX_SECONDS` is a budget, not a bound** (D4). A pass can overrun its deadline by one timing-out link.

- **Rate limiting during the backfill** (D9). About 300 `trib.al`/`slate.trib.al` links run back to back. A `429`
  defers a row, retried twice an hour apart (D6). The report shows deferred rows, and the outcome lists them per host.
- **Strato's egress.** Strato has a known dead IPv4 family for some hosts (memory: "Dead-family fetch"). The failover
  sender covers it as it does for feeds. Unmeasured there until the Strato backfill.
- **Bot blocking of the user agent** by a shortener itself (none in the sample: `bit.ly` redirects, and its *target*
  blocks).
- **A shortener that changes its target later** keeps the first landing. That is the store-once ruling.
- **`SharedLinkResolution` is written in SQL only.** Its getters serve tests; a reader that needs it in PHP finds it
  by the link's id.

## Outcome

Measured 2026-11-15 on copies of dev `feedreader` (A = develop 17edfbfeb on `feedreader_2236_a`, B = the branch on
`feedreader_2236_b`, one dump, Trending pinned at 2026-11-15 12:00:00 UTC). Scripts and raw output are in the session
scratchpad under `2236/final/`.

- **Migration:** 0.45 s wall on B (one `CREATE TABLE`); `doctrine:schema:validate` in sync. From empty: SQLite
  (6 FTS5 triggers present) and MySQL both migrate and validate; MySQL `down` then `up` again validates.
- **Backfill:** dry run 1,128 (spec expected about 1,130). Run: queued 1,128; resolved 892, unchanged 228,
  unresolved 0, deferred 8; 553.8 s wall, mean 0.49 s, median 0.39 s, p95 1.01 s, p99 1.92 s, max 12.1 s a link;
  peak RSS 84 MB. A second run queued 0 and considered 0. No shortener answered `429` (174 `trib.al`, 130
  `slate.trib.al`, 140 `n.pr`, 102 `bit.ly`: all landed). The 8 deferred: 4 chains whose target timed out on our
  agent (`reut.rs` ×2, `wapo.st`, a `bit.ly` to fresnobee.com), 2 grimme-online-award.de, 1 fulcrumsec.vg (dead),
  1 wiki.cateat.fish (DNS). The first four led to the hop-landing rule (D6 ruling in the ledger): replayed with it, 6
  of the 8 resolved, 2 stay deferred (the dead host and the DNS failure).
- **Three links took over `MAX_SECONDS` (6 s):** 11.6 to 18 s, both attempts timing out across the fetcher's
  failover routes. The pass measures real time, so a worker pass can overrun its nominal 30 s.
- **Value:** 870 week rows resolved; 340 of them (39 %) now hash like an article feed's entry (de 157, en 183, fr 0,
  es 0). 484 week posts share a URL hash with an outlet entry in the window (de 214, en 270). 726 landings are on
  another host, 172 on the same (taz.de's long form).
- **Short of D12:** the #2237 cleaner keeps True Anthem's `link_source` and `taid`, so 101 landings (95 The Atlantic
  via `bit.ly`) keep them. Without those two keys 88 more would match The Atlantic's feed entries. Not changed here
  (the key tiers are #2237's rulings).
- **Trending A/B:** day 878 → 878 groups, 3,366 pairs, no change (the shares were already grouped by their card
  headline). Week 3,949 → 3,945 groups, 20,208 → 20,136 pairs: new 235, lost 307 (en–en +230/−284, de–de +2/−18,
  mixed +3/−5, fr–fr and es–es 0). French-only and Spanish-only day and week: identical. Equal entry counts on every
  pair of runs; run time and peak RSS equal (week 81.8 / 81.4 s, 256 MB). Of 52 changed week groups, 40 hold a
  resolved card and 9 more share members with one that does; 3 are second-order regroupings with no resolved member
  (a Slate pair, the Sean Duffy group, a Guardian pair).
- **Live blogs merge posts:** 9 `vist.ly` links land on one POLITICO Europe live blog with a different `#id_…`
  fragment each; the hash drops the fragment, so unrelated posts (Merz, Stocker, Vannacci) now share a URL. 11
  fragment landings in all, 2 distinct pages.
- **Site count:** shortener sites in snapshots 22 → 0 (day), 50 → 0 (week). Stories with two or more sites: day 180
  → 169, week 230 → 227. 14 stories fall below two sites, each an outlet counted twice through its own shortener
  (`n.pr` + npr.org ×8, `slate.trib.al` + slate.com ×2, `bit.ly` + theatlantic.com ×2, `trib.al` + futurism.com ×2,
  `youtu.be` + youtube.com); one rises (`nbcnews.to` → nbcnews.com beside theguardian.com).
- **Duplicate rows (user 2):** 4 day and 7 week top-15 stories show fewer article rows on B. Each was a Bluesky post
  row (an `n.pr` or `taz.de` share) that now folds onto the outlet's row; checked by eye.
- **Who is shown:** user 2's day and week top 15 are the same stories in the same order. Only the site lists and
  row counts change.
- **Eye check:** 30 resolved rows (10 same-site, 20 other-site): all right. French and Spanish rows (7): right,
  apart from one YouTube handle that gained `?cbrd=1&ucbcb=1` (consent-redirect keys; same page). 3 landings are
  front pages (two `buff.ly` campaigns, one tool site), which correctly stop naming a story.
- **Dev run** (`feedreader`, after copying it to `feedreader_2236_before`): see the ledger.
