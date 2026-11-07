# Social search subscriptions (#2086) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A user subscribes to a Bluesky or Mastodon search query through a connected account; the reader refreshes it like any other private social feed.

**Architecture:** A new `SocialFeedKind::Search` whose remote id is the normalised query. Refreshes go through the existing per-network `TimelineSourceInterface` (new `pathOf`/`pageOf` arms); the answer is mapped by two small search-page mappers that drop replies and hand the rest to the existing `BlueskyTimelinePage::of` / `MastodonTimelinePage::of`. Subscribing reuses `POST /api/me/social-accounts/{id}/import` with a `search` collection. A new `GET /api/me/social-accounts/{id}/search-preview?query=…` runs the search once (through a consumer-owned `SocialSearchInterface`) so the picker's new "Search" tab can show a count and the newest three posts before subscribing.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (PHPUnit 12, PHPStan max, PHPMD, phptramp, Infection), Angular 20 standalone + signals, Transloco, Jest.

**Spec:** GitHub issue #2086 body ("Design (settled)") — read it first: `gh issue view 2086`.

## Global Constraints

- Follow `CLAUDE.md` in full (Clean Code, role folders, no comments unless a reader would get the code wrong, `Response::HTTP_*` never a magic number, `final readonly` by default).
- Kind value is exactly `search`. Remote id = query normalised by `trim` + collapsing every run of whitespace to one space; case and characters kept; at most 512 characters.
- Bluesky search: `/xrpc/app.bsky.feed.searchPosts?q=<urlencoded>&sort=latest&limit=100`, through the account session.
- Mastodon search: `/api/v2/search?type=statuses&resolve=false&limit=40&q=<urlencoded>` plus `&min_id=<newestSeenId>` on refreshes when one is known. The `read` scope already covers it.
- Replies are dropped on both networks (Bluesky: `post.record.reply` present; Mastodon: `in_reply_to_id` not null).
- Default title is the query. Site URL: `https://bsky.app/search?q=<urlencoded>` / `<instance>/search?q=<urlencoded>`.
- Inclusion defaults as custom feeds (All Items off, For You off, saved searches on) — `PrivateFeeds::subscribed()` already does that for every kind that does not `followsOnePerson()`. Do not add `Search` to `rememberedCollections()`.
- Preview shows the result count and the newest 3 posts; zero results is a warning, never a refusal.
- Out of scope: editing a query, paging back, recognising pasted search URLs.
- Commit messages: `type(#2086): …`, no Claude attribution.
- Frontend tests run in the Docker frontend container (`docker compose exec -T frontend npm test -- <pattern>`); `npm run check` is the gate.

---

## File Structure

Backend (all under `backend/`):

| File | Role |
|---|---|
| `src/Enum/SocialFeedKind.php` | add `case Search = 'search'` |
| `src/Service/Social/Support/SocialSearchQuery.php` (new) | static normaliser |
| `src/Service/Social/Model/PrivateFeedModel.php` | `search()` factory + `of()` arm |
| `src/Service/Social/Support/PrivateFeedRules.php` | `Search` arm |
| `src/Service/Social/Support/PrivateFeedSite.php` | search URLs |
| `src/Service/Bluesky/Support/BlueskyWeb.php` | `searchUrl()` |
| `src/Service/Social/SocialImporter.php` | `kindLabel` arm |
| `src/Dto/Social/SocialImportCollectionItem.php` | allow `search` |
| `src/Service/Social/Support/SocialSearchPaths.php` (new) | the two request paths |
| `src/Service/Social/Support/BlueskySearchPage.php` (new) | `searchPosts` answer → `TimelinePageModel` |
| `src/Service/Social/Support/MastodonSearchPage.php` (new) | `/api/v2/search` answer → `TimelinePageModel` |
| `src/Service/Social/TimelineSource/BlueskyTimelineSource.php` | `Search` arms |
| `src/Service/Social/TimelineSource/MastodonTimelineSource.php` | `Search` arms |
| `src/Service/Social/SocialSearch/SocialSearchInterface.php` (new) | one-off search, consumer-owned |
| `src/Service/Social/SocialSearch/NetworkSocialSearch.php` (new) | its implementation |
| `src/Dto/Social/SocialSearchPreviewRequest.php` (new) | `?query=` |
| `src/Http/SocialSearchPreviewJson.php` (new) | `{count, posts[3]}` |
| `src/Controller/Api/SocialImportController.php` | `search-preview` action |

Frontend (all under `frontend/src/`):

| File | Role |
|---|---|
| `app/reader/social-feed.ts` | `'search'` in the kind union |
| `app/reader/state/social-sections.ts` | `search: 5` in `KIND_ORDER` |
| `app/reader/feeds/social-picker/social-import-rows.ts` | `search` icon |
| `app/reader/feeds/social-picker/social-import.service.ts` | `preview()` |
| `app/reader/feeds/social-picker/social-search-tab.component.{ts,html,scss,spec.ts}` (new) | the Search tab |
| `app/reader/feeds/social-picker/social-import-dialog.component.{ts,html}` | the tab wiring |
| `public/i18n/en.json`, `public/i18n/de.json` | copy |

---

### Task 1: The `search` kind and its identity

**Files:**
- Modify: `backend/src/Enum/SocialFeedKind.php`
- Create: `backend/src/Service/Social/Support/SocialSearchQuery.php`
- Modify: `backend/src/Service/Social/Model/PrivateFeedModel.php`
- Modify: `backend/src/Service/Social/Support/PrivateFeedRules.php`
- Modify: `backend/src/Service/Social/Support/PrivateFeedSite.php`
- Modify: `backend/src/Service/Bluesky/Support/BlueskyWeb.php`
- Modify: `backend/src/Service/Social/SocialImporter.php` (`kindLabel`)
- Modify: `backend/src/Dto/Social/SocialImportCollectionItem.php`
- Modify (temporary arms only, real ones in Task 2): `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`, `MastodonTimelineSource.php`
- Test: `backend/tests/Service/Social/Support/SocialSearchQueryTest.php` (new), `tests/Service/Social/Model/PrivateFeedModelTest.php`, `tests/Service/Social/Support/PrivateFeedRulesTest.php`, `tests/Service/Social/Support/PrivateFeedSiteTest.php`, `tests/Controller/Api/SocialImportControllerTest.php`

**Interfaces:**
- Produces: `SocialFeedKind::Search`; `SocialSearchQuery::normalized(string $query): string`; `PrivateFeedModel::search(string $query): self` (remoteId = name = normalised query); `BlueskyWeb::searchUrl(string $query): string`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Social/Support/SocialSearchQueryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\SocialSearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialSearchQueryTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function queries(): iterable
    {
        yield 'trimmed' => ["  rss reader \n", 'rss reader'];
        yield 'inner whitespace collapsed' => ["rss \t  reader", 'rss reader'];
        yield 'case and operators kept' => ['("RSS Reader" || feedreader) -Gazette', '("RSS Reader" || feedreader) -Gazette'];
        yield 'non-breaking space is whitespace' => ["rss\u{00A0}reader", 'rss reader'];
    }

    #[DataProvider('queries')]
    public function testAQueryIsTrimmedAndItsWhitespaceCollapsed(string $typed, string $normalized): void
    {
        self::assertSame($normalized, SocialSearchQuery::normalized($typed));
    }
}
```

Add to `PrivateFeedModelTest` (follow its existing style):

```php
public function testASearchIsNamedAndKeyedByItsNormalisedQuery(): void
{
    $search = PrivateFeedModel::search('  rss   reader ');

    self::assertSame(SocialFeedKind::Search, $search->kind);
    self::assertSame('rss reader', $search->remoteId);
    self::assertSame('rss reader', $search->name);
    self::assertEquals($search, PrivateFeedModel::of(SocialFeedKind::Search, ' rss reader', 'ignored'));
}
```

Add to `PrivateFeedRulesTest` (one data-provider row per case if the file uses a provider):

```php
public function testASearchIsAllowedOnBothNetworksWhenItHasAQuery(): void
{
    self::assertTrue(PrivateFeedRules::allows(SocialNetwork::Bluesky, PrivateFeedModel::search('rss reader')));
    self::assertTrue(PrivateFeedRules::allows(SocialNetwork::Mastodon, PrivateFeedModel::search('rss reader')));
    self::assertFalse(PrivateFeedRules::allows(SocialNetwork::Bluesky, PrivateFeedModel::search("  \t ")));
    self::assertFalse(PrivateFeedRules::allows(SocialNetwork::Bluesky, PrivateFeedModel::search(str_repeat('a', 513))));
    self::assertTrue(PrivateFeedRules::allows(SocialNetwork::Bluesky, PrivateFeedModel::search(str_repeat('ä', 512))));
}
```

Add to `PrivateFeedSiteTest`:

```php
public function testASearchOpensTheSameSearchOnTheNetwork(): void
{
    $search = PrivateFeedModel::search('"rss reader" || feedreader');

    self::assertSame(
        'https://bsky.app/search?q=%22rss%20reader%22%20%7C%7C%20feedreader',
        PrivateFeedSite::of(SocialAccounts::bluesky(), $search),
    );
    self::assertSame(
        'https://mastodon.example.test/search?q=%22rss%20reader%22%20%7C%7C%20feedreader',
        PrivateFeedSite::of(SocialAccounts::mastodonOn('https://mastodon.example.test/'), $search),
    );
}
```

Add to `SocialImportControllerTest` (reuse its helpers for a stored account and the import POST; read the existing `import` tests first and copy their setup):

Two tests. Arrange both exactly as the file's existing collection-import test does (logged-in user, stored Bluesky account via `blueskyAccountOf()`, a `KernelBrowser`), then POST to `/api/me/social-accounts/{accountId}/import`:

1. `testASearchIsSubscribedAsAPrivateFeedNamedByItsQuery` — body
   `{"subscribe": [], "unsubscribe": [], "collections": [{"kind": "search", "id": "  rss   reader ", "name": "rss reader"}]}`.
   Assert: `200` with `imported` = 1; exactly one `Subscription` for the user whose `Feed` has
   - url `'social:bluesky:' . $account->requireId() . ':search:rss reader'`,
   - title `'rss reader'`,
   - site url `'https://bsky.app/search?q=rss%20reader'`;
   the subscription's `isIncludeInAllItems()` and `isIncludeInForYou()` are false (use the getters the entity has); and one `SocialFeed` with kind `SocialFeedKind::Search` and remote id `'rss reader'`.
2. `testABlankSearchIsRefused` — same body with `"id": "   "`. Assert `Response::HTTP_UNPROCESSABLE_ENTITY` (what `SocialProblems` maps `InvalidSocialImportFeedException` to) and no new subscription.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Social/Support/SocialSearchQueryTest.php tests/Service/Social/Model/PrivateFeedModelTest.php tests/Service/Social/Support/PrivateFeedRulesTest.php tests/Service/Social/Support/PrivateFeedSiteTest.php tests/Controller/Api/SocialImportControllerTest.php`
Expected: FAIL — `SocialSearchQuery` / `PrivateFeedModel::search` / `SocialFeedKind::Search` undefined.

- [ ] **Step 3: Implement**

`src/Enum/SocialFeedKind.php` — add after `Trending`:

```php
    case Search = 'search';
```

`src/Service/Social/Support/SocialSearchQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

final class SocialSearchQuery
{
    public static function normalized(string $query): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $query) ?? $query);
    }

    private function __construct()
    {
    }
}
```

`PrivateFeedModel`:

```php
    public static function search(string $query): self
    {
        $normalized = SocialSearchQuery::normalized($query);

        return new self(SocialFeedKind::Search, $normalized, $normalized);
    }
```

and in `of()`: `SocialFeedKind::Search => self::search($remoteId),`. Update the `$remoteId` doc to stay true (`'' for Home and Trending` is still correct).

`PrivateFeedRules::allows()` — new arm:

```php
            SocialFeedKind::Search => '' !== $privateFeed->remoteId
                && mb_strlen($privateFeed->remoteId) <= self::SEARCH_LENGTH,
```

with `private const int SEARCH_LENGTH = 512;`.

`BlueskyWeb`:

```php
    public static function searchUrl(string $query): string
    {
        return self::URL . '/search?q=' . rawurlencode($query);
    }
```

`PrivateFeedSite::bluesky()` — guard first:

```php
        if (SocialFeedKind::Search === $privateFeed->kind) {
            return BlueskyWeb::searchUrl($privateFeed->remoteId);
        }
```

`PrivateFeedSite::mastodon()` — new arm `SocialFeedKind::Search => $instance . '/search?q=' . rawurlencode($privateFeed->remoteId),`.

`SocialImporter::kindLabel()` — `SocialFeedKind::Search => 'search',`.

`SocialImportCollectionItem` — add `SocialFeedKind::Search->value` to the `Assert\Choice` list.

`BlueskyTimelineSource::pathOf()` and `MastodonTimelineSource::pathOf()` — add `SocialFeedKind::Search` to the arm that throws, so the build stays green until Task 2 replaces it.

- [ ] **Step 4: Run the tests to verify they pass**

Same command as Step 2. Expected: PASS. Then `composer stan` (exhaustive matches) and `composer cs`.

- [ ] **Step 5: Commit**

```bash
git add -A backend
git commit -m "feat(#2086): a search is a private feed kind named and keyed by its query"
```

---

### Task 2: Refreshing a search

**Files:**
- Create: `backend/src/Service/Social/Support/SocialSearchPaths.php`
- Create: `backend/src/Service/Social/Support/BlueskySearchPage.php`
- Create: `backend/src/Service/Social/Support/MastodonSearchPage.php`
- Modify: `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`
- Modify: `backend/src/Service/Social/TimelineSource/MastodonTimelineSource.php`
- Test: `tests/Service/Social/Support/BlueskySearchPageTest.php` (new), `tests/Service/Social/Support/MastodonSearchPageTest.php` (new), `tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php`, `tests/Service/Social/TimelineSource/MastodonTimelineSourceTest.php`

**Interfaces:**
- Consumes: `SocialFeedKind::Search` (Task 1).
- Produces:
  - `SocialSearchPaths::bluesky(string $query): string` → `/xrpc/app.bsky.feed.searchPosts?q=…&sort=latest&limit=100`
  - `SocialSearchPaths::mastodon(string $query, ?string $newestSeenId): string` → `/api/v2/search?type=statuses&resolve=false&limit=40&q=…[&min_id=…]`
  - `BlueskySearchPage::of(ApiResponseModel $accepted, string $accountDid): TimelinePageModel` (throws `SocialNetworkUnreachableException` when `posts` is missing)
  - `MastodonSearchPage::of(ApiResponseModel $accepted): TimelinePageModel` (throws the same when `statuses` is missing)

- [ ] **Step 1: Write the failing tests**

`BlueskySearchPageTest` — build bodies with the post-view shape (`uri`, `cid`, `author{did,handle,displayName}`, `record{text,createdAt[,reply]}`, `indexedAt`); copy a post fixture from `BlueskyTimelinePageTest` and drop its `feed` wrapper:

```php
public function testEveryPostButAReplyBecomesAnEntry(): void
{
    $page = BlueskySearchPage::of(self::answer(['posts' => [
        self::post('at://did:plc:amy/app.bsky.feed.post/1', 'Which RSS reader do you use?'),
        self::post('at://did:plc:bob/app.bsky.feed.post/2', 'NetNewsWire!', reply: true),
    ]]), 'did:plc:me');

    self::assertSame(
        ['at://did:plc:amy/app.bsky.feed.post/1'],
        array_map(static fn (ParsedEntryModel $entry): string => $entry->guid, $page->parsed->entries),
    );
    self::assertSame([], $page->selfReplies);
}

public function testAnAnswerWithoutPostsIsAnUnreachableBluesky(): void
{
    $this->expectException(SocialNetworkUnreachableException::class);

    BlueskySearchPage::of(self::answer(['cursor' => 'x']), 'did:plc:me');
}
```

`MastodonSearchPageTest` — status fixture from `MastodonTimelinePageTest`:

```php
public function testEveryStatusButAReplyBecomesAnEntry(): void
{
    $page = MastodonSearchPage::of(self::answer([
        'accounts' => [],
        'hashtags' => [],
        'statuses' => [self::status('111', null), self::status('112', '100')],
    ]));

    self::assertSame(['https://mastodon.example.test/@amy/111'], array_map(
        static fn (ParsedEntryModel $entry): ?string => $entry->url,
        $page->parsed->entries,
    ));
}

public function testAnAnswerWithoutStatusesIsAnUnreachableInstance(): void
{
    $this->expectException(SocialNetworkUnreachableException::class);

    MastodonSearchPage::of(self::answer(['accounts' => []]));
}
```

(`self::status($id, $inReplyToId)` returns the fixture with `id`, `uri`, `url`, `in_reply_to_id`; adjust the asserted guid/url to what `MastodonTimelinePage::entryOf` produces — read it.)

`BlueskyTimelineSourceTest` — new cases:

```php
public function testASearchAsksForTheLatestHundredMatches(): void
{
    $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1')]);

    $request = $this->source($http->client())
        ->request($this->socialFeed(SocialFeedKind::Search, '"rss reader" || feedreader'));

    self::assertSame(
        'GET https://pds.example.test/xrpc/app.bsky.feed.searchPosts?q=%22rss%20reader%22%20%7C%7C%20feedreader'
            . '&sort=latest&limit=100',
        $request->method . ' ' . $request->url,
    );
}

public function testASearchAnswerMapsToItsPostsWithoutReplies(): void
{
    $page = $this->source((new RoutedHttpClient([]))->client())->pageOf(
        $this->socialFeed(SocialFeedKind::Search, 'rss'),
        self::answer(Response::HTTP_OK, ['posts' => [
            self::searchPost('at://did:plc:amy/app.bsky.feed.post/1', null),
            self::searchPost('at://did:plc:bob/app.bsky.feed.post/2', 'at://did:plc:amy/app.bsky.feed.post/1'),
        ]]),
    );

    self::assertCount(1, $page->parsed->entries);
}
```

Add the private fixture helper this test uses (a post view; a reply carries `record.reply` with `parent`/`root` refs):

```php
/** @return array<string, mixed> */
private static function searchPost(string $uri, ?string $repliesTo): array
{
    $record = ['$type' => 'app.bsky.feed.post', 'text' => 'Which RSS reader?', 'createdAt' => '2026-11-07T10:00:00.000Z'];
    if (null !== $repliesTo) {
        $record['reply'] = ['parent' => ['uri' => $repliesTo, 'cid' => 'c1'], 'root' => ['uri' => $repliesTo, 'cid' => 'c1']];
    }

    return [
        'uri' => $uri,
        'cid' => 'c2',
        'author' => ['did' => 'did:plc:amy', 'handle' => 'amy.bsky.social'],
        'record' => $record,
        'indexedAt' => '2026-11-07T10:00:01.000Z',
    ];
}
```

`BlueskySearchPageTest`'s `self::post($uri, $text, reply: bool)` is the same shape.

`MastodonTimelineSourceTest` — new cases: the first request is `GET https://<instance>/api/v2/search?type=statuses&resolve=false&limit=40&q=rss%20reader`; with `newestSeenId` `'110'` recorded it ends `&min_id=110`; a `{statuses:[…]}` answer maps to entries without replies.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Social/Support/BlueskySearchPageTest.php tests/Service/Social/Support/MastodonSearchPageTest.php tests/Service/Social/TimelineSource`
Expected: FAIL (classes missing; Search still throws).

- [ ] **Step 3: Implement**

`SocialSearchPaths`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

final class SocialSearchPaths
{
    private const int BLUESKY_PAGE_SIZE = 100;
    private const int MASTODON_PAGE_SIZE = 40;

    public static function bluesky(string $query): string
    {
        return '/xrpc/app.bsky.feed.searchPosts?q=' . rawurlencode($query)
            . '&sort=latest&limit=' . self::BLUESKY_PAGE_SIZE;
    }

    public static function mastodon(string $query, ?string $newestSeenId): string
    {
        return '/api/v2/search?type=statuses&resolve=false&limit=' . self::MASTODON_PAGE_SIZE
            . '&q=' . rawurlencode($query)
            . (null === $newestSeenId ? '' : '&min_id=' . rawurlencode($newestSeenId));
    }

    private function __construct()
    {
    }
}
```

`BlueskySearchPage` — wraps each non-reply post view as a feed item so `BlueskyTimelinePage::of` reads it unchanged (no `reply` key ⇒ shown, never a self-reply):

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;

final class BlueskySearchPage
{
    /** @throws SocialNetworkUnreachableException */
    public static function of(ApiResponseModel $accepted, string $accountDid): TimelinePageModel
    {
        $answer = json_decode($accepted->body, true);
        $posts = \is_array($answer) && \is_array($answer['posts'] ?? null) && array_is_list($answer['posts'])
            ? $answer['posts']
            : throw new SocialNetworkUnreachableException('Bluesky answered without search results.');

        return BlueskyTimelinePage::of(self::feedItemsOf($posts), $accountDid);
    }

    /**
     * @param list<mixed> $posts
     *
     * @return list<JsonNodeModel>
     */
    private static function feedItemsOf(array $posts): array
    {
        $items = [];
        foreach ($posts as $post) {
            if (!JsonNodeModel::of($post)->node('record')->has('reply')) {
                $items[] = JsonNodeModel::of(['post' => $post]);
            }
        }

        return $items;
    }

    private function __construct()
    {
    }
}
```

`MastodonSearchPage` — same idea over `statuses`, filtering on `JsonNodeModel::of($status)->string('in_reply_to_id')`, then `MastodonTimelinePage::of($kept)` where `$kept` is `list<JsonNodeModel>` (check `MastodonTimelinePage::of`'s parameter type and match it).

`BlueskyTimelineSource`:
- `pathOf()`: move `. 'limit=' . self::PAGE_SIZE` into the four timeline arms (or a private `timeline(string $path)` helper) and add `SocialFeedKind::Search => SocialSearchPaths::bluesky($socialFeed->getRemoteId()),`; `Tag`/`Trending` keep throwing.
- `pageOf()`: after the stale-session check, `if (SocialFeedKind::Search === $socialFeed->getKind()) { return BlueskySearchPage::of(BlueskySessions::accepted($answer), $socialFeed->getSocialAccount()->getIdentity()); }` — keep `itemsOf()` for the rest. Keep `pageOf` short; extract `pageOfTimeline()` if PHPMD asks.

`MastodonTimelineSource`:
- `pathOf()`: `SocialFeedKind::Search => SocialSearchPaths::mastodon($socialFeed->getRemoteId(), $socialFeed->getNewestSeenId()),`.
- `pageOf()`: `Search` → `MastodonSearchPage::of($accepted)`, otherwise `pageFrom($accepted)`.

- [ ] **Step 4: Run to verify they pass**

Same command as Step 2, then the whole `tests/Service/Social` directory. Expected: PASS. Then `composer stan`, `composer md` on the touched files, `composer tramp`.

- [ ] **Step 5: Commit**

```bash
git add -A backend
git commit -m "feat(#2086): a search feed refreshes from the network's search, replies left out"
```

---

### Task 3: Preview endpoint

**Files:**
- Create: `backend/src/Service/Social/SocialSearch/SocialSearchInterface.php`
- Create: `backend/src/Service/Social/SocialSearch/NetworkSocialSearch.php`
- Create: `backend/src/Dto/Social/SocialSearchPreviewRequest.php`
- Create: `backend/src/Http/SocialSearchPreviewJson.php`
- Modify: `backend/src/Controller/Api/SocialImportController.php`
- Create: `backend/tests/Support/StubSocialSearch.php`
- Test: `tests/Service/Social/SocialSearch/NetworkSocialSearchTest.php` (new), `tests/Controller/Api/SocialImportControllerTest.php`

**Interfaces:**
- Consumes: `SocialSearchPaths`, `BlueskySearchPage`, `MastodonSearchPage` (Task 2); `SocialSearchQuery::normalized` (Task 1).
- Produces:
  - `SocialSearchInterface::newest(SocialAccount $account, string $query): TimelinePageModel` (`@throws SocialCredentialsRejectedException`, `SocialNetworkThrottledException`, `SocialNetworkUnreachableException`)
  - `GET /api/me/social-accounts/{id}/search-preview?query=…` → `200 {"count": int, "posts": [{"url": string|null, "author": string|null, "authorAvatarUrl": string|null, "text": string, "publishedAt": string|null}]}` — at most 3 posts, newest first, `publishedAt` ISO-8601 UTC. Route name `api_me_social_accounts_search_preview`. Rate-limited by the existing `socialImportLimiter`. `422` when `query` is blank or over 512 characters after normalising.

- [ ] **Step 1: Write the failing tests**

`NetworkSocialSearchTest` (use `RoutedHttpClient` + the source-test wiring helpers from `BlueskyTimelineSourceTest::source()` / `MastodonTimelineSourceTest`):
- Bluesky: given a cached session, `newest($account, 'rss reader')` sends `GET https://pds.example.test/xrpc/app.bsky.feed.searchPosts?q=rss%20reader&sort=latest&limit=100` with the bearer token and returns the page of the answer's posts.
- Bluesky stale session (`400 ExpiredToken`): renews once and retries (that is `BlueskySessions::askAs`' behaviour — assert two search requests and one `createSession`).
- Mastodon: sends `GET https://<instance>/api/v2/search?type=statuses&resolve=false&limit=40&q=rss%20reader` (no `min_id`) and returns the page.
- Mastodon `401` → `SocialCredentialsRejectedException`.

`SocialImportControllerTest`, with `self::getContainer()->set(SocialSearchInterface::class, new StubSocialSearch($page))` exactly as `clientWith()` does for candidates (add a sibling helper):
- anonymous → `401`;
- another user's account id → the same status the candidates route gives (read and copy);
- `?query=%20%20` → `422`;
- a page of 5 entries → `count` 5, `posts` holds the first 3 in answer order with the fields above, and the stub received the normalised query (`'rss reader'` for `?query=%20rss%20%20reader`);
- an empty page → `200 {"count":0,"posts":[]}`.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Social/SocialSearch tests/Controller/Api/SocialImportControllerTest.php`
Expected: FAIL (classes and route missing).

- [ ] **Step 3: Implement**

`SocialSearchInterface`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\SocialSearch;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;

interface SocialSearchInterface
{
    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function newest(SocialAccount $account, string $query): TimelinePageModel;
}
```

`NetworkSocialSearch`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\SocialSearch;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\BlueskySessions;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\BlueskySearchPage;
use App\Service\Social\Support\MastodonSearchPage;
use App\Service\Social\Support\SocialSearchPaths;

final readonly class NetworkSocialSearch implements SocialSearchInterface
{
    public function __construct(
        private BlueskySessions $blueskySessions,
        private MastodonAccountApi $mastodon,
        private MastodonApiClient $mastodonClient,
    ) {
    }

    public function newest(SocialAccount $account, string $query): TimelinePageModel
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => BlueskySearchPage::of(
                BlueskySessions::accepted($this->blueskySessions->askAs($account, SocialSearchPaths::bluesky($query))),
                $account->getIdentity(),
            ),
            SocialNetwork::Mastodon => MastodonSearchPage::of($this->mastodonClient->send(
                $this->mastodon->authorizedGet($account, SocialSearchPaths::mastodon($query, null)),
            )),
        };
    }
}
```

If the container cannot autowire the interface alone, add `App\Service\Social\SocialSearch\SocialSearchInterface: '@App\Service\Social\SocialSearch\NetworkSocialSearch'` to `config/services.yaml` (check how `SocialCandidatesInterface` is bound and do the same).

`SocialSearchPreviewRequest`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Support\SocialSearchQuery;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialSearchPreviewRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: [SocialSearchQuery::class, 'normalized'])]
        #[Assert\Length(max: 512, normalizer: [SocialSearchQuery::class, 'normalized'])]
        public string $query = '',
    ) {
    }

    public function toQuery(): string
    {
        return SocialSearchQuery::normalized($this->query);
    }
}
```

(If `DomainKnowsNoHttpRule` or the DTO conventions object to the normalizer callable, validate the normalised value in `toQuery()` the way sibling DTOs do — read two of them first.)

`SocialSearchPreviewJson` (in `src/Http`, `final readonly`, injectable like `SocialImportJson`):

```php
    private const int SHOWN_POSTS = 3;

    /** @return array{count: int, posts: list<array{url: ?string, author: ?string, authorAvatarUrl: ?string, text: string, publishedAt: ?string}>} */
    public function preview(TimelinePageModel $page): array
    {
        $entries = $page->parsed->entries;

        return [
            'count' => \count($entries),
            'posts' => array_map(self::post(...), \array_slice($entries, 0, self::SHOWN_POSTS)),
        ];
    }

    /** @return array{url: ?string, author: ?string, authorAvatarUrl: ?string, text: string, publishedAt: ?string} */
    private static function post(ParsedEntryModel $entry): array
    {
        return [
            'url' => $entry->url,
            'author' => $entry->author,
            'authorAvatarUrl' => $entry->authorAvatarUrl,
            'text' => $entry->headline->text,
            'publishedAt' => $entry->publishedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
```

Check how other `src/Http/*Json` mappers format dates (some go through a shared helper with a `Z` suffix for naive UTC) and use that instead of `ATOM` if one exists.

`SocialImportController` — inject `SocialSearchInterface $socialSearch` and `SocialSearchPreviewJson $searchPreviewJson`; new action:

```php
    #[Route(
        '/{id}/search-preview',
        name: 'api_me_social_accounts_search_preview',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function searchPreview(
        #[CurrentUser] User $user,
        int $id,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] SocialSearchPreviewRequest $request,
    ): JsonResponse {
        $account = $this->socialAccount->requireConnected($user, $id);
        $this->rateLimitGuard->enforceForUser($this->socialImportLimiter, $user);

        return new JsonResponse(
            $this->searchPreviewJson->preview($this->socialSearch->newest($account, $request->toQuery())),
        );
    }
```

`SocialCredentialsRejectedException`, `SocialNetworkThrottledException` and `SocialNetworkUnreachableException` already map to problems (the candidates route throws them too) — verify in `src/Http/Problem/ExceptionProblems/*`.

`tests/Support/StubSocialSearch.php`: `final class` implementing the interface, constructor takes the `TimelinePageModel` to return and records `public ?string $query`.

- [ ] **Step 4: Run to verify they pass**

Same command as Step 2. Expected: PASS. Then `composer check` and `composer md`; confirm the controller passes `ThinControllerRule`.

- [ ] **Step 5: Commit**

```bash
git add -A backend
git commit -m "feat(#2086): preview a search through a connected account before subscribing"
```

---

### Task 4: Frontend types, order, icon and preview call

**Files:**
- Modify: `frontend/src/app/reader/social-feed.ts`
- Modify: `frontend/src/app/reader/state/social-sections.ts`
- Modify: `frontend/src/app/reader/feeds/social-picker/social-import-rows.ts`
- Modify: `frontend/src/app/reader/feeds/social-picker/social-import.service.ts`
- Test: `social-sections` spec (existing file next to it), `social-import.service.spec.ts`

**Interfaces:**
- Produces:
  - `SocialFeedRefDto` kind union includes `'search'` (so `CollectionKind` does too).
  - `export interface SearchPreviewPost { readonly url: string | null; readonly author: string | null; readonly authorAvatarUrl: string | null; readonly text: string; readonly publishedAt: string | null; }`
  - `export interface SearchPreview { readonly count: number; readonly posts: readonly SearchPreviewPost[]; }`
  - `SocialImportService.preview(accountId: number, query: string): Observable<SearchPreview>` — `GET ${endpoint}/${accountId}/search-preview` with `params: { query }`; it does not touch `loading`/`failure` (the tab owns its own state).

- [ ] **Step 1: Write the failing tests**

`social-import.service.spec.ts` (follow its `HttpTestingController` setup):

```ts
it('previews a search with the query as a parameter', () => {
  let preview: SearchPreview | undefined;
  service.preview(7, '"rss reader" || feedreader').subscribe((answer) => (preview = answer));

  const request = http.expectOne(
    (req) => req.url.endsWith('/api/me/social-accounts/7/search-preview') && req.params.get('query') === '"rss reader" || feedreader',
  );
  request.flush({ count: 0, posts: [] });

  expect(preview).toEqual({ count: 0, posts: [] });
});
```

Social-sections spec: a `search` subscription of an account sorts after that account's `tag` subscription (copy an existing ordering test).

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npm test -- social-import.service social-sections`
Expected: FAIL.

- [ ] **Step 3: Implement**

- `social-feed.ts`: `kind: 'home' | 'trending' | 'list' | 'custom_feed' | 'tag' | 'search'`.
- `social-sections.ts` `KIND_ORDER`: `search: 5`.
- `social-import-rows.ts` `COLLECTION_ICONS`: `search: 'search'` (the icon name the add-feed UI already uses; check `IconComponent`'s set).
- `social-import.service.ts`: the two interfaces above and

```ts
  preview(accountId: number, query: string): Observable<SearchPreview> {
    return this.http.get<SearchPreview>(`${this.endpoint}/${accountId}/search-preview`, {
      params: { query },
    });
  }
```

- [ ] **Step 4: Run to verify they pass**

Same command. Expected: PASS. Then `docker compose exec -T frontend npm run check`.

- [ ] **Step 5: Commit**

```bash
git add -A frontend
git commit -m "feat(#2086): the frontend knows search feeds and can preview one"
```

---

### Task 5: The Search tab

**Files:**
- Create: `frontend/src/app/reader/feeds/social-picker/social-search-tab.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/reader/feeds/social-picker/social-import-dialog.component.ts`, `.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `social-search-tab.component.spec.ts`, `social-import-dialog.component.spec.ts`

**Interfaces:**
- Consumes: `SocialImportService.preview`, `SearchPreview` (Task 4); the dialog's existing `importer.importSelection()` and private `finish()`.
- Produces: `<app-social-search-tab [network]="…" [accountId]="…" (subscribe)="…" />`; `subscribe` emits the trimmed query string.

Read `docs/design-language.md` before building the tab (field, buttons, warning box, avatar, density). Reuse `ButtonComponent`, `WarningBoxComponent`, `AvatarComponent`, `ErrorBannerComponent`, `SkeletonComponent`; styles in the sibling `.scss`, tokens only (no hex, no ad-hoc `px`).

**Behaviour:**
1. A text input (`data-testid="social-search-query"`), labelled "Search query", `maxlength` 512, `cdkFocusInitial` when the tab opens.
2. Below it, one always-visible example line, then a native `<details>` "Search syntax" (`data-testid="social-search-syntax"`) with the network's list:
   - Bluesky: `"phrase"` — exact phrase; `a || b` — either; `-word` — exclude; `( … )` — group; example `("rss reader" || "feed reader" || rssreader) -gazette`.
   - Mastodon: words — all must match; `"phrase"` — exact phrase; `-word` — exclude; note: needs full-text search on your instance; only posts from people who allow search are found.
3. **Preview** button (`data-testid="social-search-preview"`), disabled while the trimmed query is empty or a preview is running; Enter in the field also previews.
4. Result area (`data-testid="social-search-result"`):
   - loading → skeleton;
   - error → `ErrorBannerComponent` with `problemMessage(parseProblem(error))`;
   - `count > 0` → "N recent posts match" (plural key) and up to 3 rows: avatar, author, text (clamped to 3 lines), relative or short date, each linking to `url` in a new tab;
   - `count === 0` → `WarningBoxComponent` (`data-testid="social-search-empty"`): Bluesky "No recent posts match. You can still subscribe; new matches will appear." / Mastodon adds "Your instance may not support full-text search."
5. **Subscribe** button (`data-testid="social-search-subscribe"`), enabled only once a preview for the *current* query has finished (editing the query after previewing disables it again); emits `subscribe` with the query.

**Dialog wiring:**
- `tabs`: append `'search'` for both networks. Type it as `DialogTab = ImportTab | 'search'`; `tabCounts` stays `Partial` (no count for search).
- In the template, when `tab() === 'search'`: hide the filter input, the state chips, the row list and the footer's apply button; render `<app-social-search-tab>` instead.
- `(subscribe)="subscribeSearch($event)"`:

```ts
  protected subscribeSearch(query: string): void {
    const selection = {
      subscribe: [],
      unsubscribe: [],
      collections: [{ kind: 'search' as const, id: query, name: query }],
    };
    this.importer.importSelection(this.data.accountId, selection).subscribe({
      next: (result) => this.finish(result),
      error: () => undefined,
    });
  }
```

  `finish()` already reloads subscriptions, starts a refresh when `imported > 0`, toasts and closes. An already-subscribed query toasts "already subscribed" via the existing `importDone` copy.
- i18n: `settings.social.importTab.search` ("Search" / "Suche") and a `settings.social.search.*` block for every string above, in both files.

- [ ] **Step 1: Write the failing tests**

`social-search-tab.component.spec.ts` (Jest, `TestBed`, stub `SocialImportService.preview` with `of(...)`/`throwError`):
- Preview disabled for an empty or whitespace-only query; enabled after typing.
- Clicking Preview calls `preview(accountId, query)` with the trimmed query and renders the count and at most 3 posts.
- `count: 0` renders `social-search-empty`; for `network: 'mastodon'` it contains the full-text-search hint.
- Subscribe is disabled before a preview, enabled after it, disabled again after editing the query; clicking it emits the query.
- The syntax list shows `||` for Bluesky and no `||` for Mastodon.

`social-import-dialog.component.spec.ts`:
- The tab list ends with `search` for both networks.
- With the search tab selected, the filter input is absent; a `subscribe` event posts `{subscribe: [], unsubscribe: [], collections: [{kind: 'search', id: 'rss reader', name: 'rss reader'}]}` to the import endpoint and closes the dialog with the result.

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npm test -- social-search-tab social-import-dialog`
Expected: FAIL.

- [ ] **Step 3: Implement** the component, the dialog wiring and the copy as specified above.

- [ ] **Step 4: Run to verify they pass**

Same command, then `docker compose exec -T frontend npm run check`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A frontend
git commit -m "feat(#2086): a Search tab previews a query and subscribes to it"
```

---

### Task 6: Verification

- [ ] **Step 1: Gates.** Backend: `php bin/phpunit` and `docker compose exec php composer test:parallel` (in parallel), `composer check`, `composer md`, `composer infection:diff`. Frontend: `docker compose exec -T frontend npm run check`. PhpStorm inspections on changed PHP. Scan today's dev log (`ls -t var/log/dev-*.log | head -1 | xargs tail -n 100 | jq .`).
- [ ] **Step 2: Live check on :4200** with Lars's connected Bluesky account: open the picker → Search tab → preview `("rss reader" || "feed reader" || rssreader || feedreader) -gazette` → expect a non-zero count and three on-topic posts → Subscribe → the feed appears after the account's tags, titled by the query, fills on the first refresh with no replies, and "visit site" opens the same search on bsky.app. Preview `zzqqxxnonexistent` → the empty warning. If a Mastodon account is connected, repeat with `rss reader -feedly` and note what the instance returns.
- [ ] **Step 3:** `/simplify`, then open the PR into `develop` with `Closes #2086`.

---

### Task 7: Keep only posts whose own text matches the query

Added after the live check (2026-11-07): Bluesky's search also matches link-card titles/descriptions, image alt text and quoted posts, so about a third of the results showed no match in what the reader displays. Lars chose **own text only**: the reader evaluates the query itself and keeps a result only when the post's own text — or the text of a post it quotes — matches. Same rule for Mastodon. The preview count reflects the filtered set.

**Files:**
- Create: `backend/src/Service/Social/Support/SocialSearchMatch.php` (or a small set of classes in the right role folders if PHPMD/clean code demands it — a parsed expression tree belongs in `Model/`, a per-call parser built with `new` in `Pass/`; a static entry point stays in `Support/`)
- Modify: `backend/src/Service/Social/Support/BlueskySearchPage.php`, `MastodonSearchPage.php` (take the query; filter before mapping)
- Modify: `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`, `MastodonTimelineSource.php`, `backend/src/Service/Social/SocialSearch/NetworkSocialSearch.php` (pass the query)
- Test: `backend/tests/Service/Social/Support/SocialSearchMatchTest.php` (new), the two `*SearchPageTest`s, source tests as needed

**Interfaces:**
- Produces: `SocialSearchMatch::matcher(string $query): \Closure(string): bool` — parse once, test many posts. (Name may change if the structure needs to; keep one public entry point.)
- Changes: `BlueskySearchPage::of(ApiResponseModel $accepted, string $accountDid, string $query)`, `MastodonSearchPage::of(ApiResponseModel $accepted, string $query)`.

**Query semantics (the syntax the Search tab documents):**
- Terms are ANDed by default.
- `a || b` — OR; binds looser than AND (`a b || c` = `(a AND b) OR c`).
- `-term`, `-"phrase"`, `-( … )` — NOT.
- `"phrase"` — the words in order, separated by any run of non-letter/non-digit characters (so `"rss reader"` matches `RSS-Reader`, `rss  reader`).
- `( … )` — grouping.
- Matching is case-insensitive (`mb_strtolower`), Unicode-aware. A word matches at the start of a word in the text: preceded by start-of-text or a non-letter/non-digit (`(?<![\p{L}\p{N}])`), not necessarily ending at a word boundary — so `reader` matches `readers`, `rssreader` matches `#RSSReader`. The same prefix rule applies to a phrase's last word.
- A token with a `prefix:` (e.g. `from:alice`, `lang:de`, `since:…`, Mastodon's `has:`) is a network-side filter the reader cannot check: treat it as matching.
- Never throw on odd input: unbalanced quotes/parentheses, a lone `-`, a lone `||`, an empty query — degrade leniently (unclosed quote = phrase to end; stray `)` ignored; an expression with no checkable terms matches everything). The network already validated the query; this filter only narrows.

**Which text counts:**
- Bluesky: `post.record.text`, plus the quoted post's text when the post embeds a record — `post.embed.record.value.text` (`app.bsky.embed.record#view`) or `post.embed.record.record.value.text` (`app.bsky.embed.recordWithMedia#view`). Not link cards, not alt text. Joined with a newline so a phrase never spans the two.
- Mastodon: `content` with tags stripped and entities decoded (reuse the helper `MastodonTimelinePage` uses for the body if there is one), plus `quote.quoted_status.content` when present. A boost's own text is its `reblog`'s.

- [ ] **Step 1: Failing tests.** `SocialSearchMatchTest` with a data provider covering at least: plain AND (`rss reader` matches "reader for rss", not "rss only"); phrase across hyphen/case; `||`; precedence (`a b || c`); `-word` and `-"phrase"`; parentheses with exclusion (`("rss reader" || feedreader) -gazette` against a post naming gazette → false); prefix match (`reader` ↔ "readers", `rssreader` ↔ "#RSSReader"); no infix match (`rss` must not match "ProgRSS"... i.e. `ssreader` does not match "rssreader"); `from:alice` ignored; Unicode (`über` ↔ "Über", `\p{L}` boundary before "über"); lenient inputs (unclosed quote, stray `)`, lone `-`, empty query → true). `BlueskySearchPageTest`: a post whose text lacks the term but whose link card has it is dropped; a post whose quoted record has it is kept. `MastodonSearchPageTest`: HTML content `<p>Best <a …>#RSSReader</a></p>` matches `rssreader`; a status that only matches in a media description is dropped.
- [ ] **Step 2: Run, see RED.**
- [ ] **Step 3: Implement** — parse once per page, filter before handing to `BlueskyTimelinePage::of` / `MastodonTimelinePage::of`. Keep every touched src file PHPMD-clean and phptramp-clean (the query is passed one hop; if it would tunnel, hold it in a per-call object).
- [ ] **Step 4: GREEN**, then `php bin/phpunit tests/Service/Social tests/Controller/Api/SocialImportControllerTest.php`, `composer check`, `composer md`.
- [ ] **Step 5: Commit** `feat(#2086): a search keeps only posts whose own text matches the query`

---

### Task 8: A "Searches" group in each network's sidebar section

Added after the live check (2026-11-07, Lars, screenshot): search feeds sat among the account's lists and custom feeds. They get their own expandable group, built and behaving like the existing **Feeds** group (profiles): a group row with glyph, label, unread count, a chevron that expands/collapses (state remembered like the Feeds group's), the search feeds listed beneath, and the group row itself selectable — selecting it lists the entries of all that network's search feeds, with mark-read and refresh scoped to them.

**Backend**
- `backend/src/Enum/NetworkFeedKind.php`: `case Search = 'search';`.
- `backend/src/Repository/NetworkScopeDql.php`: `NetworkFeedKind::Search` restricts to feeds that have a `SocialFeed` of kind `SocialFeedKind::Search` (an `EXISTS` subquery on `App\Entity\SocialFeed` joined by feed; queries stay in `src/Repository`). Read how `Profile` is wired through `NetworkScope`, entry list, unread counts, mark-read (`MarkReadRequest`) and refresh (`RefreshController`) and make sure every one of them handles `Search` — the enum may be matched exhaustively somewhere.
- Tests: the repository/controller tests that cover `feedKind=profile` get a `feedKind=search` sibling: list returns only search-feed entries of that network; mark-read with `scope=network&network=bluesky&feedKind=search` marks only those; refresh scope likewise.

**Frontend** (read `docs/design-language.md` and the Feeds group first)
- `core/social-network.ts` (or wherever `NetworkFeedKind` is declared): add `'search'`.
- `state/social-sections.ts`: `SocialSection` gains `searches` (same shape as `profiles`: subscriptions sorted by title, unreadCount, entryCount); search subscriptions are taken out of the account groups; the section's own totals still include them; a section with only searches still renders.
- `shell/sidebar/sidebar-social-section.component.{ts,html}`: render the Searches group after the account groups and before Feeds (order: accounts → Searches → Feeds → Saved), with its own collapsible state and active state (`selection.feedKind === 'search'`). The Feeds block and the Searches block are the same markup with different inputs — extract the shared part (a small child component or an `ng-template` driven by a group descriptor) rather than duplicating it. Glyph: `search`. Divider handling as for Feeds.
- `shell/list-heading.service.ts`: the network title for `feedKind === 'search'` reads `Bluesky · Searches` (map feedKind → label key instead of the current profile-only ternary); `sectionOf`/selection lookups that assume `feedKind ⇒ profiles` must pick `searches` for `'search'`.
- Any other place that branches on `feedKind` (grep `feedKind` and `'profile'` under `src/app/reader`) handles `'search'`.
- i18n (`public/i18n/en.json`, `de.json`): `reader.socialSearches` "Searches" / "Suchen", `reader.toggleSocialSearches` mirroring `reader.toggleSocialFeeds`.
- Tests: social-sections spec (searches leave account groups, land in `searches`, counts), sidebar section spec (group renders, toggles, selects with `feedKind: 'search'`), list-heading spec (title).

**Steps:** failing tests (backend + frontend) → RED → implement → GREEN → `composer check`, `composer md`, `php bin/phpunit` for the touched areas, `docker compose exec -T frontend npm run check` → commit `feat(#2086): searches get their own group in a network's sidebar section`.

---

### Task 9: Edit a search's query

Added 2026-11-07 (Lars): searches become editable, reversing the earlier "not editable" decision. Runs **after** the final-review fix wave (which adds `from:` author matching and reshapes the Search tab form).

**Decisions (Lars):**
- Edited in the existing **Edit feed** dialog (sidebar row menu → edit subscription), which gains a query field for search feeds with the same syntax help and **Preview** as the Search tab. The Search tab's form (field + example + syntax help + preview result) becomes one shared component used by both; the tab keeps its Subscribe button, the dialog its Save.
- On save, the feed is re-queried in place — same subscription, same position, tags, inclusion flags, custom title and read state — and the stored posts are **re-checked against the new query and the non-matching ones removed**. Favourited and saved posts are kept (find how unsubscribe/"Saved from" treats them and follow the same rule; never delete a favourited or saved entry).

**Backend**
- Endpoint: `PUT /api/subscriptions/{id}/search-query` with `{"query": "…"}` (same validation as the preview: normalised, non-blank, ≤ 512 → 422). 404 when the subscription is not the user's, 422 (problem) when it is not a search feed, 409 (problem, existing already-subscribed mapping if there is one) when the account already has a search with that normalised query. Response: the updated subscription in the same JSON shape the subscriptions endpoints return. Thin controller; JSON via the existing subscription mapper.
- A service in `Service/Social` performs the change in one transaction: update the `SocialFeed` remote id (add an intention-revealing entity method, e.g. `requery(string $query)`, which also clears `newestSeenId`), the `Feed` url (`SocialFeedUrl::of`), site url (`PrivateFeedSite::of`), and the feed title when it still equals the old query (a custom title on the subscription is untouched); schedule the feed due now; then prune.
- Prune: a repository method returns the feed's entries that are neither favourited nor saved, with what the matcher needs (stored text and author identity); the service evaluates `SocialSearchMatch` against each (own text from the stored content — quotes are not stored separately, so the stored body is the text; author from the entry's author URL/handle as stored — read how `BlueskyTimelinePage`/`MastodonTimelinePage` store author fields and match `from:` against the same identity the live filter uses) and deletes the non-matching ones through the repository (bulk delete; mind the FTS triggers on SQLite and any entry-dependent tables — read how existing entry deletion, e.g. retention/purge, does it and reuse that path).
- Tests: service unit tests (re-query fields, custom title kept, duplicate → exception, non-search → exception, prune keeps matching + favourited + saved, removes the rest); controller tests for each status; both DB legs for the repository methods.

**Frontend**
- Extract the shared search form component from `social-search-tab` (query field, example line, syntax help, preview + result, emits the query/preview state); `social-search-tab` = form + Subscribe; the edit-subscription dialog shows the form for `subscription.social?.kind === 'search'`, pre-filled with the current query (the feed's remote id — expose it in the subscription JSON as `social.query` for search feeds if it is not already derivable; read `SubscriptionJson`), Preview needs the account id (`social.accountId`).
- Save calls the new endpoint, then reloads subscriptions and the entry list and runs a refresh of that feed; errors show via the existing problem banner.
- i18n en/de for the field label and any new messages.
- Tests: form component spec (moved from the tab spec), tab spec (Subscribe still works), edit dialog spec (search feed shows the form pre-filled; Save posts to the endpoint; a non-search feed shows no query field).

**Steps:** failing tests → RED → implement → GREEN → backend gates (`composer check`, `composer md`, SQLite + MySQL for touched tests) and `npm run check` → commit `feat(#2086): a search's query can be edited, its stored posts re-checked`.
