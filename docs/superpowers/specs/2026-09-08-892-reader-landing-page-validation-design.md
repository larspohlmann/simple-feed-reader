# Design: validate the reader landing page before extracting (#892)

Issue: [#892](https://github.com/larspohlmann/simple-feed-reader/issues/892) —
"Reader stops at meta-refresh interstitials".

## 1. Goal & scope

Add one step to the **article-reader fetch path only**: after the guarded
redirect chain lands, inspect the landed page before extraction and either

1. follow a zero-delay `<meta http-equiv="refresh">` to the real article, or
2. reject a `200` bot/consent challenge body as a fetch failure.

Both cases reuse one landing loop, one body read, and one hop budget.

### Out of scope (deferred, documented)

- **`MediaLanding`** (`Service/Reader/Media/MediaLanding.php`) resolves HLS/media
  URLs, never reads a body, and media manifests do not serve consent walls.
  Adding body-reading there is cost with no known case. Left unchanged.
- **`BotChallengePage`** (`Service/Discovery`) stays as-is. Its narrow
  SiteGround-only match is deliberate (#424) and drives discovery's "blocked"
  verdict; the reader gets its own broader recognizer rather than changing
  discovery behavior. A future unification is possible, not now.

## 2. Placement decision

The issue's open question was: put meta-refresh following inside `RedirectFollower`
or in a reader-layer wrapper. Chosen: **reader layer**, because the reader already
reads and size-caps the body in `HtmlPageFetcher`, while `RedirectFollower` is
header-only and shared by `MediaLanding` (which `cancel()`s without reading). The
issue's reason to prefer the former — one shared hop budget — is met by having the
guarded chain **report the hops it consumed**, so the reader loop keeps a single
budget across HTTP-redirect and meta-refresh hops. `RedirectFollower` keeps its
single responsibility; `MediaLanding` and the feed-stream locator are untouched.

## 3. Components

Four touch-points: two new pure classes, one field addition, one loop.

### 3.1 `RedirectFollower` — report hops (change)

Add `public int $hops` to `LandedResponse`. `follow()` sets it to the number of
redirects consumed before landing (`0` for a direct land — the loop variable at the
return point). The header-only contract is otherwise unchanged. `LandedResponse` is
constructed at exactly one site (inside `follow()`); `MediaLanding` reads only
`->url`/`->isSuccess()` and ignores the new field.

### 3.2 `MetaRefreshTarget` — new, `Service/Reader`, `final readonly`

```
within(string $html, string $baseUrl): ?string
```

- Parse the head window with the shared `HtmlDocumentParser::parseOrNull()`.
- Find the first `<meta>` whose `http-equiv` equals `refresh` case-insensitively
  (compare `strtolower` of the attribute value) **and** whose delay parses to
  exactly `0`.
- Extract the target from the `content` attribute: split on the first `;`, strip a
  case-insensitive `url=` prefix, strip surrounding quotes.
- Absolutize via `UrlResolver::resolve($baseUrl, $target)`.
- Return the absolute URL only when its scheme is `http`/`https`; otherwise `null`.

No fetching, no side effects. A `content` with no `url=`, a non-zero delay, a
non-`http(s)` scheme, or no matching meta all yield `null`.

### 3.3 `LandingChallenge` — new, `Service/Reader`, `final readonly`

```
matches(string $html): bool
```

A short vendor table (one row per vendor) of **structural** markers, never prose:

| Vendor | Marker(s) |
|---|---|
| Cloudflare | `cf-browser-verification` / `/cdn-cgi/challenge-platform/` / `id="challenge-form"` |
| Anubis | `anubis_challenge` |
| SiteGround | `/.well-known/sgcaptcha/` |

Markers are machine/vendor strings unlikely to appear in article prose, so a
pre-extraction gate cannot reject a legitimate article that merely mentions
"captcha". New vendors are added per observed case, same caution as
`BotChallengePage` (#424).

### 3.4 `HtmlPageFetcher` — the loop (change)

Gains `MetaRefreshTarget` + `LandingChallenge` constructor dependencies. `fetch()`
becomes a bounded loop. `HtmlPageFetcher` stays the single owner of request
options, body read, size cap, and error snippet, so the body is read exactly once
per hop and reused for the final `PageResponse`.

## 4. Data flow & the shared budget

```
remainingHops = MAX_REDIRECTS (5); target = url
loop:
  landed = follow(target, options, remainingHops)   // consumes 0..remaining HTTP redirects
  remainingHops -= landed.hops
  if not 2xx:                    snippet + PageFetchException          (unchanged)
  body = read + cap (landed)                                            (unchanged per hop)
  head = first LANDING_SCAN_LENGTH bytes of body
  if challenge.matches(head):    cancel; throw PageFetchException("challenge interstitial")
  next = metaRefresh.within(head, landed.url)
  if next === null:              return PageResponse(landed.url, body)  // the article
  if remainingHops < 1:          cancel; throw PageFetchException("more than 5 redirects")
  remainingHops--; cancel; target = next
```

- **One budget.** HTTP redirects (subtracted via `landed.hops`) and meta hops
  (`remainingHops--`) draw from the same counter, so `3xx -> meta -> 3xx` cannot
  exceed `MAX_REDIRECTS`. Each re-entered `follow()` receives the remaining count,
  so it cannot overspend internally either.
- **Challenge is checked before meta-follow.** A SiteGround-style gate that is both
  a challenge and a meta-refresh (to its captcha) is rejected, not followed in.
- **SSRF is automatic.** An `http(s)` meta target is re-guarded by
  `UrlGuard::assertSafe()` on the next `follow()` entry (a private-IP target throws
  `SsrfBlockedException` -> `RedirectChainException` -> `PageFetchException`).
  Non-`http(s)` targets never re-enter — `MetaRefreshTarget` returns `null` and the
  loop returns the landed body.

## 5. Error handling & fallback wiring

No new client-facing behavior. Every rejection path throws `PageFetchException`,
which `ArticleExtractor` already maps to `ExtractionResult::failed(url, 'fetch', …)`
at HTTP 200; the frontend already renders the stored RSS/feed body on
`reason: 'fetch'` — the exact 403 UX. So:

- challenge body -> RSS fallback,
- budget exhaustion (self-loop or mixed chain) -> RSS fallback,
- SSRF-blocked meta target -> RSS fallback,
- a followed meta-refresh -> normal extraction against the **article** URL (the
  correct base for relative image resolution).

The native-iOS constraint holds: JSON in, `application/problem+json`/JSON out, no
new client coupling, no browser-only inputs.

## 6. Cost & correctness notes

- **Head window only.** Detectors see `mb_substr($body, 0, LANDING_SCAN_LENGTH)`
  (~20 KB, matching the existing snippet-scan constant), so a 3 MB body is never
  Dom-parsed to find a `<head>` tag; the full-document parse stays downstream in
  `FetchedPageNormalizer`.
- **Zero-delay only.** The delay must parse to exactly `0`; a timed reload
  (`content="5; url=…"`) is left alone.
- **PHPMD-clean.** The loop delegates to short helpers (`land`, `content`,
  `metaRefreshTarget`, `assertNotChallenge`); final method shapes settle during
  implementation to keep complexity/length green in a touched file.

## 7. Test plan (TDD, fixture-driven)

- **`MetaRefreshTargetTest` (unit):** zero-delay absolute -> target; zero-delay
  relative -> resolved absolute; `content="5;…"` -> null; no meta -> null; meta
  without `url=` -> null; `mailto:`/`javascript:` target -> null; capitalized
  `Refresh`/`URL` and quoted target -> target.
- **`LandingChallengeTest` (unit):** Cloudflare / Anubis / SiteGround fixtures ->
  true; an article body that merely mentions "captcha"/"are you a robot" in prose
  -> **false** (precision guard).
- **`HtmlPageFetcherTest` (functional, real `RedirectFollower` + real `UrlGuard`
  with stub DNS + `MockHttpClient`):**
  - `200 meta -> 200 article` resolves to the article body + its finalUrl,
  - meta self-loop -> `PageFetchException` "more than 5 redirects",
  - mixed `3xx -> meta -> 3xx` past 5 -> `PageFetchException` (proves the shared
    budget),
  - `200` challenge body -> `PageFetchException`,
  - meta target whose host maps to a private IP -> `PageFetchException` (proves the
    SSRF re-guard),
  - non-zero-delay meta -> **not** followed, returns that page (proves "left
    alone").
- **`RedirectFollowerTest`:** `hops` is `0` on a direct land and `N` after `N`
  redirects. `infection:diff` mutates the new arithmetic and the `=== 0` / `< 1`
  boundaries, so these assertions must be exact.

## 8. Coordination / risk

`RedirectFollower` and `LandedResponse` are **shared** with the feed-stream locator
and `MediaLanding`, and another session is live in this checkout. The change is
additive (one new field, set at one construction site). Before editing, re-check
`git status`/diff and avoid any `checkout`/`reset`/`stash`. Branch name:
`feature/892-reader-landing-page-validation`.

Gates: `composer check` (cs + stan + tramp) + `composer md` + `php bin/phpunit` +
`composer infection:diff` + PhpStorm inspections + a dev-log scan.
