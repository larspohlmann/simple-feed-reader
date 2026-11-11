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
| Frontend area and route | `frontend/src/app/settings/feed-directory/`, `/settings/feed-directory` |
| Preference fields | `feedDirectoryCountries`, `feedDirectoryLanguages` |

## Data

### Source and licence

The InfoBubble page loads `docs/data/catalog.js` from `infobubble/infobubble-support`: one
`window.CATALOG_DATA={…}` assignment around plain JSON (12 MB, 42,020 domains, 86,786 channel and 13,452
podcast feeds). It is derived from the repository's `Sources/` database, which is licensed **ODbL**. The
snapshot ships with the upstream `Sources/ABOUT.txt` and `Sources/LICENSE.txt`, and the directory section
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

- `countries/<code>.json.gz` — one file per country: `{ "country": code, "categories": […], "languages":
  […], "domains": [ … ] }`. A domain is `[host, rank|null, avgArticleLength|null, languageIndexes[],
  groups[], summary|null]`: upstream's tuple without the country slot, with category and language indexes
  pointing into the file's own `categories` and `languages`, so a file is self-contained. Each domain
  belongs to exactly one country, so no domain is duplicated. Largest: United States, 4,682 domains,
  325 KB gzipped; median 2.5 KB.
- `index.json` — `{ "countries": [ { "code", "name", "sites": n, "languages": { "<code>": n } } ],
  "languages": { "<code>": n } }`, where each `n` counts sites (channels and podcasts together). Enough to
  drive the scope picker with counts and to know which country files hold a language, without opening any
  of them.
- `ABOUT.txt`, `LICENSE.txt` — copied from upstream `Sources/`.
- `SOURCE` — the upstream URL, the commit SHA of `docs/data/catalog.js`, and the fetch date.
- `snapshot.php` — see below.

The **country key** is the ISO 3166-1 alpha-2 code, upper-case (`DE`, `CI`, `CG`). Upstream names are
CLDR English region names, so the snapshot script maps them through ICU (`Locale::getDisplayRegion`): 230
of 231 match exactly, and the one exception is overridden in the script (`St. Helena` → `SH`). The script
fails if any name stays unmapped or two names map to one code. Files are named by code
(`countries/DE.json.gz`). The frontend shows country names in the user's UI language through
`Intl.DisplayNames` (type `region`), falling back to upstream's English name from `index.json`.

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
language**. Until both are set, the directory section shows the scope picker instead of the list.

### Storage

`Preferences` gains two JSON list columns, `feed_directory_countries` (country keys) and
`feed_directory_languages` (language codes), both defaulting to an empty list. One migration, verified on
SQLite and MySQL by the migrate-from-empty CI leg.

Like every preference, the scope is part of the account backup: the `account` line gains
`feedDirectoryCountries` and `feedDirectoryLanguages` (a file without them restores an empty scope), and
`docs/backup.md` documents both.

### API

Following the existing pattern of one PATCH per preference in `ReaderPreferencesController`, the scope gets
`PATCH /api/me/feed-directory-scope` with `{ "countries": [keys], "languages": [codes] }`, written through
`AccountPreferencesWriter`, and is read back as a `feedDirectoryScope` object on the `/api/me` profile
(`MeProfileJson`). An update validates every key against `index.json` and every language code against its
`languages`; an unknown value is a `422` problem. Both lists are replaced together and must be both
non-empty or both empty (anything else is a `422`); two empty lists clear the scope, and the directory then
asks again.

### Picker

One standalone component, `FeedDirectoryScopePickerComponent`, used in two places inside the directory's
own Settings section: inline as the section's content while no scope is saved (first visit), and in a dialog
(`<app-overlay-panel>`) opened by **Change scope** afterwards. Two checklists side by side (stacked on a
phone), each with a filter box and the current picks as removable chips above it: countries (name and site
count) and languages (display name via `Intl.DisplayNames`, and site count). Counts come from `index.json`
and add channels and podcasts together. **Save** needs one of each.

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
(`frontend/src/app/settings/feed-directory/feed-directory-url-cases.json`, read by Jest and by PHPUnit), so
the two cannot drift. It lives under `frontend/` because the Docker frontend container mounts only that
folder, while PHPUnit can read across the repository.

## Frontend

### Route and entry point

The directory is a **wide Settings section**: one `SETTINGS_SECTIONS` entry (`path: 'feed-directory'`,
icon `travel_explore`, group `general`, `wide: true`) placed directly after Organise, and one lazy child
route in `settings.routes.ts`, so it lives at `/settings/feed-directory` inside the Settings shell (its
back link, its nav rail on desktop, its hub on a phone). It is also reached from a **Browse feed
directory** link in the add-feed dialog's footer, next to the existing link to the onboarding catalog.

### `FeedDirectoryStore`

Signals throughout. On open it reads the user's scope; with no scope it stops and the section shows the
picker. Otherwise it loads `index.json`, `/subscribed` and the chosen countries' files in parallel, and
prepares each domain once, like InfoBubble's `prepare()`: lower-cased search text (host and summary),
channel and podcast categories, channel and podcast feed counts, length bucket, and subscribed state.
Domains with none of the chosen languages are dropped at load.

Filter state: mode (channels or podcasts), selected country (one of the scope, or all of it), categories,
length buckets, languages (a subset of the scope), sort, query. Computed: the filtered and sorted rows,
the per-country counts for the country menu, the total.

- **Length buckets**, from InfoBubble: Headlines under 500 characters, Summaries 500 to under 2,500, Long
  reads 2,500 and over; a domain without a length belongs to none.
- **Sort**: Top (rank ascending, unranked last, then host), A–Z (host), Most feeds (feed count in the
  current mode, then Top).
- A domain with no feeds in the current mode never shows.

### Section layout

Agreed in the visual round: variant C of [the mockup](2026-11-11-2176-feed-directory-mockup.html), which is the visual reference (A and B below it were the rejected options). It follows the Organise section's pattern, built from
`docs/design-language.md` and the shared components, styles in sibling `.scss` files, strings through
transloco (en and de).

- **Section head**: an icon tile (`travel_explore`), the title, "N sites in your countries and languages",
  and a **Change scope** button on the right.
- **Toolbar card**: the Channels/Podcasts segmented control (`<app-segmented-choice>`), filter pills for
  Countries (only when the scope holds more than one; "All countries" plus each scoped country with its live
  count), Category, Length and Language (only when the scope holds more than one), each a menu in a CDK
  overlay, a sort pill (Top, A–Z, Most feeds), and the search field (`<app-search-field>`). A second line
  inside the card shows the scope as chips and the "N shown" count. On a phone the pills scroll sideways and
  the search field takes its own line.
- **Rows card**: `cdk-virtual-scroll-viewport` with fixed-height rows inside a rounded card. A row shows the
  host (and the Following badge), a meta line (country · languages · length bucket · rank), the summary
  clamped to two lines, the category chips (hidden on a phone), and the action on the right. No favicons:
  catalog feeds carry none (#2130).
- **Footer line**: the ODbL attribution under the card.
- **Empty states**: no scope (the picker, inline), nothing matches the filters, a country file failed to load
  (with retry).

### Subscribing from a row

- **One feed in the current mode**: the row's **Subscribe** opens `AddFeedDialogComponent` with
  `{ url }`, which runs discovery at once and offers its tag picker and live preview.
- **Several feeds**: the row's button reads **N feeds ▾** (or **N feeds · M subscribed ▾**). On a fine
  pointer it opens a popover anchored to the row (CDK overlay, so the virtual list keeps its fixed row
  height) listing the feeds grouped by category, each by URL path with its own **Subscribe** opening the
  same dialog, or a **Subscribed** mark. On a coarse pointer the same list opens through the `ActionSheet`
  service: the host as title, a `{ note }` per category, one action per feed, subscribed feeds marked.
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
- **Frontend (Jest)**: the store (scope gating, language drop at load, every filter, each sort, country menu
  counts, mode switch), `feedDirectoryUrl()` against the shared case table, the scope picker (requires one
  of each), a single-feed row opening the dialog with its URL, the multi-feed popover, the subscribed and
  following states.
- **Playwright smoke**: with the feed-directory routes stubbed, choose a scope, filter, and open the
  add-feed dialog from a row.
- **Migration**: the CI leg that migrates from empty on SQLite and MySQL.
