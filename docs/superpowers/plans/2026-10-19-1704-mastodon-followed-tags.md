# Mastodon Followed Tags as Private Feeds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A connected Mastodon account's followed hashtags show up in "Manage feeds" as a third tab. Ticking one creates a private feed that reads the instance's tag timeline with the account's token. The hourly private-feed sync keeps the subscribed tags in line with the tags the account follows.

**Architecture:** Followed tags are a new `SocialFeedKind::Tag`. Lists already travel candidate source → picker → `PrivateFeeds::create` → `SocialFeed` row → `MastodonTimelineSource` → `PrivateFeedSync`, and a tag rides that same path. The work is one more case at each step that branches on the kind, so there is no new pipeline. A tag's remote id is its name, lowercased in exactly one place (`PrivateFeedModel::followedTag`). Its display name is `#Name`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (backend), Angular 20 standalone + signals, Transloco, Jest (frontend). Mastodon REST API: `GET /api/v1/followed_tags` (4.0+, paged by `Link` header, `limit` ≤ 200) and `GET /api/v1/timelines/tag/:hashtag` (`limit` ≤ 40, `min_id`).

**Spec:** GitHub issue [larspohlmann/simple-feed-reader#1704](https://github.com/larspohlmann/simple-feed-reader/issues/1704). It builds on the list private feeds of #1599 (`docs/superpowers/plans/2026-10-12-1599-social-private-feeds.md`) and the hourly sync of #1666.

## Global Constraints

- Read the root `CLAUDE.md` before writing a line. Clean Code is mandatory: no abbreviations, no boolean flag parameters, guard clauses, `final readonly`, default to no comment.
- **Correction to the issue, decided here:** the issue says an unfollowed tag is "never removed automatically". That is wrong for lists too: since #1666 a list that disappears on the network is unsubscribed by the sync. A tag follows the same rule as a list. Following a tag on Mastodon adds it within the hour, and unfollowing it removes it. A tag the user unticks here is not added back, which `KnownSocialCollection` already guarantees. Post this as a comment on #1704 when the PR opens.
- A tag's remote id is `mb_strtolower($name)`. Mastodon matches tags case-insensitively, so `#Caturday` and `#caturday` must be one feed. Display name: `'#' . $name` with the casing the instance sent.
- A tag name is valid only if it matches `/^[\p{L}\p{M}\p{N}_\x{00B7}\x{200C}]{1,255}$/uD`: no `:`, `/`, `?`, `&`, `#` or whitespace. It is still `rawurlencode`d wherever it enters a URL.
- Tags are Mastodon-only. `PrivateFeedRules` refuses a tag on Bluesky, and Bluesky's picker gets no tags tab.
- No migration: `kind` and `reason` are `VARCHAR(16)` with a PHP enum (`'tag'`, `'followed'`, `'unfollowed'` all fit). Run `bin/console doctrine:schema:validate` to prove it.
- HTTP statuses in tests are `Response::HTTP_*`, never literals.
- Commit format is `type(#1704): …`, with no Claude attribution.
- **Worktree:** the work runs in `.claude/worktrees/1704-mastodon-followed-tags` on branch `feature/1704-mastodon-followed-tags`. Run `composer install` in `backend/` and `npm ci` in `frontend/` in the worktree itself; never symlink `vendor`, because that breaks `project_dir`. Docker and e2e run only from the main checkout (the preflight guard enforces this), so frontend Jest runs natively here as `npx jest`, and the typecheck runs separately through `npm run check`.

---

## File map

Backend, modified:

| File | Change |
|---|---|
| `backend/src/Enum/SocialFeedKind.php` | `case Tag = 'tag'`; a remembered collection |
| `backend/src/Enum/PrivateFeedSyncReason.php` | `Followed`, `Unfollowed` |
| `backend/src/Service/Social/Model/PrivateFeedModel.php` | `followedTag()`; `of()` arm |
| `backend/src/Service/Social/Support/PrivateFeedRules.php` | tag arm + `isMastodonTagName()` |
| `backend/src/Service/Social/Support/SocialFeedUrl.php` | `'tag' => 'tag'` segment |
| `backend/src/Service/Social/Support/PrivateFeedSite.php` | `/tags/<name>` |
| `backend/src/Service/Social/TimelineSource/MastodonTimelineSource.php` | `/api/v1/timelines/tag/<name>` |
| `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php` | exhaustive `match` arm that refuses a tag |
| `backend/src/Service/Social/Model/NetworkCandidatesModel.php` | `tags` |
| `backend/src/Service/Social/Model/NetworkCollectionsModel.php` | `tags` |
| `backend/src/Service/Social/CandidateSource/MastodonCandidateSource.php` | page `/api/v1/followed_tags`; shared page collector |
| `backend/src/Service/Social/Model/ImportCandidatesModel.php` | `tags` |
| `backend/src/Service/Social/ImportCandidates.php` | tag candidates |
| `backend/src/Http/SocialImportJson.php` | `'tags'` key |
| `backend/src/Dto/Social/SocialImportCollectionItem.php` | `tag` in the choice |
| `backend/src/Service/Social/SocialImporter.php` | `kindLabel` arm |
| `backend/src/Service/Social/PrivateFeedSync.php` | `Tag` in `SYNCED_KINDS` |
| `backend/src/Service/Social/PrivateFeedSyncApplier.php` | tags in `byKey()` |
| `backend/src/Service/Social/Support/PrivateFeedSyncReasons.php` | `Followed` / `Unfollowed` |
| `backend/tests/Support/StubSocialCandidates.php` | carry `tags` into collections |

Backend, created: `backend/src/Service/Social/Support/MastodonFollowedTag.php` (a tag JSON node → `SocialCollectionModel`) and its test.

Frontend, modified: `reader/social-feed.ts`, `reader/state/social-sections.ts`, `reader/feeds/social-picker/social-import.service.ts`, `social-import-rows.ts`, `social-import-dialog.component.ts`, `settings/social/social-accounts.service.ts`, `public/i18n/en.json`, `public/i18n/de.json`, and their specs.

---

### Task 1: A followed tag is a private-feed kind that can be addressed and fetched

**Files:**
- Modify: `backend/src/Enum/SocialFeedKind.php`
- Modify: `backend/src/Service/Social/Model/PrivateFeedModel.php`
- Modify: `backend/src/Service/Social/Support/PrivateFeedRules.php`
- Modify: `backend/src/Service/Social/Support/SocialFeedUrl.php`
- Modify: `backend/src/Service/Social/Support/PrivateFeedSite.php`
- Modify: `backend/src/Service/Social/TimelineSource/MastodonTimelineSource.php`
- Modify: `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php:60-71`
- Modify: `backend/src/Service/Social/SocialImporter.php` (`kindLabel`)
- Test: `backend/tests/Enum/SocialFeedKindTest.php`, `backend/tests/Service/Social/Model/PrivateFeedModelTest.php`, `backend/tests/Service/Social/Support/PrivateFeedRulesTest.php`, `backend/tests/Service/Social/Support/SocialFeedUrlTest.php`, `backend/tests/Service/Social/Support/PrivateFeedSiteTest.php`, `backend/tests/Service/Social/TimelineSource/MastodonTimelineSourceTest.php`, `backend/tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php`

**Interfaces:**
- Produces: `SocialFeedKind::Tag` (`'tag'`), with `isRememberedCollection()` true; `PrivateFeedModel::followedTag(string $tagName, string $name): PrivateFeedModel` (remote id `mb_strtolower($tagName)`); `PrivateFeedRules::isMastodonTagName(string $tagName): bool`; URL segment `tag` (`social:mastodon:<accountId>:tag:<name>`).

- [ ] **Step 1: Write the failing tests**

In `SocialFeedKindTest`, rename `testOnlyAListOrACustomFeedIsARememberedCollection` to `testAListACustomFeedOrAFollowedTagIsARememberedCollection` and expect:

```php
self::assertSame(
    [SocialFeedKind::UserList, SocialFeedKind::CustomFeed, SocialFeedKind::Tag],
    array_values($remembered),
);
```

In `PrivateFeedModelTest` add:

```php
public function testAFollowedTagIsKeyedByItsLowercasedNameAndKeepsTheCasingItWasShownIn(): void
{
    $tag = PrivateFeedModel::followedTag('Caturday', '#Caturday');

    self::assertSame(SocialFeedKind::Tag, $tag->kind);
    self::assertSame('caturday', $tag->remoteId);
    self::assertSame('#Caturday', $tag->name);
    self::assertSame('tag:caturday', $tag->key());
    self::assertEquals($tag, PrivateFeedModel::of(SocialFeedKind::Tag, 'CATURDAY', '#Caturday'));
}
```

In `PrivateFeedRulesTest` add a provider and test next to `mastodonListIds`:

```php
#[DataProvider('mastodonTagNames')]
public function testAMastodonTagNameIsWordCharactersOnly(string $tagName, bool $allowed): void
{
    self::assertSame($allowed, PrivateFeedRules::isMastodonTagName($tagName));
    self::assertSame(
        $allowed,
        PrivateFeedRules::allows(SocialNetwork::Mastodon, PrivateFeedModel::followedTag($tagName, '#' . $tagName)),
    );
}

/** @return iterable<string, array{string, bool}> */
public static function mastodonTagNames(): iterable
{
    yield 'letters' => ['caturday', true];
    yield 'digits and underscore' => ['100_days', true];
    yield 'non-latin letters' => ['日本語', true];
    yield 'a combining mark' => ["cafe\u{0301}", true];
    yield 'a middle dot' => ["l\u{00B7}l", true];
    yield 'the longest name' => [str_repeat('a', 255), true];
    yield 'a name over 255 characters' => [str_repeat('a', 256), false];
    yield 'empty' => ['', false];
    yield 'a leading hash' => ['#caturday', false];
    yield 'a space' => ['cat day', false];
    yield 'a trailing newline' => ["caturday\n", false];
    yield 'a slash' => ['cat/../day', false];
    yield 'a query' => ['cat?limit=1', false];
    yield 'a colon' => ['cat:day', false];
}
```

and add these to `verdicts()`:

```php
yield 'mastodon followed tag' => [SocialNetwork::Mastodon, PrivateFeedModel::followedTag('caturday', '#caturday'), true];
yield 'bluesky followed tag' => [SocialNetwork::Bluesky, PrivateFeedModel::followedTag('caturday', '#caturday'), false];
```

In `SocialFeedUrlTest`, `testEveryKindIsReadBackFromItsOwnUrl` iterates the cases, so read it and confirm it now fails for `Tag`. Then add:

```php
public function testAMastodonTagIsAddressedByItsLowercasedName(): void
{
    self::assertSame(
        'social:mastodon:' . SocialAccounts::mastodon()->requireId() . ':tag:caturday',
        SocialFeedUrl::of(SocialAccounts::mastodon(), PrivateFeedModel::followedTag('Caturday', '#Caturday')),
    );
}
```

Mirror `testAMastodonListIsAddressedByItsId` (line 44) for how that test obtains an account with an id. If it uses a different account helper, use that same one.

In `PrivateFeedSiteTest`, next to the list case:

```php
self::assertSame(
    'https://mastodon.example/tags/caturday',
    PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::followedTag('Caturday', '#Caturday')),
);
```

In `MastodonTimelineSourceTest`:

```php
public function testATagRequestReadsTheTagTimelineAfterTheNewestSeenStatus(): void
{
    $tag = self::socialFeed(SocialFeedKind::Tag, 'caturday');
    $tag->recordNewestSeen('100');

    $request = $this->source(new RoutedHttpClient([]))->request($tag);

    self::assertSame('https://mastodon.example/api/v1/timelines/tag/caturday?limit=40&min_id=100', $request->url);
    self::assertSame('Bearer ' . SocialAccounts::ACCESS_TOKEN, $request->headers['Authorization'] ?? null);
}

public function testANonLatinTagIsPercentEncodedInThePath(): void
{
    $request = $this->source(new RoutedHttpClient([]))->request(self::socialFeed(SocialFeedKind::Tag, '日本語'));

    self::assertSame(
        'https://mastodon.example/api/v1/timelines/tag/' . rawurlencode('日本語') . '?limit=40',
        $request->url,
    );
}

/** @return iterable<string, array{string}> */
public static function foreignTagNames(): iterable
{
    yield 'parent path' => ['..'];
    yield 'empty' => [''];
    yield 'a query' => ['cat?limit=1'];
    yield 'a slash' => ['cat/day'];
}

#[DataProvider('foreignTagNames')]
public function testAForeignTagNameIsNeverTurnedIntoARequest(string $tagName): void
{
    $this->expectException(SocialNetworkUnreachableException::class);

    $this->source(new RoutedHttpClient([]))->request(self::socialFeed(SocialFeedKind::Tag, $tagName));
}
```

In `BlueskyTimelineSourceTest`, add a test that a `SocialFeedKind::Tag` feed throws `SocialNetworkUnreachableException` from `request()`. Use that file's own `socialFeed`/`source` helpers; read them first.

- [ ] **Step 2: Run the tests to see them fail**

Run: `cd backend && php bin/phpunit tests/Enum/SocialFeedKindTest.php tests/Service/Social/Model/PrivateFeedModelTest.php tests/Service/Social/Support tests/Service/Social/TimelineSource`
Expected: FAIL on `SocialFeedKind::Tag` (undefined case) and `followedTag` (undefined method).

- [ ] **Step 3: Implement**

`SocialFeedKind`:

```php
case Tag = 'tag';

public function isRememberedCollection(): bool
{
    return self::UserList === $this || self::CustomFeed === $this || self::Tag === $this;
}
```

`PrivateFeedModel`:

```php
public static function followedTag(string $tagName, string $name): self
{
    return new self(SocialFeedKind::Tag, mb_strtolower($tagName), $name);
}
```

and the arm `SocialFeedKind::Tag => self::followedTag($remoteId, $name),` in `of()`.

`PrivateFeedRules`:

```php
private const string MASTODON_TAG = '/^[\p{L}\p{M}\p{N}_\x{00B7}\x{200C}]{1,255}$/uD';
```

the arm `SocialFeedKind::Tag => SocialNetwork::Mastodon === $network && self::isMastodonTagName($privateFeed->remoteId),` and

```php
public static function isMastodonTagName(string $tagName): bool
{
    return self::matches(self::MASTODON_TAG, $tagName);
}
```

`SocialFeedUrl::SEGMENTS`: append `'tag' => 'tag'`.

`PrivateFeedSite::mastodon()` becomes a `match` on the kind:

```php
private static function mastodon(SocialAccount $account, PrivateFeedModel $privateFeed): string
{
    $instance = rtrim($account->getServiceUrl(), '/');

    return match ($privateFeed->kind) {
        SocialFeedKind::UserList => $instance . '/lists/' . rawurlencode($privateFeed->remoteId),
        SocialFeedKind::Tag => $instance . '/tags/' . rawurlencode($privateFeed->remoteId),
        default => $instance,
    };
}
```

`MastodonTimelineSource`: replace the ternary in `pathOf()`, and add `tagName()` next to `listId()`:

```php
private static function pathOf(SocialFeed $socialFeed): string
{
    $path = match ($socialFeed->getKind()) {
        SocialFeedKind::UserList => '/api/v1/timelines/list/' . self::listId($socialFeed),
        SocialFeedKind::Tag => '/api/v1/timelines/tag/' . rawurlencode(self::tagName($socialFeed)),
        default => '/api/v1/timelines/home',
    };
    $newestSeen = $socialFeed->getNewestSeenId();

    return $path . '?limit=' . self::PAGE_SIZE
        . (null === $newestSeen ? '' : '&min_id=' . rawurlencode($newestSeen));
}

private static function tagName(SocialFeed $socialFeed): string
{
    $tagName = $socialFeed->getRemoteId();

    return PrivateFeedRules::isMastodonTagName($tagName)
        ? $tagName
        : throw new SocialNetworkUnreachableException('The Mastodon tag name is not a hashtag.');
}
```

`BlueskyTimelineSource::pathOf()` must stay exhaustive. Add the arm `SocialFeedKind::Tag => throw new SocialNetworkUnreachableException('Bluesky has no followed tags.'),` and import the exception if it isn't already imported.

`SocialImporter::kindLabel()`: add `SocialFeedKind::Tag => 'followed tag',`.

- [ ] **Step 4: Run the tests to see them pass**

Run the command from Step 2. Expected: PASS. Then run `php bin/phpunit tests/Service/Social tests/Enum tests/Repository` to catch any other exhaustive `match` or kind-count assertion. Fix what it names.

- [ ] **Step 5: Lint and commit**

Run: `composer cs && composer stan && composer md`. All touched `src` files must be PHPMD-clean.

```bash
git add backend/src backend/tests
git commit -m "feat(#1704): a followed Mastodon tag is a private-feed kind with its own timeline"
```

---

### Task 2: The Mastodon candidate source reads the followed tags

**Files:**
- Create: `backend/src/Service/Social/Support/MastodonFollowedTag.php`
- Create: `backend/tests/Service/Social/Support/MastodonFollowedTagTest.php`
- Modify: `backend/src/Service/Social/Model/NetworkCandidatesModel.php`
- Modify: `backend/src/Service/Social/Model/NetworkCollectionsModel.php`
- Modify: `backend/src/Service/Social/CandidateSource/MastodonCandidateSource.php`
- Modify: `backend/tests/Support/StubSocialCandidates.php`
- Test: `backend/tests/Service/Social/CandidateSource/MastodonCandidateSourceTest.php`

**Interfaces:**
- Consumes: `PrivateFeedRules::isMastodonTagName()` (Task 1).
- Produces: `NetworkCandidatesModel::$tags` and `NetworkCollectionsModel::$tags`, both `list<SocialCollectionModel>`, defaulting to `[]`, passed by name (`tags:`). Each tag model has `id` = the tag name as sent and `name` = `'#' . name`. `NetworkCollectionsModel::$truncated` is also true when the followed tags were cut, so `isComplete()` keeps the sync away from a partial tag list.

- [ ] **Step 1: Write the failing tests**

`MastodonFollowedTagTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\Support\MastodonFollowedTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonFollowedTagTest extends TestCase
{
    public function testATagIsOfferedUnderItsHashedName(): void
    {
        self::assertEquals(
            new SocialCollectionModel('Caturday', '#Caturday'),
            MastodonFollowedTag::collectionOf(JsonNodeModel::of(['name' => 'Caturday', 'following' => true])),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusableTags(): iterable
    {
        yield 'no name' => [['following' => true]];
        yield 'a name that is no hashtag' => [['name' => 'cat/../day']];
        yield 'a numeric name' => [['name' => 42]];
    }

    /** @param array<string, mixed> $tag */
    #[DataProvider('unusableTags')]
    public function testATagWithoutAUsableNameIsLeftOut(array $tag): void
    {
        self::assertNull(MastodonFollowedTag::collectionOf(JsonNodeModel::of($tag)));
    }
}
```

Check how `JsonNodeModel::of()` takes its argument (`backend/src/Service/Bluesky/Model/JsonNodeModel.php`) and whether `string('name')` returns null for a non-string value. Adjust the `numeric name` case to whatever the class actually does: read it, don't guess.

In `MastodonCandidateSourceTest`:
- add `private const string FOLLOWED_TAGS = 'GET https://mastodon.example/api/v1/followed_tags?limit=200';` and `private const string NEXT_TAGS = 'https://mastodon.example/api/v1/followed_tags?limit=200&max_id=7';`
- add `self::FOLLOWED_TAGS => self::json([]),` to `routes()`
- add `yield 'tags that are not a list' => [self::FOLLOWED_TAGS, new MockResponse('{"error":"gone"}')];` to `unusableAnswers()`
- add:

```php
public function testFollowedTagsAreOfferedAndPagedThroughTheLinkHeader(): void
{
    $http = new RoutedHttpClient($this->routes([
        self::FOLLOWED_TAGS => self::json(
            [['name' => 'Caturday'], ['name' => 'not/a/tag']],
            '<' . self::NEXT_TAGS . '>; rel="next"',
        ),
        'GET ' . self::NEXT_TAGS => self::json([['name' => 'fediverse']]),
    ]));

    $candidates = $this->source($http)->candidates(SocialAccounts::mastodon());

    self::assertEquals(
        [new SocialCollectionModel('Caturday', '#Caturday'), new SocialCollectionModel('fediverse', '#fediverse')],
        $candidates->tags,
    );
}

public function testCollectionsHoldTheListsAndTheFollowedTags(): void
{
    $http = new RoutedHttpClient([
        self::LISTS => self::json([['id' => '7', 'title' => 'Friends']]),
        self::FOLLOWED_TAGS => self::json([['name' => 'caturday']]),
    ]);

    $collections = $this->source($http)->collections(SocialAccounts::mastodon());

    self::assertEquals([new SocialCollectionModel('7', 'Friends')], $collections->lists);
    self::assertEquals([new SocialCollectionModel('caturday', '#caturday')], $collections->tags);
    self::assertSame([], $collections->customFeeds);
    self::assertSame([], $collections->goneIds);
    self::assertTrue($collections->isComplete());
    self::assertCount(2, $http->requests);
}

public function testTagsPagedOntoAnotherOriginMakeTheCollectionsIncomplete(): void
{
    $http = new RoutedHttpClient([
        self::LISTS => self::json([]),
        self::FOLLOWED_TAGS => self::json(
            [['name' => 'caturday']],
            '<https://evil.example/api/v1/followed_tags?max_id=1>; rel="next"',
        ),
    ]);

    $collections = $this->source($http)->collections(SocialAccounts::mastodon());

    self::assertTrue($collections->truncated);
    self::assertFalse($collections->isComplete());
}
```

and delete `testCollectionsAskOnlyForTheListsAndHoldNoCustomFeedsOrGoneIds`, which the second test replaces.

- [ ] **Step 2: Run the tests to see them fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/CandidateSource/MastodonCandidateSourceTest.php tests/Service/Social/Support/MastodonFollowedTagTest.php`
Expected: FAIL (class `MastodonFollowedTag` not found; property `tags` undefined).

- [ ] **Step 3: Implement**

`MastodonFollowedTag` (static `Support` helper, like `Support/MastodonAccount.php`):

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Model\SocialCollectionModel;

final class MastodonFollowedTag
{
    public static function collectionOf(JsonNodeModel $tag): ?SocialCollectionModel
    {
        $tagName = $tag->string('name');
        if (null === $tagName || !PrivateFeedRules::isMastodonTagName($tagName)) {
            return null;
        }

        return new SocialCollectionModel($tagName, '#' . $tagName);
    }

    private function __construct()
    {
    }
}
```

`NetworkCandidatesModel`: add a last constructor parameter `public array $tags = [],` with `@param list<SocialCollectionModel> $tags followed hashtags; Mastodon only`.

`NetworkCollectionsModel`: add a last constructor parameter `public array $tags = [],`, documented `@param list<SocialCollectionModel> $tags followed hashtags; Mastodon only`.

`MastodonCandidateSource`: generalise `collectFollows` into a page collector both endpoints use, and add the tags:

```php
private const string FOLLOWED_TAGS = '/api/v1/followed_tags?limit=200';

public function candidates(SocialAccount $account): NetworkCandidatesModel
{
    $session = $this->sessionOf($account);
    $follows = $this->collectPages($session, $this->followingUrl($session));

    return new NetworkCandidatesModel(
        self::follows($follows->items, $session->host()),
        $this->lists($session),
        [],
        $follows->truncated,
        tags: self::tagsOf($this->collectPages($session, $session->url(self::FOLLOWED_TAGS))),
    );
}

public function collections(SocialAccount $account): NetworkCollectionsModel
{
    $session = $this->sessionOf($account);
    $tags = $this->collectPages($session, $session->url(self::FOLLOWED_TAGS));

    return new NetworkCollectionsModel(
        $this->lists($session),
        [],
        truncated: $tags->truncated,
        tags: self::tagsOf($tags),
    );
}

private function followingUrl(MastodonSessionModel $session): string
{
    return $session->url(
        '/api/v1/accounts/' . rawurlencode($this->ownAccountId($session)) . '/following?limit=' . self::PAGE_SIZE,
    );
}

private function collectPages(MastodonSessionModel $session, string $firstPage): CollectedItemsModel
{
    $items = [];
    $url = $firstPage;
    do {
        $answer = $this->mastodon->send($session->get($url));
        $fresh = self::nodesOf($answer);
        array_push($items, ...$fresh);
        $url = LinkHeader::next($answer->header('link'));
        if (null !== $url && !LinkHeader::isOn($session->instanceUrl, $url)) {
            return CollectedItemsModel::cutShort($items);
        }
    } while (null !== $url && [] !== $fresh && \count($items) <= CollectedItemsModel::MAX_ITEMS);

    return CollectedItemsModel::capped($items);
}

/** @return list<SocialCollectionModel> */
private static function tagsOf(CollectedItemsModel $tags): array
{
    return array_values(array_filter(array_map(MastodonFollowedTag::collectionOf(...), $tags->items)));
}
```

Remove `collectFollows()`. The candidates' `truncated` stays the follows' flag, because it drives the picker's "only the first 5,000 are listed" note about accounts. `ownAccountId()` must still run before the follows request, so the existing tests' request order holds; check `testFollowingIsPagedThroughTheLinkHeader` if it asserts order.

`StubSocialCandidates::collections()`: carry the tags through:

```php
: new NetworkCollectionsModel(
    $answer->lists,
    $answer->customFeeds,
    truncated: $answer->truncated,
    tags: $answer->tags,
);
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `php bin/phpunit tests/Service/Social`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

Run: `composer cs && composer stan && composer md && composer tramp`

```bash
git add backend/src backend/tests
git commit -m "feat(#1704): read a Mastodon account's followed tags"
```

---

### Task 3: The picker API offers followed tags and imports a ticked one

**Files:**
- Modify: `backend/src/Service/Social/Model/ImportCandidatesModel.php`
- Modify: `backend/src/Service/Social/ImportCandidates.php`
- Modify: `backend/src/Http/SocialImportJson.php`
- Modify: `backend/src/Dto/Social/SocialImportCollectionItem.php`
- Test: `backend/tests/Service/Social/ImportCandidatesTest.php`, `backend/tests/Controller/Api/SocialImportControllerTest.php`, `backend/tests/Dto/Social/SocialImportCollectionItemTest.php`

**Interfaces:**
- Consumes: `NetworkCandidatesModel::$tags` (Task 2), `PrivateFeedModel::followedTag` and `SocialFeedKind::Tag` (Task 1).
- Produces: `GET /api/me/social-accounts/{id}/import-candidates` gains `"tags": [{kind: "tag", id: "caturday", name: "#Caturday", state, subscriptionId}]`, sorted by name. `POST …/import` accepts `{kind: "tag", id, name}` in `collections`.

- [ ] **Step 1: Write the failing tests**

In `ImportCandidatesTest`, read how an existing test builds a Mastodon account and stubs `NetworkCandidatesModel` (e.g. the test at line 63), then add a test:

```php
public function testFollowedTagsAreOfferedByNameAndASubscribedOneSaysSo(): void
```

It stubs `new NetworkCandidatesModel([], [], [], false, tags: [new SocialCollectionModel('fediverse', '#fediverse'), new SocialCollectionModel('Caturday', '#Caturday')])` for a Mastodon account. It first creates a private feed for `PrivateFeedModel::followedTag('caturday', '#caturday')` through the same helper the file uses for subscribed lists. It asserts that `$candidates->tags` lists `#Caturday` (state `Subscribed`, remote id `caturday`) before `#fediverse` (state `New`), each with kind `SocialFeedKind::Tag`.

In `SocialImportControllerTest`:
- every assertion that pins the candidates JSON keys gains `'tags' => []` (or the expected tag rows).
- add `testAFollowedTagIsImportedAsAPrivateFeedOfTheMastodonAccount`, modelled on that file's existing list-import test (read it first). It POSTs `collections: [{kind: 'tag', id: 'Caturday', name: '#Caturday'}]` and asserts a subscription to `social:mastodon:<accountId>:tag:caturday` titled `#Caturday`, with site URL `https://<instance>/tags/caturday`.
- add `testAFollowedTagIsRefusedForABlueskyAccount`. The same POST against a Bluesky account answers `Response::HTTP_UNPROCESSABLE_ENTITY` (or whatever status the file asserts for an invalid list; reuse it) and writes nothing.

In `SocialImportCollectionItemTest` add a case that `kind: 'tag'` passes validation and `toPrivateFeed()` gives `PrivateFeedModel::followedTag('Caturday', '#Caturday')` with remote id `caturday`.

- [ ] **Step 2: Run the tests to see them fail**

Run: `php bin/phpunit tests/Service/Social/ImportCandidatesTest.php tests/Controller/Api/SocialImportControllerTest.php tests/Dto/Social/SocialImportCollectionItemTest.php`
Expected: FAIL (`tags` undefined; `tag` not a valid choice).

- [ ] **Step 3: Implement**

`ImportCandidatesModel`: add `public array $tags,` after `$customFeeds` (`@param list<CollectionCandidateModel> $tags`).

`ImportCandidates::forAccount()`: pass `self::collections(SocialFeedKind::Tag, $network->tags, $subscribed),` as the new fourth argument. `collections()` goes through `PrivateFeedModel::of()`, which lowercases the id, so a subscribed tag matches its key.

`SocialImportJson::candidates()`: add `'tags' => array_map(self::collection(...), $candidates->tags),` after `customFeeds`.

`SocialImportCollectionItem`: add `SocialFeedKind::Tag->value,` to the `Assert\Choice`.

- [ ] **Step 4: Run the tests to see them pass**

Run the command from Step 2, then `php bin/phpunit tests/Controller/Api tests/Service/Social`. Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#1704): offer and import followed Mastodon tags in the picker"
```

---

### Task 4: The hourly sync follows and unfollows tags

**Files:**
- Modify: `backend/src/Enum/PrivateFeedSyncReason.php`
- Modify: `backend/src/Service/Social/PrivateFeedSync.php`
- Modify: `backend/src/Service/Social/PrivateFeedSyncApplier.php`
- Modify: `backend/src/Service/Social/Support/PrivateFeedSyncReasons.php`
- Test: `backend/tests/Service/Social/Support/PrivateFeedSyncReasonsTest.php`, `backend/tests/Service/Social/PrivateFeedSyncTest.php`

**Interfaces:**
- Consumes: `NetworkCollectionsModel::$tags` (Task 2), `PrivateFeedModel::followedTag` (Task 1).
- Produces: `PrivateFeedSyncReason::Followed = 'followed'` and `PrivateFeedSyncReason::Unfollowed = 'unfollowed'`, which `GET /api/me/social-accounts` emits in `sync.changes[].reason` with `kind: 'tag'`.

- [ ] **Step 1: Write the failing tests**

`PrivateFeedSyncReasonsTest`:

```php
public function testANewlyFollowedTagWasFollowed(): void
{
    self::assertSame(
        PrivateFeedSyncReason::Followed,
        PrivateFeedSyncReasons::added(SocialAccounts::mastodon(), PrivateFeedModel::followedTag('caturday', '#caturday')),
    );
}

public function testATagGoneFromTheNetworkWasUnfollowed(): void
{
    self::assertSame(
        PrivateFeedSyncReason::Unfollowed,
        PrivateFeedSyncReasons::removed(
            SocialAccounts::mastodon(),
            PrivateFeedModel::followedTag('caturday', '#caturday'),
            new NetworkCollectionsModel([], []),
        ),
    );
}
```

`PrivateFeedSyncTest`: the class's account is Bluesky. Add a Mastodon account in the tests that need one: `SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user))`. Add:

- update `testHomeAndAnyKindOffTheAllowListAreNeverTouched` so it asserts `[SocialFeedKind::UserList, SocialFeedKind::CustomFeed, SocialFeedKind::Tag]`.
- `testANewlyFollowedTagIsAddedAndLoggedAsFollowed`: with the Mastodon account, call `stampAnHourAgo()` (it stamps `$this->account`, so point `$this->account` at the Mastodon account first), then sync against `new NetworkCollectionsModel([], [], tags: [new SocialCollectionModel('Caturday', '#Caturday')])`. Assert outcome `added: 1`, a subscription to `sprintf('social:mastodon:%d:tag:caturday', $this->account->requireId())`, known keys `['tag:caturday']`, and changes `[['tag', 'caturday', '#Caturday', PrivateFeedSyncReason::Followed]]`.
- `testAnUnfollowedTagIsRemovedAndLoggedAsUnfollowed`: create `PrivateFeedModel::followedTag('caturday', '#caturday')` through `privateFeeds()`, stamp, and sync against an empty network. Assert outcome `removed: 1` and change reason `Unfollowed`.
- `testATagWhoseCasingChangedOnTheInstanceIsKeptNotReplaced`: subscribe `followedTag('caturday', '#caturday')`, stamp, and sync against tags `[new SocialCollectionModel('CatURDay', '#CatURDay')]`. Assert outcome `new PrivateFeedSyncOutcomeModel()` (nothing added or removed).

- [ ] **Step 2: Run the tests to see them fail**

Run: `php bin/phpunit tests/Service/Social/Support/PrivateFeedSyncReasonsTest.php tests/Service/Social/PrivateFeedSyncTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`PrivateFeedSyncReason`: add `case Followed = 'followed';` and `case Unfollowed = 'unfollowed';`.

`PrivateFeedSync::SYNCED_KINDS`: `[SocialFeedKind::UserList, SocialFeedKind::CustomFeed, SocialFeedKind::Tag]`.

`PrivateFeedSyncReasons`:

```php
public static function added(SocialAccount $account, PrivateFeedModel $collection): PrivateFeedSyncReason
{
    if (SocialFeedKind::Tag === $collection->kind) {
        return PrivateFeedSyncReason::Followed;
    }

    return self::isOwnedList($account, $collection)
        ? PrivateFeedSyncReason::Created
        : PrivateFeedSyncReason::Saved;
}

public static function removed(
    SocialAccount $account,
    PrivateFeedModel $collection,
    NetworkCollectionsModel $network,
): PrivateFeedSyncReason {
    if (SocialFeedKind::Tag === $collection->kind) {
        return PrivateFeedSyncReason::Unfollowed;
    }
    if (\in_array($collection->remoteId, $network->goneIds, true) || self::isOwnedList($account, $collection)) {
        return PrivateFeedSyncReason::Deleted;
    }

    return PrivateFeedSyncReason::Unsaved;
}
```

`PrivateFeedSyncApplier::byKey()`: three kinds would make the third copy of the same `array_map`, so fold it:

```php
/** @return array<string, PrivateFeedModel> */
private static function byKey(NetworkCollectionsModel $network): array
{
    $byKey = [];
    foreach ([
        ...self::modelsOf(SocialFeedKind::UserList, $network->lists),
        ...self::modelsOf(SocialFeedKind::CustomFeed, $network->customFeeds),
        ...self::modelsOf(SocialFeedKind::Tag, $network->tags),
    ] as $collection) {
        $byKey[$collection->key()] = $collection;
    }

    return $byKey;
}

/**
 * @param list<SocialCollectionModel> $collections
 *
 * @return list<PrivateFeedModel>
 */
private static function modelsOf(SocialFeedKind $kind, array $collections): array
{
    return array_map(
        static fn (SocialCollectionModel $collection): PrivateFeedModel => PrivateFeedModel::of(
            $kind,
            $collection->id,
            $collection->name,
        ),
        $collections,
    );
}
```

(import `App\Enum\SocialFeedKind`).

- [ ] **Step 4: Run the tests to see them pass**

Run: `php bin/phpunit tests/Service/Social tests/Repository`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#1704): sync followed Mastodon tags hourly"
```

---

### Task 5: The picker shows a Tags tab for Mastodon

**Files:**
- Modify: `frontend/src/app/reader/social-feed.ts`
- Modify: `frontend/src/app/reader/state/social-sections.ts:37-41`
- Modify: `frontend/src/app/reader/feeds/social-picker/social-import.service.ts`
- Modify: `frontend/src/app/reader/feeds/social-picker/social-import-rows.ts`
- Modify: `frontend/src/app/reader/feeds/social-picker/social-import-dialog.component.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `social-import-rows.spec.ts`, `social-import-dialog.component.spec.ts`, and `social-sections` spec if it pins `KIND_ORDER`

**Interfaces:**
- Consumes: the `tags` key and `kind: 'tag'` from Task 3.
- Produces: `ImportTab` includes `'tags'`; the exported `collectionsOf(candidates: ImportCandidates | null): readonly CollectionCandidate[]` (lists, custom feeds, then tags).

- [ ] **Step 1: Write the failing tests**

`social-import-rows.spec.ts`: add `tags: []` to the `rows` fixture and to every `tabCounts` expectation. Add:

```ts
describe('buildRows', () => {
  it('puts followed tags in their own tab with the tag icon', () => {
    const rows = buildRows(
      {
        follows: [],
        lists: [],
        customFeeds: [],
        tags: [{ kind: 'tag', id: 'caturday', name: '#Caturday', state: 'new', subscriptionId: null }],
        truncated: false,
      },
      (key) => key,
    );

    expect(rows.tags.map((row) => [row.key, row.name, row.icon])).toEqual([
      ['tag:caturday', '#Caturday', 'tag'],
    ]);
  });
});
```

Extend the existing `removals` test so a subscribed, unticked tag (`subscriptionId: 9`) appears after the lists.

`social-import-dialog.component.spec.ts`: add `tags: []` to `candidates()`, and replace `has no custom feeds tab on Mastodon` with:

```ts
it('offers followed tags instead of custom feeds on Mastodon', () => {
  const fixture = loaded(
    candidates({
      tags: [collection({ kind: 'tag', id: 'caturday', name: '#Caturday' })],
    }),
    'mastodon',
  );

  expect(tabLabels(fixture)).toEqual(['Accounts 0', 'Lists 0', 'Tags 1']);
});
```

Add a test that ticking a new tag and saving POSTs `collections: [{ kind: 'tag', id: 'caturday', name: '#Caturday' }]`. Model it on the file's existing test that saves a ticked list (read it; reuse its `http.expectOne` and request-body assertions).

- [ ] **Step 2: Run the tests to see them fail**

Run: `cd frontend && npx jest src/app/reader/feeds/social-picker src/app/reader/state`
Expected: FAIL.

- [ ] **Step 3: Implement**

`social-feed.ts`: `{ kind: 'home' | 'list' | 'custom_feed' | 'tag'; accountId: number }`.

`social-sections.ts` `KIND_ORDER`: add `tag: 3,`.

`social-import.service.ts` `ImportCandidates`: add `readonly tags: readonly CollectionCandidate[];`.

`social-import-rows.ts`:

```ts
export type ImportTab = 'accounts' | 'lists' | 'customFeeds' | 'tags';
```

and add `tag: 'tag'` to `COLLECTION_ICONS` and `tags: 'social-import-tags'` to `TAB_TEST_IDS`.

```ts
/** Every collection the picker offers, in tab order. */
export function collectionsOf(candidates: ImportCandidates | null): readonly CollectionCandidate[] {
  return [
    ...(candidates?.lists ?? []),
    ...(candidates?.customFeeds ?? []),
    ...(candidates?.tags ?? []),
  ];
}
```

`removals()` uses `collectionsOf(candidates)` in place of its spread. `buildRows()` adds `tags: (candidates?.tags ?? []).map(collectionRow),`. `tally()` starts from `{ accounts: 0, lists: 0, customFeeds: 0, tags: 0 }`.

`social-import-dialog.component.ts`: the Mastodon tabs become `['accounts', 'lists', 'tags']`, and `chosenCollections()` uses `collectionsOf(this.importer.candidates())`.

`en.json` under `settings.social.importTab`: `"tags": "Tags"`. `de.json`: `"tags": "Hashtags"`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `npx jest src/app/reader src/app/settings`, then `npm run check`, which is the CI gate (ESLint, Prettier at 100 columns, Stylelint, tsc, Jest). Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(#1704): a Tags tab in the Mastodon picker"
```

---

### Task 6: The settings page explains tag sync and logs its changes

**Files:**
- Modify: `frontend/src/app/settings/social/social-accounts.service.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/social/social-section.component.spec.ts`

**Interfaces:**
- Consumes: `reason: 'followed' | 'unfollowed'` and `kind: 'tag'` from Task 4.

- [ ] **Step 1: Write the failing test**

In `social-section.component.spec.ts`, find the test that renders a sync change (search for `syncChange` or `Added`). Add a sibling that loads an account whose `sync.changes` holds `{ kind: 'tag', name: '#Caturday', reason: 'followed', at: … }` and `{ kind: 'tag', name: '#fediverse', reason: 'unfollowed', at: … }`, and expects the texts `Added #Caturday (newly followed)` and `Removed #fediverse (no longer followed)`.

- [ ] **Step 2: Run it to see it fail**

Run: `npx jest src/app/settings/social`
Expected: FAIL (a type error on the union, or the missing translation key rendered raw).

- [ ] **Step 3: Implement**

`social-accounts.service.ts`:

```ts
export type SyncReason = 'saved' | 'created' | 'unsaved' | 'deleted' | 'followed' | 'unfollowed';

export interface SyncChange {
  readonly kind: 'list' | 'custom_feed' | 'tag';
  …
}
```

`en.json`, `settings.social`:
- `syncChange.followed`: `"Added {{name}} (newly followed)"`; `syncChange.unfollowed`: `"Removed {{name}} (no longer followed)"`
- `syncChecked`: `"Lists, tags and feeds last checked {{when}}"`
- `importSectionCaption`: after "…come back any time for new follows.", replace "Lists and feeds you save or create on the network are added within the hour, and ones you remove there go too;" with "Lists, followed tags and feeds you save, follow or create on the network are added within the hour, and ones you remove or unfollow there go too;". Leave the rest of the sentence unchanged.

`de.json`, `settings.social`:
- `syncChange.followed`: `"{{name}} hinzugefügt (neu gefolgt)"`; `syncChange.unfollowed`: `"{{name}} entfernt (nicht mehr gefolgt)"`
- `syncChecked`: `"Listen, Hashtags und Feeds zuletzt geprüft {{when}}"`
- `importSectionCaption`: replace "Listen und Feeds, die du im Netzwerk speicherst oder anlegst, kommen innerhalb einer Stunde dazu, und was du dort entfernst, verschwindet auch hier;" with "Listen, gefolgte Hashtags und Feeds, die du im Netzwerk speicherst, abonnierst oder anlegst, kommen innerhalb einer Stunde dazu, und was du dort entfernst oder entfolgst, verschwindet auch hier;".

If a spec pins the old `syncChecked` or caption text, update it to the new text.

- [ ] **Step 4: Run the tests to see them pass**

Run: `npx jest src/app/settings && npm run check`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(#1704): explain and log followed-tag sync in settings"
```

---

### Task 7: Verify the whole branch

- [ ] **Step 1: Backend gates, both database legs**

From `backend/` in the worktree: `composer check && composer md && composer test:parallel`. Run `bin/console doctrine:schema:validate --env=test` to prove no migration is needed. The MySQL leg needs the Docker stack, which runs only from the main checkout. Hand that run (and `composer infection:diff`) to CI on the PR, and say so in the PR body.

- [ ] **Step 2: Mutation gate on the changed files**

Run `composer infection:diff` (needs pcov). Kill each escaped mutant with a test; never lower `minMsi`. Note that `infection:diff` ignores untracked files, so commit first.

- [ ] **Step 3: Scan the dev log**

`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq .`. Look for deprecations or swallowed errors from the new code paths.

- [ ] **Step 4: Live check against a real instance**

This needs the running app, so do it after merging the branch into the checkout that owns the Docker stack, or ask the user to run it there. Open Settings → Social → Manage feeds for the connected Mastodon account. Confirm the Tags tab lists the account's followed tags, tick one, save, refresh that feed, and confirm posts with that hashtag arrive and the feed's site link opens `/tags/<name>` on the instance. Then follow a new tag on Mastodon, run the sync (`bin/console` command used by the hourly sweep; find it with `bin/console list app`), and confirm the change log shows "Added #… (newly followed)".

- [ ] **Step 5: PR**

Merge `develop` into the branch first. Open the PR against `develop` with `Closes #1704`. Comment on #1704 with the sync-removal correction from Global Constraints.
