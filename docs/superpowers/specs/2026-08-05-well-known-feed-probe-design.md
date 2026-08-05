# Well-known feed-path probe (#283)

**Goal:** a URL whose HTML page refuses automated clients but whose site still
serves a feed under a conventional path — `https://www.reddit.com/r/Bitwig/` —
becomes subscribable.

## Behaviour

When the fetch of the entered URL comes back **refused** (401/403/429, the
existing `BLOCKED_STATUSES`), discovery tries a short, ordered list of
conventional feed paths under that URL and returns the first one whose body the
feed parser accepts. Nothing found: the existing `blocked` failure, unchanged.

The probe runs **only for a refusal**, not for `unreachable`. `unreachable`
means no answer arrived at all (DNS failure, transport error, timeout); asking
the same dead host six more questions costs six timeouts and can only fail. A
refusal, in contrast, is a fast answer from a live server, so the whole probe
costs a few hundred milliseconds.

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

## Units

| Unit | Responsibility |
|---|---|
| `Service/Discovery/WellKnownFeedProbe` | Build the candidate URLs, fetch them in order, return the first that parses. Knows nothing about discovery outcomes. |
| `Service/Discovery/FeedDiscovery` | Unchanged except: on a refusal, ask the probe and map its answer to a `FeedDiscoveryResult`. |

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
- `FeedDiscoveryTest` — a refused page whose probe hits returns `directFeed`; a
  refused page whose probe misses still reports `blocked`; an unreachable page
  probes nothing.
- `add-feed-dialog.component.spec.ts` — the indicator shows while loading and
  disappears afterwards.
