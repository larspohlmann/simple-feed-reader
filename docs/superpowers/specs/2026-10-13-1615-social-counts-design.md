# #1615 — live like, repost, reply and quote counts in the article view (design)

Issue: https://github.com/larspohlmann/simple-feed-reader/issues/1615. Part of #1596, builds on #1600 (thumbs up).
Design agreed 2026-10-13: public sources first, counts without an account, client-side like adjustment, and a counts row
below the post that carries the like button (layout checked on phone screenshots).

## Behaviour

- Opening a social post in the article view shows its current counts: likes, reposts/boosts, replies, and quotes on
  Bluesky. List rows and magazine cards show no counts.
- Counts show for every social post, whether or not the user has an account on its network.
- A slow or failing count source never delays the article; failure shows no numbers and no toast.
- Counts are never stored in the database.

## Backend

### Endpoint

`GET /api/entries/{id}/social` → `{likes: int, reposts: int, replies: int, quotes: int|null}` (`quotes` is null on
Mastodon).

- New thin controller in `Controller/Api`, the `EntryCommentsController` pattern: `getOneSubscribedForUser`, then a new
  `social_counts` rate limiter (sliding window, 120 per 5 min, in `rate_limiter.yaml`), then the service, then a
  `src/Http/SocialCountsJson` mapper.
- Problems reuse `SocialInteractionProblems`: not a post → `409 social_post_unsupported`; every source failed →
  `502 social_network_unreachable` or `503 social_network_throttled` with `Retry-After`. No new exception arm.

### `Service/Social/SocialCounts::of(User $user, Entry $entry): SocialCountsModel`

1. `SocialPostAddress::of($entry)`; null → `NotASocialPostException`.
2. Read the cache (key: network + address, hashed). A hit returns.
3. Ask the network's public source. An answer is cached and returned.
4. If the public source failed or does not apply, and the user has an acting account on that network, ask through the
   acting account (step below). An answer is cached and returned.
5. Otherwise rethrow the public source's failure (or `SocialNetworkUnreachableException` when no source applied).

`SocialCountsModel` (Social `Model/`): `likes`, `reposts`, `replies`, `?quotes`.

### Public sources

A per-network strategy behind a new interface in its own folder (`Social/PostCountsSource/PostCountsSourceInterface`,
tagged `app.social_post_counts_source`, keyed by `network()` like `PostResolver`), method
`countsOf(string $address): SocialCountsModel`, throwing the existing Social exceptions; "does not apply" is its own
exception so the caller falls back.

- **Bluesky:** `AppViewClient::posts([$address])` (public AppView, SSRF-guarded, host throttle) → `likeCount`,
  `repostCount`, `replyCount`, `quoteCount`. A post absent from the answer → `SocialPostNotFoundException`.
- **Mastodon (origin instance):** derive the status id from the address shape — `https://{host}/users/{name}/statuses/{id}`
  or `https://{host}/@{name}/{id}` — and send an unauthenticated GET to `https://{host}/api/v1/statuses/{id}` through
  `MastodonApiClient` (GuardedApiClient; UrlGuard is never bypassed). Accept the answer only when the status's `uri` or
  `url` equals the address; otherwise it does not apply. An address of another shape (Misskey, Pixelfed, …) does not
  apply. Reads `favourites_count`, `reblogs_count`, `replies_count`; `quotes` is null.

### Acting-account fallback

- `SocialPostModel` gains `?SocialCountsModel $counts`. `BlueskyPostResolver` reads the counts from the `getPosts` answer
  it already fetches; `MastodonPostResolver` reads them from the `resolve=true` search status it already fetches
  (instance-local counts, accepted for the fallback only).
- The fallback is `SocialPostFinder::find($user, $entry)` under `ActingAccountGuard::actAs`, as for likes. Only the
  acting account `ActingAccountChooser` picks is asked; no other accounts are tried.

### Cache

- New filesystem pool `social.counts.cache`, `default_lifetime: 120`. Entries expire after 120 s. Shared across users:
  counts are public.
- Failures are not cached (the AppView host throttle already keeps a failing AppView from being hammered).
- `SocialLikes::like` and `unlike` drop that post's entry, so a reopen after a like fetches fresh counts.

### Entry JSON

- `social` becomes `{network, isLiked, canLike}` and is set for **every** entry that is a social post.
  `EntryListRowEnricher` stops filtering by the user's networks; `canLike` = the user has an account on that network.
  `isLiked` stays the local like-row read.
- List rows key the thumbs up off `canLike`, so they look as before.

## Frontend

### Data

- `reader-api.socialCounts(entryId): Observable<SocialCountsDto>`.
- Root `SocialCountsService`, the `comments.service.ts` pattern: per-entry `Map<number, WritableSignal<SocialCountsState>>`
  with states idle, loading, ok, failed; cap 50; fresh for 120 s; a failed load retried after 60 s; cleared on
  `onIdentityChange`. No throttled state: a 503 is a failure, which shows nothing.
- `EntrySocialDto` gains `canLike`.

### Counts row (`app-social-counts`, `reader/article/`, sibling `.scss`)

- Mounted in the reader view directly below the post: after the content and its link cards, before the categories,
  under `@if (e.social)`. It renders as soon as the article does; the counts call fires when the row scrolls into view
  (the comments component's viewport trigger).
- Order: like, reposts (`repeat`), replies (`chat_bubble`), quotes (`format_quote`, only when not null). Each count is a
  muted, small, tabular number with a translated `title`/`aria-label` (de, en).
- **Like:** when `canLike`, a pill button (accent colour, `--border-strong` outline, `--radius-pill`); liked = filled
  icon, accent outline, `--accent-soft` background. It calls `EntryActionHandler.like(entry)`, keeps the #1600 hints
  (`reader.actionHint.like.*` / `unlike.*`) and is always shown, with the number appearing once counts load; on failure
  it is the thumb alone. Without `canLike` the like count is a plain muted count like the others.
- Displayed likes = loaded likes + (current `isLiked` − `isLiked` when the counts loaded), so the store's optimistic
  toggle and its rollback move the number with no extra state.
- Numbers use `Intl.NumberFormat(activeLang, {notation: 'compact'})` (1234 → 1.2K).
- Nothing renders for the row when there is neither a like button nor loaded counts.

### Article header

- The article view's `app-entry-actions` drops its thumbs up through a new input (`[like]="false"`, the `[audio]`
  pattern). List rows keep it.

## Done when (tests)

- Bluesky public source maps the four counts; a post absent from the answer is not-found.
- Mastodon origin: both address shapes parse; another shape does not apply; a status whose `uri`/`url` differs from the
  address does not apply; counts map, `quotes` null.
- Order: cache hit asks no source; public answer is cached; public failure falls back to the acting account; no account
  rethrows the public failure; the like and unlike calls drop the cache entry.
- Both resolvers carry counts on `SocialPostModel`.
- Enricher: `social` set for a post on a network without an account, with `canLike` false.
- Functional: the endpoint answers counts; a non-post answers 409 `social_post_unsupported`; the limiter applies.
- Jest: service states, freshness, identity clear; row hides counts on failure but keeps the like button; quotes only
  when present; compact format; the like number follows `isLiked`; article header has no thumbs up, list rows do.

## Constraints

CLAUDE.md in full: Clean Code, role folders (§10), no Service-module cycle, domain knows no HTTP, phptramp, PHPMD clean on
touched `src`, PHPStan level max, no needless comments, `Response::HTTP_*` everywhere, iOS-viable JSON endpoint (problem
JSON, bearer auth). Frontend: no hex or px in `.scss`, styles in a sibling file, Prettier 100 columns, translations in de
and en. Gates: `composer check`, `composer md`, `composer test:parallel`, the MySQL leg, `composer infection:diff`,
PhpStorm lint, `npm run check` in the frontend container. Commits `type(#1615): …`, no attribution. Never deploy.
