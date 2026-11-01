# Delete my own posts (#1972) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From the list or the article, delete one of my own Bluesky or Mastodon posts: on the network (its folded self-replies first) and here (the entry and every copy of it), without it coming back on the next refresh.

**Architecture:** The list/article row's `social` gains `isOwn`, computed in `EntryListRowEnricher` from the user's accounts (one query, no network call) by a new `Social\Support\PostAuthor`. The post writers' `delete` now takes an account and a post id and *reports* `PostDeletion::Deleted|AlreadyGone` instead of swallowing a missing post. A new `Social\OwnPostDeletion` derives every address up front (`Support\OwnPostIds`), deletes the thread replies newest first, then the root, then hands the entry to `Social\LocalPostRemoval`, which buries each copy in `Social\DeletedPostTombstones` (a 7-day per-feed cache entry), deletes the copies by bulk DQL (FK cascades take state, saved-search links, likes, thread posts, cards) and forgets them in the search index. `Refresh\IncomingEntries` drops buried guids before ingest. `DELETE /api/entries/{id}/post` answers `{"outcome": "deleted"|"alreadyGone"}`. The frontend adds a danger "Delete post" action to `entry-actions`, run through `EntryActionHandler.deletePost`.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM, PSR-6 cache pools; Angular 20 signals, CDK dialog, Transloco.

**Spec:** GitHub issue #1972 ("Agreed design (grilled 2026-11-01)"), umbrella #1962. `gh issue view 1972`. The spec is binding; this plan only resolves what it leaves open (see "Resolved ambiguities").

## Global Constraints

- CLAUDE.md is binding: Clean Code, role folders (§10), thin controllers, no comments by default (one line, a real *why* only), PHPStan max, PHPMD-clean on every touched `src` file, phptramp, PhpStorm lint (block on ERROR/WARNING), `infection:diff`.
- HTTP statuses as `Response::HTTP_*`, never literals, in `src` and `tests`.
- Commits `type(#1972): summary`; no Claude attribution anywhere.
- No migration: every table that references `entry` already has `onDelete: 'CASCADE'` (`EntryState`, `SavedSearchEntry`, `SocialLike`, `ThreadPost`, `EntryTranscript`, `LinkCard`, `EntryCategory`, `ShareEnrichment`, `RecommendationItem`, `SavedEntryOrigin`), and SQLite's `entry_search` FTS triggers fire on the bulk delete, exactly as `EntryPruner`'s retention delete already relies on.
- Writes draw on `socialWritesLimiter`; the subscription check `EntryListRepository::getOneSubscribedForUser($user->requireId(), $id)` comes first.
- "Which posts are mine" (spec Q2): Bluesky — the DID in the AT URI guid equals an account's identity; Mastodon — the author's profile URL `https://host/@user` (or `/users/user`) read as `user@host` equals an account's identity (`SocialIdentity::isAccount`, case-insensitive).
- No pre-fetch (Q3): delete with the account whose identity matches; never resolve the post first.
- Order (Q9): replies newest → oldest (highest `ThreadPost.position` first), then the root. A reply already gone counts as deleted. Any failure stops before the root and keeps the entry.
- Already gone (Q4, Q11): delete locally anyway; the frontend shows an **info** toast, `Already deleted on Bluesky — removed here.` (network name substituted).
- Confirmation names the reply count: `Delete this post and its 3 replies?`.
- Tombstone (Q12): no DB table; a cache entry keyed by feed and guid hash, kept 7 days.
- Frontend: Prettier 100 cols, ESLint `max-params` 3, en + de i18n parity, no hex colours in component scss, component styles in `.scss`. Frontend tests only inside the container (`docker compose exec -T frontend npx jest <path>`), **never two jest processes at once**; run `npm run check` **and** `npx ng build` in the container.
- Out of scope: deleting a single folded reply (Q10); undoing my reposts (Q8, stays with Undo repost).

## Resolved ambiguities

1. **Every copy goes, not just the one row.** The list collapses copies of one post across subscribed feeds by `url_hash` (`DuplicateCollapseDql`). Deleting only the clicked entry would make the next copy surface on the next reload. `PostRemovalRepository::copiesOf()` therefore returns the entry itself plus every entry with the same non-null `url_hash`, **except copies in a `saved` feed** (another user's archive). Each copy is tombstoned under its own feed.
2. **Mastodon author fallback.** A Mastodon profile RSS item carries no author URL (`authorUrl` is set only by Atom and the timeline pages). `PostAuthor` reads the author's profile from `Entry::getAuthorUrl()`, else from the status address's account prefix (`MastodonStatusAddress::ofOrNull($address)->authorPrefix`) — the same author.
3. **Bluesky "already gone".** `com.atproto.repo.deleteRecord` does not 404 a missing record: the PDS answers 2xx without a `commit` field (a real delete answers `{"commit":{…}}`). `BlueskyRecords::delete` reports `AlreadyGone` for a 404 **or** a 2xx without `commit`. This cannot be verified live without posting; an old PDS that omits `commit` on a real delete would only show the info toast instead of none — the post is gone either way.
4. **Mastodon ids come from the account's own server.** A root or reply status address on any other host than the account's `serviceUrl` host is refused (`SocialPostRefusedException`) before the first delete call, so a thread is never half-deleted for want of an address. All ids are computed before any delete.
5. **Accounts in any status count for `isOwn`** (the enricher loads all of the user's accounts, as `networksOf` did). Deleting with an account that needs reconnecting fails with the existing `SocialAccountNeedsReconnectException` problem.
6. **Thread replies without a URL** cannot be addressed and do not exist in practice (self-replies always carry a URL); `ThreadPostRepository::urlsNewestFirst` skips them.
7. **No new problem type.** "Not my post" reuses `SocialPostRefusedException` (409 `social_post_refused`), as reply delete does; every other failure already has an arm in `SocialInteractionProblems`/`SocialProblems`.
8. **Endpoint path:** `DELETE /api/entries/{id}/post`, beside `PUT|DELETE /api/entries/{id}/like` and `/repost`; 200 with `{"outcome":"deleted"}` or `{"outcome":"alreadyGone"}`.

## File map

Backend (`backend/`):
- Create `src/Service/Social/Support/PostAuthor.php` — which of the given accounts wrote an entry's post.
- Create `src/Service/Social/Support/OwnPostIds.php` — the id a delete call names the root / a reply by.
- Create `src/Service/Social/Model/PostDeletion.php` — enum `Deleted|AlreadyGone`.
- Create `src/Service/Social/DeletedPostTombstones.php`, `src/Service/Social/LocalPostRemoval.php`, `src/Service/Social/OwnPostDeletion.php`.
- Create `src/Repository/PostRemovalRepository.php`, `src/Repository/StoredPostCopy.php`.
- Create `src/Http/PostDeletionJson.php`.
- Modify `src/Repository/SocialAccountRepository.php` (`networksOf` → `findAllForUserId`), `EntryListRowSocial.php`, `EntryListRowEnricher.php`, `ThreadPostRepository.php`; `src/Http/EntryJson.php`; `src/Service/Social/BlueskyRecords.php`, `PostWriting.php`, `SocialResponses.php`, `PostWriter/{PostWriterInterface,BlueskyPostWriter,MastodonPostWriter}.php`; `src/Service/Refresh/{IncomingEntries,FeedOutcomePersister}.php`, `Refresh/Model/IncomingFeedModel.php`; `src/Controller/Api/EntrySocialPostController.php`; `config/packages/cache.yaml`.
- Tests: create `tests/Service/Social/Support/{PostAuthorTest,OwnPostIdsTest}.php`, `tests/Service/Social/{DeletedPostTombstonesTest,LocalPostRemovalTest,OwnPostDeletionTest}.php`; modify `tests/Repository/{SocialAccountRepositoryTest,EntryListRowEnricherTest}.php`, `tests/Http/EntryJsonTest.php`, `tests/Controller/Api/{EntryLikeControllerTest,EntrySocialViewerControllerTest,EntrySocialPostControllerTest}.php`, `tests/Service/SavedPosts/SavedBlueskyPostInteractionTest.php` (only if its assertion needs it), `tests/Service/Social/PostWriter/{BlueskyPostWriterTest,MastodonPostWriterTest}.php`, `tests/Support/{FakeMastodonNetwork,FeedOutcomePersisters}.php`, `tests/Service/Refresh/FeedOutcomePersisterTest.php`.

Frontend (`frontend/`):
- Modify `src/app/reader/entry-social.ts`, `social-post-api.ts`, `article/composer/post-actions.service.ts`, `entry/entry-actions/{entry-action-handler.ts,entry-actions.component.html,entry-actions.component.scss}`, `shell/entry-state-actions.service.ts`, `shell/reader-shell.component.ts`, `list/magazine/entry-duplicates.component.ts`, `public/i18n/{en,de}.json`; specs beside each; every fixture that builds `social: {…}`.

---

### Task 1: `isOwn` on the list row and the article JSON

**Files:**
- Create: `backend/src/Service/Social/Support/PostAuthor.php`, `backend/tests/Service/Social/Support/PostAuthorTest.php`
- Modify: `backend/src/Repository/SocialAccountRepository.php`, `EntryListRowSocial.php`, `EntryListRowEnricher.php`, `backend/src/Http/EntryJson.php`
- Test: `backend/tests/Repository/SocialAccountRepositoryTest.php`, `EntryListRowEnricherTest.php`, `backend/tests/Http/EntryJsonTest.php`, `backend/tests/Controller/Api/EntryLikeControllerTest.php`, `EntrySocialViewerControllerTest.php`

**Interfaces:**
- Produces: `App\Service\Social\Support\PostAuthor::accountAmong(Entry $entry, list<SocialAccount> $accounts): ?SocialAccount`; `SocialAccountRepository::findAllForUserId(int $userId): list<SocialAccount>`; `EntryListRowSocial::$isOwn` (bool, 4th constructor param, default `false`); JSON `social: {network, isLiked, canLike, isOwn}` on every list row and on `GET /api/entries/{id}` (the article reuses `EntryJson::listRow` through `detail()`).

- [ ] **Step 1: Write the failing `PostAuthorTest`** (plain `TestCase`, no DB)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Service\Social\Support\PostAuthor;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostAuthorTest extends TestCase
{
    #[DataProvider('posts')]
    public function testThePostIsMineWhenOneOfMyAccountsWroteIt(Entry $entry, ?string $expectedIdentity): void
    {
        $accounts = [SocialAccounts::bluesky(), SocialAccounts::mastodon()];

        self::assertSame($expectedIdentity, PostAuthor::accountAmong($entry, $accounts)?->getIdentity());
    }

    /** @return iterable<string, array{Entry, ?string}> */
    public static function posts(): iterable
    {
        $bluesky = self::feed('https://bsky.app/profile/did:plc:alice/rss', SocialNetwork::Bluesky);
        $mastodon = self::feed('https://mastodon.example/@alice.rss', SocialNetwork::Mastodon);
        $plain = self::feed('https://example.com/feed.xml', null);

        yield 'bluesky post by my DID' => [
            self::entry($bluesky, 'at://did:plc:alice/app.bsky.feed.post/3own'),
            'did:plc:alice',
        ];
        yield 'bluesky post by another DID' => [
            self::entry($bluesky, 'at://did:plc:bob/app.bsky.feed.post/3abc'),
            null,
        ];
        yield 'mastodon post by its author profile' => [
            self::entry($mastodon, 'https://elsewhere.example/@x/1', 'https://Mastodon.Example/@Alice'),
            'alice@mastodon.example',
        ];
        yield 'mastodon post without an author, by its status address' => [
            self::entry($mastodon, 'https://mastodon.example/users/alice/statuses/120'),
            'alice@mastodon.example',
        ];
        yield 'mastodon post by someone else' => [
            self::entry($mastodon, 'https://mastodon.example/@bob/121', 'https://mastodon.example/@bob'),
            null,
        ];
        yield 'an article is nobody\'s post' => [self::entry($plain, 'https://example.com/a'), null];
    }

    private static function feed(string $url, ?SocialNetwork $network): Feed
    {
        $feed = new Feed($url);
        $feed->classifyAs($network);

        return $feed;
    }

    private static function entry(Feed $feed, string $guid, ?string $authorUrl = null): Entry
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, $guid, $guid, 'Post', $now, $now);
        $entry->setAuthor(new EntryAuthor('Alice', $authorUrl));

        return $entry;
    }
}
```

- [ ] **Step 2: Run it, see it fail** — `php bin/phpunit tests/Service/Social/Support/PostAuthorTest.php` (from `backend/`). Expected: error, class `PostAuthor` not found.

- [ ] **Step 3: Write `PostAuthor`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;

/** Who wrote an entry's post, as an account identity: the DID of its AT URI, or user@host of its Mastodon author. */
final class PostAuthor
{
    private const string MASTODON_PROFILE = '~^https://([a-z0-9.-]+)/(?:@|users/)([^/?#@]+)/?$~iD';

    /** @param list<SocialAccount> $accounts */
    public static function accountAmong(Entry $entry, array $accounts): ?SocialAccount
    {
        $author = self::identityOf($entry);
        foreach ($accounts as $account) {
            if (SocialIdentity::isAccount($author, $account)) {
                return $account;
            }
        }

        return null;
    }

    private static function identityOf(Entry $entry): ?string
    {
        $post = SocialPostAddress::of($entry);
        if (null === $post) {
            return null;
        }

        return match ($post->network) {
            SocialNetwork::Bluesky => AtPostUri::authorityOf($post->address),
            SocialNetwork::Mastodon => self::mastodonIdentityOf(
                $entry->getAuthorUrl() ?? MastodonStatusAddress::ofOrNull($post->address)?->authorPrefix,
            ),
        };
    }

    private static function mastodonIdentityOf(?string $profileUrl): ?string
    {
        if (null === $profileUrl || 1 !== preg_match(self::MASTODON_PROFILE, $profileUrl, $match)) {
            return null;
        }

        return mb_strtolower($match[2] . '@' . $match[1]);
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run `PostAuthorTest`** — expected PASS.

- [ ] **Step 5: Replace `networksOf` with `findAllForUserId`.** In `SocialAccountRepositoryTest`, replace `testNetworksOfNamesEachNetworkOfTheUserOnce` and `testNetworksOfListsEveryNetworkInOrder` with:

```php
    public function testFindAllForUserIdListsOnlyThatUsersAccountsByNetworkThenAge(): void
    {
        $this->persistAccount($this->owner, SocialNetwork::Mastodon, 'alice@mastodon.example');
        $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:one');
        $this->persistAccount($this->stranger, SocialNetwork::Bluesky, 'did:plc:stranger');
        $this->entityManager->flush();

        self::assertSame(
            ['did:plc:one', 'alice@mastodon.example'],
            array_map(
                static fn (SocialAccount $account): string => $account->getIdentity(),
                $this->repository()->findAllForUserId($this->owner->requireId()),
            ),
        );
        self::assertSame([], $this->repository()->findAllForUserId($this->stranger->requireId() + 1000));
    }
```

In `SocialAccountRepository`, delete `networksOf()` and add (its only caller is the enricher):

```php
    /** @return list<SocialAccount> */
    public function findAllForUserId(int $userId): array
    {
        // @phpstan-ignore arrayValues.list (deliberate: findBy() promises a list only in its @phpstan-return)
        return array_values($this->findBy(['user' => $userId], ['network' => 'ASC', 'id' => 'ASC']));
    }
```

- [ ] **Step 6: Write the failing enricher and JSON tests.**
  - `EntryListRowEnricherTest`: add (feed URL is new, so it does not clash with `mastodonFeed()`):

```php
    public function testMyOwnPostSaysSoAndSomeoneElsesDoesNot(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user));
        $ownFeed = new Feed('https://mastodon.example/@alice.rss');
        $this->entityManager->persist($ownFeed);
        $own = $this->postOn($ownFeed, 'https://mastodon.example/@alice/1');
        $foreign = $this->postOn($this->mastodonFeed(), self::POST . '7');

        $rows = $this->enrich([$this->row($own), $this->row($foreign)]);

        self::assertEquals(
            new EntryListRowSocial(SocialNetwork::Mastodon, false, true, isOwn: true),
            $rows[0]->enrichment->social,
        );
        self::assertEquals(
            new EntryListRowSocial(SocialNetwork::Mastodon, false, true),
            $rows[1]->enrichment->social,
        );
    }
```
  - `EntryJsonTest`: add `'isOwn' => false` to the two existing expected `social` arrays (lines ~345 and ~358) and add:

```php
    public function testMyOwnPostSaysItIsMine(): void
    {
        $row = $this->row(
            $this->entryTitled('Post'),
            new EntryListRowEnrichment(social: new EntryListRowSocial(SocialNetwork::Bluesky, false, true, true)),
        );

        self::assertSame(
            ['network' => 'bluesky', 'isLiked' => false, 'canLike' => true, 'isOwn' => true],
            JsonMappers::entryJson()->listRow($row)['social'],
        );
    }
```
  - `EntryLikeControllerTest` (lines ~66, ~107, ~154) and `EntrySocialViewerControllerTest` (~67): add `'isOwn' => false` to each expected `social` array, and `isOwn: bool` to both `socialOf()` array-shape docblocks (`@return` and `@var`).

- [ ] **Step 7: Run them, see them fail** — `php bin/phpunit tests/Repository/EntryListRowEnricherTest.php tests/Http/EntryJsonTest.php tests/Repository/SocialAccountRepositoryTest.php`. Expected: unknown named parameter `isOwn` / missing key `isOwn`.

- [ ] **Step 8: Implement.**
  - `EntryListRowSocial`: add `public bool $isOwn = false,` as the fourth promoted property; docblock becomes `/** A row's post on its network: whether the reader can like it there, did, and wrote it. */`.
  - `EntryListRowEnricher::socialStatesOf()`:

```php
        $accounts = $this->socialAccounts->findAllForUserId($userId);
        $accountNetworks = array_map(static fn (SocialAccount $account): SocialNetwork => $account->getNetwork(), $accounts);
        // …$likeable and $likedIds unchanged…
        foreach ($postNetworks as $entryId => $network) {
            $states[$entryId] = new EntryListRowSocial(
                $network,
                isLiked: isset($likedIds[$entryId]),
                canLike: isset($likeable[$entryId]),
                isOwn: null !== PostAuthor::accountAmong($entries[$entryId], $accounts),
            );
        }
```
  (wrap the `array_map` line at 120 cols; import `App\Entity\SocialAccount` and `App\Service\Social\Support\PostAuthor`). Update the class docblock only if it now lies.
  - `EntryJson`: the `social` shape in the `EntryRow` phpstan-type and in `socialOf()`'s `@return` gain `isOwn: bool`; `socialOf()` returns `[..., 'canLike' => $social->canLike, 'isOwn' => $social->isOwn]`.
  - If `tests/Service/SavedPosts/SavedBlueskyPostInteractionTest.php:113` now fails, it needs nothing but the default `isOwn: false` — check, do not guess.

- [ ] **Step 9: Run** `php bin/phpunit tests/Service/Social/Support/PostAuthorTest.php tests/Repository tests/Http/EntryJsonTest.php tests/Controller/Api/EntryLikeControllerTest.php tests/Controller/Api/EntrySocialViewerControllerTest.php tests/Service/SavedPosts` — expected PASS. `testAPageWithAPostLooksUpTheSocialAccountsOnce` and the query-count test must still pass unchanged (one `social_account` query replaced by one).

- [ ] **Step 10: Gates on touched files** — `composer cs`, `composer stan`, `composer md` (touched files clean).

- [ ] **Step 11: Commit**

```bash
git add backend/src/Service/Social/Support/PostAuthor.php backend/src/Repository backend/src/Http/EntryJson.php backend/tests
git commit -m "feat(#1972): a list row and the article say whether the post is mine"
```

---

### Task 2: A post delete reports a post the network no longer had

**Files:**
- Create: `backend/src/Service/Social/Model/PostDeletion.php`
- Modify: `backend/src/Service/Social/BlueskyRecords.php`, `PostWriter/PostWriterInterface.php`, `PostWriter/BlueskyPostWriter.php`, `PostWriter/MastodonPostWriter.php`, `PostWriting.php`, `SocialResponses.php`; `backend/tests/Support/FakeMastodonNetwork.php`
- Test: `backend/tests/Service/Social/PostWriter/BlueskyPostWriterTest.php`, `MastodonPostWriterTest.php`; `tests/Service/Social/SocialResponsesTest.php` must stay green unchanged.

**Interfaces:**
- Produces: `enum App\Service\Social\Model\PostDeletion: string { case Deleted = 'deleted'; case AlreadyGone = 'alreadyGone'; }`; `BlueskyRecords::delete(SocialAccount $account, string $collection, string $recordKey): PostDeletion`; `PostWriterInterface::delete(SocialAccount $account, string $postId): PostDeletion` (`$postId` = the Bluesky AT URI, or the status id on the account's own server — the same meaning `SocialPostModel::$postId` has); `PostWriting::delete(SocialAccount $account, string $postId): PostDeletion`; `FakeMastodonNetwork::knowsGone(string $postId): void`.

- [ ] **Step 1: Write the failing writer tests.**
  - `BlueskyPostWriterTest`: replace `testDeletingAReplyDeletesItsPostRecord` and add the gone cases:

```php
    public function testDeletingAPostDeletesItsRecordInTheAccountsRepo(): void
    {
        $http = $this->deleting(new MockResponse('{"commit":{"cid":"bafy-commit","rev":"3rev"}}'));

        $deletion = $this->writer($http)->delete(SocialApiWiring::blueskyAccount(), self::REPLY_URI);

        self::assertSame(PostDeletion::Deleted, $deletion);
        self::assertSame(
            ['repo' => 'did:plc:alice', 'collection' => 'app.bsky.feed.post', 'rkey' => '3rep'],
            json_decode($http->requests[1]['body'], true),
        );
    }

    #[DataProvider('goneAnswers')]
    public function testARecordBlueskyNoLongerHasIsReportedGone(MockResponse $answer): void
    {
        $deletion = $this->writer($this->deleting($answer))->delete(SocialApiWiring::blueskyAccount(), self::REPLY_URI);

        self::assertSame(PostDeletion::AlreadyGone, $deletion);
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function goneAnswers(): iterable
    {
        yield 'an answer without a commit' => [new MockResponse('{}')];
        yield 'not found' => [new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND])];
    }

    private function deleting(MockResponse $answer): RoutedHttpClient
    {
        return new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => $answer,
        ]);
    }
```
  and change `testAnAddressThatNamesNoPostRecordCannotBeDeleted` to `->delete(SocialApiWiring::blueskyAccount(), 'at://did:plc:alice/x/y')`. Imports: `PostDeletion`, `PHPUnit\Framework\Attributes\DataProvider`, `Symfony\Component\HttpFoundation\Response`.
  - `MastodonPostWriterTest`: replace `testDeletingAReplyDeletesItsStatusAndAGoneOneCountsAsDeleted` with:

```php
    public function testDeletingAStatusReportsWhetherMastodonStillHadIt(): void
    {
        $http = new RoutedHttpClient([
            'DELETE https://mastodon.example/api/v1/statuses/120' => new MockResponse('{}'),
            'DELETE https://mastodon.example/api/v1/statuses/121' => new MockResponse(
                '{}',
                ['http_code' => Response::HTTP_NOT_FOUND],
            ),
        ]);

        self::assertSame(PostDeletion::Deleted, $this->writer($http)->delete(SocialAccounts::mastodon(), '120'));
        self::assertSame(PostDeletion::AlreadyGone, $this->writer($http)->delete(SocialAccounts::mastodon(), '121'));
    }
```

- [ ] **Step 2: Run, see them fail** — `php bin/phpunit tests/Service/Social/PostWriter`. Expected: `PostDeletion` not found / wrong argument types.

- [ ] **Step 3: Implement.**
  - `Model/PostDeletion.php` as in Interfaces, docblock `/** What deleting a post found on its network: the post, or that it was gone already. */`.
  - `BlueskyRecords::delete` (likers and reposters keep calling it and ignore the result):

```php
    /** Bluesky answers a missing record without a commit, or with a 404. */
    public function delete(SocialAccount $account, string $collection, string $recordKey): PostDeletion
    {
        try {
            $answer = $this->sessions->actAs($account, self::DELETE_RECORD, [
                'repo' => $account->getIdentity(),
                'collection' => $collection,
                'rkey' => $recordKey,
            ]);
        } catch (SocialPostNotFoundException) {
            return PostDeletion::AlreadyGone;
        }

        return JsonNodeModel::of($answer->json())->has('commit') ? PostDeletion::Deleted : PostDeletion::AlreadyGone;
    }
```
  - `PostWriterInterface::delete(SocialAccount $account, string $postId): PostDeletion`, docblock `@return PostDeletion AlreadyGone when the network no longer has the post` plus the existing `@throws`.
  - `BlueskyPostWriter::delete`:

```php
    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        $recordKey = BlueskyRecordUri::recordKeyOf(BlueskyRecordUri::postOrNull($postId) ?? '')
            ?? throw new SocialPostRefusedException('The address names no Bluesky post.');

        return $this->records->delete($account, BlueskyRecords::POST_COLLECTION, $recordKey);
    }
```
  - `MastodonPostWriter::delete`:

```php
    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        $url = $account->getServiceUrl() . self::STATUSES . '/' . rawurlencode($postId);
        try {
            $this->mastodon->writeAs($account, ApiRequestModel::delete($url), self::STATUSES_SCOPE);
        } catch (SocialPostNotFoundException) {
            return PostDeletion::AlreadyGone;
        }

        return PostDeletion::Deleted;
    }
```
  - `PostWriting::delete`:

```php
    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        $writer = $this->writerOf($account);

        return $this->guard->actAs($account, static fn (): PostDeletion => $writer->delete($account, $postId));
    }
```
  - `SocialResponses::delete`: `$this->writing->delete($ownReply->account, $ownReply->postId);` (a reply already gone stays a silent success there, as today).
  - `FakeMastodonNetwork`: add `/** @var array<string, true> */ private array $gonePostIds = [];`, `public function knowsGone(string $postId): void { $this->gonePostIds[$postId] = true; }` and

```php
    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        $this->record('delete ' . $postId);

        return isset($this->gonePostIds[$postId]) ? PostDeletion::AlreadyGone : PostDeletion::Deleted;
    }
```

- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Social` — expected PASS (`SocialResponsesTest` still records `delete 110`; the liker/reposter tests ignore the new return value).

- [ ] **Step 5: Gates** — `composer cs`, `composer stan`, `composer md`.

- [ ] **Step 6: Commit** `refactor(#1972): deleting a post reports a post the network no longer had`.

---

### Task 3: A deleted post leaves every feed and stays out for seven days

**Files:**
- Create: `backend/src/Repository/StoredPostCopy.php`, `backend/src/Repository/PostRemovalRepository.php`, `backend/src/Service/Social/DeletedPostTombstones.php`, `backend/src/Service/Social/LocalPostRemoval.php`
- Modify: `backend/config/packages/cache.yaml`, `backend/src/Service/Refresh/IncomingEntries.php`, `backend/src/Service/Refresh/FeedOutcomePersister.php`, `backend/src/Service/Refresh/Model/IncomingFeedModel.php`, `backend/tests/Support/FeedOutcomePersisters.php`
- Test: create `backend/tests/Service/Social/DeletedPostTombstonesTest.php`, `backend/tests/Service/Social/LocalPostRemovalTest.php`; modify `backend/tests/Service/Refresh/FeedOutcomePersisterTest.php`

**Interfaces:**
- Produces: `StoredPostCopy(int $entryId, int $feedId, string $guidHash)` (public readonly props); `PostRemovalRepository::copiesOf(Entry $entry): list<StoredPostCopy>`, `::deleteEntries(list<int> $entryIds): void`; `DeletedPostTombstones::bury(int $feedId, string $guidHash): void`, `::buriedIn(int $feedId): list<string>`; `LocalPostRemoval::remove(Entry $entry): void`; `IncomingEntries::withoutDeletedPosts(Feed $feed, IncomingFeedModel $incoming): IncomingFeedModel`; `IncomingFeedModel::withParsed(ParsedFeedModel $parsed): self`; test helper `FeedOutcomePersisters::tombstones(): DeletedPostTombstones`.
- Consumes: `RowIds::delete(class-string, list<int>)`, `EntryIndexer::forget(list<int>)`, `SocialCountsCache::forgetEntry(Entry)`, `EntryGuid::hashOf(string)`.

- [ ] **Step 1: Cache pool.** In `config/packages/cache.yaml` add under `pools` (after `embed.details.cache`):

```yaml
            # Posts the reader deleted from their network (#1972), per feed, so a lagging profile feed or AppView
            # cannot store one again. DeletedPostTombstones keeps each for seven days; this lifetime is the backstop.
            social.deleted_posts.cache:
                adapter: cache.adapter.filesystem
                default_lifetime: 604800
```
and under `when@test: … pools:`:

```yaml
                # The suite's database starts empty every run while a filesystem pool would not: a stale tombstone
                # would hide a recycled feed id's entries.
                social.deleted_posts.cache:
                    adapter: cache.adapter.array
```

- [ ] **Step 2: Write the failing `DeletedPostTombstonesTest`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\DeletedPostTombstones;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class DeletedPostTombstonesTest extends TestCase
{
    public function testABuriedPostStaysBuriedInItsFeedForSevenDays(): void
    {
        $clock = new MockClock('2026-11-01 12:00:00');
        $tombstones = new DeletedPostTombstones(new ArrayAdapter(), $clock);

        $tombstones->bury(3, 'hash-a');
        $clock->modify('+6 days');
        $tombstones->bury(3, 'hash-b');

        self::assertSame(['hash-a', 'hash-b'], $tombstones->buriedIn(3));
        self::assertSame([], $tombstones->buriedIn(4));
        $clock->modify('+1 day');
        self::assertSame(['hash-b'], $tombstones->buriedIn(3));
        $clock->modify('+6 days');
        self::assertSame([], $tombstones->buriedIn(3));
    }
}
```
Run `php bin/phpunit tests/Service/Social/DeletedPostTombstonesTest.php` — expected FAIL (class missing).

- [ ] **Step 3: Write `DeletedPostTombstones`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Posts deleted from the reader, per feed, kept from being stored again while a lagging source still serves them. */
final readonly class DeletedPostTombstones
{
    private const int KEPT_SECONDS = 7 * 24 * 3600;

    public function __construct(
        #[Autowire(service: 'social.deleted_posts.cache')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public function bury(int $feedId, string $guidHash): void
    {
        $item = $this->cache->getItem(self::keyOf($feedId));
        $item->set([...$this->standing($item), $guidHash => $this->now() + self::KEPT_SECONDS]);
        $this->cache->save($item);
    }

    /** @return list<string> the guid hashes of the feed's posts deleted within the last seven days */
    public function buriedIn(int $feedId): array
    {
        return array_keys($this->standing($this->cache->getItem(self::keyOf($feedId))));
    }

    /** @return array<string, int> each guid hash still buried, with the time it may be stored again */
    private function standing(CacheItemInterface $item): array
    {
        $buried = $item->get();
        if (!\is_array($buried)) {
            return [];
        }
        $now = $this->now();
        $standing = [];
        foreach ($buried as $guidHash => $until) {
            if (\is_string($guidHash) && \is_int($until) && $until > $now) {
                $standing[$guidHash] = $until;
            }
        }

        return $standing;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private static function keyOf(int $feedId): string
    {
        return 'deleted_posts.' . $feedId;
    }
}
```
Run the test — expected PASS.

- [ ] **Step 4: Write the failing `LocalPostRemovalTest`** (`DbTestCase`; the repository is built by hand because no production service uses it until Task 4, so the container has removed it):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\EntryGuid;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\PostRemovalRepository;
use App\Repository\RowIds;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use App\Service\Social\DeletedPostTombstones;
use App\Service\Social\LocalPostRemoval;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\SocialCountsCache;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\EntryIndexers;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class LocalPostRemovalTest extends DbTestCase
{
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.example/@alice/120';

    private DeletedPostTombstones $tombstones;
    private RecordingSearchIndexWriter $index;
    private SocialCountsCache $countsCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tombstones = new DeletedPostTombstones(new ArrayAdapter(), new MockClock('2026-11-01 12:00:00'));
        $this->index = new RecordingSearchIndexWriter();
        $this->countsCache = new SocialCountsCache(new ArrayAdapter());
    }

    public function testEveryCopyButASavedOneGoesAndEachIsBuriedInItsFeed(): void
    {
        $user = $this->user('local-removal@example.test');
        $profile = $this->feed('https://mastodon.example/@alice.rss');
        $home = $this->feed('https://other.example/@reader.rss');
        $saved = $this->feed(SavedFeedUrl::of($user->requireId(), SocialNetwork::Mastodon), SourceFormat::SAVED);
        $post = $this->copy($profile, self::STATUS_URL, 'hash-120');
        $copy = $this->copy($home, 'https://mastodon.example/users/alice/statuses/120', 'hash-120');
        $savedCopy = $this->copy($saved, 'saved:120', 'hash-120');
        $other = $this->copy($profile, 'https://mastodon.example/@alice/121', 'hash-121');
        $this->entityManager->persist(new EntryState($user, $post));
        $this->entityManager->flush();
        $ids = [$post->requireId(), $copy->requireId(), $savedCopy->requireId(), $other->requireId()];
        $this->countsCache->remember(
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
            new SocialCountsModel(1, 0, 0, null),
        );

        $this->removal()->remove($post);

        $this->entityManager->clear();
        self::assertSame(
            [false, false, true, true],
            array_map(fn (int $id): bool => null !== $this->entityManager->find(Entry::class, $id), $ids),
        );
        self::assertSame([], $this->entityManager->getRepository(EntryState::class)->findAll());
        self::assertSame([EntryGuid::hashOf(self::STATUS_URL)], $this->tombstones->buriedIn($profile->requireId()));
        self::assertCount(1, $this->tombstones->buriedIn($home->requireId()));
        self::assertSame([], $this->tombstones->buriedIn($saved->requireId()));
        self::assertSame([[$ids[0], $ids[1]]], $this->index->forgets);
        self::assertNull(
            $this->countsCache->find(new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL)),
        );
    }

    public function testAPostWithoutAUrlHashTakesOnlyItself(): void
    {
        $feed = $this->feed('https://mastodon.example/@alice.rss');
        $post = $this->copy($feed, self::STATUS_URL, null);
        $twin = $this->copy($feed, 'https://mastodon.example/@alice/122', null);
        $twinId = $twin->requireId();

        $this->removal()->remove($post);

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Entry::class, $twinId));
    }

    private function removal(): LocalPostRemoval
    {
        return new LocalPostRemoval(
            new PostRemovalRepository($this->entityManager, new RowIds($this->entityManager)),
            $this->tombstones,
            EntryIndexers::with($this->index),
            $this->countsCache,
        );
    }

    private function feed(string $url, string $sourceFormat = SourceFormat::XML): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function copy(Feed $feed, string $guid, ?string $urlHash): Entry
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, $guid, $guid, 'Post', $now, $now, $urlHash);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
```
Run `php bin/phpunit tests/Service/Social/LocalPostRemovalTest.php` — expected FAIL (classes missing). If `EntryState`'s `findAll()` shows rows of other fixtures, assert on `findBy(['user' => $user])` instead.

- [ ] **Step 5: Implement the repository and the removal.**
  - `src/Repository/StoredPostCopy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

/** One stored copy of a post: which entry, in which feed, under which guid. */
final readonly class StoredPostCopy
{
    public function __construct(
        public int $entryId,
        public int $feedId,
        public string $guidHash,
    ) {
    }
}
```
  - `src/Repository/PostRemovalRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Enum\SourceFormat;
use Doctrine\ORM\EntityManagerInterface;

/** Every stored copy of a post its network no longer has, and their deletion. */
final readonly class PostRemovalRepository
{
    public function __construct(private EntityManagerInterface $entityManager, private RowIds $rowIds)
    {
    }

    /** @return list<StoredPostCopy> the entry, and each copy of its post outside a saved feed, which is an archive */
    public function copiesOf(Entry $entry): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e.id AS entryId', 'IDENTITY(e.feed) AS feedId', 'e.guid.hash AS guidHash')
            ->from(Entry::class, 'e')
            ->join('e.feed', 'f')
            ->andWhere('e = :entry')
            ->setParameter('entry', $entry);
        $urlHash = $entry->getUrlHash();
        if (null !== $urlHash) {
            $qb->orWhere('e.location.urlHash = :urlHash AND f.sourceFormat <> :savedFormat')
                ->setParameter('urlHash', $urlHash)
                ->setParameter('savedFormat', SourceFormat::SAVED);
        }
        /** @var list<array{entryId: int|string, feedId: int|string, guidHash: string}> $rows */
        $rows = $qb->orderBy('e.id', 'ASC')->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): StoredPostCopy
                => new StoredPostCopy((int) $row['entryId'], (int) $row['feedId'], $row['guidHash']),
            $rows,
        );
    }

    /** @param list<int> $entryIds */
    public function deleteEntries(array $entryIds): void
    {
        $this->rowIds->delete(Entry::class, $entryIds);
    }
}
```
  - `src/Service/Social/LocalPostRemoval.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Repository\PostRemovalRepository;
use App\Repository\StoredPostCopy;
use App\Service\Search\EntryIndexer;

/** Removes a post its network no longer has: every stored copy, each kept from being stored again for a while. */
final readonly class LocalPostRemoval
{
    public function __construct(
        private PostRemovalRepository $removals,
        private DeletedPostTombstones $tombstones,
        private EntryIndexer $indexer,
        private SocialCountsCache $countsCache,
    ) {
    }

    public function remove(Entry $entry): void
    {
        $copies = $this->removals->copiesOf($entry);
        foreach ($copies as $copy) {
            $this->tombstones->bury($copy->feedId, $copy->guidHash);
        }
        $this->countsCache->forgetEntry($entry);
        $entryIds = array_map(static fn (StoredPostCopy $copy): int => $copy->entryId, $copies);
        $this->removals->deleteEntries($entryIds);
        $this->indexer->forget($entryIds);
    }
}
```
Run `LocalPostRemovalTest` — expected PASS. (A bulk DQL delete leaves the `Entry` objects managed: tests must `clear()` before `find()`.)

- [ ] **Step 6: Write the failing ingest-skip test.** In `tests/Support/FeedOutcomePersisters.php`: add `private DeletedPostTombstones $tombstones;`, set `$this->tombstones = new DeletedPostTombstones(new ArrayAdapter(), $clock);` in the constructor, add

```php
    public function tombstones(): DeletedPostTombstones
    {
        return $this->tombstones;
    }
```
and pass `$this->tombstones` as the third `IncomingEntries` argument in `enrichingWith()`. In `FeedOutcomePersisterTest` add:

```php
    public function testAPostDeletedFromTheReaderIsNotStoredAgain(): void
    {
        $feed = $this->feed('https://one.example.com/feed');
        $persisters = FeedOutcomePersisters::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->loggingTo($this->logger);
        $persisters->tombstones()->bury($feed->requireId(), EntryGuid::hashOf('one-2'));

        $result = $persisters->idle()->persist(
            $feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::fetched($feed->getUrl(), false, $this->rss(), null, null)),
            $this->clock->now(),
        );

        self::assertSame(1, $result->entriesCreated);
        self::assertNull($this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'one-2']));
    }
```
(Imports: `App\Entity\EntryGuid` in the test; `App\Service\Social\DeletedPostTombstones` and `Symfony\Component\Cache\Adapter\ArrayAdapter` in `FeedOutcomePersisters`.) Run `php bin/phpunit tests/Service/Refresh/FeedOutcomePersisterTest.php` — expected FAIL (`IncomingEntries` takes 2 arguments / entry stored).

- [ ] **Step 7: Implement the skip.**
  - `IncomingFeedModel::withParsed(ParsedFeedModel $parsed): self` → `new self($parsed, $this->knownShares, $this->threads)`.
  - `IncomingEntries`: third constructor parameter `private DeletedPostTombstones $tombstones`, and

```php
    public function withoutDeletedPosts(Feed $feed, IncomingFeedModel $incoming): IncomingFeedModel
    {
        $buried = array_flip($this->tombstones->buriedIn($feed->requireId()));
        if ([] === $buried) {
            return $incoming;
        }

        return $incoming->withParsed($incoming->parsed->withEntries(array_values(array_filter(
            $incoming->parsed->entries,
            static fn (ParsedEntryModel $entry): bool => !isset($buried[EntryGuid::hashOf($entry->guid)]),
        ))));
    }
```
  (Imports: `App\Entity\EntryGuid`, `App\Service\Parser\Model\ParsedEntryModel`, `App\Service\Social\DeletedPostTombstones`.) Update the class docblock to `/** What a refresh does with a page's entries besides storing them: deleted posts out, a timeline's threads, then each share. */` (≤120 cols, wrap if needed).
  - `FeedOutcomePersister::storeIngested()`: first line `$stored = $this->incomingEntries->withoutDeletedPosts($feed, $incoming);`, then use `$stored` for `ingest`, `fillMissingImages` and `settle`. Both the fetched-body path and `storeTimeline` pass through here; a folded reply whose root was filtered finds no root and is skipped by `TimelineSelfThreads::fold` as today.

- [ ] **Step 8: Run** `php bin/phpunit tests/Service/Refresh tests/Service/Social/DeletedPostTombstonesTest.php tests/Service/Social/LocalPostRemovalTest.php` — expected PASS. Then `php bin/phpunit tests/DependencyInjection/EveryApplicationServiceBuildsTest.php` — the new services must build.

- [ ] **Step 9: Gates** — `composer cs`, `composer stan`, `composer md`, `composer tramp`.

- [ ] **Step 10: Commit** `feat(#1972): a deleted post leaves every feed and stays out for seven days`.

---

### Task 4: `OwnPostDeletion` — replies newest first, then the post, then here

**Files:**
- Create: `backend/src/Service/Social/Support/OwnPostIds.php`, `backend/src/Service/Social/OwnPostDeletion.php`
- Modify: `backend/src/Repository/ThreadPostRepository.php`
- Test: create `backend/tests/Service/Social/Support/OwnPostIdsTest.php`, `backend/tests/Service/Social/OwnPostDeletionTest.php`

**Interfaces:**
- Consumes: `PostAuthor::accountAmong` (Task 1), `PostWriting::delete`, `PostDeletion`, `FakeMastodonNetwork::knowsGone` (Task 2), `LocalPostRemoval`, `DeletedPostTombstones`, `PostRemovalRepository` (Task 3).
- Produces: `OwnPostIds::ofAddress(SocialPostAddressModel $post, SocialAccount $account): string`, `OwnPostIds::ofReplyUrl(string $url, SocialAccount $account): string` (both `@throws SocialPostRefusedException`); `ThreadPostRepository::urlsNewestFirst(Entry $entry): list<string>`; `OwnPostDeletion::delete(User $user, Entry $entry): PostDeletion`.

- [ ] **Step 1: Write the failing `OwnPostIdsTest`** (`TestCase`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Support\OwnPostIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OwnPostIdsTest extends TestCase
{
    public function testABlueskyPostIsDeletedByItsAtUri(): void
    {
        $post = new SocialPostAddressModel(SocialNetwork::Bluesky, 'at://did:plc:alice/app.bsky.feed.post/3own');

        self::assertSame(
            'at://did:plc:alice/app.bsky.feed.post/3own',
            OwnPostIds::ofAddress($post, SocialAccounts::bluesky()),
        );
    }

    #[DataProvider('blueskyReplies')]
    public function testABlueskyReplyIsTheAccountsRecordUnderTheRecordKeyOfItsUrl(string $url): void
    {
        self::assertSame(
            'at://did:plc:alice/app.bsky.feed.post/3rep',
            OwnPostIds::ofReplyUrl($url, SocialAccounts::bluesky()),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function blueskyReplies(): iterable
    {
        yield 'by DID' => ['https://bsky.app/profile/did:plc:alice/post/3rep'];
        yield 'by handle' => ['https://bsky.app/profile/alice.bsky.social/post/3rep'];
    }

    #[DataProvider('mastodonStatuses')]
    public function testAMastodonStatusIsDeletedByItsIdOnTheAccountsServer(string $address): void
    {
        $post = new SocialPostAddressModel(SocialNetwork::Mastodon, $address);

        self::assertSame('120', OwnPostIds::ofAddress($post, SocialAccounts::mastodon()));
        self::assertSame('120', OwnPostIds::ofReplyUrl($address, SocialAccounts::mastodon()));
    }

    /** @return iterable<string, array{string}> */
    public static function mastodonStatuses(): iterable
    {
        yield 'web address' => ['https://mastodon.example/@alice/120'];
        yield 'activity address' => ['https://Mastodon.Example/users/alice/statuses/120'];
    }

    #[DataProvider('unaddressable')]
    public function testAPostThatCannotBeNamedOnTheAccountsServerIsRefused(string $url, bool $onBluesky): void
    {
        $this->expectException(SocialPostRefusedException::class);

        OwnPostIds::ofReplyUrl($url, $onBluesky ? SocialAccounts::bluesky() : SocialAccounts::mastodon());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function unaddressable(): iterable
    {
        yield 'a Mastodon status on another server' => ['https://elsewhere.example/@alice/120', false];
        yield 'no Mastodon status' => ['https://mastodon.example/@alice', false];
        yield 'no Bluesky post' => ['https://bsky.app/profile/did:plc:alice', true];
    }
}
```
Run `php bin/phpunit tests/Service/Social/Support/OwnPostIdsTest.php` — expected FAIL (class missing).

- [ ] **Step 2: Write `OwnPostIds`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialPostAddressModel;

/** What a delete names one of the account's own posts by: a Bluesky AT URI, or the status id on its own server. */
final class OwnPostIds
{
    /** @throws SocialPostRefusedException */
    public static function ofAddress(SocialPostAddressModel $post, SocialAccount $account): string
    {
        return match ($post->network) {
            SocialNetwork::Bluesky => $post->address,
            SocialNetwork::Mastodon => self::mastodonStatusId($post->address, $account),
        };
    }

    /** @throws SocialPostRefusedException */
    public static function ofReplyUrl(string $url, SocialAccount $account): string
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => self::blueskyPost($url, $account),
            SocialNetwork::Mastodon => self::mastodonStatusId($url, $account),
        };
    }

    private static function blueskyPost(string $url, SocialAccount $account): string
    {
        $post = AtPostUri::ofWebUrl($url)
            ?? throw new SocialPostRefusedException('A reply in the thread names no Bluesky post.');

        return AtPostUri::withAuthority($post, $account->getIdentity());
    }

    private static function mastodonStatusId(string $address, SocialAccount $account): string
    {
        $status = MastodonStatusAddress::ofOrNull($address);
        $ownHost = parse_url($account->getServiceUrl(), \PHP_URL_HOST);
        if (null === $status || !\is_string($ownHost) || 0 !== strcasecmp($status->host, $ownHost)) {
            throw new SocialPostRefusedException('The post is not on this account\'s own Mastodon server.');
        }

        return $status->id;
    }

    private function __construct()
    {
    }
}
```
Run the test — expected PASS.

- [ ] **Step 3: `ThreadPostRepository::urlsNewestFirst`**

```php
    /** @return list<string> the addresses of the entry's thread posts, the last one first */
    public function urlsNewestFirst(Entry $entry): array
    {
        /** @var list<string> $urls */
        $urls = $this->createQueryBuilder('post')
            ->select('post.url')
            ->andWhere('post.entry = :entry')
            ->andWhere('post.url IS NOT NULL')
            ->setParameter('entry', $entry)
            ->orderBy('post.position', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();

        return $urls;
    }
```
(covered by `OwnPostDeletionTest`'s order assertion.)

- [ ] **Step 4: Write the failing `OwnPostDeletionTest`** (`DbTestCase`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\EntryGuid;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\ThreadPost;
use App\Entity\ThreadPostContent;
use App\Entity\User;
use App\Repository\PostRemovalRepository;
use App\Repository\RowIds;
use App\Repository\SocialAccountRepository;
use App\Repository\ThreadPostRepository;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\DeletedPostTombstones;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\LocalPostRemoval;
use App\Service\Social\Model\PostDeletion;
use App\Service\Social\OwnPostDeletion;
use App\Service\Social\PostWriting;
use App\Service\Social\SocialCountsCache;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\EntryIndexers;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class OwnPostDeletionTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.example/@alice/120';

    private FakeMastodonNetwork $mastodon;
    private DeletedPostTombstones $tombstones;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->tombstones = new DeletedPostTombstones(new ArrayAdapter(), new MockClock('2026-11-01 12:00:00'));
        $this->owner = $this->user('own-post@example.test');
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    public function testTheRepliesGoNewestFirstThenThePostThenItsEntryHere(): void
    {
        $post = $this->post('https://mastodon.example/@alice.rss', self::STATUS_URL);
        $this->reply($post, 1, 'https://mastodon.example/@alice/121');
        $this->reply($post, 2, 'https://mastodon.example/@alice/122');
        $postId = $post->requireId();
        $feedId = $post->getFeed()->requireId();

        $deletion = $this->deletion()->delete($this->owner, $post);

        self::assertSame(PostDeletion::Deleted, $deletion);
        self::assertSame(['delete 122', 'delete 121', 'delete 120'], $this->mastodon->calls);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Entry::class, $postId));
        self::assertSame([EntryGuid::hashOf(self::STATUS_URL)], $this->tombstones->buriedIn($feedId));
    }

    public function testAPostTheNetworkNoLongerHasIsStillRemovedHereAndSaysSo(): void
    {
        $post = $this->post('https://mastodon.example/@alice.rss', self::STATUS_URL);
        $postId = $post->requireId();
        $this->mastodon->knowsGone('120');

        self::assertSame(PostDeletion::AlreadyGone, $this->deletion()->delete($this->owner, $post));
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Entry::class, $postId));
    }

    public function testAFailedReplyStopsBeforeThePostAndKeepsTheEntry(): void
    {
        $post = $this->post('https://mastodon.example/@alice.rss', self::STATUS_URL);
        $this->reply($post, 1, 'https://mastodon.example/@alice/121');
        $postId = $post->requireId();
        $this->mastodon->failWith(new SocialNetworkUnreachableException('The instance is down.'));

        $this->assertRefused(
            fn () => $this->deletion()->delete($this->owner, $post),
            SocialNetworkUnreachableException::class,
            'A failed reply delete must stop the whole delete.',
        );

        self::assertSame(['delete 121'], $this->mastodon->calls);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Entry::class, $postId));
    }

    public function testSomeoneElsesPostIsRefusedBeforeAnyCall(): void
    {
        $post = $this->post('https://mastodon.example/@bob.rss', 'https://mastodon.example/@bob/7');

        $this->assertRefused(
            fn () => $this->deletion()->delete($this->owner, $post),
            SocialPostRefusedException::class,
            'Only a post a connected account wrote can be deleted.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    public function testAReplyOffTheAccountsServerRefusesTheWholeDeleteBeforeAnyCall(): void
    {
        $post = $this->post('https://mastodon.example/@alice.rss', self::STATUS_URL);
        $this->reply($post, 1, 'https://elsewhere.example/@alice/9');

        $this->assertRefused(
            fn () => $this->deletion()->delete($this->owner, $post),
            SocialPostRefusedException::class,
            'A thread whose reply cannot be named must not be half-deleted.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    private function deletion(): OwnPostDeletion
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        /** @var ThreadPostRepository $threadPosts */
        $threadPosts = $this->entityManager->getRepository(ThreadPost::class);
        $mastodon = $this->mastodon;

        return new OwnPostDeletion(
            $accounts,
            $threadPosts,
            new PostWriting(
                new ActingAccountGuard($this->entityManager),
                new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]),
            ),
            new LocalPostRemoval(
                new PostRemovalRepository($this->entityManager, new RowIds($this->entityManager)),
                $this->tombstones,
                EntryIndexers::with(new RecordingSearchIndexWriter()),
                new SocialCountsCache(new ArrayAdapter()),
            ),
        );
    }

    private function post(string $feedUrl, string $statusUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, $statusUrl, $statusUrl, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function reply(Entry $post, int $position, string $url): void
    {
        $this->entityManager->persist(new ThreadPost($post, $position, new ThreadPostContent($url, null, '<p>…</p>', [])));
        $this->entityManager->flush();
    }
}
```
(The feed is classified Mastodon by `FeedNetworkListener` on flush; `@bob.rss` is a second feed URL so the two do not clash.) Run `php bin/phpunit tests/Service/Social/OwnPostDeletionTest.php` — expected FAIL (class missing).

- [ ] **Step 5: Write `OwnPostDeletion`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Repository\ThreadPostRepository;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\PostDeletion;
use App\Service\Social\Support\OwnPostIds;
use App\Service\Social\Support\PostAuthor;
use App\Service\Social\Support\SocialPostAddress;

/** Deletes one of the user's own posts on its network, its folded replies newest first, then every copy here. */
final readonly class OwnPostDeletion
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private ThreadPostRepository $threadPosts,
        private PostWriting $writing,
        private LocalPostRemoval $localRemoval,
    ) {
    }

    /**
     * Every address is named before the first delete, so a thread is never left half-deleted for want of one.
     *
     * @throws NotASocialPostException
     * @throws SocialPostRefusedException when no account of the user's wrote the post, or a post of it has no address
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function delete(User $user, Entry $entry): PostDeletion
    {
        $post = SocialPostAddress::ofOrRefuse($entry);
        $author = $this->authorOf($user, $entry, $post->network);
        $postId = OwnPostIds::ofAddress($post, $author);
        foreach ($this->replyIdsOf($entry, $author) as $replyId) {
            $this->writing->delete($author, $replyId);
        }
        $deletion = $this->writing->delete($author, $postId);
        $this->localRemoval->remove($entry);

        return $deletion;
    }

    private function authorOf(User $user, Entry $entry, SocialNetwork $network): SocialAccount
    {
        return PostAuthor::accountAmong($entry, $this->socialAccounts->findAllOnNetwork($user, $network))
            ?? throw new SocialPostRefusedException('No connected account wrote this post.');
    }

    /** @return list<string> */
    private function replyIdsOf(Entry $entry, SocialAccount $author): array
    {
        return array_map(
            static fn (string $url): string => OwnPostIds::ofReplyUrl($url, $author),
            $this->threadPosts->urlsNewestFirst($entry),
        );
    }
}
```
(The one-line docblock above `@throws` earns its place: it is the invariant Step 4's last test pins. Drop it if `/simplify` finds the name says it.)

- [ ] **Step 6: Run** `php bin/phpunit tests/Service/Social tests/Repository` — expected PASS.

- [ ] **Step 7: Gates** — `composer cs`, `composer stan`, `composer md`, `composer tramp` (the account travels `OwnPostDeletion → PostWriting → ActingAccountGuard → writer`, and every hop reads it).

- [ ] **Step 8: Commit** `feat(#1972): delete one of my own posts, its folded replies first`.

---

### Task 5: `DELETE /api/entries/{id}/post`

**Files:**
- Create: `backend/src/Http/PostDeletionJson.php`
- Modify: `backend/src/Controller/Api/EntrySocialPostController.php`
- Test: `backend/tests/Controller/Api/EntrySocialPostControllerTest.php`

**Interfaces:**
- Consumes: `OwnPostDeletion::delete(User, Entry): PostDeletion`.
- Produces: `DELETE /api/entries/{id}/post` (route name `api_entries_delete_post`) → 200 `{"outcome":"deleted"}` | `{"outcome":"alreadyGone"}`; 404 for an entry the user does not subscribe to; 409 `social_post_refused` when not theirs; 429 past the social write budget; network failures map through the existing problem arms (502 unreachable, 503 throttled, 409 reconnect/scope).
- §6 checklist: bearer auth, stateless, no body in, JSON out, problem+json errors, no browser-only input — a native client uses it unchanged.

- [ ] **Step 1: Write the failing controller tests.** In `EntrySocialPostControllerTest` add constants and tests:

```php
    private const string OWN_PROFILE = 'https://mastodon.example/@alice.rss';
    private const string OWN_STATUS = 'https://mastodon.example/@alice/120';
    private const string DELETE_OWN = 'DELETE https://mastodon.example/api/v1/statuses/120';

    public function testMyOwnPostIsDeletedThereAndHere(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::DELETE_OWN => new MockResponse('{}'),
        ]);
        $user = $this->signIn($client, 'own-post-delete@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->ownPostFor($user);

        $client->request('DELETE', sprintf('/api/entries/%d/post', $entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['outcome' => 'deleted'], $this->payload($client));
        self::assertSame([self::DELETE_OWN], $this->routesAsked());
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Entry::class, $entryId));
    }

    public function testTheArticleSaysWhichPostIsMine(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'own-post-article@example.test');
        $this->mastodonAccountOf($user);

        $client->request('GET', sprintf('/api/entries/%d', $this->ownPostFor($user)));
        self::assertTrue($this->socialOf($client)['isOwn']);
        $client->request('GET', sprintf('/api/entries/%d', $this->mastodonPostFor($user)));
        self::assertFalse($this->socialOf($client)['isOwn']);
    }

    public function testAPostAlreadyGoneThereIsRemovedHereAndSaysSo(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::DELETE_OWN => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);
        $user = $this->signIn($client, 'own-post-gone@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->ownPostFor($user);

        $client->request('DELETE', sprintf('/api/entries/%d/post', $entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['outcome' => 'alreadyGone'], $this->payload($client));
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Entry::class, $entryId));
    }

    public function testSomeoneElsesPostIsRefusedAndKept(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'own-post-foreign@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('DELETE', sprintf('/api/entries/%d/post', $entryId));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame([], $this->http->requests);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Entry::class, $entryId));
    }

    public function testAnUnreachableNetworkKeepsThePost(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::DELETE_OWN => new MockResponse('{}', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR]),
        ]);
        $user = $this->signIn($client, 'own-post-down@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->ownPostFor($user);

        $client->request('DELETE', sprintf('/api/entries/%d/post', $entryId));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Entry::class, $entryId));
    }

    private function ownPostFor(User $user): int
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());

        return $fixtures->post($fixtures->subscribe($user, self::OWN_PROFILE), self::OWN_STATUS, '2026-10-12T10:00:00Z')
            ->requireId();
    }

    /** @return array{network: string, isLiked: bool, canLike: bool, isOwn: bool} */
    private function socialOf(KernelBrowser $client): array
    {
        $entry = $this->payload($client)['entry'];
        self::assertIsArray($entry);
        self::assertIsArray($entry['social']);

        /** @var array{network: string, isLiked: bool, canLike: bool, isOwn: bool} $social */
        $social = $entry['social'];

        return $social;
    }
```
and add `yield 'delete post' => ['DELETE', '/api/entries/{id}/post'];` to `writes()`. (A Mastodon 500 reaches `MastodonApiClient::accepted()` and becomes `SocialNetworkUnreachableException`, 502.) Imports: `App\Entity\Entry`, `App\Entity\User`, `App\Tests\Support\NetworkFeedSubscriptions`.

- [ ] **Step 2: Run, see them fail** — `php bin/phpunit tests/Controller/Api/EntrySocialPostControllerTest.php`. Expected: 404/405 for the new route.

- [ ] **Step 3: Implement.**
  - `src/Http/PostDeletionJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Social\Model\PostDeletion;

final class PostDeletionJson
{
    /** @return array{outcome: 'deleted'|'alreadyGone'} */
    public static function of(PostDeletion $deletion): array
    {
        return ['outcome' => $deletion->value];
    }
}
```
  - `EntrySocialPostController`: constructor gains `private OwnPostDeletion $ownPosts,` (after `$responses`), and:

```php
    #[Route('/{id}/post', name: 'api_entries_delete_post', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deletePost(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        return new JsonResponse(PostDeletionJson::of($this->ownPosts->delete($user, $entry)));
    }
```

- [ ] **Step 4: Run** `php bin/phpunit tests/Controller/Api/EntrySocialPostControllerTest.php` — expected PASS. `composer stan` (ThinControllerRule, ControllerMutatesNoEntityRule) must pass.

- [ ] **Step 5: Scan the dev log** for deprecations: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .` (no new errors from the test run are expected there; the test log is `test-*.log`).

- [ ] **Step 6: Commit** `feat(#1972): DELETE /api/entries/{id}/post deletes my own post`.

---

### Task 6: Frontend — "Delete post" in the list and the article

**Files:**
- Modify: `frontend/src/app/reader/entry-social.ts`, `frontend/src/app/reader/social-post-api.ts`, `frontend/src/app/reader/article/composer/post-actions.service.ts`, `frontend/src/app/reader/entry/entry-actions/entry-action-handler.ts`, `entry-actions.component.html`, `entry-actions.component.scss`, `frontend/src/app/reader/shell/entry-state-actions.service.ts`, `frontend/src/app/reader/shell/reader-shell.component.ts`, `frontend/src/app/reader/list/magazine/entry-duplicates.component.ts`, `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/social-post-api.spec.ts`, `article/composer/post-actions.service.spec.ts`, `entry/entry-actions/entry-actions.component.spec.ts`, `shell/entry-state-actions.service.spec.ts`; every fixture building `social: {…}`.

**Interfaces:**
- Consumes: `DELETE /api/entries/{id}/post` → `{ outcome: 'deleted' | 'alreadyGone' }`; `social.isOwn` on every row.
- Produces: `EntrySocialDto.isOwn: boolean`; `PostDeletionDto`; `SocialPostApi.deletePost(entryId: number): Observable<PostDeletionDto>`; `PostActions.deletePost(entry: EntryDto): Observable<void>` (emits once when the post is gone here, after confirm; emits nothing when cancelled or failed); `EntryActionHandler.deletePost(entry: EntryDto): void`; `EntryStateActions.closeEntry(): void`.

- [ ] **Step 1: DTOs and fixtures.** In `entry-social.ts`: add `isOwn: boolean;` to `EntrySocialDto` (doc line: `/** Whether one of the reader's own accounts wrote the post (#1972). */`) and

```ts
/** The answer of `DELETE /api/entries/{id}/post`: whether the network still had the post. */
export interface PostDeletionDto {
  outcome: 'deleted' | 'alreadyGone';
}
```
Then update every fixture (one-line shapes):

```bash
cd frontend && grep -rl "canLike: \(true\|false\) }" src e2e | xargs perl -pi -e 's/canLike: (true|false) \}/canLike: $1, isOwn: false }/g'
```
and by hand the three irregular ones: `src/app/reader/entry-social.spec.ts` (the `social` factory near line 4), `src/app/reader/state/entries.store.spec.ts` (~line 883, multi-line), `src/app/reader/article/social-counts/social-counts.component.spec.ts` (~line 60, `canLike: true, ...social`). `grep -rn "canLike" src e2e | grep -v isOwn` must then list only `entry-social.ts`'s interface line and expressions that read `canLike`.

- [ ] **Step 2: API.** Spec in `social-post-api.spec.ts`, beside the `deleteReply` case:

```ts
  it('deletes my own post', () => {
    let answer: PostDeletionDto | undefined;
    api.deletePost(7).subscribe((deletion) => (answer = deletion));

    const testRequest = ctrl.expectOne('https://api.test/api/entries/7/post');
    expect(testRequest.request.method).toBe('DELETE');
    testRequest.flush({ outcome: 'alreadyGone' });
    expect(answer).toEqual({ outcome: 'alreadyGone' });
  });
```
Implementation in `SocialPostApi`:

```ts
  deletePost(entryId: number): Observable<PostDeletionDto> {
    return this.http.delete<PostDeletionDto>(this.entryPath(entryId, 'post'));
  }
```

- [ ] **Step 3: i18n.** In both `public/i18n/en.json` and `de.json`, under `reader`, right after `deleteReply`:

```json
    "deletePost": {
      "action": "Delete post",
      "title": "Delete this post?",
      "titleWithOneReply": "Delete this post and its reply?",
      "titleWithReplies": "Delete this post and its {{ count }} replies?",
      "message": "It is deleted on {{ network }} as well.",
      "confirm": "Delete",
      "alreadyGone": "Already deleted on {{ network }} — removed here."
    },
```

```json
    "deletePost": {
      "action": "Beitrag löschen",
      "title": "Diesen Beitrag löschen?",
      "titleWithOneReply": "Diesen Beitrag und seine Antwort löschen?",
      "titleWithReplies": "Diesen Beitrag und seine {{ count }} Antworten löschen?",
      "message": "Er wird auch auf {{ network }} gelöscht.",
      "confirm": "Löschen",
      "alreadyGone": "Auf {{ network }} schon gelöscht – hier entfernt."
    },
```

- [ ] **Step 4: `PostActions.deletePost` — failing spec first.** In `post-actions.service.spec.ts`: extend `api` with `deletePost: jest.fn(() => of({ outcome: 'deleted' }))` (type `{ deleteReply: jest.Mock; deletePost: jest.Mock }`), import `anEntry` from `'../../../../testing/entry-testing'` and `aThreadPost` from `'../../../../testing/thread-testing'`, and add:

```ts
  const ownPost = (replies: number): EntryDto =>
    anEntry({
      id: 9,
      social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: true },
      thread: Array.from({ length: replies }, () => aThreadPost()),
    });

  it.each([
    [0, 'Delete this post?'],
    [1, 'Delete this post and its reply?'],
    [3, 'Delete this post and its 3 replies?'],
  ])('asks before deleting a post with %i replies', (replies, title) => {
    let gone = false;
    actions.deletePost(ownPost(replies)).subscribe(() => (gone = true));

    expect(confirm.ask).toHaveBeenCalledWith(
      expect.objectContaining({ title, message: 'It is deleted on Bluesky as well.', danger: true }),
    );
    expect(api.deletePost).toHaveBeenCalledWith(9);
    expect(gone).toBe(true);
    expect(toast.show).not.toHaveBeenCalled();
  });

  it('says so when the network no longer had the post, and still lets it go', () => {
    api.deletePost.mockReturnValue(of({ outcome: 'alreadyGone' }));
    let gone = false;

    actions.deletePost(ownPost(0)).subscribe(() => (gone = true));

    expect(gone).toBe(true);
    expect(toast.show).toHaveBeenCalledWith({ message: 'Already deleted on Bluesky — removed here.' });
  });

  it('deletes nothing when the reader cancels', () => {
    confirm.ask.mockReturnValue(of(false));
    let gone = false;

    actions.deletePost(ownPost(0)).subscribe(() => (gone = true));

    expect(api.deletePost).not.toHaveBeenCalled();
    expect(gone).toBe(false);
  });

  it('keeps the post and says why when the network refuses', () => {
    api.deletePost.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 502,
            error: { type: 'social_network_unreachable', title: 'x', detail: 'Bluesky is down.' },
          }),
      ),
    );
    let gone = false;

    actions.deletePost(ownPost(0)).subscribe(() => (gone = true));

    expect(gone).toBe(false);
    expect(toast.show).toHaveBeenCalledWith({ message: 'Bluesky is down.' });
  });
```
Run `docker compose exec -T frontend npx jest src/app/reader/article/composer/post-actions.service.spec.ts` — expected FAIL (`deletePost` is not a function).

Implementation in `PostActions` (imports `EMPTY, catchError, map, tap` from `rxjs`, `EntryDto` from `'../../models'`; update the class docblock's first line to "Replying, quoting, posting a link, deleting a reply or one's own post, …"):

```ts
  /** Deletes the reader's own post after a confirm; emits once the post is gone here, nothing otherwise. */
  deletePost(entry: EntryDto): Observable<void> {
    const network = entry.social?.network;
    if (network === undefined) return EMPTY;
    const params = { network: SOCIAL_NETWORK_NAMES[network] };
    return this.confirm
      .ask({
        title: this.deletePostTitle(entry.thread.length),
        message: this.transloco.translate('reader.deletePost.message', params),
        confirmLabel: this.transloco.translate('reader.deletePost.confirm'),
        danger: true,
      })
      .pipe(
        filter(Boolean),
        switchMap(() => this.api.deletePost(entry.id)),
        tap(({ outcome }) => {
          if (outcome === 'alreadyGone') {
            this.toast.show({
              message: this.transloco.translate('reader.deletePost.alreadyGone', params),
            });
          }
        }),
        map(() => undefined),
        catchError((error: HttpErrorResponse) => {
          this.toast.show({ message: problemMessage(parseProblem(error)) });
          return EMPTY;
        }),
      );
  }

  private deletePostTitle(replies: number): string {
    if (replies === 0) return this.transloco.translate('reader.deletePost.title');
    if (replies === 1) return this.transloco.translate('reader.deletePost.titleWithOneReply');
    return this.transloco.translate('reader.deletePost.titleWithReplies', { count: replies });
  }
```
Run the spec — expected PASS.

- [ ] **Step 5: The handler, the shell and the duplicates.**
  - `EntryActionHandler`: add `abstract deletePost(entry: EntryDto): void;` and `deletePost: () => undefined,` in `IGNORED_ENTRY_ACTIONS`.
  - `EntryStateActions` (failing spec first, Step 6): inject `private readonly postActions = inject(PostActions);` (from `'../article/composer/post-actions.service'`) and add

```ts
  /** The deleted post's row leaves the list as an excluded one does; its open article closes. */
  deletePost(entry: EntryDto): void {
    this.postActions.deletePost(entry).subscribe(() => {
      this.markLeaving(entry.id);
      if (this.routeState.openEntryId() === entry.id) this.closeEntry();
      this.subscriptions.reloadCountsQuietly();
    });
  }

  closeEntry(): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: entryChangeParams(null),
      queryParamsHandling: 'merge',
    });
  }
```
  with `private markLeaving(id: number): void { this.leaving.update((ids) => new Set(ids).add(id)); }`, which `leaveExcludedRow` now calls too (no second copy of that line).
  - `ReaderShellComponent.onCloseReader()` becomes `this.entryActions.closeEntry();` (the article closes exactly as on Back). Keep the `entryChangeParams` import: line ~357 still uses it.
  - `EntryDuplicatesComponent`: a copy in the popover is the same post as the row (copies collapse by `url_hash`, and the server deletes them all), so the row is what leaves:

```ts
  deletePost(): void {
    this.close();
    this.shellActions.deletePost(this.entry());
  }
```

- [ ] **Step 6: `EntryStateActions` spec.** Add `{ provide: PostActions, useValue: {} }` to the three existing `TestBed.configureTestingModule` provider lists (EntryStateActions now injects it), and a new block:

```ts
describe('EntryStateActions deleting my own post (#1972)', () => {
  const deletePost = jest.fn<Observable<void>, [EntryDto]>();
  const reloadCountsQuietly = jest.fn();
  const openEntryId = signal<number | null>(null);
  let actions: EntryStateActions;
  let navigate: jest.SpyInstance;

  beforeEach(() => {
    deletePost.mockReset().mockReturnValue(of(undefined));
    reloadCountsQuietly.mockReset();
    openEntryId.set(null);
    TestBed.configureTestingModule({
      providers: [
        EntryStateActions,
        provideRouter([]),
        { provide: EntriesStore, useValue: {} },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
        { provide: SavedSearchesStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId, openEntry: signal(null), selection: () => ({ kind: 'all' }) },
        },
        { provide: ToastService, useValue: {} },
        { provide: PostActions, useValue: { deletePost } },
      ],
    });
    actions = TestBed.inject(EntryStateActions);
    navigate = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
  });

  it('lets the row leave and recounts once the post is gone', () => {
    actions.deletePost(anEntry({ id: 7 }));

    expect(actions.leavingIds().has(7)).toBe(true);
    expect(reloadCountsQuietly).toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();
  });

  it('closes the article of the deleted post as Back does', () => {
    openEntryId.set(7);

    actions.deletePost(anEntry({ id: 7 }));

    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { entry: null, link: null } }),
    );
  });

  it('keeps the row when the post stays', () => {
    deletePost.mockReturnValue(EMPTY);

    actions.deletePost(anEntry({ id: 7 }));

    expect(actions.leavingIds().size).toBe(0);
    expect(reloadCountsQuietly).not.toHaveBeenCalled();
  });
});
```
(imports `Observable, of, EMPTY` from `rxjs`, `PostActions`). Run `docker compose exec -T frontend npx jest src/app/reader/shell/entry-state-actions.service.spec.ts` — FAIL first, PASS after Step 5.

- [ ] **Step 7: The button.** Spec first in `entry-actions.component.spec.ts`: give `HostComponent` `deleted: EntryDto | null = null;` and `deletePost(entry: EntryDto): void { this.deleted = entry; }`, then:

```ts
  it('offers Delete post last, and only on my own post', () => {
    const own = anEntry({ social: { network: 'mastodon', isLiked: false, canLike: true, isOwn: true } });
    const foreign = anEntry({ social: { network: 'mastodon', isLiked: false, canLike: true, isOwn: false } });

    const ownLabels = buttons(mount(own)).map((button) => button.getAttribute('aria-label'));
    const foreignLabels = buttons(mount(foreign)).map((button) => button.getAttribute('aria-label'));

    expect(ownLabels.at(-1)).toBe('Delete post');
    expect(foreignLabels).not.toContain('Delete post');
  });

  it('deletes through the handler without opening the card', () => {
    const own = anEntry({ social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: true } });
    const fixture = mount(own);

    buttons(fixture).at(-1)?.click();

    expect(fixture.componentInstance.deleted).toBe(own);
    expect(fixture.componentInstance.cardOpened).toBe(false);
  });
```
Template, appended after the read toggle in `entry-actions.component.html`:

```html
@if (entry().social?.isOwn) {
  <button
    type="button"
    class="danger"
    [attr.aria-label]="'reader.deletePost.action' | transloco"
    [attr.title]="'reader.deletePost.action' | transloco"
    (click)="$event.stopPropagation(); actions.deletePost(entry())"
    (keydown.enter)="$event.stopPropagation()"
    (keydown.space)="$event.stopPropagation()"
  >
    <app-icon name="delete" [size]="size()" />
  </button>
}
```
`entry-actions.component.scss`, after the `button` rule:

```scss
.danger {
  color: var(--danger);
}
```
Update the component's docblock list of actions to name "delete my own post (#1972)". Run `docker compose exec -T frontend npx jest src/app/reader/entry/entry-actions` — expected PASS.

- [ ] **Step 8: The whole frontend suite and gates, one jest process at a time:**
  `docker compose exec -T frontend npx jest src/app/reader` → PASS; then `docker compose exec -T frontend npm run check` → clean (ESLint, Prettier, Stylelint, tsc app/spec/e2e, full Jest); then `docker compose exec -T frontend npx ng build` → succeeds (template errors `npm run check` misses).

- [ ] **Step 9: Commit** `feat(#1972): the reader offers Delete post on my own posts`.

---

### Task 7: Simplify, full gates, read-only live check

- [ ] **Step 1: `/simplify`** over the branch diff (`git diff develop...HEAD`); apply its fixes; re-run the touched tests.
- [ ] **Step 2: Backend gates** (from `backend/`): `composer check` (cs + stan + tramp; `composer show larspohlmann/phptramp` first if tramp fails without a cause in this diff), `composer md` (every touched `src` file clean), `composer test:parallel` (SQLite) **and**, at the same time, `docker compose exec php composer test:parallel` (MySQL), then `composer infection:diff` (every new file is committed, so the diff sees it). PhpStorm `mcp__phpstorm__lint_files` on every changed PHP file: no ERROR/WARNING.
- [ ] **Step 3: Frontend gates** — `docker compose exec -T frontend npm run check` and `docker compose exec -T frontend npx ng build`.
- [ ] **Step 4: Containers current** — the Docker `php` container serves this checkout and its DI container is rebuilt (`docker compose exec php bin/console cache:clear`); `:4200` serves the new chunk (hard reload). No migration to apply.
- [ ] **Step 5: Live check on `http://localhost:4200`, read-only.** Never click "Delete post" and never confirm a delete: that would delete Lars's real posts. Signed in as Lars:
  - Open a feed holding Lars's own Bluesky and Mastodon posts (his test posts, see memory `social-live-test-posts`): the trash icon shows last in the row's actions, danger-coloured, in the list and in the open article; it is absent on other people's posts and on his reposts of other people's posts.
  - In the browser's network panel, the list and `GET /api/entries/{id}` answers carry `social.isOwn: true` on his posts and `false` elsewhere.
  - Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 100 | jq .` — no new errors or deprecations.
- [ ] **Step 6: Report** to the controller: what passed, the live-check observations, and that deleting itself was verified only by tests (spec Q3 forbids a pre-fetch, and posting is not allowed here).
