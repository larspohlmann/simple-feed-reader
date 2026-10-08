# #1443 — An Apple Podcasts show link subscribes to the show's feed

Settled in chat on 2026-10-08. The issue body carries the user-facing decisions;
this file adds what the code survey and the API probes found.

## What the user does

Apple Podcasts exports no subscription list and shows no feed address, but every
show has a share link, and Apple's public lookup API maps that link's show id to
the show's RSS feed. So the user copies the link and pastes it where a feed
address goes:

- iPhone, iPad: open the show, tap "…", Share Show, Copy Link. Long-pressing a
  show in the Library offers the same Share Show.
- Mac: right-click the show, or its "…" menu, then Share Show, Copy Link.
- Web: the address of any `podcasts.apple.com` show page.

The link is pasted into "Add feed". The show's feed is resolved, fetched and
parsed, and the subscription appears straight away, exactly as pasting the feed
address itself does today. No candidate card, no second step. An episode link
(the same path plus `?i=<episode>`) subscribes to its show.

## Decisions

- **Resolution through Apple's lookup API, not the show page.** The share link
  is turned into `https://itunes.apple.com/lookup?id=<showId>` before anything
  is fetched, and `results[0].feedUrl` is the address discovery continues with.
  Probed on 2026-10-08: the endpoint needs no key, answers the app's own
  `SimpleFeedReader/1.0` user agent with HTTP 200 and `text/javascript`, returns
  `resultCount: 0` for an unknown id and `kind: "software"` with no `feedUrl` for
  an App Store id. The show page itself embeds the same `feedUrl` in a serialised
  JSON blob, but it is 570 KB, carries no `<link rel="alternate">`, and the blob
  is an undocumented render detail; the lookup API is Apple's published contract.
  Rejected for that reason: a `FeedOfferInterface` reading the page body, the
  way the SoundCloud resolver has to.
- **The fetcher is the HTTP client.** `FeedFetcherInterface::fetch()` carries the
  SSRF guard, the size cap (5 MB, far above a lookup response) and the outbound
  user agent, and it does not gate on content type, so the JavaScript content
  type Apple sends is no obstacle. This is how the Substack resolver already
  calls its profile API.
- **Accepted link shapes.** Host `podcasts.apple.com` or `itunes.apple.com`,
  compared lowercase and exactly (no look-alike suffix). Path
  `/<storefront>/podcast/<slug>/id<digits>` where the two-letter storefront and
  the slug are each optional and a trailing slash is allowed; the query string
  is ignored (`?i=`, `?l=`, `?uo=`). Verified: `/de/podcast/id1092957894` loads
  the show without a slug. Everything else returns null without a fetch: an App
  Store path (`/app/…/id…`), a non-numeric id, any other host.
- **A valid resolution is an absolute http(s) URL.** `feedUrl` must be a string
  whose `parse_url()` yields an `http` or `https` scheme and a host; anything else
  (missing, non-string, relative, `javascript:`, `ftp:`) is null. `results[0]`
  must carry `kind: "podcast"`. The resolver never throws: a `FetchException`,
  a non-JSON body and a body that is not an object all return null.
- **Null falls through, as it does for Substack.** When the link is recognised
  but does not resolve, discovery continues with the pasted URL on the ordinary
  web-page path and yields whatever that path yields for an Apple page today.
  The resolver adds no failure reason to `ScrapeFailureReason` and no new
  outcome to the dialog. A tri-state ("not mine" / "mine but unresolved") was
  considered and dropped: it would change the resolver contract for a case the
  Substack resolver already accepts, and the user's recovery is the same either
  way, paste the feed address.
- **Share-link resolvers become a tagged list.** The Substack special case in
  `FeedDiscovery::discover()` (`$this->substackProfile->feedUrl($url) ?? $url`)
  is the first implementation of a role, and Apple is the second, so the role
  gets its interface folder, the way `FeedOffer/` holds `FeedOfferInterface` and
  its two implementations:
  - `Service/Discovery/ShareLinkFeed/ShareLinkFeedInterface.php`, with one
    method, `feedUrl(string $enteredUrl): ?string`: the feed a share link points
    at, or null when the URL is not one of this resolver's links or cannot be
    resolved. The docblock states the contract that a null must not have cost a
    fetch when the URL is not the resolver's shape, which every implementation's
    unit test proves.
  - `SubstackProfileFeed` moves into that folder and implements the interface;
    its two tests move with it under `tests/Service/Discovery/ShareLinkFeed/`.
  - `ApplePodcastShowFeed` is the new implementation, next to it.
  - `services.yaml` tags the interface `app.share_link_feed` in `_instanceof`,
    beside `app.feed_offer`; `FeedDiscovery` takes
    `#[AutowireIterator('app.share_link_feed')] iterable $shareLinks` in place
    of the `SubstackProfileFeed` parameter and asks each in turn, first non-null
    wins. The two resolvers claim disjoint hosts, so no priority attribute.
  - `tests/Service/Discovery/BuildsFeedDiscovery.php` passes
    `[new SubstackProfileFeed($fetcher), new ApplePodcastShowFeed($fetcher)]`.
- **Names and constants** in `ApplePodcastShowFeed`: `SHARE_HOSTS`
  (`['podcasts.apple.com', 'itunes.apple.com']`), `SHOW_PATH` (the path regex,
  capturing the id), `LOOKUP_API` (`'https://itunes.apple.com/lookup?id=%d'`).
  Private methods read as the steps above: `showId(string $enteredUrl): ?int`,
  `lookedUpFeedUrl(int $showId): ?string`, `feedUrlOf(string $lookupJson):
  ?string`. Three public-facing lines in `feedUrl()`, mirroring the Substack
  resolver.
- **The dialog says so.** `app-field` has a `hint` input; the URL field gets one,
  `dialog.addFeed.urlHint`: "A website or feed address, or a show link copied
  from Apple Podcasts." / "Eine Website- oder Feed-Adresse, oder ein aus Apple
  Podcasts kopierter Sendungslink." Without it nobody would try pasting an Apple
  link. No other frontend change.
- **README.** One bullet under Listening, beside the SoundCloud one: paste a show
  link from Apple Podcasts into "Add feed" to subscribe to its RSS feed.

## Testing

- `tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeedTest.php`, a unit
  test over `StubFeedFetcher`, in the shape of `SubstackProfileFeedTest`:
  - resolves a show link to the feed the lookup names (fixture: a lookup
    response trimmed to the real shape, `resultCount`, `results[0]` with `kind`,
    `collectionId`, `collectionName`, `feedUrl`);
  - asks the lookup API for exactly that id and nothing else (`fetchedUrls`);
  - a data provider over every accepted link shape: storefront and slug,
    storefront without slug, no storefront, `itunes.apple.com`, mixed-case host,
    trailing slash, episode query, tracking query;
  - a data provider over URLs it must leave alone with no fetch: an App Store
    link, an id with letters, a look-alike host
    (`podcasts.apple.com.evil.example`), a non-Apple host, an Apple page that is
    not a show;
  - a data provider over unresolvable lookups: `resultCount: 0`, `kind:
    "software"`, no `feedUrl`, a non-string `feedUrl`, an empty string, a
    relative URL, a `javascript:` URL, a body that is not JSON, a JSON scalar;
  - a `FetchException` from the lookup returns null.
- `tests/Service/Discovery/ApplePodcastShowDiscoveryTest.php`, a `KernelTestCase`
  with `BuildsFeedDiscovery`, in the shape of `SubstackProfileDiscoveryTest`:
  a show link subscribes the feed the lookup resolves (direct feed, no
  candidates, no failure reason); an unresolvable show link falls through to the
  ordinary outcome for the pasted URL.
- `tests/Service/Discovery/ShareLinkFeed/ShareLinkFeedsAreConsultedTest.php`, in
  the shape of `FeedOffersAreCollectedTest`: the first resolver to answer wins
  and the ones after it are not asked; when none answers, discovery fetches the
  entered URL.
- The moved Substack tests pass unchanged apart from their namespace.
- Frontend: the add-feed dialog spec asserts the hint renders; no behaviour
  changes.
- `composer check`, `composer md` on every touched file, PhpStorm inspections,
  `composer infection:diff`; both database legs. No migration.

## Kept open: level 2, search by name

Not built now, but the seams it needs are named so it stays a small follow-up:

- The lookup call lives in `ApplePodcastShowFeed` as a private method, like the
  Substack profile call. A catalog search (`https://itunes.apple.com/search?media=podcast&term=…`,
  verified to return the same `feedUrl`, `collectionName` and `artworkUrl600`
  per result) would be the third Apple API reader, which is when the API access
  moves into one `ApplePodcastCatalog` service that both the resolver and the
  search use, and the result shape becomes an `ApplePodcastModel` in `Model/`.
- Level 2 adds a search endpoint under `/api/discovery` behind a rate limiter
  (Apple allows roughly twenty requests per minute per IP, shared by every user
  of an instance), and the dialog treats input that is not a URL as a search
  term and shows the results as the candidate cards it already renders, with
  artwork. The field's `type="url"` and the required validator are the frontend
  change that step needs.

## Out of scope

- Any Apple catalog search or browse.
- A share-sheet target: only a native client can offer one.
- Bulk import from an Apple Podcasts library. There is no API for a user's
  subscriptions; the Mac library database can be turned into OPML with a
  one-off script, which could go into the docs as a how-to in its own change.
- Resolving the episode of an episode link to a specific entry; the link
  subscribes the show.
