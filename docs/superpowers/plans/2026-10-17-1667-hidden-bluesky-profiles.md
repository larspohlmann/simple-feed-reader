# Hidden Bluesky Profiles via the Account Session — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Bluesky profile that carries the self-label `!no-unauthenticated` (its public RSS answers 403) can be subscribed through the user's connected Bluesky account: on subscribe, from the import picker, and automatically when an existing RSS subscription starts failing with 403.

**Architecture:** A new `SocialFeedKind::Author` private feed (`social:bluesky:<accountId>:author:<did>`) reads `app.bsky.feed.getAuthorFeed` through the account session and keeps only what the public RSS would list. Detection is one Social interface, `HiddenProfilesInterface`, answered by the public AppView's `getProfiles` (test container: a stub). The Subscription module owns `SubscribeRouteInterface`, consulted before discovery; Social implements it. The import picker partitions the chosen follows server-side. The automatic switch is a state-driven sweep (worker message + maintenance tick) that first creates the author feed and, once that feed has fetched, moves the user's saved state by guid with the shared `SavedStateMover` and unsubscribes the RSS feed.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM, PHPUnit 12; Angular 20 + Jest (one spec).

**Spec:** GitHub issue [#1667](https://github.com/larspohlmann/simple-feed-reader/issues/1667). The decisions there are binding; the ones that matter are restated in each task.

**The shared entry-state service (also used by #1666):** `App\Service\SavedPosts\SavedStateMover`, at `backend/src/Service/SavedPosts/SavedStateMover.php`, with `move(int $userId, array<int, int> $targetByEntryId): void` (Task 9). Its signature and semantics are fixed by the #1666 plan; whichever branch lands first, the other takes it over unchanged.

## Global Constraints

- Work only in the worktree `.claude/worktrees/1667-hidden-bluesky-profiles`, branch `feature/1667-hidden-bluesky-profiles` off `develop`. Commits `type(#1667): …`; no Claude attribution anywhere (no `Co-Authored-By`, no "Generated with").
- `social_feed.kind` is `VARCHAR(16)`: `author` fits, **no migration**.
- **Merge order (Lars, 2026-10-17): #1667 merges only after #1666.** The switch's unsubscribe relies on #1666 Part 1 to save favourites and kept posts with no counterpart in the new author feed (older than its first 50 posts) to "Saved from Bluesky". Before Task 12, merge `origin/develop`. If #1666 has landed, add a test that a switched subscription's unmatched favourite ends up in the saved feed. If it has not landed yet, do not open the PR for merge; report back instead.
- Only hidden profiles use the session; every other profile stays on the shared public RSS `https://bsky.app/profile/<did>/rss`. We never read the anonymous `public.api.bsky.app` `getAuthorFeed` for content: the session honours the author's choice and applies the user's blocks and mutes. The AppView is asked only for `getProfiles` (the label).
- Hidden = the profile view carries a label with `val` `!no-unauthenticated` whose `src` is the profile's own DID and that is not negated (`neg`).
- An author feed is a normal subscription: tags from the request, `includeInAllItems` and `includeInForYou` stay `true`. It is presented as a person: `SubscriptionJson` reports `kind: 'profile'` with `accountId: null` (the existing contract for profile feeds, so the frontend types do not change).
- The read goes through the user's **default connected** Bluesky account: `SocialAccountRepository::findReadyActingOnNetwork()` (default first, `needs_reconnect` excluded). The import picker uses the importing account.
- Hidden + no connected Bluesky account on subscribe: **422** `bluesky_profile_hidden`, detail "This profile is only visible to logged-in Bluesky users. Connect a Bluesky account in Settings → Social Media to subscribe."
- If the AppView cannot be asked (unreachable, malformed), subscribe and import fall back to today's behaviour (discovery / RSS); the automatic switch then catches the 403 later. Never fail a subscribe because the label check failed.
- **Design deviation from the brief, deliberately:** the brief asked for a worker message dispatched on the transition into failure. Messenger here has no async transport ("Web requests dispatch nothing", `messenger.yaml`); a dispatch from `FeedOutcomePersister` would run synchronously inside the refresh, and on Strato the refresh itself runs inside the `/maintenance/tick` request. So the switch is a property-less scheduled message (`SwitchHiddenProfileFeeds`, house pattern) plus a maintenance-tick sweep, and its queue is the database state itself (a Bluesky profile RSS subscription whose feed last failed with `HTTP 403` and whose user has a connected Bluesky account). A daily cache memo of profiles found public stops a 403 for another reason from being re-asked every sweep.
- The switch is two-phase because Social may not depend on Refresh (Refresh → Social exists): phase 1 creates the author feed with the RSS subscription's settings (due at once); phase 2, on a later sweep after that feed's first successful fetch, moves the saved state by guid and unsubscribes the RSS subscription. The two feeds sit side by side for at most one refresh cycle.
- #1666 runs in parallel. Its private-feed sync acts on `PrivateFeedSync::SYNCED_KINDS = [UserList, CustomFeed]`, so `Author` needs nothing there. Changes to `PrivateFeeds`, `SocialImporter` and `ImportCandidates` stay small and additive; `PrivateFeeds` is not touched at all.
- Clean Code rules from `CLAUDE.md` apply to every file touched: role folders (§10), no boolean flag parameters, `Response::HTTP_*` never literals, queries only in `src/Repository`, no comment unless a future reader would get the code wrong, PHPMD-clean, phptramp (tags travel inside `SubscribeRequestModel`, not down a 6-method chain).
- Worktree limits: the Docker stack, both e2e suites and PhpStorm inspections cannot run from this worktree. Task 12 lists a manual checklist for after the merge into the main checkout.
- Every test names the production condition it guards ("Deletion check"); Infection gates changed lines (`composer infection:diff`).

---

## File Structure

**Backend — kind and addressing**
- Modify `backend/src/Enum/SocialFeedKind.php` (case `Author = 'author'`).
- Modify `backend/src/Service/Social/Support/SocialFeedUrl.php` (segment `author`, `AUTHOR_FEEDS`, `authorOf()`).
- Modify `backend/src/Service/Social/Model/PrivateFeedModel.php` (`author()`, `of()` arm).
- Modify `backend/src/Service/Social/Support/PrivateFeedRules.php`, `PrivateFeedSite.php`, `backend/src/Service/Social/SocialImporter.php` (`kindLabel`).
- Create `backend/src/Service/Social/Support/ProfileIdentity.php`.
- Modify `backend/src/Service/Social/Support/ProfileFeedUrl.php` (`blueskyProfileActorOf()`).

**Backend — reading**
- Modify `backend/src/Service/Social/Support/BlueskyTimelinePage.php` (`ofAuthor()`), `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`.

**Backend — detection**
- Create `backend/src/Service/Social/Support/BlueskyProfileLabels.php`, `backend/src/Service/Social/Model/HiddenProfileModel.php`.
- Create `backend/src/Service/Social/HiddenProfiles/HiddenProfilesInterface.php`, `backend/src/Service/Social/HiddenProfiles/AppViewHiddenProfiles.php`.
- Create `backend/tests/Support/StubHiddenProfiles.php`; modify `backend/config/services_test.yaml`.

**Backend — creation, presentation, subscribe, import**
- Create `backend/src/Service/Social/AuthorFeeds.php`; modify `backend/src/Repository/SocialAccountRepository.php`.
- Modify `backend/src/Http/SubscriptionJson.php`, `backend/src/Repository/NetworkScopeDql.php`, `backend/src/Repository/FeedRepository.php`, `backend/src/Service/Social/ProfileImageSweep.php`, `backend/src/Service/Social/ImportCandidates.php`.
- Create `backend/src/Service/Subscription/SubscribeRoute/SubscribeRouteInterface.php`, `backend/src/Service/Subscription/Model/SubscribeRequestModel.php`; modify `backend/src/Service/Subscription/SubscriptionService.php`, `backend/config/services.yaml`.
- Create `backend/src/Service/Social/HiddenProfileSubscribeRoute.php`, `backend/src/Service/Social/Exception/BlueskyProfileHiddenException.php`; modify `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php`.
- Create `backend/src/Service/Social/HiddenImportFollows.php`; modify `backend/src/Service/Social/SocialImporter.php`.

**Backend — automatic switch**
- Create `backend/src/Service/SavedPosts/SavedStateMover.php` (new module); modify `backend/src/Repository/SocialLikeRepository.php` (`copyLike`), `backend/src/Repository/EntryRepository.php` (`sameGuidEntryIds`).
- Modify `backend/src/Entity/Subscription.php` (`takeSettingsFrom`), `backend/src/Repository/SubscriptionRepository.php`.
- Create `backend/src/Service/Social/PublicProfileCache.php`, `HiddenProfileMove.php`, `HiddenProfileSwitchSweep.php`, `ProfileSweeps.php`, `Model/HiddenProfileMoveOutcome.php`, `Model/HiddenProfileSwitchReportModel.php`; modify `backend/config/packages/cache.yaml`, `backend/src/Service/Social/Model/ProfileImageBudgetModel.php` (docblock).
- Create `backend/src/Service/Worker/Message/SwitchHiddenProfileFeeds.php`, `backend/src/Service/Worker/Handler/SwitchHiddenProfileFeedsHandler.php`; modify `WorkerSchedule.php`, `backend/src/Service/Maintenance/MaintenanceTick.php`, `Model/MaintenanceSweepsModel.php`, `backend/src/Http/MaintenanceTickJson.php`.

**Frontend**
- Modify `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts` (the 422 message). No production change: the dialog already shows a problem's `detail`, and the author feed reaches the client as `kind: 'profile'`.

---

### Task 1: The Author kind and its address

**Files:**
- Modify: `backend/src/Enum/SocialFeedKind.php`, `backend/src/Service/Social/Support/SocialFeedUrl.php`, `backend/src/Service/Social/Model/PrivateFeedModel.php`, `backend/src/Service/Social/Support/PrivateFeedRules.php`, `backend/src/Service/Social/Support/PrivateFeedSite.php`, `backend/src/Service/Social/SocialImporter.php`
- Create: `backend/src/Service/Social/Support/ProfileIdentity.php`
- Test: `backend/tests/Service/Social/Support/SocialFeedUrlTest.php`, `PrivateFeedRulesTest.php`, `PrivateFeedSiteTest.php`, create `ProfileIdentityTest.php`

**Interfaces:**
- Produces: `SocialFeedKind::Author`; `PrivateFeedModel::author(string $did, string $name): self`; `SocialFeedUrl::AUTHOR_FEEDS` (`'social:%:author:%'`, a DQL `LIKE` pattern); `SocialFeedUrl::authorOf(SocialNetwork $network, string $url): ?string` (the DID of an author feed URL on that network); `ProfileIdentity::of(SocialNetwork $network, string $feedUrl): ?string` (a profile RSS feed's identity, else an author feed's DID).

- [ ] **Step 1: Write the failing tests.**

In `SocialFeedUrlTest`, add a row to `blueskyFeeds()`:

```php
        yield 'author' => [
            PrivateFeedModel::author('did:plc:rusty', 'Rusty'),
            'social:bluesky:12:author:did:plc:rusty',
        ];
```

add a row to `addresses()`:

```php
        yield 'author' => [
            'social:bluesky:12:author:did:plc:rusty',
            new SocialFeedAddressModel(SocialNetwork::Bluesky, 12, SocialFeedKind::Author),
        ];
```

and append:

```php
    #[DataProvider('authors')]
    public function testAnAuthorFeedNamesTheDidItFollows(SocialNetwork $network, string $url, ?string $did): void
    {
        self::assertSame($did, SocialFeedUrl::authorOf($network, $url));
    }

    /** @return iterable<string, array{SocialNetwork, string, ?string}> */
    public static function authors(): iterable
    {
        yield 'an author feed' => [SocialNetwork::Bluesky, 'social:bluesky:12:author:did:plc:rusty', 'did:plc:rusty'];
        yield 'on another network' => [SocialNetwork::Mastodon, 'social:bluesky:12:author:did:plc:rusty', null];
        yield 'another kind' => [SocialNetwork::Bluesky, 'social:bluesky:12:list:at://did:plc:bob/app.bsky.graph.list/1', null];
        yield 'no did' => [SocialNetwork::Bluesky, 'social:bluesky:12:author:rusty.bsky.social', null];
        yield 'an rss feed' => [SocialNetwork::Bluesky, 'https://bsky.app/profile/did:plc:rusty/rss', null];
    }
```

In `PrivateFeedRulesTest::verdicts()` add:

```php
        yield 'bluesky author' => [SocialNetwork::Bluesky, PrivateFeedModel::author('did:plc:rusty', 'Rusty'), true];
        yield 'bluesky author named by a handle' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::author('rusty.bsky.social', 'Rusty'),
            false,
        ];
        yield 'mastodon has no author feeds' => [
            SocialNetwork::Mastodon,
            PrivateFeedModel::author('did:plc:rusty', 'Rusty'),
            false,
        ];
```

In `PrivateFeedSiteTest::blueskyFeeds()` add:

```php
        yield 'author' => [PrivateFeedModel::author('did:plc:rusty', 'Rusty'), 'https://bsky.app/profile/did:plc:rusty'];
```

Create `backend/tests/Service/Social/Support/ProfileIdentityTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Support\ProfileIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileIdentityTest extends TestCase
{
    #[DataProvider('feeds')]
    public function testAFeedFollowsThePersonItsAddressNames(SocialNetwork $network, string $feedUrl, ?string $identity): void
    {
        self::assertSame($identity, ProfileIdentity::of($network, $feedUrl));
    }

    /** @return iterable<string, array{SocialNetwork, string, ?string}> */
    public static function feeds(): iterable
    {
        yield 'a profile rss feed' => [SocialNetwork::Bluesky, 'https://bsky.app/profile/did:plc:bob/rss', 'did:plc:bob'];
        yield 'an author feed' => [SocialNetwork::Bluesky, 'social:bluesky:3:author:did:plc:rusty', 'did:plc:rusty'];
        yield 'a home timeline' => [SocialNetwork::Bluesky, 'social:bluesky:3:home', null];
        yield 'an ordinary feed' => [SocialNetwork::Bluesky, 'https://example.com/feed.xml', null];
    }
}
```

Deletion checks:
- `'author'` rows in `blueskyFeeds`/`addresses`: delete the `'author' => 'author'` segment → `segmentOf` reads an undefined key (error) and `addressOf` returns `null` (row expects the model) → red. The existing `testEveryKindIsReadBackFromItsOwnUrl` also turns red for the new case.
- `authors()`: one row per guard in `authorOf` — drop the network check → "on another network" returns the DID; drop the kind check → "another kind" returns `at://…` (not null); drop `isDid` → "no did" returns the handle; drop the `addressOf` null check → "an rss feed" errors.
- `PrivateFeedRules` rows: drop `SocialNetwork::Bluesky ===` → the Mastodon row turns true; drop `isDid` → the handle row turns true.
- `PrivateFeedSite` author row: without the Author arm the site falls back to the account's own profile (`did:plc:alice`) → red.
- `ProfileIdentityTest`: drop the `??` fallback → "an author feed" yields `null` → red; the RSS row guards the first operand.

- [ ] **Step 2: Run the tests and see them fail.** `cd backend && php bin/phpunit tests/Service/Social/Support/SocialFeedUrlTest.php tests/Service/Social/Support/PrivateFeedRulesTest.php tests/Service/Social/Support/PrivateFeedSiteTest.php tests/Service/Social/Support/ProfileIdentityTest.php`. Expected: errors — undefined method `PrivateFeedModel::author`, class `ProfileIdentity` not found.

- [ ] **Step 3: Implement.**

`SocialFeedKind`:

```php
enum SocialFeedKind: string
{
    case Home = 'home';
    case UserList = 'list';
    case CustomFeed = 'custom_feed';
    case Author = 'author';
}
```

`PrivateFeedModel` — add after `customFeed()`:

```php
    public static function author(string $did, string $name): self
    {
        return new self(SocialFeedKind::Author, $did, $name);
    }
```

and the `of()` arm:

```php
            SocialFeedKind::CustomFeed => self::customFeed($remoteId, $name),
            SocialFeedKind::Author => self::author($remoteId, $name),
```

`SocialFeedUrl` — the segments, the pattern and the DID reader:

```php
    /** Every author feed's URL, as a DQL LIKE pattern: the one private feed that follows a person. */
    public const string AUTHOR_FEEDS = self::SCHEME . '%:author:%';

    private const string SCHEME = 'social:';
    private const string ACCOUNT_ID = '/^[1-9]\d*$/';
    private const array SEGMENTS = ['home' => 'home', 'list' => 'list', 'custom_feed' => 'feed', 'author' => 'author'];
```

(`AUTHOR_FEEDS` must sit after `SCHEME` if your PHP version complains about the forward reference; order the constants `SCHEME`, `AUTHOR_FEEDS`, `ACCOUNT_ID`, `SEGMENTS`.)

```php
    /** @return string|null the DID an author feed of $network follows; null for any other URL */
    public static function authorOf(SocialNetwork $network, string $url): ?string
    {
        $address = self::addressOf($url);
        if (null === $address || $network !== $address->network || SocialFeedKind::Author !== $address->kind) {
            return null;
        }
        $did = explode(':', $url, 5)[4] ?? '';

        return ProfileFeedUrl::isDid($did) ? $did : null;
    }
```

`PrivateFeedRules::allows()` — add the arm (and `use` nothing new: `ProfileFeedUrl` is in the same namespace):

```php
            SocialFeedKind::CustomFeed => SocialNetwork::Bluesky === $network
                && BlueskyRecordUri::isFeedGenerator($privateFeed->remoteId),
            SocialFeedKind::Author => SocialNetwork::Bluesky === $network
                && ProfileFeedUrl::isDid($privateFeed->remoteId),
```

`PrivateFeedSite::bluesky()`:

```php
    private static function bluesky(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        if (SocialFeedKind::Author === $privateFeed->kind) {
            return BlueskyWeb::profileUrl($privateFeed->remoteId);
        }

        return BlueskyRecordUri::webUrl($privateFeed->remoteId)
            ?? BlueskyWeb::profileUrl($account->getIdentity());
    }
```

`SocialImporter::kindLabel()` — the exhaustive match needs the arm (Author never arrives as a collection: `SocialImportCollectionItem`'s `Assert\Choice` stays as it is):

```php
            SocialFeedKind::CustomFeed => 'custom feed',
            SocialFeedKind::Author => 'profile',
```

Create `backend/src/Service/Social/Support/ProfileIdentity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialNetwork;

/** The person a subscribed feed follows: a profile RSS feed by its URL, an author feed by the DID it reads. */
final class ProfileIdentity
{
    /** @return string|null the DID, handle or user@host; null when the feed follows no person on $network */
    public static function of(SocialNetwork $network, string $feedUrl): ?string
    {
        return ProfileFeedUrl::identityOf($network, $feedUrl) ?? SocialFeedUrl::authorOf($network, $feedUrl);
    }

    private function __construct()
    {
    }
}
```

`MastodonTimelineSource`, `SocialImportCollectionItem` and the frontend `KIND_ORDER`/`COLLECTION_ICONS` need nothing: an author feed exists only on Bluesky, is never a selectable collection, and reaches the client as `kind: 'profile'` (Task 5).

- [ ] **Step 4: Run the tests and see them pass.** Same command. Expected: PASS.

- [ ] **Step 5: Commit.** `cd backend && composer cs`, then:

```bash
git add backend/src/Enum/SocialFeedKind.php backend/src/Service/Social/Support backend/src/Service/Social/Model/PrivateFeedModel.php backend/src/Service/Social/SocialImporter.php backend/tests/Service/Social/Support
git commit -m "feat(#1667): an author kind for private feeds that follow one person"
```

---

### Task 2: Read an author feed as its public RSS would list it

**Files:**
- Modify: `backend/src/Service/Social/Support/BlueskyTimelinePage.php`, `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`
- Test: `backend/tests/Service/Social/Support/BlueskyTimelinePageTest.php`, `backend/tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php`

**Interfaces:**
- Produces: `BlueskyTimelinePage::ofAuthor(list<JsonNodeModel> $items, string $authorDid): TimelinePageModel`. `of()` keeps its signature and rule. One private `pageOf(array $items, \Closure $isShown)` carries the loop: a per-kind rule as a closure, no boolean flag.
- `BlueskyTimelineSource` asks `/xrpc/app.bsky.feed.getAuthorFeed?actor=<did>&filter=posts_and_author_threads&limit=50` for `SocialFeedKind::Author`.

- [ ] **Step 1: Write the failing tests.** Append to `BlueskyTimelinePageTest`:

```php
    /** @param array<string, mixed> $overrides */
    #[DataProvider('authorFeedItems')]
    public function testAnAuthorFeedListsOnlyTheProfilesOwnPostsAndSelfThreads(array $overrides, int $shown): void
    {
        self::assertCount($shown, BlueskyTimelinePage::ofAuthor([self::item($overrides)], self::BOB)->parsed->entries);
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function authorFeedItems(): iterable
    {
        yield 'an own post' => [[], 1];
        yield 'a repost' => [['reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost', 'by' => ['did' => self::BOB]]], 0];
        yield 'a self-thread reply' => [['reply' => ['parent' => ['author' => ['did' => self::BOB, 'viewer' => []]]]], 1];
        yield 'a reply to someone else' => [
            ['reply' => ['parent' => ['author' => ['did' => 'did:plc:eve', 'viewer' => ['following' => 'at://x/y/1']]]]],
            0,
        ];
        yield 'a reply to a deleted post' => [['reply' => ['parent' => ['notFound' => true]]], 0];
    }
```

and add the constant `private const string BOB = 'did:plc:bob';` next to `ALICE`.

Append to `BlueskyTimelineSourceTest`:

```php
    public function testAnAuthorFeedAsksForTheProfilesPostsAndSelfThreads(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1')]);

        $request = $this->source($http->client())->request($this->socialFeed(SocialFeedKind::Author, 'did:plc:bob'));

        self::assertSame(
            'GET https://pds.example.test/xrpc/app.bsky.feed.getAuthorFeed?actor=did%3Aplc%3Abob'
                . '&filter=posts_and_author_threads&limit=50',
            $request->method . ' ' . $request->url,
        );
    }

    public function testAnAuthorFeedKeepsASelfThreadReplyTheHomeRuleWouldHide(): void
    {
        $body = ['feed' => [[
            'post' => [
                'uri' => 'at://did:plc:bob/app.bsky.feed.post/3abd',
                'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social'],
                'record' => ['text' => 'And another thing', 'createdAt' => '2026-10-12T10:05:00Z'],
            ],
            'reply' => ['parent' => ['author' => ['did' => 'did:plc:bob', 'viewer' => []]]],
        ]]];

        $page = $this->source(new MockHttpClient())->pageOf(
            $this->socialFeed(SocialFeedKind::Author, 'did:plc:bob'),
            self::answer($body),
        );

        self::assertCount(1, $page->parsed->entries);
    }
```

Deletion checks:
- `authorFeedItems`, one row per condition of `isAuthorsOwn`: drop the repost check → "a repost" shows 1; drop the parent-author comparison (return `true` for any reply) → "a reply to someone else" and "a reply to a deleted post" show 1; invert `!$item->has('reply')` → "an own post" shows 0.
- `testAnAuthorFeedAsksFor…`: delete the `Author` arm of `pathOf` → PHP's `UnhandledMatchError` → red; a typo in the filter fails the string assertion.
- `testAnAuthorFeedKeepsASelfThreadReply…`: route `pageOf` through `of()` for every kind → the Home rule hides a reply from bob (not alice, not followed) → 0 entries → red.

- [ ] **Step 2: Run the tests and see them fail.** `cd backend && php bin/phpunit tests/Service/Social/Support/BlueskyTimelinePageTest.php tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php`. Expected: undefined method `ofAuthor`; `UnhandledMatchError` for the path.

- [ ] **Step 3: Implement.** In `BlueskyTimelinePage`, replace `of()` with:

```php
    /** @param list<JsonNodeModel> $items the answer's `feed` */
    public static function of(array $items, string $accountDid): TimelinePageModel
    {
        return self::pageOf($items, static fn (JsonNodeModel $item): bool => self::isShown($item, $accountDid));
    }

    /**
     * What the profile's public RSS lists: its own posts and its replies to itself, never a repost.
     *
     * @param list<JsonNodeModel> $items the answer's `feed`
     */
    public static function ofAuthor(array $items, string $authorDid): TimelinePageModel
    {
        return self::pageOf($items, static fn (JsonNodeModel $item): bool => self::isAuthorsOwn($item, $authorDid));
    }

    /**
     * @param list<JsonNodeModel>          $items
     * @param \Closure(JsonNodeModel): bool $isShown
     */
    private static function pageOf(array $items, \Closure $isShown): TimelinePageModel
    {
        $entries = [];
        $posts = [];
        foreach ($items as $item) {
            $entry = $isShown($item) ? self::entryOf($item) : null;
            if (null === $entry) {
                continue;
            }
            $entries[] = $entry;
            $posts[$entry->guid] = $item->node('post');
        }

        return TimelinePageModel::of($entries, KnownSharesModel::ofBlueskyPosts($posts), null);
    }

    private static function isAuthorsOwn(JsonNodeModel $item, string $authorDid): bool
    {
        if (self::REPOST === $item->node('reason')->type()) {
            return false;
        }

        return !$item->has('reply')
            || $authorDid === $item->node('reply')->node('parent')->node('author')->string('did');
    }
```

Update the class docblock to `/** A getTimeline / getListFeed / getFeed / getAuthorFeed answer as entries; each post view rides along for its embed. */`.

In `BlueskyTimelineSource::pageOf()`:

```php
        $items = self::itemsOf($answer);

        return SocialFeedKind::Author === $socialFeed->getKind()
            ? BlueskyTimelinePage::ofAuthor($items, $socialFeed->getRemoteId())
            : BlueskyTimelinePage::of($items, $socialFeed->getSocialAccount()->getIdentity());
```

and the `pathOf()` arm:

```php
            SocialFeedKind::CustomFeed => '/xrpc/app.bsky.feed.getFeed?feed=' . $remoteId . '&',
            SocialFeedKind::Author => '/xrpc/app.bsky.feed.getAuthorFeed?actor=' . $remoteId
                . '&filter=posts_and_author_threads&',
```

- [ ] **Step 4: Run the tests and see them pass.** Same command. Expected: PASS.

- [ ] **Step 5: Commit.** `composer cs`, then:

```bash
git add backend/src/Service/Social/Support/BlueskyTimelinePage.php backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php backend/tests/Service/Social/Support/BlueskyTimelinePageTest.php backend/tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php
git commit -m "feat(#1667): read an author feed through the session as its public RSS lists it"
```

---

### Task 3: Tell a hidden profile from a public one

**Files:**
- Create: `backend/src/Service/Social/Support/BlueskyProfileLabels.php`, `backend/src/Service/Social/Model/HiddenProfileModel.php`, `backend/src/Service/Social/HiddenProfiles/HiddenProfilesInterface.php`, `backend/src/Service/Social/HiddenProfiles/AppViewHiddenProfiles.php`, `backend/tests/Support/StubHiddenProfiles.php`
- Modify: `backend/src/Service/Social/Support/ProfileFeedUrl.php`, `backend/config/services_test.yaml`
- Test: create `backend/tests/Service/Social/Support/BlueskyProfileLabelsTest.php`, `backend/tests/Service/Social/HiddenProfiles/AppViewHiddenProfilesTest.php`; modify `backend/tests/Service/Social/Support/ProfileFeedUrlTest.php`

**Interfaces:**
- Produces: `BlueskyProfileLabels::hidesFromLoggedOut(JsonNodeModel $profile): bool`.
- Produces: `HiddenProfileModel(string $did, string $name)`.
- Produces: `HiddenProfilesInterface::hiddenAmong(list<string> $actors): array<string, HiddenProfileModel>` — the hidden ones, keyed by DID **and** lower-cased handle (as `AppViewClient::profiles()` keys); throws `SocialNetworkUnreachableException` when the AppView cannot answer.
- Produces: `ProfileFeedUrl::blueskyProfileActorOf(string $url): ?string` — the DID or lower-cased handle of `bsky.app/profile/<actor>` with or without `/rss` and a trailing slash.
- Produces (tests): `App\Tests\Support\StubHiddenProfiles` — `hide(HiddenProfileModel $profile, string ...$handles): void`, `public ?SocialNetworkUnreachableException $failure`, `public array $asked` (each call's actors). The test container aliases the interface to it, so no container test ever asks the real AppView.

- [ ] **Step 1: Write the failing tests.**

Create `backend/tests/Service/Social/Support/BlueskyProfileLabelsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Support\BlueskyProfileLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyProfileLabelsTest extends TestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';

    /** @param array<string, mixed> $profile */
    #[DataProvider('profiles')]
    public function testOnlyTheProfilesOwnLabelHidesIt(array $profile, bool $hidden): void
    {
        self::assertSame($hidden, BlueskyProfileLabels::hidesFromLoggedOut(JsonNodeModel::of($profile)));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function profiles(): iterable
    {
        $own = ['src' => self::RUSTY, 'uri' => 'at://' . self::RUSTY . '/app.bsky.actor.profile/self', 'val' => '!no-unauthenticated'];
        yield 'its own label' => [['did' => self::RUSTY, 'labels' => [$own]], true];
        yield 'among other labels' => [['did' => self::RUSTY, 'labels' => [['src' => self::RUSTY, 'val' => 'porn'], $own]], true];
        yield 'set by a labeller' => [['did' => self::RUSTY, 'labels' => [['src' => 'did:plc:labeller'] + $own]], false];
        yield 'another value' => [['did' => self::RUSTY, 'labels' => [['val' => '!hide'] + $own]], false];
        yield 'negated' => [['did' => self::RUSTY, 'labels' => [['neg' => true] + $own]], false];
        yield 'no labels' => [['did' => self::RUSTY], false];
        yield 'neither did nor src' => [['labels' => [['val' => '!no-unauthenticated']]], false];
    }
}
```

Create `backend/tests/Service/Social/HiddenProfiles/AppViewHiddenProfilesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\HiddenProfiles;

use App\Service\Bluesky\AppViewClient;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\AppViewHiddenProfiles;
use App\Service\Social\Model\HiddenProfileModel;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class AppViewHiddenProfilesTest extends TestCase
{
    private const string GET_PROFILES = 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfiles';
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';

    private StubFeedFetcher $fetcher;

    protected function setUp(): void
    {
        $this->fetcher = new StubFeedFetcher();
    }

    public function testAHiddenProfileIsFoundByItsDidAndItsHandle(): void
    {
        $this->answer(['rusty-17.bsky.social'], [self::rusty(['displayName' => 'Rusty'])]);

        $hidden = $this->hiddenProfiles()->hiddenAmong(['rusty-17.bsky.social']);

        $expected = new HiddenProfileModel(self::RUSTY, 'Rusty');
        self::assertEquals($expected, $hidden['rusty-17.bsky.social'] ?? null);
        self::assertEquals($expected, $hidden[self::RUSTY] ?? null);
    }

    public function testAPublicProfileIsNotAmongTheHidden(): void
    {
        $this->answer(['did:plc:bob'], [['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social']]);

        self::assertSame([], $this->hiddenProfiles()->hiddenAmong(['did:plc:bob']));
    }

    public function testAHiddenProfileWithoutADisplayNameIsNamedByItsHandle(): void
    {
        $this->answer([self::RUSTY], [self::rusty()]);

        self::assertSame('Rusty-17.bsky.social', $this->hiddenProfiles()->hiddenAmong([self::RUSTY])[self::RUSTY]->name);
    }

    public function testActorsAreAskedAPageAtATime(): void
    {
        $actors = array_map(static fn (int $index): string => sprintf('user%02d.bsky.social', $index), range(1, 26));
        $this->answer(\array_slice($actors, 0, 25), []);
        $this->answer(\array_slice($actors, 25), []);

        $this->hiddenProfiles()->hiddenAmong($actors);

        self::assertCount(2, $this->fetcher->fetchedUrls);
    }

    public function testNoActorsAskNothing(): void
    {
        self::assertSame([], $this->hiddenProfiles()->hiddenAmong([]));
        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    public function testAnUnreachableAppViewIsAnUnreachableNetwork(): void
    {
        $this->fetcher->willThrow(self::profilesUrl([self::RUSTY]), new FeedUnreachableException('timeout'));

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->hiddenProfiles()->hiddenAmong([self::RUSTY]);
    }

    public function testAMalformedAnswerIsAnUnreachableNetwork(): void
    {
        $this->fetcher->willReturnBody(self::profilesUrl([self::RUSTY]), '{"posts":[]}');

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->hiddenProfiles()->hiddenAmong([self::RUSTY]);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, mixed>
     */
    private static function rusty(array $overrides = []): array
    {
        return $overrides + [
            'did' => self::RUSTY,
            'handle' => 'Rusty-17.bsky.social',
            'labels' => [[
                'src' => self::RUSTY,
                'uri' => 'at://' . self::RUSTY . '/app.bsky.actor.profile/self',
                'val' => '!no-unauthenticated',
            ]],
        ];
    }

    /**
     * @param list<string>               $actors
     * @param list<array<string, mixed>> $profiles
     */
    private function answer(array $actors, array $profiles): void
    {
        $this->fetcher->willReturnBody(
            self::profilesUrl($actors),
            json_encode(['profiles' => $profiles], \JSON_THROW_ON_ERROR),
        );
    }

    /** @param list<string> $actors */
    private static function profilesUrl(array $actors): string
    {
        $pairs = array_map(static fn (string $actor): string => 'actors=' . rawurlencode($actor), $actors);

        return self::GET_PROFILES . '?' . implode('&', $pairs);
    }

    private function hiddenProfiles(): AppViewHiddenProfiles
    {
        $clock = new MockClock('2026-10-17 12:00:00', 'UTC');

        return new AppViewHiddenProfiles(
            new AppViewClient($this->fetcher, new HostThrottle(new ArrayAdapter(clock: $clock), $clock)),
        );
    }
}
```

In `ProfileFeedUrlTest`, append:

```php
    #[DataProvider('profilePages')]
    public function testABlueskyProfilePageOrItsFeedNamesItsActor(string $url, ?string $actor): void
    {
        self::assertSame($actor, ProfileFeedUrl::blueskyProfileActorOf($url));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function profilePages(): iterable
    {
        yield 'a page by handle' => ['https://bsky.app/profile/Rusty-17.bsky.social', 'rusty-17.bsky.social'];
        yield 'its feed' => ['https://bsky.app/profile/rusty-17.bsky.social/rss', 'rusty-17.bsky.social'];
        yield 'a trailing slash' => ['https://bsky.app/profile/rusty-17.bsky.social/', 'rusty-17.bsky.social'];
        yield 'a page by did' => ['http://bsky.app/profile/did:plc:hczfd45prvqtdrw77256syag', 'did:plc:hczfd45prvqtdrw77256syag'];
        yield 'a post' => ['https://bsky.app/profile/rusty-17.bsky.social/post/3abc', null];
        yield 'another host' => ['https://example.com/profile/rusty-17.bsky.social', null];
        yield 'no actor syntax' => ['https://bsky.app/profile/some%20name', null];
    }
```

(Add `use PHPUnit\Framework\Attributes\DataProvider;` if the file lacks it.)

Deletion checks:
- `profiles()`, one row per condition of `hidesFromLoggedOut`: drop the `src === did` comparison → "set by a labeller" true; drop the `val` comparison → "another value" true; drop the `neg` check → "negated" true; drop the `null !== $did` guard → "neither did nor src" compares `null === null` → true → red; "among other labels" guards that the loop does not stop at the first label; "no labels" guards the `false` fall-through.
- `AppViewHiddenProfiles`: drop the label check → the public profile appears; drop the handle key → the handle lookup is null; drop `array_chunk` → one call with 26 actors (1 fetch); drop the `[] === $actors` early return → a call is made (the stub throws `LogicException` for the unstubbed URL); drop either `catch` type → the exception escapes un-wrapped.
- `profilePages`: drop `(?:/rss)?` → "its feed" null; drop `/?` → "a trailing slash" null; drop the host check → "another host" yields an actor; drop the `$` anchor → "a post" yields an actor; drop `BlueskyActor::isValid` → "no actor syntax" yields `some name`; drop the lower-casing → "a page by handle" keeps capitals.

- [ ] **Step 2: Run the tests and see them fail.** `cd backend && php bin/phpunit tests/Service/Social/Support/BlueskyProfileLabelsTest.php tests/Service/Social/HiddenProfiles tests/Service/Social/Support/ProfileFeedUrlTest.php`. Expected: classes and method not found.

- [ ] **Step 3: Implement.**

`backend/src/Service/Social/Support/BlueskyProfileLabels.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;

final class BlueskyProfileLabels
{
    private const string HIDDEN_FROM_LOGGED_OUT = '!no-unauthenticated';

    /** Only the profile's own label is its choice: a labeller may put the same value on someone else. */
    public static function hidesFromLoggedOut(JsonNodeModel $profile): bool
    {
        $did = $profile->string('did');
        foreach ($profile->nodes('labels') as $label) {
            if (
                self::HIDDEN_FROM_LOGGED_OUT === $label->string('val')
                && null !== $did
                && $did === $label->string('src')
                && !$label->isTrue('neg')
            ) {
                return true;
            }
        }

        return false;
    }

    private function __construct()
    {
    }
}
```

`backend/src/Service/Social/Model/HiddenProfileModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** A Bluesky profile that hides its posts from logged-out readers, as the author feed that reads it is named. */
final readonly class HiddenProfileModel
{
    public function __construct(
        public string $did,
        public string $name,
    ) {
    }
}
```

`backend/src/Service/Social/HiddenProfiles/HiddenProfilesInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\HiddenProfiles;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\HiddenProfileModel;

interface HiddenProfilesInterface
{
    /**
     * @param list<string> $actors DIDs or handles
     *
     * @return array<string, HiddenProfileModel> the hidden ones among $actors, by DID and by lower-cased handle
     *
     * @throws SocialNetworkUnreachableException when Bluesky cannot say
     */
    public function hiddenAmong(array $actors): array;
}
```

`backend/src/Service/Social/HiddenProfiles/AppViewHiddenProfiles.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\HiddenProfiles;

use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Support\BlueskyAuthor;
use App\Service\Social\Support\BlueskyProfileLabels;

final readonly class AppViewHiddenProfiles implements HiddenProfilesInterface
{
    public function __construct(private AppViewClient $appView)
    {
    }

    public function hiddenAmong(array $actors): array
    {
        $hidden = [];
        foreach (array_chunk(array_values(array_unique($actors)), AppViewClient::URIS_PER_CALL) as $batch) {
            foreach ($this->profilesOf($batch) as $key => $profile) {
                if (BlueskyProfileLabels::hidesFromLoggedOut($profile)) {
                    $hidden[$key] = self::modelOf($profile);
                }
            }
        }

        return $hidden;
    }

    /**
     * @param list<string> $batch
     *
     * @return array<string, JsonNodeModel>
     */
    private function profilesOf(array $batch): array
    {
        try {
            return $this->appView->profiles($batch);
        } catch (FetchException | AppViewAnswerException $exception) {
            throw new SocialNetworkUnreachableException('Bluesky could not say whether a profile is hidden.', 0, $exception);
        }
    }

    private static function modelOf(JsonNodeModel $profile): HiddenProfileModel
    {
        $did = (string) $profile->string('did');

        return new HiddenProfileModel($did, BlueskyAuthor::name($profile) ?? $did);
    }
}
```

(`AppViewClient::profiles()` keys only views that carry a `did`, so the `(string)` cast never meets `null`; if PHPStan reads it otherwise, assert it with `?? throw new \LogicException(...)` instead.)

`ProfileFeedUrl` — add the constant, the reader, and share the actor normalisation with `blueskyIdentity()`:

```php
    private const string BLUESKY_PROFILE_PAGE = '#^/profile/([^/@]+)(?:/rss)?/?$#';
```

```php
    /** @return string|null the DID or lower-cased handle a bsky.app profile page or its feed names; null otherwise */
    public static function blueskyProfileActorOf(string $url): ?string
    {
        $parts = self::webPartsOf($url);
        if (
            null === $parts
            || BlueskyWeb::HOST !== $parts['host']
            || 1 !== preg_match(self::BLUESKY_PROFILE_PAGE, $parts['path'], $match)
        ) {
            return null;
        }
        $actor = self::actorIn($match[1]);

        return BlueskyActor::isValid($actor) ? $actor : null;
    }
```

```php
    private static function blueskyIdentity(string $host, string $path): ?string
    {
        if (BlueskyWeb::HOST !== $host || 1 !== preg_match(self::BLUESKY_PATH, $path, $match)) {
            return null;
        }

        return self::actorIn($match[1]);
    }

    private static function actorIn(string $segment): string
    {
        $actor = rawurldecode($segment);

        return self::isDid($actor) ? $actor : strtolower($actor);
    }
```

`backend/tests/Support/StubHiddenProfiles.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Model\HiddenProfileModel;

/** Knows no hidden profile until a test hides one, so no test asks the public AppView. */
final class StubHiddenProfiles implements HiddenProfilesInterface
{
    public ?SocialNetworkUnreachableException $failure = null;

    /** @var list<list<string>> */
    public array $asked = [];

    /** @var array<string, HiddenProfileModel> */
    private array $hidden = [];

    public function hide(HiddenProfileModel $profile, string ...$handles): void
    {
        $this->hidden[$profile->did] = $profile;
        foreach ($handles as $handle) {
            $this->hidden[strtolower($handle)] = $profile;
        }
    }

    public function hiddenAmong(array $actors): array
    {
        $this->asked[] = $actors;
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return array_intersect_key($this->hidden, array_flip($actors));
    }
}
```

`backend/config/services_test.yaml` — after the `StubRerankClient` block:

```yaml
    App\Tests\Support\StubHiddenProfiles: ~

    # Every test asks StubHiddenProfiles, never the public AppView, whether a Bluesky profile hides itself.
    App\Service\Social\HiddenProfiles\HiddenProfilesInterface:
        alias: App\Tests\Support\StubHiddenProfiles
```

In production the interface has one implementation, so Symfony aliases it automatically (as `PendingEmbedInterface`).

- [ ] **Step 4: Run the tests and see them pass.** Same command. Expected: PASS. Also run `php bin/phpunit tests/Service/Social/Support/ProfileFeedUrlTest.php` in full: the `blueskyIdentity` refactor must keep every old row green.

- [ ] **Step 5: Commit.** `composer cs`, then:

```bash
git add backend/src/Service/Social backend/tests/Service/Social backend/tests/Support/StubHiddenProfiles.php backend/config/services_test.yaml
git commit -m "feat(#1667): tell a Bluesky profile hidden from logged-out readers by its own label"
```

---

### Task 4: Create an author feed as a normal subscription

**Files:**
- Create: `backend/src/Service/Social/AuthorFeeds.php`
- Modify: `backend/src/Repository/SocialAccountRepository.php`
- Test: create `backend/tests/Service/Social/AuthorFeedsTest.php`; modify `backend/tests/Repository/SocialAccountRepositoryTest.php`

**Interfaces:**
- Produces: `AuthorFeeds::create(SocialAccount $account, HiddenProfileModel $profile, list<Tag> $tags): Subscription` — throws `SubscriptionLimitReachedException` (nothing written, entity manager open) and `AlreadySubscribedException`. Its own creation path: `PrivateFeeds::create` stays untouched (it forces both inclusion flags off, which an author feed must not).
- Produces: `SocialAccountRepository::findReadyActingOnNetwork(User $user, SocialNetwork $network): ?SocialAccount` — the default first, among accounts still `Connected`.

- [ ] **Step 1: Write the failing tests.**

Append to `SocialAccountRepositoryTest` (read its helpers first; if it has none for users, use `UserFactory` as `PrivateFeedsTest::setUp()` does):

```php
    public function testTheReadyActingAccountIsTheDefaultAmongTheConnectedOnes(): void
    {
        $user = $this->userNamed('ready-acting@example.test');
        $older = SocialAccounts::blueskyOf($user);
        $default = new SocialAccount(
            $user,
            SocialNetwork::Bluesky,
            'did:plc:second',
            '@second.bsky.social',
            'https://pds.example.test',
            SocialAccounts::cipher()->seal($user->requireId(), SocialAccounts::APP_PASSWORD),
            'mnop',
            new \DateTimeImmutable('2026-10-13 10:00:00'),
        );
        $default->becomeDefault();
        $this->entityManager->persist($older);
        $this->entityManager->persist($default);
        $this->entityManager->flush();

        self::assertSame($default, $this->repository()->findReadyActingOnNetwork($user, SocialNetwork::Bluesky));

        $default->markNeedsReconnect();
        $this->entityManager->flush();

        self::assertSame($older, $this->repository()->findReadyActingOnNetwork($user, SocialNetwork::Bluesky));
        self::assertNull($this->repository()->findReadyActingOnNetwork($user, SocialNetwork::Mastodon));
    }
```

(`userNamed()` and `repository()` stand for the test's own helpers; name them as the file does.)

Create `backend/tests/Service/Social/AuthorFeedsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Social\AuthorFeeds;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Tests\DbTestCase;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthorFeedsTest extends DbTestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('author-feeds@example.test');
    }

    public function testAHiddenProfileBecomesATaggedAuthorFeedInAllItemsAndForYou(): void
    {
        $account = $this->account();
        $tag = $this->tag('People');

        $subscription = $this->authorFeeds()->create($account, self::rusty(), [$tag]);

        $feed = $subscription->getFeed();
        self::assertSame(sprintf('social:bluesky:%d:author:%s', $account->requireId(), self::RUSTY), $feed->getUrl());
        self::assertSame(SourceFormat::BLUESKY_API, $feed->getSourceFormat());
        self::assertSame('Rusty', $feed->getTitle());
        self::assertSame('https://bsky.app/profile/' . self::RUSTY, $feed->getSiteUrl());
        self::assertTrue($subscription->isIncludeInAllItems());
        self::assertTrue($subscription->isIncludeInForYou());
        self::assertSame(['People'], array_map(static fn (Tag $each): string => $each->getName(), $subscription->getTags()->toArray()));
        $socialFeed = SocialFeeds::repository($this->entityManager)->findOneFor($account, SocialFeedKind::Author, self::RUSTY);
        self::assertSame($feed, $socialFeed?->getFeed());
    }

    public function testSubscribingTheSameProfileTwiceIsRefused(): void
    {
        $account = $this->account();
        $this->authorFeeds()->create($account, self::rusty(), []);

        $this->expectException(AlreadySubscribedException::class);
        $this->authorFeeds()->create($account, self::rusty(), []);
    }

    public function testAFullAccountGetsNoAuthorFeedAndTheEntityManagerStaysOpen(): void
    {
        $this->user->setMaxSubscriptions(0);
        $account = $this->account();

        try {
            $this->authorFeeds()->create($account, self::rusty(), []);
            self::fail('The subscription cap must hold for author feeds too.');
        } catch (SubscriptionLimitReachedException) {
            self::assertTrue($this->entityManager->isOpen());
            self::assertSame(0, $this->entityManager->getRepository(Feed::class)->count([]));
            self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
        }
    }

    public function testDisconnectingTheAccountRemovesItsAuthorFeeds(): void
    {
        $account = $this->account();
        $this->authorFeeds()->create($account, self::rusty(), []);
        $privateFeeds = PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, new MockClock());

        self::assertSame(1, $privateFeeds->removeAllOf($account));
        $this->entityManager->clear();
        self::assertSame(0, $this->entityManager->getRepository(Subscription::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
    }

    private function authorFeeds(): AuthorFeeds
    {
        $authorFeeds = self::getContainer()->get(AuthorFeeds::class);
        self::assertInstanceOf(AuthorFeeds::class, $authorFeeds);

        return $authorFeeds;
    }

    private function account(): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
    }

    private function tag(string $name): Tag
    {
        $tag = new Tag($this->user, $name);
        $tag->setPosition(0);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private static function rusty(): HiddenProfileModel
    {
        return new HiddenProfileModel(self::RUSTY, 'Rusty');
    }
}
```

Deletion checks:
- `findReadyActingOnNetwork`: drop the `status` criterion → after `markNeedsReconnect()` the default is still returned → red; drop the `isDefault` ordering → the older account wins the first assertion → red.
- Author creation: drop `setSiteUrl` → site URL null; drop the `SocialFeed` persist → `findOneFor` null; pass `[]` instead of `$tags` → no tag; any `setIncludeIn…(false)` copied from `PrivateFeeds` → the flag assertions fail.
- Duplicate: removing nothing in `AuthorFeeds` makes this fail; it guards that `SubscriptionCreator`'s duplicate check is reached (not bypassed by a custom path).
- Cap: replace the manual `beginTransaction/rollback` with `wrapInTransaction` → the entity manager closes → `isOpen()` false → red.
- Disconnect: guards that `SocialFeedRepository::subscriptionsOf()` covers the new kind (it filters by account, not kind) — a future kind filter there turns it red.

- [ ] **Step 2: Run the tests and see them fail.** `cd backend && php bin/phpunit tests/Service/Social/AuthorFeedsTest.php tests/Repository/SocialAccountRepositoryTest.php`. Expected: class `AuthorFeeds` not found; undefined method `findReadyActingOnNetwork`.

- [ ] **Step 3: Implement.**

`SocialAccountRepository` — share the order with the existing method:

```php
    private const array ACTING_ORDER = ['isDefault' => 'DESC', 'connectedAt' => 'ASC', 'id' => 'ASC'];
```

```php
    public function findActingOnNetwork(User $user, SocialNetwork $network): ?SocialAccount
    {
        return $this->findOneBy(['user' => $user, 'network' => $network], self::ACTING_ORDER);
    }

    /** The account that acts on $network among those still connected: one that needs a reconnect cannot read. */
    public function findReadyActingOnNetwork(User $user, SocialNetwork $network): ?SocialAccount
    {
        return $this->findOneBy(
            ['user' => $user, 'network' => $network, 'status' => SocialAccountStatus::Connected],
            self::ACTING_ORDER,
        );
    }
```

(add `use App\Enum\SocialAccountStatus;`).

`backend/src/Service/Social/AuthorFeeds.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Repository\SocialFeedRepository;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Support\PrivateFeedSite;
use App\Service\Social\Support\SocialFeedUrl;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\SubscriptionCreator;
use Doctrine\ORM\EntityManagerInterface;

/** A hidden profile read through a Bluesky account, subscribed like any feed: tagged, in All items and For You. */
final readonly class AuthorFeeds
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private SubscriptionCreator $subscriptionCreator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<Tag> $tags
     *
     * @throws SubscriptionLimitReachedException with nothing written and the entity manager still open
     * @throws AlreadySubscribedException
     */
    public function create(SocialAccount $account, HiddenProfileModel $profile, array $tags): Subscription
    {
        $author = PrivateFeedModel::author($profile->did, $profile->name);
        $this->entityManager->beginTransaction();
        try {
            $subscription = $this->subscriptionCreator->create(
                $account->getUser(),
                SocialFeedUrl::of($account, $author),
                SourceFormat::BLUESKY_API,
                $tags,
                $author->name,
            );
            $this->recordAuthor($account, $subscription->getFeed(), $author);
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        return $subscription;
    }

    private function recordAuthor(SocialAccount $account, Feed $feed, PrivateFeedModel $author): void
    {
        $feed->setSiteUrl(PrivateFeedSite::of($account, $author));
        if (null === $this->socialFeeds->findOneFor($account, SocialFeedKind::Author, $author->remoteId)) {
            $this->entityManager->persist(new SocialFeed($account, $feed, SocialFeedKind::Author, $author->remoteId));
        }
        $this->entityManager->flush();
    }
}
```

The body stays in `create()` (no `createOnce()` like `PrivateFeeds`): `$tags` then passes through two methods only, below phptramp's warning.

- [ ] **Step 4: Run the tests and see them pass.** Same command. Expected: PASS.

- [ ] **Step 5: Commit.** `composer cs`, then:

```bash
git add backend/src/Service/Social/AuthorFeeds.php backend/src/Repository/SocialAccountRepository.php backend/tests/Service/Social/AuthorFeedsTest.php backend/tests/Repository/SocialAccountRepositoryTest.php
git commit -m "feat(#1667): create an author feed as a tagged subscription read by the default account"
```

---

### Task 5: Present an author feed as a person

**Files:**
- Modify: `backend/src/Http/SubscriptionJson.php`, `backend/src/Repository/NetworkScopeDql.php`, `backend/src/Repository/FeedRepository.php`, `backend/src/Service/Social/ProfileImageSweep.php`, `backend/src/Service/Social/ImportCandidates.php`
- Test: `backend/tests/Http/SubscriptionJsonTest.php`, `backend/tests/Repository/FeedRepositoryNetworkScopeTest.php`, `backend/tests/Repository/FeedRepositoryProfileImageDueTest.php`, `backend/tests/Service/Social/ProfileImageSweepTest.php`, `backend/tests/Service/Social/ImportCandidatesTest.php`

**Interfaces:**
- `SubscriptionJson` reports an author feed as `{network: 'bluesky', accountId: null, kind: 'profile'}`. `accountId` stays `null` for every profile: that is the frontend's `SocialFeedRefDto` contract (`{kind:'profile'; accountId:null}`), and every consumer (`isProfileFeed`, `isPrivateFeed`, `feedAvatarUrl`, `list-heading.service.ts`'s `sharedNetworkHandle`, `inNetworkTarget`) then treats it as a person with no TypeScript change.
- `NetworkScopeDql`'s profile scope (the network "People" view, its entries, counts and the avatar sweep's query) includes author feeds, by `SocialFeedUrl::AUTHOR_FEEDS`.
- The avatar sweep and the import picker read an author feed's DID via `ProfileIdentity::of()`.
- Nothing to change (verified): `FeedNetworkListener`/`SocialFeedClassifier` set network `bluesky` from the private address; `ActingAccountChooser` acts with the feed's own account; `EntryJson::posterOf` shows the post's author for a private format; `BlueskyPendingEmbed` skips `bluesky-api` (the post view rides along); OPML and backups exclude `SOCIAL` formats (`SubscriptionRepository::findExportableForUserWithTags`, `PrivateFeedRefusal`).

- [ ] **Step 1: Write the failing tests.**

`SubscriptionJsonTest` — append:

```php
    public function testAnAuthorFeedIsAProfileFeedOfItsNetwork(): void
    {
        $feed = new Feed('social:bluesky:12:author:did:plc:rusty');
        $feed->setSourceFormat(SourceFormat::BLUESKY_API);
        $feed->classifyAs(SocialNetwork::Bluesky);

        self::assertSame(
            ['network' => 'bluesky', 'accountId' => null, 'kind' => 'profile'],
            SubscriptionJson::one($this->subscriptionTo($feed))['social'],
        );
    }
```

`FeedRepositoryNetworkScopeTest` — append:

```php
    public function testAProfileScopeTakesAnAuthorFeedForAPerson(): void
    {
        $owner = $this->user('network-scope-authors@example.com');
        $author = $this->fixtures()->subscribe(
            $owner,
            'social:bluesky:1:author:did:plc:rusty',
            SourceFormat::BLUESKY_API,
        )->getFeed();
        $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::blueskyHome(1), SourceFormat::BLUESKY_API);

        $criteria = $this->criteria($owner, NetworkScope::profiles(SocialNetwork::Bluesky));

        self::assertSame([$author->requireId()], $this->idsOf($this->feeds()->findDue($criteria, 50)));
    }
```

`FeedRepositoryProfileImageDueTest` — append:

```php
    public function testAnAuthorFeedIsDueLikeAProfileFeed(): void
    {
        $author = new Feed('social:bluesky:1:author:did:plc:rusty');
        $author->setSourceFormat(SourceFormat::BLUESKY_API);
        $this->entityManager->persist($author);
        $this->entityManager->persist(
            new Subscription($this->subscriber(), $author, new \DateTimeImmutable(self::CHECKED_BEFORE)),
        );
        $this->entityManager->flush();

        $due = $this->feeds()->findBlueskyProfileFeedsWithImageDue(new \DateTimeImmutable(self::CHECKED_BEFORE), 10);

        self::assertSame([$author->requireId()], array_map(static fn (Feed $feed): int => $feed->requireId(), $due));
    }
```

`ProfileImageSweepTest` — append:

```php
    public function testAnAuthorFeedIsPicturedByTheDidItReads(): void
    {
        $author = new Feed('social:bluesky:1:author:did:plc:rusty');
        $author->setSourceFormat(SourceFormat::BLUESKY_API);
        $this->persistSubscribed($author);
        $this->answer(['did:plc:rusty'], [
            ['did' => 'did:plc:rusty', 'handle' => 'rusty.bsky.social', 'avatar' => 'https://cdn.example/rusty.jpg'],
        ]);

        $this->sweep(1);

        self::assertSame('https://cdn.example/rusty.jpg', $author->getImageUrl());
    }
```

(add `use App\Enum\SourceFormat;`).

`ImportCandidatesTest` — append:

```php
    public function testAFollowReadThroughAnAuthorFeedReadsAsSubscribed(): void
    {
        $account = $this->account();
        $author = $this->subscribe(sprintf('social:bluesky:%d:author:did:plc:rusty', $account->requireId()));
        $network = new StubSocialCandidates(new NetworkCandidatesModel(
            [self::follow('did:plc:rusty', '@rusty-17.bsky.social')],
            [],
            [],
            false,
        ));

        $follows = $this->importCandidates($network)->forAccount($account)->follows;

        self::assertSame([['@rusty-17.bsky.social', 'subscribed', $author->requireId()]], self::summary($follows));
    }
```

Deletion checks:
- `SubscriptionJson`: drop the `Author` comparison → `accountId: 12, kind: 'author'` → red.
- Network scope: drop the `OR … LIKE :authorFeeds` → the author feed is excluded → `[]` → red; the Home row guards that the `NOT IN` half stays (Home must remain excluded).
- Image due: revert `findBlueskyProfileFeedsWithImageDue` to its own `NOT IN` → `[]` → red.
- Sweep: revert `actorsOf` to `ProfileFeedUrl::identityOf` → the author feed has no actor → the stub sees no request (`LogicException` is not raised, but the image stays null) → red.
- Import candidates: revert `profileFeedsOf` to `ProfileFeedUrl::identityOf` → the follow reads `new` → red.

- [ ] **Step 2: Run the tests and see them fail.** `cd backend && php bin/phpunit tests/Http/SubscriptionJsonTest.php tests/Repository/FeedRepositoryNetworkScopeTest.php tests/Repository/FeedRepositoryProfileImageDueTest.php tests/Service/Social/ProfileImageSweepTest.php tests/Service/Social/ImportCandidatesTest.php`. Expected: the five new tests fail.

- [ ] **Step 3: Implement.**

`SubscriptionJson::social()`:

```php
    /**
     * A private feed's synthetic URL also names its account and kind. Any other networked feed is a profile feed, and
     * so is an author feed, which follows one person.
     *
     * @return array{network: string, accountId: int|null, kind: string}|null
     */
    private static function social(Feed $feed): ?array
    {
        $network = $feed->getNetwork();
        if (null === $network) {
            return null;
        }
        $address = SocialFeedClassifier::privateAddressOf($feed);
        if (null === $address || SocialFeedKind::Author === $address->kind) {
            return ['network' => $network->value, 'accountId' => null, 'kind' => self::PROFILE_KIND];
        }

        return ['network' => $network->value, 'accountId' => $address->accountId, 'kind' => $address->kind->value];
    }
```

(add `use App\Enum\SocialFeedKind;`).

`NetworkScopeDql`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\NetworkFeedKind;
use App\Enum\SourceFormat;
use App\Service\Social\Support\SocialFeedUrl;
use Doctrine\ORM\QueryBuilder;

/** A profile feed is a networked feed read without an account, or an author feed, which follows one person. */
final class NetworkScopeDql
{
    public static function restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void
    {
        $qb->andWhere(\sprintf('%s.network = :network', $feedAlias))->setParameter('network', $scope->network);
        if ($scope->feedKind === NetworkFeedKind::Profile) {
            $qb->andWhere(\sprintf('(%1$s.sourceFormat NOT IN (:socialFormats) OR %1$s.url LIKE :authorFeeds)', $feedAlias))
                ->setParameter('socialFormats', SourceFormat::SOCIAL)
                ->setParameter('authorFeeds', SocialFeedUrl::AUTHOR_FEEDS);
        }
    }

    private function __construct()
    {
    }
}
```

`FeedRepository::findBlueskyProfileFeedsWithImageDue()` — one home for "a profile feed":

```php
    public function findBlueskyProfileFeedsWithImageDue(\DateTimeImmutable $checkedBefore, int $limit): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere(sprintf('EXISTS (SELECT s.id FROM %s s WHERE s.feed = f)', Subscription::class))
            ->andWhere('f.imageCheckedAt IS NULL OR f.imageCheckedAt < :checkedBefore')
            ->setParameter('checkedBefore', $checkedBefore)
            ->orderBy('f.imageCheckedAt', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->setMaxResults($limit);
        NetworkScopeDql::restrict($qb, 'f', NetworkScope::profiles(SocialNetwork::Bluesky));

        /** @var list<Feed> $feeds */
        $feeds = $qb->getQuery()->getResult();

        return $feeds;
    }
```

Change its docblock first line to `Subscribed Bluesky profile and author feeds whose picture …`. Drop `SourceFormat` from the `use` list only if nothing else in the file uses it.

`ProfileImageSweep` — class docblock `Fills and refreshes the pictures of Bluesky profile and author feeds, whose RSS or timeline carries none, …`; in `actorsOf()`:

```php
            $actor = ProfileIdentity::of(SocialNetwork::Bluesky, $feed->getUrl());
```

(replace the `ProfileFeedUrl` import with `use App\Service\Social\Support\ProfileIdentity;`).

`ImportCandidates::profileFeedsOf()`:

```php
            $identity = ProfileIdentity::of($account->getNetwork(), $subscription['feedUrl']);
```

(replace `use App\Service\Social\Support\ProfileFeedUrl;` with `use App\Service\Social\Support\ProfileIdentity;`). `subscribedCollectionsOf()` also returns author rows; their `author:<did>` keys are never looked up by a collection, so nothing changes there.

- [ ] **Step 4: Run the tests and see them pass.** Same command, plus the whole `tests/Repository/SubscriptionScopeRepositoryTest.php` and `tests/Repository/EntryQueryTest.php` (other `NetworkScopeDql` callers). Expected: PASS.

- [ ] **Step 5: Commit.** `composer cs`, then:

```bash
git add backend/src/Http/SubscriptionJson.php backend/src/Repository/NetworkScopeDql.php backend/src/Repository/FeedRepository.php backend/src/Service/Social/ProfileImageSweep.php backend/src/Service/Social/ImportCandidates.php backend/tests
git commit -m "feat(#1667): present an author feed as the person it follows"
```

---

### Task 6: Subscribe a hidden profile before discovery runs

**Files:**
- Create: `backend/src/Service/Subscription/SubscribeRoute/SubscribeRouteInterface.php`, `backend/src/Service/Subscription/Model/SubscribeRequestModel.php`, `backend/src/Service/Social/HiddenProfileSubscribeRoute.php`, `backend/src/Service/Social/Exception/BlueskyProfileHiddenException.php`
- Modify: `backend/src/Service/Subscription/SubscriptionService.php`, `backend/config/services.yaml`, `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php`
- Test: `backend/tests/Service/Subscription/SubscriptionServiceTest.php`, `backend/tests/Service/Subscription/UnsubscribeAllTest.php`; create `backend/tests/Service/Social/HiddenProfileSubscribeRouteTest.php`, `backend/tests/Http/Problem/ExceptionProblems/SocialProblemsTest.php`, `backend/tests/Controller/Api/SubscriptionHiddenProfileTest.php`

**Interfaces:**
- Produces: `SubscribeRouteInterface::subscribe(SubscribeRequestModel $request): ?SubscribeOutcomeModel` — `null` when the URL is not the route's to take; tagged `app.subscribe_route`, collected by `SubscriptionService` with `#[AutowireIterator]` as its **last** constructor parameter (`iterable $subscribeRoutes`). Subscription owns the interface; Social implements it, so the module dependency stays Social → Subscription.
- Produces: `SubscribeRequestModel(User $user, string $url, list<Tag> $tags)` — the request travels as one object, so the tags are no tramp data.
- Produces: `BlueskyProfileHiddenException` → 422 `bluesky_profile_hidden`.
- The routes run after the two verbatim formats (`scraped`, `wp-json`) and before discovery. Preview (`/api/feeds/preview`, `FeedPreviewService`) is **not** changed: the route answers the typed URL before discovery ever offers a candidate, so the dialog never previews a hidden profile's `/rss`; a hand-made preview of it keeps today's "could not be loaded".

- [ ] **Step 1: Write the failing tests.**

`SubscriptionServiceTest` — change `service()` to take the routes:

```php
    /** @param list<SubscribeRouteInterface> $routes */
    private function service(FeedDiscoveryInterface $discovery, array $routes = []): SubscriptionService
```

and pass `$routes` as the new last constructor argument after `$this->entityManager`. Append:

```php
    private function discoveryNeverAsked(): FeedDiscoveryInterface
    {
        return new class () implements FeedDiscoveryInterface {
            public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResultModel
            {
                throw new \LogicException('Discovery must not run for a URL a route took.');
            }
        };
    }

    private function route(?SubscribeOutcomeModel $outcome): SubscribeRouteInterface
    {
        return new class ($outcome) implements SubscribeRouteInterface {
            /** @var list<SubscribeRequestModel> */
            public array $requests = [];

            public function __construct(private readonly ?SubscribeOutcomeModel $outcome)
            {
            }

            public function subscribe(SubscribeRequestModel $request): ?SubscribeOutcomeModel
            {
                $this->requests[] = $request;

                return $this->outcome;
            }
        };
    }

    public function testARouteThatTakesTheUrlAnswersInsteadOfDiscovery(): void
    {
        $user = $this->factory()->create('routed@example.com');
        $tag = new Tag($user, 'People');
        $taken = SubscribeOutcomeModel::candidates([]);
        $route = $this->route($taken);

        $outcome = $this->service($this->discoveryNeverAsked(), [$route])
            ->subscribe($user, 'https://bsky.app/profile/rusty-17.bsky.social', null, [$tag]);

        self::assertSame($taken, $outcome);
        self::assertEquals([new SubscribeRequestModel($user, 'https://bsky.app/profile/rusty-17.bsky.social', [$tag])], $route->requests);
    }

    public function testADecliningRouteLeavesTheUrlToDiscovery(): void
    {
        $user = $this->factory()->create('declined@example.com');

        $outcome = $this->service(
            $this->discoveryReturning($this->discovered('https://example.com/feed.xml')),
            [$this->route(null)],
        )->subscribe($user, 'https://example.com/feed');

        self::assertSame('https://example.com/feed.xml', $outcome->subscription?->getFeed()->getUrl());
    }

    public function testAVerbatimFormatNeverAsksTheRoutes(): void
    {
        $user = $this->factory()->create('verbatim-route@example.com');
        $route = $this->route(SubscribeOutcomeModel::candidates([]));

        $outcome = $this->service($this->discoveryNeverAsked(), [$route])
            ->subscribe($user, 'https://example.com/wp-json/wp/v2/posts', SourceFormat::WP_JSON);

        self::assertNotNull($outcome->subscription);
        self::assertSame([], $route->requests);
    }
```

(add `use App\Service\Subscription\Model\SubscribeOutcomeModel;`, `use App\Service\Subscription\Model\SubscribeRequestModel;`, `use App\Service\Subscription\SubscribeRoute\SubscribeRouteInterface;`).

`UnsubscribeAllTest` — add `[],` as the last argument of `new SubscriptionService(…)`.

Create `backend/tests/Service/Social/HiddenProfileSubscribeRouteTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Social\AuthorFeeds;
use App\Service\Social\Exception\BlueskyProfileHiddenException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfileSubscribeRoute;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Subscription\Model\SubscribeRequestModel;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubHiddenProfiles;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HiddenProfileSubscribeRouteTest extends DbTestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';
    private const string PAGE = 'https://bsky.app/profile/rusty-17.bsky.social';

    private User $user;
    private StubHiddenProfiles $hiddenProfiles;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('hidden-route@example.test');
        $this->hiddenProfiles = new StubHiddenProfiles();
        $this->hiddenProfiles->hide(new HiddenProfileModel(self::RUSTY, 'Rusty'), 'rusty-17.bsky.social');
        $this->logger = new RecordingLogger();
    }

    public function testAHiddenProfileIsSubscribedAsAnAuthorFeedOfTheDefaultAccount(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));

        $outcome = $this->route()->subscribe(new SubscribeRequestModel($this->user, self::PAGE . '/rss', []));

        self::assertSame(
            sprintf('social:bluesky:%d:author:%s', $account->requireId(), self::RUSTY),
            $outcome?->subscription?->getFeed()->getUrl(),
        );
        self::assertSame([['rusty-17.bsky.social']], $this->hiddenProfiles->asked);
    }

    public function testAHiddenProfileWithoutAConnectedAccountIsRefusedWithTheWayOut(): void
    {
        $this->expectException(BlueskyProfileHiddenException::class);
        $this->expectExceptionMessage(
            'This profile is only visible to logged-in Bluesky users. Connect a Bluesky account in Settings → Social'
            . ' Media to subscribe.',
        );

        $this->route()->subscribe(new SubscribeRequestModel($this->user, self::PAGE, []));
    }

    public function testAnAccountThatNeedsAReconnectCannotReadTheProfile(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
        $account->markNeedsReconnect();
        $this->entityManager->flush();

        $this->expectException(BlueskyProfileHiddenException::class);
        $this->route()->subscribe(new SubscribeRequestModel($this->user, self::PAGE, []));
    }

    public function testAPublicProfileIsLeftToDiscovery(): void
    {
        self::assertNull(
            $this->route()->subscribe(new SubscribeRequestModel($this->user, 'https://bsky.app/profile/bob.bsky.social', [])),
        );
    }

    public function testAnyOtherUrlIsLeftToDiscoveryUnasked(): void
    {
        self::assertNull($this->route()->subscribe(new SubscribeRequestModel($this->user, 'https://example.com/feed', [])));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAnUnansweredLabelCheckIsLeftToDiscoveryAndLogged(): void
    {
        $this->hiddenProfiles->failure = new SocialNetworkUnreachableException('down');

        self::assertNull($this->route()->subscribe(new SubscribeRequestModel($this->user, self::PAGE, [])));
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
    }

    private function route(): HiddenProfileSubscribeRoute
    {
        $socialAccounts = self::getContainer()->get(SocialAccountRepository::class);
        self::assertInstanceOf(SocialAccountRepository::class, $socialAccounts);
        $authorFeeds = self::getContainer()->get(AuthorFeeds::class);
        self::assertInstanceOf(AuthorFeeds::class, $authorFeeds);

        return new HiddenProfileSubscribeRoute($this->hiddenProfiles, $socialAccounts, $authorFeeds, $this->logger);
    }
}
```

Create `backend/tests/Http/Problem/ExceptionProblems/SocialProblemsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\SocialProblems;
use App\Service\Social\Exception\BlueskyProfileHiddenException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class SocialProblemsTest extends TestCase
{
    public function testAHiddenProfileIsAnUnprocessableRequestThatSaysWhatToDo(): void
    {
        $resolved = (new SocialProblems())->resolve(new BlueskyProfileHiddenException('Connect a Bluesky account.'));

        self::assertNotNull($resolved);
        self::assertSame('bluesky_profile_hidden', $resolved->problem->type);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $resolved->problem->status);
        self::assertSame('Connect a Bluesky account.', $resolved->problem->detail);
    }
}
```

(Read `ApiProblem` first; if its detail property has another name, use that.)

Create `backend/tests/Controller/Api/SubscriptionHiddenProfileTest.php` — the API end to end, AppView stubbed by `StubHiddenProfiles`, no PDS call at subscribe time:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\Social\Model\HiddenProfileModel;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubHiddenProfiles;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionHiddenProfileTest extends WebTestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $hiddenProfiles = self::getContainer()->get(StubHiddenProfiles::class);
        self::assertInstanceOf(StubHiddenProfiles::class, $hiddenProfiles);
        $hiddenProfiles->hide(new HiddenProfileModel(self::RUSTY, 'Rusty'), 'rusty-17.bsky.social');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $this->user = (new UserFactory($this->entityManager(), $hasher))->create('hidden-profile-api@example.test');
    }

    public function testAHiddenProfileIsSubscribedThroughTheConnectedAccountWithItsTags(): void
    {
        $account = SocialAccounts::blueskyOf($this->user);
        $tag = new Tag($this->user, 'People');
        $tag->setPosition(0);
        $this->entityManager()->persist($account);
        $this->entityManager()->persist($tag);
        $this->entityManager()->flush();

        $this->post(['url' => 'https://bsky.app/profile/rusty-17.bsky.social', 'tagIds' => [$tag->requireId()]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $subscription = $this->json()['subscription'] ?? null;
        self::assertIsArray($subscription);
        self::assertSame(sprintf('social:bluesky:%d:author:%s', $account->requireId(), self::RUSTY), $subscription['feedUrl']);
        self::assertSame('Rusty', $subscription['title']);
        self::assertSame(['network' => 'bluesky', 'accountId' => null, 'kind' => 'profile'], $subscription['social']);
        self::assertTrue($subscription['includeInAllItems']);
        self::assertTrue($subscription['includeInForYou']);
        self::assertIsArray($subscription['tags']);
        self::assertCount(1, $subscription['tags']);
    }

    public function testWithoutAConnectedAccountTheAnswerSaysHowToSubscribe(): void
    {
        $this->post(['url' => 'https://bsky.app/profile/rusty-17.bsky.social/rss']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = $this->json();
        self::assertSame('bluesky_profile_hidden', $problem['type'] ?? null);
        self::assertSame(
            'This profile is only visible to logged-in Bluesky users. Connect a Bluesky account in Settings → Social'
            . ' Media to subscribe.',
            $problem['detail'] ?? null,
        );
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): void
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $this->client->request(
            'POST',
            '/api/subscriptions',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($this->user), 'CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
```

Deletion checks:
- `SubscriptionService`: drop the route loop → `testARouteThatTakes…` runs discovery → `LogicException` → red; return the route's answer even when `null` → `testADecliningRoute…` gets candidates and no subscription → red; move the loop above the verbatim branches → `testAVerbatimFormat…` records a request → red.
- Route: drop the `findReadyActingOnNetwork` status filter (use `findActingOnNetwork`) → the reconnect test subscribes → red; drop the `null === $actor` guard → `hiddenAmong` is asked for `example.com` → `asked` not empty → red; drop the `catch` → the failure escapes → red; a hard-coded `true` for "hidden" → the public-profile row subscribes (no account → exception) → red.
- Problems: drop the arm → `resolve()` returns null → red.
- API: the 201 test guards the `_instanceof` tag (without it the iterator is empty, discovery runs on bsky.app through the real fetcher and the test fails on the response); the 422 test guards the message.

- [ ] **Step 2: Run the tests and see them fail.** `cd backend && php bin/phpunit tests/Service/Subscription/SubscriptionServiceTest.php tests/Service/Subscription/UnsubscribeAllTest.php tests/Service/Social/HiddenProfileSubscribeRouteTest.php tests/Http/Problem/ExceptionProblems/SocialProblemsTest.php tests/Controller/Api/SubscriptionHiddenProfileTest.php`. Expected: classes not found.

- [ ] **Step 3: Implement.**

`backend/src/Service/Subscription/Model/SubscribeRequestModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription\Model;

use App\Entity\Tag;
use App\Entity\User;

final readonly class SubscribeRequestModel
{
    /** @param list<Tag> $tags */
    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public User $user,
        public string $url,
        public array $tags,
    ) {
    }
}
```

`backend/src/Service/Subscription/SubscribeRoute/SubscribeRouteInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription\SubscribeRoute;

use App\Service\Subscription\Model\SubscribeOutcomeModel;
use App\Service\Subscription\Model\SubscribeRequestModel;

/** A source that subscribes some URLs its own way, asked before discovery. */
interface SubscribeRouteInterface
{
    /** @return SubscribeOutcomeModel|null null when the URL is not this route's to take */
    public function subscribe(SubscribeRequestModel $request): ?SubscribeOutcomeModel;
}
```

`backend/config/services.yaml` — under `_instanceof`, after the `FeedOfferInterface` entry:

```yaml
        App\Service\Subscription\SubscribeRoute\SubscribeRouteInterface:
            tags: ['app.subscribe_route']
```

`SubscriptionService` — constructor and routing:

```php
    /** @param iterable<SubscribeRouteInterface> $subscribeRoutes */
    public function __construct(
        private FeedDiscoveryInterface $discovery,
        private SubscriptionCreator $creator,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private FirstFetchRecorder $firstFetch,
        private OrphanedFeedReclaimer $orphanedFeeds,
        private EntityManagerInterface $entityManager,
        #[AutowireIterator('app.subscribe_route')]
        private iterable $subscribeRoutes,
    ) {
    }
```

In `subscribe()`, between the `WP_JSON` branch and the discovery call:

```php
        $routed = $this->routed(new SubscribeRequestModel($user, $url, $tags));
        if (null !== $routed) {
            return $routed;
        }

        $result = $this->discovery->discover($url, $this->scrapeFallbackPolicy->forUser($user));
```

and the private method:

```php
    private function routed(SubscribeRequestModel $request): ?SubscribeOutcomeModel
    {
        foreach ($this->subscribeRoutes as $route) {
            $outcome = $route->subscribe($request);
            if (null !== $outcome) {
                return $outcome;
            }
        }

        return null;
    }
```

(add `use App\Service\Subscription\Model\SubscribeRequestModel;`, `use App\Service\Subscription\SubscribeRoute\SubscribeRouteInterface;`, `use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;`).

`backend/src/Service/Social/Exception/BlueskyProfileHiddenException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

final class BlueskyProfileHiddenException extends \RuntimeException
{
}
```

`SocialProblems::resolve()` — a new arm before `default` (and `use App\Service\Social\Exception\BlueskyProfileHiddenException;`):

```php
            $exception instanceof BlueskyProfileHiddenException => new ResolvedProblem(new ApiProblem(
                'bluesky_profile_hidden',
                'Profile hidden from logged-out readers',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
```

`backend/src/Service/Social/HiddenProfileSubscribeRoute.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Exception\BlueskyProfileHiddenException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Subscription\Model\SubscribeOutcomeModel;
use App\Service\Subscription\Model\SubscribeRequestModel;
use App\Service\Subscription\SubscribeRoute\SubscribeRouteInterface;
use Psr\Log\LoggerInterface;

/** A bsky.app profile hidden from logged-out readers: its RSS answers 403, so it is read through the user's account. */
final readonly class HiddenProfileSubscribeRoute implements SubscribeRouteInterface
{
    private const string CONNECT_AN_ACCOUNT = 'This profile is only visible to logged-in Bluesky users. Connect a'
        . ' Bluesky account in Settings → Social Media to subscribe.';

    public function __construct(
        private HiddenProfilesInterface $hiddenProfiles,
        private SocialAccountRepository $socialAccounts,
        private AuthorFeeds $authorFeeds,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws BlueskyProfileHiddenException when the profile is hidden and no connected Bluesky account can read it */
    public function subscribe(SubscribeRequestModel $request): ?SubscribeOutcomeModel
    {
        $actor = ProfileFeedUrl::blueskyProfileActorOf($request->url);
        $profile = null === $actor ? null : $this->hiddenProfileOf($actor);
        if (null === $profile) {
            return null;
        }
        $account = $this->socialAccounts->findReadyActingOnNetwork($request->user, SocialNetwork::Bluesky)
            ?? throw new BlueskyProfileHiddenException(self::CONNECT_AN_ACCOUNT);

        return SubscribeOutcomeModel::subscribed($this->authorFeeds->create($account, $profile, $request->tags));
    }

    private function hiddenProfileOf(string $actor): ?HiddenProfileModel
    {
        try {
            return $this->hiddenProfiles->hiddenAmong([$actor])[$actor] ?? null;
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning('Subscribe could not tell whether a Bluesky profile is hidden; discovery decides.', [
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
```

- [ ] **Step 4: Run the tests and see them pass.** Same command, plus `php bin/phpunit tests/Controller/Api/SubscriptionControllerTest.php` (the full subscribe path must stay green). Expected: PASS.

- [ ] **Step 5: Commit.** `composer cs`, then:

```bash
git add backend/src/Service/Subscription backend/src/Service/Social/HiddenProfileSubscribeRoute.php backend/src/Service/Social/Exception/BlueskyProfileHiddenException.php backend/src/Http/Problem/ExceptionProblems/SocialProblems.php backend/config/services.yaml backend/tests
git commit -m "feat(#1667): subscribe a hidden Bluesky profile through the account before discovery"
```

---

### Task 7: Show the 422 in the add-feed dialog

**Files:**
- Test: `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts`

**Interfaces:**
- None new. The dialog's subscribe error path already shows `problem.errors?.['url']?.[0] ?? problem.detail ?? problem.title` in the URL field's `.error`; this pins it for the new problem. Production code does not change.

- [ ] **Step 1: Write the test.** Append inside the `describe`:

```ts
  it('tells the user how to subscribe a Bluesky profile hidden from logged-out readers', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://bsky.app/profile/rusty-17.bsky.social' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(
      {
        type: 'bluesky_profile_hidden',
        title: 'Profile hidden from logged-out readers',
        status: 422,
        detail:
          'This profile is only visible to logged-in Bluesky users. Connect a Bluesky account in Settings → Social Media to subscribe.',
      },
      { status: 422, statusText: 'Unprocessable' },
    );
    fixture.detectChanges();

    expect(
      (fixture.nativeElement as HTMLElement).querySelector('app-field .error')?.textContent,
    ).toContain('Connect a Bluesky account in Settings → Social Media');
    expect(close).not.toHaveBeenCalled();
  });
```

Deletion check: drop `?? problem.detail` in `AddFeedDialogComponent.subscribe()`'s error handler → the field shows the title → red.

- [ ] **Step 2: Run it.** One-time `cd frontend && npm ci`, then `./node_modules/.bin/jest src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts`. Expected: PASS (a characterisation test). Prove it guards: temporarily delete `?? problem.detail`, see it fail, restore the line by editing it back (not with `git checkout --`).

- [ ] **Step 3: Commit.** `./node_modules/.bin/prettier --check src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts`, then:

```bash
git add frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts
git commit -m "test(#1667): the add-feed dialog shows why a hidden Bluesky profile needs an account"
```

---

### Task 8: Import a hidden follow as an author feed

**Files:**
- Create: `backend/src/Service/Social/HiddenImportFollows.php`
- Modify: `backend/src/Service/Social/SocialImporter.php`
- Test: create `backend/tests/Service/Social/HiddenImportFollowsTest.php`; modify `backend/tests/Service/Social/SocialImporterTest.php`

**Interfaces:**
- Produces: `HiddenImportFollows::among(SocialAccount $account, list<SocialImportFeedModel> $feeds): array<string, HiddenProfileModel>` keyed by the chosen identity. The label is decided **server-side** from `getProfiles` (25 actors per call), never from a client field; the `labels` the picker's `getFollows` showed are not trusted. An unanswered check returns `[]`: those follows become RSS subscriptions as today, and Task 10's switch moves them once their RSS answers 403.
- `SocialImporter::import()` takes the hidden follows out of the `BulkSubscriber` items and creates an author feed per hidden follow with the importing account; outcomes count into the existing `CollectionImportModel` tally (`Created`, `AlreadySubscribed`, over the limit), so `SocialImportResultModel` is unchanged. Additive only: two constructor parameters, two lines in `import()`, two private methods.

- [ ] **Step 1: Write the failing tests.**

Create `backend/tests/Service/Social/HiddenImportFollowsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenImportFollows;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubHiddenProfiles;
use PHPUnit\Framework\TestCase;

final class HiddenImportFollowsTest extends TestCase
{
    private StubHiddenProfiles $hiddenProfiles;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->hiddenProfiles = new StubHiddenProfiles();
        $this->hiddenProfiles->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));
        $this->logger = new RecordingLogger();
    }

    public function testTheHiddenFollowsAreFoundByTheirIdentity(): void
    {
        $hidden = $this->follows()->among(SocialAccounts::bluesky(), [
            new SocialImportFeedModel('did:plc:rusty', 'Rusty'),
            new SocialImportFeedModel('did:plc:bob', 'Bob'),
        ]);

        self::assertEquals(['did:plc:rusty' => new HiddenProfileModel('did:plc:rusty', 'Rusty')], $hidden);
    }

    public function testAnIdentityThatIsNoActorIsNeverAsked(): void
    {
        $this->follows()->among(SocialAccounts::bluesky(), [
            new SocialImportFeedModel('did:plc:rusty', null),
            new SocialImportFeedModel('not an actor', null),
        ]);

        self::assertSame([['did:plc:rusty']], $this->hiddenProfiles->asked);
    }

    public function testAMastodonImportAsksNothing(): void
    {
        self::assertSame([], $this->follows()->among(SocialAccounts::mastodon(), [
            new SocialImportFeedModel('did:plc:rusty', null),
        ]));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testNothingToAskAsksNothing(): void
    {
        self::assertSame([], $this->follows()->among(SocialAccounts::bluesky(), []));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAnUnansweredCheckLeavesEveryFollowToRssAndIsLogged(): void
    {
        $this->hiddenProfiles->failure = new SocialNetworkUnreachableException('down');

        self::assertSame([], $this->follows()->among(SocialAccounts::bluesky(), [
            new SocialImportFeedModel('did:plc:rusty', null),
        ]));
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
    }

    private function follows(): HiddenImportFollows
    {
        return new HiddenImportFollows($this->hiddenProfiles, $this->logger);
    }
}
```

`SocialImporterTest` — append (the importer comes from the container, whose `HiddenProfilesInterface` is `StubHiddenProfiles`):

```php
    public function testAHiddenFollowIsImportedAsAnAuthorFeedAndTheRestAsRss(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $hiddenProfiles = self::getContainer()->get(StubHiddenProfiles::class);
        self::assertInstanceOf(StubHiddenProfiles::class, $hiddenProfiles);
        $hiddenProfiles->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));

        $result = $this->importer()->import($account, new SocialImportModel([
            new SocialImportFeedModel('did:plc:rusty', 'Rusty'),
            new SocialImportFeedModel('did:plc:bob', 'Bob'),
        ], []));

        self::assertSame(2, $result->imported);
        self::assertNotNull($this->feedAt('https://bsky.app/profile/did:plc:bob/rss'));
        self::assertNull($this->feedAt('https://bsky.app/profile/did:plc:rusty/rss'));
        $author = $this->feedAt(sprintf('social:bluesky:%d:author:did:plc:rusty', $account->requireId()));
        self::assertSame(SourceFormat::BLUESKY_API, $author?->getSourceFormat());
    }

    public function testAHiddenFollowAlreadyReadThroughTheAccountCountsAsAlreadySubscribed(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $hiddenProfiles = self::getContainer()->get(StubHiddenProfiles::class);
        self::assertInstanceOf(StubHiddenProfiles::class, $hiddenProfiles);
        $hiddenProfiles->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));
        $import = new SocialImportModel([new SocialImportFeedModel('did:plc:rusty', 'Rusty')], []);
        $this->importer()->import($account, $import);

        $result = $this->importer()->import($account, $import);

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
    }
```

(add `use App\Service\Social\Model\HiddenProfileModel;` and `use App\Tests\Support\StubHiddenProfiles;`; `storedAccount()` is the file's existing helper.)

Deletion checks:
- `HiddenImportFollows`: drop the network guard → the Mastodon row asks → red; drop the `BlueskyActor::isValid` filter → `asked` holds both identities → red; drop the `[] === $actors` return → `asked` is `[[]]` → red; drop the `catch` → the exception escapes → red; return all asked actors instead of the hidden ones → the first test gets bob too → red.
- `SocialImporter`: drop `withoutHidden` → the RSS feed for rusty exists → red; drop `addAuthors` → no author feed and `imported` 1 → red; drop the `AlreadySubscribedException` catch → the second import throws → red.

- [ ] **Step 2: Run the tests and see them fail.** `cd backend && php bin/phpunit tests/Service/Social/HiddenImportFollowsTest.php tests/Service/Social/SocialImporterTest.php`. Expected: class `HiddenImportFollows` not found; the importer tests fail.

- [ ] **Step 3: Implement.**

`backend/src/Service/Social/HiddenImportFollows.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Support\BlueskyActor;
use Psr\Log\LoggerInterface;

/** The chosen Bluesky follows that hide their posts from logged-out readers, so their RSS would answer 403. */
final readonly class HiddenImportFollows
{
    public function __construct(
        private HiddenProfilesInterface $hiddenProfiles,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<SocialImportFeedModel> $feeds
     *
     * @return array<string, HiddenProfileModel> by the chosen identity; none when Bluesky cannot say, and the 403 of
     *                                          their RSS later moves them
     */
    public function among(SocialAccount $account, array $feeds): array
    {
        $actors = SocialNetwork::Bluesky === $account->getNetwork() ? self::actorsOf($feeds) : [];
        if ([] === $actors) {
            return [];
        }
        try {
            $hidden = $this->hiddenProfiles->hiddenAmong($actors);
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning('Import could not tell which Bluesky follows are hidden; all come in by RSS.', [
                'exception' => $exception,
            ]);

            return [];
        }

        return array_intersect_key($hidden, array_flip($actors));
    }

    /**
     * @param list<SocialImportFeedModel> $feeds
     *
     * @return list<string>
     */
    private static function actorsOf(array $feeds): array
    {
        $identities = array_map(static fn (SocialImportFeedModel $feed): string => $feed->identity, $feeds);

        return array_values(array_filter($identities, BlueskyActor::isValid(...)));
    }
}
```

`SocialImporter` — constructor:

```php
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BulkSubscriber $bulkSubscriber,
        private OwnedSubscriptions $ownedSubscriptions,
        private SubscriptionService $subscriptionService,
        private PrivateFeeds $privateFeeds,
        private ImportedProfilePictures $importedPictures,
        private HiddenImportFollows $hiddenFollows,
        private AuthorFeeds $authorFeeds,
    ) {
    }
```

In `import()`, replace the `$items` line and the result:

```php
        $network = $account->getNetwork();
        $hidden = $this->hiddenFollows->among($account, $import->subscribe);
        $items = self::items($network, self::withoutHidden($import->subscribe, $hidden));
```

```php
        return $this->entityManager->wrapInTransaction(
            function () use ($account, $import, $items, $toRemove, $hidden): SocialImportResultModel {
                $removed = $this->subscriptionService->unsubscribeAll(array_values($toRemove));
                $subscribed = $this->bulkSubscriber->subscribeAll($account->getUser(), $items);
                $this->importedPictures->picture(
                    $account,
                    array_map(static fn (BulkSubscribeItemModel $item): string => $item->feedUrl, $items),
                );
                $collections = $this->addCollections($account, $import->collections);

                return SocialImportResultModel::from(
                    $subscribed,
                    $this->addAuthors($account, $hidden, $collections),
                    $removed,
                );
            },
        );
```

and the two private methods:

```php
    /**
     * @param list<SocialImportFeedModel>       $feeds
     * @param array<string, HiddenProfileModel> $hidden
     *
     * @return list<SocialImportFeedModel>
     */
    private static function withoutHidden(array $feeds, array $hidden): array
    {
        return array_values(array_filter(
            $feeds,
            static fn (SocialImportFeedModel $feed): bool => !isset($hidden[$feed->identity]),
        ));
    }

    /** @param array<string, HiddenProfileModel> $hidden */
    private function addAuthors(SocialAccount $account, array $hidden, CollectionImportModel $tally): CollectionImportModel
    {
        foreach ($hidden as $profile) {
            try {
                $this->authorFeeds->create($account, $profile, []);
                $tally = $tally->counting(PrivateFeedOutcome::Created);
            } catch (AlreadySubscribedException) {
                $tally = $tally->counting(PrivateFeedOutcome::AlreadySubscribed);
            } catch (SubscriptionLimitReachedException) {
                $tally = $tally->countingOverLimit();
            }
        }

        return $tally;
    }
```

(add `use App\Service\Social\Model\HiddenProfileModel;`, `use App\Service\Social\Model\PrivateFeedOutcome;`, `use App\Service\Subscription\Exception\AlreadySubscribedException;`). The new follows' avatars come from `ProfileImageSweep` (Task 5), so `ImportedProfilePictures` stays as it is.

- [ ] **Step 4: Run the tests and see them pass.** Same command, plus `php bin/phpunit tests/Controller/Api/SocialImportControllerTest.php`. Expected: PASS. Run `composer md` on `src/Service/Social/SocialImporter.php`; if PHPMD flags the class, report it rather than tuning.

- [ ] **Step 5: Commit.** `composer cs`, then:

```bash
git add backend/src/Service/Social/HiddenImportFollows.php backend/src/Service/Social/SocialImporter.php backend/tests/Service/Social
git commit -m "feat(#1667): import a hidden Bluesky follow as an author feed"
```

---

### Task 9: Move a user's saved state between two feeds of the same post (`SavedStateMover`)

**Files:**
- Create: `backend/src/Service/SavedPosts/SavedStateMover.php` (new module `Service/SavedPosts`)
- Modify: `backend/src/Repository/SocialLikeRepository.php` (`copyLike`), `backend/src/Repository/EntryRepository.php` (`sameGuidEntryIds`)
- Test: create `backend/tests/Service/SavedPosts/SavedStateMoverTest.php`, `backend/tests/Repository/SocialLikeCopyTest.php`, `backend/tests/Repository/EntrySameGuidTest.php`

**Interfaces (fixed by the #1666 plan — do not change names, signature or semantics):**
- `App\Service\SavedPosts\SavedStateMover::__construct(EntryStateRepository $entryStates, SocialLikeRepository $socialLikes, EntityManagerInterface $entityManager)`.
- `move(int $userId, array<int, int> $targetByEntryId): void` — keys are source entry ids, values the target entry ids of the same post; id-based, the caller matches guids. For each source entry with a state row it ensures a target row carrying the read state and `hiddenAt`, ORs favourite and kept onto the target, copies `viewedAt`, copies the user's `SocialLike`, clears favourite and kept on the source, and flushes once.
- Relies on `EntryStateRepository::forUserByEntryIds(int $userId, list<int> $entryIds): array<int, EntryState>` and `EntryStateRepository::ensureRow(int $userId, int $entryId, ?\DateTimeImmutable $hiddenSince): void` — **both already exist in the tree** (the backup and the read marker use them); nothing to add there. New: `SocialLikeRepository::copyLike(int $userId, int $sourceEntryId, int $targetEntryId): void`.
- Produces for Task 10: `EntryRepository::sameGuidEntryIds(int $userId, int $sourceFeedId, int $targetFeedId): array<int, int>` — the guid (`guid_hash`) matching, limited to posts the user has a state row or a like on.
- Module rule: `SavedPosts` imports only `App\Repository` and `App\Entity`, never a `Service` module, so `Social → SavedPosts` adds no cycle (`ServiceModuleCycleRule`), and `ServiceModuleBoundaryRule` names no boundary with it.

- [ ] **Step 0: Take it over if it already landed.** `git fetch && git merge origin/develop`. If `backend/src/Service/SavedPosts/SavedStateMover.php` now exists, reuse it and skip writing it and `copyLike` (keep only `EntryRepository::sameGuidEntryIds` and its test); check that the merged signature matches the one above.

- [ ] **Step 1: Write the failing tests.**

Create `backend/tests/Repository/SocialLikeCopyTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\SocialLikeRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialLikeCopyTest extends DbTestCase
{
    private const string LIKE = 'at://did:plc:alice/app.bsky.feed.like/3l1';

    private User $user;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('social-like-copy@example.test');
        $this->account = SocialAccounts::blueskyOf($this->user);
        $this->entityManager->persist($this->account);
    }

    public function testTheLikeIsGivenToTheSamePostInTheOtherFeed(): void
    {
        $source = $this->entry('https://bsky.app/profile/did:plc:rusty/rss');
        $target = $this->entry('social:bluesky:1:author:did:plc:rusty');
        $this->like($source, self::LIKE, '2026-10-16 08:00:00');

        $this->likes()->copyLike($this->user->requireId(), $source->requireId(), $target->requireId());

        $copied = $this->likes()->findOneFor($this->user, $target);
        self::assertSame(self::LIKE, $copied?->getRemoteId());
        self::assertSame('2026-10-16 08:00:00', $copied->getLikedAt()->format('Y-m-d H:i:s'));
    }

    public function testALikedTargetKeepsItsOwnAndAnUnlikedSourceCopiesNothing(): void
    {
        $source = $this->entry('https://bsky.app/profile/did:plc:rusty/rss');
        $target = $this->entry('social:bluesky:1:author:did:plc:rusty');
        $unliked = $this->entry('https://bsky.app/profile/did:plc:other/rss');
        $this->like($target, 'at://own', '2026-10-16 09:00:00');
        $this->like($source, self::LIKE, '2026-10-16 08:00:00');

        $this->likes()->copyLike($this->user->requireId(), $source->requireId(), $target->requireId());
        $this->likes()->copyLike($this->user->requireId(), $unliked->requireId(), $target->requireId());

        self::assertSame('at://own', $this->likes()->findOneFor($this->user, $target)?->getRemoteId());
        self::assertSame(2, $this->likes()->count([]));
    }

    private function like(Entry $entry, string $remoteId, string $likedAt): void
    {
        $this->entityManager->persist(
            new SocialLike($this->user, $entry, $this->account, $remoteId, new \DateTimeImmutable($likedAt)),
        );
        $this->entityManager->flush();
    }

    private function entry(string $feedUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $at = new \DateTimeImmutable('2026-10-16 07:00:00');
        $entry = new Entry($feed, 'at://did:plc:rusty/app.bsky.feed.post/3abc', null, 'Post', $at, $at);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function likes(): SocialLikeRepository
    {
        $likes = $this->entityManager->getRepository(SocialLike::class);
        self::assertInstanceOf(SocialLikeRepository::class, $likes);

        return $likes;
    }
}
```

Create `backend/tests/Repository/EntrySameGuidTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntrySameGuidTest extends DbTestCase
{
    private UserFactory $users;
    private User $user;
    private Feed $source;
    private Feed $target;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->users = new UserFactory($this->entityManager, $hasher);
        $this->user = $this->users->create('entry-same-guid@example.test');
        $this->source = $this->feed('https://bsky.app/profile/did:plc:rusty/rss');
        $this->target = $this->feed('social:bluesky:1:author:did:plc:rusty');
    }

    public function testEachPostTheUserKeptSomethingOnIsPairedWithItsTwinInTheOtherFeed(): void
    {
        $read = $this->entry($this->source, 'post-read');
        $readTwin = $this->entry($this->target, 'post-read');
        $liked = $this->entry($this->source, 'post-liked');
        $likedTwin = $this->entry($this->target, 'post-liked');
        $this->entry($this->source, 'post-untouched');
        $this->entry($this->target, 'post-untouched');
        $onlyInRss = $this->entry($this->source, 'post-only-in-rss');
        $strangersPost = $this->entry($this->source, 'post-of-a-stranger');
        $this->entry($this->target, 'post-of-a-stranger');
        $this->entityManager->persist(new EntryState($this->user, $read));
        $this->entityManager->persist(new EntryState($this->user, $onlyInRss));
        $this->entityManager->persist(new EntryState($this->users->create('entry-same-guid-other@example.test'), $strangersPost));
        $account = SocialAccounts::blueskyOf($this->user);
        $this->entityManager->persist($account);
        $this->entityManager->persist(new SocialLike($this->user, $liked, $account, 'at://like', new \DateTimeImmutable()));
        $this->entityManager->flush();

        $pairs = $this->entries()->sameGuidEntryIds(
            $this->user->requireId(),
            $this->source->requireId(),
            $this->target->requireId(),
        );
        ksort($pairs);

        $expected = [$read->requireId() => $readTwin->requireId(), $liked->requireId() => $likedTwin->requireId()];
        ksort($expected);
        self::assertSame($expected, $pairs);
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $at = new \DateTimeImmutable('2026-10-16 07:00:00');
        $entry = new Entry($feed, $guid, null, $guid, $at, $at);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function entries(): EntryRepository
    {
        $entries = $this->entityManager->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $entries);

        return $entries;
    }
}
```

Create `backend/tests/Service/SavedPosts/SavedStateMoverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts;

use App\Entity\BackedUpReadMark;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Repository\SocialLikeRepository;
use App\Service\SavedPosts\SavedStateMover;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedStateMoverTest extends DbTestCase
{
    private const string READ_AT = '2026-10-16 08:00:00';
    private const string VIEWED_AT = '2026-10-16 08:05:00';

    private User $user;
    private Entry $source;
    private Entry $target;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('saved-state-mover@example.test');
        $this->source = $this->entry('https://bsky.app/profile/did:plc:rusty/rss');
        $this->target = $this->entry('social:bluesky:1:author:did:plc:rusty');
    }

    public function testFavouriteAndKeptMoveToTheTarget(): void
    {
        $state = $this->stateOf($this->source);
        $state->markFavorite();
        $state->markKept();
        $this->entityManager->flush();

        $this->move();

        $target = $this->storedState($this->target);
        self::assertTrue($target?->isFavorite());
        self::assertTrue($target->isKept());
        $source = $this->storedState($this->source);
        self::assertFalse($source?->isFavorite());
        self::assertFalse($source->isKept());
    }

    public function testTheReadMarkAndTheOpeningCarryOver(): void
    {
        $state = $this->stateOf($this->source);
        $state->hide(new \DateTimeImmutable(self::READ_AT));
        $state->markViewed(new \DateTimeImmutable(self::VIEWED_AT));
        $this->entityManager->flush();

        $this->move();

        $target = $this->storedState($this->target);
        self::assertTrue($target?->isHidden());
        self::assertSame(self::READ_AT, $target->getHiddenAt()?->format('Y-m-d H:i:s'));
        self::assertSame(self::VIEWED_AT, $target->getViewedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAReadMarkWithoutAnInstantStaysRead(): void
    {
        $this->stateOf($this->source)->restoreReadMark(new BackedUpReadMark(true, null));
        $this->entityManager->flush();

        $this->move();

        self::assertTrue($this->storedState($this->target)?->isHidden());
    }

    public function testATargetAlreadySavedStaysSaved(): void
    {
        $this->stateOf($this->source);
        $this->stateOf($this->target)->markFavorite();
        $this->entityManager->flush();

        $this->move();

        self::assertTrue($this->storedState($this->target)?->isFavorite());
    }

    public function testTheLikeIsCopied(): void
    {
        $this->stateOf($this->source);
        $account = SocialAccounts::blueskyOf($this->user);
        $this->entityManager->persist($account);
        $this->entityManager->persist(
            new SocialLike($this->user, $this->source, $account, 'at://like', new \DateTimeImmutable(self::READ_AT)),
        );
        $this->entityManager->flush();

        $this->move();

        self::assertSame('at://like', $this->likes()->findOneFor($this->user, $this->target)?->getRemoteId());
    }

    public function testAPostWithoutStateLeavesTheTargetWithoutARow(): void
    {
        $this->move();

        self::assertNull($this->storedState($this->target));
    }

    private function move(): void
    {
        (new SavedStateMover($this->states(), $this->likes(), $this->entityManager))
            ->move($this->user->requireId(), [$this->source->requireId() => $this->target->requireId()]);
        $this->entityManager->clear();
    }

    private function stateOf(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $this->entityManager->persist($state);

        return $state;
    }

    private function storedState(Entry $entry): ?EntryState
    {
        return $this->states()->findOneForUserEntry($this->user->requireId(), $entry->requireId());
    }

    private function states(): EntryStateRepository
    {
        $states = $this->entityManager->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $states);

        return $states;
    }

    private function likes(): SocialLikeRepository
    {
        $likes = $this->entityManager->getRepository(SocialLike::class);
        self::assertInstanceOf(SocialLikeRepository::class, $likes);

        return $likes;
    }

    private function entry(string $feedUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $at = new \DateTimeImmutable('2026-10-16 07:00:00');
        $entry = new Entry($feed, 'at://did:plc:rusty/app.bsky.feed.post/3abc', null, 'Post', $at, $at);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
```

Deletion checks:
- `copyLike`: drop the `INSERT … SELECT` → no like on the target → red; drop `IGNORE`/`OR IGNORE` → the second test's insert hits `uniq_social_like_user_entry` → exception → red; drop `entry_id = ?` from the `WHERE` → the unliked call copies nothing new but the first call would copy the target's own like too (still blocked by the unique key) — the count assertion (2) then guards an accidental extra row only on MySQL/SQLite both, so keep it.
- `sameGuidEntryIds`, one fixture per condition: drop the guid join condition → cross pairs; drop the state `EXISTS` → "post-read" missing; drop the like `EXISTS` → "post-liked" missing; drop `userState.user = :user` → the stranger's post pairs; drop the target-feed condition → "post-only-in-rss" pairs with itself; "post-untouched" guards that a post without state or like is not paired.
- Mover: drop the favourite/kept copy → target not favourite; drop the clearing → source still favourite; pass `null` to `ensureRow` and drop `restoreReadMark` → the read state is lost; drop only the `restoreReadMark` branch → `testAReadMarkWithoutAnInstant…` red (`ensureRow` with a null instant writes an unread row); drop `markViewed` → `viewedAt` null; turn OR into assignment (clear the target when the source is not favourite) → `testATargetAlreadySaved…` red; drop `copyLike` → no like; ensure a row for every key instead of each stated source → `testAPostWithoutState…` finds a row.

- [ ] **Step 2: Run the tests and see them fail.** `cd backend && php bin/phpunit tests/Repository/SocialLikeCopyTest.php tests/Repository/EntrySameGuidTest.php tests/Service/SavedPosts`. Expected: undefined methods; class `SavedStateMover` not found.

- [ ] **Step 3: Implement.**

`SocialLikeRepository` (add `use Doctrine\DBAL\Types\Types;`):

```php
    /** Gives the user's like of $sourceEntryId to the same post's $targetEntryId, unless that one carries a like. */
    public function copyLike(int $userId, int $sourceEntryId, int $targetEntryId): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $conflictClause = DatabasePlatform::isMySql($connection) ? 'IGNORE' : 'OR IGNORE';

        $connection->executeStatement(
            sprintf(
                'INSERT %s INTO social_like (user_id, entry_id, social_account_id, remote_id, liked_at)'
                . ' SELECT user_id, ?, social_account_id, remote_id, liked_at FROM social_like'
                . ' WHERE user_id = ? AND entry_id = ?',
                $conflictClause,
            ),
            [$targetEntryId, $userId, $sourceEntryId],
            [Types::INTEGER, Types::INTEGER, Types::INTEGER],
        );
    }
```

(Column names follow the default underscore naming of `SocialLike`'s fields; check them against the `social_like` migration before running.)

`EntryRepository` (add `use App\Entity\EntryState;`, `use App\Entity\SocialLike;`, `use Doctrine\ORM\Query\Expr\Join;`):

```php
    /**
     * The same post's entry in the target feed, by its entry in the source feed, for each post the user holds a state
     * or a like on.
     *
     * @return array<int, int>
     */
    public function sameGuidEntryIds(int $userId, int $sourceFeedId, int $targetFeedId): array
    {
        /** @var list<array{sourceId: int, targetId: int}> $rows */
        $rows = $this->createQueryBuilder('sourceEntry')
            ->select('sourceEntry.id AS sourceId', 'targetEntry.id AS targetId')
            ->join(
                Entry::class,
                'targetEntry',
                Join::WITH,
                'targetEntry.feed = :targetFeed AND targetEntry.guid.hash = sourceEntry.guid.hash',
            )
            ->andWhere('sourceEntry.feed = :sourceFeed')
            ->andWhere(sprintf(
                '(EXISTS (SELECT IDENTITY(userState.entry) FROM %s userState'
                . ' WHERE userState.entry = sourceEntry AND userState.user = :user)'
                . ' OR EXISTS (SELECT userLike.id FROM %s userLike'
                . ' WHERE userLike.entry = sourceEntry AND userLike.user = :user))',
                EntryState::class,
                SocialLike::class,
            ))
            ->setParameter('targetFeed', $targetFeedId)
            ->setParameter('sourceFeed', $sourceFeedId)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'targetId', 'sourceId');
    }
```

`backend/src/Service/SavedPosts/SavedStateMover.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SavedPosts;

use App\Entity\BackedUpReadMark;
use App\Entity\EntryState;
use App\Repository\EntryStateRepository;
use App\Repository\SocialLikeRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Moves what a user saved on posts from one feed's entries to another feed's entries of the same posts. */
final readonly class SavedStateMover
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SocialLikeRepository $socialLikes,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<int, int> $targetByEntryId the same post's target entry id, by its source entry id */
    public function move(int $userId, array $targetByEntryId): void
    {
        if ([] === $targetByEntryId) {
            return;
        }
        $sources = $this->entryStates->forUserByEntryIds($userId, array_keys($targetByEntryId));
        foreach ($sources as $entryId => $source) {
            $this->entryStates->ensureRow(
                $userId,
                $targetByEntryId[$entryId],
                $source->isHidden() ? $source->getHiddenAt() : null,
            );
        }
        $targets = $this->entryStates->forUserByEntryIds(
            $userId,
            array_values(array_intersect_key($targetByEntryId, $sources)),
        );
        foreach ($sources as $entryId => $source) {
            $target = $targets[$targetByEntryId[$entryId]] ?? null;
            if (null !== $target) {
                self::carry($source, $target);
            }
        }
        foreach ($targetByEntryId as $sourceEntryId => $targetEntryId) {
            $this->socialLikes->copyLike($userId, $sourceEntryId, $targetEntryId);
        }
        $this->entityManager->flush();
    }

    /** A legacy read mark has no instant, which ensureRow() cannot write; restoreReadMark() can. */
    private static function carry(EntryState $source, EntryState $target): void
    {
        if ($source->isHidden() && !$target->isHidden()) {
            $target->restoreReadMark(new BackedUpReadMark(true, $source->getHiddenAt()));
        }
        if ($source->isFavorite()) {
            $target->markFavorite();
            $source->clearFavorite();
        }
        if ($source->isKept()) {
            $target->markKept();
            $source->clearKept();
        }
        $viewedAt = $source->getViewedAt();
        if (null !== $viewedAt) {
            $target->markViewed($viewedAt);
        }
    }
}
```

If PHPMD's NPath on `move()` complains, extract the `ensureRow` loop into `private function ensureTargets(int $userId, array $sources, array $targetByEntryId): void`.

- [ ] **Step 4: Run the tests and see them pass.** Same command. Expected: PASS. The MySQL branch of `copyLike` runs in Task 12's manual checklist.

- [ ] **Step 5: Commit.** `composer cs`, then:

```bash
git add backend/src/Service/SavedPosts backend/src/Repository/SocialLikeRepository.php backend/src/Repository/EntryRepository.php backend/tests/Service/SavedPosts backend/tests/Repository/SocialLikeCopyTest.php backend/tests/Repository/EntrySameGuidTest.php
git commit -m "feat(#1667): move a user's saved state to the same posts in another feed"
```

---

### Task 10: Switch a refused RSS subscription to an author feed

**Files:**
- Modify: `backend/src/Entity/Subscription.php` (`takeSettingsFrom`), `backend/src/Repository/SubscriptionRepository.php`, `backend/config/packages/cache.yaml`, `backend/src/Service/Social/Model/ProfileImageBudgetModel.php` (docblock only)
- Create: `backend/src/Service/Social/PublicProfileCache.php`, `backend/src/Service/Social/HiddenProfileMove.php`, `backend/src/Service/Social/HiddenProfileSwitchSweep.php`, `backend/src/Service/Social/Model/HiddenProfileMoveOutcome.php`, `backend/src/Service/Social/Model/HiddenProfileSwitchReportModel.php`
- Test: create `backend/tests/Entity/SubscriptionSettingsTest.php`, `backend/tests/Repository/ForbiddenBlueskyProfileSubscriptionsTest.php`, `backend/tests/Service/Social/HiddenProfileSwitchSweepTest.php`

**Interfaces:**
- `Subscription::takeSettingsFrom(Subscription $previous): void` — custom title, position, both inclusion flags, `markedReadUntil`, and every tag at its position in that tag. On the entity: copying a subscription's own settings is its behaviour, and five setters from a service would be §10's "build and save" smell.
- `SubscriptionRepository::findOnForbiddenBlueskyProfileFeeds(int $limit): list<Subscription>` — Bluesky profile **RSS** feeds (`xml`) with `consecutiveFailures > 0` and a last error ending `: HTTP 403` (`ResponseClassifier`'s wording), whose subscriber has a `Connected` Bluesky account; feed by feed. This is the switch's queue: no marker column, no migration.
- `SubscriptionRepository::findOneForUserAndFeed(int $userId, int $feedId): ?Subscription`.
- `PublicProfileCache::isPublic(string $actor): bool`, `rememberPublic(list<string> $actors): void` — pool `social.public_profiles.cache`, a day. A miss costs one more AppView call, never a wrong move.
- `HiddenProfileMove::move(Subscription $rssSubscription, HiddenProfileModel $profile): HiddenProfileMoveOutcome` — `Skipped` (no connected account), `Started` (author feed created and given the RSS subscription's settings), `Waiting` (author feed not fetched yet), `Finished` (saved state moved by guid with `SavedStateMover`, RSS subscription removed via `SubscriptionService::unsubscribe`, whose orphan reclaim drops the RSS feed once nobody reads it). Throws `SubscriptionLimitReachedException`.
- `HiddenProfileSwitchSweep::sweep(ProfileImageBudgetModel $budget): HiddenProfileSwitchReportModel` — at most `$budget->calls` AppView calls of 25 actors; skips actors known public; remembers the asked-and-public ones; logs and stops when the AppView cannot answer (nothing remembered, asked again next sweep); a full account is logged and skipped. The label is re-checked every sweep for a hidden profile until its move finishes (one or two sweeps).
- `HiddenProfileSwitchReportModel(int $started, int $finished)`, `none()`, `counting(HiddenProfileMoveOutcome)`, `toLogContext()`.
- `ProfileImageBudgetModel` becomes the budget of AppView profile calls for both sweeps; its docblock loses "on profile pictures".
- Why two phases, in one line: the author feed has no entries until its first fetch, and Social may not call Refresh (Refresh → Social exists), so the move waits one refresh for the guids to exist.

- [ ] **Step 1: Write the failing tests.**

Create `backend/tests/Entity/SubscriptionSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class SubscriptionSettingsTest extends TestCase
{
    public function testANewSubscriptionTakesOverThePreviousOnesSettings(): void
    {
        $now = new \DateTimeImmutable('2026-10-17 12:00:00');
        $user = new User('settings@example.test', $now);
        $previous = new Subscription($user, new Feed('https://bsky.app/profile/did:plc:rusty/rss'), $now);
        $previous->setCustomTitle('Rusty, old');
        $previous->setPosition(7);
        $previous->setIncludeInAllItems(false);
        $previous->setIncludeInForYou(false);
        $previous->setMarkedReadUntil(new \DateTimeImmutable('2026-10-10 00:00:00'));
        $previous->addTag(new Tag($user, 'People'), 3);
        $next = new Subscription($user, new Feed('social:bluesky:1:author:did:plc:rusty'), $now);

        $next->takeSettingsFrom($previous);

        self::assertSame('Rusty, old', $next->getCustomTitle());
        self::assertSame(7, $next->getPosition());
        self::assertFalse($next->isIncludeInAllItems());
        self::assertFalse($next->isIncludeInForYou());
        self::assertSame('2026-10-10', $next->getMarkedReadUntil()?->format('Y-m-d'));
        self::assertSame(
            [['People', 3]],
            array_map(
                static fn (SubscriptionTag $subscriptionTag): array => [
                    $subscriptionTag->getTag()->getName(),
                    $subscriptionTag->getPosition(),
                ],
                $next->getSubscriptionTags(),
            ),
        );
    }
}
```

Create `backend/tests/Repository/ForbiddenBlueskyProfileSubscriptionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ForbiddenBlueskyProfileSubscriptionsTest extends DbTestCase
{
    private const string NOW = '2026-10-17 12:00:00';
    private const string PROFILE = 'https://bsky.app/profile/did:plc:rusty/rss';

    public function testASubscriberWithAnAccountOnAProfileFeedRefusedWith403IsACandidate(): void
    {
        $user = $this->user('forbidden-candidate@example.test');
        $this->connect($user);
        $subscription = $this->subscribe($user, self::PROFILE, 'HTTP 403');

        self::assertSame([$subscription], $this->subscriptions()->findOnForbiddenBlueskyProfileFeeds(10));
    }

    #[DataProvider('otherFeeds')]
    public function testNoOtherFeedIsACandidate(string $feedUrl, ?string $failure, string $sourceFormat): void
    {
        $user = $this->user('forbidden-other-feed@example.test');
        $this->connect($user);
        $this->subscribe($user, $feedUrl, $failure, $sourceFormat);

        self::assertSame([], $this->subscriptions()->findOnForbiddenBlueskyProfileFeeds(10));
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function otherFeeds(): iterable
    {
        yield 'another failure' => [self::PROFILE, 'HTTP 500', SourceFormat::XML];
        yield 'a healthy feed' => [self::PROFILE, null, SourceFormat::XML];
        yield 'a private feed' => ['social:bluesky:1:author:did:plc:rusty', 'HTTP 403', SourceFormat::BLUESKY_API];
        yield 'a mastodon profile' => ['https://mastodon.social/@rusty.rss', 'HTTP 403', SourceFormat::XML];
    }

    public function testASubscriberWithoutAnAccountIsNoCandidate(): void
    {
        $this->subscribe($this->user('forbidden-no-account@example.test'), self::PROFILE, 'HTTP 403');

        self::assertSame([], $this->subscriptions()->findOnForbiddenBlueskyProfileFeeds(10));
    }

    public function testASubscriberWhoseAccountNeedsAReconnectIsNoCandidate(): void
    {
        $user = $this->user('forbidden-reconnect@example.test');
        $this->connect($user)->markNeedsReconnect();
        $this->entityManager->flush();
        $this->subscribe($user, self::PROFILE, 'HTTP 403');

        self::assertSame([], $this->subscriptions()->findOnForbiddenBlueskyProfileFeeds(10));
    }

    public function testTheLimitHolds(): void
    {
        $user = $this->user('forbidden-limit@example.test');
        $this->connect($user);
        $this->subscribe($user, 'https://bsky.app/profile/did:plc:one/rss', 'HTTP 403');
        $this->subscribe($user, 'https://bsky.app/profile/did:plc:two/rss', 'HTTP 403');

        self::assertCount(1, $this->subscriptions()->findOnForbiddenBlueskyProfileFeeds(1));
    }

    public function testASubscriptionIsFoundByItsUserAndFeed(): void
    {
        $user = $this->user('forbidden-lookup@example.test');
        $stranger = $this->user('forbidden-lookup-stranger@example.test');
        $subscription = $this->subscribe($user, 'https://example.com/feed.xml', null);
        $feedId = $subscription->getFeed()->requireId();

        self::assertSame($subscription, $this->subscriptions()->findOneForUserAndFeed($user->requireId(), $feedId));
        self::assertNull($this->subscriptions()->findOneForUserAndFeed($stranger->requireId(), $feedId));
    }

    private function subscribe(
        User $user,
        string $feedUrl,
        ?string $failure,
        string $sourceFormat = SourceFormat::XML,
    ): Subscription {
        $now = new \DateTimeImmutable(self::NOW);
        $feed = new Feed($feedUrl);
        $feed->setSourceFormat($sourceFormat);
        if (null !== $failure) {
            $feed->recordFailedFetch($now, $feedUrl . ': ' . $failure, 60);
        }
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, $now);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    private function connect(User $user): SocialAccount
    {
        $account = SocialAccounts::blueskyOf($user);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->entityManager, $hasher))->create($email);
    }

    private function subscriptions(): SubscriptionRepository
    {
        $subscriptions = $this->entityManager->getRepository(Subscription::class);
        self::assertInstanceOf(SubscriptionRepository::class, $subscriptions);

        return $subscriptions;
    }
}
```

Create `backend/tests/Service/Social/HiddenProfileSwitchSweepTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfileMove;
use App\Service\Social\HiddenProfileSwitchSweep;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\ProfileImageBudgetModel;
use App\Service\Social\PublicProfileCache;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubHiddenProfiles;
use App\Tests\Support\UserFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HiddenProfileSwitchSweepTest extends DbTestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';
    private const string NOW = '2026-10-17 12:00:00';
    private const string POST = 'at://did:plc:hczfd45prvqtdrw77256syag/app.bsky.feed.post/3abc';

    private StubHiddenProfiles $hiddenProfiles;
    private PublicProfileCache $publicProfiles;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hiddenProfiles = new StubHiddenProfiles();
        $this->hiddenProfiles->hide(new HiddenProfileModel(self::RUSTY, 'Rusty'));
        $this->publicProfiles = new PublicProfileCache(new ArrayAdapter());
        $this->logger = new RecordingLogger();
    }

    public function testTheFirstSweepGivesTheSubscriberAnAuthorFeedWithTheSameSettings(): void
    {
        $user = $this->user('switch-start@example.test');
        $account = $this->accountOf($user);
        $rss = $this->rssSubscription($user);
        $rss->setCustomTitle('Rusty, old');
        $rss->setPosition(7);
        $rss->setIncludeInForYou(false);
        $tag = new Tag($user, 'People');
        $tag->setPosition(0);
        $this->entityManager->persist($tag);
        $rss->addTag($tag, 3);
        $this->entityManager->flush();

        $report = $this->sweep(1);

        self::assertEquals(new HiddenProfileSwitchReportModel(1, 0), $report);
        $author = $this->authorSubscriptionOf($account);
        self::assertSame('Rusty, old', $author?->getCustomTitle());
        self::assertSame(7, $author->getPosition());
        self::assertFalse($author->isIncludeInForYou());
        self::assertTrue($author->isIncludeInAllItems());
        self::assertSame(['People'], array_map(static fn (Tag $each): string => $each->getName(), $author->getTags()->toArray()));
        self::assertNotNull($this->entityManager->find(Subscription::class, $rss->requireId()));
    }

    public function testOnceTheAuthorFeedFetchedTheSavedStateMovesAndTheRssSubscriptionGoes(): void
    {
        $user = $this->user('switch-finish@example.test');
        $account = $this->accountOf($user);
        $rss = $this->rssSubscription($user);
        $rssFeedId = $rss->getFeed()->requireId();
        $saved = new EntryState($user, $this->entry($rss->getFeed()));
        $saved->markFavorite();
        $saved->hide(new \DateTimeImmutable(self::NOW));
        $this->entityManager->persist($saved);
        $this->entityManager->flush();
        $this->sweep(1);
        $authorFeed = $this->authorSubscriptionOf($account)?->getFeed();
        self::assertNotNull($authorFeed);
        $twin = $this->entry($authorFeed);
        $authorFeed->recordSuccessfulFetch(new \DateTimeImmutable(self::NOW), 15);
        $this->entityManager->flush();

        $report = $this->sweep(1);

        self::assertEquals(new HiddenProfileSwitchReportModel(0, 1), $report);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Subscription::class, $rss->requireId()));
        self::assertNull($this->entityManager->find(Feed::class, $rssFeedId));
        $states = $this->entityManager->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $states);
        $moved = $states->findOneForUserEntry($user->requireId(), $twin->requireId());
        self::assertTrue($moved?->isFavorite());
        self::assertTrue($moved->isHidden());
    }

    public function testAnAuthorFeedNotFetchedYetIsWaitedFor(): void
    {
        $user = $this->user('switch-wait@example.test');
        $this->accountOf($user);
        $rss = $this->rssSubscription($user);
        $this->sweep(1);

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(1));
        self::assertNotNull($this->entityManager->find(Subscription::class, $rss->requireId()));
    }

    public function testASubscriberWithoutAnAccountKeepsTheRefusedFeed(): void
    {
        $this->rssSubscription($this->user('switch-no-account@example.test'));

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(1));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAProfileFoundPublicIsNotAskedAgain(): void
    {
        $user = $this->user('switch-public@example.test');
        $this->accountOf($user);
        $this->rssSubscription($user, 'did:plc:public');

        $this->sweep(1);
        $this->sweep(1);

        self::assertSame([['did:plc:public']], $this->hiddenProfiles->asked);
    }

    public function testAnUnansweredCheckMovesNothingAndIsAskedAgain(): void
    {
        $user = $this->user('switch-unanswered@example.test');
        $this->accountOf($user);
        $this->rssSubscription($user);
        $this->hiddenProfiles->failure = new SocialNetworkUnreachableException('down');

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(1));
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);

        $this->hiddenProfiles->failure = null;

        self::assertEquals(new HiddenProfileSwitchReportModel(1, 0), $this->sweep(1));
    }

    public function testAnEmptyBudgetAsksNothing(): void
    {
        $user = $this->user('switch-budget@example.test');
        $this->accountOf($user);
        $this->rssSubscription($user);

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(0));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAFullAccountKeepsItsRssFeedAndTheSkipIsLogged(): void
    {
        $user = $this->user('switch-full@example.test');
        $user->setMaxSubscriptions(1);
        $account = $this->accountOf($user);
        $this->rssSubscription($user);

        self::assertEquals(HiddenProfileSwitchReportModel::none(), $this->sweep(1));
        self::assertNull($this->authorSubscriptionOf($account));
        self::assertSame('info', $this->logger->records[0]['level'] ?? null);
    }

    private function sweep(int $calls): HiddenProfileSwitchReportModel
    {
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $subscriptions);
        $move = self::getContainer()->get(HiddenProfileMove::class);
        self::assertInstanceOf(HiddenProfileMove::class, $move);

        return (new HiddenProfileSwitchSweep(
            $subscriptions,
            $this->hiddenProfiles,
            $this->publicProfiles,
            $move,
            $this->logger,
        ))->sweep(ProfileImageBudgetModel::calls($calls));
    }

    private function rssSubscription(User $user, string $did = self::RUSTY): Subscription
    {
        $now = new \DateTimeImmutable(self::NOW);
        $url = 'https://bsky.app/profile/' . $did . '/rss';
        $feed = new Feed($url);
        $feed->recordFailedFetch($now, $url . ': HTTP 403', 60);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, $now);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    private function entry(Feed $feed): Entry
    {
        $at = new \DateTimeImmutable(self::NOW);
        $entry = new Entry($feed, self::POST, null, 'Post', $at, $at);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function authorSubscriptionOf(SocialAccount $account): ?Subscription
    {
        $feed = $this->entityManager->getRepository(Feed::class)->findOneBy([
            'url' => sprintf('social:bluesky:%d:author:%s', $account->requireId(), self::RUSTY),
        ]);

        return null === $feed
            ? null
            : $this->entityManager->getRepository(Subscription::class)->findOneBy(['feed' => $feed]);
    }

    private function accountOf(User $user): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($user));
    }

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->entityManager, $hasher))->create($email);
    }
}
```

(If the container's `HiddenProfileMove` shares its entity manager with the test, as `DbTestCase` does, the fixtures are visible to it without extra wiring.)

Deletion checks:
- `takeSettingsFrom`: one assertion per copied field; dropping any line turns its assertion red; copying tags without their position gives `['People', 0]`.
- Repository, one fixture per condition: drop the `LIKE` → "another failure" is a candidate; drop `consecutiveFailures > 0` → "a healthy feed" still fails on the `LIKE` (null message) — the guard is redundant for correctness; if Infection reports it surviving, delete the condition rather than invent a test; drop the account `EXISTS` → the no-account test; drop `sa.status` → the reconnect test; drop `sourceFormat = :xml` → "a private feed"; drop `f.network = :bluesky` → "a mastodon profile"; drop `setMaxResults` → `testTheLimitHolds`; `findOneForUserAndFeed` without the user criterion → the stranger finds it.
- Sweep and move: drop `start()` → no author feed (first test); drop `takeSettingsFrom()` → title, position and tag differ; drop the `getLastSuccessfulFetchAt()` wait → `…WaitedFor`'s second sweep finishes and unsubscribes → red; drop `unsubscribe` in `finish()` → the RSS subscription survives; drop the `SavedStateMover` call → the twin has no state; drop `rememberPublic` → asked twice; remember on failure → the retry asks nothing new and starts nothing → `…AskedAgain` red; drop the budget check → asked with an empty budget; drop the limit `catch` → the exception escapes; the account-less subscriber is excluded by the query, so `move()`'s `Skipped` branch is a race guard (account disconnected between query and move) — Infection may list it; accept it as equivalent or kill it with a unit test that calls `HiddenProfileMove::move()` for a user without an account and expects `Skipped`.

- [ ] **Step 2: Run the tests and see them fail.** `cd backend && php bin/phpunit tests/Entity/SubscriptionSettingsTest.php tests/Repository/ForbiddenBlueskyProfileSubscriptionsTest.php tests/Service/Social/HiddenProfileSwitchSweepTest.php`. Expected: undefined methods, classes not found.

- [ ] **Step 3: Implement.**

`Subscription` — add:

```php
    /** This subscription follows the same source as $previous under a new feed, so it takes over its settings. */
    public function takeSettingsFrom(self $previous): void
    {
        $this->customTitle = $previous->customTitle;
        $this->position = $previous->position;
        $this->includeInAllItems = $previous->includeInAllItems;
        $this->includeInForYou = $previous->includeInForYou;
        $this->markedReadUntil = $previous->markedReadUntil;
        foreach ($previous->getSubscriptionTags() as $subscriptionTag) {
            $this->addTag($subscriptionTag->getTag(), $subscriptionTag->getPosition());
        }
    }
```

`SubscriptionRepository` (add `use App\Entity\SocialAccount;`, `use App\Enum\SocialAccountStatus;`, `use App\Enum\SocialNetwork;`, `use Symfony\Component\HttpFoundation\Response;` — `Response::HTTP_*` constants are what `DomainKnowsNoHttpRule` permits in a repository):

```php
    public function findOneForUserAndFeed(int $userId, int $feedId): ?Subscription
    {
        return $this->findOneBy(['user' => $userId, 'feed' => $feedId]);
    }

    /**
     * Subscriptions to Bluesky profile RSS feeds whose last fetch was refused with a 403, held by a user with a
     * connected Bluesky account: the ones a hidden profile's author feed may replace. Feed by feed.
     *
     * @return list<Subscription>
     */
    public function findOnForbiddenBlueskyProfileFeeds(int $limit): array
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->createQueryBuilder('s')
            ->addSelect('f')
            ->join('s.feed', 'f')
            ->andWhere('f.network = :bluesky')
            ->andWhere('f.sourceFormat = :xml')
            ->andWhere('f.fetchSchedule.consecutiveFailures > 0')
            ->andWhere('f.fetchSchedule.lastErrorMessage LIKE :forbidden')
            ->andWhere(sprintf(
                'EXISTS (SELECT sa.id FROM %s sa WHERE sa.user = s.user AND sa.network = :bluesky'
                . ' AND sa.status = :connected)',
                SocialAccount::class,
            ))
            ->setParameter('bluesky', SocialNetwork::Bluesky)
            ->setParameter('xml', SourceFormat::XML)
            ->setParameter('forbidden', '%: HTTP ' . Response::HTTP_FORBIDDEN)
            ->setParameter('connected', SocialAccountStatus::Connected)
            ->orderBy('f.id', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $subscriptions;
    }
```

`backend/config/packages/cache.yaml` — after `social.follow_avatars.cache`:

```yaml
            # Bluesky profiles the hidden-profile switch found public, so a profile feed refused for another reason is
            # asked about once a day (#1667). A miss costs one more AppView call.
            social.public_profiles.cache:
                adapter: cache.adapter.filesystem
                default_lifetime: 86400
```

`ProfileImageBudgetModel` — docblock to `/** How many AppView profile calls one pass may spend, each asking for up to a page of actors. */`.

`backend/src/Service/Social/PublicProfileCache.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PublicProfileCache
{
    public function __construct(
        #[Autowire(service: 'social.public_profiles.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function isPublic(string $actor): bool
    {
        return $this->cache->getItem(self::keyOf($actor))->isHit();
    }

    /** @param list<string> $actors */
    public function rememberPublic(array $actors): void
    {
        foreach ($actors as $actor) {
            $this->cache->saveDeferred($this->cache->getItem(self::keyOf($actor))->set(true));
        }
        $this->cache->commit();
    }

    /** A DID holds colons, which PSR-6 reserves in keys. */
    private static function keyOf(string $actor): string
    {
        return 'public_profile.' . hash('xxh128', $actor);
    }
}
```

`backend/src/Service/Social/Model/HiddenProfileMoveOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

enum HiddenProfileMoveOutcome
{
    case Skipped;
    case Started;
    case Waiting;
    case Finished;
}
```

`backend/src/Service/Social/Model/HiddenProfileSwitchReportModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class HiddenProfileSwitchReportModel
{
    public function __construct(
        public int $started,
        public int $finished,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    public function counting(HiddenProfileMoveOutcome $outcome): self
    {
        return match ($outcome) {
            HiddenProfileMoveOutcome::Started => new self($this->started + 1, $this->finished),
            HiddenProfileMoveOutcome::Finished => new self($this->started, $this->finished + 1),
            HiddenProfileMoveOutcome::Skipped, HiddenProfileMoveOutcome::Waiting => $this,
        };
    }

    /** @return array{started: int, finished: int} */
    public function toLogContext(): array
    {
        return ['started' => $this->started, 'finished' => $this->finished];
    }
}
```

`backend/src/Service/Social/HiddenProfileMove.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Repository\EntryRepository;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialFeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\SavedPosts\SavedStateMover;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\HiddenProfileMoveOutcome;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;

/** Moves one subscriber of a hidden profile's refused RSS feed to an author feed of their own, over two sweeps. */
final readonly class HiddenProfileMove
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private SocialFeedRepository $socialFeeds,
        private SubscriptionRepository $subscriptions,
        private AuthorFeeds $authorFeeds,
        private EntryRepository $entries,
        private SavedStateMover $savedStates,
        private SubscriptionService $subscriptionService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @throws SubscriptionLimitReachedException */
    public function move(Subscription $rssSubscription, HiddenProfileModel $profile): HiddenProfileMoveOutcome
    {
        $account = $this->socialAccounts->findReadyActingOnNetwork($rssSubscription->getUser(), SocialNetwork::Bluesky);
        if (null === $account) {
            return HiddenProfileMoveOutcome::Skipped;
        }
        $authorSubscription = $this->authorSubscriptionOf($account, $profile);
        if (null === $authorSubscription) {
            $this->start($account, $profile, $rssSubscription);

            return HiddenProfileMoveOutcome::Started;
        }
        if (null === $authorSubscription->getFeed()->getLastSuccessfulFetchAt()) {
            return HiddenProfileMoveOutcome::Waiting;
        }
        $this->finish($rssSubscription, $authorSubscription);

        return HiddenProfileMoveOutcome::Finished;
    }

    private function authorSubscriptionOf(SocialAccount $account, HiddenProfileModel $profile): ?Subscription
    {
        $socialFeed = $this->socialFeeds->findOneFor($account, SocialFeedKind::Author, $profile->did);

        return null === $socialFeed
            ? null
            : $this->subscriptions->findOneForUserAndFeed(
                $account->getUser()->requireId(),
                $socialFeed->getFeed()->requireId(),
            );
    }

    private function start(SocialAccount $account, HiddenProfileModel $profile, Subscription $rssSubscription): void
    {
        $this->authorFeeds->create($account, $profile, [])->takeSettingsFrom($rssSubscription);
        $this->entityManager->flush();
    }

    private function finish(Subscription $rssSubscription, Subscription $authorSubscription): void
    {
        $userId = $rssSubscription->getUser()->requireId();
        $this->savedStates->move($userId, $this->entries->sameGuidEntryIds(
            $userId,
            $rssSubscription->getFeed()->requireId(),
            $authorSubscription->getFeed()->requireId(),
        ));
        $this->subscriptionService->unsubscribe($rssSubscription);
    }
}
```

`backend/src/Service/Social/HiddenProfileSwitchSweep.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Subscription;
use App\Enum\SocialNetwork;
use App\Repository\SubscriptionRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\HiddenProfileMoveOutcome;
use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\ProfileImageBudgetModel;
use App\Service\Social\Support\BlueskyActor;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use Psr\Log\LoggerInterface;

/**
 * Moves the subscribers of Bluesky profile feeds refused with a 403 to author feeds when the profile hides itself from
 * logged-out readers. Its queue is the refused feeds themselves; a subscriber without an account keeps the feed.
 */
final readonly class HiddenProfileSwitchSweep
{
    private const int CANDIDATES_PER_SWEEP = 200;

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private HiddenProfilesInterface $hiddenProfiles,
        private PublicProfileCache $publicProfiles,
        private HiddenProfileMove $move,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(ProfileImageBudgetModel $budget): HiddenProfileSwitchReportModel
    {
        if ($budget->isEmpty()) {
            return HiddenProfileSwitchReportModel::none();
        }
        $subscriptions = $this->subscriptions->findOnForbiddenBlueskyProfileFeeds(self::CANDIDATES_PER_SWEEP);
        $actors = $this->unsettledActorsOf($subscriptions);
        $asked = \array_slice(array_values(array_unique($actors)), 0, $budget->calls * AppViewClient::URIS_PER_CALL);
        if ([] === $asked) {
            return HiddenProfileSwitchReportModel::none();
        }
        try {
            $hidden = $this->hiddenProfiles->hiddenAmong($asked);
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning('Hidden-profile switch stopped: Bluesky did not answer.', ['exception' => $exception]);

            return HiddenProfileSwitchReportModel::none();
        }
        $this->publicProfiles->rememberPublic(array_values(array_diff($asked, array_keys($hidden))));

        return $this->moveHidden($subscriptions, $actors, $hidden);
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return array<int, string> the actor each subscription's feed follows, by its position, unless known public
     */
    private function unsettledActorsOf(array $subscriptions): array
    {
        $actors = [];
        foreach ($subscriptions as $index => $subscription) {
            $actor = ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $subscription->getFeed()->getUrl());
            if (null !== $actor && BlueskyActor::isValid($actor) && !$this->publicProfiles->isPublic($actor)) {
                $actors[$index] = $actor;
            }
        }

        return $actors;
    }

    /**
     * @param list<Subscription>                $subscriptions
     * @param array<int, string>                $actors
     * @param array<string, HiddenProfileModel> $hidden
     */
    private function moveHidden(array $subscriptions, array $actors, array $hidden): HiddenProfileSwitchReportModel
    {
        $report = HiddenProfileSwitchReportModel::none();
        foreach ($actors as $index => $actor) {
            $profile = $hidden[$actor] ?? null;
            if (null !== $profile) {
                $report = $report->counting($this->moveOne($subscriptions[$index], $profile));
            }
        }

        return $report;
    }

    private function moveOne(Subscription $subscription, HiddenProfileModel $profile): HiddenProfileMoveOutcome
    {
        try {
            return $this->move->move($subscription, $profile);
        } catch (SubscriptionLimitReachedException $exception) {
            $this->logger->info('Hidden-profile switch skipped a subscriber at the subscription cap.', [
                'subscriptionId' => $subscription->requireId(),
                'exception' => $exception,
            ]);

            return HiddenProfileMoveOutcome::Skipped;
        }
    }
}
```

- [ ] **Step 4: Run the tests and see them pass.** Same command. Expected: PASS. Then `php bin/phpunit tests/Service/Subscription tests/Repository/SubscriptionRepositoryTest.php` (the repository grew).

- [ ] **Step 5: Commit.** `composer cs`, then:

```bash
git add backend/src/Entity/Subscription.php backend/src/Repository/SubscriptionRepository.php backend/config/packages/cache.yaml backend/src/Service/Social backend/tests
git commit -m "feat(#1667): move subscribers of a hidden profile's refused RSS feed to author feeds"
```

---

### Task 11: Run the switch from the worker and the maintenance tick

**Files:**
- Create: `backend/src/Service/Worker/Message/SwitchHiddenProfileFeeds.php`, `backend/src/Service/Worker/Handler/SwitchHiddenProfileFeedsHandler.php`, `backend/src/Service/Social/ProfileSweeps.php`
- Modify: `backend/src/Service/Worker/WorkerSchedule.php`, `backend/src/Service/Maintenance/MaintenanceTick.php`, `backend/src/Service/Maintenance/Model/MaintenanceSweepsModel.php`, `backend/src/Http/MaintenanceTickJson.php`
- Test: create `backend/tests/Service/Worker/SwitchHiddenProfileFeedsHandlerTest.php`; modify `backend/tests/Service/Worker/WorkerScheduleWiringTest.php`, `backend/tests/Service/Maintenance/MaintenanceTickTest.php`, `backend/tests/Http/MaintenanceTickJsonTest.php`

**Interfaces:**
- `SwitchHiddenProfileFeeds` — property-less, scheduled `every 10 minutes`, appended **last** (the stateful checkpoint stores list positions). Its handler spends one AppView call and logs the report.
- `ProfileSweeps::pictures(ProfileImageBudgetModel): ProfileImageSweepReportModel` and `ProfileSweeps::switches(ProfileImageBudgetModel): HiddenProfileSwitchReportModel` — the tick's two AppView profile sweeps behind one collaborator. Reason: `MaintenanceTick` already takes 9 collaborators and PHPMD's `ExcessiveParameterList` reports at 10; both sweeps spend the same AppView budget back to back. `RefreshProfileImagesHandler` keeps using `ProfileImageSweep` directly.
- `MaintenanceSweepsModel` gains `public HiddenProfileSwitchReportModel $hiddenProfiles` after `$profileImages`; the tick JSON gains `hiddenProfiles: {started, finished}` after `profileImages`. Strato has no worker, so the tick is what runs the switch in production.

- [ ] **Step 1: Write the failing tests.**

Create `backend/tests/Service/Worker/SwitchHiddenProfileFeedsHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Social\HiddenProfileSwitchSweep;
use App\Service\Worker\Handler\SwitchHiddenProfileFeedsHandler;
use App\Service\Worker\Message\SwitchHiddenProfileFeeds;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;

final class SwitchHiddenProfileFeedsHandlerTest extends DbTestCase
{
    public function testFiringSweepsAndLogsTheReport(): void
    {
        $sweep = self::getContainer()->get(HiddenProfileSwitchSweep::class);
        self::assertInstanceOf(HiddenProfileSwitchSweep::class, $sweep);
        $logger = new RecordingLogger();

        (new SwitchHiddenProfileFeedsHandler($sweep, $logger))(new SwitchHiddenProfileFeeds());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker hidden-profile switch finished.', $logger->records[0]['message']);
        self::assertSame(['started' => 0, 'finished' => 0], $logger->records[0]['context']['report']);
    }
}
```

`WorkerScheduleWiringTest`: `assertCount(11, …)`; append `SwitchHiddenProfileFeeds::class` to the class list and `'every 10 minutes'` to the frequency list; add `use App\Service\Worker\Message\SwitchHiddenProfileFeeds;`.

`MaintenanceTickJsonTest`: in the first test add `new HiddenProfileSwitchReportModel(20, 21),` right after `new ProfileImageSweepReportModel(18, 19),` and `'hiddenProfiles' => ['started' => 20, 'finished' => 21],` right after the `'profileImages'` line; in the second add `'hiddenProfiles' => ['started' => 0, 'finished' => 0, 'skipped' => $reason],` after its `'profileImages'` line; add `use App\Service\Social\Model\HiddenProfileSwitchReportModel;`.

`MaintenanceTickTest`:
- in `tickAround()`, replace `$this->profileImageSweep($appViewFetcher ?? self::unreachableAppView(), $clock),` with `$this->profileSweeps($appViewFetcher ?? self::unreachableAppView(), $clock),` and add:

```php
    private function profileSweeps(StubFeedFetcher $fetcher, MockClock $clock): ProfileSweeps
    {
        $switches = self::getContainer()->get(HiddenProfileSwitchSweep::class);
        self::assertInstanceOf(HiddenProfileSwitchSweep::class, $switches);

        return new ProfileSweeps($this->profileImageSweep($fetcher, $clock), $switches);
    }
```

- append the window pair, mirroring the profile-picture tests:

```php
    public function testMovesHiddenProfilesInTheSpareWindowOfATickWhoseRefreshTakesNoTime(): void
    {
        self::assertSame(1, $this->hiddenProfilesStartedByATickWhoseRefreshTakes(0));
    }

    public function testLeavesHiddenProfilesForLaterWhenTheRefreshSpentTheWindow(): void
    {
        self::assertSame(0, $this->hiddenProfilesStartedByATickWhoseRefreshTakes(18));
    }

    private function hiddenProfilesStartedByATickWhoseRefreshTakes(int $refreshSeconds): int
    {
        $clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $now = $clock->now();
        $subscriber = new User('tick-hidden-profiles@example.com', $now);
        $this->entityManager->persist($subscriber);
        $this->entityManager->flush();
        $this->entityManager->persist(SocialAccounts::blueskyOf($subscriber));
        $feed = new Feed('https://one.example.com/feed');
        $feed->scheduleNextFetchAt($now->modify('-1 hour'));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($subscriber, $feed, $now));
        $refused = new Feed('https://bsky.app/profile/did:plc:rusty/rss');
        $refused->recordFailedFetch($now, 'https://bsky.app/profile/did:plc:rusty/rss: HTTP 403', 60);
        $refused->scheduleNextFetchAt($now->modify('+1 hour'));
        $this->entityManager->persist($refused);
        $this->entityManager->persist(new Subscription($subscriber, $refused, $now));
        $this->entityManager->flush();
        $hiddenProfiles = self::getContainer()->get(StubHiddenProfiles::class);
        self::assertInstanceOf(StubHiddenProfiles::class, $hiddenProfiles);
        $hiddenProfiles->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));
        $passes = new StubPassFetcher($clock);
        $passes->secondsPerFetch = $refreshSeconds;
        $passes->willReturnBody(
            'https://one.example.com/feed',
            /** @lang TEXT */ '<?xml version="1.0"?><rss version="2.0"><channel><title>F</title></channel></rss>',
        );
        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->build($passes, new StubFeedFetcher());
        $sendDueDigests = self::getContainer()->get(SendDueDigests::class);
        self::assertInstanceOf(SendDueDigests::class, $sendDueDigests);
        $linkCardSweep = LinkCardSweepWiring::build($this->entityManager, new MockHttpClient(), $clock);

        $report = $this->tickAround($refreshRunner, $sendDueDigests, $linkCardSweep, $clock)->run();

        return $report->sweeps->hiddenProfiles->started;
    }
```

(add `use App\Service\Social\HiddenProfileSwitchSweep;`, `use App\Service\Social\Model\HiddenProfileModel;`, `use App\Service\Social\ProfileSweeps;`, `use App\Tests\Support\SocialAccounts;`, `use App\Tests\Support\StubHiddenProfiles;`. The profile-picture sweep asks the unreachable AppView about `did:plc:rusty` first; it logs and stops, which leaves the switch its own call.)

Deletion checks:
- Handler: drop the log call → no record.
- Schedule: drop the `add()` → count 10.
- JSON: drop the `hiddenProfiles` key → both JSON tests red; drop its `markedIfSkipped` → no `skipped` in the second.
- Tick: drop the `switches()` call → `started` 0 in the "no time" test; compute its budget before the refresh has spent its time → the "spent the window" test turns 1 → red.

- [ ] **Step 2: Run the tests and see them fail.** `cd backend && php bin/phpunit tests/Service/Worker tests/Service/Maintenance/MaintenanceTickTest.php tests/Http/MaintenanceTickJsonTest.php`. Expected: classes not found, constructor arity errors.

- [ ] **Step 3: Implement.**

`backend/src/Service/Worker/Message/SwitchHiddenProfileFeeds.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

final readonly class SwitchHiddenProfileFeeds
{
}
```

`backend/src/Service/Worker/Handler/SwitchHiddenProfileFeedsHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Social\HiddenProfileSwitchSweep;
use App\Service\Social\Model\ProfileImageBudgetModel;
use App\Service\Worker\Message\SwitchHiddenProfileFeeds;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SwitchHiddenProfileFeedsHandler
{
    private const int CALLS = 1;

    public function __construct(
        private HiddenProfileSwitchSweep $hiddenProfileSwitchSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SwitchHiddenProfileFeeds $message): void
    {
        $report = $this->hiddenProfileSwitchSweep->sweep(ProfileImageBudgetModel::calls(self::CALLS));
        $this->logger->info('Worker hidden-profile switch finished.', ['report' => $report->toLogContext()]);
    }
}
```

`WorkerSchedule::getSchedule()` — after the `RefreshProfileImages` line:

```php
            ->add(RecurringMessage::every('10 minutes', new SwitchHiddenProfileFeeds()))
```

(and `use App\Service\Worker\Message\SwitchHiddenProfileFeeds;`).

`backend/src/Service/Social/ProfileSweeps.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\ProfileImageBudgetModel;
use App\Service\Social\Model\ProfileImageSweepReportModel;

/** The two sweeps that spend AppView profile calls, for a caller that runs them back to back in one window. */
final readonly class ProfileSweeps
{
    public function __construct(
        private ProfileImageSweep $profileImageSweep,
        private HiddenProfileSwitchSweep $hiddenProfileSwitchSweep,
    ) {
    }

    public function pictures(ProfileImageBudgetModel $budget): ProfileImageSweepReportModel
    {
        return $this->profileImageSweep->sweep($budget);
    }

    public function switches(ProfileImageBudgetModel $budget): HiddenProfileSwitchReportModel
    {
        return $this->hiddenProfileSwitchSweep->sweep($budget);
    }
}
```

`MaintenanceTick` — replace `private ProfileImageSweep $profileImageSweep,` with `private ProfileSweeps $profileSweeps,` (and the `use`); add `private const int HIDDEN_PROFILE_MAX_CALLS = 1;` next to `PROFILE_IMAGE_MAX_CALLS`; in `sweep()`:

```php
        $profileImages = $this->profileSweeps->pictures(
            ProfileImageBudgetModel::fittingBefore($deadline, $this->clock->now(), self::PROFILE_IMAGE_MAX_CALLS),
        );
        $hiddenProfiles = $this->profileSweeps->switches(
            ProfileImageBudgetModel::fittingBefore($deadline, $this->clock->now(), self::HIDDEN_PROFILE_MAX_CALLS),
        );
        $budget = SweepBudgetModel::remainingUntil($deadline, $this->clock->now(), self::MEMBERSHIP_BUDGET_SECONDS);

        return new MaintenanceSweepsModel(
            $recommendations,
            $digests,
            $imageVerification,
            $linkCards,
            $profileImages,
            $hiddenProfiles,
            $this->membershipSweep->sweep($budget),
        );
```

`MaintenanceSweepsModel` — the new property after `$profileImages`:

```php
        public ProfileImageSweepReportModel $profileImages,
        public HiddenProfileSwitchReportModel $hiddenProfiles,
        public SavedSearchMembershipSweepReportModel $savedSearchMemberships,
```

and `HiddenProfileSwitchReportModel::none(),` after `ProfileImageSweepReportModel::none(),` in `skippedAfterAbortedRefresh()` (with its `use`).

`MaintenanceTickJson::report()` — after the `'profileImages'` entry:

```php
            'hiddenProfiles' => self::markedIfSkipped($sweeps, self::hiddenProfiles($sweeps->hiddenProfiles)),
```

and:

```php
    /** @return array{started: int, finished: int} */
    private static function hiddenProfiles(HiddenProfileSwitchReportModel $report): array
    {
        return ['started' => $report->started, 'finished' => $report->finished];
    }
```

(with `use App\Service\Social\Model\HiddenProfileSwitchReportModel;`).

- [ ] **Step 4: Run the tests and see them pass.** Same command. Grep `tests/` for other pins of the tick JSON (`grep -rn "profileImages" backend/tests`) and update each the same way. Expected: PASS.

- [ ] **Step 5: Commit.** `composer cs`, then:

```bash
git add backend/src/Service/Worker backend/src/Service/Social/ProfileSweeps.php backend/src/Service/Maintenance backend/src/Http/MaintenanceTickJson.php backend/tests
git commit -m "feat(#1667): run the hidden-profile switch from the worker and the maintenance tick"
```

---

### Task 12: Gates, and the checklist for the main checkout

- [ ] **Step 1: Backend gates** (from `backend/`):
  - `bin/console cache:warmup`, then `composer check` (cs + stan + tramp). PHPStan watch points: `ServiceRoleRule` (the new classes sit in root, `Model/`, `Support/`, `HiddenProfiles/`, `SubscribeRoute/`, `Exception/` as planned), `ServiceModuleCycleRule` (`SavedPosts` imports no `Service` module), `DomainKnowsNoHttpRule` (only `Response::HTTP_FORBIDDEN` in `SubscriptionRepository`), `AbbreviatedNameRule`, `NoCollaboratorDefaultRule`, `InvocationMatchersOnThisRule`. phptramp: `$tags` crosses `SubscriptionService` only inside `SubscribeRequestModel`; `AuthorFeeds::create` → `SubscriptionCreator::create` is the existing two-method creator chain.
  - `composer md` — every touched `src` file PHPMD-clean (`MaintenanceTick` stays at 9 constructor parameters).
  - `composer test:parallel`, and `php bin/phpunit` once in full.
  - `composer infection:diff` — kill every escaped mutant on a changed line with a test, or delete the condition it proves redundant; never lower `minMsi`.
  - `bin/console lint:container`; `EveryApplicationServiceBuildsTest` is part of the suite.
  - `ls -t var/log/dev-*.log | head -1 | xargs tail -n 100 | jq .` — no new deprecation.
- [ ] **Step 2: Frontend gate** (from `frontend/`): `npm run check`.
- [ ] **Step 3: Commit any gate fixes** as `refactor(#1667): …` or `test(#1667): …`.
- [ ] **Step 4: PR body notes** (`Closes #1667`): the switch runs as a scheduled sweep plus the tick, not a dispatched message, and why (no async transport; the refresh runs inside the tick request on Strato); the two feeds coexist for one refresh cycle; saved state on posts older than the author feed's first page (50 posts) is not carried; the import decides the label server-side with `getProfiles`, not from the picker's `getFollows` labels; preview of a hidden profile's `/rss` is unchanged (the route answers before discovery offers it).
- [ ] **Step 5: Manual checklist after merging into the main checkout** (Docker stack; none of it runs from this worktree):
  1. `docker compose exec php composer test` — the MySQL leg (exercises `copyLike`'s `INSERT IGNORE`).
  2. With a connected Bluesky account, add `https://bsky.app/profile/rusty-17.bsky.social` (and once more as `…/rss` in another user): the dialog closes; the feed sits under Bluesky → People, gets its avatar after the next picture sweep, loads posts after the next refresh, shows no reposts and no replies to others, and counts in the network's People view.
  3. Without a Bluesky account (or with one that needs a reconnect), the same URL shows "This profile is only visible to logged-in Bluesky users. Connect a Bluesky account in Settings → Social Media to subscribe." under the URL field.
  4. Import picker: a followed hidden account imports as an author feed (URL `social:bluesky:…:author:…`), and reads as subscribed when the picker reopens.
  5. Automatic switch: import an OPML holding `https://bsky.app/profile/did:plc:hczfd45prvqtdrw77256syag/rss`, tag and favourite something, let a refresh fail with 403, then let two ticks (or `messenger:consume scheduler_worker` firings) pass, one before and one after the author feed's first fetch: title, tags, position and the favourite carry over; the RSS subscription is gone. A second user without an account keeps the failing RSS feed.
  6. Disconnecting the account removes its author feeds with its other private feeds.
  7. PhpStorm inspections (`mcp__phpstorm__lint_files`) on every changed PHP file: no ERROR or WARNING.

## Open questions for the implementer

- `SocialAccountRepositoryTest`'s helper names (`userNamed()`, `repository()` in Task 4) stand for whatever that file already uses; match them.
- Task 3's `AppViewHiddenProfiles::modelOf()` casts the DID; if PHPStan level max objects, assert it non-null instead.
- If `ApiProblem`'s detail property is not called `detail`, adjust `SocialProblemsTest`.
- Check `copyLike`'s column names against the `social_like` migration.
- For Lars, not blocking: the two-phase window shows the person twice (refused RSS + not-yet-fetched author feed) until the next refresh; and saved state on posts beyond the author feed's first page is lost when the RSS feed is reclaimed. Should a move wait while unmatched favourites or kept posts exist?
