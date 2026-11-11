# Feed directory (#2176)

## Problem

Finding new sources today means knowing a URL or picking from the short, curated onboarding catalog
(`/discover`). InfoBubble publishes an open directory of ~42,000 news sites and podcasts across 231
countries, each with a rank, languages, average article length, an AI-written summary and its feeds by
category. Readers cannot browse it from here, and the planned AI feed recommender has no pool of
candidates to draw from.

## Goal

A **feed directory**: a list view modelled on <https://infobubble.github.io/infobubble-support/catalog.html>,
scoped to the countries and languages each user has chosen, where any feed is one tap from the existing
add-feed dialog. The data is a file snapshot in the repository, split per country, so neither the API nor
the later recommender ever reads the whole directory.

Out of scope: the recommender itself, multi-select and bulk subscribe, OPML/CSV export, an inline feed
preview in the list (the add-feed dialog already previews), feed titles, feed liveness checks, any change
to the onboarding catalog.

## Name

Everything is called **FeedDirectory**, kept apart from the onboarding `Catalog`
(`Service/Catalog`, `/api/catalog`, `/discover`) and from the many `…Source` classes.

| Piece | Name |
|---|---|
| PHP module | `App\Service\FeedDirectory` |
| Controller | `Controller/Api/FeedDirectoryController` |
| API | `/api/feed-directory/…` |
| Data and snapshot script | `backend/resources/feed-directory/` |
| Frontend area and route | `frontend/src/app/feed-directory/`, `/feed-directory` |
| Preference fields | `feedDirectoryCountries`, `feedDirectoryLanguages` |

## Data

### Source and licence

The InfoBubble page loads `docs/data/catalog.js` from `infobubble/infobubble-support`: one
`window.CATALOG_DATA={…}` assignment around plain JSON (12 MB, 42,020 domains, 86,786 channel and 13,452
podcast feeds). It is derived from the repository's `Sources/` database, which is licensed **ODbL**. The
snapshot ships with the upstream `Sources/ABOUT.txt` and `Sources/LICENSE.txt`, and the directory page
carries the attribution InfoBubble's footer carries (InfoBubble, GDELT Project, Tranco, Majestic Million
CC BY 3.0).

Upstream shape, kept as it is inside each country file:

```
countries:  string[]            // display names, 231
categories: string[]            // 9: "World / International", "Politics & Society", …
languages:  string[]            // ISO 639-1 codes, 50
domains:    [host, countryIndex, rank|null, avgArticleLength|null, languageIndexes[], groups[], summary|null][]
groups:     [categoryIndex, isPodcast (0|1), feedUrls[]][]
```

24,559 domains have no rank, 831 no article length, 2 no summary; every reader of the data treats those
fields as nullable.

### Split per country, committed

`backend/resources/feed-directory/` holds:

- `countries/<key>.json.gz` — one file per country: `{ "country": key, "categories": […], "languages":
  […], "domains": [ … ] }`. Domain tuples keep the upstream positions, but `categories` and `languages`
  are the file's own lists, so a file is self-contained. Each domain belongs to exactly one country, so no
  domain is duplicated. Largest: United States, 4,682 domains, 325 KB gzipped; median 2.5 KB.
- `index.json` — `{ "countries": [ { "key", "name", "languages": { "<code>": { "channels": n,
  "podcasts": n } } } ], "languages": [codes] }`. Enough to drive the scope picker with counts and to
  know which country files hold a language, without opening any of them.
- `ABOUT.txt`, `LICENSE.txt` — copied from upstream `Sources/`.
- `SOURCE` — the upstream URL, the commit SHA of `docs/data/catalog.js`, and the fetch date.
- `snapshot.php` — see below.

The **country key** is the ASCII slug of the display name (NFKD, lower-case, runs of non-alphanumerics to
`-`, trimmed): `cote-d-ivoire`, `bosnia-herzegovina`, `congo-brazzaville`. All 231 slugs are distinct.
Display names stay upstream's (`Côte d’Ivoire`).

### Snapshot script

`backend/resources/feed-directory/snapshot.php` is a standalone PHP CLI script, run by hand when someone
decides to update the data: it downloads `catalog.js`, `ABOUT.txt` and `LICENSE.txt`, unwraps the JSON,
writes the per-country files and `index.json`, and rewrites `SOURCE`. It is not wired into deploys, CI,
the worker or the console; the result is reviewed and committed like any other change. It runs once to
produce the first snapshot.

### Identity

Everything that persists or crosses a boundary names a country by its **key**, a domain by its **host**
and a feed by its **URL** — never by an array position, since a new snapshot reorders the lists.

## User scope

The directory is **hard-scoped** to the user's choice: it shows only the chosen countries, and within them
only domains with at least one chosen language. Choosing is required — **at least one country and one
language**. Until both are set, the directory page shows the scope picker instead of the list.

### Storage

`Preferences` gains two JSON list columns, `feed_directory_countries` (country keys) and
`feed_directory_languages` (language codes), both defaulting to an empty list. One migration, verified on
SQLite and MySQL by the migrate-from-empty CI leg.

### API

Following the existing pattern of one PATCH per preference in `ReaderPreferencesController`, the scope gets
`PATCH /api/me/feed-directory-scope` with `{ "countries": [keys], "languages": [codes] }`, written through
`AccountPreferencesWriter`, and is read back as a `feedDirectoryScope` object on the `/api/me` profile
(`MeProfileJson`). An update validates every key against `index.json` and every language code against its
`languages`; an unknown value is a `422` problem. Both lists are replaced together and must be both
non-empty or both empty (anything else is a `422`); two empty lists clear the scope, and the directory then
asks again.

### Picker

One standalone component, `FeedDirectoryScopePicker`, used in two places: a new **Feed directory**
section in Settings, and the directory page's empty state. Two multi-select lists with a filter box each:
countries (name and source count) and languages (display name via `Intl.DisplayNames`, and source count).
Counts come from `index.json` and add channels and podcasts together. Saving needs one of each.

## Backend

### `FeedDirectoryFiles`

`Service/FeedDirectory/FeedDirectoryFiles` is the one place that knows the resource layout. It resolves
the index path and a country key to its file path, and rejects a key that is not in the index with a typed
`UnknownFeedDirectoryCountryException` — the key is matched against the index's list, never interpolated
into a path unchecked. It also exposes the known keys and language codes for preference validation. It
never decodes a country file; the recommender will add its own decoding reader next to it.

### Endpoints

All under bearer auth, stateless, JSON or gzip JSON, so a native iOS client can use them unchanged.

| Method and path | Response |
|---|---|
| `GET /api/feed-directory/index` | `index.json` |
| `GET /api/feed-directory/countries/{key}` | the country's `.json.gz` streamed as-is: `Content-Type: application/json`, `Content-Encoding: gzip`, `ETag`, `304` on a matching `If-None-Match`, `Cache-Control: private, max-age=86400` |
| `GET /api/feed-directory/subscribed` | `{ "urls": [normalised feed URLs], "hosts": [hosts] }` of the user's subscriptions |

An unknown country key maps to a `404` problem through a `FeedDirectoryProblems` mapper. PHP streams the
files (`BinaryFileResponse`) and never decodes them, so serving costs no heap.

`/subscribed` reads the user's feed URLs through the existing `FeedRepository::subscribedUrlSetForUser`
and normalises them with `FeedDirectoryUrl`.

### `FeedDirectoryUrl`

A `Support/` helper with the one normalising rule: lower-case scheme and host, `https` and `http` treated
alike, a leading `www.` dropped, a trailing `/` dropped, query kept. It also derives the host. The same rule
is implemented in TypeScript (below); both are tested against **one shared table of cases**
(`backend/resources/feed-directory/url-cases.json`, read by PHPUnit and by Jest), so the two cannot drift.

## Frontend

### Route and entry point

A lazy `/feed-directory` route. It is reached from a **Browse feed directory** link in the add-feed
dialog's footer, next to the existing link to the onboarding catalog.

### `FeedDirectoryStore`

Signals throughout. On open it reads the user's scope; with no scope it stops and the page shows the
picker. Otherwise it loads `index.json`, `/subscribed` and the chosen countries' files in parallel, and
prepares each domain once, like InfoBubble's `prepare()`: lower-cased search text (host and summary),
channel and podcast categories, channel and podcast feed counts, length bucket, and subscribed state.
Domains with none of the chosen languages are dropped at load.

Filter state: mode (channels or podcasts), selected country (one of the scope, or all of it), categories,
length buckets, languages (a subset of the scope), sort, query. Computed: the filtered and sorted rows,
the per-country counts for the sidebar, the total.

- **Length buckets**, from InfoBubble: Headlines under 500 characters, Summaries 500 to under 2,500, Long
  reads 2,500 and over; a domain without a length belongs to none.
- **Sort**: Top (rank ascending, unranked last, then host), A–Z (host), Most feeds (feed count in the
  current mode, then Top).
- A domain with no feeds in the current mode never shows.

### Page layout

Built from `docs/design-language.md` and the shared components, styles in sibling `.scss` files, strings
through transloco.

- **Toolbar**: title, Channels/Podcasts segmented control, filter pills for Category, Length and Language
  (menus in a CDK overlay), sort select, and a link to change the scope in Settings.
- **Sidebar**: the scoped countries with live counts and an "All" row; on narrow screens it collapses into
  a country select in the toolbar.
- **List**: `cdk-virtual-scroll-viewport` with fixed-height rows. A row shows the host, the summary
  clamped to two lines, category chips, languages, the feed count in the current mode, and its state.
- **Footer**: the ODbL attribution.
- **Empty states**: no scope (the picker), nothing matches the filters, a country file failed to load (with
  retry).

### Subscribing from a row

- **One feed in the current mode**: the row's **Subscribe** opens `AddFeedDialogComponent` with
  `{ url }`, which runs discovery at once and offers its tag picker and live preview.
- **Several feeds**: the row's **Subscribe** opens a popover anchored to the row (CDK overlay, so the
  virtual list keeps its fixed row height) listing the feeds by category and URL path, each with its own
  **Subscribe** opening the same dialog.
- When the dialog closes with a subscription, the store marks that feed subscribed and the row updates.

### Subscribed state

The catalog URL is normalised with `feedDirectoryUrl()` (the TypeScript twin of `FeedDirectoryUrl`) and
looked up in `/subscribed`'s `urls`: a match shows the feed as **Subscribed** instead of offering
Subscribe. A row whose host equals, or is a parent domain of, any host in `hosts` reads **Following this
site** (`feeds.bbci.co.uk` follows `bbci.co.uk`); its unsubscribed feeds stay subscribable. Feeds hosted
elsewhere (a site's podcasts on `anchor.fm`) do not count towards the host flag.

## Recommender hand-off

The recommender gets its own spec. Settled here and inherited by it:

- Its candidates are the user's scope: the chosen countries' files, filtered to the chosen languages, minus
  hosts the user already follows. No embeddings. Measured scopes: Germany, Austria and Switzerland in
  German, 1,632 domains (~53k tokens of host and summary); the United States in English, 4,552 (~147k);
  US, UK, Canada and Australia in English, 7,092 (~231k). That is scored in batches on the existing
  `BatchWave` machinery, not in one prompt.
- It reads the files through `FeedDirectoryFiles` and identifies candidates by host and URL.

Left to that spec: batch scoring, checking a feed is alive before recommending it, feed titles.

## Testing

- **Backend**
  - `FeedDirectoryFiles`: known key, unknown key, a key with path characters, the index lists.
  - `FeedDirectoryController` functional tests: bearer auth required; the country file comes back with
    `Content-Encoding: gzip` and an `ETag`, and a matching `If-None-Match` gets `304`; an unknown key gets a
    `404` problem; `/subscribed` returns normalised URLs and hosts for the user's subscriptions only.
  - `PATCH /api/me/feed-directory-scope`: the scope round-trips through `/api/me`; an unknown country or
    language is a `422` problem, as is one empty list beside a non-empty one; two empty lists clear it.
  - `FeedDirectoryUrl` against the shared case table.
  - The suite runs against small fixture files, not the committed snapshot.
- **Frontend (Jest)**: the store (scope gating, language drop at load, every filter, each sort, sidebar
  counts, mode switch), `feedDirectoryUrl()` against the shared case table, the scope picker (requires one
  of each), a single-feed row opening the dialog with its URL, the multi-feed popover, the subscribed and
  following states.
- **Playwright smoke**: with the feed-directory routes stubbed, choose a scope, filter, and open the
  add-feed dialog from a row.
- **Migration**: the CI leg that migrates from empty on SQLite and MySQL.
