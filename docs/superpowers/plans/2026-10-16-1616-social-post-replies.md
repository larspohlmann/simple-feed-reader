# #1616 Social post replies in the article view — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The article view of a Bluesky or Mastodon post shows the post's replies — threaded, with avatars and full embeds — in the existing comments panel, cached for two hours with a visible age and a refresh button.

**Architecture:** `Service/Comments` gains a source interface (`CommentsSource/CommentsSourceInterface`) and a cache of raw answers; today's feed reading becomes `CommentsFeedSource`, and `Service/Social` implements the interface as `SocialRepliesSource` (the lower module owns the interface, architecture §9). Social fetches a thread per network (`ReplyThreadSource/*`), reads it into a reply tree, and `ReplyThreadOrder` flattens the tree into depth-tagged comments under the agreed order and caps. The frontend comments panel renders depth, avatars and embeds with the existing `app-post-media`, `app-link-card` and `app-quoted-post`, and hosts its own autoplay coordinator.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (PHPUnit 12, PHPStan max, PHPMD, phptramp), Angular 20 standalone + signals (Jest, Playwright).

**Spec:** GitHub issue #1616 plus the grilling decisions recorded below (session 2026-10-16). There is no separate spec file; the decisions section *is* the spec.

## Decisions (the spec — do not relitigate)

| # | Decision |
|---|---|
| Q1 Bluesky | Acting account's authenticated `getPostThread` (applies blocks/mutes) when the user has one; else the public AppView. Account failure falls back to public. |
| Q1b Mastodon | Acting account's instance `/api/v1/statuses/:id/context` first (signed in: up to 4096 descendants, mutes/blocks applied, 4.4+ fetches missing replies); anonymous origin `/context` only when there is no account or the account path fails (logged-out cap: 60 descendants, depth 20). |
| Q1c | An answer carrying the `Mastodon-Async-Refresh` header (instance still gathering replies) is cached **60 s**; every other answer **7200 s**. |
| Q2 | Replies show without a connected account (public paths). |
| Q3 | Each comment carries `depth` (0 = top level); the backend sends comments in thread order (depth-first). |
| Q4 | Max depth 6 (depths 0–5), max 100 comments, container parameters `social_replies_max_depth` / `social_replies_max_count`. Depth-first fill; everything cut is counted in `omitted`. |
| Q5/Q13 | Top-level replies newest first; replies inside a thread oldest first. |
| Q6/Q14 | Full embeds as **structured** fields per comment: `media` (EntryMedium list), `linkCard`, `quote`, built by the same helpers the timeline uses, rendered by `app-post-media`, `app-link-card` (layout `article`), `app-quoted-post` (layout `article`). Avatar, display name, `@handle`, profile link, time linked to the reply, sanitized html. Mastodon CW → `<details><summary>CW: …</summary>…</details>`. `byEntryAuthor` = reply by the post's own author. |
| Q7 | Drop `#blockedPost`, `#notFoundPost`, replies by muted/blocked authors (Bluesky viewer state), non-public/unlisted Mastodon statuses — each **with its whole subtree**. |
| Q8/Q17 | Server cache of 120 min (7200 s) for **both** sources (comment feeds and social replies); response carries `fetchedAt`; the panel shows "Updated N min ago". |
| Q9 | Social posts get `comments: 'auto'` and `discussionUrl` = the post's web URL at render time (EntryJson), nothing stored. A social post uses the social source even if it had a comments feed. |
| Q10 | `auto` load. |
| Q11/Q16/Q22 | The panel's refresh button sends `?refresh=1` to `/comments` **and** `/social` (counts) — never reloads the article. Rate limits count only real network fetches (cache misses and refreshes): `comments` (30/5 min) for feeds, new `social_replies` (120/5 min) for social. Client freshness stays 1 h. |
| Q12 | No link from the counts row to the comments. |
| Q15/Q23 | Reply videos autoplay when the per-device "list autoplay" preference is on, via a coordinator the comments panel provides — one video at a time; starting the post's own video stops the reply video and vice versa; the post's own video never autoplays. |
| Q24 | Settings label/hint text changes to cover comments; storage key `listAutoplay` unchanged. |
| Q19 | `omitted > 0` turns the existing "All comments" link into "N more on Bluesky/Mastodon". |
| Q18 | Social failures map onto the existing panel states: `failed` (unreachable / not found), `throttled` + `retryAfter`. |

## Global Constraints

- Branch `feature/1616-social-post-replies` off `develop`; commits `type(#1616): …`; no Claude attribution anywhere.
- Every outbound call goes through `GuardedApiClient` / the SSRF-guarded fetcher (`UrlGuard` never bypassed).
- JSON in, `application/problem+json` out; no browser-only inputs (native iOS client must work).
- Every reply body through `EntrySanitizer` — remote HTML is untrusted.
- `CLAUDE.md` PHP rules apply in full: `final readonly`, intent-revealing names, no boolean flag parameters, guard clauses, no comment unless a future reader gets the code wrong without it, one role per folder, no `@phpstan-ignore` without reason, PHPMD-clean on every touched `src` file, HTTP statuses as `Response::HTTP_*`.
- Service modules form no cycle: `Comments` must never name `App\Service\Social`; `Social` may name `App\Service\Comments`.
- Hex colours / ad-hoc px forbidden in component `.scss`; styles in sibling `.scss`.
- Frontend tests run in the container: `docker compose exec -T frontend npm test -- <path>`; never two Jest runs at once.
- Gates before the PR: `composer check`, `composer md`, `php bin/phpunit` (SQLite), `docker compose exec php composer test` (MySQL), `composer infection:diff`, `npm run check` (container), Playwright smoke for the new spec.

## File map

**Backend — Comments module (`backend/src/Service/Comments/`)**
- Create `Model/CommentAuthorModel.php` — name, handle, profile url, avatar url.
- Create `Model/CommentEmbedsModel.php` — media, link card, quote.
- Modify `Model/EntryCommentModel.php` — author model, depth, embeds, `atDepth()`.
- Create `Model/CommentsPageModel.php` — comments + omitted.
- Modify `Model/CommentsResultModel.php` — `ok(page, fetchedAt)`, `omitted`, `fetchedAt`.
- Create `Model/CommentsAnswerModel.php` — a source's raw answer + whether it is still gathering.
- Create `CommentsSource/CommentsSourceInterface.php`, `CommentsSource/CommentsFeedSource.php`.
- Create `CommentsCache.php`.
- Create `Exception/CommentsThrottledException.php`, `Exception/CommentsUnavailableException.php`.
- Modify `CommentsLoader.php` — chooses a source, cache, `load()` / `refreshed()`.
- Modify `backend/src/Http/CommentsJson.php`, `backend/src/Controller/Api/EntryCommentsController.php`.

**Backend — Social module (`backend/src/Service/Social/`)**
- Create `ReplyThreadSource/ReplyThreadSourceInterface.php`, `ReplyThreadSource/BlueskyReplyThreadSource.php`, `ReplyThreadSource/MastodonReplyThreadSource.php`.
- Create `BlueskyThreadReplies.php`, `MastodonContextReplies.php` (readers: answer → reply tree).
- Create `ReplyThreadOrder.php` (tree → page under order and caps).
- Create `SocialRepliesSource.php` (implements `CommentsSourceInterface`).
- Create `Model/ReplyNodeModel.php`.
- Create `Support/MastodonAuthor.php` (name/handle/avatar/profile of an account node), `Support/MastodonCard.php` (status `card` → `LinkCardContent`).
- Modify `Support/MastodonStatusAddress.php` (`contextApiUrlOrNull`, `authorPrefixOrNull`), `Support/MastodonTimelinePage.php`, `Support/MastodonQuotedStatus.php`, `Support/MastodonAccount.php` (use the new Support helpers).
- Modify `SocialCounts.php` (`refreshed()`), `backend/src/Controller/Api/EntrySocialCountsController.php` (`?refresh=1`).
- Modify `backend/src/Service/Bluesky/AppViewClient.php` (`thread()`), `backend/src/Service/Bluesky/Support/AtPostUri.php` (`authorityOf()`).
- Modify `backend/src/Http/EntryJson.php` (social `comments` / `discussionUrl`).
- Config: `backend/config/packages/cache.yaml` (`comments.cache`), `rate_limiter.yaml` (`social_replies`), `services.yaml` (parameters).

**Frontend (`frontend/src/app/`)**
- Modify `reader/models.ts`, `reader/reader-api.ts`.
- Modify `reader/article/entry-comments/comments.service.ts`, `entry-comments.component.{ts,html,scss}`.
- Modify `reader/article/social-counts/social-counts.service.ts` (`reload`).
- Modify `reader/media/autoplay/list-autoplay-coordinator.ts` (doc line only).
- Modify `frontend/public/i18n/en.json`, `de.json`.
- Create `frontend/e2e/social-replies.spec.ts`.

---

### Task 1: Comment shape — author, depth, embeds, page, fetchedAt

Reshape the comments models and their JSON so later tasks have the fields. Feed comments keep working and now pass their avatar.

**Files:**
- Create: `backend/src/Service/Comments/Model/CommentAuthorModel.php`, `CommentEmbedsModel.php`, `CommentsPageModel.php`
- Modify: `backend/src/Service/Comments/Model/EntryCommentModel.php`, `CommentsResultModel.php`, `backend/src/Service/Comments/CommentsLoader.php` (only the `comments()` mapping and `ok()` call), `backend/src/Http/CommentsJson.php`
- Test: `backend/tests/Service/Comments/CommentsLoaderTest.php`, create `backend/tests/Http/CommentsJsonTest.php`, create `backend/tests/Service/Comments/Model/EntryCommentModelTest.php`

**Interfaces — Produces:**
```php
final readonly class CommentAuthorModel {
    public function __construct(public ?string $name, public ?string $handle = null, public ?string $profileUrl = null, public ?string $avatarUrl = null) {}
}
final readonly class CommentEmbedsModel {
    /** @param list<EntryMedium> $media */
    public function __construct(public array $media = [], public ?LinkCardContent $linkCard = null, public ?QuotedPostContent $quote = null) {}
}
final readonly class EntryCommentModel {
    public function __construct(
        public CommentAuthorModel $author,
        public ?string $url,
        public ?\DateTimeImmutable $publishedAt,
        public string $html,
        public bool $byEntryAuthor,
        public CommentEmbedsModel $embeds = new CommentEmbedsModel(),
        public int $depth = 0,
    ) {}
    public function atDepth(int $depth): self;
}
final readonly class CommentsPageModel {
    /** @param list<EntryCommentModel> $comments */
    public function __construct(public array $comments, public int $omitted = 0) {}
}
// CommentsResultModel
public static function ok(CommentsPageModel $page, \DateTimeImmutable $fetchedAt): self;
public int $omitted;               // 0 unless ok
public ?\DateTimeImmutable $fetchedAt;
```
JSON of one comment (`CommentsJson`):
```json
{"author": "Ann", "authorHandle": "@ann.bsky.social", "authorUrl": "…", "authorAvatarUrl": "…",
 "url": "…", "publishedAt": "ATOM", "html": "…", "byEntryAuthor": false, "depth": 0,
 "media": [EntryMedia::toJsonList], "linkCard": LinkCardRow|null, "quote": QuoteRow|null}
```
Ok envelope: `{"status": "ok", "comments": […], "omitted": 0, "fetchedAt": "ATOM"}`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Comments/Model/EntryCommentModelTest.php`:
```php
final class EntryCommentModelTest extends TestCase
{
    public function testAtDepthKeepsEverythingButTheDepth(): void
    {
        $comment = new EntryCommentModel(new CommentAuthorModel('Ann'), 'https://x.test/1', null, '<p>Hi</p>', true);

        $nested = $comment->atDepth(3);

        self::assertSame(3, $nested->depth);
        self::assertSame(0, $comment->depth);
        self::assertEquals($comment->author, $nested->author);
        self::assertSame('<p>Hi</p>', $nested->html);
        self::assertTrue($nested->byEntryAuthor);
    }
}
```
`backend/tests/Http/CommentsJsonTest.php` — one test per envelope:
```php
public function testAnOkResultCarriesEveryCommentFieldTheOmittedCountAndTheFetchTime(): void
{
    $quote = new QuotedPostContent('https://bsky.app/profile/b/post/1', 'Bea', 'b.test', null, null, 'quoted', [], null);
    $card = new LinkCardContent('https://site.test/a', 'A page', null, null, 'site.test');
    $medium = new EntryMedium('https://cdn.test/v.m3u8', 'video', 16, 9, 'https://cdn.test/v.jpg');
    $comment = new EntryCommentModel(
        new CommentAuthorModel('Ann', '@ann.test', 'https://bsky.app/profile/ann.test', 'https://cdn.test/a.jpg'),
        'https://bsky.app/profile/ann.test/post/2',
        new \DateTimeImmutable('2026-10-16T10:00:00+00:00'),
        '<p>Hi</p>',
        false,
        new CommentEmbedsModel([$medium], $card, $quote),
        2,
    );

    $json = CommentsJson::one(CommentsResultModel::ok(
        new CommentsPageModel([$comment], 7),
        new \DateTimeImmutable('2026-10-16T11:00:00+00:00'),
    ));

    self::assertSame('ok', $json['status']);
    self::assertSame(7, $json['omitted']);
    self::assertSame('2026-10-16T11:00:00+00:00', $json['fetchedAt']);
    self::assertSame([
        'author' => 'Ann',
        'authorHandle' => '@ann.test',
        'authorUrl' => 'https://bsky.app/profile/ann.test',
        'authorAvatarUrl' => 'https://cdn.test/a.jpg',
        'url' => 'https://bsky.app/profile/ann.test/post/2',
        'publishedAt' => '2026-10-16T10:00:00+00:00',
        'html' => '<p>Hi</p>',
        'byEntryAuthor' => false,
        'depth' => 2,
        'media' => EntryMedia::toJsonList([$medium]),
        'linkCard' => ['url' => 'https://site.test/a', 'title' => 'A page', 'description' => null, 'imageUrl' => null, 'siteName' => 'site.test'],
        'quote' => QuotedPostJson::of($quote),
    ], $json['comments'][0]);
}

public function testAThrottledResultSaysWhenToAskAgain(): void { /* ['status' => 'throttled', 'retryAfter' => 30] exactly */ }
public function testAFailedResultCarriesOnlyItsStatus(): void { /* ['status' => 'failed'] exactly */ }
```

In `CommentsLoaderTest`, add:
```php
public function testAFeedCommentCarriesItsAuthorsAvatar(): void
```
Use an Atom/RSS fixture whose item has an author avatar the parser reads into `ParsedEntryModel::$authorAvatarUrl` (grep `authorAvatarUrl` in `backend/src/Service/Parser` for which element feeds it; add a minimal inline feed string if no fixture has one) and assert `$comments[0]->author->avatarUrl`. Update the existing assertions from `$comment->author` / `->authorUrl` to `->author->name` / `->author->profileUrl`.

- [ ] **Step 2: Run, verify they fail**

Run: `cd backend && php bin/phpunit tests/Http/CommentsJsonTest.php tests/Service/Comments`
Expected: FAIL — classes `CommentAuthorModel`, `CommentsPageModel` not found.

- [ ] **Step 3: Implement**

- The three new models exactly as in *Interfaces*. `EntryCommentModel::atDepth()` returns `new self(...)` with every field copied and the new depth.
- `CommentsResultModel`: private constructor gains `public int $omitted = 0, public ?\DateTimeImmutable $fetchedAt = null`; `ok(CommentsPageModel $page, \DateTimeImmutable $fetchedAt)` → `new self(CommentsStatus::Ok, $page->comments, null, $page->omitted, $fetchedAt)`.
- `CommentsLoader::comments()` builds `new CommentAuthorModel($item->author, null, $item->authorUrl, $item->authorAvatarUrl)`; `load()` wraps with `CommentsResultModel::ok(new CommentsPageModel($comments), $this->clock->now())` — inject `Psr\Clock\ClockInterface $clock` (Task 2 replaces this method anyway; keep it minimal).
- `CommentsJson`: ok branch adds `'omitted'` and `'fetchedAt' => $result->fetchedAt?->format(\DateTimeInterface::ATOM)`; `comment()` emits the fields in the order of the test. Reuse `QuotedPostJson::of()` and `EntryMedia::toJsonList()`; the link card row is the same five keys `EntryJson::linkCard()` builds — move that private method to a public static `LinkCardJson::of(LinkCardContent): array` in `backend/src/Http/LinkCardJson.php` (with the `LinkCardRow` phpstan type moved there and imported by `EntryJson`) so both mappers share it (second user; the type already exists once).

- [ ] **Step 4: Run, verify they pass**

Run: `cd backend && php bin/phpunit tests/Http tests/Service/Comments tests/Controller/Api/EntryCommentsControllerTest.php`
Expected: PASS. Fix any `EntryCommentsControllerTest` assertion on the old comment keys to the new names.

- [ ] **Step 5: Commit**
```bash
git add backend/src/Service/Comments backend/src/Http backend/tests
git commit -m "feat(#1616): comments carry author avatar, handle, depth, embeds and fetch time"
```

---

### Task 2: Comment sources, the answer cache, and refresh

Introduce the source seam and the two-hour cache of raw answers; today's feed reading becomes the first source. The rate limit moves from the controller into the source so only real fetches spend it.

**Files:**
- Create: `backend/src/Service/Comments/CommentsSource/CommentsSourceInterface.php`, `CommentsSource/CommentsFeedSource.php`, `backend/src/Service/Comments/CommentsCache.php`, `backend/src/Service/Comments/Model/CommentsAnswerModel.php`, `backend/src/Service/Comments/Exception/CommentsThrottledException.php`, `Exception/CommentsUnavailableException.php`
- Modify: `backend/src/Service/Comments/CommentsLoader.php`, `backend/src/Controller/Api/EntryCommentsController.php`, `backend/config/packages/cache.yaml`, `backend/config/services.yaml`
- Test: create `backend/tests/Service/Comments/CommentsSource/CommentsFeedSourceTest.php` (move the fetch/parse/throttle tests out of `CommentsLoaderTest` here), rewrite `backend/tests/Service/Comments/CommentsLoaderTest.php` around a fake source, create `backend/tests/Support/FakeCommentsSource.php`, extend `backend/tests/Controller/Api/EntryCommentsControllerTest.php`

**Interfaces — Produces:**
```php
#[AutoconfigureTag('app.comments_source')]
interface CommentsSourceInterface
{
    public function serves(Entry $entry): bool;

    /** Who gets the same answer: the cache scope (a feed URL; a post plus the viewing account). */
    public function audienceOf(User $user, Entry $entry): string;

    /**
     * @throws CommentsThrottledException
     * @throws CommentsUnavailableException
     * @throws RateLimitedException
     */
    public function fetch(User $user, Entry $entry): CommentsAnswerModel;

    /** @throws CommentsUnavailableException */
    public function read(Entry $entry, string $answer): CommentsPageModel;
}

final readonly class CommentsAnswerModel {
    private function __construct(public string $body, public bool $isStillGathering) {}
    public static function settled(string $body): self;
    public static function stillGathering(string $body): self;
}

final class CommentsThrottledException extends \RuntimeException {
    public function __construct(string $message, public readonly int $retryAfterSeconds, ?\Throwable $previous = null);
}
final class CommentsUnavailableException extends \RuntimeException {}

final readonly class CommentsCache {
    public function find(string $key): ?CachedCommentsModel;  // see below
    public function remember(string $key, CommentsAnswerModel $answer, \DateTimeImmutable $fetchedAt): void;
    public function forget(string $key): void;
}
// Model/CachedCommentsModel: body + fetchedAt, toCacheEntry()/fromCacheEntryOrNull(mixed) like SocialCountsModel.

final readonly class CommentsLoader {
    /** @throws NoCommentsFeedException when no source serves the entry */
    public function load(User $user, Entry $entry): CommentsResultModel;
    /** As load(), but asks the source again whatever is cached. */
    public function refreshed(User $user, Entry $entry): CommentsResultModel;
}
```
Why raw answers are cached, not models: a cache entry holds a string and a timestamp, so no nested value object has to round-trip through `toCacheEntry()`, and an answer cached before a deploy is read by the new code.

- [ ] **Step 1: Write the failing loader tests (fake source)**

`backend/tests/Support/FakeCommentsSource.php`: implements the interface; constructor takes `string $answer`; public `array $fetches = []` (records entry ids); `failWith(\Throwable)`; `stillGathering()` makes the next fetch return `CommentsAnswerModel::stillGathering`; `serves()` returns a settable bool (default true); `audienceOf()` returns `'fake:' . $entry->getGuid()`; `read()` returns `new CommentsPageModel([new EntryCommentModel(new CommentAuthorModel('Reader'), null, null, $answer, false)])` so tests can see which answer was read.

`CommentsLoaderTest` (KernelTestCase is not needed — construct the loader by hand with an `ArrayAdapter`-backed `CommentsCache`, a `MockClock('2026-10-16 12:00:00')`, lifetimes 7200 / 60):
```php
public function testAFreshFetchIsReadAndStamped(): void
// load → status ok, comments[0]->html === 'answer-1', fetchedAt == clock now, one fetch

public function testASecondLoadIsAnsweredFromTheCacheWithTheFirstFetchTime(): void
// load, clock->sleep(600), load → one fetch; fetchedAt is the first time

public function testRefreshedAsksTheSourceAgain(): void
// load, refreshed → two fetches; fetchedAt is the later time

public function testAnAnswerStillGatheringExpiresAfterAMinute(): void
// stillGathering(); load; clock->sleep(61); load → two fetches
// (ArrayAdapter must share the MockClock: new ArrayAdapter(clock: $clock))

public function testASettledAnswerLastsTwoHours(): void
// load; sleep(7199); load → one fetch; sleep(2); load → two fetches

public function testAThrottledSourceReportsWhenToRetry(): void
// failWith(new CommentsThrottledException('slow', 30)) → status throttled, retryAfter 30

public function testAnUnavailableSourceReportsFailedAndCachesNothing(): void
// failWith(new CommentsUnavailableException('down')) → failed; recover; load → fetches again

public function testAnEntryNoSourceServesIsRefused(): void
// serves=false → expectException(NoCommentsFeedException::class)

public function testTheFirstSourceThatServesTheEntryAnswers(): void
// two fakes, first serves=false, second true → only the second fetched
```

- [ ] **Step 2: Run, verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Comments/CommentsLoaderTest.php`
Expected: FAIL — `CommentsSourceInterface` not found.

- [ ] **Step 3: Implement the seam, cache and loader**

`CommentsLoader`:
```php
final readonly class CommentsLoader
{
    /** @param iterable<CommentsSourceInterface> $sources */
    public function __construct(
        #[AutowireIterator('app.comments_source')]
        private iterable $sources,
        private CommentsCache $cache,
        private ClockInterface $clock,
    ) {
    }

    public function load(User $user, Entry $entry): CommentsResultModel
    {
        $source = $this->sourceFor($entry);
        $key = self::keyOf($source, $user, $entry);
        $cached = $this->cache->find($key);
        if (null !== $cached) {
            return $this->read($source, $entry, $cached);
        }

        return $this->fetched($source, $key, $user, $entry);
    }

    public function refreshed(User $user, Entry $entry): CommentsResultModel
    {
        $source = $this->sourceFor($entry);

        return $this->fetched($source, self::keyOf($source, $user, $entry), $user, $entry);
    }
    // fetched(): try fetch → remember(key, answer, now) → read(new CachedCommentsModel(body, now));
    //   catch CommentsThrottledException → CommentsResultModel::throttled($e->retryAfterSeconds)
    //   catch CommentsUnavailableException → CommentsResultModel::failed()
    // read(): try ok($source->read($entry, $cached->body), $cached->fetchedAt)
    //   catch CommentsUnavailableException → CommentsResultModel::failed()
    // sourceFor(): first $source->serves($entry), else throw NoCommentsFeedException('No comments source serves the entry.')
    // keyOf(): $source::class . ' ' . $source->audienceOf($user, $entry)
}
```
`fetched()` takes four values; if PHPMD or phptramp complains, fold `$source`, `$key`, `$entry` into a per-call `Pass/CommentsRequest` (`new` in `load()`/`refreshed()`), not a longer signature.

`CommentsCache` (pool `comments.cache`):
```php
public function __construct(
    #[Autowire(service: 'comments.cache')] private CacheItemPoolInterface $cache,
    #[Autowire('%comments_cache_lifetime%')] private int $settledLifetime,
    #[Autowire('%comments_gathering_cache_lifetime%')] private int $gatheringLifetime,
) {}
// remember(): item->set((new CachedCommentsModel($answer->body, $fetchedAt))->toCacheEntry());
//             item->expiresAfter($answer->isStillGathering ? $this->gatheringLifetime : $this->settledLifetime)
// key: 'comments.' . hash('xxh128', $key)
```
`CachedCommentsModel::toCacheEntry()` → `['body' => string, 'fetchedAt' => int (unix)]`; `fromCacheEntryOrNull()` validates both types, else null (UTC: `(new \DateTimeImmutable('@' . $ts))`).

Config:
```yaml
# services.yaml parameters
comments_cache_lifetime: 7200
comments_gathering_cache_lifetime: 60
social_replies_max_depth: 6
social_replies_max_count: 100
```
```yaml
# cache.yaml, next to social.counts.cache
            # A comments answer per source and audience (#1616): two hours; a Mastodon thread still gathering replies one minute.
            comments.cache:
                adapter: cache.adapter.filesystem
```
(Check whether the test env overrides pools — `grep -n "social.counts" backend/config/packages/test/*.yaml`; mirror any override for `comments.cache`.)

- [ ] **Step 4: Write the failing feed-source tests, then move the feed logic**

`CommentsFeedSourceTest` takes over every existing fetch/parse/throttle/skip-own-guid/sanitize/OP test from `CommentsLoaderTest` (same fixtures, `StubFeedFetcher`, `HostThrottle` with `MockClock`), rewritten against `fetch()` + `read()`:
```php
public function testServesAnEntryWithACommentsFeed(): void
public function testServesNoEntryWithoutOne(): void
public function testTheAudienceIsTheFeedForEveryReader(): void   // same audience for two users
public function testAThrottledHostIsReportedWithoutAFetch(): void  // expect CommentsThrottledException, retryAfterSeconds
public function testAFeedAskingForFewerRequestsIsRecordedAndReported(): void
public function testAnUnreachableFeedIsUnavailable(): void         // CommentsUnavailableException
public function testAnUnparsableAnswerIsUnavailableWhenRead(): void
public function testEachFetchSpendsTheReadersCommentsBudget(): void // 31st fetch in 5 min throws RateLimitedException
// + the moved WordPress / Reddit fixture tests on read()
```
`CommentsFeedSource`:
```php
final readonly class CommentsFeedSource implements CommentsSourceInterface
{
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private EntrySanitizer $sanitizer,
        private HostThrottle $hostThrottle,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $commentsLimiter,
    ) {}
    // serves: null !== $entry->getDiscussion()->commentsFeedUrl
    // audienceOf: the feed URL
    // fetch: hostThrottle wait > 0 → throw CommentsThrottledException; enforceForUser($this->commentsLimiter, $user);
    //   body = fetcher->fetch(url)->modifiedBody() → CommentsAnswerModel::settled($body);
    //   FeedThrottledException → record + CommentsThrottledException(recorded wait); FetchException → CommentsUnavailableException
    // read: parser->parse($answer) (FeedParseException → CommentsUnavailableException) → the existing comments() mapping
}
```
Order inside `fetch()`: the host-throttle check comes before the budget so a throttled host spends nothing.

- [ ] **Step 5: Controller — `?refresh=1`, no limiter**

```php
#[Route('/{id}/comments', name: 'api_entries_comments', methods: ['GET'], requirements: ['id' => '\d+'])]
public function comments(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
{
    $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
    $result = $request->query->getBoolean('refresh')
        ? $this->comments->refreshed($user, $entry)
        : $this->comments->load($user, $entry);

    return new JsonResponse(CommentsJson::one($result));
}
```
Remove `RateLimitGuard` and `$commentsLimiter` from the controller. Update the comment above `comments:` in `rate_limiter.yaml`: "Per-user cap on comments-feed fetches; a cached answer spends nothing."

`EntryCommentsControllerTest` additions (RoutedHttpClient / existing setup — clear `comments.cache` in `setUp` the way `EntrySocialCountsControllerTest` clears its pools):
```php
public function testASecondOpenIsAnsweredFromTheCache(): void      // one outbound request for two GETs
public function testRefreshFetchesAgain(): void                    // GET, GET ?refresh=1 → two outbound requests
public function testCachedOpensSpendNoBudget(): void               // 40 GETs of a cached thread → all 200
```

- [ ] **Step 6: Run everything Comments**

Run: `cd backend && php bin/phpunit tests/Service/Comments tests/Controller/Api/EntryCommentsControllerTest.php tests/Http`
Expected: PASS.

- [ ] **Step 7: Gates on touched files, commit**

Run: `cd backend && composer stan && composer md && composer tramp`
```bash
git add backend
git commit -m "feat(#1616): comments come from a source behind a two-hour answer cache with refresh"
```

---

### Task 3: Counts refresh

**Files:**
- Modify: `backend/src/Service/Social/SocialCounts.php`, `backend/src/Controller/Api/EntrySocialCountsController.php`
- Test: `backend/tests/Service/Social/SocialCountsTest.php`, `backend/tests/Controller/Api/EntrySocialCountsControllerTest.php`

**Interfaces — Produces:** `SocialCounts::refreshed(User $user, Entry $entry): SocialCountsModel` — forgets the cached counts, then `of()`. Endpoint `GET /api/entries/{id}/social?refresh=1`.

- [ ] **Step 1: Failing tests**
```php
// SocialCountsTest
public function testRefreshedAsksTheSourceAgainAndCachesTheNewCounts(): void
// remember(address, (9,8,7)); refreshed → (5,2,1) from the fake public source, askedAddresses = [STATUS_URL];
// then of() → (5,2,1) with no further ask

// EntrySocialCountsControllerTest
public function testRefreshAsksTheOriginAgain(): void   // GET, GET ?refresh=1 → [ORIGIN, ORIGIN]
```
- [ ] **Step 2: Run, verify fail** — `php bin/phpunit tests/Service/Social/SocialCountsTest.php tests/Controller/Api/EntrySocialCountsControllerTest.php` → FAIL, method `refreshed` missing.
- [ ] **Step 3: Implement** — `refreshed()`: `$post = SocialPostAddress::of($entry) ?? throw new NotASocialPostException(…)`; `$this->cache->forget($post)`; `return $this->of($user, $entry);`. Controller: same `getBoolean('refresh')` ternary as Task 2.
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(#1616): the counts endpoint can be asked to refresh"`

---

### Task 4: Mastodon and Bluesky address helpers

Small pure helpers the readers need, plus the shared Mastodon author/card helpers that replace private copies.

**Files:**
- Modify: `backend/src/Service/Bluesky/Support/AtPostUri.php`, `backend/src/Service/Social/Support/MastodonStatusAddress.php`
- Create: `backend/src/Service/Social/Support/MastodonAuthor.php`, `backend/src/Service/Social/Support/MastodonCard.php`
- Modify (use the new helpers, delete the private copies): `Support/MastodonTimelinePage.php` (`nameOf`, `profileOf`, `cardOf`), `Support/MastodonQuotedStatus.php` (`handleOf`), `Support/MastodonAccount.php` (`avatar`)
- Test: `backend/tests/Service/Bluesky/Support/AtPostUriTest.php`, `backend/tests/Service/Social/Support/MastodonStatusAddressTest.php`, create `MastodonAuthorTest.php`, `MastodonCardTest.php`

**Interfaces — Produces:**
```php
AtPostUri::authorityOf(string $uri): ?string        // 'did:plc:abc' or 'ann.test' from at://…/app.bsky.feed.post/rkey; null when no post URI
MastodonStatusAddress::contextApiUrlOrNull(string $address): ?string   // https://host/api/v1/statuses/{id}/context
MastodonStatusAddress::authorPrefixOrNull(string $address): ?string    // https://host/@ann  |  https://host/users/ann
MastodonAuthor::name(JsonNodeModel $account): ?string     // display_name, else acct ('' display_name counts as absent: JsonNodeModel::string returns null for '' — verify)
MastodonAuthor::handle(JsonNodeModel $account): ?string   // acct, completed with the profile host when local (moved from MastodonQuotedStatus::handleOf)
MastodonAuthor::avatar(JsonNodeModel $account): ?string   // avatar_static, else avatar, absolute http only (moved from MastodonAccount::avatar)
MastodonAuthor::profile(JsonNodeModel $account): ?string  // url, absolute http only
MastodonCard::of(JsonNodeModel $card): ?LinkCardContent   // moved from MastodonTimelinePage::cardOf
```

- [ ] **Step 1: Failing tests** — data-provider tests (`#[DataProvider]`, PHPUnit 12):
  - `authorityOf`: `at://did:plc:abc/app.bsky.feed.post/3k` → `did:plc:abc`; `at://ann.test/app.bsky.feed.post/3k` → `ann.test`; `https://bsky.app/…` → null.
  - `contextApiUrlOrNull`: both address shapes → `https://mastodon.social/api/v1/statuses/110/context`; a non-status URL → null.
  - `authorPrefixOrNull`: `https://mastodon.social/@Gargron/110` → `https://mastodon.social/@Gargron`; `https://mastodon.social/users/Gargron/statuses/110` → `https://mastodon.social/users/Gargron`; else null.
  - `MastodonAuthor::handle`: `acct: 'ann'`, `url: 'https://hachyderm.io/@ann'` → `ann@hachyderm.io`; `acct: 'bo@fosstodon.org'` → unchanged; `avatar` prefers `avatar_static`; `name` falls back to `acct`.
  - `MastodonCard::of`: url+title → card (siteName = provider_name, else host); missing title → null.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — `MastodonStatusAddress` already holds both shapes in `STATUS_SHAPES`; refactor to one private `partsOrNull(string $address): ?array{host, id, prefix}` so the three public methods share the match (capture the prefix with an extra group in each shape). Moves are verbatim; callers switch to the helpers; existing tests of `MastodonTimelinePage`, `MastodonQuotedStatus`, `MastodonAccount` must stay green unchanged.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Social tests/Service/Bluesky` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "refactor(#1616): share Mastodon author and card reading; address helpers for threads"`

---

### Task 5: Reply readers — answers into reply trees

Turn a Bluesky `getPostThread` answer and a Mastodon `/context` answer into a tree of `ReplyNodeModel`, applying Q6/Q7. No fetching here.

**Files:**
- Create: `backend/src/Service/Social/Model/ReplyNodeModel.php`, `backend/src/Service/Social/BlueskyThreadReplies.php`, `backend/src/Service/Social/MastodonContextReplies.php`
- Test: `backend/tests/Service/Social/BlueskyThreadRepliesTest.php`, `backend/tests/Service/Social/MastodonContextRepliesTest.php`, fixtures `backend/tests/Fixtures/social/bluesky-post-thread.json`, `backend/tests/Fixtures/social/mastodon-context.json`

**Interfaces — Produces:**
```php
final readonly class ReplyNodeModel {
    /** @param list<ReplyNodeModel> $replies */
    public function __construct(public EntryCommentModel $comment, public array $replies = []) {}
}
final readonly class BlueskyThreadReplies {          // root service: PostEmbedRenderer + EntrySanitizer
    /** @return list<ReplyNodeModel> the post's direct replies, each with its own
     *  @throws CommentsUnavailableException when the answer holds no thread */
    public function of(string $postAddress, string $answer): array;
}
final readonly class MastodonContextReplies {        // root service: EntrySanitizer
    /** @return list<ReplyNodeModel>
     *  @throws CommentsUnavailableException when the answer holds no descendants list */
    public function of(string $postAddress, string $answer): array;
}
```
Order is not decided here (Task 6 sorts); readers keep the answer's order.

**Bluesky reading rules** (`thread` is `app.bsky.feed.defs#threadViewPost`; its `replies` are nodes of that type, `#notFoundPost`, or `#blockedPost`):
- A node whose `$type` is not `app.bsky.feed.defs#threadViewPost` → dropped with its subtree.
- `post.author.viewer.muted === true`, `post.author.viewer.blockedBy === true`, or a non-null `post.author.viewer.blocking` → dropped with its subtree.
- Comment fields: name = `displayName` ?? `handle`; handle = `'@' . handle`; profileUrl = `BlueskyWeb::profileUrl(handle ?? did)`; avatarUrl = `AbsoluteHttpUrl::orNull(author.avatar)`; url = `AtPostUri::webUrl(post.uri)`; publishedAt = `DateParser::parse(record.createdAt ?? post.indexedAt)`; html = sanitized `BlueskyRichText::asHtml(record.text, record.facets) ?? ''`; byEntryAuthor = `AtPostUri::authorityOf($postAddress)` equals the author's `did` or `handle`.
- Embeds: `$rendered = $this->embeds->render($post)` (`PostEmbedRenderer`); null → no embeds. Else `media` = `EntryMediaAssembler::assemble(null, $rendered->media, [])->media`, `linkCard` = `$rendered->linkCards[0] ?? null`, `quote` = `$rendered->quote`. The renderer's `html` is **not** used (it would duplicate the media); an `uncardedLink` is appended to the text as `<p><a href>…</a></p>` (escaped) so a link without a card still shows.

**Mastodon reading rules** (`descendants`: flat list, `in_reply_to_id`):
- Collect every descendant id (`allIds`). A status is *visible* when its `visibility` is `public` or `unlisted`.
- Top-level = visible statuses whose `in_reply_to_id` is not in `allIds` (they answer the post itself — whichever id the post has on the answering instance). Every other visible status hangs under its parent when the parent is visible; a status whose parent is not visible is dropped, and so, transitively, is its subtree.
- Comment fields: name/handle/profile/avatar via `MastodonAuthor` (handle prefixed `@`); url = `url` ?? `uri`; publishedAt = `created_at`; html = sanitized body where a non-empty `spoiler_text` wraps the content: `<details><summary>CW: {escaped}</summary>{content}</details>`; byEntryAuthor = `account.url` or `account.uri` equals `MastodonStatusAddress::authorPrefixOrNull($postAddress)`.
- Embeds: `media` = `EntryMediaAssembler::assemble(null, MastodonAttachments::of(media_attachments), [])->media`; `linkCard` = `MastodonCard::of(card)`; `quote` = `MastodonQuotedStatus::ofQuote(quote)`.

- [ ] **Step 1: Write fixtures**

`bluesky-post-thread.json` — a real-shaped answer (copy the field names from the AppView lexicon; keep it small): root post `at://did:plc:op/app.bsky.feed.post/root` with replies:
1. `r1` by `ann.test` (text with a link facet, `embed` of `app.bsky.embed.images#view` with one image), which has reply `r1a` by the OP `did:plc:op` (byEntryAuthor) which has reply `r1a1` by `bo.test`;
2. `r2` by `muted.test` whose `author.viewer.muted: true`, with a reply `r2a` (dropped with it);
3. a `#blockedPost` node;
4. a `#notFoundPost` node;
5. `r3` by `cy.test` with `embed` `app.bsky.embed.record#view` quoting another post (a `#viewRecord` with `value.$type: app.bsky.feed.post`);
6. `r4` by `di.test` with `app.bsky.embed.external#view` (title + thumb) and `r5` with `app.bsky.embed.video#view` (playlist + thumbnail).

`mastodon-context.json` — `{"ancestors": [], "descendants": [...]}` for post `https://mastodon.social/@Gargron/110`: `200` (public, reply to 110, by `ann@hachyderm.io`, with `spoiler_text` "spoilers", one image attachment, a `card`), `201` (public, reply to 200, by Gargron local `acct: "Gargron"`, `url: "https://mastodon.social/@Gargron"` → byEntryAuthor), `202` (`visibility: "private"`, reply to 110) and `203` (public, reply to 202 → dropped), `204` (unlisted, reply to 110, with an accepted `quote`).

- [ ] **Step 2: Failing tests** (build the services from the container: `self::getContainer()->get(BlueskyThreadReplies::class)` in a `KernelTestCase`, or `new` with real `PostEmbedRenderer` / `EntrySanitizer` from the container)
```php
// BlueskyThreadRepliesTest
public function testOnlyShownRepliesBecomeTheTree(): void
// top-level urls in answer order = [r1, r3, r4, r5]; r1 has [r1a]; r1a has [r1a1]
public function testAMutedAuthorsReplyGoesWithItsAnswers(): void        // no node for r2 or r2a anywhere
public function testAReplyNamesItsAuthor(): void                         // r1 author model fields
public function testTheOriginalPostersReplyIsMarked(): void              // r1a byEntryAuthor true, r1 false
public function testTextKeepsItsLinks(): void                            // r1 html contains the facet's <a href>
public function testImagesBecomeMedia(): void                            // r1 embeds media[0] kind image
public function testAQuotedPostBecomesTheQuote(): void                   // r3 embeds quote url = bsky.app web url
public function testAnExternalEmbedBecomesTheLinkCard(): void            // r4 linkCard title
public function testAVideoBecomesAPlayableMedium(): void                 // r5 media[0] kind video, previewImageUrl
public function testAnAnswerWithoutAThreadIsUnavailable(): void          // '{"error":"x"}' → CommentsUnavailableException

// MastodonContextRepliesTest
public function testRepliesNestByTheStatusTheyAnswer(): void             // top = [200, 204]; 200 → [201]
public function testANonPublicReplyGoesWithItsAnswers(): void            // no 202, no 203
public function testAContentWarningFoldsTheText(): void                  // 200 html starts with '<details><summary>CW: spoilers</summary>'
public function testTheOriginalPostersReplyIsMarked(): void              // 201 true, 200 false
public function testALocalAuthorsHandleNamesTheirInstance(): void        // 201 handle '@Gargron@mastodon.social'
public function testAttachmentsCardAndQuoteBecomeEmbeds(): void          // 200 media + linkCard, 204 quote
public function testAnAnswerWithoutDescendantsIsUnavailable(): void
```
- [ ] **Step 3: Run, verify fail.**
- [ ] **Step 4: Implement** both readers, keeping each method one level of abstraction (`nodesOf`, `isShown`, `commentOf`, `authorOf`, `embedsOf`). If the sanitizer drops `<details>`/`<summary>` (`allowSafeElements()` should keep them — the CW test proves it), add `->allowElement('details')->allowElement('summary')` in `EntrySanitizer` with its own test in `EntrySanitizerTest`.
- [ ] **Step 5: Run** `php bin/phpunit tests/Service/Social` → PASS; `composer md` on the two new services.
- [ ] **Step 6: Commit** — `git commit -m "feat(#1616): read Bluesky threads and Mastodon contexts into reply trees"`

---

### Task 6: Thread order and caps

**Files:**
- Create: `backend/src/Service/Social/ReplyThreadOrder.php`
- Test: `backend/tests/Service/Social/ReplyThreadOrderTest.php`

**Interfaces — Produces:**
```php
final readonly class ReplyThreadOrder
{
    public function __construct(
        #[Autowire('%social_replies_max_depth%')] private int $maxDepth,
        #[Autowire('%social_replies_max_count%')] private int $maxCount,
    ) {}

    /** @param list<ReplyNodeModel> $replies the post's direct replies */
    public function pageOf(array $replies): CommentsPageModel;
}
```
Rules: top-level newest first; inside a thread oldest first (a null `publishedAt` sorts as the oldest); walk depth-first emitting `comment->atDepth($depth)` starting at 0; a node at depth `>= maxDepth` is omitted with its subtree; once `maxCount` comments are emitted, every remaining node (and subtree) is omitted. `omitted` = number of nodes cut.

- [ ] **Step 1: Failing tests** (construct with small caps, e.g. `new ReplyThreadOrder(3, 5)`; a helper `node(string $url, string $at, ReplyNodeModel ...$replies)`):
```php
public function testTopLevelRepliesAreNewestFirst(): void
public function testRepliesInsideAThreadAreOldestFirst(): void
public function testEachCommentCarriesItsDepthInThreadOrder(): void        // urls+depths list
public function testRepliesBelowTheDepthCapAreCountedAsOmitted(): void     // chain of 5 under cap 3 → 3 shown, omitted 2
public function testTheCountCapKeepsWholeEarlierThreadsAndOmitsTheRest(): void
public function testAnUndatedReplySortsAsTheOldest(): void
public function testNoRepliesIsAnEmptyPage(): void
```
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement** — the walk keeps a running list and count; a per-call `Pass/ThreadWalk` (mutable list + omitted counter, built with `new` inside `pageOf`) keeps `pageOf` short and avoids threading the caps through recursive signatures (phptramp).
- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(#1616): order reply threads and cap their depth and size"`

---

### Task 7: Thread fetching per network

**Files:**
- Modify: `backend/src/Service/Bluesky/AppViewClient.php`
- Create: `backend/src/Service/Social/ReplyThreadSource/ReplyThreadSourceInterface.php`, `BlueskyReplyThreadSource.php`, `MastodonReplyThreadSource.php`
- Test: `backend/tests/Service/Bluesky/AppViewClientTest.php`, create `backend/tests/Service/Social/ReplyThreadSource/BlueskyReplyThreadSourceTest.php`, `MastodonReplyThreadSourceTest.php`

**Interfaces — Produces:**
```php
// AppViewClient
/** @throws FetchException @throws AppViewAnswerException when the answer holds no thread object */
public function thread(string $uri, int $depth): string;   // raw body of getPostThread?uri=…&depth=…&parentHeight=0

#[AutoconfigureTag('app.social_reply_thread_source')]
interface ReplyThreadSourceInterface
{
    public static function network(): string;

    /** The thread as the account's own server shows it to the account.
     * @throws SocialPostNotFoundException @throws SocialCredentialsRejectedException @throws SocialTokenScopeMissingException
     * @throws SocialPostRefusedException @throws SocialNetworkThrottledException @throws SocialNetworkUnreachableException */
    public function asAccount(SocialAccount $account, string $address): CommentsAnswerModel;

    /** The thread anyone may read.
     * @throws SocialPostNotFoundException @throws SocialNetworkThrottledException @throws SocialNetworkUnreachableException */
    public function inPublic(string $address): CommentsAnswerModel;

    /** @return list<ReplyNodeModel> @throws CommentsUnavailableException */
    public function repliesIn(string $address, string $answer): array;
}
```
- Bluesky `asAccount`: `BlueskySessions::accepted($this->sessions->askAs($account, '/xrpc/app.bsky.feed.getPostThread?uri=' . rawurlencode($address) . '&depth=' . $this->maxDepth . '&parentHeight=0'))` → `settled(body)`; a 400 whose `error` is `NotFound` → `SocialPostNotFoundException` (check before `accepted()`). `inPublic`: `AppViewClient::thread($address, $this->maxDepth)`; `throttledForSeconds() > 0` first (as `BlueskyAppViewCountsSource`), `FeedThrottledException` → `SocialNetworkThrottledException`, other `FetchException|AppViewAnswerException` → `SocialNetworkUnreachableException`. `repliesIn` → `BlueskyThreadReplies::of`. `maxDepth` from `%social_replies_max_depth%`.
- Mastodon `asAccount`: `$post = $this->resolver->resolve($account, $address)` (`MastodonPostResolver`) → `$this->mastodon->askAboutPost($account, '/api/v1/statuses/' . $post->postId . '/context')` → `null === $answer->header('Mastodon-Async-Refresh') ? settled : stillGathering`. `inPublic`: `MastodonStatusAddress::contextApiUrlOrNull($address) ?? throw new SocialNetworkUnreachableException('The address names no status on a Mastodon instance.')`; anonymous `MastodonApiClient::answer(ApiRequestModel::get($url))`; 404 → `SocialPostNotFoundException`; 401/403 → `SocialNetworkUnreachableException('The Mastodon instance shows its statuses to accounts only.')`; else `MastodonApiClient::accepted()` → `settled`. `repliesIn` → `MastodonContextReplies::of`.

- [ ] **Step 1: Failing tests**
  - `AppViewClientTest::testAThreadIsAskedWithItsDepthAndNoParents` (StubFeedFetcher records URL `https://public.api.bsky.app/xrpc/app.bsky.feed.getPostThread?uri=at%3A%2F%2F…&depth=6&parentHeight=0`, returns `{"thread":{…}}` body unchanged), `testAnAnswerWithoutAThreadIsAnAnswerError`, `testAThrottledThreadRecordsTheHostThrottle`.
  - Bluesky source with `RoutedHttpClient` + `SocialAccounts::bluesky()` + a cached session (see `BlueskyPostResolverTest` for the setup it uses): `testTheAccountAsksItsOwnServiceForTheThread` (request line `GET https://<pds>/xrpc/app.bsky.feed.getPostThread?uri=…&depth=6&parentHeight=0` with `Authorization`), `testAThreadGoneFromTheAccountIsNotFound` (400 `{"error":"NotFound"}`), `testThePublicThreadComesFromTheAppView`.
  - Mastodon source: `testTheAccountResolvesThePostThenAsksItsContext` (lines `[SEARCH, GET https://mastodon.example/api/v1/statuses/9/context]`), `testAnInstanceStillGatheringRepliesSaysSo` (answer header `Mastodon-Async-Refresh: …` → `isStillGathering`), `testThePublicContextComesFromTheOrigin` (`GET https://mastodon.social/api/v1/statuses/110/context`), `testAnOriginClosedToThePublicIsUnreachable` (401), `testAPostGoneFromItsOriginIsNotFound` (404).
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Social tests/Service/Bluesky` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(#1616): fetch reply threads as the account and in public"`

---

### Task 8: SocialRepliesSource, entry JSON, budget

**Files:**
- Create: `backend/src/Service/Social/SocialRepliesSource.php`
- Modify: `backend/src/Http/EntryJson.php`, `backend/config/packages/rate_limiter.yaml`
- Test: create `backend/tests/Service/Social/SocialRepliesSourceTest.php`, `backend/tests/Support/FakeReplyThreadSource.php`; extend `backend/tests/Controller/Api/EntryCommentsControllerTest.php`; extend the EntryJson / entries-list test that asserts `comments` and `discussionUrl` (grep `'discussionUrl'` in `backend/tests`)

**Interfaces — Produces:**
```php
#[AsTaggedItem(priority: 10)]   // ahead of CommentsFeedSource: a social post's replies win over a comments feed
final readonly class SocialRepliesSource implements CommentsSourceInterface
{
    public function __construct(
        private ActingAccountChooser $accounts,
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_reply_thread_source', defaultIndexMethod: 'network')]
        private ContainerInterface $threads,
        private ReplyThreadOrder $order,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialRepliesLimiter,
    ) {}
}
```
Behaviour:
- `serves`: `null !== SocialPostAddress::of($entry)`.
- `audienceOf`: `network + ' ' + address + ' ' + ('account:' . accountId | 'public')`, the account from `ActingAccountChooser::accountFor`, `NoSocialAccountForNetworkException` → `public`.
- `fetch`: spend the `social_replies` budget, then: no account → `inPublic`. Account → `$this->guard->actAs($account, fn () => $thread->asAccount($account, $address))`; on `SocialAccountNeedsReconnectException | SocialCredentialsRejectedException | SocialTokenScopeMissingException | SocialPostRefusedException | SocialPostNotFoundException | SocialNetworkUnreachableException` → `inPublic`. Mapping out: `SocialNetworkThrottledException` → `CommentsThrottledException($message, $e->retryAfterSeconds ?? 60, $e)`; public `SocialPostNotFoundException | SocialNetworkUnreachableException` → `CommentsUnavailableException`.
- `read`: `$this->order->pageOf($thread->repliesIn($address, $answer))`.

Config:
```yaml
        # Per-user cap on social reply fetches (#1616); a cached thread spends nothing.
        social_replies:
            policy: 'sliding_window'
            limit: 120
            interval: '5 minutes'
            cache_pool: cache.rate_limiter
```
EntryJson (`listRow`): social posts get `comments: 'auto'` and `discussionUrl: $entry->getUrl()`:
```php
'discussionUrl' => null === $row->enrichment->social ? $discussion->url : $entry->getUrl(),
'comments' => null === $row->enrichment->social ? $discussion->commentsLoad?->value : CommentsLoad::Auto->value,
```
Fold the two into one private `discussionOf(EntryListRow): array{discussionUrl: …, comments: …}` merged into the row if that reads better; `enrichment->social` is set exactly when `SocialPostAddress::of()` names a post (`EntryListRowEnricher::postNetworksOf`), so this follows the same rule as `entry.social`.

- [ ] **Step 1: Failing tests**
`SocialRepliesSourceTest` (DbTestCase, `FakeReplyThreadSource` with `answersAsAccount`/`answersInPublic`/`failAsAccountWith`/`failInPublicWith`, records calls; `SocialAccounts`, `SocialFeeds` helpers as in `SocialCountsTest`):
```php
public function testServesASocialPostAndNoPlainArticle(): void
public function testWithoutAnAccountTheThreadIsPublic(): void            // inPublic only
public function testWithAnAccountTheThreadIsTheAccounts(): void          // asAccount only
public function testAFailingAccountFallsBackToThePublicThread(): void    // unreachable → both called, public body
public function testAThrottledNetworkSaysWhenToRetry(): void             // CommentsThrottledException retry 30
public function testAThreadNowhereToBeFoundIsUnavailable(): void         // both not found → CommentsUnavailableException
public function testTheAudienceSeparatesAccountsFromThePublic(): void
public function testEachFetchSpendsTheSocialRepliesBudget(): void        // 121st → RateLimitedException
public function testReadingOrdersTheTree(): void                         // fake repliesIn returns two top-level nodes; page newest first
```
`EntryCommentsControllerTest` (RoutedHttpClient, `StaticDnsResolver` for `mastodon.social`, a Mastodon profile-feed post like `EntrySocialCountsControllerTest::mastodonPostFor`):
```php
public function testAMastodonPostsRepliesComeFromItsOriginWithoutAnAccount(): void
// route GET https://mastodon.social/api/v1/statuses/110/context → fixture; 200; comments[0] has depth 0, authorHandle, media
public function testASocialPostsRepliesAreCachedAndRefreshable(): void
```
EntryJson test: a Mastodon post row → `comments === 'auto'`, `discussionUrl === entry url`; a plain article unchanged.
- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Implement.** Keep `fetch()` a sentence: `spend → accountOrNull → $account === null ? publicOf(...) : accountOrPublic(...)`; exception mapping in one private `unavailableOr...` helper per direction. Re-check the module rule: nothing in `App\Service\Comments` may now name `Social`.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service tests/Controller tests/Http` → PASS; then `composer check && composer md`.
- [ ] **Step 5: Commit** — `git commit -m "feat(#1616): a social post's replies are its comments"`

---

### Task 9: Frontend — data, service, refresh of comments and counts

**Files:**
- Modify: `frontend/src/app/reader/models.ts`, `reader/reader-api.ts`, `reader/article/entry-comments/comments.service.ts`, `reader/article/social-counts/social-counts.service.ts`
- Test: `comments.service.spec.ts`, `social-counts.service.spec.ts`, `reader-api` spec if one asserts URLs (grep `api/entries/` in `*.spec.ts`)

**Interfaces — Produces:**
```ts
export interface EntryCommentDto {
  author: string | null;
  authorHandle: string | null;
  authorUrl: string | null;
  authorAvatarUrl: string | null;
  url: string | null;
  publishedAt: string | null;
  html: string;
  byEntryAuthor: boolean;
  depth: number;
  media: EntryMediumDto[];
  linkCard: LinkCardDto | null;      // the type app-link-card's `card` input takes
  quote: QuotedPostDto | null;
}
export type CommentsResponse =
  | { status: 'ok'; comments: EntryCommentDto[]; omitted: number; fetchedAt: string }
  | { status: 'throttled'; retryAfter: number }
  | { status: 'failed' };

// ReaderApi
comments(entryId: number, refresh = false): Observable<CommentsResponse>;     // ?refresh=1 when true
socialCounts(entryId: number, refresh = false): Observable<SocialCountsDto>;

// CommentsState ok branch
{ status: 'ok'; comments: EntryCommentDto[]; omitted: number; fetchedAt: string; loadedAt: number }

// CommentsService.reload(id) now asks with refresh = true; load() unchanged.
// SocialCountsService.reload(entry: CountedEntry): void — refetches with refresh = true unless loading.
```
(The `refresh = false` default parameter is a wire option on the HTTP call, not a behaviour switch inside domain code; keep it. If ESLint's boolean-parameter rule objects, split into `comments()` / `refreshedComments()`.)

- [ ] **Step 1: Failing specs**
```ts
// comments.service.spec.ts
it('asks the server to refresh on reload', () => { /* reload → GET …/comments?refresh=1 */ });
it('keeps the fetch time and the omitted count of an ok answer', () => { /* state.fetchedAt, state.omitted */ });
// social-counts.service.spec.ts
it('reloads the counts with a refresh', () => { /* load, flush, reload → GET …/social?refresh=1 */ });
it('ignores a reload while counts are loading', () => {});
```
Use the existing spec's `HttpTestingController` pattern.
- [ ] **Step 2: Run** `docker compose exec -T frontend npm test -- src/app/reader/article` → FAIL.
- [ ] **Step 3: Implement.** Update every `EntryCommentDto` / `CommentsResponse` literal in specs and test builders (grep `byEntryAuthor` under `frontend/src` and `frontend/e2e`) so `tsc` stays green.
- [ ] **Step 4: Run** the same command → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(#1616): comments and counts can be refreshed; comments carry the new fields"`

---

### Task 10: Frontend — the comments panel shows threads, avatars, embeds, age

**Files:**
- Modify: `frontend/src/app/reader/article/entry-comments/entry-comments.component.{ts,html,scss}`, `frontend/public/i18n/en.json`, `de.json`
- Test: `entry-comments.component.spec.ts`

Behaviour:
- Each `<li class="comment">` gets `[style.--depth]="min(c.depth, 5)"`; SCSS indents with `margin-inline-start: calc(var(--depth) * var(--space-…))` using an existing spacing token from `docs/design-language.md` (read it for the token and for the avatar size the quoted post uses) and a thin `border-inline-start` in a theme token for depth > 0. On narrow screens cap the visual indent lower via a token-based media query.
- Byline: avatar (`appProxiedImage`, `referrerpolicy="no-referrer"`, fallback initials + `avatarToneOf` as `QuotedPostComponent` does) when `authorAvatarUrl`; name; `authorHandle` in a muted span; OP pill; time.
- Body: `[innerHTML]="c.html"`, then `@if (c.media.length)` `<app-post-media [media]="c.media" [ratio]="ratio(c)" [videoLabel]="'reader.comments.video' | transloco" />`, `@if (c.quote)` `<app-quoted-post [quote]="c.quote" layout="article" />`, `@if (c.linkCard)` `<app-link-card [card]="c.linkCard" layout="article" />`. `ratio(c)` = `mediumRatio(c.media[0])`.
- Header: when ok, `<span class="age">{{ 'reader.comments.updated' | transloco: { when: when(s.fetchedAt) } }}</span>` before the refresh button. The refresh button calls `refresh()`: `service.reload(id)` and, when `entry().social`, `socialCounts.reload(entry())`. The component input type widens to `Pick<EntryDto, 'id' | 'comments' | 'discussionUrl' | 'social'>`.
- Link after the list: `omitted > 0` → `'reader.comments.more' | transloco: { count, network }` where `network` = `Bluesky` / `Mastodon` from `entry().social?.network`; else the existing `all` text.
- i18n (en / de):
  - `reader.comments.updated`: "Updated {{ when }}" / "Aktualisiert {{ when }}"
  - `reader.comments.more`: "{{ count }} more on {{ network }}" / "{{ count }} weitere auf {{ network }}"
  - `reader.comments.video`: "Video in a comment" / "Video in einem Kommentar"
  - `reader.comments.reload` stays.

- [ ] **Step 1: Failing component specs**
```ts
it('indents a reply by its depth', ...)                 // li style --depth: 2
it('shows the author avatar and handle', ...)
it('renders a reply\'s media, quote and link card', ...)   // app-post-media / app-quoted-post / app-link-card present
it('says how old the comments are', ...)               // .age text contains "Updated"
it('refreshes comments and counts together', ...)      // reload spies on both services for a social entry
it('refreshes only comments for an ordinary article', ...)
it('names the network and the count when replies were cut', ...)  // "3 more on Bluesky"
```
- [ ] **Step 2: Run** `docker compose exec -T frontend npm test -- src/app/reader/article/entry-comments` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run → PASS**, then `docker compose exec -T frontend npm run check`.
- [ ] **Step 5: Visual check** on the dev stack (`https://localhost:8443` or :4200 per `docs/local-docker.md`): open a Bluesky post with replies and a Mastodon post with replies; screenshot both at desktop and mobile width (built-in browser Mobile preset). Compare with the quoted-post and link-card look in the article body; fix spacing via tokens only.
- [ ] **Step 6: Commit** — `git commit -m "feat(#1616): the comments panel shows threads, avatars, embeds and its age"`

---

### Task 11: Frontend — reply videos autoplay one at a time; settings text

**Files:**
- Modify: `entry-comments.component.ts` (providers + article-wide exclusivity), `frontend/src/app/reader/media/autoplay/list-autoplay-coordinator.ts` (doc line), `frontend/public/i18n/en.json`, `de.json`
- Test: `entry-comments.component.spec.ts`

Behaviour:
- `EntryCommentsComponent` adds `providers: [ListAutoplayCoordinator, UnlockedVideoElements]`, so each `app-post-media` inside the panel registers its videos with the panel's coordinator (it injects `ListAutoplayCoordinator` optionally — already does). The preference gate is inside the coordinator (`ListAutoplayService.enabled`), so nothing else is needed for "autoplay if the option is on, one at a time".
- One video at a time across the article: in the component constructor, after render, take `host.closest('article')` and add a capture-phase `play` listener (`AbortController` released on destroy):
  - a medium playing **inside** the panel → pause every `video`/`audio` in the article that is outside the panel and playing;
  - a medium playing **outside** the panel (the post's own video) → `coordinator.suspend()`, and on that medium's next `pause` or `ended` (`{ once: true }`) → `coordinator.resume()`.
  Run the listener outside the Angular zone (memory: an in-zone listener/timer can keep `whenStable()` busy — not needed for an event listener, but keep it outside to avoid change detection per `play`).
- Coordinator doc line becomes: "Plays the most visible video of the list or comments that provides it, one at a time."
- i18n: `settings.listAutoplay` → "Autoplay videos in lists and comments" / "Videos in Listen und Kommentaren automatisch abspielen"; hint unchanged.

- [ ] **Step 1: Failing specs**
```ts
it('provides its own autoplay coordinator to the reply media', ...)     // injector of a child resolves a coordinator !== the root's
it('pauses the post\'s own video when a reply video plays', ...)        // dispatch play (capture) on a panel <video>; outside video.pause called
it('holds reply autoplay while the post\'s own video plays', ...)       // spy coordinator.suspend on outside play, resume on its pause
```
- [ ] **Step 2: Run → FAIL. Step 3: Implement. Step 4: Run → PASS**, `npm run check`.
- [ ] **Step 5: Manual check** with autoplay on and off on the dev stack (a Bluesky post whose replies hold two videos): only the most visible reply video plays muted; tapping the post's own video stops it; scrolling between replies switches. iOS check is Lars's (memory: iOS sound unlock is per element).
- [ ] **Step 6: Commit** — `git commit -m "feat(#1616): reply videos autoplay one at a time with the post's own video"`

---

### Task 12: Playwright smoke, final gates, PR

**Files:**
- Create: `frontend/e2e/social-replies.spec.ts`

The spec owns its data (CLAUDE.md): stub `/api/entries/*/comments` and `/api/entries/*/social` with routes and use the typed builders (`aSubscription`, `subscriptionsJson` / `oneFeedJson` in `e2e/support/reader.ts`; a social entry like the counts spec from #1634 — grep `social-counts` in `frontend/e2e` and reuse its entry stub). Assert:
1. a reply at depth 0 and its answer at depth 1 render, the deeper one indented (bounding box x larger);
2. the reply's image renders through `app-post-media`;
3. "Updated" age is visible; clicking refresh issues `…/comments?refresh=1` and `…/social?refresh=1` (`page.waitForRequest`);
4. `omitted: 3` → link text "3 more on Bluesky".

- [ ] **Step 1: Write the spec; run** `cd frontend && npx playwright test e2e/social-replies.spec.ts` (Docker stack up from this checkout) → PASS.
- [ ] **Step 2: Full gates**
```bash
cd backend && composer check && composer md && php bin/phpunit
docker compose exec php composer test
cd backend && composer infection:diff
docker compose exec -T frontend npm run check
```
All green; raise nothing, lower nothing. Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq .`) for deprecations from the new code.
- [ ] **Step 3: Real-network check** on the dev stack with a connected Bluesky and Mastodon account and without: replies appear, CW folds, muted author absent (Bluesky with account), "Updated" ages, refresh refetches.
- [ ] **Step 4: Commit, push, PR** into `develop`, body `Closes #1616`, listing the decisions table's deviations from the issue text (Mastodon acting-first; structured embeds; 2 h cache for both sources) and the known limits: Mastodon logged-out context caps at 60 replies; Mastodon mutes apply only on the account path.

---

## Self-review notes

- Spec coverage: Q1/Q1b/Q2 → Task 8 (+7); Q1c → Tasks 2 and 7; Q3/Q4/Q5/Q13 → Task 6; Q6/Q14/Q7 → Task 5 (+1, 10); Q8/Q17 → Task 2; Q9/Q10 → Task 8; Q11/Q16/Q22 → Tasks 2, 3, 8, 9, 10; Q12 → nothing to build; Q15/Q23 → Task 11; Q18 → Task 8; Q19 → Task 10; Q24 → Task 11.
- Known limits recorded for the PR: logged-out Mastodon context returns at most 60 descendants; the reply count in the counts row can differ from the shown list (cap, filters, cache age).
