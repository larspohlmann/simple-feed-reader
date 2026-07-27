# Onboarding Feed Catalog — Design

**Date:** 2026-07-26
**Issue:** [#99](https://github.com/larspohlmann/simple-feed-reader/issues/99)
**Branch:** `feature/99-onboarding-feed-catalog`

## Problem

A newly registered user lands in an empty entry list. The only route from
"account created" to "reader with content" is adding feeds one URL at a time,
and a new user typically has no feed URLs to hand. The empty reader is the worst
first impression the app can make.

## Goal

A picker of ~110 curated, category-grouped feed suggestions with favicons.
Multi-select, subscribe in one action, and every category the user picked from
becomes a tag the chosen feeds are filed under.

## Non-goals

- Personalised or algorithmic recommendations.
- Locale-aware filtering of the catalog.
- A refresh scheduler. The post-subscribe sweep is the existing client-driven
  `/api/refresh` loop.
- Making `RefreshRunner`'s lock per-user — see [Accepted risks](#accepted-risks).
- OPML import during onboarding; that already exists in Settings.

## Decisions

| Question | Decision |
| --- | --- |
| Where the catalog lives | DB tables, admin-editable, populated by importing a shipped OPML document |
| Favicons | Bytes cached on the row, filled by a budgeted warmer the admin UI drives after an import |
| Catalog scope | Mixed English + German, single-language per row |
| Bulk subscribe | A `BulkSubscriber` extracted from `OpmlImporter`, shared by both callers |
| Picker layout | Continuous scroll with a scroll-tracking category rail |
| Onboarding trigger | Zero subscriptions, a session-scoped skip flag, and a non-empty catalog |
| Post-subscribe refresh | The reader shell owns it, driven by state rather than call order |
| Catalog updates | An admin import with `merge` and `replace` modes — no migration ever carries catalog data |
| Protecting admin edits | Rows can be **locked**: an import neither overwrites nor deletes them |
| Admin | Full catalog CRUD plus the import in this ticket |

## Data model

Two entities, both admin-editable, following the existing `Tag`/`Subscription`
conventions.

**`CatalogCategory`** — `id`, `key` (unique slug), `name`, `icon` (Material
Symbol, `^[a-z0-9_]+$`, same constraint as `CreateTagRequest`), `color`
(`^#[0-9a-fA-F]{6}$`), `position`, `enabled`, `locked`.

**`CatalogFeed`** — `id`, `category` (FK, `onDelete: CASCADE`), `title`, `url`
(unique), `siteUrl`, `description`, `sourceFormat` (defaults `xml`, matching
`Feed::$sourceFormat`), `position`, `enabled`, `locked`, plus the favicon cache columns
`faviconSourceUrl`, `faviconData` (BLOB), `faviconContentType`,
`faviconFetchedAt`, `faviconFailedAt`.

Storing the icon bytes on the row keeps the cache in the same backup/restore unit
as the catalog and avoids a writable `var/` path on Strato. Icons are a few KB
each; ~110 rows stays well under a megabyte.

## Backend

### `GET /api/catalog`

Authenticated. Returns enabled categories in `position` order, each with its
enabled feeds in `position` order, plus `subscribed: bool` per feed for the
current user. No favicon bytes — only the per-feed favicon URL.

### `GET /api/catalog/feeds/{id}/favicon`

Serves the cached bytes with a long `Cache-Control` and an `ETag`. On a cache
miss or a recorded failure it serves a deterministic monogram placeholder — the
first letter of the title on the category colour — rather than fetching inline.
The picker must render at full speed with no network fan-out, and must work
offline in e2e.

### Warming favicons

`CatalogFaviconWarmer` is the only code path that fetches a favicon. It selects
enabled `CatalogFeed` rows with no `faviconData`, or whose `faviconFetchedAt` has
aged past the staleness window, skipping rows still inside the `faviconFailedAt`
retry backoff. Each budgeted slice resolves its icon URLs in a single concurrent
batch through the shared `FaviconResolver::resolveAll()` — the same concurrent
fetch path #116 introduced for refresh — rather than resolving each site in turn.
It commits per icon, so an interrupted run resumes rather than restarting, and it
works to a **time budget**, returning `{ warmed, failed, remaining }`.

Resolution (a site homepage to its best icon URL) and download (fetching that
URL's bytes) are separate steps: the shared resolver does the first for a whole
slice at once, and `CatalogFaviconFetcher` downloads each resolved URL under the
same guards as the feed fetch path — timeout, redirect cap, response size cap, an
allow-list of image content types, and an SSRF guard rejecting private and
link-local addresses.

**Warming must not depend on how the app is deployed.** This project's own server
runs a Strato deploy script, but a fork will run Docker, or rsync, or a shared
host with no shell at all — and tying icons to one of those would make them a
property of the deployment rather than of the app. So the primary mechanism is
in-app:

`POST /api/admin/catalog/favicons/warm` runs one budgeted slice and reports what
is left. The admin UI polls it until `remaining` reaches 0, showing progress —
the same budget-and-poll contract `/api/refresh` already uses, for the same
reason: 111 publisher round trips cannot fit in one request. **It starts
automatically after an import**, which is exactly when a catalog has no icons and
an admin is already watching.

Two conveniences sit on top, and nothing depends on either:

- `app:catalog:warm-favicons` loops the same warmer, for cron or an operator who
  prefers a shell.
- This repo's `deploy/strato/activate-release.sh` calls that command after the
  symlink flip, non-fatally, so its own server never needs the nudge.

A cold cache is not a failure state: every uncached row renders the monogram
placeholder, which is a working picker. Cost is self-limiting — one pass after
the first import, then a no-op, since cached rows match neither predicate.

No lock: each row commits on its own and the due-query skips anything already
fresh, so two concurrent runs merely duplicate a little work.

### `POST /api/onboarding/subscribe`

Body `{ "catalogFeedIds": [1, 2, 3] }`. Response
`{ subscribed: n, skipped: n, tagsCreated: [...] }`.

- **No discovery.** Catalog rows carry a verified direct feed URL and its
  `sourceFormat`, so this path must never call `FeedDiscoveryInterface`. 110
  selections must not mean 110 outbound discovery fetches.
- **No inline refresh.** New `Feed` rows are due immediately; the endpoint
  fetches nothing.
- **Idempotent and partial-tolerant.** Already-subscribed ids are skipped, not
  errors. Crossing `MAX_SUBSCRIPTIONS_PER_USER` mid-batch stops cleanly and
  reports what was created. Unknown or disabled ids are ignored.

### `BulkSubscriber`

The ticket originally specified a `SubscriptionService::subscribeDirect` plus
"batch the unit of work". That rebuilds what `OpmlImporter` already does
correctly: batch-local dedup maps guarding both `uniq_subscription_user_feed` and
`uniq_tag_user_name`, in-memory position counters seeded from the committed max,
find-or-create against the shared `Feed` table, cap handling that counts in
memory, and a single terminal flush.

So the shared logic is **extracted from `OpmlImporter` into a `BulkSubscriber`**
that both callers use. OPML import keeps its parsing and its tag-from-folder-name
rule; onboarding supplies catalog rows and the tag styling rule below. Neither
grows a second copy of the cap, the dedup or the position arithmetic.

`OpmlImporter` is covered by existing tests, which become the safety net for the
extraction: it must be a pure refactor with no behaviour change.

### Seeded feed titles

`SubscriptionJson` resolves a title as `customTitle ?? feed.title ?? feed.url`,
and `Feed.title` is filled by the *refresh* pipeline. Without intervention, a
user who just picked 35 feeds sees a sidebar of raw URLs until the sweep reaches
each one — the exact opposite of the first impression this feature exists to
create.

`BulkSubscriber` therefore copies the catalog row's title onto the `Feed` **at
creation only**. `Feed` is shared between users, so an existing row's title is
not ours to overwrite. Refresh replaces it with the publisher's own title later.

### Tag creation

For each category with at least one *newly created* subscription:

- Find-or-create the `Tag` by `(user, name)`. `uniq_tag_user_name` means a name
  the user already has is **reused**, never duplicated.
- Set `color`/`icon` from the category **only when creating**. A tag the user has
  already customised is never overwritten.
- New tags get `position` continuing the user's existing max.
- One `SubscriptionTag` per subscription, `position` following catalog order
  within that tag.
- A category the user selected nothing from creates no tag.

## Frontend

### The picker

One route, `/discover`, mounted as a component so Settings and Add-feed can reach
it permanently. For a user who already has subscriptions, already-subscribed
entries render selected and disabled.

**Desktop** is a continuous scroll through every category — nothing is hidden
behind a click — with a category rail on the left that tracks the scroll
position. Clicking a rail row jumps to that section; the section header pins
while its category is in view. The rail carries a second job: the count picked
per category against categories the user has selected from, the plain feed count
against the rest, so "what have I chosen so far" is answerable without scrolling
back.

Scroll-spy is an `IntersectionObserver` over the section headers. Click-to-jump
must **suspend** the observer until the smooth scroll settles, or the highlight
strobes through every category it passes.

**Mobile** drops the rail below the breakpoint and renders the same scroll-spy
state as a horizontal chip strip pinned under the title bar: taps jump, the
active chip scrolls itself into view. One piece of state, two renderings.

Selection is client-side; nothing is written until Subscribe. A sticky footer
shows `N feeds in M categories` and the primary action.

**Accessibility.** Each category is a labelled group; feed cards are real
checkboxes, so keyboard-only selection and screen-reader semantics come from the
control rather than from ARIA bolted onto a `div`. The selected count is a live
region, announced as it changes. The rail and the chip strip are navigation, not
selection — reaching a section must never toggle anything. Scroll-spy updates the
active row visually but must not steal focus, and the pinned section header must
not obscure the focused card when tabbing into a section.

### Entry and skip

The reader shell redirects to `/discover` when the user has zero subscriptions,
no session-scoped skip flag, **and** a catalog with something in it. The flag
lives in `sessionStorage`, not the database: it evaporates on the next visit,
which preserves the intent that an empty reader keeps offering the picker.

**An empty catalog suppresses onboarding.** Nothing seeds the catalog — it
arrives by admin import — so a deployment where that has not happened yet would
otherwise send every new user to a blank picker. The shell asks a shared
`CatalogStore` before deciding, and a catalog that is empty, or that failed to
load, leaves the user in the reader with its normal empty state. The store
resolves as *empty* on error deliberately: failing closed means "do not
redirect", which is the safe direction.

The cost is one extra request, and only in the zero-subscription case. The store
is shared with `/discover`, so a redirect that does happen still fetches the
catalog exactly once. `/discover` itself stays reachable from Settings and says
so plainly when there is nothing to show.

### The admin warning

Suppressing onboarding silently is safe for the user and dangerous for the
operator: a deployment where nobody imported would never onboard anyone, with
nothing anywhere saying so. So **admins, and only admins, see a warning** in the
reader whenever the catalog is empty — "no feed catalog has been imported, so new
users are not being offered any suggestions" — linking straight to the importer.

For admins the shell therefore resolves the catalog unconditionally rather than
only in the zero-subscription case; an admin with a full reader of their own is
precisely the person who would otherwise never notice. It is one cached request
per session. The admin catalog page repeats the notice at the top, immediately
above the bundled-import button, so following the link lands on the fix rather
than on a form.

The redirect uses `replaceUrl: true`. Without it, Back from `/discover` lands on
the reader, which redirects again — a dead Back button.

The check lives in the shell rather than a `canActivate` guard: a guard would
have to resolve `subscriptions.store` before answering and would block the route.
The shell gates its empty state on "subscriptions resolved" so nothing renders
until the answer is known, which costs no blocking load and shows no flash of
empty reader.

**Skip** goes to today's empty state, which keeps a visible link back to
`/discover`.

### Language

Catalog rows are single-language as curated, and the tag inherits exactly what
the picker showed. The picker's own chrome goes through Transloco like the rest
of the app — the ticket's claim that the frontend has no i18n framework is
outdated; `@jsverse/transloco` ships with `public/i18n/{en,de}.json`.

A category name becomes a **tag**, which is user data and cannot retroactively
follow a language switch. Translating catalog rows would mean either duplicated
columns or translation keys, doubling the admin surface for 13 labels on a screen
most users see once. If it grates later, resolving the 13 category names through
Transloco is a non-breaking upgrade.

### Post-subscribe

Subscribe returns → **navigate into the reader first** → the sweep starts behind
the UI. The UI is never blocked waiting on feeds.

`RefreshService.run()` early-returns when `running()` is already true, so an
onboarding-triggered call can be silently swallowed by a refresh the shell
started on load. Rather than sequencing two components, **the shell owns the
sweep** under one rule: *subscriptions exist that have never been fetched → run
an unscoped refresh.* State-driven, so there is no ordering to get wrong and
nothing to swallow.

`RefreshService` gains a **per-slice tick** — today only `onDone` fires, so the
entry list would sit still until the whole sweep ended. The list refetches on
each `partial` slice, so entries appear progressively.

Progress is presented two ways:

- A 2px determinate hairline under the header, fed by the existing `progress`
  computed signal. No layout shift, and it upgrades *every* refresh in the app,
  not just onboarding.
- A counted banner above the entry list in the post-onboarding session only —
  "Fetching your feeds — 12 of 35". `RefreshReport` already carries `total` and
  `remaining`, so the numbers are real. The hairline shows *that* something is
  happening; the banner explains *why the list is thin*, which is the one thing a
  brand-new user needs told.

### Errors and edge cases

- **Cap reached** — the banner reports what was created and what was skipped;
  subscriptions and tags stay.
- **Refresh failure** — the existing error path; the banner becomes a retry. The
  just-completed onboarding is untouched.
- **Leaving mid-sweep** — un-fetched feeds stay due and are picked up by the next
  refresh. The reader looks sparse until then, and must not read as an error.
- **Re-submitting the same selection** — a no-op, not a set of errors.

## Seeding by import

The catalog is **data, not schema**. The migration creates `catalog_category` and
`catalog_feed` and stops there; the rows arrive by importing
`backend/resources/catalog/catalog.opml`, the document this release ships.

**OPML, not an invented format.** It is what feed collections are exchanged in,
the repo already imports and exports it, and the shipped file consequently opens
in any other reader. Categories are group outlines; feeds use the standard
`xmlUrl`, `htmlUrl` and `description`. The three things OPML has no equivalent
for — a category's stable `key`, its `icon` and its `colour` — ride as extra
attributes on the group outline, which OPML 2.0 permits.

The hardened parsing already exists in `OpmlImporter::parseBody()` — no network,
no DTD, an `<opml>` root with a `<body>`. That is a security boundary, so it is
**extracted into one shared `OpmlBodyReader`** used by both the user-facing OPML
import and the catalog document, rather than copied.

That keeps catalog churn out of the migration chain entirely. A feed URL that
rots is a corrected JSON file and an import — not a new migration, not a deploy,
and never a blanket rewrite of rows an admin has edited.

**Two modes**, differing in exactly one respect:

- **merge** — upsert everything the document lists; rows it does not mention are
  left alone.
- **replace** — upsert everything the document lists; rows it does not mention
  are deleted.

Both match on the natural key — category by `key`, feed by `url` — never by id,
because the document carries no ids and the rows may have been edited since the
last import. Both therefore **preserve the cached favicon of any feed whose URL
survives**: `replace` is deliberately not truncate-and-insert, which would throw
away every cached icon and force a full re-warm on each import.

### Locked rows

Without something like this, `replace` is a foot-gun: an admin who adds a feed
the shipped catalog does not carry loses it the next time they re-import.

So a category or feed can be **locked**, which means the row is the admin's, not
the document's. An import will neither overwrite nor delete it.

Lock covers overwriting as well as deletion on purpose. Delete-only protection
would still let a re-import silently revert an edited title or description, which
is the same unwelcome surprise wearing a different hat — and it is the same
principle already applied to tag styling and to admin-edited catalog rows.

Two details that are easy to get wrong:

- **A category holding a locked feed cannot be deleted**, even when the category
  itself is unlocked and the document omits it. The FK cascade would take the
  locked feed with it and defeat the lock entirely.
- **Locking a category protects the category row, not the catalog beneath it.**
  Its feeds are still imported; otherwise locking a category would silently
  freeze feed updates inside it, which nobody would predict from the word.

Rows an admin creates **by hand** default to locked — they meant to add them, and
a later `replace` should not quietly take them away. Rows created by an import do
not, because the document already owns them.

The import reports `lockedSkipped` alongside its other counts, so a lock that did
something says so rather than looking like a no-op.

The document is fully validated before a single row is touched, and the import
runs in one transaction. There is no partial import: a malformed upload is a 422
that changed nothing.

**Three routes to the same importer**, all sharing `CatalogImporter` and its
validation:

- **The bundled document, one click.** The release already ships
  `resources/catalog/catalog.opml`, so the common case — an admin who has just
  been told the catalog is empty — needs no file at all. `GET
  /api/admin/catalog/bundled` reports what it would import so the button can name
  real numbers; `POST /api/admin/catalog/import/bundled` applies it. Nothing is
  transferred, because the document is already on the server.
- **An uploaded document,** for a catalog from anywhere else. The OPML text
  rides inside an ordinary JSON body, so there is no multipart handling and the
  admin API stays pure JSON.
- **`app:catalog:import`,** so an unattended environment — a developer's box, the
  e2e stack — can be seeded without clicking.

`BundledCatalog` owns reading the shipped file and is shared by the first and the
third, so its location is stated once.

### Consequences

**Every environment needs one import.** A migrated database has empty catalog
tables, so the Docker stack, the e2e stack and production each need one. In
production that is the admin's one-click bundled import; elsewhere it is
`app:catalog:import`. **A fresh deploy onboards nobody until that happens** —
which is exactly why the admin gets told (below). Wiring the command into
`activate-release.sh` would remove the manual step; that is a deliberate open
choice rather than part of this design.

**Tests build their own fixtures.** `backend/tests/bootstrap.php` creates the
test schema from ORM metadata, so no test executes a migration *and* nothing
imports the shipped document. Functional tests construct their own two-category
fixtures, which is the better outcome anyway: deterministic, and independent of a
111-row production file.

**E2E imports first.** `composer e2e` drives the migrated Docker stack, so the
suite runs `app:catalog:import` as part of provisioning and then exercises the
real 111-row payload — provided favicons resolve to the offline placeholder
rather than fanning out to publisher domains.

## Admin

A **Feed catalog** section under the existing admin area: an import panel, plus
CRUD for categories (name, icon, colour, position, enabled) and feeds (title,
url, siteUrl, description, category, position, enabled), reordering, and a
"refresh favicon" action per feed that re-fetches a single row through the same
service the warm command uses. `ROLE_ADMIN` only, consistent with the existing
admin controllers.

The import panel takes a `.json` file and a mode. The component parses the file
in the browser, so a malformed document fails before any request, and posts
`{ mode, document }` as ordinary JSON — no multipart, which keeps the admin API
consistent with the native-iOS constraint. `replace` deletes rows the document
omits, so the UI says so on the control rather than in a tooltip.

## Testing

- Backend functional tests for `GET /api/catalog`, the favicon endpoint (hit,
  miss, failure) and `POST /api/onboarding/subscribe`: happy path, tag reuse,
  tag-colour preservation, empty category, duplicate submit, cap boundary,
  unknown and disabled ids.
- A test asserting the subscribe path performs **zero** discovery calls, via a
  spy on `FeedDiscoveryInterface`, driven **through the real HTTP kernel**. A
  direct-invocation test here can assert something the wired-up app never does.
- `BulkSubscriber` extraction is guarded by the existing `OpmlImporter` tests —
  no behaviour change permitted.
- Warmer tests: fills a missing icon, skips a fresh one, records a failure and
  honours the backoff on the next run, stops on its budget and reports the
  correct `remaining`.
- A functional test for `POST /api/admin/catalog/favicons/warm` covering the
  slice contract, with the fetcher stubbed — CI must not need the internet.
- Migration leg: CI's existing "builds the schema from empty" step covers the two
  new tables, and `doctrine:schema:validate` covers the mapping. No row-count
  assertion is needed here any more — the migration carries no data.
- The **shipped document** is validated like production data: 13 categories, 111
  feeds, unique keys, unique URLs, well-formed icons and colours, every URL
  http(s) and inside the 750-character `Feed.url` limit. Nested categories, a
  feed at the top level and a missing category `key` are all rejected rather than
  guessed at.
- `OpmlBodyReader` is tested as the security boundary it is: non-OPML root,
  missing body, malformed XML and a DOCTYPE all rejected. Extracting it must not
  change `OpmlImporter`'s behaviour — the existing OPML tests are the proof.
- Importer tests: first import creates; re-import updates in place and **keeps
  the cached favicon**; `merge` leaves unmentioned rows alone; `replace` removes
  them; positions follow document order; a malformed document is a 422 that
  wrote nothing.
- Locking tests: `replace` keeps a locked feed the document dropped; a locked
  feed is not overwritten by the document; `replace` keeps a locked category; and
  `replace` keeps an *unlocked* category that still holds a locked feed — the
  cascade case.
- Frontend unit tests for the selection store (per-category select-all, counts,
  disabled entries), the scroll-spy state, and the post-subscribe sequence:
  navigation happens before the sweep, the sweep is actually issued, the list
  refetches per slice, and a refresh failure leaves subscriptions and tags
  intact.
- E2E: register → land on `/discover` → select two categories → subscribe →
  reader shows the tags in the sidebar, against the migrated Docker stack with
  the full 111-row catalog imported during provisioning, and no live network
  calls.

## URL rot

A **scheduled** workflow fetches every URL in the shipped
`resources/catalog/catalog.opml` and opens an issue on non-feed responses. It
checks the document rather than the database, because the document is what a new
install receives and therefore what rots unnoticed. Deliberately not a PR gate: a merge check hitting 111
publisher domains will be red for reasons that have nothing to do with the PR —
rate limits, bot blocks, transient outages. Five candidates were already dropped
during curation for exactly these reasons.

## Accepted risks

**The global refresh lock.** `RefreshRunner` holds an application-wide
`feed-refresh` lock with a 300-second TTL, not a per-user one. A new user's
111-feed sweep makes every other user's refresh return `busy` until their client
gives up after `MAX_BUSY_RETRIES`, and a sweep longer than the TTL can have its
lock expire mid-run.

Accepted rather than fixed here.
[#116](https://github.com/larspohlmann/simple-feed-reader/issues/116) takes a
sweep from the sum of every round trip to roughly `max(total / 8, slowest)`,
which defuses both the TTL risk and the multi-user interaction without this
ticket touching locking. Scoping the lock per user remains its own change.

**The rate limiter is not a constraint.** `refresh` allows 90 requests per 5
minutes per user; 111 feeds at `BATCH_LIMIT = 50` and a 25-second budget is 6–10
slices. Confirmed rather than discovered.
