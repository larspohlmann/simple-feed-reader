# Feeds a page does not advertise (#283)

**Goal:** a URL whose HTML page refuses automated clients but whose site still
serves a feed under a conventional path — `https://www.reddit.com/r/Bitwig/` —
becomes subscribable. And, since the same guessing machinery answers it, so does
a page that has a feed but never advertises one.

## Behaviour

Discovery tries four sources, in decreasing order of certainty:

1. the entered URL parsed as a feed (unchanged);
2. the feeds the page points at — exact autodiscovery links first, then
   feed-shaped links as a guess (`FeedLinkScanner`);
3. a feed under one of the conventional paths (`WellKnownFeedProbe`);
4. a synthetic `scraped` candidate from the page's article list (unchanged).

Source 3 is the only one left when the page never arrives, which is the #283
case. It runs whenever the site **answered** — a refusal (401/403/429) or any
other error status, a 404 included — and whenever a page **arrived pointing at
no feed at all**, where a real feed beats a synthesized one.

It does not run when nothing answered at all: a null status code means DNS
failure, refused connection or timeout, and asking a dead host six more
questions costs six timeouts and can only fail. An answer, by contrast, is proof
the host is alive and replies fast.

Nothing found anywhere: the existing failure reasons, unchanged.

### The paths

Appended to the entered URL's path, in this order, first hit wins:

`.rss`, `feed`, `rss`, `feed.xml`, `atom.xml`, `index.xml`

`.rss` leads because it is the convention of the site class this ticket is
about — Reddit serves `/r/<name>/.rss` while refusing `/r/<name>/`. The rest are
the common CMS conventions, most frequent first.

The entered URL is treated as a directory: a path not ending in `/` gets one, so
`/r/Bitwig` probes `/r/Bitwig/.rss`, not `/r/.rss`. Query and fragment are
dropped. Every probe goes through the same SSRF-guarded fetcher as any other
outbound request, so the probe inherits the guard rather than re-implementing
it.

**Skip rule:** if the entered URL's last path segment is already one of the
suffixes, no probe runs. That URL *is* the feed address and was merely refused —
usually a 429 from a rate limiter — and probing `/.rss/.rss` can only add load
and delay.

### What a hit returns

`FeedDiscoveryResult::directFeed($finalUrl)` — the same outcome as typing a feed
URL, so the subscribe completes in one round trip.

A candidate list was the alternative and was rejected: a candidate costs two
further fetches of the same host (the preview card, then the subscribe), and the
motivating site rate-limits hard enough that the third request reliably answers
429 — the user would be shown a feed they then cannot subscribe to. The probe
has already proved the body parses as a feed, so the extra confirmation buys
nothing.

## The guessed links

`FeedLinkScanner` owns both passes over the page. The strict pass is the old
inline scan, moved out of `FeedDiscovery`: `<link rel="alternate">` with an RSS
or Atom type, whose dialect is therefore known. The fuzzy pass runs only when
the strict one found nothing, and accepts:

- an `alternate` link with a vaguer type (`text/xml`, `application/xml`,
  `application/feed+json`);
- an anchor whose path looks like a feed (`/feed`, `/rss/`, `/index.atom`,
  `/feed.xml`) — but not `/feedback`;
- an anchor whose query says so (`?feed=rss2`, `?format=atom`);
- an anchor whose label says RSS, Atom or feed.

Guessed candidates carry the format `feed`, since nothing has parsed them yet,
and are capped at five. They cost no request: the dialog previews every
candidate it is offered, so a wrong guess reads as an unavailable preview rather
than as a bad subscription.

## Units

| Unit | Responsibility |
|---|---|
| `Service/Discovery/FeedLinkScanner` | Read the feeds a page points at, exactly and then approximately. Pure: HTML in, candidates out. |
| `Service/Discovery/WellKnownFeedProbe` | Build the conventional URLs, fetch them in order, return the first that parses. Knows nothing about discovery outcomes. |
| `Service/Discovery/FeedDiscovery` | Order the four sources and map the winner to a `FeedDiscoveryResult`. |

`WellKnownFeedProbe::probe(string $pageUrl): ?string` returns the canonical feed
URL, or `null` when no conventional path answered with a feed. Null is an
absence here, not a failure signal: "this site has no feed under a conventional
path" is an expected, ordinary outcome.

Per-probe fetch failures (404, timeout, SSRF refusal) are swallowed and the next
suffix is tried — a probe is a guess, and a wrong guess is not an error.

## Frontend

Discovery now costs more wall-clock time in the refusal case, so the dialog
gains a live indicator instead of only a disabled button: while a subscribe is
in flight the dialog body shows the app spinner next to "Looking for a feed…".
Indeterminate by necessity — the work happens in one request and the browser
cannot know which suffix the server is on.

## Testing

- `WellKnownFeedProbeTest` — hit on the first suffix, hit on a later one, order
  and stop-at-first-hit, non-feed bodies skipped, all-miss returns null,
  fetch exceptions skipped, trailing-slash normalisation, query dropped, the
  skip rule, and the redirect-canonical final URL.
- `FeedLinkScannerTest` — the strict pass and its dialects, the footer icon, the
  feed-shaped addresses, the near-misses it must ignore, the cap, the
  self-link and duplicate guards.
- `FeedDiscoveryTest` — a refused page whose probe hits returns `directFeed`; a
  404 that probes; a refused page whose probe misses still reports `blocked`; a
  page whose only hint is an anchor; a scrapable page that still prefers a
  conventional feed; an unreachable page that probes nothing.
- `add-feed-dialog.component.spec.ts` and `e2e/add-feed-progress.spec.ts` — the
  indicator shows while the request is in flight and disappears afterwards.
