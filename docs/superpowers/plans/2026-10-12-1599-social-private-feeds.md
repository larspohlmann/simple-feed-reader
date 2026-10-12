# Social private feeds (#1599) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every connected Bluesky and Mastodon account gets a private Home-timeline feed on connect; its lists (and Bluesky custom feeds) become opt-in private feeds from the import picker; the refresh worker polls them through the account's credential and stores their posts in the entry shape RSS posts reach; the reader shows them in a Bluesky and a Mastodon sidebar section.

**Architecture:** A `SocialFeed` row (account, Feed, kind, remote id, Mastodon cursor) marks a `Feed` private. Its synthetic `social:<network>:<accountId>:…` URL and its `bluesky-api` / `mastodon-api` source format keep it out of every URL path. `PrivateFeeds` creates private feeds idempotently through `SubscriptionCreator`, makes them due again on reconnect and removes them on disconnect. `RefreshRunner` hands its pass to a new `RefreshPassFetcher`: ordinary feeds go to the batch fetcher as today, social feeds go one at a time, inside the same budget, to `SocialFeedRefresher`. That class reads one page through a per-network `TimelineSourceInterface` (keyed locator; a cached, sealed Bluesky session or the Mastodon token). Pure mappers turn the page into a `ParsedFeedModel` plus a `KnownSharesModel` (Bluesky post views, Mastodon preview cards). `FeedOutcomePersister::storeParsed` stores the page through the same ingest → schedule → share queue → flush → index step a fetched body takes. The share queue settles a known share exactly as `PostEnricher` / `ShareLinkCards` would later. The API adds `social` to each subscription and `socialAccounts` to the list; the import candidates and the import carry collections. The frontend picker gets checkboxes for collections; the sidebar gets one `SidebarSocialSectionComponent` per network.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM 3 / DBAL 4.4, symfony/http-client (`MockHttpClient` through `RoutedHttpClient` in tests), symfony/cache; Angular 20 (standalone, signals), Transloco, Jest.

**Spec:** GitHub #1596 ("Private timeline feeds", "Sidebar") and #1599, plus the binding design brief `/private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/67891348-6acf-4396-bb8b-0e3735c2a620/scratchpad/1599-design-brief.md`. Global Constraints restates the brief's rulings, so this plan stands alone.

## Deviations from the brief

1. **`social` per row is `{network, accountId, kind}`, parsed from the synthetic URL; handles come once per list as `socialAccounts`.** `one()` serves five endpoints, and a per-row handle needs a query at each.
2. **`SocialFeed.feed` is a `ManyToOne` plus a named unique constraint, not a `OneToOne`.** Doctrine's `OneToOne` adds an unnamed `UNIQ_` index that the migration cannot match.
3. **The cursor column is `newest_seen_id`.** `CURSOR` is reserved in MySQL.
4. **Reconnect is `SocialAccountConnector::reconnect()`.** `SocialAccountEditor::reconnect()` does not exist. It sets `nextFetchAt` to now, not null, since `Feed` cannot null it.
5. **Bluesky timeline entries skip the classifier and are settled the way `PostEnricher::settle()` settles RSS posts.** This replaces "queue() then fill"; the end state is the same.
6. **A repost's headline comes from the post text, before the attribution is appended.** Otherwise an image-only repost would be titled "Reposted by …".
7. **The store step is `storeParsed(Feed, IncomingFeedModel, now)`, which carries the known shares.** It gets two siblings, `storeFailure()` and `storeThrottled()`.
8. **A new `RefreshPassFetcher` takes over `RefreshRunner`'s fetcher and persister.** A tenth constructor parameter would trip PHPMD.
9. **A Mastodon card that covers every link in its post queues no `LinkCards` row.**

## Decisions the brief left to the plan

- **Home uniqueness:** `remote_id` is `NOT NULL`, with `''` for Home, so the database's `(social_account_id, kind, remote_id)` unique key covers Home too, races included. Tested in Task 1.
- **`HostThrottle` key for social feeds: no code change.** `HostKey::forUrl('social:…')` finds no host and falls back to the whole URL, so a 429 throttles that one feed. A Retry-After is per call on these APIs, and the per-feed key keeps a throttled list feed from silencing Home.
- **`collectionsAdded` is folded into `imported`.** The same goes for `alreadySubscribed` and `skippedOverLimit`. The toast already reads "N added", and `finish()` already refreshes when `imported > 0`.
- **Invalid collection → `InvalidSocialImportFeedException`, problem code `social_import_invalid_feed` (unchanged), title becomes "Not importable from this account".** One more `match` arm in `SocialProblems::resolve()` would push its cyclomatic complexity to PHPMD's limit of 10. The detail message says what was wrong.
- **At most 100 collections per import request** (`#[Assert\Count(max: 100)]`). Each one is a few flushes, and an account has a handful of lists.
- **A full account still connects.** When the Home timeline would exceed the subscription cap, connect keeps the account and skips Home; the picker offers Home again later.
- **The sidebar's social sections are hidden in Organise mode.** Organise mode reorders tags and feeds, and private feeds have a fixed order.
- **Settings → Organise keeps private feeds.** It calls `untaggedSubscriptions()` itself, and the exclusion lives in the store's `tagTree`/`untagged` computeds, not in that shared helper. Organise is where a feed is managed in bulk; the sidebar's tree is what the brief cleans up.
- **The Bluesky reply filter** reads `item.reply.parent.author`. A parent that is not a post view (not found, blocked), and so has no author, drops the reply.
- **`docs/architecture.md` is not changed.** No section lists source formats or the refresh path (checked with grep for `sourceFormat`, `RefreshRunner`, `FeedBodyParser`).

## Global Constraints

- CLAUDE.md "PHP code style" applies in full. Services in a module root are `final readonly`. Role folders: `Model/` (suffix `Model`), `Factory/`, `Pass/`, `Support/` (static-only, private constructor), `Exception/`, and a folder per interface. No abbreviations, no boolean flag parameters, guard clauses, at most three parameters. No `toArray()`/`jsonSerialize()` in `App\Service`; a cache shape is `toCacheEntry()`. Typed exceptions sit next to their service. Comments only when a future reader would otherwise get the code wrong.
- **HTTP statuses are `Symfony\Component\HttpFoundation\Response::HTTP_*` constants in `src` and `tests`.** Never a literal, never a home-made constant, a `MockResponse` `http_code` included.
- Model: `App\Entity\SocialFeed`, table `social_feed`: `socialAccount` (CASCADE), `feed` (CASCADE, unique), `kind` (`App\Enum\SocialFeedKind`: `home`, `list`, `custom_feed`), `remoteId` (list id / list at-uri / feed-generator at-uri; `''` for Home), `newestSeenId` (Mastodon cursor, unused on Bluesky). Unique `(social_account_id, kind, remote_id)`. `Feed` itself is untouched.
- Synthetic URL, unique per **account** (never per DID): `social:<network>:<accountId>:home`, `social:<network>:<accountId>:list:<remoteId>`, `social:<network>:<accountId>:feed:<remoteId>`. One pure builder, `Service/Social/Support/SocialFeedUrl`.
- `SourceFormat::BLUESKY_API = 'bluesky-api'`, `SourceFormat::MASTODON_API = 'mastodon-api'`, `SourceFormat::isSocial(string)`. Docblock unions extended.
- Feed rows get `title` (`Home` / list name / feed name) and `siteUrl`. Bluesky: `https://bsky.app/profile/<did>`, or the list/feed web URL. Mastodon: the instance origin, or `<instance>/lists/<id>`. Favicon lookup and `FeedWebsite` never see a `social:` URL.
- A private feed's subscription has `includeInAllItems = false`, `includeInForYou = false`, no tag, and counts against `SubscriptionLimitResolver`. Creating an existing one returns it unchanged.
- Home is created on connect. The picker offers Home as the first item of the Lists group, state new/subscribed. Disconnect unsubscribes the account's private feeds through `SubscriptionService::unsubscribeAll()` (orphan reclaim deletes Feed and entries), then removes the account, in one transaction.
- Fetching: social feeds never reach `ConcurrentFeedFetcher`. They are read sequentially, one API call per feed, inside the run's deadline.
  - Bluesky: PDS `app.bsky.feed.getTimeline` / `getListFeed?list=` / `getFeed?feed=`, Bearer accessJwt, `limit=50`, first page only.
  - Mastodon: `/api/v1/timelines/home` / `/api/v1/timelines/list/:id`, `limit=40`, `since_id=<cursor>` when set. The new cursor is written in the same flush as the entries.
- Bluesky session cache: per account, in pool `bluesky.session.cache`, accessJwt sealed with `InstanceSecretCipher` bound to the owner, lifetime 5400 s. A 400/401 `ExpiredToken`/`InvalidToken` drops the cached session and retries once with a fresh one.
- Errors:
  - `SocialCredentialsRejectedException` → account `markNeedsReconnect()`, feed failure message, polling paused (`findDue()` excludes feeds of an account that needs reconnecting).
  - Unreachable or malformed → `FeedScheduler::recordFailure`.
  - 429 → `recordThrottled` with Retry-After.
- Social feeds' interval floor is 15 minutes (other feeds keep 5).
- Mapping, common to both networks: headline `EntryTitle::of(null, $html)`; author = display name, falling back to the handle; authorUrl = profile; publishedAt in UTC; guid = post URI; url = web URL.
- Mapping, Bluesky:
  - Facets rendered by UTF-8 byte ranges; everything else escaped; blank lines → paragraphs, single breaks → `<br>`.
  - A repost shows the original post with a trailing "Reposted by <a>Name</a>".
  - A reply is kept only when its parent's author is the account itself or `viewer.following` is set.
- Mapping, Mastodon:
  - A reblog maps `status.reblog` with a trailing "Boosted by <a>Name</a>".
  - `spoiler_text` → a leading `<p><strong>CW: …</strong></p>`.
  - Images → Image media; video/gifv → Video media with a preview; the first image is the lead image.
  - **`status.card`** → a `LinkCardContent` via `StorableLinkCard::of`, appended at ingest. Its URL counts as carded, and the share kind is `Link`. No card, or one `StorableLinkCard` refuses: the generic `ShareLinkCards` path, as today.
- Exclusions: OPML export and backup export skip private feeds. A restore refuses a `social:` URL or social format. Search, For You and recommendations are already user-scoped.
- API:
  - `SubscriptionJson::one` gains `social` (see Deviation 1). Import candidates' `lists[]` / `customFeeds[]` items are `{kind, id, name, state, subscriptionId}`, and `lists` starts with Home.
  - Import POST gains `collections: [{kind, id, name}]`. Validation: the kind must suit the network (`custom_feed` is Bluesky-only), and the id format is checked; a mismatch is a 422.
- Frontend:
  - Picker: checkboxes for collections; subscribed ones are checked and disabled.
  - Sidebar: a Bluesky and a Mastodon section between Saved searches and Tags, collapsible like Tags and persisted (`sfr.bluesky.collapsed` / `sfr.mastodon.collapsed`), hidden when the network has no private feed. A handle label shows only when the network has several accounts. Rows reuse `SidebarFeedRowComponent`, and Home's label comes from i18n.
  - Private feeds leave the sidebar's tag tree and untagged list.
  - i18n en + de for every key.
- Datetimes are naive UTC.
- Commit format `type(#1599): subject`, no attribution lines. Branch `feature/1599-social-private-feeds` (checked out, off `develop` 7297ffbee).
- Backend tests: `php bin/phpunit <path>` from `backend/` (SQLite). Frontend tests only in the container: `docker compose exec -T frontend npm test -- <path>`; gate `docker compose exec -T frontend npm run check`.
- Every backend task ends with:
  - `composer check` (warm the cache first: `bin/console cache:warmup`);
  - `composer md`, clean for every touched `src` file (it scans all of `src`);
  - PhpStorm inspections (`mcp__phpstorm__lint_files`) on the changed PHP files: ERROR and WARNING block;
  - `composer infection:diff` before the PR (Task 14).
- The plan's code fixes content, not final line wrapping: where `composer cs` (PSR-12, 120 columns) or Prettier (100 columns) asks for a wrap, wrap.

## Pre-flight notes for the executor

1. **Concurrent sessions share this checkout.** Run `git status` and `git branch --show-current` before every task. Do not `checkout`, `reset` or `stash` without checking.
2. `MockHttpClient` hands the callback the URL **verbatim**: an encoded `at%3A%2F%2F…` stays encoded. This was measured in #1598 and is recorded in `docs/superpowers/plans/2026-10-12-1598-social-import-picker.md`, pre-flight note 4. Route keys in tests are exact strings.
3. `JsonNodeModel::string()` **trims**. Bluesky facet byte offsets index the untrimmed `record.text`, so the mapper reads it with the new `JsonNodeModel::text()` (Task 6).
4. SQLite enforces the FK cascades only because `SqliteConnectionSetupDriver` runs `PRAGMA foreign_keys = ON`. A `SocialFeed` row disappears with its `Feed` (orphan reclaim) and with its `SocialAccount`.
5. Bluesky allows about 300 `createSession` calls a day per account. The session cache (Task 6) exists for that limit; no test may route a real `createSession`.
6. `infection:diff` ignores untracked files and diffs against `origin/develop`. Commit everything and `git fetch origin develop` before the final run.
7. Where a step says "append to `<Test>`", add the shown methods inside the existing class and merge the shown `use` lines into its import block.
8. If `composer stan` reports a module cycle, look at it before changing anything; the plan expects none. This PR adds the edges `Refresh → Social`, `Social → Completion`, `Social → LinkCard`, `Social → Parser` and `Backup → Social`. A check of each module's `use App\Service\…` lines found no path back.

## File structure

Backend (`backend/`):

| File | Responsibility |
|---|---|
| `src/Enum/SourceFormat.php` (modify) | `BLUESKY_API`, `MASTODON_API`, `SOCIAL`, `isSocial()` |
| `src/Enum/SocialFeedKind.php` | home / list / custom feed |
| `src/Entity/SocialFeed.php`, `src/Repository/SocialFeedRepository.php` | the private-feed marker and its reads |
| `migrations/Version20261012130000.php` | `social_feed` table, MySQL + SQLite |
| `src/Service/Social/Model/{PrivateFeedModel,PrivateFeedOutcome,SocialFeedAddressModel}.php` | what a private feed is |
| `src/Service/Social/Support/{SocialFeedUrl,PrivateFeedSite,PrivateFeedRules}.php` | synthetic URL, web site, validity per network |
| `src/Service/Social/PrivateFeeds.php` | create / resume / remove |
| `src/Service/Social/{SocialAccountConnector,SocialAccountEditor}.php` (modify) | Home on connect, resume on reconnect, removal on disconnect |
| `src/Service/Feed/FeedScheduler.php` (modify) | 15-minute floor for social feeds |
| `src/Repository/FeedRepository.php` (modify) | due query skips accounts that need reconnecting |
| `src/Service/Bluesky/BlueskyPendingEmbed.php` (modify) | `bluesky-api` entries never await the AppView |
| `src/Service/Completion/Model/KnownSharesModel.php`, `src/Service/Completion/KnownShareSettler.php` | shares a network already answered |
| `src/Service/Completion/ShareEnrichmentQueue.php` (modify) | `queueWithKnownShares()` |
| `src/Service/Refresh/Model/IncomingFeedModel.php`, `src/Service/Refresh/FeedOutcomePersister.php` (modify) | the shared store step |
| `src/Service/Bluesky/Model/JsonNodeModel.php` (modify) | `text()`, `has()` |
| `src/Service/Social/Exception/{SocialNetworkUnreachableException (modify),SocialNetworkThrottledException}.php` | 429 |
| `src/Service/Social/Support/RetryAfter.php`, `src/Service/Social/MastodonApiClient.php` (modify) | Retry-After |
| `src/Service/Social/Crypto/BlueskySessionCipher.php`, `src/Service/Social/Model/SealedBlueskySessionModel.php`, `src/Service/Social/{BlueskySessionCache,BlueskySessions}.php` | sealed session cache |
| `src/Service/Social/Model/TimelinePageModel.php`, `src/Service/Social/Support/{BlueskyRichText,BlueskyTimelinePage,MastodonTimelinePage}.php` | pure mappers |
| `src/Service/Social/TimelineSource/{TimelineSourceInterface,BlueskyTimelineSource,MastodonTimelineSource}.php`, `src/Service/Social/TimelineReader/{TimelineReaderInterface,NetworkTimelineReader}.php` | per-network reads behind a keyed locator |
| `src/Service/Refresh/Pass/{BudgetedFeedQueue,RefreshPass}.php` (modify), `src/Service/Refresh/{SocialFeedRefresher,RefreshPassFetcher}.php`, `src/Service/Refresh/RefreshRunner/RefreshRunner.php` (modify) | refresh integration |
| `src/Repository/SubscriptionRepository.php`, `src/Service/Opml/OpmlExporter.php`, `src/Service/Backup/AccountBackupExporter.php`, `src/Service/Backup/Dto/FeedLine.php` (modify) | exclusions |
| `src/Http/SubscriptionJson.php`, `src/Controller/Api/SubscriptionController.php` (modify) | `social`, `socialAccounts` |
| `src/Service/Social/Model/{CollectionCandidateModel,CollectionImportModel}.php`, `ImportCandidatesModel`, `SocialImportModel`, `SocialImportResultModel` (modify), `ImportCandidates`, `SocialImporter` (modify), `src/Dto/Social/{SocialImportCollectionItem,SocialImportRequest}.php`, `src/Http/SocialImportJson.php`, `src/Http/Problem/ExceptionProblems/SocialProblems.php` (modify) | collections in the picker API |
| `config/packages/cache.yaml` (modify) | `bluesky.session.cache` |
| `tests/Support/{PrivateFeedsWiring,FeedOutcomePersisters,StubTimelineReader}.php`, `tests/Support/{PostEnrichers,RefreshRunners}.php` (modify) | test wiring |

Frontend (`frontend/`):

- `src/app/settings/social/social-import.service.ts`, `social-import-dialog.component.{ts,html,spec.ts}`, `social-accounts.service.{ts,spec.ts}`, `social-section.component.spec.ts`
- `src/app/reader/models.ts`, `src/app/reader/state/social-sections.{ts,spec.ts}`, `src/app/reader/state/subscriptions.store.{ts,spec.ts}`
- `src/app/reader/shell/sidebar/sidebar-social-section.component.{ts,html,scss,spec.ts}`, `sidebar.component.{ts,html,scss,spec.ts}`, `sidebar-feed-row.component.{ts,html}`, `_sidebar-row.scss`
- `src/app/reader/shell/reader-shell.component.html`, `src/app/reader/testing/subscription.factory.ts`, `src/app/reader/feeds/manage/edit-subscription-dialog.component.spec.ts`
- `public/i18n/{en,de}.json`

Docs: `docs/backup.md` §6.2, `docs/security.md` "Social accounts".

---

### Task 1: The `SocialFeed` model and its migration

**Files:**
- Modify: `backend/src/Enum/SourceFormat.php`
- Create: `backend/src/Enum/SocialFeedKind.php`
- Create: `backend/src/Entity/SocialFeed.php`
- Create: `backend/src/Repository/SocialFeedRepository.php`
- Create: `backend/migrations/Version20261012130000.php`
- Modify: `backend/src/Service/Subscription/SubscriptionCreator.php:39` (docblock)
- Modify: `backend/src/Http/SubscriptionJson.php:72-74` (comment)
- Modify: `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php:127-131`
- Modify: `docs/backup.md` §6.2 table
- Test: `backend/tests/Enum/SourceFormatTest.php`, `backend/tests/Repository/SocialFeedRepositoryTest.php`

**Interfaces:**
- Produces:
  - `SourceFormat::BLUESKY_API`, `SourceFormat::MASTODON_API` (string constants)
  - `SourceFormat::SOCIAL` (`list<string>`)
  - `SourceFormat::isSocial(string $sourceFormat): bool`
  - `enum SocialFeedKind: string { Home = 'home'; UserList = 'list'; CustomFeed = 'custom_feed' }`
  - `new SocialFeed(SocialAccount $socialAccount, Feed $feed, SocialFeedKind $kind, ?string $remoteId)`
  - `SocialFeed::getId(): ?int`, `requireId(): int`, `getSocialAccount(): SocialAccount`, `getFeed(): Feed`, `getKind(): SocialFeedKind`
  - `SocialFeed::getRemoteId(): ?string` (null for Home)
  - `SocialFeed::getNewestSeenId(): ?string`, `recordNewestSeen(?string $statusId): void` (null keeps the old value)
  - `SocialFeed::storedRemoteId(?string $remoteId): string`
  - `SocialFeedRepository::findOneByFeed(Feed $feed): ?SocialFeed`
  - `SocialFeedRepository::findOneFor(SocialAccount $account, SocialFeedKind $kind, ?string $remoteId): ?SocialFeed`
  - `SocialFeedRepository::findAllOf(SocialAccount $account): list<SocialFeed>`
  - `SocialFeedRepository::subscriptionsOf(SocialAccount $account): list<Subscription>`

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Enum/SourceFormatTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SourceFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceFormatTest extends TestCase
{
    #[DataProvider('formats')]
    public function testOnlyTheTwoNetworkApisAreSocial(string $sourceFormat, bool $social): void
    {
        self::assertSame($social, SourceFormat::isSocial($sourceFormat));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function formats(): iterable
    {
        yield 'bluesky' => [SourceFormat::BLUESKY_API, true];
        yield 'mastodon' => [SourceFormat::MASTODON_API, true];
        yield 'xml' => [SourceFormat::XML, false];
        yield 'scraped' => [SourceFormat::SCRAPED, false];
        yield 'wp-json' => [SourceFormat::WP_JSON, false];
        yield 'unknown' => ['bluesky', false];
    }
}
```

Create `backend/tests/Repository/SocialFeedRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Repository\SocialFeedRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialFeedRepositoryTest extends DbTestCase
{
    private const string WRITERS = 'at://did:plc:alice/app.bsky.graph.list/3l1';

    private User $user;
    private SocialAccount $account;
    private int $feedCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('social-feeds@example.test');
        $this->account = $this->persisted(SocialAccounts::blueskyOf($this->user));
    }

    public function testAHomeFeedIsFoundByItsAbsentRemoteId(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, null);

        self::assertSame($home, $this->repository()->findOneFor($this->account, SocialFeedKind::Home, null));
        self::assertNull($home->getRemoteId());
        self::assertNull($this->repository()->findOneFor($this->account, SocialFeedKind::UserList, null));
    }

    public function testASecondHomeFeedOfTheSameAccountIsRefusedByTheDatabase(): void
    {
        $this->socialFeed($this->account, SocialFeedKind::Home, null);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->socialFeed($this->account, SocialFeedKind::Home, null);
    }

    public function testAListIsFoundByItsRemoteIdAndByItsFeed(): void
    {
        $list = $this->socialFeed($this->account, SocialFeedKind::UserList, self::WRITERS);

        self::assertSame($list, $this->repository()->findOneFor($this->account, SocialFeedKind::UserList, self::WRITERS));
        self::assertSame($list, $this->repository()->findOneByFeed($list->getFeed()));
        self::assertSame(self::WRITERS, $list->getRemoteId());
    }

    public function testAnAccountsFeedsAndSubscriptionsAreItsOwnOnly(): void
    {
        $other = $this->persisted(SocialAccounts::mastodonOf($this->user));
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, null);
        $theirs = $this->socialFeed($other, SocialFeedKind::Home, null);
        $own = $this->subscribe($home);
        $this->subscribe($theirs);
        (new ProfileFeedSubscriptions($this->entityManager))->subscribe($this->user, 'https://bsky.app/profile/did:plc:bob/rss');

        self::assertSame([$own], $this->repository()->subscriptionsOf($this->account));
        self::assertSame([$home], $this->repository()->findAllOf($this->account));
    }

    public function testThePageNewestIdIsKeptAndAnEmptyPageKeepsTheLastOne(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, null);

        $home->recordNewestSeen('109');
        $home->recordNewestSeen(null);

        self::assertSame('109', $home->getNewestSeenId());
    }

    private function socialFeed(SocialAccount $account, SocialFeedKind $kind, ?string $remoteId): SocialFeed
    {
        $feed = new Feed('social:test:' . ++$this->feedCount);
        $this->entityManager->persist($feed);
        $socialFeed = new SocialFeed($account, $feed, $kind, $remoteId);
        $this->entityManager->persist($socialFeed);
        $this->entityManager->flush();

        return $socialFeed;
    }

    private function subscribe(SocialFeed $socialFeed): Subscription
    {
        $subscription = new Subscription($this->user, $socialFeed->getFeed(), new \DateTimeImmutable('2026-10-12 10:00:00'));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function persisted(object $entity): object
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }

    private function repository(): SocialFeedRepository
    {
        /** @var SocialFeedRepository $repository */
        $repository = $this->entityManager->getRepository(SocialFeed::class);

        return $repository;
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Enum/SourceFormatTest.php tests/Repository/SocialFeedRepositoryTest.php`
Expected: FAIL. `SourceFormat::BLUESKY_API` is undefined, and the class `App\Entity\SocialFeed` is not found.

- [ ] **Step 3: Extend `SourceFormat` and add `SocialFeedKind`**

In `backend/src/Enum/SourceFormat.php`, after the `WP_JSON` constant, add:

```php
    /** A Bluesky timeline, list or custom feed, read through the account's session (#1599). */
    public const string BLUESKY_API = 'bluesky-api';

    /** A Mastodon home or list timeline, read with the account's token (#1599). */
    public const string MASTODON_API = 'mastodon-api';

    /** The formats of private feeds: read through an account, never fetched by URL, never exported. */
    public const array SOCIAL = [self::BLUESKY_API, self::MASTODON_API];

    public static function isSocial(string $sourceFormat): bool
    {
        return \in_array($sourceFormat, self::SOCIAL, true);
    }
```

Create `backend/src/Enum/SocialFeedKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum SocialFeedKind: string
{
    case Home = 'home';
    case UserList = 'list';
    case CustomFeed = 'custom_feed';
}
```

- [ ] **Step 4: Create the entity and its repository**

Create `backend/src/Entity/SocialFeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SocialFeedKind;
use App\Repository\SocialFeedRepository;
use Doctrine\ORM\Mapping as ORM;

/** Marks a Feed as one account's private timeline, list or custom feed (#1599). */
#[ORM\Entity(repositoryClass: SocialFeedRepository::class)]
#[ORM\Table(name: 'social_feed')]
#[ORM\Index(name: 'idx_social_feed_account', columns: ['social_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_social_feed_feed', columns: ['feed_id'])]
#[ORM\UniqueConstraint(name: 'uniq_social_feed_remote', columns: ['social_account_id', 'kind', 'remote_id'])]
final class SocialFeed
{
    use PersistedId;

    /** Home has no remote id; stored as '' because SQL never finds two NULLs equal, which would let Home repeat. */
    private const string NO_REMOTE_ID = '';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialAccount $socialAccount;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Feed $feed;

    #[ORM\Column(length: 16, enumType: SocialFeedKind::class)]
    private SocialFeedKind $kind;

    #[ORM\Column(length: 512)]
    private string $remoteId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $newestSeenId = null;

    public function __construct(SocialAccount $socialAccount, Feed $feed, SocialFeedKind $kind, ?string $remoteId)
    {
        $this->socialAccount = $socialAccount;
        $this->feed = $feed;
        $this->kind = $kind;
        $this->remoteId = self::storedRemoteId($remoteId);
    }

    public static function storedRemoteId(?string $remoteId): string
    {
        return $remoteId ?? self::NO_REMOTE_ID;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSocialAccount(): SocialAccount
    {
        return $this->socialAccount;
    }

    public function getFeed(): Feed
    {
        return $this->feed;
    }

    public function getKind(): SocialFeedKind
    {
        return $this->kind;
    }

    public function getRemoteId(): ?string
    {
        return self::NO_REMOTE_ID === $this->remoteId ? null : $this->remoteId;
    }

    public function getNewestSeenId(): ?string
    {
        return $this->newestSeenId;
    }

    public function recordNewestSeen(?string $statusId): void
    {
        if (null !== $statusId) {
            $this->newestSeenId = $statusId;
        }
    }
}
```

Create `backend/src/Repository/SocialFeedRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Enum\SocialFeedKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialFeed>
 */
final class SocialFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialFeed::class);
    }

    public function findOneByFeed(Feed $feed): ?SocialFeed
    {
        return $this->findOneBy(['feed' => $feed]);
    }

    public function findOneFor(SocialAccount $account, SocialFeedKind $kind, ?string $remoteId): ?SocialFeed
    {
        return $this->findOneBy([
            'socialAccount' => $account,
            'kind' => $kind,
            'remoteId' => SocialFeed::storedRemoteId($remoteId),
        ]);
    }

    /** @return list<SocialFeed> */
    public function findAllOf(SocialAccount $account): array
    {
        // @phpstan-ignore arrayValues.list (deliberate: findBy() promises a list only in its @phpstan-return)
        return array_values($this->findBy(['socialAccount' => $account], ['id' => 'ASC']));
    }

    /** @return list<Subscription> */
    public function subscriptionsOf(SocialAccount $account): array
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->join(SocialFeed::class, 'sf', 'WITH', 'sf.feed = s.feed')
            ->andWhere('sf.socialAccount = :account')
            ->setParameter('account', $account)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $subscriptions;
    }
}
```

- [ ] **Step 5: Write the migration**

Create `backend/migrations/Version20261012130000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create social_feed: the private timeline, list and custom feeds of social accounts (#1599).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('social_feed'), 'social_feed already exists.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE social_feed (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL,'
                . ' remote_id VARCHAR(512) NOT NULL, newest_seen_id VARCHAR(64) DEFAULT NULL,'
                . ' social_account_id INT NOT NULL, feed_id INT NOT NULL,'
                . ' INDEX idx_social_feed_account (social_account_id),'
                . ' UNIQUE INDEX uniq_social_feed_feed (feed_id),'
                . ' UNIQUE INDEX uniq_social_feed_remote (social_account_id, kind, remote_id), PRIMARY KEY (id))'
                . ' DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE social_feed ADD CONSTRAINT fk_social_feed_account'
                . ' FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE social_feed ADD CONSTRAINT fk_social_feed_feed'
                . ' FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE');

            return;
        }

        $this->addSql('CREATE TABLE social_feed (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL, remote_id VARCHAR(512) NOT NULL, newest_seen_id VARCHAR(64) DEFAULT NULL,'
            . ' social_account_id INTEGER NOT NULL, feed_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_social_feed_account FOREIGN KEY (social_account_id) REFERENCES social_account (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_feed_feed FOREIGN KEY (feed_id) REFERENCES feed (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_social_feed_account ON social_feed (social_account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_feed_feed ON social_feed (feed_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_feed_remote ON social_feed (social_account_id, kind, remote_id)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('social_feed'), 'social_feed does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE social_feed');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
```

- [ ] **Step 6: Declare the entity as wholly dropped from backups**

In `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, add `use App\Entity\SocialFeed;` beside `use App\Entity\SocialAccount;`. In `ACCOUNT_SCOPED_WHOLLY_DROPPED`, directly after the `SocialAccount::class => …,` entry, add:

```php
        SocialFeed::class => 'Which feeds are a connected account\'s private timeline, lists and custom feeds (#1599). '
            . 'They belong to an account a backup drops; the export leaves their feeds and posts out too.',
```

In `docs/backup.md` §6.2, directly below the `` | `SocialAccount` | … | `` row, add:

```markdown
| `SocialFeed` | Which of your feeds are the private timeline, lists and custom feeds of a connected Bluesky or Mastodon account. They belong to that account, which the file leaves out, so the export also leaves out their feeds, subscriptions and posts. After a restore, reconnect the account and add them again in the import picker. |
```

- [ ] **Step 7: Extend the docblock unions**

In `backend/src/Service/Subscription/SubscriptionCreator.php`, change `@param 'xml'|'scraped'|'wp-json' $sourceFormat` to:

```php
     * @param 'xml'|'scraped'|'wp-json'|'bluesky-api'|'mastodon-api' $sourceFormat
```

In `backend/src/Http/SubscriptionJson.php`, replace the two comment lines above `'sourceFormat' => $feed->getSourceFormat(),` with:

```php
            // A SourceFormat value: lets the UI mark synthesized feeds and private social feeds.
```

- [ ] **Step 8: Run the tests and the migration checks**

Run: `cd backend && php bin/phpunit tests/Enum/SourceFormatTest.php tests/Repository/SocialFeedRepositoryTest.php tests/Service/Backup/Support/BackupSchemaCoverageTest.php`
Expected: PASS.

Run the SQLite migration leg from empty:

```bash
cd backend
rm -f var/migrate-check.db
DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:schema:validate
rm -f var/migrate-check.db
```

Expected: the migrations run, and `schema:validate` prints `[OK] The database schema is in sync with the mapping files.`

Run the MySQL leg against the Docker stack (from the repo root):

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: both `[OK]`.

- [ ] **Step 9: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Enum/SourceFormat.php backend/src/Enum/SocialFeedKind.php backend/src/Entity/SocialFeed.php \
  backend/src/Repository/SocialFeedRepository.php backend/migrations/Version20261012130000.php \
  backend/src/Service/Subscription/SubscriptionCreator.php backend/src/Http/SubscriptionJson.php \
  backend/tests/Enum/SourceFormatTest.php backend/tests/Repository/SocialFeedRepositoryTest.php \
  backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php docs/backup.md
git commit -m "feat(#1599): a SocialFeed marks a feed as an account's private timeline"
```

---

### Task 2: Creating, resuming and removing private feeds

**Files:**
- Create: `backend/src/Service/Social/Model/PrivateFeedModel.php`
- Create: `backend/src/Service/Social/Model/PrivateFeedOutcome.php`
- Create: `backend/src/Service/Social/Model/SocialFeedAddressModel.php`
- Create: `backend/src/Service/Social/Support/SocialFeedUrl.php`
- Create: `backend/src/Service/Social/Support/PrivateFeedSite.php`
- Create: `backend/src/Service/Social/PrivateFeeds.php`
- Create: `backend/tests/Support/PrivateFeedsWiring.php`
- Test: `backend/tests/Service/Social/Support/SocialFeedUrlTest.php`, `backend/tests/Service/Social/Support/PrivateFeedSiteTest.php`, `backend/tests/Service/Social/PrivateFeedsTest.php`

**Interfaces:**
- Consumes: Task 1's `SocialFeed`, `SocialFeedRepository`, `SocialFeedKind`, `SourceFormat::BLUESKY_API` / `MASTODON_API`.
- Produces:
  - `PrivateFeedModel` (public `SocialFeedKind $kind`, `?string $remoteId`, `string $name`)
  - `PrivateFeedModel::home(): self`, `userList(string $listId, string $name): self`, `customFeed(string $feedUri, string $name): self`
  - `PrivateFeedModel::of(SocialFeedKind $kind, ?string $remoteId, string $name): self`
  - `PrivateFeedModel::key(): string`, `PrivateFeedModel::keyOf(SocialFeedKind $kind, ?string $remoteId): string` (`"<kind>:<remoteId or ''>"`)
  - `enum PrivateFeedOutcome { Created; AlreadySubscribed }`
  - `SocialFeedAddressModel(SocialNetwork $network, int $accountId, SocialFeedKind $kind)`
  - `SocialFeedUrl::of(SocialAccount $account, PrivateFeedModel $privateFeed): string`
  - `SocialFeedUrl::isPrivate(string $url): bool`
  - `SocialFeedUrl::addressOf(string $url): ?SocialFeedAddressModel`
  - `PrivateFeedSite::of(SocialAccount $account, PrivateFeedModel $privateFeed): string`
  - `PrivateFeeds::create(SocialAccount $account, PrivateFeedModel $privateFeed): PrivateFeedOutcome`, which throws `SubscriptionLimitReachedException` before anything is written
  - `PrivateFeeds::resume(SocialAccount $account): void` (the caller flushes)
  - `PrivateFeeds::removeAllOf(SocialAccount $account): int`
  - Test support: `PrivateFeedsWiring::build(ContainerInterface $container, EntityManagerInterface $entityManager, ClockInterface $clock): PrivateFeeds`

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Social/Support/SocialFeedUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SocialFeedAddressModel;
use App\Service\Social\Support\SocialFeedUrl;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialFeedUrlTest extends TestCase
{
    use AssignsEntityIds;

    private const string LIST = 'at://did:plc:bob/app.bsky.graph.list/3l1';
    private const string FEED = 'at://did:plc:bob/app.bsky.feed.generator/news';

    #[DataProvider('blueskyFeeds')]
    public function testAPrivateFeedIsAddressedByItsAccountNotItsDid(PrivateFeedModel $privateFeed, string $url): void
    {
        self::assertSame($url, SocialFeedUrl::of(self::withId(SocialAccounts::bluesky(), 12), $privateFeed));
    }

    /** @return iterable<string, array{PrivateFeedModel, string}> */
    public static function blueskyFeeds(): iterable
    {
        yield 'home' => [PrivateFeedModel::home(), 'social:bluesky:12:home'];
        yield 'list' => [PrivateFeedModel::userList(self::LIST, 'Writers'), 'social:bluesky:12:list:' . self::LIST];
        yield 'feed' => [PrivateFeedModel::customFeed(self::FEED, 'News'), 'social:bluesky:12:feed:' . self::FEED];
    }

    public function testAMastodonListIsAddressedByItsId(): void
    {
        self::assertSame(
            'social:mastodon:4:list:42',
            SocialFeedUrl::of(self::withId(SocialAccounts::mastodon(), 4), PrivateFeedModel::userList('42', 'Friends')),
        );
    }

    #[DataProvider('addresses')]
    public function testAnAddressIsReadBackFromTheUrl(string $url, ?SocialFeedAddressModel $address): void
    {
        self::assertEquals($address, SocialFeedUrl::addressOf($url));
    }

    /** @return iterable<string, array{string, ?SocialFeedAddressModel}> */
    public static function addresses(): iterable
    {
        yield 'home' => ['social:bluesky:12:home', new SocialFeedAddressModel(SocialNetwork::Bluesky, 12, SocialFeedKind::Home)];
        yield 'list with colons' => [
            'social:bluesky:12:list:' . self::LIST,
            new SocialFeedAddressModel(SocialNetwork::Bluesky, 12, SocialFeedKind::UserList),
        ];
        yield 'custom feed' => [
            'social:bluesky:12:feed:' . self::FEED,
            new SocialFeedAddressModel(SocialNetwork::Bluesky, 12, SocialFeedKind::CustomFeed),
        ];
        yield 'mastodon list' => ['social:mastodon:4:list:42', new SocialFeedAddressModel(SocialNetwork::Mastodon, 4, SocialFeedKind::UserList)];
        yield 'an rss feed' => ['https://bsky.app/profile/did:plc:bob/rss', null];
        yield 'an unknown network' => ['social:threads:4:home', null];
        yield 'an unknown kind' => ['social:bluesky:4:starter-pack:x', null];
        yield 'no account id' => ['social:bluesky:alice:home', null];
    }

    public function testOnlyTheSocialSchemeIsPrivate(): void
    {
        self::assertTrue(SocialFeedUrl::isPrivate('social:bluesky:12:home'));
        self::assertFalse(SocialFeedUrl::isPrivate('https://example.com/social:bluesky'));
    }
}
```

Create `backend/tests/Service/Social/Support/PrivateFeedSiteTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Support\PrivateFeedSite;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrivateFeedSiteTest extends TestCase
{
    #[DataProvider('blueskyFeeds')]
    public function testABlueskyFeedLinksToItsPageOnBsky(PrivateFeedModel $privateFeed, string $site): void
    {
        self::assertSame($site, PrivateFeedSite::of(SocialAccounts::bluesky(), $privateFeed));
    }

    /** @return iterable<string, array{PrivateFeedModel, string}> */
    public static function blueskyFeeds(): iterable
    {
        yield 'home' => [PrivateFeedModel::home(), 'https://bsky.app/profile/did:plc:alice'];
        yield 'list' => [
            PrivateFeedModel::userList('at://did:plc:bob/app.bsky.graph.list/3l1', 'Writers'),
            'https://bsky.app/profile/did:plc:bob/lists/3l1',
        ];
        yield 'custom feed' => [
            PrivateFeedModel::customFeed('at://did:plc:bob/app.bsky.feed.generator/news', 'News'),
            'https://bsky.app/profile/did:plc:bob/feed/news',
        ];
        yield 'an unreadable uri' => [PrivateFeedModel::userList('not-a-uri', 'Odd'), 'https://bsky.app/profile/did:plc:alice'];
    }

    public function testAMastodonFeedLinksToItsInstance(): void
    {
        self::assertSame('https://mastodon.example', PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::home()));
        self::assertSame(
            'https://mastodon.example/lists/42',
            PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::userList('42', 'Friends')),
        );
    }
}
```

Create `backend/tests/Support/PrivateFeedsWiring.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Repository\SocialFeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Social\PrivateFeeds;
use App\Service\Subscription\SubscriptionCreator;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/** PrivateFeeds over the container's subscription services, with the test's own clock. */
final class PrivateFeedsWiring
{
    public static function build(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): PrivateFeeds {
        $creator = $container->get(SubscriptionCreator::class);
        Assert::assertInstanceOf(SubscriptionCreator::class, $creator);
        $subscriptionService = $container->get(SubscriptionService::class);
        Assert::assertInstanceOf(SubscriptionService::class, $subscriptionService);
        /** @var SocialFeedRepository $socialFeeds */
        $socialFeeds = $entityManager->getRepository(SocialFeed::class);
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = $entityManager->getRepository(Subscription::class);

        return new PrivateFeeds($socialFeeds, $subscriptions, $creator, $subscriptionService, $entityManager, $clock);
    }

    private function __construct()
    {
    }
}
```

Create `backend/tests/Service/Social/PrivateFeedsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Repository\SocialFeedRepository;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\PrivateFeedOutcome;
use App\Service\Social\PrivateFeeds;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Tests\DbTestCase;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PrivateFeedsTest extends DbTestCase
{
    private MockClock $clock;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-12 12:00:00', 'UTC');

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('private-feeds@example.test');
    }

    public function testTheHomeTimelineBecomesAPrivateFeedOutsideAllItemsAndForYou(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));

        $outcome = $this->privateFeeds()->create($account, PrivateFeedModel::home());

        self::assertSame(PrivateFeedOutcome::Created, $outcome);
        $subscription = $this->onlySubscription();
        $feed = $subscription->getFeed();
        self::assertSame(sprintf('social:bluesky:%d:home', $account->requireId()), $feed->getUrl());
        self::assertSame(SourceFormat::BLUESKY_API, $feed->getSourceFormat());
        self::assertSame('Home', $feed->getTitle());
        self::assertSame('https://bsky.app/profile/did:plc:alice', $feed->getSiteUrl());
        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou());
        self::assertCount(0, $subscription->getTags());
        $socialFeed = $this->socialFeeds()->findOneByFeed($feed);
        self::assertSame(SocialFeedKind::Home, $socialFeed?->getKind());
        self::assertSame($account->requireId(), $socialFeed?->getSocialAccount()->requireId());
    }

    public function testCreatingItAgainChangesNothing(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $this->privateFeeds()->create($account, PrivateFeedModel::home());

        $outcome = $this->privateFeeds()->create($account, PrivateFeedModel::home());

        self::assertSame(PrivateFeedOutcome::AlreadySubscribed, $outcome);
        self::assertSame(1, $this->entityManager->getRepository(Subscription::class)->count([]));
        self::assertSame(1, $this->entityManager->getRepository(SocialFeed::class)->count([]));
    }

    public function testAMastodonListLinksToTheListOnItsInstance(): void
    {
        $account = $this->stored(SocialAccounts::mastodonOf($this->user));

        $this->privateFeeds()->create($account, PrivateFeedModel::userList('42', 'Friends'));

        $feed = $this->onlySubscription()->getFeed();
        self::assertSame(sprintf('social:mastodon:%d:list:42', $account->requireId()), $feed->getUrl());
        self::assertSame(SourceFormat::MASTODON_API, $feed->getSourceFormat());
        self::assertSame('Friends', $feed->getTitle());
        self::assertSame('https://mastodon.example/lists/42', $feed->getSiteUrl());
    }

    public function testAFullAccountGetsNoPrivateFeedAndNothingIsWritten(): void
    {
        $this->user->setMaxSubscriptions(0);
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));

        try {
            $this->privateFeeds()->create($account, PrivateFeedModel::home());
            self::fail('The subscription cap must hold for private feeds too.');
        } catch (SubscriptionLimitReachedException) {
            self::assertSame(0, $this->entityManager->getRepository(Feed::class)->count([]));
            self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
        }
    }

    public function testRemovingAnAccountsPrivateFeedsKeepsItsOtherSubscriptions(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $this->privateFeeds()->create($account, PrivateFeedModel::home());
        $this->privateFeeds()->create($account, PrivateFeedModel::customFeed('at://did:plc:bob/app.bsky.feed.generator/news', 'News'));
        $follow = (new ProfileFeedSubscriptions($this->entityManager))
            ->subscribe($this->user, 'https://bsky.app/profile/did:plc:bob/rss');

        $removed = $this->privateFeeds()->removeAllOf($account);

        self::assertSame(2, $removed);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Subscription::class, $follow->requireId()));
        self::assertSame(1, $this->entityManager->getRepository(Subscription::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
        self::assertNull($this->entityManager->getRepository(Feed::class)->findOneBy([
            'url' => sprintf('social:bluesky:%d:home', $account->requireId()),
        ]));
    }

    public function testResumingMakesEveryPrivateFeedDueNow(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $this->privateFeeds()->create($account, PrivateFeedModel::home());
        $feed = $this->onlySubscription()->getFeed();
        $feed->scheduleNextFetchAt(new \DateTimeImmutable('2026-10-12 18:00:00'));
        $this->entityManager->flush();

        $this->privateFeeds()->resume($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Feed::class, $feed->requireId());
        self::assertSame('2026-10-12 12:00:00', $reloaded?->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    private function privateFeeds(): PrivateFeeds
    {
        return PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock);
    }

    private function stored(SocialAccount $account): SocialAccount
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function onlySubscription(): Subscription
    {
        $subscriptions = $this->entityManager->getRepository(Subscription::class)->findAll();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }

    private function socialFeeds(): SocialFeedRepository
    {
        /** @var SocialFeedRepository $socialFeeds */
        $socialFeeds = $this->entityManager->getRepository(SocialFeed::class);

        return $socialFeeds;
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/Support/SocialFeedUrlTest.php tests/Service/Social/Support/PrivateFeedSiteTest.php tests/Service/Social/PrivateFeedsTest.php`
Expected: FAIL. `App\Service\Social\Model\PrivateFeedModel` is not found.

- [ ] **Step 3: Write the models**

Create `backend/src/Service/Social/Model/PrivateFeedModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Enum\SocialFeedKind;

final readonly class PrivateFeedModel
{
    private const string HOME_NAME = 'Home';

    private function __construct(
        public SocialFeedKind $kind,
        public ?string $remoteId,
        public string $name,
    ) {
    }

    public static function home(): self
    {
        return new self(SocialFeedKind::Home, null, self::HOME_NAME);
    }

    public static function userList(string $listId, string $name): self
    {
        return new self(SocialFeedKind::UserList, $listId, $name);
    }

    public static function customFeed(string $feedUri, string $name): self
    {
        return new self(SocialFeedKind::CustomFeed, $feedUri, $name);
    }

    public static function of(SocialFeedKind $kind, ?string $remoteId, string $name): self
    {
        return match ($kind) {
            SocialFeedKind::Home => self::home(),
            SocialFeedKind::UserList => self::userList($remoteId ?? '', $name),
            SocialFeedKind::CustomFeed => self::customFeed($remoteId ?? '', $name),
        };
    }

    public static function keyOf(SocialFeedKind $kind, ?string $remoteId): string
    {
        return $kind->value . ':' . ($remoteId ?? '');
    }

    public function key(): string
    {
        return self::keyOf($this->kind, $this->remoteId);
    }
}
```

Create `backend/src/Service/Social/Model/PrivateFeedOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

enum PrivateFeedOutcome
{
    case Created;
    case AlreadySubscribed;
}
```

Create `backend/src/Service/Social/Model/SocialFeedAddressModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;

final readonly class SocialFeedAddressModel
{
    public function __construct(
        public SocialNetwork $network,
        public int $accountId,
        public SocialFeedKind $kind,
    ) {
    }
}
```

- [ ] **Step 4: Write the URL and site helpers**

Create `backend/src/Service/Social/Support/SocialFeedUrl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SocialFeedAddressModel;

/**
 * A private feed's synthetic URL, `social:<network>:<accountId>:<kind>[:<remoteId>]`. Keyed by the account, never the
 * DID: two users may connect the same network account, and each must get a feed row of their own.
 */
final class SocialFeedUrl
{
    private const string SCHEME = 'social:';
    private const string ACCOUNT_ID = '/^[1-9]\d*$/';

    public static function of(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $url = self::SCHEME . $account->getNetwork()->value . ':' . $account->requireId() . ':'
            . self::segmentOf($privateFeed->kind);

        return null === $privateFeed->remoteId ? $url : $url . ':' . $privateFeed->remoteId;
    }

    public static function isPrivate(string $url): bool
    {
        return str_starts_with($url, self::SCHEME);
    }

    public static function addressOf(string $url): ?SocialFeedAddressModel
    {
        if (!self::isPrivate($url)) {
            return null;
        }
        $parts = explode(':', $url, 5);
        $network = SocialNetwork::tryFrom($parts[1] ?? '');
        $kind = self::kindOf($parts[3] ?? '');
        $accountId = $parts[2] ?? '';
        if (null === $network || null === $kind || 1 !== preg_match(self::ACCOUNT_ID, $accountId)) {
            return null;
        }

        return new SocialFeedAddressModel($network, (int) $accountId, $kind);
    }

    private static function segmentOf(SocialFeedKind $kind): string
    {
        return match ($kind) {
            SocialFeedKind::Home => 'home',
            SocialFeedKind::UserList => 'list',
            SocialFeedKind::CustomFeed => 'feed',
        };
    }

    private static function kindOf(string $segment): ?SocialFeedKind
    {
        return match ($segment) {
            'home' => SocialFeedKind::Home,
            'list' => SocialFeedKind::UserList,
            'feed' => SocialFeedKind::CustomFeed,
            default => null,
        };
    }

    private function __construct()
    {
    }
}
```

Create `backend/src/Service/Social/Support/PrivateFeedSite.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;

/** The web page a private feed stands for: its favicon and "visit site" link, so neither ever sees `social:`. */
final class PrivateFeedSite
{
    private const string BLUESKY_PROFILE = 'https://bsky.app/profile/';
    private const string BLUESKY_RECORD = '#^at://([^/]+)/(app\.bsky\.graph\.list|app\.bsky\.feed\.generator)/([^/]+)$#';
    private const string BLUESKY_LIST = 'app.bsky.graph.list';

    public static function of(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => self::bluesky($account, $privateFeed),
            SocialNetwork::Mastodon => self::mastodon($account, $privateFeed),
        };
    }

    private static function bluesky(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $remoteId = $privateFeed->remoteId ?? '';
        if (1 !== preg_match(self::BLUESKY_RECORD, $remoteId, $parts)) {
            return self::BLUESKY_PROFILE . $account->getIdentity();
        }
        $section = self::BLUESKY_LIST === $parts[2] ? 'lists' : 'feed';

        return self::BLUESKY_PROFILE . $parts[1] . '/' . $section . '/' . $parts[3];
    }

    private static function mastodon(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $instance = rtrim($account->getServiceUrl(), '/');
        if (SocialFeedKind::UserList !== $privateFeed->kind) {
            return $instance;
        }

        return $instance . '/lists/' . rawurlencode($privateFeed->remoteId ?? '');
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 5: Write `PrivateFeeds`**

Create `backend/src/Service/Social/PrivateFeeds.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\SocialFeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\PrivateFeedOutcome;
use App\Service\Social\Support\PrivateFeedSite;
use App\Service\Social\Support\SocialFeedUrl;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\SubscriptionCreator;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class PrivateFeeds
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private SubscriptionRepository $subscriptions,
        private SubscriptionCreator $subscriptionCreator,
        private SubscriptionService $subscriptionService,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @throws SubscriptionLimitReachedException before anything is written */
    public function create(SocialAccount $account, PrivateFeedModel $privateFeed): PrivateFeedOutcome
    {
        $socialFeed = $this->socialFeeds->findOneFor($account, $privateFeed->kind, $privateFeed->remoteId);
        if (null !== $socialFeed && $this->isSubscribed($account, $socialFeed->getFeed())) {
            return PrivateFeedOutcome::AlreadySubscribed;
        }

        $subscription = $this->subscriptionCreator->create(
            $account->getUser(),
            SocialFeedUrl::of($account, $privateFeed),
            self::sourceFormatOf($account),
            [],
            $privateFeed->name,
        );
        $subscription->setIncludeInAllItems(false);
        $subscription->setIncludeInForYou(false);
        $feed = $subscription->getFeed();
        $feed->setSiteUrl(PrivateFeedSite::of($account, $privateFeed));
        if (null === $socialFeed) {
            $this->entityManager->persist(new SocialFeed($account, $feed, $privateFeed->kind, $privateFeed->remoteId));
        }
        $this->entityManager->flush();

        return PrivateFeedOutcome::Created;
    }

    /** Makes the account's private feeds due at once, after a reconnect lifted their pause. The caller flushes. */
    public function resume(SocialAccount $account): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        foreach ($this->socialFeeds->findAllOf($account) as $socialFeed) {
            $socialFeed->getFeed()->scheduleNextFetchAt($now);
        }
    }

    public function removeAllOf(SocialAccount $account): int
    {
        return $this->subscriptionService->unsubscribeAll($this->socialFeeds->subscriptionsOf($account));
    }

    private function isSubscribed(SocialAccount $account, Feed $feed): bool
    {
        return $this->subscriptions->existsForUserAndFeed($account->getUser()->requireId(), $feed->requireId());
    }

    /** @return 'bluesky-api'|'mastodon-api' */
    private static function sourceFormatOf(SocialAccount $account): string
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => SourceFormat::BLUESKY_API,
            SocialNetwork::Mastodon => SourceFormat::MASTODON_API,
        };
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social/Support/SocialFeedUrlTest.php tests/Service/Social/Support/PrivateFeedSiteTest.php tests/Service/Social/PrivateFeedsTest.php`
Expected: PASS.

- [ ] **Step 7: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Social/Model/PrivateFeedModel.php backend/src/Service/Social/Model/PrivateFeedOutcome.php \
  backend/src/Service/Social/Model/SocialFeedAddressModel.php backend/src/Service/Social/Support/SocialFeedUrl.php \
  backend/src/Service/Social/Support/PrivateFeedSite.php backend/src/Service/Social/PrivateFeeds.php \
  backend/tests/Support/PrivateFeedsWiring.php backend/tests/Service/Social/Support/SocialFeedUrlTest.php \
  backend/tests/Service/Social/Support/PrivateFeedSiteTest.php backend/tests/Service/Social/PrivateFeedsTest.php
git commit -m "feat(#1599): private feeds are created, resumed and removed per account"
```

---

### Task 3: Home on connect, resume on reconnect, removal on disconnect

**Files:**
- Modify: `backend/src/Service/Social/SocialAccountConnector.php`
- Modify: `backend/src/Service/Social/SocialAccountEditor.php`
- Test: `backend/tests/Service/Social/SocialAccountConnectorTest.php`, `backend/tests/Service/Social/SocialAccountEditorTest.php`, `backend/tests/Service/Account/AccountDeleterTest.php`

**Interfaces:**
- Consumes:
  - `PrivateFeeds::create()`, `resume()` and `removeAllOf()`
  - `PrivateFeedModel::home()`
  - `PrivateFeedsWiring::build()`
  - `SocialFeedRepository::findOneFor()`
- Produces:
  - `new SocialAccountConnector(AccountVerifierInterface, SocialAccountRepository, SocialAccountFactory, SocialCredentialCipher, ClockInterface, EntityManagerInterface, PrivateFeeds)`
  - `new SocialAccountEditor(SocialAccountRepository, PrivateFeeds, EntityManagerInterface)`
  - Behaviour: `connect()` leaves a Home private feed (unless the account is at its cap); `reconnect()` makes the account's private feeds due now; `disconnect()` removes its private feeds and the account in one transaction.

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Service/Social/SocialAccountConnectorTest.php`, replace the `connector()` helper with:

```php
    private function connector(StubAccountVerifier $verifier): SocialAccountConnector
    {
        return new SocialAccountConnector(
            $verifier,
            $this->repository(),
            new SocialAccountFactory($this->cipher, $this->clock),
            $this->cipher,
            $this->clock,
            $this->entityManager,
            PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock),
        );
    }

    private function socialFeeds(): SocialFeedRepository
    {
        /** @var SocialFeedRepository $socialFeeds */
        $socialFeeds = $this->entityManager->getRepository(SocialFeed::class);

        return $socialFeeds;
    }
```

Append these tests:

```php
    public function testConnectingCreatesTheAccountsHomeTimelineFeed(): void
    {
        $account = $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());

        $home = $this->socialFeeds()->findOneFor($account, SocialFeedKind::Home, null);
        self::assertNotNull($home);
        self::assertSame(sprintf('social:bluesky:%d:home', $account->requireId()), $home->getFeed()->getUrl());
    }

    public function testAnAccountAtItsSubscriptionCapStillConnectsWithoutAHomeFeed(): void
    {
        $this->user->setMaxSubscriptions(0);
        $this->entityManager->flush();

        $account = $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());

        self::assertSame(SocialAccountStatus::Connected, $account->getStatus());
        self::assertNull($this->socialFeeds()->findOneFor($account, SocialFeedKind::Home, null));
    }

    public function testReconnectingMakesTheAccountsPrivateFeedsDueAgain(): void
    {
        $account = $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());
        $feed = $this->socialFeeds()->findOneFor($account, SocialFeedKind::Home, null)?->getFeed();
        self::assertNotNull($feed);
        $feed->scheduleNextFetchAt(new \DateTimeImmutable('2026-10-13 10:00:00'));
        $account->markNeedsReconnect();
        $this->entityManager->flush();

        $this->reconnect($this->bluesky('did:plc:alice'), $account);
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Feed::class, $feed->requireId());
        self::assertSame('2026-10-12 10:00:00', $reloaded?->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }
```

Merge into the imports: `App\Entity\Feed`, `App\Entity\SocialFeed`, `App\Enum\SocialFeedKind`, `App\Repository\SocialFeedRepository`, `App\Tests\Support\PrivateFeedsWiring`.

In `backend/tests/Service/Social/SocialAccountEditorTest.php`, replace the `editor()` helper with:

```php
    private function editor(): SocialAccountEditor
    {
        return new SocialAccountEditor($this->repository(), $this->privateFeeds(), $this->entityManager);
    }

    private function privateFeeds(): PrivateFeeds
    {
        return PrivateFeedsWiring::build(
            self::getContainer(),
            $this->entityManager,
            new MockClock('2026-10-12 12:00:00', 'UTC'),
        );
    }
```

Append:

```php
    public function testDisconnectingRemovesThePrivateFeedsAndKeepsTheImportedFollows(): void
    {
        $account = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);
        $this->privateFeeds()->create($account, PrivateFeedModel::home());
        $follow = (new ProfileFeedSubscriptions($this->entityManager))
            ->subscribe($this->user, 'https://bsky.app/profile/did:plc:bob/rss');
        $homeUrl = sprintf('social:bluesky:%d:home', $account->requireId());

        $this->editor()->disconnect($account);
        $this->entityManager->clear();

        self::assertNull($this->entityManager->getRepository(Feed::class)->findOneBy(['url' => $homeUrl]));
        self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
        self::assertNotNull($this->entityManager->find(Subscription::class, $follow->requireId()));
    }
```

Merge into the imports: `App\Entity\Feed`, `App\Entity\SocialFeed`, `App\Entity\Subscription`, `App\Service\Social\Model\PrivateFeedModel`, `App\Service\Social\PrivateFeeds`, `App\Tests\Support\PrivateFeedsWiring`, `App\Tests\Support\ProfileFeedSubscriptions`, `Symfony\Component\Clock\MockClock`.

In `backend/tests/Service/Account/AccountDeleterTest.php`, append:

```php
    public function testDeletionTakesTheAccountsPrivateSocialFeeds(): void
    {
        $admin = $this->userFactory->create('admin-social@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->userFactory->create('target-social@example.com');
        $account = SocialAccounts::blueskyOf($target);
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, new MockClock('2026-10-12 12:00:00', 'UTC'))
            ->create($account, PrivateFeedModel::home());
        $feed = $this->entityManager->getRepository(Feed::class)->findOneBy([
            'url' => sprintf('social:bluesky:%d:home', $account->requireId()),
        ]);
        self::assertNotNull($feed);
        $feedId = $feed->requireId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Feed::class, $feedId));
        self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
    }
```

Merge into the imports: `App\Entity\SocialFeed`, `App\Service\Social\Model\PrivateFeedModel`, `App\Tests\Support\PrivateFeedsWiring`, `App\Tests\Support\SocialAccounts`, `Symfony\Component\Clock\MockClock`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/SocialAccountConnectorTest.php tests/Service/Social/SocialAccountEditorTest.php tests/Service/Account/AccountDeleterTest.php`
Expected: FAIL with `ArgumentCountError`: the constructors take one parameter fewer than the helpers pass. `AccountDeleterTest::testDeletionTakesTheAccountsPrivateSocialFeeds` passes already; it pins the existing orphan reclaim.

- [ ] **Step 3: Wire `PrivateFeeds` into the connector**

In `backend/src/Service/Social/SocialAccountConnector.php`:

1. Add `private PrivateFeeds $privateFeeds,` as the last constructor parameter.
2. Add the imports `App\Service\Social\Model\PrivateFeedModel` and `App\Service\Subscription\Exception\SubscriptionLimitReachedException`.
3. In `connect()`, replace the final `return $account;` with:

```php
        $this->createHome($account);

        return $account;
```

4. In `reconnect()`, insert `$this->privateFeeds->resume($account);` directly before `$this->entityManager->flush();`.
5. Add the private method:

```php
    private function createHome(SocialAccount $account): void
    {
        try {
            $this->privateFeeds->create($account, PrivateFeedModel::home());
        } catch (SubscriptionLimitReachedException) {
            // A full account still connects; the import picker offers Home again once a slot is free.
        }
    }
```

- [ ] **Step 4: Remove private feeds on disconnect**

Replace `backend/src/Service/Social/SocialAccountEditor.php`'s constructor and `disconnect()` with:

```php
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private PrivateFeeds $privateFeeds,
        private EntityManagerInterface $entityManager,
    ) {
    }
```

```php
    public function disconnect(SocialAccount $account): void
    {
        $successor = $account->isDefault() ? ($this->siblingsOf($account)[0] ?? null) : null;

        $this->entityManager->wrapInTransaction(function () use ($account, $successor): void {
            $this->privateFeeds->removeAllOf($account);
            $this->entityManager->remove($account);
            $successor?->becomeDefault();
            $this->entityManager->flush();
        });
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Service/Account/AccountDeleterTest.php tests/Controller/Api/SocialAccountControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Social/SocialAccountConnector.php backend/src/Service/Social/SocialAccountEditor.php \
  backend/tests/Service/Social/SocialAccountConnectorTest.php backend/tests/Service/Social/SocialAccountEditorTest.php \
  backend/tests/Service/Account/AccountDeleterTest.php
git commit -m "feat(#1599): connecting creates the home timeline feed, disconnecting removes the private feeds"
```

---
### Task 4: Polling rules for private feeds — a 15-minute floor, a pause while reconnecting, no AppView queue

**Files:**
- Modify: `backend/src/Service/Feed/FeedScheduler.php`
- Modify: `backend/src/Repository/FeedRepository.php:175-184`
- Modify: `backend/src/Service/Bluesky/BlueskyPendingEmbed.php`
- Test: `backend/tests/Service/Feed/FeedSchedulerTest.php`, `backend/tests/Repository/DueSocialFeedsTest.php`, `backend/tests/Service/Bluesky/BlueskyPendingEmbedTest.php`

**Interfaces:**
- Consumes: `SourceFormat::isSocial()`, `SocialFeed`, `SocialAccountStatus::NeedsReconnect`.
- Produces:
  - `FeedScheduler` uses a 15-minute floor for social feeds everywhere it used 5.
  - `FeedRepository::findDue()` / `countDue()` never return a feed whose social account needs reconnecting (the `feedId` path included).
  - `BlueskyPendingEmbed::awaitsEmbed()` is false for an entry of a `bluesky-api` feed.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Service/Feed/FeedSchedulerTest.php`:

```php
    #[DataProvider('floors')]
    public function testNewEntriesResetTheIntervalToTheFeedsOwnFloor(string $sourceFormat, int $floorMinutes): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setSourceFormat($sourceFormat);

        FeedSchedulers::build(new MockClock('2026-10-12 12:00:00', 'UTC'))->recordSuccess($feed, 3);

        self::assertSame($floorMinutes, $feed->getFetchIntervalMinutes());
    }

    /** @return iterable<string, array{string, int}> */
    public static function floors(): iterable
    {
        yield 'rss' => [SourceFormat::XML, 5];
        yield 'bluesky timeline' => [SourceFormat::BLUESKY_API, 15];
        yield 'mastodon timeline' => [SourceFormat::MASTODON_API, 15];
    }

    public function testAQuietSocialFeedNeverPollsMoreOftenThanItsFloor(): void
    {
        $clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        $feed = new Feed('social:bluesky:1:home');
        $feed->setSourceFormat(SourceFormat::BLUESKY_API);
        $feed->recordSuccessfulFetch($clock->now(), 1);

        FeedSchedulers::build($clock)->recordSuccess($feed, 0);

        self::assertSame(15, $feed->getFetchIntervalMinutes());
    }

    public function testASocialFeedsBackoffStartsFromItsFloor(): void
    {
        $clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        $feed = new Feed('social:mastodon:1:home');
        $feed->setSourceFormat(SourceFormat::MASTODON_API);
        $feed->recordSuccessfulFetch($clock->now(), 1);

        FeedSchedulers::build($clock)->recordFailure($feed, 'The Mastodon instance answered 502.');

        self::assertSame('2026-10-12 12:30:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }
```

Merge into the imports: `App\Entity\Feed`, `App\Enum\SourceFormat`, `App\Tests\Support\FeedSchedulers`, `PHPUnit\Framework\Attributes\DataProvider`, `Symfony\Component\Clock\MockClock` (skip any already present).

Create `backend/tests/Repository/DueSocialFeedsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DueSocialFeedsTest extends DbTestCase
{
    private const string NOW = '2026-10-12 12:00:00';

    public function testAFeedWhoseAccountNeedsReconnectingIsNeverDue(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('due-social@example.test');
        $connected = SocialAccounts::blueskyOf($user);
        $stale = SocialAccounts::mastodonOf($user);
        $stale->markNeedsReconnect();
        $this->entityManager->persist($connected);
        $this->entityManager->persist($stale);
        $reachable = $this->privateFeed($connected, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        $paused = $this->privateFeed($stale, 'social:mastodon:2:home', SourceFormat::MASTODON_API);
        $rss = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($rss);
        $this->entityManager->flush();
        $now = new \DateTimeImmutable(self::NOW);

        $due = $this->feeds()->findDue(new DueFeedCriteria($now), 10);

        $expected = [$reachable->requireId(), $rss->requireId()];
        sort($expected);
        $dueIds = array_map(static fn (Feed $feed): int => $feed->requireId(), $due);
        sort($dueIds);
        self::assertSame($expected, $dueIds);
        self::assertSame(2, $this->feeds()->countDue(new DueFeedCriteria($now)));
        self::assertSame([], $this->feeds()->findDue(new DueFeedCriteria($now, feedId: $paused->requireId()), 10));
    }

    private function privateFeed(SocialAccount $account, string $url, string $sourceFormat): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new SocialFeed($account, $feed, SocialFeedKind::Home, null));

        return $feed;
    }

    private function feeds(): FeedRepository
    {
        /** @var FeedRepository $feeds */
        $feeds = $this->entityManager->getRepository(Feed::class);

        return $feeds;
    }
}
```

Create `backend/tests/Service/Bluesky/BlueskyPendingEmbedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Bluesky\BlueskyPendingEmbed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyPendingEmbedTest extends TestCase
{
    private const string AT_POST = 'at://did:plc:bob/app.bsky.feed.post/3abc';

    #[DataProvider('entries')]
    public function testOnlyAPostFromABlueskyRssFeedWaitsForTheAppView(
        string $sourceFormat,
        string $guid,
        bool $awaits,
    ): void {
        $feed = new Feed('https://bsky.app/profile/did:plc:bob/rss');
        $feed->setSourceFormat($sourceFormat);
        $now = new \DateTimeImmutable('2026-10-12 12:00:00');

        $entry = new Entry($feed, $guid, null, 'Post', $now, $now);

        self::assertSame($awaits, (new BlueskyPendingEmbed())->awaitsEmbed($entry));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function entries(): iterable
    {
        yield 'rss post' => [SourceFormat::XML, self::AT_POST, true];
        yield 'timeline post, embed already in hand' => [SourceFormat::BLUESKY_API, self::AT_POST, false];
        yield 'not a post' => [SourceFormat::XML, 'https://example.com/a', false];
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Feed/FeedSchedulerTest.php tests/Repository/DueSocialFeedsTest.php tests/Service/Bluesky/BlueskyPendingEmbedTest.php`
Expected: FAIL:
- the `bluesky timeline` / `mastodon timeline` floor cases get `5`;
- the due test also returns the paused feed;
- the `timeline post` case answers `true`.

- [ ] **Step 3: Give `FeedScheduler` a floor per feed**

In `backend/src/Service/Feed/FeedScheduler.php`:

1. Add the constant `private const int SOCIAL_FLOOR_MINUTES = 15;` below `FLOOR_MINUTES`.
2. Add `use App\Enum\SourceFormat;`.
3. In `recordSuccess()`, replace `self::FLOOR_MINUTES` with `self::floorOf($feed)`.
4. In `backoffMinutes()`, replace `max($feed->getFetchIntervalMinutes(), self::FLOOR_MINUTES)` with `max($feed->getFetchIntervalMinutes(), self::floorOf($feed))`.
5. In `grownInterval()`, replace `max(self::FLOOR_MINUTES, …)` with `max(self::floorOf($feed), …)`.
6. Add:

```php
    /** A private social feed costs its account an API call per poll, so it polls at most every 15 minutes. */
    private static function floorOf(Feed $feed): int
    {
        return SourceFormat::isSocial($feed->getSourceFormat()) ? self::SOCIAL_FLOOR_MINUTES : self::FLOOR_MINUTES;
    }
```

- [ ] **Step 4: Keep paused accounts out of the due query**

In `backend/src/Repository/FeedRepository.php`:

1. Add the imports `App\Entity\SocialFeed` and `App\Enum\SocialAccountStatus`.
2. In `dueQueryBuilder()`, add `$this->restrictToConnectedAccounts($qb);` after `$this->restrictToUnhandled($qb, $criteria);`.
3. Add:

```php
    /** A private feed of an account whose credential was refused waits for the reconnect, which makes it due again. */
    private function restrictToConnectedAccounts(QueryBuilder $qb): void
    {
        $qb->andWhere(sprintf(
            'NOT EXISTS (SELECT sf.id FROM %s sf JOIN sf.socialAccount sa'
            . ' WHERE sf.feed = f AND sa.status = :needsReconnect)',
            SocialFeed::class,
        ))->setParameter('needsReconnect', SocialAccountStatus::NeedsReconnect);
    }
```

- [ ] **Step 5: Keep timeline posts off the AppView queue**

Replace the body of `backend/src/Service/Bluesky/BlueskyPendingEmbed.php::awaitsEmbed()` with:

```php
        return AtPostUri::matches($entry->getGuid())
            && SourceFormat::BLUESKY_API !== $entry->getFeed()->getSourceFormat();
```

and add `use App\Enum\SourceFormat;`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Feed tests/Repository tests/Service/Bluesky tests/Service/Refresh`
Expected: PASS.

- [ ] **Step 7: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Feed/FeedScheduler.php backend/src/Repository/FeedRepository.php \
  backend/src/Service/Bluesky/BlueskyPendingEmbed.php backend/tests/Service/Feed/FeedSchedulerTest.php \
  backend/tests/Repository/DueSocialFeedsTest.php backend/tests/Service/Bluesky/BlueskyPendingEmbedTest.php
git commit -m "feat(#1599): private feeds poll at most every 15 minutes and pause while their account needs reconnecting"
```

---

### Task 5: Known shares and the persister's shared store step

**Files:**
- Create: `backend/src/Service/Completion/Model/KnownSharesModel.php`
- Create: `backend/src/Service/Completion/KnownShareSettler.php`
- Modify: `backend/src/Service/Completion/ShareEnrichmentQueue.php`
- Create: `backend/src/Service/Refresh/Model/IncomingFeedModel.php`
- Modify: `backend/src/Service/Refresh/FeedOutcomePersister.php`
- Modify: `backend/tests/Support/PostEnrichers.php`
- Create: `backend/tests/Support/FeedOutcomePersisters.php`
- Test: `backend/tests/Service/Completion/ShareEnrichmentQueueTest.php`, `backend/tests/Service/Refresh/FeedOutcomePersisterTimelineTest.php`

**Interfaces:**
- Consumes:
  - `EntryEmbedWriterInterface::fill(Entry, JsonNodeModel): FillOutcome`
  - `LinkCardRepository::append(Entry, list<LinkCardContent> $stored, list<LinkCardContent> $cards)`
  - `SharedLinks::of(string)->uncardedBy(list<string>): list<string>`
- Produces:
  - `KnownSharesModel`:
    - `KnownSharesModel::none(): self`
    - `KnownSharesModel::ofBlueskyPosts(array<string, JsonNodeModel> $postsByGuid): self`
    - `KnownSharesModel::ofLinkCards(array<string, LinkCardContent> $cardsByGuid): self`
    - `blueskyPostFor(string $guid): ?JsonNodeModel`, `linkCardFor(string $guid): ?LinkCardContent`
  - `KnownShareSettler::settleEmbedded(Entry, JsonNodeModel, \DateTimeImmutable): void`, `settleCarded(Entry, LinkCardContent, \DateTimeImmutable): void`
  - `ShareEnrichmentQueue::queueWithKnownShares(list<Entry> $createdEntries, KnownSharesModel $knownShares): void` (`queue()` keeps its signature)
  - `new ShareEnrichmentQueue(EntityManagerInterface, NaiveUtcClock, EntryShareClassifier, PendingEmbedInterface, KnownShareSettler)`
  - `IncomingFeedModel(ParsedFeedModel $parsed, KnownSharesModel $knownShares)`, `IncomingFeedModel::parsed(ParsedFeedModel): self`
  - `FeedOutcomePersister`:
    - `storeParsed(Feed $feed, IncomingFeedModel $incoming, \DateTimeImmutable $now): FeedRefreshResultModel`
    - `storeFailure(Feed $feed, \Exception $failure): FeedRefreshResultModel`
    - `storeThrottled(Feed $feed, ?int $retryAfterSeconds): FeedRefreshResultModel`
  - Test support: `new FeedOutcomePersisters(ContainerInterface, EntityManagerInterface, ClockInterface)` with `->idle(): FeedOutcomePersister` and `->enrichingWith(PostEnricher): FeedOutcomePersister`; `PostEnrichers::embedWriter(EntityManagerInterface): EntryEmbedWriter`

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Service/Completion/ShareEnrichmentQueueTest.php`:

```php
    public function testAKnownBlueskyEmbedSettlesThePostWithoutTheAppView(): void
    {
        $guid = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
        $post = $this->post($guid, '<p>Progressives are calling on her to step down. www.motherjones.com/politics/202...</p>');

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::ofBlueskyPosts([$guid => Bluesky::post('external')]));
        $this->entityManager->flush();

        self::assertNotContains(ShareEnrichmentKind::AppView, $this->queuedKinds());
        $stored = $this->reload($post);
        self::assertSame(ShareKind::Link, $stored->getShareKind());
        self::assertCount(1, PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$stored->requireId()])[$stored->requireId()] ?? []);
    }

    public function testAKnownBlueskyPostWithoutAnEmbedSharesNothing(): void
    {
        $post = $this->post(self::AT_POST, '<p>See <a href="https://example.com/a">https://example.com/a</a></p>');

        $this->queue->queueWithKnownShares(
            [$post],
            KnownSharesModel::ofBlueskyPosts([self::AT_POST => JsonNodeModel::of(['uri' => self::AT_POST])]),
        );
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        self::assertNull($this->reload($post)->getShareKind());
    }

    public function testAMastodonCardCardsItsLinkWithoutAPageFetch(): void
    {
        $guid = 'https://mastodon.example/@someone/1';
        $post = $this->post($guid, '<p>Read <a href="https://example.com/story">https://example.com/story</a></p>');
        $card = new LinkCardContent('https://example.com/story', 'The story', 'What happened.', 'https://example.com/story.jpg', 'Example');

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::ofLinkCards([$guid => $card]));
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        $stored = $this->reload($post);
        self::assertSame(ShareKind::Link, $stored->getShareKind());
        self::assertEquals(
            [$card],
            PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$stored->requireId()])[$stored->requireId()] ?? [],
        );
    }

    public function testALinkTheCardDoesNotCoverStillWaitsForItsOwnCard(): void
    {
        $guid = 'https://mastodon.example/@someone/2';
        $post = $this->post(
            $guid,
            '<p><a href="https://example.com/story">https://example.com/story</a> and <a href="https://other.example/b">https://other.example/b</a></p>',
        );
        $card = new LinkCardContent('https://example.com/story', 'The story', null, null, 'Example');

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::ofLinkCards([$guid => $card]));
        $this->entityManager->flush();

        self::assertSame([$guid => ShareEnrichmentKind::LinkCards], $this->queuedKinds());
    }

    public function testWithoutACardALinkPostWaitsForThePageFetchAsBefore(): void
    {
        $guid = 'https://mastodon.example/@someone/3';
        $post = $this->post($guid, '<p>Read <a href="https://example.com/story">https://example.com/story</a></p>');

        $this->queue->queueWithKnownShares([$post], KnownSharesModel::none());
        $this->entityManager->flush();

        self::assertSame([$guid => ShareEnrichmentKind::LinkCards], $this->queuedKinds());
        self::assertSame(ShareKind::Link, $this->reload($post)->getShareKind());
    }
```

Merge into the imports: `App\Entity\LinkCardContent`, `App\Service\Bluesky\Model\JsonNodeModel`, `App\Service\Completion\Model\KnownSharesModel`, `App\Tests\Support\Bluesky`.

Create `backend/tests/Service/Refresh/FeedOutcomePersisterTimelineTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Enum\SourceFormat;
use App\Service\Bluesky\AppViewClient;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Fetch\HostThrottle;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Support\Bluesky;
use App\Tests\Support\FakeAppView;
use App\Tests\Support\FeedOutcomePersisters;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ReadsFixtures;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class FeedOutcomePersisterTimelineTest extends DbTestCase
{
    use ReadsFixtures;

    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
    private const string VIDEO_POST = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxhlfehxzi27';

    private MockClock $clock;
    private FakeAppView $appView;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-10 17:00:00', 'UTC');
        $this->appView = new FakeAppView();
        $this->appView->knowsFixture('external');
        $this->appView->knowsFixture('video');
    }

    public function testATimelinePostEndsAsTheSameRssPostDoesOnceTheAppViewFilledIt(): void
    {
        $rssFeed = $this->feed('https://bsky.app/profile/did:plc:qobvnkudcv3zlaklxxjduqoi/rss', SourceFormat::XML);
        $timelineFeed = $this->feed('social:bluesky:1:home', SourceFormat::BLUESKY_API);
        $persister = $this->persister();
        $persister->persist(
            $rssFeed,
            FetchOutcomeModel::succeeded(FetchResponseModel::fetched(
                $rssFeed->getUrl(),
                false,
                $this->fixture('Bluesky/motherjones.rss'),
                null,
                null,
            )),
            $this->clock->now(),
        );
        $appViewCalls = \count($this->appView->requests);

        $result = $persister->storeParsed(
            $timelineFeed,
            new IncomingFeedModel(
                $this->parsedMotherJones($rssFeed),
                KnownSharesModel::ofBlueskyPosts([
                    self::TISCH => Bluesky::post('external'),
                    self::VIDEO_POST => Bluesky::post('video'),
                ]),
            ),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->entriesCreated);
        self::assertSame($appViewCalls, \count($this->appView->requests));
        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count([]));
        foreach ([self::TISCH, self::VIDEO_POST] as $guid) {
            self::assertSame($this->stateOf($rssFeed, $guid), $this->stateOf($timelineFeed, $guid), $guid);
        }
    }

    public function testAStoredFailureBacksTheFeedOffAndKeepsTheMessage(): void
    {
        $feed = $this->feed('social:mastodon:1:home', SourceFormat::MASTODON_API);

        $result = $this->persister()->storeFailure($feed, new \RuntimeException('The Mastodon instance answered 502.'));

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        self::assertSame('The Mastodon instance answered 502.', $feed->getLastErrorMessage());
        self::assertSame(1, $feed->getConsecutiveFailures());
    }

    public function testAStoredThrottleOnlyMovesTheNextFetch(): void
    {
        $feed = $this->feed('social:mastodon:1:home', SourceFormat::MASTODON_API);

        $result = $this->persister()->storeThrottled($feed, 600);

        self::assertSame(FeedOutcome::Throttled, $result->outcome);
        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertSame('2026-10-10 17:10:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    private function persister(): FeedOutcomePersister
    {
        $throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);

        return (new FeedOutcomePersisters(self::getContainer(), $this->entityManager, $this->clock))->enrichingWith(
            PostEnrichers::build(
                $this->entityManager,
                $this->clock,
                new AppViewClient($this->appView, $throttle),
                new NullLogger(),
            ),
        );
    }

    private function parsedMotherJones(Feed $rssFeed): ParsedFeedModel
    {
        $bodyParser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return $bodyParser->parse($rssFeed, $this->fixture('Bluesky/motherjones.rss'));
    }

    private function feed(string $url, string $sourceFormat): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    /** @return array<string, mixed> */
    private function stateOf(Feed $feed, string $guid): array
    {
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed, 'guid.value' => $guid]);
        self::assertInstanceOf(Entry::class, $entry);
        $cards = PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$entry->requireId()]);

        return [
            'content' => $entry->getContentHtml(),
            'summary' => $entry->getSummary(),
            'shareKind' => $entry->getShareKind()?->value,
            'imageUrl' => $entry->getImageUrl(),
            'media' => serialize($entry->getMedia()),
            'cards' => serialize($cards[$entry->requireId()] ?? []),
        ];
    }
}
```

Create `backend/tests/Support/FeedOutcomePersisters.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Service\Bluesky\BlueskyEntryRule;
use App\Service\Bluesky\PostEnricher;
use App\Service\Ingest\PlatformEntryRules;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Search\EntryIndexer;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;

/** A FeedOutcomePersister over the real repositories and the container's body parsers, Bluesky rule included. */
final readonly class FeedOutcomePersisters
{
    public function __construct(
        private ContainerInterface $container,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function idle(): FeedOutcomePersister
    {
        return $this->enrichingWith(PostEnrichers::idle($this->entityManager, $this->clock));
    }

    public function enrichingWith(PostEnricher $postEnricher): FeedOutcomePersister
    {
        /** @var FeedRepository $feeds */
        $feeds = $this->entityManager->getRepository(Feed::class);
        $bodyParser = $this->container->get(FeedBodyParser::class);
        Assert::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return new FeedOutcomePersister(
            $this->entityManager,
            $feeds,
            $bodyParser,
            EntryIngestors::withPlatformRules(
                $this->entityManager,
                $this->clock,
                new PlatformEntryRules([new BlueskyEntryRule()]),
            ),
            FeedSchedulers::build($this->clock),
            new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            PostEnrichers::queue($this->entityManager, $this->clock),
            $postEnricher,
            new NullLogger(),
        );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Completion/ShareEnrichmentQueueTest.php tests/Service/Refresh/FeedOutcomePersisterTimelineTest.php`
Expected: FAIL. `App\Service\Completion\Model\KnownSharesModel` is not found.

- [ ] **Step 3: Write `KnownSharesModel` and `KnownShareSettler`**

Create `backend/src/Service/Completion/Model/KnownSharesModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Completion\Model;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;

/** What a network's own answer already says about a new entry's share, keyed by the entry's guid. */
final readonly class KnownSharesModel
{
    /**
     * @param array<string, JsonNodeModel>   $blueskyPosts
     * @param array<string, LinkCardContent> $linkCards
     */
    private function __construct(
        private array $blueskyPosts,
        private array $linkCards,
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /** @param array<string, JsonNodeModel> $postsByGuid the post views a timeline answered with */
    public static function ofBlueskyPosts(array $postsByGuid): self
    {
        return new self($postsByGuid, []);
    }

    /** @param array<string, LinkCardContent> $cardsByGuid the preview cards the instance already built */
    public static function ofLinkCards(array $cardsByGuid): self
    {
        return new self([], $cardsByGuid);
    }

    public function blueskyPostFor(string $guid): ?JsonNodeModel
    {
        return $this->blueskyPosts[$guid] ?? null;
    }

    public function linkCardFor(string $guid): ?LinkCardContent
    {
        return $this->linkCards[$guid] ?? null;
    }
}
```

Create `backend/src/Service/Completion/KnownShareSettler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Enum\ShareKind;
use App\Repository\LinkCardRepository;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriterInterface;
use App\Service\Bluesky\Model\FillOutcome;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Share\Support\SharedLinks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Settles a new entry whose network already answered its embed or its card, as the later passes would have. */
final readonly class KnownShareSettler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryEmbedWriterInterface $embedWriter,
        private LinkCardRepository $linkCards,
        private LoggerInterface $logger,
    ) {
    }

    /** Ends where PostEnricher::settle() leaves an RSS Bluesky post: one without an embed shares nothing. */
    public function settleEmbedded(Entry $entry, JsonNodeModel $post, \DateTimeImmutable $queuedAt): void
    {
        $outcome = $this->filled($entry, $post);
        if (FillOutcome::Unfilled === $outcome) {
            $entry->setShareKind(null);

            return;
        }
        if (FillOutcome::AwaitingLinkCards === $outcome) {
            $this->awaitLinkCards($entry, $queuedAt);
        }
    }

    /** The card is the one ShareLinkCards would build, so only links it does not cover still wait for a page fetch. */
    public function settleCarded(Entry $entry, LinkCardContent $card, \DateTimeImmutable $queuedAt): void
    {
        $this->linkCards->append($entry, [], [$card]);
        $entry->setShareKind(ShareKind::Link);
        if ([] !== SharedLinks::of($entry->getContentHtml() ?? '')->uncardedBy([$card->url])) {
            $this->awaitLinkCards($entry, $queuedAt);
        }
    }

    private function filled(Entry $entry, JsonNodeModel $post): FillOutcome
    {
        try {
            return $this->embedWriter->fill($entry, $post);
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Bluesky post {guid} could not be filled',
                ['guid' => $entry->getGuid(), 'exception' => $exception],
            );

            return FillOutcome::Unfilled;
        }
    }

    private function awaitLinkCards(Entry $entry, \DateTimeImmutable $queuedAt): void
    {
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::LinkCards, $queuedAt));
    }
}
```

- [ ] **Step 4: Route known shares through the queue**

Replace `backend/src/Service/Completion/ShareEnrichmentQueue.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Enum\ShareKind;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Share\EntryShareClassifier;
use App\Service\Share\PendingEmbed\PendingEmbedInterface;
use Doctrine\ORM\EntityManagerInterface;

/** Classifies each new entry and queues the ones whose embed or link cards are still to come. */
final readonly class ShareEnrichmentQueue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private EntryShareClassifier $classifier,
        private PendingEmbedInterface $pendingEmbed,
        private KnownShareSettler $knownShares,
    ) {
    }

    /** @param list<Entry> $createdEntries persisted, not yet flushed: the caller flushes */
    public function queue(array $createdEntries): void
    {
        $this->queueWithKnownShares($createdEntries, KnownSharesModel::none());
    }

    /** @param list<Entry> $createdEntries persisted, not yet flushed: the caller flushes */
    public function queueWithKnownShares(array $createdEntries, KnownSharesModel $knownShares): void
    {
        $queuedAt = $this->clock->now();
        foreach ($createdEntries as $entry) {
            $this->queueOne($entry, $knownShares, $queuedAt);
        }
    }

    private function queueOne(Entry $entry, KnownSharesModel $knownShares, \DateTimeImmutable $queuedAt): void
    {
        $post = $knownShares->blueskyPostFor($entry->getGuid());
        if (null !== $post) {
            $this->knownShares->settleEmbedded($entry, $post, $queuedAt);

            return;
        }
        $card = $knownShares->linkCardFor($entry->getGuid());
        if (null !== $card) {
            $this->knownShares->settleCarded($entry, $card, $queuedAt);

            return;
        }
        $this->classifier->classify($entry);
        $this->queueCreated($entry, $queuedAt);
    }

    private function queueCreated(Entry $entry, \DateTimeImmutable $queuedAt): void
    {
        if ($this->pendingEmbed->awaitsEmbed($entry)) {
            $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::AppView, $queuedAt));

            return;
        }
        if ($entry->getShareKind() !== ShareKind::Link) {
            return;
        }
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::LinkCards, $queuedAt));
    }
}
```

In `backend/tests/Support/PostEnrichers.php`:

1. In `build()`, replace the inline `new EntryEmbedWriter(…)` argument with `self::embedWriter($entityManager),`.
2. Replace `queue()` with:

```php
    public static function queue(EntityManagerInterface $entityManager, ClockInterface $clock): ShareEnrichmentQueue
    {
        return new ShareEnrichmentQueue(
            $entityManager,
            new NaiveUtcClock($clock),
            new EntryShareClassifier(new BlueskyPendingEmbed(), new EntrySanitizer(new TrailingBlankRemover())),
            new BlueskyPendingEmbed(),
            new KnownShareSettler(
                $entityManager,
                self::embedWriter($entityManager),
                self::linkCards($entityManager),
                new NullLogger(),
            ),
        );
    }

    public static function embedWriter(EntityManagerInterface $entityManager): EntryEmbedWriter
    {
        return new EntryEmbedWriter(
            new PostEmbedRenderer(),
            new EntrySanitizer(new TrailingBlankRemover()),
            new EntryImageWriter(),
            self::linkCards($entityManager),
        );
    }
```

3. Add `use App\Service\Completion\KnownShareSettler;`.

- [ ] **Step 5: Extract the store step in `FeedOutcomePersister`**

Create `backend/src/Service/Refresh/Model/IncomingFeedModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Parser\Model\ParsedFeedModel;

/** A feed's entries ready to store, with whatever its source already said about their shares. */
final readonly class IncomingFeedModel
{
    public function __construct(
        public ParsedFeedModel $parsed,
        public KnownSharesModel $knownShares,
    ) {
    }

    public static function parsed(ParsedFeedModel $parsed): self
    {
        return new self($parsed, KnownSharesModel::none());
    }
}
```

In `backend/src/Service/Refresh/FeedOutcomePersister.php`, replace everything from `public function persist(` down to (not including) `private function applyPermanentRedirect(` with:

```php
    /** @throws \DateMalformedStringException */
    public function persist(Feed $feed, FetchOutcomeModel $outcome, \DateTimeImmutable $now): FeedRefreshResultModel
    {
        // Read before recordSuccess() stamps the new lastSuccessfulFetchAt (#384).
        $context = new FeedIngestContext($now, $feed->getLastSuccessfulFetchAt());

        return $this->guarded($feed, fn (): FeedRefreshResultModel => $this->record($feed, $outcome, $context));
    }

    /** Entries a source mapped itself (a social timeline): stored through the very step a fetched body takes. */
    public function storeParsed(Feed $feed, IncomingFeedModel $incoming, \DateTimeImmutable $now): FeedRefreshResultModel
    {
        $context = new FeedIngestContext($now, $feed->getLastSuccessfulFetchAt());

        return $this->guarded($feed, fn (): FeedRefreshResultModel => $this->storeIngested($feed, $incoming, $context));
    }

    public function storeFailure(Feed $feed, \Exception $failure): FeedRefreshResultModel
    {
        return $this->guarded($feed, fn (): FeedRefreshResultModel => $this->recordFailure($feed, $failure));
    }

    public function storeThrottled(Feed $feed, ?int $retryAfterSeconds): FeedRefreshResultModel
    {
        return $this->guarded($feed, fn (): FeedRefreshResultModel => $this->recordThrottled($feed, $retryAfterSeconds));
    }

    /** @param \Closure(): FeedRefreshResultModel $store */
    private function guarded(Feed $feed, \Closure $store): FeedRefreshResultModel
    {
        try {
            return $store();
        } catch (
            UniqueConstraintViolationException |
            ForeignKeyConstraintViolationException |
            ORMException $exception
        ) {
            // A failed flush closes the EntityManager, so the run must stop here. The FK case is a feed whose
            // last subscriber left mid-run and whose row was reclaimed under the fetch.
            $this->logger->error(
                'Refresh aborted: persistence failed for {url}',
                ['url' => $feed->getUrl(), 'exception' => $exception],
            );

            return FeedRefreshResultModel::of(FeedOutcome::Aborted);
        }
    }

    private function record(Feed $feed, FetchOutcomeModel $outcome, FeedIngestContext $context): FeedRefreshResultModel
    {
        try {
            $response = $outcome->responseOrThrow();
            if ($response->notModified) {
                return $this->storeNotModified($feed, $response);
            }

            $parsed = $this->bodyParser->parse($feed, $response->modifiedBody());
            $feed->recordCacheValidators($response->etag, $response->lastModified);
            $this->applyPermanentRedirect($feed, $response);

            return $this->storeIngested($feed, IncomingFeedModel::parsed($parsed), $context);
        } catch (FeedThrottledException $exception) {
            return $this->recordThrottled($feed, $exception->retryAfterSeconds);
        } catch (FeedGoneException $exception) {
            return $this->recordGone($feed, $exception);
        } catch (FetchException | FeedParseException $exception) {
            return $this->recordFailure($feed, $exception);
        }
    }

    /** @throws \DateMalformedStringException */
    private function storeNotModified(Feed $feed, FetchResponseModel $response): FeedRefreshResultModel
    {
        // A moved feed can answer 304 at its new address; without this the redirect chain is re-walked every time.
        $this->applyPermanentRedirect($feed, $response);
        $this->scheduler->recordNotModified($feed);
        $this->entityManager->flush();
        $this->postEnricher->enrich($feed);

        return FeedRefreshResultModel::of(FeedOutcome::NotModified);
    }

    /** @throws \DateMalformedStringException */
    private function storeIngested(
        Feed $feed,
        IncomingFeedModel $incoming,
        FeedIngestContext $context,
    ): FeedRefreshResultModel {
        $createdEntries = $this->ingestor->ingest($feed, $incoming->parsed, $context);
        // A backfilled image is not new content, so it stays out of the count.
        $this->ingestor->fillMissingImages($feed, $incoming->parsed);
        $this->scheduler->recordSuccess($feed, \count($createdEntries));
        $this->enrichmentQueue->queueWithKnownShares($createdEntries, $incoming->knownShares);
        $this->entityManager->flush();
        // Only the flush assigns ids, so indexing has to follow it.
        $this->indexer->index($createdEntries);
        $this->postEnricher->enrich($feed);

        return FeedRefreshResultModel::fetched(\count($createdEntries));
    }

    /** @throws \DateMalformedStringException */
    private function recordThrottled(Feed $feed, ?int $retryAfterSeconds): FeedRefreshResultModel
    {
        $this->scheduler->recordThrottled($feed, $retryAfterSeconds);
        $this->entityManager->flush();
        $this->logger->info('Feed rate limited: {url}', ['url' => $feed->getUrl()]);

        return FeedRefreshResultModel::of(FeedOutcome::Throttled);
    }

    private function recordGone(Feed $feed, FeedGoneException $gone): FeedRefreshResultModel
    {
        $this->scheduler->recordGone($feed, $gone->getMessage());
        $this->entityManager->flush();
        $this->logger->warning('Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $gone]);

        return FeedRefreshResultModel::of(FeedOutcome::Failed);
    }

    /** @throws \DateMalformedStringException */
    private function recordFailure(Feed $feed, \Exception $failure): FeedRefreshResultModel
    {
        $this->scheduler->recordFailure($feed, $failure->getMessage());
        $this->entityManager->flush();
        $this->logger->warning('Feed refresh failed: {url}', ['url' => $feed->getUrl(), 'exception' => $failure]);

        return FeedRefreshResultModel::of(FeedOutcome::Failed);
    }

```

Add `use App\Service\Refresh\Model\IncomingFeedModel;`. Remove the now-unused `use App\Entity\Entry;`.

Order note for the reviewer: the cache validators and the permanent redirect are now applied right after the parse, before the ingest. Before, they came after it. Neither reads entries, and `applyPermanentRedirect()`'s `findOneBy()` does not auto-flush, so nothing observable moves.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Completion tests/Service/Refresh tests/Service/Bluesky tests/Service/Subscription tests/Controller/Api/EntryCompletionControllerTest.php`
Expected: PASS. The parity test passes byte-for-byte; if a field differs, the message names the guid.

- [ ] **Step 7: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Completion backend/src/Service/Refresh/Model/IncomingFeedModel.php \
  backend/src/Service/Refresh/FeedOutcomePersister.php backend/tests/Support/PostEnrichers.php \
  backend/tests/Support/FeedOutcomePersisters.php backend/tests/Service/Completion/ShareEnrichmentQueueTest.php \
  backend/tests/Service/Refresh/FeedOutcomePersisterTimelineTest.php
git commit -m "feat(#1599): shares a network already answered settle at ingest, through the persister's shared store step"
```

---

### Task 6: A sealed Bluesky session cache, Retry-After and the JSON reads mapping needs

**Files:**
- Modify: `backend/src/Service/Bluesky/Model/JsonNodeModel.php`
- Modify: `backend/src/Service/Social/Exception/SocialNetworkUnreachableException.php`
- Create: `backend/src/Service/Social/Exception/SocialNetworkThrottledException.php`
- Create: `backend/src/Service/Social/Support/RetryAfter.php`
- Modify: `backend/src/Service/Social/MastodonApiClient.php`
- Create: `backend/src/Service/Social/Crypto/BlueskySessionCipher.php`
- Create: `backend/src/Service/Social/Model/SealedBlueskySessionModel.php`
- Create: `backend/src/Service/Social/BlueskySessionCache.php`
- Create: `backend/src/Service/Social/BlueskySessions.php`
- Modify: `backend/config/packages/cache.yaml`
- Modify: `docs/security.md` (Social accounts)
- Test: `backend/tests/Service/Bluesky/Model/JsonNodeModelTest.php`, `backend/tests/Service/Social/Support/RetryAfterTest.php`, `backend/tests/Service/Social/MastodonApiClientTest.php`, `backend/tests/Service/Social/Model/SealedBlueskySessionModelTest.php`, `backend/tests/Service/Social/BlueskySessionsTest.php`

**Interfaces:**
- Consumes: `BlueskySessionOpener::openForAccount(SocialAccount): BlueskySessionModel`, `InstanceSecretCipher::seal/open(SecretBindingModel, …)`.
- Produces:
  - `JsonNodeModel::text(string $key): ?string` (untrimmed) and `JsonNodeModel::has(string $key): bool` (present and not null)
  - `class SocialNetworkUnreachableException` (no longer final); `final class SocialNetworkThrottledException extends SocialNetworkUnreachableException` with `public readonly ?int $retryAfterSeconds` and constructor `(string $message, ?int $retryAfterSeconds = null, ?\Throwable $previous = null)`
  - `RetryAfter::seconds(?string $header): ?int`
  - `MastodonApiClient::send()` throws `SocialNetworkThrottledException` on 429
  - `BlueskySessionCipher::seal(int $userId, string $accessJwt): SealedSecret` and `open(int $userId, SealedSecret $sealed): string`
  - `SealedBlueskySessionModel(SealedSecret $accessJwt, string $handle, string $pdsUrl)` with `toCacheEntry(): array` and `static fromCacheEntryOrNull(mixed $stored): ?self`
  - `BlueskySessionCache::find(SocialAccount): ?BlueskySessionModel` and `remember(SocialAccount, BlueskySessionModel): void`
  - `BlueskySessions::current(SocialAccount): BlueskySessionModel` and `fresh(SocialAccount): BlueskySessionModel`; both throw `SocialCredentialsRejectedException` and `SocialNetworkUnreachableException`

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Service/Bluesky/Model/JsonNodeModelTest.php`:

```php
    public function testTextKeepsTheStringAsSentWhereStringTrimsIt(): void
    {
        $node = JsonNodeModel::of(['text' => "  hi\n", 'count' => 3]);

        self::assertSame("  hi\n", $node->text('text'));
        self::assertSame('hi', $node->string('text'));
        self::assertNull($node->text('count'));
        self::assertNull($node->text('absent'));
    }

    public function testHasTellsAPresentFieldFromAnAbsentOrNullOne(): void
    {
        $node = JsonNodeModel::of(['reply' => ['parent' => []], 'reblog' => null]);

        self::assertTrue($node->has('reply'));
        self::assertFalse($node->has('reblog'));
        self::assertFalse($node->has('reason'));
    }
```

Create `backend/tests/Service/Social/Support/RetryAfterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\RetryAfter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryAfterTest extends TestCase
{
    #[DataProvider('headers')]
    public function testOnlyADelayInSecondsNamesAWait(?string $header, ?int $seconds): void
    {
        self::assertSame($seconds, RetryAfter::seconds($header));
    }

    /** @return iterable<string, array{?string, ?int}> */
    public static function headers(): iterable
    {
        yield 'seconds' => ['120', 120];
        yield 'padded' => [' 30 ', 30];
        yield 'a date' => ['Wed, 21 Oct 2026 07:28:00 GMT', null];
        yield 'negative' => ['-5', null];
        yield 'absent' => [null, null];
    }
}
```

Create `backend/tests/Service/Social/MastodonApiClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonApiClientTest extends TestCase
{
    private const string HOME = 'https://mastodon.example/api/v1/timelines/home';

    public function testTooManyRequestsIsAThrottleCarryingItsDelay(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::HOME => new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '90'],
        ])]);
        $guard = new UrlGuard(new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]), new IpValidator());
        $client = new MastodonApiClient(FetchWiring::guardedApiClient($http->client(), $guard));

        try {
            $client->send(ApiRequestModel::get(self::HOME));
            self::fail('A 429 must not read as an answer.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(90, $exception->retryAfterSeconds);
            self::assertInstanceOf(SocialNetworkUnreachableException::class, $exception);
        }
    }
}
```

Create `backend/tests/Service/Social/Model/SealedBlueskySessionModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Entity\SealedSecret;
use App\Service\Social\Model\SealedBlueskySessionModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SealedBlueskySessionModelTest extends TestCase
{
    public function testACacheEntryReadsBackAsTheSameSession(): void
    {
        $session = new SealedBlueskySessionModel(
            new SealedSecret('c', 'n', 's', 2),
            'alice.bsky.social',
            'https://pds.example.test',
        );

        self::assertEquals($session, SealedBlueskySessionModel::fromCacheEntryOrNull($session->toCacheEntry()));
    }

    #[DataProvider('damagedEntries')]
    public function testADamagedEntryReadsAsNoSession(mixed $stored): void
    {
        self::assertNull(SealedBlueskySessionModel::fromCacheEntryOrNull($stored));
    }

    /** @return iterable<string, array{mixed}> */
    public static function damagedEntries(): iterable
    {
        $whole = [
            'ciphertext' => 'c',
            'nonce' => 'n',
            'salt' => 's',
            'version' => 2,
            'handle' => 'alice.bsky.social',
            'pdsUrl' => 'https://pds.example.test',
        ];
        yield 'not an array' => ['token'];
        yield 'no ciphertext' => [array_diff_key($whole, ['ciphertext' => true])];
        yield 'version as text' => [['version' => '2'] + $whole];
        yield 'no pds' => [array_diff_key($whole, ['pdsUrl' => true])];
    }
}
```

Create `backend/tests/Service/Social/BlueskySessionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\StoredCredential;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BlueskySessionsTest extends TestCase
{
    use AssignsEntityIds;

    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';

    public function testASecondPollReusesTheCachedSession(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-1')]);
        $sessions = $this->sessions($http, new ArrayAdapter());
        $account = self::account();

        $first = $sessions->current($account);
        $second = $sessions->current($account);

        self::assertSame('jwt-1', $first->accessJwt);
        self::assertEquals($first, $second);
        self::assertCount(1, $http->requests);
    }

    public function testAFreshSessionReplacesTheCachedOne(): void
    {
        $cache = new ArrayAdapter();
        $account = self::account();
        self::cacheOver($cache)->remember(
            $account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social', 'https://pds.example.test'),
        );
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-2')]);
        $sessions = $this->sessions($http, $cache);

        self::assertSame('stale-jwt', $sessions->current($account)->accessJwt);
        self::assertSame('jwt-2', $sessions->fresh($account)->accessJwt);
        self::assertSame('jwt-2', $sessions->current($account)->accessJwt);
        self::assertCount(1, $http->requests);
    }

    public function testTheCacheHoldsTheTokenSealedNeverInTheClear(): void
    {
        $cache = new ArrayAdapter();
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-secret-token')]);

        $this->sessions($http, $cache)->current(self::account());

        self::assertNotSame([], $cache->getValues());
        self::assertStringNotContainsString('jwt-secret-token', serialize($cache->getValues()));
    }

    public function testANewCredentialMissesTheSessionTheOldOneOpened(): void
    {
        $cache = new ArrayAdapter();
        $account = self::account();
        self::cacheOver($cache)->remember(
            $account,
            new BlueskySessionModel('old-jwt', 'alice.bsky.social', 'https://pds.example.test'),
        );
        $account->replaceCredential(
            '@alice.bsky.social',
            SocialAccounts::cipher()->seal(SocialAccounts::OWNER_ID, 'wxyz-wxyz-wxyz-9999'),
            '9999',
            new \DateTimeImmutable('2026-10-12 11:00:00'),
        );
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-new')]);

        self::assertSame('jwt-new', $this->sessions($http, $cache)->current($account)->accessJwt);
    }

    private function sessions(RoutedHttpClient $http, ArrayAdapter $cache): BlueskySessions
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());

        return new BlueskySessions(
            new BlueskySessionOpener(
                new BlueskyApiClient(FetchWiring::guardedApiClient($http->client(), $guard)),
                new StoredCredential(SocialAccounts::cipher()),
            ),
            self::cacheOver($cache),
        );
    }

    private static function cacheOver(ArrayAdapter $cache): BlueskySessionCache
    {
        return new BlueskySessionCache($cache, new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32))));
    }

    private static function account(): SocialAccount
    {
        $account = SocialAccounts::bluesky();
        self::assignId($account, 3);

        return $account;
    }

    private static function sessionAnswer(string $accessJwt): MockResponse
    {
        return new MockResponse(json_encode(
            ['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => $accessJwt],
            \JSON_THROW_ON_ERROR,
        ));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Bluesky/Model/JsonNodeModelTest.php tests/Service/Social/Support/RetryAfterTest.php tests/Service/Social/MastodonApiClientTest.php tests/Service/Social/Model/SealedBlueskySessionModelTest.php tests/Service/Social/BlueskySessionsTest.php`
Expected: FAIL: `JsonNodeModel::text()` and `has()` are undefined, and the classes `RetryAfter`, `SocialNetworkThrottledException` and `BlueskySessions` are not found.

- [ ] **Step 3: Add `text()` and `has()` to `JsonNodeModel`**

In `backend/src/Service/Bluesky/Model/JsonNodeModel.php`, after `string()`, add:

```php
    /** The string as sent: Bluesky facets index its UTF-8 bytes, which trimming would shift. */
    public function text(string $key): ?string
    {
        $value = $this->fields[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function has(string $key): bool
    {
        return null !== ($this->fields[$key] ?? null);
    }
```

- [ ] **Step 4: The throttle exception, Retry-After and Mastodon's 429**

In `backend/src/Service/Social/Exception/SocialNetworkUnreachableException.php`, change `final class SocialNetworkUnreachableException` to `class SocialNetworkUnreachableException`.

Create `backend/src/Service/Social/Exception/SocialNetworkThrottledException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

/** A 429: still an unreachable network to a caller that cannot wait, a schedule to the refresh that can. */
final class SocialNetworkThrottledException extends SocialNetworkUnreachableException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
```

Create `backend/src/Service/Social/Support/RetryAfter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

final class RetryAfter
{
    private const string DELAY = '/^\d{1,9}$/';

    /** Only the delay form: the networks' APIs send seconds, and a value we cannot read names no wait. */
    public static function seconds(?string $header): ?int
    {
        $value = trim($header ?? '');

        return 1 === preg_match(self::DELAY, $value) ? (int) $value : null;
    }

    private function __construct()
    {
    }
}
```

In `backend/src/Service/Social/MastodonApiClient.php::send()`, insert as the first check after `$answer = $this->transport($request);`:

```php
        if (Response::HTTP_TOO_MANY_REQUESTS === $answer->status) {
            throw new SocialNetworkThrottledException(
                'The Mastodon instance asked for fewer requests.',
                RetryAfter::seconds($answer->header('retry-after')),
            );
        }
```

Add the imports `App\Service\Social\Exception\SocialNetworkThrottledException` and `App\Service\Social\Support\RetryAfter`. Add `@throws SocialNetworkThrottledException` to `send()`'s docblock.

- [ ] **Step 5: The sealed session cache**

Create `backend/src/Service/Social/Crypto/BlueskySessionCipher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Crypto;

use App\Entity\SealedSecret;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Crypto\Model\SecretBindingModel;

final readonly class BlueskySessionCipher
{
    private const string PURPOSE = 'bluesky-session';

    public function __construct(private InstanceSecretCipher $cipher)
    {
    }

    public function seal(int $userId, string $accessJwt): SealedSecret
    {
        return $this->cipher->seal(SecretBindingModel::forUser(self::PURPOSE, $userId), $accessJwt);
    }

    public function open(int $userId, SealedSecret $sealed): string
    {
        return $this->cipher->open(SecretBindingModel::forUser(self::PURPOSE, $userId), $sealed);
    }
}
```

Create `backend/src/Service/Social/Model/SealedBlueskySessionModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SealedSecret;

final readonly class SealedBlueskySessionModel
{
    public function __construct(
        public SealedSecret $accessJwt,
        public string $handle,
        public string $pdsUrl,
    ) {
    }

    /** @return array{ciphertext: string, nonce: string, salt: string, version: int, handle: string, pdsUrl: string} */
    public function toCacheEntry(): array
    {
        return [
            'ciphertext' => $this->accessJwt->ciphertext,
            'nonce' => $this->accessJwt->nonce,
            'salt' => $this->accessJwt->salt,
            'version' => $this->accessJwt->version,
            'handle' => $this->handle,
            'pdsUrl' => $this->pdsUrl,
        ];
    }

    public static function fromCacheEntryOrNull(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }
        $texts = array_map(
            static fn (string $key): ?string => \is_string($stored[$key] ?? null) ? $stored[$key] : null,
            ['ciphertext' => 'ciphertext', 'nonce' => 'nonce', 'salt' => 'salt', 'handle' => 'handle', 'pdsUrl' => 'pdsUrl'],
        );
        $version = $stored['version'] ?? null;
        if (\in_array(null, $texts, true) || !\is_int($version)) {
            return null;
        }

        return new self(
            new SealedSecret((string) $texts['ciphertext'], (string) $texts['nonce'], (string) $texts['salt'], $version),
            (string) $texts['handle'],
            (string) $texts['pdsUrl'],
        );
    }
}
```

Create `backend/src/Service/Social/BlueskySessionCache.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\SealedBlueskySessionModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class BlueskySessionCache
{
    /** Well under the access token's own lifetime, so a cached session is rarely found stale. */
    private const int LIFETIME_SECONDS = 5400;

    public function __construct(
        #[Autowire(service: 'bluesky.session.cache')]
        private CacheItemPoolInterface $cache,
        private BlueskySessionCipher $cipher,
    ) {
    }

    public function find(SocialAccount $account): ?BlueskySessionModel
    {
        $item = $this->cache->getItem(self::keyOf($account));
        $sealed = $item->isHit() ? SealedBlueskySessionModel::fromCacheEntryOrNull($item->get()) : null;
        if (null === $sealed) {
            return null;
        }

        try {
            $accessJwt = $this->cipher->open($account->getUser()->requireId(), $sealed->accessJwt);
        } catch (SecretUnreadableException) {
            return null;
        }

        return new BlueskySessionModel($accessJwt, $sealed->handle, $sealed->pdsUrl);
    }

    public function remember(SocialAccount $account, BlueskySessionModel $session): void
    {
        $sealed = new SealedBlueskySessionModel(
            $this->cipher->seal($account->getUser()->requireId(), $session->accessJwt),
            $session->handle,
            $session->pdsUrl,
        );
        $item = $this->cache->getItem(self::keyOf($account));
        $item->set($sealed->toCacheEntry());
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->cache->save($item);
    }

    /** Keyed by the stored credential too: a reconnect seals a new one, and the old password's session must go. */
    private static function keyOf(SocialAccount $account): string
    {
        return sprintf(
            'bluesky_session.%d.%s',
            $account->requireId(),
            hash('xxh128', $account->getSealedCredential()->ciphertext),
        );
    }
}
```

Create `backend/src/Service/Social/BlueskySessions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;

/** Bluesky allows about 300 createSession calls a day, and every private feed polls every 15 minutes. */
final readonly class BlueskySessions
{
    public function __construct(
        private BlueskySessionOpener $opener,
        private BlueskySessionCache $cache,
    ) {
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function current(SocialAccount $account): BlueskySessionModel
    {
        return $this->cache->find($account) ?? $this->fresh($account);
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function fresh(SocialAccount $account): BlueskySessionModel
    {
        $session = $this->opener->openForAccount($account);
        $this->cache->remember($account, $session);

        return $session;
    }
}
```

In `backend/config/packages/cache.yaml`, below the `host_throttle.cache` pool, add:

```yaml
            # Bluesky sessions per connected account (#1599). createSession allows about 300 a day, and every private
            # feed polls every 15 minutes. The access token is stored sealed; BlueskySessionCache sets each lifetime.
            bluesky.session.cache:
                adapter: cache.adapter.filesystem
                default_lifetime: 5400
```

- [ ] **Step 6: Document the cache**

In `docs/security.md`, in "## Social accounts", directly after the bullet that starts "The stored credential is read back only to load the import picker (#1598)", add:

```markdown
- The refresh worker reads the stored credential for every poll of an account's private feeds (#1599). A Bluesky
  session is cached per account for 90 minutes in the `bluesky.session.cache` pool. Its access token is sealed with the
  instance key, bound to the owner; a reconnect changes the cache key. A token the PDS calls expired or invalid is
  replaced once with a fresh session.
```

Then change "The stored credential is read back only to load the import picker (#1598)" to "The stored credential is read back to load the import picker (#1598)".

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Bluesky tests/Service/Social`
Expected: PASS.

- [ ] **Step 8: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Bluesky/Model/JsonNodeModel.php backend/src/Service/Social backend/config/packages/cache.yaml \
  docs/security.md backend/tests/Service/Bluesky/Model/JsonNodeModelTest.php backend/tests/Service/Social
git commit -m "feat(#1599): Bluesky sessions are cached sealed per account; a 429 carries its Retry-After"
```

---
### Task 7: Mapping timelines into the RSS entry shape

**Files:**
- Create: `backend/src/Service/Social/Model/TimelinePageModel.php`
- Create: `backend/src/Service/Social/Support/BlueskyRichText.php`
- Create: `backend/src/Service/Social/Support/BlueskyTimelinePage.php`
- Create: `backend/src/Service/Social/Support/MastodonTimelinePage.php`
- Test: `backend/tests/Service/Social/Support/BlueskyRichTextTest.php`, `backend/tests/Service/Social/Support/BlueskyTimelinePageTest.php`, `backend/tests/Service/Social/Support/MastodonTimelinePageTest.php`

**Interfaces:**
- Consumes:
  - `JsonNodeModel::text()` / `has()` (Task 6) and `KnownSharesModel::ofBlueskyPosts()` / `ofLinkCards()` (Task 5)
  - `EntryTitle::of(?string, ?string): Headline`, `DateParser::parse(?string): ?\DateTimeImmutable`
  - `AtPostUri::webUrl(string): ?string`, `ParagraphedText::asHtml(string, \Closure)`, `HtmlEscape::text(string)`
  - `StorableLinkCard::of(LinkCardContent): ?LinkCardContent`
- Produces:
  - `TimelinePageModel(ParsedFeedModel $parsed, KnownSharesModel $knownShares, ?string $newestId)`
  - `TimelinePageModel::of(list<ParsedEntryModel> $entries, KnownSharesModel $knownShares, ?string $newestId): self`; the title, site and description stay null so `EntryIngestor::updateFeedMetadata()` keeps what creation stored
  - `BlueskyRichText::asHtml(string $text, list<JsonNodeModel> $facets): ?string`
  - `BlueskyTimelinePage::of(list<JsonNodeModel> $items, string $accountDid): TimelinePageModel`
  - `MastodonTimelinePage::of(list<JsonNodeModel> $statuses): TimelinePageModel`

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Social/Support/BlueskyRichTextTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Support\BlueskyRichText;
use PHPUnit\Framework\TestCase;

final class BlueskyRichTextTest extends TestCase
{
    // "Grüße" is 7 bytes: the facets below index bytes, so a character count would land every link two bytes early.
    private const string TEXT = 'Grüße an @bob.bsky.social: https://example.com #tag';

    public function testFacetsLinkTheirUtf8ByteRanges(): void
    {
        $html = BlueskyRichText::asHtml(self::TEXT, [
            self::facet(11, 27, ['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:bob']),
            self::facet(29, 48, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com']),
            self::facet(49, 53, ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'tag']),
        ]);

        self::assertSame(
            '<p>Grüße an <a href="https://bsky.app/profile/did:plc:bob">@bob.bsky.social</a>: '
            . '<a href="https://example.com">https://example.com</a> '
            . '<a href="https://bsky.app/hashtag/tag">#tag</a></p>',
            $html,
        );
    }

    public function testLineBreaksBecomeParagraphsAndBreaksAndEverythingIsEscaped(): void
    {
        self::assertSame(
            '<p>a &lt; b &amp; c<br>two</p><p>three</p>',
            BlueskyRichText::asHtml("a < b & c\ntwo\n\nthree", []),
        );
    }

    public function testAFacetOutsideTheTextOrInsideACharacterIsIgnored(): void
    {
        self::assertSame('<p>ü x</p>', BlueskyRichText::asHtml('ü x', [
            self::facet(1, 3, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com']),
            self::facet(2, 40, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com']),
        ]));
    }

    public function testAnOverlappingOrUnsafeFacetIsIgnored(): void
    {
        self::assertSame('<p><a href="https://example.com">abc</a>def</p>', BlueskyRichText::asHtml('abcdef', [
            self::facet(0, 3, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com']),
            self::facet(2, 5, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://other.example']),
            self::facet(3, 6, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'javascript:alert(1)']),
        ]));
    }

    public function testNoTextIsNoBody(): void
    {
        self::assertNull(BlueskyRichText::asHtml("  \n", []));
    }

    /** @param array<string, string> $feature */
    private static function facet(int $byteStart, int $byteEnd, array $feature): JsonNodeModel
    {
        return JsonNodeModel::of([
            'index' => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
            'features' => [$feature],
        ]);
    }
}
```

Create `backend/tests/Service/Social/Support/BlueskyTimelinePageTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Social\Support\BlueskyTimelinePage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyTimelinePageTest extends TestCase
{
    private const string ALICE = 'did:plc:alice';
    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';

    public function testAPostBecomesAnEntryWithItsWebUrlAuthorAndUtcDate(): void
    {
        $entry = BlueskyTimelinePage::of([self::item()], self::ALICE)->parsed->entries[0];

        self::assertSame(self::POST_URI, $entry->guid);
        self::assertSame('https://bsky.app/profile/did:plc:bob/post/3abc', $entry->url);
        self::assertSame('Bob', $entry->author);
        self::assertSame('https://bsky.app/profile/bob.bsky.social', $entry->authorUrl);
        self::assertSame('<p>Hello world. More text</p>', $entry->contentHtml);
        self::assertEquals(EntryTitle::of(null, '<p>Hello world. More text</p>'), $entry->headline);
        self::assertTrue($entry->headline->derived);
        self::assertSame('2026-10-12 08:00:00', $entry->publishedAt?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $entry->publishedAt?->getTimezone()->getName());
    }

    public function testThePostViewIsKeptForItsEmbedAndThePageNamesNoTitle(): void
    {
        $page = BlueskyTimelinePage::of([self::item()], self::ALICE);

        self::assertSame(self::POST_URI, $page->knownShares->blueskyPostFor(self::POST_URI)?->string('uri'));
        self::assertNull($page->parsed->title);
        self::assertNull($page->newestId);
    }

    public function testARepostShowsTheOriginalWithATrailingAttributionAtTheRepostTime(): void
    {
        $item = self::item(['reason' => [
            '$type' => 'app.bsky.feed.defs#reasonRepost',
            'by' => ['did' => 'did:plc:carol', 'handle' => 'carol.bsky.social', 'displayName' => 'Carol <3'],
            'indexedAt' => '2026-10-12T09:30:00Z',
        ]]);

        $entry = BlueskyTimelinePage::of([$item], self::ALICE)->parsed->entries[0];

        self::assertSame(
            '<p>Hello world. More text</p>'
            . '<p>Reposted by <a href="https://bsky.app/profile/carol.bsky.social">Carol &lt;3</a></p>',
            $entry->contentHtml,
        );
        self::assertEquals(EntryTitle::of(null, '<p>Hello world. More text</p>'), $entry->headline);
        self::assertSame('2026-10-12 09:30:00', $entry->publishedAt?->format('Y-m-d H:i:s'));
    }

    /** @param array<string, mixed> $parent */
    #[DataProvider('replies')]
    public function testAReplyShowsOnlyWhenItAnswersTheAccountOrSomeoneItFollows(array $parent, int $shown): void
    {
        $item = self::item(['reply' => ['root' => $parent, 'parent' => $parent]]);

        self::assertCount($shown, BlueskyTimelinePage::of([$item], self::ALICE)->parsed->entries);
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function replies(): iterable
    {
        yield 'to the account' => [['author' => ['did' => self::ALICE, 'viewer' => []]], 1];
        yield 'to a followed account' => [
            ['author' => ['did' => 'did:plc:dan', 'viewer' => ['following' => 'at://did:plc:alice/app.bsky.graph.follow/1']]],
            1,
        ];
        yield 'to a stranger' => [['author' => ['did' => 'did:plc:eve', 'viewer' => []]], 0];
        yield 'to a deleted post' => [['$type' => 'app.bsky.feed.defs#notFoundPost', 'notFound' => true], 0];
    }

    public function testAnItemWithoutAPostUriIsSkipped(): void
    {
        $item = JsonNodeModel::of(['post' => ['record' => ['text' => 'orphan']]]);

        self::assertSame([], BlueskyTimelinePage::of([$item], self::ALICE)->parsed->entries);
    }

    /** @param array<string, mixed> $overrides */
    private static function item(array $overrides = []): JsonNodeModel
    {
        return JsonNodeModel::of($overrides + [
            'post' => [
                'uri' => self::POST_URI,
                'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social', 'displayName' => 'Bob'],
                'record' => [
                    '$type' => 'app.bsky.feed.post',
                    'text' => 'Hello world. More text',
                    'createdAt' => '2026-10-12T10:00:00+02:00',
                ],
                'indexedAt' => '2026-10-12T08:00:05Z',
            ],
        ]);
    }
}
```

Create `backend/tests/Service/Social/Support/MastodonTimelinePageTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Social\Support\MastodonTimelinePage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonTimelinePageTest extends TestCase
{
    private const string URI = 'https://mastodon.example/users/bob/statuses/109';
    private const string CONTENT = '<p>Hello <a href="https://example.com/story">example.com/story</a></p>';

    public function testAStatusBecomesAnEntryWithItsUriUrlAuthorAndUtcDate(): void
    {
        $page = MastodonTimelinePage::of([self::status()]);
        $entry = $page->parsed->entries[0];

        self::assertSame(self::URI, $entry->guid);
        self::assertSame('https://mastodon.example/@bob/109', $entry->url);
        self::assertSame('Bob', $entry->author);
        self::assertSame('https://mastodon.example/@bob', $entry->authorUrl);
        self::assertSame(self::CONTENT, $entry->contentHtml);
        self::assertEquals(EntryTitle::of(null, self::CONTENT), $entry->headline);
        self::assertSame('2026-10-12 08:00:00', $entry->publishedAt?->format('Y-m-d H:i:s'));
        self::assertSame('109', $page->newestId);
    }

    public function testAContentWarningLeadsTheBody(): void
    {
        $entry = MastodonTimelinePage::of([self::status(['spoiler_text' => 'Spoilers <3'])])->parsed->entries[0];

        self::assertSame('<p><strong>CW: Spoilers &lt;3</strong></p>' . self::CONTENT, $entry->contentHtml);
    }

    public function testABoostShowsTheOriginalWithATrailingAttributionAtTheBoostTime(): void
    {
        $boost = self::status([
            'id' => '200',
            'uri' => 'https://mastodon.example/users/carol/statuses/200/activity',
            'created_at' => '2026-10-12T09:30:00Z',
            'content' => '',
            'account' => ['acct' => 'carol', 'display_name' => 'Carol', 'url' => 'https://mastodon.example/@carol'],
            'reblog' => self::statusFields([]),
        ]);

        $page = MastodonTimelinePage::of([$boost]);
        $entry = $page->parsed->entries[0];

        self::assertSame(self::URI, $entry->guid);
        self::assertSame(
            self::CONTENT . '<p>Boosted by <a href="https://mastodon.example/@carol">Carol</a></p>',
            $entry->contentHtml,
        );
        self::assertEquals(EntryTitle::of(null, self::CONTENT), $entry->headline);
        self::assertSame('2026-10-12 09:30:00', $entry->publishedAt?->format('Y-m-d H:i:s'));
        self::assertSame('200', $page->newestId);
    }

    public function testImagesAndVideosBecomeMediaAndTheFirstImageLeads(): void
    {
        $entry = MastodonTimelinePage::of([self::status(['media_attachments' => [
            [
                'type' => 'gifv',
                'url' => 'https://files.mastodon.example/b.mp4',
                'preview_url' => 'https://files.mastodon.example/b.jpg',
                'meta' => ['original' => ['width' => 640, 'height' => 360]],
            ],
            [
                'type' => 'image',
                'url' => 'https://files.mastodon.example/a.jpg',
                'meta' => ['original' => ['width' => 800, 'height' => 600]],
            ],
            ['type' => 'audio', 'url' => 'https://files.mastodon.example/c.mp3'],
        ]])])->parsed->entries[0];

        self::assertEquals(new DeclaredImageModel('https://files.mastodon.example/a.jpg', 800, 600), $entry->media->image);
        self::assertEquals(
            [
                new ParsedMediumModel(
                    'https://files.mastodon.example/b.mp4',
                    VisualMediaKind::Video,
                    640,
                    360,
                    'https://files.mastodon.example/b.jpg',
                ),
                new ParsedMediumModel('https://files.mastodon.example/a.jpg', VisualMediaKind::Image, 800, 600),
            ],
            $entry->media->mediaBundle?->media,
        );
    }

    public function testAPreviewCardIsKeptAsTheLinksCard(): void
    {
        $page = MastodonTimelinePage::of([self::status(['card' => [
            'url' => 'https://example.com/story',
            'title' => 'The story',
            'description' => 'What happened.',
            'image' => 'https://files.mastodon.example/card.jpg',
            'provider_name' => 'Example News',
        ]])]);

        self::assertEquals(
            new LinkCardContent(
                'https://example.com/story',
                'The story',
                'What happened.',
                'https://files.mastodon.example/card.jpg',
                'Example News',
            ),
            $page->knownShares->linkCardFor(self::URI),
        );
    }

    /** @param array<string, mixed> $card */
    #[DataProvider('unusableCards')]
    public function testACardLinkCardsCannotStoreFallsBackToThePageFetch(array $card): void
    {
        $page = MastodonTimelinePage::of([self::status(['card' => $card])]);

        self::assertNull($page->knownShares->linkCardFor(self::URI));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusableCards(): iterable
    {
        yield 'blank title' => [['url' => 'https://example.com/story', 'title' => ' ']];
        yield 'not a web page' => [['url' => 'ftp://example.com/story', 'title' => 'The story']];
        yield 'no url' => [['title' => 'The story']];
    }

    public function testAnEmptyPageNamesNoNewestId(): void
    {
        self::assertNull(MastodonTimelinePage::of([])->newestId);
    }

    /** @param array<string, mixed> $overrides */
    private static function status(array $overrides = []): JsonNodeModel
    {
        return JsonNodeModel::of(self::statusFields($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function statusFields(array $overrides): array
    {
        return $overrides + [
            'id' => '109',
            'uri' => self::URI,
            'url' => 'https://mastodon.example/@bob/109',
            'created_at' => '2026-10-12T10:00:00.000+02:00',
            'content' => self::CONTENT,
            'spoiler_text' => '',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
            'media_attachments' => [],
            'reblog' => null,
            'card' => null,
        ];
    }

}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/Support/BlueskyRichTextTest.php tests/Service/Social/Support/BlueskyTimelinePageTest.php tests/Service/Social/Support/MastodonTimelinePageTest.php`
Expected: FAIL. `App\Service\Social\Support\BlueskyRichText` is not found.

- [ ] **Step 3: Write the page model**

Create `backend/src/Service/Social/Model/TimelinePageModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;

final readonly class TimelinePageModel
{
    public function __construct(
        public ParsedFeedModel $parsed,
        public KnownSharesModel $knownShares,
        public ?string $newestId,
    ) {
    }

    /**
     * No title or site: EntryIngestor keeps what the private feed was created with only while the page names none.
     *
     * @param list<ParsedEntryModel> $entries
     */
    public static function of(array $entries, KnownSharesModel $knownShares, ?string $newestId): self
    {
        return new self(new ParsedFeedModel(null, null, null, null, $entries), $knownShares, $newestId);
    }
}
```

- [ ] **Step 4: Write `BlueskyRichText`**

Create `backend/src/Service/Social/Support/BlueskyRichText.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Text\Support\HtmlEscape;
use App\Service\Text\Support\ParagraphedText;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** A Bluesky post's text as HTML: its facets linked by their UTF-8 byte ranges, everything else escaped. */
final class BlueskyRichText
{
    private const string LINK = 'app.bsky.richtext.facet#link';
    private const string MENTION = 'app.bsky.richtext.facet#mention';
    private const string TAG = 'app.bsky.richtext.facet#tag';
    private const string PROFILE = 'https://bsky.app/profile/';
    private const string HASHTAG = 'https://bsky.app/hashtag/';

    /** @param list<JsonNodeModel> $facets */
    public static function asHtml(string $text, array $facets): ?string
    {
        if ('' === trim($text)) {
            return null;
        }

        $html = '';
        $offset = 0;
        foreach (self::usableSpans($text, $facets) as [$start, $end, $href]) {
            $html .= HtmlEscape::text(substr($text, $offset, $start - $offset))
                . sprintf('<a href="%s">%s</a>', HtmlEscape::text($href), self::linkText(substr($text, $start, $end - $start)));
            $offset = $end;
        }
        $html .= HtmlEscape::text(substr($text, $offset));

        return ParagraphedText::asHtml($html, static fn (string $line): string => $line);
    }

    /**
     * @param list<JsonNodeModel> $facets
     *
     * @return list<array{int, int, string}> in text order, an overlapping one dropped
     */
    private static function usableSpans(string $text, array $facets): array
    {
        $spans = [];
        foreach ($facets as $facet) {
            $span = self::spanOf($text, $facet);
            if (null !== $span) {
                $spans[] = $span;
            }
        }
        usort($spans, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $kept = [];
        $reached = 0;
        foreach ($spans as $span) {
            if ($span[0] >= $reached) {
                $kept[] = $span;
                $reached = $span[1];
            }
        }

        return $kept;
    }

    /** @return array{int, int, string}|null */
    private static function spanOf(string $text, JsonNodeModel $facet): ?array
    {
        $index = $facet->node('index');
        $start = $index->int('byteStart');
        $end = $index->int('byteEnd');
        $href = self::hrefOf($facet->first('features'));
        if (null === $start || null === $end || null === $href || $start < 0 || $end <= $start) {
            return null;
        }
        // A range past the text, or one splitting a character, is not a range of this text.
        if ($end > \strlen($text) || !mb_check_encoding(substr($text, $start, $end - $start), 'UTF-8')) {
            return null;
        }

        return [$start, $end, $href];
    }

    private static function hrefOf(JsonNodeModel $feature): ?string
    {
        return match ($feature->type()) {
            self::LINK => AbsoluteHttpUrl::orNull($feature->string('uri')),
            self::MENTION => self::profileOf($feature->string('did')),
            self::TAG => self::hashtagOf($feature->string('tag')),
            default => null,
        };
    }

    private static function profileOf(?string $did): ?string
    {
        return null === $did ? null : self::PROFILE . $did;
    }

    private static function hashtagOf(?string $tag): ?string
    {
        return null === $tag ? null : self::HASHTAG . rawurlencode($tag);
    }

    /** A link must stay on one line: ParagraphedText splits at every line break. */
    private static function linkText(string $slice): string
    {
        return HtmlEscape::text(str_replace(["\r\n", "\r", "\n"], ' ', $slice));
    }

    private function __construct()
    {
    }
}
```

Note for the executor: `ParagraphedText` breaks only at newlines, and an escaped segment contains no newline that HTML would need. The escaping happens before the paragraphing on purpose: the facets index the raw text.

- [ ] **Step 5: Write `BlueskyTimelinePage`**

Create `backend/src/Service/Social/Support/BlueskyTimelinePage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Text\Support\HtmlEscape;

/** A getTimeline / getListFeed / getFeed answer as entries; each post view rides along for its embed. */
final class BlueskyTimelinePage
{
    private const string REPOST = 'app.bsky.feed.defs#reasonRepost';
    private const string PROFILE = 'https://bsky.app/profile/';

    /** @param list<JsonNodeModel> $items the answer's `feed` */
    public static function of(array $items, string $accountDid): TimelinePageModel
    {
        $entries = [];
        $posts = [];
        foreach ($items as $item) {
            $entry = self::isShown($item, $accountDid) ? self::entryOf($item) : null;
            if (null === $entry) {
                continue;
            }
            $entries[] = $entry;
            $posts[$entry->guid] = $item->node('post');
        }

        return TimelinePageModel::of($entries, KnownSharesModel::ofBlueskyPosts($posts), null);
    }

    /** As the official app: a reply shows when it answers the account itself or someone the account follows. */
    private static function isShown(JsonNodeModel $item, string $accountDid): bool
    {
        if (!$item->has('reply')) {
            return true;
        }
        $parentAuthor = $item->node('reply')->node('parent')->node('author');

        return $accountDid === $parentAuthor->string('did')
            || null !== $parentAuthor->node('viewer')->string('following');
    }

    private static function entryOf(JsonNodeModel $item): ?ParsedEntryModel
    {
        $post = $item->node('post');
        $uri = $post->string('uri');
        $url = AtPostUri::webUrl($uri ?? '');
        if (null === $uri || null === $url) {
            return null;
        }
        $record = $post->node('record');
        $author = $post->node('author');
        $text = BlueskyRichText::asHtml($record->text('text') ?? '', $record->nodes('facets'));

        return new ParsedEntryModel(
            guid: $uri,
            url: $url,
            headline: EntryTitle::of(null, $text),
            author: $author->string('displayName') ?? $author->string('handle'),
            summary: null,
            contentHtml: self::withRepostAttribution($text, $item->node('reason')),
            publishedAt: DateParser::parse(self::postedAt($item)),
            authorUrl: self::profileOf($author),
        );
    }

    /** A repost lands in the timeline when it was reposted, not when the original was written. */
    private static function postedAt(JsonNodeModel $item): ?string
    {
        $reason = $item->node('reason');
        if (self::REPOST === $reason->type()) {
            return $reason->string('indexedAt');
        }
        $post = $item->node('post');

        return $post->node('record')->string('createdAt') ?? $post->string('indexedAt');
    }

    private static function withRepostAttribution(?string $html, JsonNodeModel $reason): ?string
    {
        if (self::REPOST !== $reason->type()) {
            return $html;
        }
        $by = $reason->node('by');

        return ($html ?? '') . sprintf(
            '<p>Reposted by <a href="%s">%s</a></p>',
            HtmlEscape::text(self::profileOf($by)),
            HtmlEscape::text($by->string('displayName') ?? $by->string('handle') ?? ''),
        );
    }

    private static function profileOf(JsonNodeModel $author): string
    {
        return self::PROFILE . ($author->string('handle') ?? $author->string('did') ?? '');
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 6: Write `MastodonTimelinePage`**

Create `backend/src/Service/Social/Support/MastodonTimelinePage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Fetch\Support\HostKey;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedMediaBundleModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\EntryTitle;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Text\Support\HtmlEscape;
use App\Service\Url\Support\HttpsImageUrl;

/** A home or list timeline answer as entries; replies are already filtered by the instance. */
final class MastodonTimelinePage
{
    private const array VIDEO_TYPES = ['video', 'gifv'];

    /** @param list<JsonNodeModel> $statuses newest first, as a timeline answers */
    public static function of(array $statuses): TimelinePageModel
    {
        $entries = [];
        $cards = [];
        foreach ($statuses as $status) {
            $entry = self::entryOf($status);
            if (null === $entry) {
                continue;
            }
            $entries[] = $entry;
            $card = self::cardOf(self::originalOf($status)->node('card'));
            if (null !== $card) {
                $cards[$entry->guid] = $card;
            }
        }

        return TimelinePageModel::of($entries, KnownSharesModel::ofLinkCards($cards), ($statuses[0] ?? null)?->string('id'));
    }

    private static function entryOf(JsonNodeModel $status): ?ParsedEntryModel
    {
        $post = self::originalOf($status);
        $uri = $post->string('uri');
        if (null === $uri) {
            return null;
        }
        $body = self::bodyOf($post);
        $account = $post->node('account');

        return new ParsedEntryModel(
            guid: $uri,
            url: $post->string('url') ?? $uri,
            headline: EntryTitle::of(null, $body),
            author: $account->string('display_name') ?? $account->string('acct'),
            summary: null,
            contentHtml: self::withBoostAttribution($body, $status),
            publishedAt: DateParser::parse($status->string('created_at')),
            media: self::mediaOf($post->nodes('media_attachments')),
            authorUrl: $account->string('url'),
        );
    }

    private static function originalOf(JsonNodeModel $status): JsonNodeModel
    {
        $reblog = $status->node('reblog');

        return null === $reblog->string('uri') ? $status : $reblog;
    }

    private static function bodyOf(JsonNodeModel $post): ?string
    {
        $warning = $post->string('spoiler_text');
        $html = (null === $warning ? '' : sprintf('<p><strong>CW: %s</strong></p>', HtmlEscape::text($warning)))
            . ($post->string('content') ?? '');

        return '' === $html ? null : $html;
    }

    private static function withBoostAttribution(?string $body, JsonNodeModel $status): ?string
    {
        if (null === $status->node('reblog')->string('uri')) {
            return $body;
        }
        $booster = $status->node('account');

        return ($body ?? '') . sprintf(
            '<p>Boosted by <a href="%s">%s</a></p>',
            HtmlEscape::text($booster->string('url') ?? ''),
            HtmlEscape::text($booster->string('display_name') ?? $booster->string('acct') ?? ''),
        );
    }

    /** @param list<JsonNodeModel> $attachments */
    private static function mediaOf(array $attachments): ParsedEntryMediaModel
    {
        $media = [];
        foreach ($attachments as $attachment) {
            $medium = self::mediumOf($attachment);
            if (null !== $medium) {
                $media[] = $medium;
            }
        }
        if ([] === $media) {
            return new ParsedEntryMediaModel();
        }

        return new ParsedEntryMediaModel(self::leadImageOf($media), new ParsedMediaBundleModel($media));
    }

    private static function mediumOf(JsonNodeModel $attachment): ?ParsedMediumModel
    {
        $url = HttpsImageUrl::orNull($attachment->string('url'));
        $size = $attachment->node('meta')->node('original');
        $type = $attachment->string('type');

        return match (true) {
            null === $url => null,
            'image' === $type => new ParsedMediumModel($url, VisualMediaKind::Image, $size->int('width'), $size->int('height')),
            \in_array($type, self::VIDEO_TYPES, true) => new ParsedMediumModel(
                $url,
                VisualMediaKind::Video,
                $size->int('width'),
                $size->int('height'),
                HttpsImageUrl::orNull($attachment->string('preview_url')),
            ),
            default => null,
        };
    }

    /** @param list<ParsedMediumModel> $media */
    private static function leadImageOf(array $media): ?DeclaredImageModel
    {
        foreach ($media as $medium) {
            if (VisualMediaKind::Image === $medium->kind) {
                return new DeclaredImageModel($medium->url, $medium->width, $medium->height);
            }
        }

        return null;
    }

    private static function cardOf(JsonNodeModel $card): ?LinkCardContent
    {
        $url = $card->string('url');
        $title = $card->string('title');
        if (null === $url || null === $title) {
            return null;
        }

        return StorableLinkCard::of(new LinkCardContent(
            $url,
            $title,
            $card->string('description'),
            $card->string('image'),
            $card->string('provider_name') ?? HostKey::forUrl($url),
        ));
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social/Support`
Expected: PASS.

- [ ] **Step 8: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean. `composer stan` also checks the new `Social → Completion`, `Social → LinkCard` and `Social → Parser` edges for cycles; the plan expects none (pre-flight note 8).

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Social/Model/TimelinePageModel.php backend/src/Service/Social/Support/BlueskyRichText.php \
  backend/src/Service/Social/Support/BlueskyTimelinePage.php backend/src/Service/Social/Support/MastodonTimelinePage.php \
  backend/tests/Service/Social/Support/BlueskyRichTextTest.php backend/tests/Service/Social/Support/BlueskyTimelinePageTest.php \
  backend/tests/Service/Social/Support/MastodonTimelinePageTest.php
git commit -m "feat(#1599): Bluesky and Mastodon timeline pages map into the RSS entry shape"
```

---

### Task 8: Reading one timeline page per network

**Files:**
- Create: `backend/src/Service/Social/TimelineSource/TimelineSourceInterface.php`
- Create: `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`
- Create: `backend/src/Service/Social/TimelineSource/MastodonTimelineSource.php`
- Create: `backend/src/Service/Social/TimelineReader/TimelineReaderInterface.php`
- Create: `backend/src/Service/Social/TimelineReader/NetworkTimelineReader.php`
- Test: `backend/tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php`, `backend/tests/Service/Social/TimelineSource/MastodonTimelineSourceTest.php`, `backend/tests/Service/Social/TimelineReader/NetworkTimelineReaderTest.php`

**Interfaces:**
- Consumes:
  - `BlueskySessions::current/fresh()`, `BlueskyApiClient::answer()`
  - `MastodonApiClient::send()`, `StoredCredential::open()`, `MastodonSessionModel`
  - `RetryAfter::seconds()`, `BlueskyTimelinePage::of()`, `MastodonTimelinePage::of()`
- Produces:
  - `#[AutoconfigureTag('app.social_timeline_source')] interface TimelineSourceInterface { public static function network(): string; public function page(SocialFeed $socialFeed): TimelinePageModel; }`
  - `interface TimelineReaderInterface { public function page(SocialFeed $socialFeed): TimelinePageModel; }`, implemented by `NetworkTimelineReader`
  - Both `page()` methods throw `SocialCredentialsRejectedException`, `SocialNetworkThrottledException` and `SocialNetworkUnreachableException`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineSource;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\StoredCredential;
use App\Service\Social\TimelineSource\BlueskyTimelineSource;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BlueskyTimelineSourceTest extends TestCase
{
    use AssignsEntityIds;

    private const string PDS = 'https://pds.example.test';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string TIMELINE = 'GET https://pds.example.test/xrpc/app.bsky.feed.getTimeline?limit=50';
    private const string LIST = 'at://did:plc:bob/app.bsky.graph.list/3l1';
    private const string FEED = 'at://did:plc:bob/app.bsky.feed.generator/news';

    private ArrayAdapter $cache;
    private SocialAccount $account;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->account = SocialAccounts::bluesky();
        self::assignId($this->account, 3);
    }

    public function testTheHomeTimelineIsReadWithTheSessionsToken(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1'), self::TIMELINE => self::timeline()]);

        $page = $this->source($http->client())->page($this->socialFeed(SocialFeedKind::Home, null));

        self::assertCount(1, $page->parsed->entries);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[1]['headers']);
    }

    #[DataProvider('collections')]
    public function testAListOrCustomFeedIsReadByItsUri(SocialFeedKind $kind, string $uri, string $route): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1'), $route => self::timeline()]);

        $page = $this->source($http->client())->page($this->socialFeed($kind, $uri));

        self::assertCount(1, $page->parsed->entries);
    }

    /** @return iterable<string, array{SocialFeedKind, string, string}> */
    public static function collections(): iterable
    {
        yield 'list' => [
            SocialFeedKind::UserList,
            self::LIST,
            'GET https://pds.example.test/xrpc/app.bsky.feed.getListFeed?list='
                . 'at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.graph.list%2F3l1&limit=50',
        ];
        yield 'custom feed' => [
            SocialFeedKind::CustomFeed,
            self::FEED,
            'GET https://pds.example.test/xrpc/app.bsky.feed.getFeed?feed='
                . 'at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.feed.generator%2Fnews&limit=50',
        ];
    }

    public function testASecondPollOpensNoSecondSession(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1'), self::TIMELINE => self::timeline()]);
        $source = $this->source($http->client());

        $source->page($this->socialFeed(SocialFeedKind::Home, null));
        $source->page($this->socialFeed(SocialFeedKind::Home, null));

        self::assertSame(1, \count(array_filter($http->requests, static fn (array $request): bool => 'POST' === $request['method'])));
    }

    public function testAnExpiredCachedSessionIsReplacedOnceAndThePollSucceeds(): void
    {
        $this->sessionCache()->remember($this->account, new BlueskySessionModel('stale-jwt', 'alice.bsky.social', self::PDS));
        $sessionAnswer = self::session('jwt-2');
        $timelineAnswer = self::timeline();
        $http = new MockHttpClient([
            self::json(['error' => 'ExpiredToken', 'message' => 'Token has expired'], Response::HTTP_BAD_REQUEST),
            $sessionAnswer,
            $timelineAnswer,
        ]);

        $page = $this->source($http)->page($this->socialFeed(SocialFeedKind::Home, null));

        self::assertCount(1, $page->parsed->entries);
        self::assertSame('POST', $sessionAnswer->getRequestMethod());
        $headers = $timelineAnswer->getRequestOptions()['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertContains('Authorization: Bearer jwt-2', $headers);
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testATokenRefusedAgainAfterAFreshSessionIsARefusedCredential(): void
    {
        $this->sessionCache()->remember($this->account, new BlueskySessionModel('stale-jwt', 'alice.bsky.social', self::PDS));
        $http = new MockHttpClient([
            self::json(['error' => 'InvalidToken'], Response::HTTP_UNAUTHORIZED),
            self::session('jwt-2'),
            self::json(['error' => 'InvalidToken'], Response::HTTP_UNAUTHORIZED),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->source($http)->page($this->socialFeed(SocialFeedKind::Home, null));
    }

    public function testTooManyRequestsIsAThrottleCarryingItsDelay(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => self::session('jwt-1'),
            self::TIMELINE => self::json(['error' => 'RateLimitExceeded'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '120']),
        ]);

        try {
            $this->source($http->client())->page($this->socialFeed(SocialFeedKind::Home, null));
            self::fail('A 429 must not read as a page.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
    }

    #[DataProvider('unusableAnswers')]
    public function testAnUnusableAnswerIsAnUnreachableNetwork(MockResponse $answer): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1'), self::TIMELINE => $answer]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http->client())->page($this->socialFeed(SocialFeedKind::Home, null));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function unusableAnswers(): iterable
    {
        yield 'server error' => [self::json([], Response::HTTP_BAD_GATEWAY)];
        yield 'no feed' => [self::json(['cursor' => 'x'])];
    }

    private function source(HttpClientInterface $http): BlueskyTimelineSource
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());
        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http, $guard));

        return new BlueskyTimelineSource(
            $api,
            new BlueskySessions(
                new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
                $this->sessionCache(),
            ),
        );
    }

    private function sessionCache(): BlueskySessionCache
    {
        return new BlueskySessionCache($this->cache, new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32))));
    }

    private function socialFeed(SocialFeedKind $kind, ?string $remoteId): SocialFeed
    {
        return new SocialFeed($this->account, new Feed('social:bluesky:3:home'), $kind, $remoteId);
    }

    private static function session(string $accessJwt): MockResponse
    {
        return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => $accessJwt]);
    }

    private static function timeline(): MockResponse
    {
        return self::json(['feed' => [[
            'post' => [
                'uri' => 'at://did:plc:bob/app.bsky.feed.post/3abc',
                'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social'],
                'record' => ['text' => 'Hello', 'createdAt' => '2026-10-12T10:00:00Z'],
            ],
        ]]]);
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    private static function json(array $body, int $status = Response::HTTP_OK, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => $headers,
        ]);
    }
}
```

Create `backend/tests/Service/Social/TimelineSource/MastodonTimelineSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineSource;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\StoredCredential;
use App\Service\Social\TimelineSource\MastodonTimelineSource;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonTimelineSourceTest extends TestCase
{
    private const string HOME = 'GET https://mastodon.example/api/v1/timelines/home?limit=40';

    public function testTheHomeTimelineIsReadWithTheTokenAndNamesItsNewestStatus(): void
    {
        $http = new RoutedHttpClient([self::HOME => self::statuses('109')]);

        $page = $this->source($http)->page(self::socialFeed(SocialFeedKind::Home, null));

        self::assertCount(1, $page->parsed->entries);
        self::assertSame('109', $page->newestId);
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
    }

    public function testAListAsksOnlyForWhatCameAfterTheNewestSeenStatus(): void
    {
        $http = new RoutedHttpClient([
            'GET https://mastodon.example/api/v1/timelines/list/42?limit=40&since_id=100' => self::statuses('120'),
        ]);
        $list = self::socialFeed(SocialFeedKind::UserList, '42');
        $list->recordNewestSeen('100');

        self::assertSame('120', $this->source($http)->page($list)->newestId);
    }

    public function testARefusedTokenIsARefusedCredential(): void
    {
        $http = new RoutedHttpClient([self::HOME => new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED])]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->source($http)->page(self::socialFeed(SocialFeedKind::Home, null));
    }

    public function testAnAnswerThatIsNoListIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([self::HOME => new MockResponse('{"error":"odd"}')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->page(self::socialFeed(SocialFeedKind::Home, null));
    }

    private function source(RoutedHttpClient $http): MastodonTimelineSource
    {
        $guard = new UrlGuard(new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]), new IpValidator());

        return new MastodonTimelineSource(
            new MastodonApiClient(FetchWiring::guardedApiClient($http->client(), $guard)),
            new StoredCredential(SocialAccounts::cipher()),
        );
    }

    private static function socialFeed(SocialFeedKind $kind, ?string $remoteId): SocialFeed
    {
        return new SocialFeed(SocialAccounts::mastodon(), new Feed('social:mastodon:4:home'), $kind, $remoteId);
    }

    private static function statuses(string $newestId): MockResponse
    {
        return new MockResponse(json_encode([[
            'id' => $newestId,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $newestId,
            'url' => 'https://mastodon.example/@bob/' . $newestId,
            'created_at' => '2026-10-12T10:00:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ]], \JSON_THROW_ON_ERROR));
    }
}
```

Create `backend/tests/Service/Social/TimelineReader/NetworkTimelineReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineReader;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineReader\NetworkTimelineReader;
use App\Service\Social\TimelineSource\TimelineSourceInterface;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class NetworkTimelineReaderTest extends TestCase
{
    public function testTheAccountsNetworkPicksTheSource(): void
    {
        $page = TimelinePageModel::of([], KnownSharesModel::none(), '7');
        $mastodon = new class ($page) implements TimelineSourceInterface {
            public function __construct(private readonly TimelinePageModel $page)
            {
            }

            public static function network(): string
            {
                return SocialNetwork::Mastodon->value;
            }

            public function page(SocialFeed $socialFeed): TimelinePageModel
            {
                return $this->page;
            }
        };
        $reader = new NetworkTimelineReader(new ServiceLocator([
            SocialNetwork::Mastodon->value => static fn (): TimelineSourceInterface => $mastodon,
        ]));

        $socialFeed = new SocialFeed(SocialAccounts::mastodon(), new Feed('social:mastodon:4:home'), SocialFeedKind::Home, null);

        self::assertSame($page, $reader->page($socialFeed));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/TimelineSource tests/Service/Social/TimelineReader`
Expected: FAIL. `App\Service\Social\TimelineSource\BlueskyTimelineSource` is not found.

- [ ] **Step 3: The source interface and the reader**

Create `backend/src/Service/Social/TimelineSource/TimelineSourceInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineSource;

use App\Entity\SocialFeed;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's private feeds, one call per poll, keyed by its SocialNetwork value in NetworkTimelineReader. */
#[AutoconfigureTag('app.social_timeline_source')]
interface TimelineSourceInterface
{
    public static function network(): string;

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function page(SocialFeed $socialFeed): TimelinePageModel;
}
```

Create `backend/src/Service/Social/TimelineReader/TimelineReaderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineReader;

use App\Entity\SocialFeed;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;

interface TimelineReaderInterface
{
    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function page(SocialFeed $socialFeed): TimelinePageModel;
}
```

Create `backend/src/Service/Social/TimelineReader/NetworkTimelineReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineReader;

use App\Entity\SocialFeed;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineSource\TimelineSourceInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NetworkTimelineReader implements TimelineReaderInterface
{
    public function __construct(
        #[AutowireLocator('app.social_timeline_source', defaultIndexMethod: 'network')]
        private ContainerInterface $sources,
    ) {
    }

    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        $source = $this->sources->get($socialFeed->getSocialAccount()->getNetwork()->value);
        \assert($source instanceof TimelineSourceInterface);

        return $source->page($socialFeed);
    }
}
```

- [ ] **Step 4: The Bluesky source**

Create `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineSource;

use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\BlueskyTimelinePage;
use App\Service\Social\Support\RetryAfter;
use Symfony\Component\HttpFoundation\Response;

/** The newest page only: posts already stored are recognised by their guid, which is the newest-seen cutoff. */
final readonly class BlueskyTimelineSource implements TimelineSourceInterface
{
    private const int PAGE_SIZE = 50;
    private const array STALE_SESSION_ERRORS = ['ExpiredToken', 'InvalidToken'];
    private const array STALE_SESSION_STATUSES = [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED];

    public function __construct(
        private BlueskyApiClient $bluesky,
        private BlueskySessions $sessions,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        $account = $socialFeed->getSocialAccount();
        $answer = $this->ask($socialFeed, $this->sessions->current($account));
        if (self::isStaleSession($answer)) {
            $answer = $this->ask($socialFeed, $this->sessions->fresh($account));
        }

        return BlueskyTimelinePage::of(self::itemsOf($answer), $account->getIdentity());
    }

    private function ask(SocialFeed $socialFeed, BlueskySessionModel $session): ApiResponseModel
    {
        return $this->bluesky->answer(ApiRequestModel::get(
            $session->pdsUrl . self::pathOf($socialFeed),
            ['Authorization' => 'Bearer ' . $session->accessJwt],
        ));
    }

    private static function pathOf(SocialFeed $socialFeed): string
    {
        $remoteId = rawurlencode($socialFeed->getRemoteId() ?? '');

        return match ($socialFeed->getKind()) {
            SocialFeedKind::Home => '/xrpc/app.bsky.feed.getTimeline?limit=' . self::PAGE_SIZE,
            SocialFeedKind::UserList => '/xrpc/app.bsky.feed.getListFeed?list=' . $remoteId . '&limit=' . self::PAGE_SIZE,
            SocialFeedKind::CustomFeed => '/xrpc/app.bsky.feed.getFeed?feed=' . $remoteId . '&limit=' . self::PAGE_SIZE,
        };
    }

    private static function isStaleSession(ApiResponseModel $answer): bool
    {
        return \in_array($answer->status, self::STALE_SESSION_STATUSES, true)
            && \in_array(JsonNodeModel::of($answer->json())->string('error'), self::STALE_SESSION_ERRORS, true);
    }

    /**
     * @return list<JsonNodeModel>
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    private static function itemsOf(ApiResponseModel $answer): array
    {
        if (Response::HTTP_TOO_MANY_REQUESTS === $answer->status) {
            throw new SocialNetworkThrottledException(
                'Bluesky asked for fewer requests.',
                RetryAfter::seconds($answer->header('retry-after')),
            );
        }
        if (Response::HTTP_UNAUTHORIZED === $answer->status || self::isStaleSession($answer)) {
            throw new SocialCredentialsRejectedException('Bluesky refused a fresh session for this account.');
        }
        if (!$answer->isSuccessful()) {
            throw new SocialNetworkUnreachableException(sprintf('Bluesky answered %d.', $answer->status));
        }

        return JsonNodeModel::of($answer->json())->listAt('feed')
            ?? throw new SocialNetworkUnreachableException('Bluesky answered without a feed.');
    }
}
```

- [ ] **Step 5: The Mastodon source**

Create `backend/src/Service/Social/TimelineSource/MastodonTimelineSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineSource;

use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\MastodonSessionModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\StoredCredential;
use App\Service\Social\Support\MastodonTimelinePage;

final readonly class MastodonTimelineSource implements TimelineSourceInterface
{
    private const int PAGE_SIZE = 40;

    public function __construct(
        private MastodonApiClient $mastodon,
        private StoredCredential $storedCredential,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        $account = $socialFeed->getSocialAccount();
        $session = new MastodonSessionModel($account->getServiceUrl(), $this->storedCredential->open($account));
        $answer = $this->mastodon->send($session->get($session->url(self::pathOf($socialFeed))));
        $statuses = JsonNodeModel::listOf(json_decode($answer->body, true))
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without a timeline.');

        return MastodonTimelinePage::of($statuses);
    }

    private static function pathOf(SocialFeed $socialFeed): string
    {
        $path = SocialFeedKind::UserList === $socialFeed->getKind()
            ? '/api/v1/timelines/list/' . rawurlencode($socialFeed->getRemoteId() ?? '')
            : '/api/v1/timelines/home';
        $newestSeen = $socialFeed->getNewestSeenId();

        return $path . '?limit=' . self::PAGE_SIZE
            . (null === $newestSeen ? '' : '&since_id=' . rawurlencode($newestSeen));
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social`
Expected: PASS.

- [ ] **Step 7: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Social/TimelineSource backend/src/Service/Social/TimelineReader \
  backend/tests/Service/Social/TimelineSource backend/tests/Service/Social/TimelineReader
git commit -m "feat(#1599): one timeline page per poll, read per network through the account's credential"
```

---

### Task 9: The refresh polls private feeds through their account

**Files:**
- Modify: `backend/src/Service/Refresh/Pass/BudgetedFeedQueue.php`
- Modify: `backend/src/Service/Refresh/Pass/RefreshPass.php`
- Create: `backend/src/Service/Refresh/SocialFeedRefresher.php`
- Create: `backend/src/Service/Refresh/RefreshPassFetcher.php`
- Modify: `backend/src/Service/Refresh/RefreshRunner/RefreshRunner.php`
- Create: `backend/tests/Support/StubTimelineReader.php`
- Modify: `backend/tests/Support/RefreshRunners.php`
- Test: `backend/tests/Service/Refresh/Pass/BudgetedFeedQueueTest.php`, `backend/tests/Service/Refresh/SocialFeedRefresherTest.php`, `backend/tests/Service/Refresh/RefreshRunner/RefreshRunnerSocialTest.php`

**Interfaces:**
- Consumes:
  - `TimelineReaderInterface::page()` (Task 8); `SocialFeedRepository::findOneByFeed()` and `SocialFeed::recordNewestSeen()` (Task 1)
  - `FeedOutcomePersister::storeParsed/storeFailure/storeThrottled` and `IncomingFeedModel` (Task 5)
  - `SocialNetworkThrottledException::$retryAfterSeconds` (Task 6)
  - `SocialAccountNeedsReconnectException` (exists)
- Produces:
  - `BudgetedFeedQueue::tickets()` yields only non-social feeds; `BudgetedFeedQueue::socialFeeds(): \Generator<int, Feed>` yields social feeds under the same deadline; both record started ids.
  - `RefreshPass::socialFeeds(): \Generator<int, Feed>`
  - `SocialFeedRefresher::refresh(Feed $feed, \DateTimeImmutable $now): FeedRefreshResultModel`
  - `RefreshPassFetcher::fetchAndPersist(RefreshPass $pass, \DateTimeImmutable $now): void`
  - `new RefreshRunner(FeedRepository, RefreshPassFetcher, MissingFaviconResolver, RefreshHousekeeping, LockFactory, ClockInterface, LoggerInterface, ContentChangeMarkerInterface)`
  - Test support: `new StubTimelineReader(array<string feedUrl, TimelinePageModel|\Throwable> $outcomes = [])` with public `list<SocialFeed> $asked`; `RefreshRunners::readingTimelinesFrom(TimelineReaderInterface): self`

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Service/Refresh/Pass/BudgetedFeedQueueTest.php`:

```php
    public function testASocialFeedGetsNoTicketButItsOwnTurnAfterTheTickets(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $rss = self::withId(new Feed('https://feed1.example.com/rss'), 1);
        $timeline = self::withId(self::socialFeed('social:bluesky:1:home'), 2);
        $queue = new BudgetedFeedQueue([$timeline, $rss], $clock, $clock->now()->getTimestamp() + 300);

        $tickets = iterator_to_array($queue->tickets());
        $social = iterator_to_array($queue->socialFeeds());

        self::assertSame([1], array_keys($tickets));
        self::assertSame([2 => $timeline], $social);
        self::assertSame([1, 2], $queue->startedFeedIds());
    }

    public function testASocialFeedWaitsForTheBudgetLikeAnyOther(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $rss = self::withId(new Feed('https://feed1.example.com/rss'), 1);
        $timeline = self::withId(self::socialFeed('social:bluesky:1:home'), 2);
        $queue = new BudgetedFeedQueue([$rss, $timeline], $clock, $clock->now()->getTimestamp() + 5);

        iterator_to_array($queue->tickets());

        self::assertSame([], iterator_to_array($queue->socialFeeds()));
        self::assertSame(1, $queue->skippedCount());
    }

    private static function socialFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat(SourceFormat::BLUESKY_API);

        return $feed;
    }
```

Add `use App\Enum\SourceFormat;`.

Create `backend/tests/Support/StubTimelineReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialFeed;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineReader\TimelineReaderInterface;
use PHPUnit\Framework\Assert;

final class StubTimelineReader implements TimelineReaderInterface
{
    /** @var list<SocialFeed> */
    public array $asked = [];

    /** @param array<string, TimelinePageModel|\Throwable> $outcomes by the private feed's URL */
    public function __construct(private readonly array $outcomes = [])
    {
    }

    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        $this->asked[] = $socialFeed;
        $url = $socialFeed->getFeed()->getUrl();
        $outcome = $this->outcomes[$url] ?? Assert::fail('No timeline page scripted for ' . $url);
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
```

Create `backend/tests/Service/Refresh/SocialFeedRefresherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialFeedKind;
use App\Repository\SocialFeedRepository;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\SocialFeedRefresher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\MastodonTimelinePage;
use App\Tests\DbTestCase;
use App\Tests\Support\FeedOutcomePersisters;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubTimelineReader;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialFeedRefresherTest extends DbTestCase
{
    private MockClock $clock;
    private SocialAccount $account;
    private SocialFeed $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-12 12:00:00', 'UTC');

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('social-refresher@example.test');
        $this->account = SocialAccounts::mastodonOf($user);
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock)
            ->create($this->account, PrivateFeedModel::home());
        $home = $this->socialFeeds()->findOneFor($this->account, SocialFeedKind::Home, null);
        self::assertNotNull($home);
        $this->home = $home;
    }

    public function testAPageIsStoredAndItsNewestIdKeptForTheNextPoll(): void
    {
        $result = $this->refresher(self::page('109'))->refresh($this->home->getFeed(), $this->clock->now());

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(1, $result->entriesCreated);
        $feedId = $this->home->getFeed()->requireId();
        $this->entityManager->clear();
        self::assertSame('109', $this->socialFeeds()->find($this->home->requireId())?->getNewestSeenId());
        self::assertSame(1, $this->entityManager->getRepository(Entry::class)->count(['feed' => $feedId]));
    }

    public function testARefusedCredentialPausesTheAccountAndFailsTheFeed(): void
    {
        $refused = new SocialCredentialsRejectedException('The Mastodon instance refused this access token.');

        $result = $this->refresher($refused)->refresh($this->home->getFeed(), $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        $this->entityManager->clear();
        self::assertSame(
            SocialAccountStatus::NeedsReconnect,
            $this->entityManager->find(SocialAccount::class, $this->account->requireId())?->getStatus(),
        );
        self::assertSame(
            'The Mastodon instance refused this access token.',
            $this->entityManager->find(Feed::class, $this->home->getFeed()->requireId())?->getLastErrorMessage(),
        );
    }

    public function testAnAccountAlreadyPausedIsNotAskedAgain(): void
    {
        $this->account->markNeedsReconnect();
        $this->entityManager->flush();
        $reader = new StubTimelineReader();

        $result = $this->refresherReading($reader)->refresh($this->home->getFeed(), $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        self::assertSame([], $reader->asked);
    }

    public function testAThrottledTimelineWaitsOutItsRetryAfter(): void
    {
        $throttled = new SocialNetworkThrottledException('The Mastodon instance asked for fewer requests.', 120);

        $result = $this->refresher($throttled)->refresh($this->home->getFeed(), $this->clock->now());

        self::assertSame(FeedOutcome::Throttled, $result->outcome);
        self::assertSame(0, $this->home->getFeed()->getConsecutiveFailures());
        self::assertSame('2026-10-12 12:02:00', $this->home->getFeed()->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testAnUnreachableNetworkBacksOffAndKeepsTheCursor(): void
    {
        $this->home->recordNewestSeen('100');
        $this->entityManager->flush();

        $result = $this->refresher(new SocialNetworkUnreachableException('The Mastodon instance answered 502.'))
            ->refresh($this->home->getFeed(), $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        self::assertSame(1, $this->home->getFeed()->getConsecutiveFailures());
        self::assertSame('100', $this->home->getNewestSeenId());
    }

    private function refresher(TimelinePageModel|\Throwable $outcome): SocialFeedRefresher
    {
        return $this->refresherReading(new StubTimelineReader([$this->home->getFeed()->getUrl() => $outcome]));
    }

    private function refresherReading(StubTimelineReader $reader): SocialFeedRefresher
    {
        return new SocialFeedRefresher(
            $this->socialFeeds(),
            $reader,
            (new FeedOutcomePersisters(self::getContainer(), $this->entityManager, $this->clock))->idle(),
        );
    }

    private static function page(string $newestId): TimelinePageModel
    {
        return MastodonTimelinePage::of([JsonNodeModel::of([
            'id' => $newestId,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $newestId,
            'url' => 'https://mastodon.example/@bob/' . $newestId,
            'created_at' => '2026-10-12T11:59:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ])]);
    }

    private function socialFeeds(): SocialFeedRepository
    {
        /** @var SocialFeedRepository $socialFeeds */
        $socialFeeds = $this->entityManager->getRepository(SocialFeed::class);

        return $socialFeeds;
    }
}
```

Create `backend/tests/Service/Refresh/RefreshRunner/RefreshRunnerSocialTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\RefreshRunner;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Support\MastodonTimelinePage;
use App\Tests\DbTestCase;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\StubTimelineReader;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RefreshRunnerSocialTest extends DbTestCase
{
    private const string RSS_URL = 'https://example.com/feed.xml';

    public function testAPrivateFeedIsReadThroughItsAccountAndNeverFetchedByUrl(): void
    {
        $clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('refresh-social@example.test');
        $account = SocialAccounts::mastodonOf($user);
        $rss = new Feed(self::RSS_URL);
        $this->entityManager->persist($account);
        $this->entityManager->persist($rss);
        $this->entityManager->persist(new Subscription($user, $rss, $clock->now()));
        $this->entityManager->flush();
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $clock)
            ->create($account, PrivateFeedModel::home());
        $homeUrl = sprintf('social:mastodon:%d:home', $account->requireId());
        $fetcher = new StubFeedFetcher($clock);
        $fetcher->willReturnBody(self::RSS_URL, <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Example</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>p-1</guid></item>
            </channel></rss>
            XML);
        $homepages = new StubFeedFetcher();
        foreach (['https://example.com', 'https://mastodon.example'] as $origin) {
            $homepages->willReturn($origin, FetchResponseModel::fetched($origin, false, '<html lang="en"></html>', null, null));
        }
        $reader = new StubTimelineReader([$homeUrl => MastodonTimelinePage::of([JsonNodeModel::of([
            'id' => '109',
            'uri' => 'https://mastodon.example/users/bob/statuses/109',
            'url' => 'https://mastodon.example/@bob/109',
            'created_at' => '2026-10-12T11:59:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ])])]);

        $report = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->readingTimelinesFrom($reader)
            ->build($fetcher, $homepages)
            ->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->total);
        self::assertSame(2, $report->fetched);
        self::assertSame([self::RSS_URL], $fetcher->fetchedUrls);
        self::assertCount(1, $reader->asked);
        $home = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => $homeUrl]);
        self::assertNotNull($home);
        self::assertSame(1, $this->entityManager->getRepository(Entry::class)->count(['feed' => $home]));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Refresh/Pass/BudgetedFeedQueueTest.php tests/Service/Refresh/SocialFeedRefresherTest.php tests/Service/Refresh/RefreshRunner/RefreshRunnerSocialTest.php`
Expected: FAIL: `BudgetedFeedQueue::socialFeeds()` is undefined, `SocialFeedRefresher` is not found, and `RefreshRunners::readingTimelinesFrom()` is undefined.

- [ ] **Step 3: Split the queue's turns**

In `backend/src/Service/Refresh/Pass/BudgetedFeedQueue.php`, add `use App\Enum\SourceFormat;` and replace `tickets()` with:

```php
    /** @return \Generator<int, FetchTicketModel, mixed, void> */
    public function tickets(): \Generator
    {
        $fetched = static fn (Feed $feed): bool => !SourceFormat::isSocial($feed->getSourceFormat());
        foreach ($this->startedAmong($fetched) as $feedId => $feed) {
            yield $feedId => new FetchTicketModel($feed->getUrl(), $feed->getEtag(), $feed->getLastModified());
        }
    }

    /**
     * Private feeds, read through their account instead of fetched by URL, on the same budget.
     *
     * @return \Generator<int, Feed, mixed, void>
     */
    public function socialFeeds(): \Generator
    {
        yield from $this->startedAmong(static fn (Feed $feed): bool => SourceFormat::isSocial($feed->getSourceFormat()));
    }

    /**
     * @param \Closure(Feed): bool $belongs
     *
     * @return \Generator<int, Feed, mixed, void>
     */
    private function startedAmong(\Closure $belongs): \Generator
    {
        foreach ($this->feeds as $feed) {
            if (!$belongs($feed)) {
                continue;
            }
            if (!$this->mayStartAnother()) {
                return;
            }

            $this->startedFeedIds[] = $feed->requireId();

            yield $feed->requireId() => $feed;
        }
    }
```

In `backend/src/Service/Refresh/Pass/RefreshPass.php`, after `tickets()`, add:

```php
    /** @return \Generator<int, Feed, mixed, void> */
    public function socialFeeds(): \Generator
    {
        return $this->queue->socialFeeds();
    }
```

- [ ] **Step 4: Write `SocialFeedRefresher` and `RefreshPassFetcher`**

Create `backend/src/Service/Refresh/SocialFeedRefresher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialAccountStatus;
use App\Repository\SocialFeedRepository;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineReader\TimelineReaderInterface;

/** Polls one private feed through its account and stores the page the way a fetched feed is stored. */
final readonly class SocialFeedRefresher
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private TimelineReaderInterface $timelines,
        private FeedOutcomePersister $outcomePersister,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function refresh(Feed $feed, \DateTimeImmutable $now): FeedRefreshResultModel
    {
        $socialFeed = $this->socialFeeds->findOneByFeed($feed);
        if (null === $socialFeed) {
            return $this->outcomePersister->storeFailure(
                $feed,
                new \UnexpectedValueException('This private feed has lost its social account.'),
            );
        }

        try {
            $page = $this->pageOf($socialFeed);
        } catch (SocialNetworkThrottledException $exception) {
            return $this->outcomePersister->storeThrottled($feed, $exception->retryAfterSeconds);
        } catch (
            SocialAccountNeedsReconnectException |
            SocialCredentialsRejectedException |
            SocialNetworkUnreachableException $exception
        ) {
            return $this->outcomePersister->storeFailure($feed, $exception);
        }

        // Set before the store's flush, which writes it with the page's entries, or not at all.
        $socialFeed->recordNewestSeen($page->newestId);

        return $this->outcomePersister->storeParsed(
            $feed,
            new IncomingFeedModel($page->parsed, $page->knownShares),
            $now,
        );
    }

    /**
     * An account refused earlier in this pass is not asked again: findDue() chose its feeds before the refusal.
     *
     * @throws SocialAccountNeedsReconnectException
     */
    private function pageOf(SocialFeed $socialFeed): TimelinePageModel
    {
        $account = $socialFeed->getSocialAccount();
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            throw new SocialAccountNeedsReconnectException(sprintf('%s needs reconnecting.', $account->getHandle()));
        }

        try {
            return $this->timelines->page($socialFeed);
        } catch (SocialCredentialsRejectedException $exception) {
            $account->markNeedsReconnect();

            throw $exception;
        }
    }
}
```

Create `backend/src/Service/Refresh/RefreshPassFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Refresh\Pass\RefreshPass;

/** Stores each due feed's outcome as it lands: fetched feeds through the batch engine, private ones per account. */
final readonly class RefreshPassFetcher
{
    public function __construct(
        private BatchFeedFetcherInterface $fetcher,
        private FeedOutcomePersister $outcomePersister,
        private SocialFeedRefresher $socialRefresher,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function fetchAndPersist(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        foreach ($this->fetcher->fetchAll($pass->tickets()) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($this->outcomePersister->persist($feed, $outcome, $now), $feed);
            if ($pass->tally->isAborted()) {
                return;
            }
        }

        foreach ($pass->socialFeeds() as $feed) {
            $pass->tally->record($this->socialRefresher->refresh($feed, $now), $feed);
            if ($pass->tally->isAborted()) {
                return;
            }
        }
    }
}
```

- [ ] **Step 5: Hand the pass to `RefreshPassFetcher`**

In `backend/src/Service/Refresh/RefreshRunner/RefreshRunner.php`:

1. Replace the constructor parameters `private BatchFeedFetcherInterface $fetcher,` and `private FeedOutcomePersister $outcomePersister,` with `private RefreshPassFetcher $passFetcher,`.
2. In `refresh()`, replace `$this->persistOutcomes($pass, $now);` with `$this->passFetcher->fetchAndPersist($pass, $now);`.
3. Delete the private `persistOutcomes()` method.
4. Imports: remove `App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface` and `App\Service\Refresh\FeedOutcomePersister`; add `App\Service\Refresh\RefreshPassFetcher`.

- [ ] **Step 6: Rewire the test assembly**

Replace `backend/tests/Support/RefreshRunners.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Repository\FeedRepository;
use App\Repository\OrphanedFeedRepository;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Repository\SocialFeedRepository;
use App\Service\Feed\OrphanedFeedReclaimer;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\FaviconResolver\FaviconResolver;
use App\Service\Parser\Factory\FeedParserFactory;
use App\Service\Parser\FeedParser;
use App\Service\Refresh\ContentChangeMarker\ContentChangeMarkerInterface;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedBodyParser\ScrapedBodyParser;
use App\Service\Refresh\FeedBodyParser\XmlBodyParser;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\MissingFaviconResolver;
use App\Service\Refresh\RefreshHousekeeping;
use App\Service\Refresh\RefreshPassFetcher;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Refresh\SocialFeedRefresher;
use App\Service\Retention\EntryPruner;
use App\Service\Scraper\HtmlItemExtractor;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Service\Social\TimelineReader\TimelineReaderInterface;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** A RefreshRunner over the EntityManager's real repositories: the one assembly the refresh and tick tests share. */
final readonly class RefreshRunners
{
    private function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private FeedBodyParser $bodyParser,
        private EntityManagerInterface $flushingEntityManager,
        private LockFactory $lockFactory,
        private ContentChangeMarkerInterface $changeMarker,
        private SearchIndexWriterInterface $indexWriter,
        private LoggerInterface $logger,
        private TimelineReaderInterface $timelines,
    ) {
    }

    public static function fromContainer(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): self {
        return new self(
            $entityManager,
            $clock,
            self::bodyParser($container),
            $entityManager,
            new LockFactory(new InMemoryStore()),
            new RecordingContentChangeMarker(),
            new RecordingSearchIndexWriter(),
            new NullLogger(),
            new StubTimelineReader(),
        );
    }

    /** Only the outcome and favicon flushes go through this EntityManager; the repositories keep the real one. */
    public function flushingThrough(EntityManagerInterface $flushingEntityManager): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->bodyParser,
            $flushingEntityManager,
            $this->lockFactory,
            $this->changeMarker,
            $this->indexWriter,
            $this->logger,
            $this->timelines,
        );
    }

    public function lockingWith(LockFactory $lockFactory): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->bodyParser,
            $this->flushingEntityManager,
            $lockFactory,
            $this->changeMarker,
            $this->indexWriter,
            $this->logger,
            $this->timelines,
        );
    }

    public function markingChangesOn(ContentChangeMarkerInterface $changeMarker): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->bodyParser,
            $this->flushingEntityManager,
            $this->lockFactory,
            $changeMarker,
            $this->indexWriter,
            $this->logger,
            $this->timelines,
        );
    }

    public function indexingInto(SearchIndexWriterInterface $indexWriter): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->bodyParser,
            $this->flushingEntityManager,
            $this->lockFactory,
            $this->changeMarker,
            $indexWriter,
            $this->logger,
            $this->timelines,
        );
    }

    public function loggingTo(LoggerInterface $logger): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->bodyParser,
            $this->flushingEntityManager,
            $this->lockFactory,
            $this->changeMarker,
            $this->indexWriter,
            $logger,
            $this->timelines,
        );
    }

    public function readingTimelinesFrom(TimelineReaderInterface $timelines): self
    {
        return new self(
            $this->entityManager,
            $this->clock,
            $this->bodyParser,
            $this->flushingEntityManager,
            $this->lockFactory,
            $this->changeMarker,
            $this->indexWriter,
            $this->logger,
            $timelines,
        );
    }

    public function build(
        BatchFeedFetcherInterface $feedFetcher,
        BatchFeedFetcherInterface $homepageFetcher,
    ): RefreshRunner {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->entityManager->getRepository(Feed::class);
        /** @var SocialFeedRepository $socialFeeds */
        $socialFeeds = $this->entityManager->getRepository(SocialFeed::class);
        $indexer = new EntryIndexer($this->indexWriter, new NullLogger());
        $outcomePersister = new FeedOutcomePersister(
            $this->flushingEntityManager,
            $feedRepository,
            $this->bodyParser,
            EntryIngestors::build($this->entityManager, $this->clock),
            FeedSchedulers::build($this->clock),
            $indexer,
            PostEnrichers::queue($this->entityManager, $this->clock),
            PostEnrichers::idle($this->entityManager, $this->clock),
            new NullLogger(),
        );

        return new RefreshRunner(
            $feedRepository,
            new RefreshPassFetcher(
                $feedFetcher,
                $outcomePersister,
                new SocialFeedRefresher($socialFeeds, $this->timelines, $outcomePersister),
            ),
            new MissingFaviconResolver(
                new FaviconResolver($homepageFetcher, new NullLogger()),
                $this->flushingEntityManager,
            ),
            new RefreshHousekeeping(
                new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->entityManager)),
                new EntryPruner(
                    new RetentionRepository($this->entityManager, new RowIds($this->entityManager)),
                    $this->clock,
                    $indexer,
                ),
                new NullLogger(),
            ),
            $this->lockFactory,
            $this->clock,
            $this->logger,
            $this->changeMarker,
        );
    }

    /** The keys the container's tagged locator carries; FeedBodyParserWiringTest proves the container routes alike. */
    private static function bodyParser(ContainerInterface $container): FeedBodyParser
    {
        $extractor = $container->get(HtmlItemExtractor::class);

        return new FeedBodyParser(new ServiceLocator([
            XmlBodyParser::format() => static fn (): XmlBodyParser => new XmlBodyParser(
                new FeedParser(new FeedParserFactory(FeedFormatParsers::all())),
            ),
            ScrapedBodyParser::format() => static fn (): ScrapedBodyParser => new ScrapedBodyParser($extractor),
        ]));
    }
}
```

`grep -rn "new RefreshRunner(" backend/src backend/tests` must show only this file.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Refresh tests/Service/Maintenance tests/Service/Worker tests/Controller/Api/RefreshControllerTest.php tests/Controller/MaintenanceControllerTest.php`
Expected: PASS. If a listed path does not exist, drop it from the command; the `tests/Service/Refresh` directory is the one that must pass.

- [ ] **Step 8: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean. `composer tramp`: the chain `RefreshPassFetcher::fetchAndPersist → SocialFeedRefresher::refresh → FeedOutcomePersister::storeParsed` forwards `$now` across two methods before it is read; that is under the 3-hop warning. `composer stan` checks the new `Refresh → Social` edge.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Refresh backend/tests/Support/StubTimelineReader.php backend/tests/Support/RefreshRunners.php \
  backend/tests/Service/Refresh/Pass/BudgetedFeedQueueTest.php backend/tests/Service/Refresh/SocialFeedRefresherTest.php \
  backend/tests/Service/Refresh/RefreshRunner/RefreshRunnerSocialTest.php
git commit -m "feat(#1599): the refresh polls private feeds through their account inside the same budget"
```

---
### Task 10: Private feeds stay out of OPML, backups and every URL entry point

**Files:**
- Modify: `backend/src/Repository/SubscriptionRepository.php:41-55`
- Modify: `backend/src/Service/Opml/OpmlExporter.php:28`
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php:64`
- Modify: `backend/src/Service/Backup/Dto/FeedLine.php`
- Modify: `docs/security.md` (Social accounts)
- Test: `backend/tests/Repository/ExportableSubscriptionsTest.php`, `backend/tests/Service/Opml/OpmlExporterTest.php`, `backend/tests/Service/Backup/AccountBackupExporterTest.php`, `backend/tests/Service/Backup/Dto/FeedLineTest.php`, `backend/tests/Controller/Api/PrivateFeedUrlRefusalTest.php`

**Interfaces:**
- Consumes: `SourceFormat::SOCIAL` / `isSocial()`, `SocialFeedUrl::isPrivate()`.
- Produces:
  - `SubscriptionRepository::findExportableForUserWithTags(int $userId): list<Subscription>`, the same as `findForUserWithTags()` minus private feeds
  - `FeedLine::fromLine()` throws `InvalidBackupException` for a `social:` URL or a social source format

Checked while planning, no change needed:
- `SubscribeRequest` (`Assert\Url` http/https) and `BulkSubscriber::isSubscribableUrl` refuse a `social:` URL. Task 10 pins the first with a test.
- The catalog is the separate `CatalogFeed`.
- Search, For You and recommendations scope by the user's own subscriptions.
- The admin user screen (`AdminUserJson`) shows only the inspected user's own subscription URLs, so the synthetic URL reveals nothing there.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Repository/ExportableSubscriptionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;

final class ExportableSubscriptionsTest extends DbTestCase
{
    public function testAnExportLeavesThePrivateSocialFeedsOut(): void
    {
        $user = new User('exportable@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $rss = $this->subscribed($user, 'https://example.com/feed.xml', SourceFormat::XML);
        $private = $this->subscribed($user, 'social:mastodon:1:home', SourceFormat::MASTODON_API);
        $this->entityManager->flush();

        self::assertSame([$rss], $this->subscriptions()->findExportableForUserWithTags($user->requireId()));
        self::assertSame([$rss, $private], $this->subscriptions()->findForUserWithTags($user->requireId()));
    }

    private function subscribed(User $user, string $url, string $sourceFormat): Subscription
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function subscriptions(): SubscriptionRepository
    {
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = $this->entityManager->getRepository(Subscription::class);

        return $subscriptions;
    }
}
```

Append to `backend/tests/Service/Opml/OpmlExporterTest.php`:

```php
    public function testAPrivateSocialFeedIsLeftOut(): void
    {
        $user = new User('opml-social@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $rss = new Feed('https://blog.example.com/feed.xml');
        $private = new Feed('social:bluesky:1:home');
        $private->setSourceFormat(SourceFormat::BLUESKY_API);
        foreach ([$rss, $private] as $feed) {
            $this->entityManager->persist($feed);
            $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-10-12T00:00:00Z')));
        }
        $this->entityManager->flush();

        $xml = $this->exporter()->export($user);

        self::assertStringContainsString('xmlUrl="https://blog.example.com/feed.xml"', $xml);
        self::assertStringNotContainsString('social:', $xml);
    }
```

Add `use App\Enum\SourceFormat;`.

Append to `backend/tests/Service/Backup/AccountBackupExporterTest.php`:

```php
    public function testAPrivateSocialFeedAndItsPostsStayOutOfTheBackup(): void
    {
        $user = $this->makeUser('backup-social@example.test');
        $rss = new Feed('https://example.com/feed.xml');
        $private = new Feed('social:bluesky:1:home');
        $private->setSourceFormat(SourceFormat::BLUESKY_API);
        $now = new \DateTimeImmutable('2026-10-12 10:00:00');
        foreach ([$rss, $private] as $feed) {
            $this->entityManager->persist($feed);
            $this->entityManager->persist(new Subscription($user, $feed, $now));
            $entry = new Entry($feed, $feed->getUrl() . '#post', null, 'Post', $now, $now);
            $entry->getImage()->storePending(null, null, null);
            $this->entityManager->persist($entry);
        }
        $this->entityManager->flush();

        $exported = json_encode($this->decodedParts($user), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        self::assertStringContainsString('https://example.com/feed.xml', $exported);
        self::assertStringNotContainsString('social:', $exported);
    }
```

Add `use App\Enum\SourceFormat;`.

Create `backend/tests/Service/Backup/Dto/FeedLineTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Enum\SourceFormat;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedLineTest extends TestCase
{
    /** @param array<string, mixed> $line */
    #[DataProvider('privateFeedLines')]
    public function testAPrivateSocialFeedIsRefused(array $line): void
    {
        $this->expectException(InvalidBackupException::class);

        FeedLine::fromLine($line);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function privateFeedLines(): iterable
    {
        yield 'a social url, posing as rss' => [self::line('social:bluesky:5:home', SourceFormat::XML)];
        yield 'a social format' => [self::line('https://example.com/feed.xml', SourceFormat::MASTODON_API)];
    }

    public function testAnOrdinaryFeedIsRead(): void
    {
        $line = FeedLine::fromLine(self::line('https://example.com/feed.xml', SourceFormat::XML));

        self::assertSame('https://example.com/feed.xml', $line->url);
    }

    /** @return array<string, mixed> */
    private static function line(string $url, string $sourceFormat): array
    {
        return [
            'url' => $url,
            'siteUrl' => null,
            'title' => 'Feed',
            'description' => null,
            'faviconUrl' => null,
            'imageUrl' => null,
            'sourceFormat' => $sourceFormat,
        ];
    }
}
```

Create `backend/tests/Controller/Api/PrivateFeedUrlRefusalTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class PrivateFeedUrlRefusalTest extends ApiTestCase
{
    public function testASocialUrlCannotBeSubscribedToByHand(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('private-url@example.test');
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user), 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'social:bluesky:1:home'], \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $this->payload($client)['type']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Repository/ExportableSubscriptionsTest.php tests/Service/Opml/OpmlExporterTest.php tests/Service/Backup/AccountBackupExporterTest.php tests/Service/Backup/Dto/FeedLineTest.php tests/Controller/Api/PrivateFeedUrlRefusalTest.php`
Expected:
- FAIL: `findExportableForUserWithTags()` is undefined.
- FAIL: OPML and the backup contain `social:`.
- FAIL: `FeedLine` accepts both private lines.
- PASS: `PrivateFeedUrlRefusalTest` already passes; it pins the URL guard that keeps a hand-made `social:` subscription out.

- [ ] **Step 3: One exportable-subscriptions query**

In `backend/src/Repository/SubscriptionRepository.php`, add the imports `App\Enum\SourceFormat` and `Doctrine\ORM\QueryBuilder`, and replace `findForUserWithTags()` with:

```php
    /** @return list<Subscription> */
    public function findForUserWithTags(int $userId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->withTagsForUser($userId)->getQuery()->getResult();

        return $rows;
    }

    /**
     * What an OPML or backup export carries: a private social feed is read through an account neither file holds.
     *
     * @return list<Subscription>
     */
    public function findExportableForUserWithTags(int $userId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->withTagsForUser($userId)
            ->andWhere('f.sourceFormat NOT IN (:socialFormats)')
            ->setParameter('socialFormats', SourceFormat::SOCIAL)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    private function withTagsForUser(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.subscriptionTags', 'st')->addSelect('st')
            ->leftJoin('st.tag', 't')->addSelect('t')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC');
    }
```

- [ ] **Step 4: Use it in both exports**

In `backend/src/Service/Opml/OpmlExporter.php`, change `$subscriptions = $this->subscriptions->findForUserWithTags($user->requireId());` to `$subscriptions = $this->subscriptions->findExportableForUserWithTags($user->requireId());`.

In `backend/src/Service/Backup/AccountBackupExporter.php::foundationSnapshot()`, change `$subscriptions = $this->subscriptions->findForUserWithTags($userId);` to `$subscriptions = $this->subscriptions->findExportableForUserWithTags($userId);`. The entry walk iterates `feedUrlsByFeedId`, which comes from these subscriptions, so the private feeds' entries and entry states drop out with them.

- [ ] **Step 5: Refuse a private feed in a restore**

Replace `FeedLine::fromLine()` in `backend/src/Service/Backup/Dto/FeedLine.php` with:

```php
    /**
     * @param array<string, mixed> $line
     *
     * @throws InvalidBackupException for a private social feed: referenced by URL, it would hand over another
     *                                user's timeline, and created anew it could never be read
     */
    public static function fromLine(array $line): self
    {
        $feedLine = new self(
            url: LineField::string($line, 'url'),
            siteUrl: LineField::stringOrNull($line, 'siteUrl'),
            title: LineField::stringOrNull($line, 'title'),
            description: LineField::stringOrNull($line, 'description'),
            faviconUrl: LineField::stringOrNull($line, 'faviconUrl'),
            imageUrl: LineField::stringOrNull($line, 'imageUrl'),
            sourceFormat: LineField::string($line, 'sourceFormat'),
        );
        if (SocialFeedUrl::isPrivate($feedLine->url) || SourceFormat::isSocial($feedLine->sourceFormat)) {
            throw new InvalidBackupException(
                sprintf('Feed "%s" is a private social feed, which a backup never carries.', $feedLine->url),
            );
        }

        return $feedLine;
    }
```

Add the imports `App\Enum\SourceFormat`, `App\Service\Backup\Exception\InvalidBackupException` and `App\Service\Social\Support\SocialFeedUrl`.

- [ ] **Step 6: Document the boundary**

In `docs/security.md` "## Social accounts", append:

```markdown
- A private feed's synthetic `social:` URL never reaches `UrlGuard` or the feed fetcher: only `SocialFeedRefresher`
  reads the feed, through its account. The URL names the owning account, not the network identity, so two users of one
  network account get separate rows. Subscribing by URL refuses the scheme. OPML and backups leave private feeds out,
  and a restore refuses a `social:` feed line, which could otherwise attach another user's timeline by URL.
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Repository tests/Service/Opml tests/Service/Backup tests/Controller/Api/PrivateFeedUrlRefusalTest.php`
Expected: PASS.

- [ ] **Step 8: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean. `composer stan` checks the new `Backup → Social` edge.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Repository/SubscriptionRepository.php backend/src/Service/Opml/OpmlExporter.php \
  backend/src/Service/Backup/AccountBackupExporter.php backend/src/Service/Backup/Dto/FeedLine.php docs/security.md \
  backend/tests/Repository/ExportableSubscriptionsTest.php backend/tests/Service/Opml/OpmlExporterTest.php \
  backend/tests/Service/Backup/AccountBackupExporterTest.php backend/tests/Service/Backup/Dto/FeedLineTest.php \
  backend/tests/Controller/Api/PrivateFeedUrlRefusalTest.php
git commit -m "feat(#1599): OPML and backups leave private feeds out, and a restore refuses one"
```

---

### Task 11: Subscriptions name their network, account and kind

**Files:**
- Modify: `backend/src/Http/SubscriptionJson.php`
- Modify: `backend/src/Controller/Api/SubscriptionController.php`
- Test: `backend/tests/Http/SubscriptionJsonTest.php`, `backend/tests/Http/SubscriptionJsonListTest.php`, `backend/tests/Controller/Api/SubscriptionSocialListTest.php`

**Interfaces:**
- Consumes: `SocialFeedUrl::addressOf()`, `SourceFormat::isSocial()`, `SocialAccountRepository::findAllForUser(User): list<SocialAccount>`.
- Produces (JSON; the frontend in Task 14 relies on these exact keys):
  - every subscription gains `"social": {"network": "bluesky"|"mastodon", "accountId": int, "kind": "home"|"list"|"custom_feed"} | null`;
  - `GET /api/subscriptions` gains a top-level `"socialAccounts": [{"id": int, "network": string, "handle": string}]`, after `viewedCount`.
- PHP: `SubscriptionJson::list(array $subscriptions, SubscriptionTalliesModel $tallies, array $socialAccounts = [])` with `@param list<SocialAccount> $socialAccounts`.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Http/SubscriptionJsonTest.php`:

```php
    public function testAPrivateFeedNamesItsNetworkAccountAndKind(): void
    {
        $feed = new Feed('social:bluesky:12:list:at://did:plc:bob/app.bsky.graph.list/3l1');
        $feed->setSourceFormat(SourceFormat::BLUESKY_API);

        self::assertSame(
            ['network' => 'bluesky', 'accountId' => 12, 'kind' => 'list'],
            SubscriptionJson::one($this->subscriptionTo($feed))['social'],
        );
    }

    public function testAnOrdinaryFeedHasNoSocialPart(): void
    {
        self::assertNull(SubscriptionJson::one($this->subscriptionTo(new Feed('https://example.com/feed.xml')))['social']);
    }

    public function testASocialLookingUrlOfAnRssFeedIsNoPrivateFeed(): void
    {
        self::assertNull(SubscriptionJson::one($this->subscriptionTo(new Feed('social:bluesky:12:home')))['social']);
    }
```

Add `use App\Enum\SourceFormat;`. The existing private helper `subscriptionTo(Feed $feed): Subscription` is used as is.

In `backend/tests/Http/SubscriptionJsonListTest.php`, change the keys assertion to:

```php
        self::assertSame(
            ['subscriptions', 'favoritesCount', 'keptCount', 'viewedCount', 'socialAccounts'],
            array_keys($payload),
        );
```

and append:

```php
    public function testTheListNamesEachConnectedAccountsHandleOnce(): void
    {
        $user = new User('subscription-list-social@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $account = SocialAccounts::blueskyOf($user);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $payload = SubscriptionJson::list(
            [],
            new SubscriptionTalliesModel([], [], ['favorites' => 0, 'kept' => 0, 'viewed' => 0]),
            [$account],
        );

        self::assertSame(
            [['id' => $account->requireId(), 'network' => 'bluesky', 'handle' => '@alice.bsky.social']],
            $payload['socialAccounts'],
        );
    }
```

Add `use App\Tests\Support\SocialAccounts;`.

Create `backend/tests/Controller/Api/SubscriptionSocialListTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\PrivateFeeds;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\SocialAccounts;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class SubscriptionSocialListTest extends ApiTestCase
{
    public function testTheListMarksAPrivateFeedAndNamesItsAccount(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('social-list@example.test');
        $account = SocialAccounts::blueskyOf($user);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();
        $privateFeeds = self::getContainer()->get(PrivateFeeds::class);
        self::assertInstanceOf(PrivateFeeds::class, $privateFeeds);
        $privateFeeds->create($account, PrivateFeedModel::home());
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        $client->request('GET', '/api/subscriptions', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)]);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertIsArray($payload['subscriptions']);
        $home = $payload['subscriptions'][0];
        self::assertIsArray($home);
        self::assertSame(['network' => 'bluesky', 'accountId' => $account->requireId(), 'kind' => 'home'], $home['social']);
        self::assertFalse($home['includeInAllItems']);
        self::assertSame(
            [['id' => $account->requireId(), 'network' => 'bluesky', 'handle' => '@alice.bsky.social']],
            $payload['socialAccounts'],
        );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Http/SubscriptionJsonTest.php tests/Http/SubscriptionJsonListTest.php tests/Controller/Api/SubscriptionSocialListTest.php`
Expected: FAIL with `Undefined array key "social"`; the list keys lack `socialAccounts`.

- [ ] **Step 3: Shape the JSON**

In `backend/src/Http/SubscriptionJson.php`:

1. Add the imports `App\Entity\SocialAccount`, `App\Enum\SourceFormat` and `App\Service\Social\Support\SocialFeedUrl`.
2. Replace `list()` with:

```php
    /**
     * @param list<Subscription>  $subscriptions
     * @param list<SocialAccount> $socialAccounts the handles the sidebar labels a network's accounts with
     *
     * @return array<string, mixed>
     */
    public static function list(
        array $subscriptions,
        SubscriptionTalliesModel $tallies,
        array $socialAccounts = [],
    ): array {
        return [
            'subscriptions' => array_map(
                static fn (Subscription $subscription): array => self::one(
                    $subscription,
                    $tallies->unreadCounts[$subscription->requireId()] ?? 0,
                    $tallies->entryCounts[$subscription->requireId()] ?? 0,
                ),
                $subscriptions,
            ),
            ...SubscriptionCountsJson::surfaceTotals($tallies),
            'socialAccounts' => array_map(self::socialAccount(...), $socialAccounts),
        ];
    }
```

3. In `one()`'s `@return` array shape, append `, social: array{network: string, accountId: int, kind: string}|null` after `includeInForYou: bool`. Add `'social' => self::social($feed),` as the last element of the returned array.
4. Add:

```php
    /**
     * Read from the feed's own synthetic URL, so every endpoint that returns one subscription carries it without a
     * query. The format must agree: only refreshes route by it.
     *
     * @return array{network: string, accountId: int, kind: string}|null
     */
    private static function social(Feed $feed): ?array
    {
        $address = SourceFormat::isSocial($feed->getSourceFormat()) ? SocialFeedUrl::addressOf($feed->getUrl()) : null;
        if (null === $address) {
            return null;
        }

        return ['network' => $address->network->value, 'accountId' => $address->accountId, 'kind' => $address->kind->value];
    }

    /** @return array{id: int, network: string, handle: string} */
    private static function socialAccount(SocialAccount $account): array
    {
        return ['id' => $account->requireId(), 'network' => $account->getNetwork()->value, 'handle' => $account->getHandle()];
    }
```

- [ ] **Step 4: Pass the accounts from the controller**

In `backend/src/Controller/Api/SubscriptionController.php`:

1. Add `private SocialAccountRepository $socialAccounts,` to the constructor, after `private SubscriptionRepository $subscriptions,`, and import `App\Repository\SocialAccountRepository`.
2. Change `list()` to:

```php
    #[Route('', name: 'api_subscriptions_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(SubscriptionJson::list(
            $this->subscriptions->findForUserWithTags($user->requireId()),
            $this->tallies->forUser($user->requireId()),
            $this->socialAccounts->findAllForUser($user),
        ));
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Http tests/Controller/Api/SubscriptionControllerTest.php tests/Controller/Api/SubscriptionBulkTest.php tests/Controller/Api/SubscriptionSocialListTest.php`
Expected: PASS.

- [ ] **Step 6: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean (the controller still has no private method; `ThinControllerRule` passes).

- [ ] **Step 7: Commit**

```bash
git add backend/src/Http/SubscriptionJson.php backend/src/Controller/Api/SubscriptionController.php \
  backend/tests/Http/SubscriptionJsonTest.php backend/tests/Http/SubscriptionJsonListTest.php \
  backend/tests/Controller/Api/SubscriptionSocialListTest.php
git commit -m "feat(#1599): a subscription names its social network, account and kind; the list names the handles"
```

---

### Task 12: Lists, custom feeds and Home become importable

**Files:**
- Create: `backend/src/Service/Social/Model/CollectionCandidateModel.php`
- Create: `backend/src/Service/Social/Model/CollectionImportModel.php`
- Create: `backend/src/Service/Social/Support/PrivateFeedRules.php`
- Modify: `backend/src/Service/Social/Model/ImportCandidatesModel.php`
- Modify: `backend/src/Service/Social/Model/SocialImportModel.php`
- Modify: `backend/src/Service/Social/Model/SocialImportResultModel.php`
- Modify: `backend/src/Service/Social/ImportCandidates.php`
- Modify: `backend/src/Service/Social/SocialImporter.php`
- Create: `backend/src/Dto/Social/SocialImportCollectionItem.php`
- Modify: `backend/src/Dto/Social/SocialImportRequest.php`
- Modify: `backend/src/Http/SocialImportJson.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php:57-62`
- Test: `backend/tests/Service/Social/Support/PrivateFeedRulesTest.php`, `backend/tests/Service/Social/ImportCandidatesTest.php`, `backend/tests/Service/Social/SocialImporterTest.php`, `backend/tests/Controller/Api/SocialImportControllerTest.php`

**Interfaces:**
- Consumes: `PrivateFeeds::create()`, `PrivateFeedModel::of()/home()/key()/keyOf()`, `PrivateFeedOutcome`, `SocialFeedRepository::findAllOf()/subscriptionsOf()`.
- Produces (PHP):
  - `CollectionCandidateModel` (public `PrivateFeedModel $privateFeed`, `ImportCandidateState $state`, `?int $subscriptionId`); `CollectionCandidateModel::of(PrivateFeedModel $privateFeed, array<string, int> $subscriptionIds): self`
  - `CollectionImportModel(int $added = 0, int $alreadySubscribed = 0, int $overLimit = 0)` with `counting(PrivateFeedOutcome): self` and `countingOverLimit(): self`
  - `PrivateFeedRules::allows(SocialNetwork $network, PrivateFeedModel $privateFeed): bool`
  - `ImportCandidatesModel::$lists` / `$customFeeds`: `list<CollectionCandidateModel>`
  - `new SocialImportModel(list<SocialImportFeedModel> $subscribe, list<int> $unsubscribe, list<PrivateFeedModel> $collections = [])`
  - `SocialImportResultModel::from(BulkSubscribeResultModel $subscribed, CollectionImportModel $collections, int $removed): self`
- Produces (JSON; the frontend in Task 13 relies on these exact keys):
  - Candidates `lists[]` / `customFeeds[]` = `{"kind": "home"|"list"|"custom_feed", "id": string|null, "name": string, "state": "new"|"subscribed", "subscriptionId": int|null}`, and `lists[0]` is Home.
  - POST `/import` accepts `"collections": [{"kind": …, "id": string|null, "name": string}]` (at most 100).
  - The result keys are unchanged; collections count into `imported`, `alreadySubscribed` and `skippedOverLimit`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Social/Support/PrivateFeedRulesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Support\PrivateFeedRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrivateFeedRulesTest extends TestCase
{
    private const string LIST = 'at://did:plc:bob/app.bsky.graph.list/3l1';
    private const string FEED = 'at://did:plc:bob/app.bsky.feed.generator/news';

    #[DataProvider('verdicts')]
    public function testACollectionMustSuitItsNetwork(SocialNetwork $network, PrivateFeedModel $privateFeed, bool $allowed): void
    {
        self::assertSame($allowed, PrivateFeedRules::allows($network, $privateFeed));
    }

    /** @return iterable<string, array{SocialNetwork, PrivateFeedModel, bool}> */
    public static function verdicts(): iterable
    {
        yield 'home on bluesky' => [SocialNetwork::Bluesky, PrivateFeedModel::home(), true];
        yield 'home on mastodon' => [SocialNetwork::Mastodon, PrivateFeedModel::home(), true];
        yield 'bluesky list' => [SocialNetwork::Bluesky, PrivateFeedModel::userList(self::LIST, 'Writers'), true];
        yield 'bluesky list named by a feed uri' => [SocialNetwork::Bluesky, PrivateFeedModel::userList(self::FEED, 'Writers'), false];
        yield 'bluesky custom feed' => [SocialNetwork::Bluesky, PrivateFeedModel::customFeed(self::FEED, 'News'), true];
        yield 'mastodon list' => [SocialNetwork::Mastodon, PrivateFeedModel::userList('42', 'Friends'), true];
        yield 'mastodon list named by a uri' => [SocialNetwork::Mastodon, PrivateFeedModel::userList(self::LIST, 'Friends'), false];
        yield 'mastodon has no custom feeds' => [SocialNetwork::Mastodon, PrivateFeedModel::customFeed(self::FEED, 'News'), false];
        yield 'a list without an id' => [SocialNetwork::Bluesky, PrivateFeedModel::of(SocialFeedKind::UserList, null, 'X'), false];
    }
}
```

In `backend/tests/Service/Social/ImportCandidatesTest.php`:

1. In `importCandidates()`, pass the repository: replace `new FollowMatcher($resolver),` + `$this->entityManager,` with:

```php
            new FollowMatcher($resolver),
            $this->socialFeeds(),
            $this->entityManager,
```

and add:

```php
    private function socialFeeds(): SocialFeedRepository
    {
        /** @var SocialFeedRepository $socialFeeds */
        $socialFeeds = $this->entityManager->getRepository(SocialFeed::class);

        return $socialFeeds;
    }
```

2. In `testListsAndCustomFeedsAreSortedByNameAndTruncationIsKept()`, replace the two name assertions with:

```php
        self::assertSame(
            ['Home', 'Artists', 'writers'],
            array_map(static fn (CollectionCandidateModel $list): string => $list->privateFeed->name, $candidates->lists),
        );
        self::assertSame(
            ['Feed 9', 'Feed 10'],
            array_map(static fn (CollectionCandidateModel $feed): string => $feed->privateFeed->name, $candidates->customFeeds),
        );
```

3. Append:

```php
    public function testHomeLeadsTheListsAndAnAddedCollectionReadsAsSubscribed(): void
    {
        $account = $this->account();
        $writers = 'at://did:plc:alice/app.bsky.graph.list/writers';
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, new MockClock('2026-10-12 12:00:00', 'UTC'))
            ->create($account, PrivateFeedModel::userList($writers, 'Writers'));
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy([]);
        self::assertNotNull($subscription);
        $network = new StubSocialCandidates(new NetworkCandidatesModel(
            [],
            [new SocialCollectionModel($writers, 'Writers'), new SocialCollectionModel('at://did:plc:alice/app.bsky.graph.list/art', 'Artists')],
            [new SocialCollectionModel('at://did:plc:x/app.bsky.feed.generator/news', 'News')],
            false,
        ));

        $candidates = $this->importCandidates($network)->forAccount($account);

        $summary = static fn (CollectionCandidateModel $candidate): array => [
            $candidate->privateFeed->kind->value,
            $candidate->privateFeed->name,
            $candidate->state->value,
            $candidate->subscriptionId,
        ];
        self::assertSame(
            [
                ['home', 'Home', 'new', null],
                ['list', 'Artists', 'new', null],
                ['list', 'Writers', 'subscribed', $subscription->requireId()],
            ],
            array_map($summary, $candidates->lists),
        );
        self::assertSame([['custom_feed', 'News', 'new', null]], array_map($summary, $candidates->customFeeds));
    }
```

Merge into the imports: `App\Entity\SocialFeed`, `App\Repository\SocialFeedRepository`, `App\Service\Social\Model\CollectionCandidateModel`, `App\Service\Social\Model\PrivateFeedModel`, `App\Tests\Support\PrivateFeedsWiring`, `Symfony\Component\Clock\MockClock`.

In `backend/tests/Service/Social/SocialImporterTest.php`, add the constant `private const string NEWS = 'at://did:plc:bob/app.bsky.feed.generator/news';` at the top of the class, then append:

```php
    public function testChosenCollectionsBecomePrivateFeedsCountedAsImported(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));

        $result = $this->importer()->import($account, new SocialImportModel([], [], [
            PrivateFeedModel::home(),
            PrivateFeedModel::customFeed(self::NEWS, 'News'),
        ]));

        self::assertSame(2, $result->imported);
        $feed = $this->feedAt(sprintf('social:bluesky:%d:feed:%s', $account->requireId(), self::NEWS));
        self::assertSame(SourceFormat::BLUESKY_API, $feed?->getSourceFormat());
        self::assertSame('News', $feed?->getTitle());
    }

    public function testAnAlreadyAddedCollectionCountsAsAlreadySubscribed(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $this->importer()->import($account, new SocialImportModel([], [], [PrivateFeedModel::home()]));

        $result = $this->importer()->import($account, new SocialImportModel([], [], [PrivateFeedModel::home()]));

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
    }

    public function testACollectionOverTheCapIsSkippedNotFailed(): void
    {
        $this->user->setMaxSubscriptions(0);
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));

        $result = $this->importer()->import($account, new SocialImportModel([], [], [PrivateFeedModel::home()]));

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->skippedOverLimit);
    }

    #[DataProvider('foreignCollections')]
    public function testACollectionItsNetworkCannotHoldRefusesTheWholeImport(
        SocialNetwork $network,
        PrivateFeedModel $collection,
    ): void {
        $account = $this->storedAccount(SocialNetwork::Bluesky === $network
            ? SocialAccounts::blueskyOf($this->user)
            : SocialAccounts::mastodonOf($this->user));

        try {
            $this->importer()->import($account, new SocialImportModel([], [], [PrivateFeedModel::home(), $collection]));
            self::fail('A collection of the wrong shape must refuse the import.');
        } catch (InvalidSocialImportFeedException) {
            self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
        }
    }

    /** @return iterable<string, array{SocialNetwork, PrivateFeedModel}> */
    public static function foreignCollections(): iterable
    {
        yield 'a mastodon list id on bluesky' => [SocialNetwork::Bluesky, PrivateFeedModel::userList('42', 'Friends')];
        yield 'a custom feed on mastodon' => [SocialNetwork::Mastodon, PrivateFeedModel::customFeed(self::NEWS, 'News')];
    }

    private function storedAccount(SocialAccount $account): SocialAccount
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }
```

Merge into the imports: `App\Entity\SocialAccount`, `App\Entity\SocialFeed`, `App\Enum\SocialNetwork`, `App\Enum\SourceFormat`, `App\Service\Social\Model\PrivateFeedModel`, `PHPUnit\Framework\Attributes\DataProvider`.

In `backend/tests/Controller/Api/SocialImportControllerTest.php`:

1. In `testTheCandidatesListFollowsByStateThenHandleBesideListsAndFeeds()`, replace the `lists` and `customFeeds` assertions with:

```php
        self::assertSame(
            [
                ['kind' => 'home', 'id' => null, 'name' => 'Home', 'state' => 'new', 'subscriptionId' => null],
                ['kind' => 'list', 'id' => 'at://list/a', 'name' => 'Artists', 'state' => 'new', 'subscriptionId' => null],
                ['kind' => 'list', 'id' => 'at://list/b', 'name' => 'Writers', 'state' => 'new', 'subscriptionId' => null],
            ],
            $payload['lists'],
        );
        self::assertSame(
            [['kind' => 'custom_feed', 'id' => 'at://feed/a', 'name' => 'Discover', 'state' => 'new', 'subscriptionId' => null]],
            $payload['customFeeds'],
        );
```

2. Append:

```php
    public function testImportAddsTheChosenCollectionsAsPrivateFeeds(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-collections@example.test');
        $account = $this->blueskyAccountOf($user);

        $this->postImport($client, $account, ['collections' => [
            ['kind' => 'home', 'id' => null, 'name' => 'Home'],
            ['kind' => 'list', 'id' => 'at://did:plc:bob/app.bsky.graph.list/3l1', 'name' => 'Writers'],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->payload($client)['imported']);
        self::assertSame(2, $this->entityManager()->getRepository(SocialFeed::class)->count([]));
    }

    public function testImportRefusesACollectionOfTheWrongShape(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-bad-collection@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), ['collections' => [
            ['kind' => 'custom_feed', 'id' => '42', 'name' => 'Not a feed'],
        ]]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_import_invalid_feed', $this->payload($client)['type']);
    }

    public function testImportRefusesAnUnknownCollectionKind(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-unknown-kind@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), ['collections' => [
            ['kind' => 'starter_pack', 'id' => 'x', 'name' => 'Pack'],
        ]]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $this->payload($client)['type']);
    }
```

Add `use App\Entity\SocialFeed;`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Controller/Api/SocialImportControllerTest.php`
Expected: FAIL. `PrivateFeedRules` and `CollectionCandidateModel` are not found, and `ImportCandidates` gets too many constructor arguments.

- [ ] **Step 3: Models and rules**

Create `backend/src/Service/Social/Model/CollectionCandidateModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class CollectionCandidateModel
{
    private function __construct(
        public PrivateFeedModel $privateFeed,
        public ImportCandidateState $state,
        public ?int $subscriptionId,
    ) {
    }

    /** @param array<string, int> $subscriptionIds the account's private-feed subscriptions by PrivateFeedModel::key() */
    public static function of(PrivateFeedModel $privateFeed, array $subscriptionIds): self
    {
        $subscriptionId = $subscriptionIds[$privateFeed->key()] ?? null;

        return new self(
            $privateFeed,
            null === $subscriptionId ? ImportCandidateState::New : ImportCandidateState::Subscribed,
            $subscriptionId,
        );
    }
}
```

Create `backend/src/Service/Social/Model/CollectionImportModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class CollectionImportModel
{
    public function __construct(
        public int $added = 0,
        public int $alreadySubscribed = 0,
        public int $overLimit = 0,
    ) {
    }

    public function counting(PrivateFeedOutcome $outcome): self
    {
        return match ($outcome) {
            PrivateFeedOutcome::Created => new self($this->added + 1, $this->alreadySubscribed, $this->overLimit),
            PrivateFeedOutcome::AlreadySubscribed => new self($this->added, $this->alreadySubscribed + 1, $this->overLimit),
        };
    }

    public function countingOverLimit(): self
    {
        return new self($this->added, $this->alreadySubscribed, $this->overLimit + 1);
    }
}
```

Create `backend/src/Service/Social/Support/PrivateFeedRules.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;

/** Which collections a network can hold, and the shape of each one's id. */
final class PrivateFeedRules
{
    private const string BLUESKY_LIST = '#^at://did:[a-z0-9]+:[A-Za-z0-9._:%-]+/app\.bsky\.graph\.list/[A-Za-z0-9._~:-]{1,512}$#';
    private const string BLUESKY_FEED = '#^at://did:[a-z0-9]+:[A-Za-z0-9._:%-]+/app\.bsky\.feed\.generator/[A-Za-z0-9._~:-]{1,512}$#';
    private const string MASTODON_LIST = '/^\d{1,32}$/';

    public static function allows(SocialNetwork $network, PrivateFeedModel $privateFeed): bool
    {
        return match ($privateFeed->kind) {
            SocialFeedKind::Home => true,
            SocialFeedKind::UserList => self::matches(
                SocialNetwork::Bluesky === $network ? self::BLUESKY_LIST : self::MASTODON_LIST,
                $privateFeed->remoteId,
            ),
            SocialFeedKind::CustomFeed => SocialNetwork::Bluesky === $network
                && self::matches(self::BLUESKY_FEED, $privateFeed->remoteId),
        };
    }

    private static function matches(string $pattern, ?string $remoteId): bool
    {
        return null !== $remoteId && 1 === preg_match($pattern, $remoteId);
    }

    private function __construct()
    {
    }
}
```

In `backend/src/Service/Social/Model/ImportCandidatesModel.php`, change the two docblock lines to `@param list<CollectionCandidateModel> $lists` and `@param list<CollectionCandidateModel> $customFeeds`.

Replace `backend/src/Service/Social/Model/SocialImportModel.php`'s constructor with:

```php
    /**
     * @param list<SocialImportFeedModel> $subscribe
     * @param list<int>                   $unsubscribe subscription ids
     * @param list<PrivateFeedModel>      $collections Home, lists and custom feeds to add as private feeds
     */
    public function __construct(
        public array $subscribe,
        public array $unsubscribe,
        public array $collections = [],
    ) {
    }
```

Replace `SocialImportResultModel::from()` with:

```php
    public static function from(
        BulkSubscribeResultModel $subscribed,
        CollectionImportModel $collections,
        int $removed,
    ): self {
        return new self(
            $subscribed->imported + $collections->added,
            $subscribed->alreadySubscribed + $collections->alreadySubscribed,
            $subscribed->invalid,
            $subscribed->skippedOverLimit + $collections->overLimit,
            $removed,
        );
    }
```

- [ ] **Step 4: Candidates carry the collections' state**

In `backend/src/Service/Social/ImportCandidates.php`:

1. Insert `private SocialFeedRepository $socialFeeds,` into the constructor before `private EntityManagerInterface $entityManager,`.
2. Add the imports `App\Enum\SocialFeedKind`, `App\Repository\SocialFeedRepository`, `App\Service\Social\Model\CollectionCandidateModel`, `App\Service\Social\Model\PrivateFeedModel` and `App\Service\Social\Model\SocialCollectionModel`.
3. Replace `forAccount()` with:

```php
    public function forAccount(SocialAccount $account): ImportCandidatesModel
    {
        $network = $this->networkCandidatesOf($account);
        $follows = $this->followMatcher->match(
            new FollowMatching($account->getNetwork(), $network->follows),
            $this->profileFeedsOf($account),
        );
        $subscribed = $this->privateFeedSubscriptionsOf($account);

        return new ImportCandidatesModel(
            CandidateOrder::follows(self::trustedFollows($network, $follows)),
            [
                CollectionCandidateModel::of(PrivateFeedModel::home(), $subscribed),
                ...self::collections(SocialFeedKind::UserList, $network->lists, $subscribed),
            ],
            self::collections(SocialFeedKind::CustomFeed, $network->customFeeds, $subscribed),
            $network->truncated,
        );
    }
```

4. Add:

```php
    /**
     * @param list<SocialCollectionModel> $collections
     * @param array<string, int>          $subscribed subscription id by PrivateFeedModel::key()
     *
     * @return list<CollectionCandidateModel> by name
     */
    private static function collections(SocialFeedKind $kind, array $collections, array $subscribed): array
    {
        return array_map(
            static fn (SocialCollectionModel $collection): CollectionCandidateModel => CollectionCandidateModel::of(
                PrivateFeedModel::of($kind, $collection->id, $collection->name),
                $subscribed,
            ),
            CandidateOrder::collections($collections),
        );
    }

    /** @return array<string, int> subscription id by PrivateFeedModel::key() */
    private function privateFeedSubscriptionsOf(SocialAccount $account): array
    {
        $subscriptionIdsByFeed = [];
        foreach ($this->socialFeeds->subscriptionsOf($account) as $subscription) {
            $subscriptionIdsByFeed[$subscription->getFeed()->requireId()] = $subscription->requireId();
        }

        $subscribed = [];
        foreach ($this->socialFeeds->findAllOf($account) as $socialFeed) {
            $subscriptionId = $subscriptionIdsByFeed[$socialFeed->getFeed()->requireId()] ?? null;
            if (null !== $subscriptionId) {
                $subscribed[PrivateFeedModel::keyOf($socialFeed->getKind(), $socialFeed->getRemoteId())] = $subscriptionId;
            }
        }

        return $subscribed;
    }
```

- [ ] **Step 5: The importer adds collections**

In `backend/src/Service/Social/SocialImporter.php`:

1. Add `private PrivateFeeds $privateFeeds,` as the last constructor parameter.
2. Add the imports `App\Service\Social\Model\CollectionImportModel`, `App\Service\Social\Model\PrivateFeedModel`, `App\Service\Social\Support\PrivateFeedRules` and `App\Service\Subscription\Exception\SubscriptionLimitReachedException`.
3. Replace `import()` with:

```php
    /**
     * @throws InvalidSocialImportFeedException before anything is written
     * @throws InvalidSelectionException        when a subscription to remove is not the owner's
     */
    public function import(SocialAccount $account, SocialImportModel $import): SocialImportResultModel
    {
        $network = $account->getNetwork();
        $this->assertProfileFeedsOf($network, $import->subscribe);
        self::assertCollectionsOf($network, $import->collections);
        $toRemove = $this->ownedSubscriptions->resolve($account->getUser()->requireId(), $import->unsubscribe);

        // Removals first, so the slots they free count toward the subscription cap.
        return $this->entityManager->wrapInTransaction(
            function () use ($account, $import, $toRemove): SocialImportResultModel {
                $removed = $this->subscriptionService->unsubscribeAll(array_values($toRemove));
                $subscribed = $this->bulkSubscriber->subscribeAll(
                    $account->getUser(),
                    self::items($account->getNetwork(), $import->subscribe),
                );

                return SocialImportResultModel::from(
                    $subscribed,
                    $this->addCollections($account, $import->collections),
                    $removed,
                );
            },
        );
    }
```

4. Add:

```php
    /** @param list<PrivateFeedModel> $collections */
    private static function assertCollectionsOf(SocialNetwork $network, array $collections): void
    {
        foreach ($collections as $collection) {
            if (!PrivateFeedRules::allows($network, $collection)) {
                throw new InvalidSocialImportFeedException(sprintf(
                    '%s is no %s %s.',
                    $collection->remoteId ?? $collection->name,
                    $network->displayName(),
                    str_replace('_', ' ', $collection->kind->value),
                ));
            }
        }
    }

    /**
     * The cap is checked before PrivateFeeds writes anything, so a collection over it is counted, not a failure.
     *
     * @param list<PrivateFeedModel> $collections
     */
    private function addCollections(SocialAccount $account, array $collections): CollectionImportModel
    {
        $added = new CollectionImportModel();
        foreach ($collections as $collection) {
            try {
                $added = $added->counting($this->privateFeeds->create($account, $collection));
            } catch (SubscriptionLimitReachedException) {
                $added = $added->countingOverLimit();
            }
        }

        return $added;
    }
```

- [ ] **Step 6: The request and the JSON**

Create `backend/src/Dto/Social/SocialImportCollectionItem.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Enum\SocialFeedKind;
use App\Service\Social\Model\PrivateFeedModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialImportCollectionItem
{
    public function __construct(
        #[Assert\Choice(choices: ['home', 'list', 'custom_feed'])]
        public string $kind = '',
        #[Assert\Length(max: 512)]
        public ?string $id = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $name = '',
    ) {
    }

    public function toPrivateFeed(): PrivateFeedModel
    {
        $id = trim($this->id ?? '');

        return PrivateFeedModel::of(SocialFeedKind::from($this->kind), '' === $id ? null : $id, trim($this->name));
    }
}
```

In `backend/src/Dto/Social/SocialImportRequest.php`:

1. Add the constant `private const int MAX_COLLECTIONS = 100;` and the import `App\Service\Social\Model\PrivateFeedModel`.
2. Add the third constructor parameter, after `$unsubscribe`:

```php
        #[Assert\Valid]
        #[Assert\Count(max: self::MAX_COLLECTIONS)]
        public array $collections = [],
```

3. Add `@param list<SocialImportCollectionItem> $collections` to the constructor docblock.
4. Change `hasSelection()` to `return [] !== $this->subscribe || [] !== $this->unsubscribe || [] !== $this->collections;` and its message to `'Choose at least one feed, list or custom feed to add, or one to unsubscribe from.'`.
5. In `toImport()`, pass the collections as the third argument:

```php
            array_map(
                static fn (SocialImportCollectionItem $item): PrivateFeedModel => $item->toPrivateFeed(),
                $this->collections,
            ),
```

In `backend/src/Http/SocialImportJson.php`, replace `use App\Service\Social\Model\SocialCollectionModel;` with `use App\Service\Social\Model\CollectionCandidateModel;`, and replace `collection()` with:

```php
    /** @return array{kind: string, id: string|null, name: string, state: string, subscriptionId: int|null} */
    private static function collection(CollectionCandidateModel $candidate): array
    {
        return [
            'kind' => $candidate->privateFeed->kind->value,
            'id' => $candidate->privateFeed->remoteId,
            'name' => $candidate->privateFeed->name,
            'state' => $candidate->state->value,
            'subscriptionId' => $candidate->subscriptionId,
        ];
    }
```

In `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php`, change the `InvalidSocialImportFeedException` arm's title from `'Not a profile feed of this network'` to `'Not importable from this account'`. Code and status stay; the detail names what was wrong.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Controller/Api/SocialImportControllerTest.php tests/Http`
Expected: PASS.

- [ ] **Step 8: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then PhpStorm `lint_files` on the changed PHP files.
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Social backend/src/Dto/Social backend/src/Http/SocialImportJson.php \
  backend/src/Http/Problem/ExceptionProblems/SocialProblems.php backend/tests/Service/Social \
  backend/tests/Controller/Api/SocialImportControllerTest.php
git commit -m "feat(#1599): the import picker's lists, custom feeds and Home become importable private feeds"
```

---
### Task 13: The picker adds lists, custom feeds and Home

**Files:**
- Modify: `frontend/src/app/settings/social/social-import.service.ts`
- Modify: `frontend/src/app/settings/social/social-import-dialog.component.ts`
- Modify: `frontend/src/app/settings/social/social-import-dialog.component.html:84-102`
- Modify: `frontend/public/i18n/en.json:405`, `frontend/public/i18n/de.json:405`
- Test: `frontend/src/app/settings/social/social-import-dialog.component.spec.ts`, `frontend/src/app/settings/social/social-import.service.spec.ts`

**Interfaces:**
- Consumes: Task 12's JSON. Candidates `lists[]` / `customFeeds[]` are `{kind, id, name, state, subscriptionId}`; POST takes `collections: [{kind, id, name}]`.
- Produces (TS):
  - `type CollectionKind = 'home' | 'list' | 'custom_feed'`, `type CollectionState = 'new' | 'subscribed'`
  - `interface CollectionCandidate { kind; id: string | null; name; state: CollectionState; subscriptionId: number | null }`
  - `interface SocialImportCollection { kind; id; name }`
  - `SocialImportSelection.collections: readonly SocialImportCollection[]` (required)
  - `export function collectionKey(collection: CollectionCandidate): string` (`"<kind>:<id or ''>"`), from the dialog file
- i18n keys:
  - `settings.social.importHome`, `settings.social.importAddCollection` (`{{name}}`), `settings.social.importCollectionAdded` (`{{name}}`)
  - `settings.social.importCollectionsLater` is removed.

- [ ] **Step 1: Write the failing specs**

In `frontend/src/app/settings/social/social-import-dialog.component.spec.ts`:

1. Change the import from `./social-import.service` to `import { CollectionCandidate, FollowCandidate, ImportCandidates } from './social-import.service';`.
2. Below the `DAVE` constant, add:

```ts
function collection(overrides: Partial<CollectionCandidate> = {}): CollectionCandidate {
  return { kind: 'list', id: 'at://list/a', name: 'News', state: 'new', subscriptionId: null, ...overrides };
}

const HOME = collection({ kind: 'home', id: null, name: 'Home' });
const DISCOVER = collection({ kind: 'custom_feed', id: 'at://feed/a', name: 'Discover' });
```

3. Inside the `describe`, after `checkboxOf`, add:

```ts
  const collectionBox = (
    fixture: ComponentFixture<SocialImportDialogComponent>,
    key: string,
  ): HTMLInputElement =>
    byTestId(fixture, `social-import-collection-${key}`)?.querySelector(
      'input[type="checkbox"]',
    ) as HTMLInputElement;
```

4. In 'labels each group with its count', change `lists: [{ id: 'at://list/a', name: 'News' }]` to `lists: [collection()]`.
5. Replace the whole test `it('lists lists and custom feeds without a checkbox', …)` with:

```ts
  it('offers lists and custom feeds as checkboxes, the added ones checked and fixed', () => {
    const fixture = loaded(
      candidates({
        lists: [HOME, collection({ state: 'subscribed', subscriptionId: 12 })],
        customFeeds: [DISCOVER],
      }),
    );

    const lists = byTestId(fixture, 'social-import-lists') as HTMLElement;
    expect(lists.textContent).toContain('Home timeline');
    expect(lists.textContent).not.toContain('arrives with private timeline feeds');
    expect(collectionBox(fixture, 'home:').checked).toBe(false);
    expect(collectionBox(fixture, 'list:at://list/a').checked).toBe(true);
    expect(collectionBox(fixture, 'list:at://list/a').disabled).toBe(true);
    expect(collectionBox(fixture, 'list:at://list/a').getAttribute('aria-label')).toBe(
      'Already added: News',
    );
    expect(collectionBox(fixture, 'custom_feed:at://feed/a').getAttribute('aria-label')).toBe(
      'Add Discover',
    );
  });

  it('imports the chosen collections and refreshes the reader', () => {
    const fixture = loaded(candidates({ lists: [HOME], customFeeds: [DISCOVER] }));
    collectionBox(fixture, 'home:').click();
    collectionBox(fixture, 'custom_feed:at://feed/a').click();
    fixture.detectChanges();

    press(fixture, 'social-import-apply');
    const post = http.expectOne(IMPORT);
    expect(post.request.body).toEqual({
      subscribe: [],
      unsubscribe: [],
      collections: [
        { kind: 'home', id: null, name: 'Home' },
        { kind: 'custom_feed', id: 'at://feed/a', name: 'Discover' },
      ],
    });
    post.flush({ imported: 2, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 0 });

    expect(subscriptionsStub.load).toHaveBeenCalled();
    expect(refreshStub.run).toHaveBeenCalled();
  });
```

6. In 'selects every visible new follow at once' and 'imports the chosen follows, unsubscribes the chosen leftovers and reloads the reader', add `collections: [],` to the expected `post.request.body` objects, after `unsubscribe`.

In `frontend/src/app/settings/social/social-import.service.spec.ts`, add `collections: [],` after `unsubscribe: [],` in the `importSelection(4, {…})` call.

- [ ] **Step 2: Run the specs to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/settings/social`
Expected: FAIL. `CollectionCandidate` is not exported, and the collection checkboxes are not found.

- [ ] **Step 3: The service types**

In `frontend/src/app/settings/social/social-import.service.ts`, replace the `SocialCollection` interface with:

```ts
export type CollectionKind = 'home' | 'list' | 'custom_feed';
export type CollectionState = 'new' | 'subscribed';

/** A list, custom feed or the Home timeline the account could read as a private feed. Home has no id. */
export interface CollectionCandidate {
  readonly kind: CollectionKind;
  readonly id: string | null;
  readonly name: string;
  readonly state: CollectionState;
  readonly subscriptionId: number | null;
}

export interface SocialImportCollection {
  readonly kind: CollectionKind;
  readonly id: string | null;
  readonly name: string;
}
```

Change `lists` and `customFeeds` in `ImportCandidates` to `readonly CollectionCandidate[]`, and add `readonly collections: readonly SocialImportCollection[];` to `SocialImportSelection`.

- [ ] **Step 4: The dialog**

In `frontend/src/app/settings/social/social-import-dialog.component.ts`:

1. Change the `./social-import.service` import to `CollectionCandidate, CollectionState, FollowCandidate, ImportCandidateState, SocialImportCollection, SocialImportFeed, SocialImportResult, SocialImportService`.
2. Below `CHECKBOX_KEYS`, add:

```ts
const COLLECTION_CHECKBOX_KEYS: Readonly<Record<CollectionState, string>> = {
  new: 'settings.social.importAddCollection',
  subscribed: 'settings.social.importCollectionAdded',
};

/** Home has no id, and a list and a custom feed could share one, so the kind is part of the key. */
export function collectionKey(collection: CollectionCandidate): string {
  return `${collection.kind}:${collection.id ?? ''}`;
}
```

3. In `CollectionSection`, change `items` to `readonly items: readonly CollectionCandidate[];`.
4. In the class, below `protected readonly checkboxKeys = CHECKBOX_KEYS;`, add:

```ts
  protected readonly collectionCheckboxKeys = COLLECTION_CHECKBOX_KEYS;
  protected readonly collectionKey = collectionKey;
```

below `toUnsubscribe`, add:

```ts
  protected readonly toCollect = signal<ReadonlySet<string>>(new Set());
```

and change `selectedCount` to:

```ts
  protected readonly selectedCount = computed(
    () => this.toSubscribe().size + this.toUnsubscribe().size + this.toCollect().size,
  );
```

5. Replace `apply()` with:

```ts
  protected apply(): void {
    const selection = {
      subscribe: this.chosenFollows(),
      unsubscribe: [...this.toUnsubscribe()],
      collections: this.chosenCollections(),
    };

    this.importer.importSelection(this.data.accountId, selection).subscribe({
      next: (result) => this.finish(result),
      error: () => undefined,
    });
  }

  protected collectionName(collection: CollectionCandidate): string {
    return collection.kind === 'home'
      ? this.i18n.translate('settings.social.importHome')
      : collection.name;
  }

  protected isCollected(collection: CollectionCandidate): boolean {
    return collection.state === 'subscribed' || this.toCollect().has(collectionKey(collection));
  }

  protected toggleCollection(collection: CollectionCandidate): void {
    if (collection.state === 'subscribed') return;
    this.toCollect.update((current) => toggled(current, collectionKey(collection)));
  }
```

6. Add these private methods, and change `collection()`'s `items` parameter type and its filter:

```ts
  private chosenFollows(): SocialImportFeed[] {
    return (this.importer.candidates()?.follows ?? [])
      .filter((follow) => follow.state === 'new' && this.toSubscribe().has(follow.feedUrl))
      .map((follow) => ({ feedUrl: follow.feedUrl, title: follow.displayName }));
  }

  private chosenCollections(): SocialImportCollection[] {
    const candidates = this.importer.candidates();
    return [...(candidates?.lists ?? []), ...(candidates?.customFeeds ?? [])]
      .filter(
        (collection) =>
          collection.state === 'new' && this.toCollect().has(collectionKey(collection)),
      )
      .map(({ kind, id, name }) => ({ kind, id, name }));
  }

  private collection(
    label: string,
    testId: string,
    items: readonly CollectionCandidate[] = [],
  ): CollectionSection {
    const shown = items.filter((item) => this.matches(this.collectionName(item)));
    return { labelKey: pluralKey(label, shown.length), testId, items: shown };
  }
```

`finish()` already reloads subscriptions and runs a refresh when `result.imported > 0`; collections count into `imported` (Task 12).

In `frontend/src/app/settings/social/social-import-dialog.component.html`, replace the `@for (section of collections(); …) { … }` block with:

```html
    @for (section of collections(); track section.testId) {
      <app-disclosure
        appearance="row"
        [attr.data-testid]="section.testId"
        [label]="section.labelKey | transloco: { count: section.items.length }"
      >
        <ul class="rows">
          @for (item of section.items; track collectionKey(item)) {
            <li>
              <label
                class="row"
                [attr.data-testid]="'social-import-collection-' + collectionKey(item)"
              >
                <input
                  type="checkbox"
                  [checked]="isCollected(item)"
                  [disabled]="item.state === 'subscribed'"
                  [attr.aria-label]="
                    collectionCheckboxKeys[item.state] | transloco: { name: collectionName(item) }
                  "
                  (change)="toggleCollection(item)"
                />
                <span class="who">
                  <span class="handle">{{ collectionName(item) }}</span>
                </span>
                <span class="state" [class.is-new]="item.state === 'new'">
                  {{ stateKeys[item.state] | transloco }}
                </span>
              </label>
            </li>
          } @empty {
            <li class="empty">{{ 'settings.social.importEmpty' | transloco }}</li>
          }
        </ul>
      </app-disclosure>
    }
```

- [ ] **Step 5: Translations**

In `frontend/public/i18n/en.json`, replace line 405 (`"importCollectionsLater": …,`) with:

```json
      "importHome": "Home timeline",
      "importAddCollection": "Add {{name}}",
      "importCollectionAdded": "Already added: {{name}}",
```

In `frontend/public/i18n/de.json`, replace line 405 (`"importCollectionsLater": …,`) with:

```json
      "importHome": "Home-Timeline",
      "importAddCollection": "{{name}} hinzufügen",
      "importCollectionAdded": "Bereits hinzugefügt: {{name}}",
```

Then run `grep -rn "importCollectionsLater" frontend/src`. Expected: no output.

- [ ] **Step 6: Run the specs to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/settings/social`
Expected: PASS.

- [ ] **Step 7: Gate**

Run: `docker compose exec -T frontend npm run check`
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/settings/social frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1599): the import picker adds lists, custom feeds and the Home timeline"
```

---

### Task 14: Bluesky and Mastodon sidebar sections, and the final verification

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Create: `frontend/src/app/reader/state/social-sections.ts`, `frontend/src/app/reader/state/social-sections.spec.ts`
- Modify: `frontend/src/app/reader/state/subscriptions.store.ts`, `frontend/src/app/reader/state/subscriptions.store.spec.ts`
- Create: `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/shell/sidebar/_sidebar-row.scss`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-feed-row.component.{ts,html}`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html:76-77`
- Modify: `frontend/src/app/reader/testing/subscription.factory.ts`, `frontend/src/app/reader/feeds/manage/edit-subscription-dialog.component.spec.ts:10-35`
- Modify: `frontend/src/app/settings/social/social-accounts.service.ts`, `social-accounts.service.spec.ts`, `social-section.component.spec.ts`
- Modify: `frontend/public/i18n/en.json:1114`, `frontend/public/i18n/de.json:1114`

**Interfaces:**
- Consumes: Task 11's JSON (`social`, `socialAccounts`).
- Produces (TS):
  - `type SocialNetworkName = 'bluesky' | 'mastodon'`
  - `interface SocialFeedRefDto { network: SocialNetworkName; accountId: number; kind: 'home' | 'list' | 'custom_feed' }`
  - `interface SocialAccountRefDto { id: number; network: SocialNetworkName; handle: string }`
  - `SubscriptionDto.social: SocialFeedRefDto | null`, `SubscriptionsResponse.socialAccounts: SocialAccountRefDto[]`
  - `isPrivateFeed(subscription): boolean`
  - `buildSocialSections(subscriptions, accounts): SocialSection[]` with `SocialSection { network; accounts: SocialAccountGroup[] }` and `SocialAccountGroup { accountId; handle: string | null; subscriptions }`
  - `SubscriptionsStore.socialSections` (computed) and `socialAccounts` (signal)
  - `SidebarComponent` input `socialSections`
  - `SidebarFeedRowComponent` input `label: string | null`
  - SCSS mixin `row.section-head`
- i18n keys: `reader.socialHome`, `reader.toggleSocialSection` (`{{network}}`).

- [ ] **Step 1: Write the failing specs**

Create `frontend/src/app/reader/state/social-sections.spec.ts`:

```ts
import { SubscriptionDto } from '../models';
import { makeSubscription } from '../testing/subscription.factory';
import { buildSocialSections, isPrivateFeed } from './social-sections';

const feed = (
  id: number,
  network: 'bluesky' | 'mastodon',
  accountId: number,
  kind: 'home' | 'list' | 'custom_feed',
  title = `Feed ${id}`,
): SubscriptionDto => makeSubscription({ id, title, social: { network, accountId, kind } });

describe('buildSocialSections', () => {
  it('builds one section per network holding a private feed, Bluesky first', () => {
    const sections = buildSocialSections(
      [feed(1, 'mastodon', 8, 'home'), feed(2, 'bluesky', 3, 'home'), makeSubscription({ id: 3 })],
      [],
    );

    expect(sections.map((section) => section.network)).toEqual(['bluesky', 'mastodon']);
  });

  it('leaves out a network without private feeds', () => {
    expect(buildSocialSections([makeSubscription()], [])).toEqual([]);
  });

  it("orders accounts by id and each account's feeds Home, lists, custom feeds, then by title", () => {
    const sections = buildSocialSections(
      [
        feed(1, 'bluesky', 9, 'home'),
        feed(2, 'bluesky', 3, 'custom_feed', 'Art'),
        feed(3, 'bluesky', 3, 'list', 'Writers'),
        feed(4, 'bluesky', 3, 'list', 'Artists'),
        feed(5, 'bluesky', 3, 'home'),
      ],
      [{ id: 3, network: 'bluesky', handle: '@alice.bsky.social' }],
    );

    expect(
      sections[0].accounts.map((group) => [
        group.accountId,
        group.handle,
        group.subscriptions.map((subscription) => subscription.id),
      ]),
    ).toEqual([
      [3, '@alice.bsky.social', [5, 4, 3, 2]],
      [9, null, [1]],
    ]);
  });

  it('tells a private feed from an ordinary one', () => {
    expect(isPrivateFeed(feed(1, 'bluesky', 3, 'home'))).toBe(true);
    expect(isPrivateFeed(makeSubscription())).toBe(false);
  });
});
```

Create `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.spec.ts`:

```ts
import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { API_BASE_URL } from '../../../core/api';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { LayoutService } from '../../layout.service';
import { SubscriptionDto } from '../../models';
import { Selection } from '../../query/query';
import { SocialSection } from '../../state/social-sections';
import { makeSubscription } from '../../testing/subscription.factory';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarSocialSectionComponent } from './sidebar-social-section.component';

const feed = (
  id: number,
  kind: 'home' | 'list',
  title: string,
  over: Partial<SubscriptionDto> = {},
): SubscriptionDto =>
  makeSubscription({ id, title, unreadCount: id, social: { network: 'bluesky', accountId: 3, kind }, ...over });

const ONE_ACCOUNT: SocialSection = {
  network: 'bluesky',
  accounts: [
    {
      accountId: 3,
      handle: '@alice.bsky.social',
      subscriptions: [feed(1, 'home', 'Timeline'), feed(2, 'list', 'Writers')],
    },
  ],
};

function mount(section: SocialSection): ComponentFixture<SidebarSocialSectionComponent> {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [SidebarSocialSectionComponent, provideTranslocoTesting()],
    providers: [
      provideRouter([{ path: '**', children: [] }]),
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      { provide: LayoutService, useValue: { isCoarse: signal(false), isNarrow: signal(false) } },
      { provide: ManageActions, useValue: {} },
      {
        provide: SidebarRowActions,
        useValue: {
          menuFor: signal<string | null>(null),
          toggleMenu: jest.fn(),
          closeMenu: jest.fn(),
          openFeedSheet: jest.fn(),
          exclusionTitle: () => 'Not in All items',
        },
      },
    ],
  });
  const fixture = TestBed.createComponent(SidebarSocialSectionComponent);
  fixture.componentRef.setInput('section', section);
  fixture.componentRef.setInput('selection', { kind: 'all', id: null, unread: true } as Selection);
  fixture.detectChanges();
  return fixture;
}

const element = (fixture: ComponentFixture<SidebarSocialSectionComponent>): HTMLElement =>
  fixture.nativeElement as HTMLElement;

describe('SidebarSocialSectionComponent', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  it('titles the section by its network and names an unrenamed Home through i18n', () => {
    const root = element(mount(ONE_ACCOUNT));

    expect(root.querySelector('.section-toggle')?.textContent).toContain('Bluesky');
    const rows = [...root.querySelectorAll('app-sidebar-feed-row')].map((row) => row.textContent ?? '');
    expect(rows[0]).toContain('Home');
    expect(rows[0]).not.toContain('Timeline');
    expect(rows[1]).toContain('Writers');
    expect(root.querySelector('.account-handle')).toBeNull();
  });

  it('keeps a renamed Home under the name it was given', () => {
    const renamed: SocialSection = {
      network: 'bluesky',
      accounts: [
        {
          accountId: 3,
          handle: null,
          subscriptions: [feed(1, 'home', 'Main', { customTitle: 'Main' })],
        },
      ],
    };

    expect(element(mount(renamed)).querySelector('app-sidebar-feed-row')?.textContent).toContain('Main');
  });

  it('heads each account with its handle when the network has several', () => {
    const twoAccounts: SocialSection = {
      network: 'bluesky',
      accounts: [
        { accountId: 3, handle: '@alice.bsky.social', subscriptions: [feed(1, 'home', 'Home')] },
        {
          accountId: 4,
          handle: '@work.bsky.social',
          subscriptions: [feed(2, 'home', 'Home', { social: { network: 'bluesky', accountId: 4, kind: 'home' } })],
        },
      ],
    };

    const handles = [...element(mount(twoAccounts)).querySelectorAll('.account-handle')].map(
      (handle) => handle.textContent?.trim(),
    );
    expect(handles).toEqual(['@alice.bsky.social', '@work.bsky.social']);
  });

  it("shows each feed's unread count", () => {
    const counts = [...element(mount(ONE_ACCOUNT)).querySelectorAll('.count')].map((count) =>
      count.textContent?.trim(),
    );

    expect(counts).toEqual(['1', '2']);
  });

  it('collapses and remembers it for its own network only', () => {
    const fixture = mount(ONE_ACCOUNT);

    (element(fixture).querySelector('.section-toggle') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(element(fixture).querySelectorAll('app-sidebar-feed-row').length).toBe(0);
    expect(localStorage.getItem('sfr.bluesky.collapsed')).toBe('true');
    expect(localStorage.getItem('sfr.mastodon.collapsed')).toBeNull();
    expect(element(mount(ONE_ACCOUNT)).querySelectorAll('app-sidebar-feed-row').length).toBe(0);
  });
});
```

In `frontend/src/app/reader/state/subscriptions.store.spec.ts`:
- Add `social: null,` after `includeInForYou: true,` in the `sub()` factory.
- Inside `describe('SubscriptionsStore', …)`, append:

```ts
  it('keeps private feeds out of the tag tree and the untagged list, and groups them by network', () => {
    store.load();
    const home: SubscriptionDto = {
      ...sub(5, 2, [tag(20, 'Tech')]),
      includeInAllItems: false,
      social: { network: 'bluesky', accountId: 3, kind: 'home' },
    };
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      ...counts([sub(1, 1, []), home]),
      socialAccounts: [{ id: 3, network: 'bluesky', handle: '@alice.bsky.social' }],
    });

    expect(store.untagged().map((subscription) => subscription.id)).toEqual([1]);
    expect(store.tagTree()).toEqual([]);
    expect(store.socialSections()).toEqual([
      {
        network: 'bluesky',
        accounts: [{ accountId: 3, handle: '@alice.bsky.social', subscriptions: [home] }],
      },
    ]);
    expect(store.totalUnread()).toBe(1);
  });
```

In `frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts`:
- Add `social: null,` after `includeInForYou: true,` in the `sub()` factory.
- Import `SocialSection` from `'../../state/social-sections'`.
- Add `socialSections: [] as SocialSection[],` to `inputDefaults()` and `socialSections: SocialSection[];` to `mount()`'s `Partial<{…}>` type.
- Append inside the top-level `describe`:

```ts
  describe('social sections', () => {
    const section: SocialSection = {
      network: 'bluesky',
      accounts: [
        {
          accountId: 3,
          handle: '@alice.bsky.social',
          subscriptions: [
            { ...sub(9, 2), title: 'Home', social: { network: 'bluesky', accountId: 3, kind: 'home' } },
          ],
        },
      ],
    };
    const node: TagNode = {
      tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
      subscriptions: [],
      unreadCount: 0,
      entryCount: 0,
    };

    beforeEach(() => localStorage.clear());
    afterEach(() => localStorage.clear());

    it('sit between the saved searches and the tags', () => {
      const element = mount({ tagTree: [node], socialSections: [section] }).nativeElement as HTMLElement;
      const saved = element.querySelector('app-sidebar-saved-searches') as Element;
      const social = element.querySelector('app-sidebar-social-section') as Element;
      const tags = element.querySelector('.tags-head') as Element;

      expect(saved.compareDocumentPosition(social) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      expect(social.compareDocumentPosition(tags) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('step aside while organising', () => {
      const element = mount({ organising: true, coarse: true, socialSections: [section] })
        .nativeElement as HTMLElement;

      expect(element.querySelector('app-sidebar-social-section')).toBeNull();
    });
  });
```

In `frontend/src/app/settings/social/social-accounts.service.spec.ts`:
- Import `SubscriptionsStore` from `'../../reader/state/subscriptions.store'`.
- Add `const subscriptionsStub = { load: jest.fn() };` above `describe`.
- Add `{ provide: SubscriptionsStore, useValue: subscriptionsStub },` to the providers, and `subscriptionsStub.load.mockReset();` as the first line of `beforeEach`.
- Append:

```ts
  it('reloads the reader once a connect has created the Home feed', () => {
    loadAccounts([]);

    service.connectBluesky('alice.bsky.social', 'abcd-efgh-ijkl-mnop').subscribe();
    http.expectOne(`${ENDPOINT}/bluesky`).flush(account());

    expect(subscriptionsStub.load).toHaveBeenCalledTimes(1);
  });

  it('reloads the reader once a disconnect has removed the private feeds', () => {
    loadAccounts([account()]);

    service.disconnect(1);
    expect(subscriptionsStub.load).not.toHaveBeenCalled();
    http.expectOne(`${ENDPOINT}/1`).flush({ accounts: [] });

    expect(subscriptionsStub.load).toHaveBeenCalledTimes(1);
  });
```

In `frontend/src/app/settings/social/social-section.component.spec.ts`:
- Import `SubscriptionsStore` from `'../../reader/state/subscriptions.store'`.
- Add `{ provide: SubscriptionsStore, useValue: { load: jest.fn() } },` to the providers in `mount()`.

- [ ] **Step 2: Run the specs to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/state src/app/reader/shell/sidebar src/app/settings/social`
Expected: FAIL. `./social-sections` and `./sidebar-social-section.component` cannot be resolved; `social` is not a known `SubscriptionDto` property.

- [ ] **Step 3: Models and fixtures**

In `frontend/src/app/reader/models.ts`, above `export interface SubscriptionDto`, add:

```ts
export type SocialNetworkName = 'bluesky' | 'mastodon';

/** Where a private feed comes from: the network, the connected account it is read through, which of its feeds. */
export interface SocialFeedRefDto {
  network: SocialNetworkName;
  accountId: number;
  kind: 'home' | 'list' | 'custom_feed';
}

/** A connected account, named once per subscriptions answer for the sidebar's account labels. */
export interface SocialAccountRefDto {
  id: number;
  network: SocialNetworkName;
  handle: string;
}
```

In `SubscriptionDto`, after `includeInForYou: boolean;`, add:

```ts
  /** Set for a private timeline, list or custom feed of a connected account (#1599); null for any other feed. */
  social: SocialFeedRefDto | null;
```

In `SubscriptionsResponse`, after `viewedCount: number;`, add `socialAccounts: SocialAccountRefDto[];`.

Add `social: null,` after `includeInForYou: true,` in:
- `frontend/src/app/reader/testing/subscription.factory.ts`;
- the `sub` literal at the top of `frontend/src/app/reader/feeds/manage/edit-subscription-dialog.component.spec.ts` (lines 10–35).

- [ ] **Step 4: The section builder and the store**

Create `frontend/src/app/reader/state/social-sections.ts`:

```ts
import { SocialAccountRefDto, SocialNetworkName, SubscriptionDto } from '../models';

export interface SocialAccountGroup {
  readonly accountId: number;
  /** null when the answer named no such account, e.g. one disconnected since the list loaded. */
  readonly handle: string | null;
  readonly subscriptions: readonly SubscriptionDto[];
}

export interface SocialSection {
  readonly network: SocialNetworkName;
  readonly accounts: readonly SocialAccountGroup[];
}

const NETWORKS: readonly SocialNetworkName[] = ['bluesky', 'mastodon'];

const KIND_ORDER: Readonly<Record<'home' | 'list' | 'custom_feed', number>> = {
  home: 0,
  list: 1,
  custom_feed: 2,
};

export function isPrivateFeed(subscription: SubscriptionDto): boolean {
  return Boolean(subscription.social);
}

/** One section per network that holds a private feed; its accounts by id; each account's Home first. */
export function buildSocialSections(
  subscriptions: readonly SubscriptionDto[],
  accounts: readonly SocialAccountRefDto[],
): SocialSection[] {
  const handles = new Map(accounts.map((account) => [account.id, account.handle]));
  return NETWORKS.map((network) => ({
    network,
    accounts: accountGroups(
      subscriptions.filter((subscription) => subscription.social?.network === network),
      handles,
    ),
  })).filter((section) => section.accounts.length > 0);
}

function accountGroups(
  subscriptions: readonly SubscriptionDto[],
  handles: ReadonlyMap<number, string>,
): SocialAccountGroup[] {
  const byAccount = new Map<number, SubscriptionDto[]>();
  for (const subscription of subscriptions) {
    const accountId = subscription.social?.accountId ?? 0;
    byAccount.set(accountId, [...(byAccount.get(accountId) ?? []), subscription]);
  }
  return [...byAccount.entries()]
    .sort(([left], [right]) => left - right)
    .map(([accountId, feeds]) => ({
      accountId,
      handle: handles.get(accountId) ?? null,
      subscriptions: [...feeds].sort(byKindThenTitle),
    }));
}

function byKindThenTitle(left: SubscriptionDto, right: SubscriptionDto): number {
  return kindRank(left) - kindRank(right) || left.title.localeCompare(right.title);
}

function kindRank(subscription: SubscriptionDto): number {
  return KIND_ORDER[subscription.social?.kind ?? 'home'];
}
```

In `frontend/src/app/reader/state/subscriptions.store.ts`:
1. Import `SocialAccountRefDto` with the other `../models` types, and `{ buildSocialSections, isPrivateFeed }` from `'./social-sections'`.
2. Add `readonly socialAccounts = signal<SocialAccountRefDto[]>([]);` below `readonly viewedCount = signal(0);`.
3. Replace the `tagTree` and `untagged` computeds with:

```ts
  /** Private feeds have their own sidebar sections, so the tag tree and the Feeds list leave them out. */
  private readonly publicSubscriptions = computed(() =>
    this.subscriptions().filter((subscription) => !isPrivateFeed(subscription)),
  );

  readonly tagTree = computed(() => buildTagTree(this.publicSubscriptions(), this.tags.tags()));
  readonly untagged = computed(() => untaggedSubscriptions(this.publicSubscriptions()));
  readonly socialSections = computed(() =>
    buildSocialSections(this.subscriptions(), this.socialAccounts()),
  );
```

4. In `applyCounts()`, add `this.socialAccounts.set(response.socialAccounts ?? []);`.
5. In `invalidate()`, add `this.socialAccounts.set([]);`.

The `?? []` covers answers recorded before this field existed, such as the stubbed bodies in the shell and e2e specs.

`totalUnread`/`totalEntries` keep summing all subscriptions: `sumUnread` already skips `includeInAllItems: false`.

- [ ] **Step 5: A shared section-head mixin**

In `frontend/src/app/reader/shell/sidebar/_sidebar-row.scss`, append:

```scss
/* Collapsible section headers — Tags, Feeds (#612) and the social networks (#1599). The row reproduces
   `.label`'s vertical margins, since inside the toggle button `.label` is inline and its block margins no
   longer apply; the horizontal margin stays. */
@mixin section-head {
  .label {
    font-size: var(--fs-sm);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
    margin: var(--space-3) var(--space-2) var(--space-1);
  }

  .section-head {
    display: flex;
    align-items: center;
    margin-block: var(--space-3) var(--space-1);
  }

  /* A `<button>` standing in for the plain `.label` text: reset it and let it absorb the row's spare width so
     the whole title toggles. */
  .section-toggle {
    flex: 1;
    min-width: 0;
    padding: 0;
    background: none;
    border: none;
    font: inherit;
    text-align: left;
    cursor: pointer;
  }

  .section-toggle .label {
    margin-block: 0;
  }

  /* A borderless chevron in the title's muted colour: `chevron_right` collapsed, `expand_more` open. It keeps
     the same button margin as `.chevzone`. */
  .section-chevron {
    flex: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    margin: var(--space-1);
  }

  /* The glyph carries a `.chevzone` glyph's box metrics, its border transparent, so its centre lines up with
     the tag and saved-search chevrons. */
  .section-chevron app-icon {
    display: inline-flex;
    padding: var(--space-2);
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
  }

  @media (pointer: coarse) {
    .section-chevron {
      width: var(--tap-target);
      height: var(--tap-target);
      margin: 0;
    }
  }
}
```

In `frontend/src/app/reader/shell/sidebar/sidebar.component.scss`:
1. Delete the rules from `.label {` (line 84) through the `@media (pointer: coarse) { .section-chevron { … } }` block (line 151), including the comments between them.
2. Add `@include row.section-head;` below `@include row.row-menu;`.

- [ ] **Step 6: The section component**

Create `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.ts`:

```ts
import { Component, computed, input, linkedSignal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SocialNetworkName, SubscriptionDto } from '../../models';
import { Selection } from '../../query/query';
import { SocialSection } from '../../state/social-sections';
import { SidebarFeedRowComponent } from './sidebar-feed-row.component';

const collapsedKey = (network: SocialNetworkName): string => `sfr.${network}.collapsed`;

/** One network's private feeds, collapsible and remembered like Tags; a handle heads each of several accounts. */
@Component({
  selector: 'app-sidebar-social-section',
  imports: [TranslocoPipe, IconComponent, SidebarFeedRowComponent],
  templateUrl: './sidebar-social-section.component.html',
  styleUrl: './sidebar-social-section.component.scss',
})
export class SidebarSocialSectionComponent {
  readonly section = input.required<SocialSection>();
  readonly selection = input.required<Selection>();

  protected readonly expanded = linkedSignal(
    () => localStorage.getItem(collapsedKey(this.section().network)) !== 'true',
  );
  protected readonly showsHandles = computed(() => this.section().accounts.length > 1);

  protected toggle(): void {
    const open = !this.expanded();
    this.expanded.set(open);
    localStorage.setItem(collapsedKey(this.section().network), String(!open));
  }

  protected isUnrenamedHome(subscription: SubscriptionDto): boolean {
    return subscription.social?.kind === 'home' && subscription.customTitle === null;
  }
}
```

Create `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.html`:

```html
@let current = section();
<div class="section-head" [attr.data-testid]="'sidebar-social-' + current.network">
  <button
    class="section-toggle"
    type="button"
    [attr.aria-expanded]="expanded()"
    (click)="toggle()"
  >
    <span class="label">{{ 'settings.social.' + current.network | transloco }}</span>
  </button>
  <button
    class="section-chevron"
    type="button"
    [attr.aria-expanded]="expanded()"
    [attr.aria-label]="
      'reader.toggleSocialSection'
        | transloco: { network: ('settings.social.' + current.network | transloco) }
    "
    (click)="toggle()"
  >
    <app-icon [name]="expanded() ? 'expand_more' : 'chevron_right'" size="sm" />
  </button>
</div>
@if (expanded()) {
  @for (group of current.accounts; track group.accountId) {
    @if (showsHandles()) {
      <p class="account-handle">{{ group.handle ?? '' }}</p>
    }
    @for (subscription of group.subscriptions; track subscription.id) {
      <div class="feedrow">
        <app-sidebar-feed-row
          [subscription]="subscription"
          [selection]="selection()"
          [label]="isUnrenamedHome(subscription) ? ('reader.socialHome' | transloco) : null"
        />
      </div>
    }
  }
}
```

Create `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.scss`:

```scss
@use 'sidebar-row' as row;
@include row.section-head;

:host {
  display: contents;
}

.feedrow {
  display: flex;
  align-items: center;
}

.account-handle {
  margin: var(--space-2) var(--space-2) var(--space-1);
  overflow: hidden;
  color: var(--text-muted);
  font-size: var(--fs-xs);
  white-space: nowrap;
  text-overflow: ellipsis;
}
```

- [ ] **Step 7: The feed row's label, the sidebar and the shell**

In `frontend/src/app/reader/shell/sidebar/sidebar-feed-row.component.ts`, below `readonly tagId = …;`, add:

```ts
  /** Shown instead of the feed's title: the social section names an unrenamed Home timeline through i18n. */
  readonly label = input<string | null>(null);
```

In `sidebar-feed-row.component.html`, replace both occurrences of `<span>{{ feed.title }}</span>` with `<span>{{ label() ?? feed.title }}</span>`. The `aria-label`s keep `feed.title`.

In `frontend/src/app/reader/shell/sidebar/sidebar.component.ts`:
1. Import `SidebarSocialSectionComponent` and add it to `imports`.
2. Import `SocialSection` from `'../../state/social-sections'`.
3. Below `readonly untagged = input.required<SubscriptionDto[]>();`, add `readonly socialSections = input<SocialSection[]>([]);`.

In `frontend/src/app/reader/shell/sidebar/sidebar.component.html`, directly after the closing `/>` of `<app-sidebar-saved-searches … />`, add:

```html
  @if (!organising()) {
    @for (section of socialSections(); track section.network) {
      <app-sidebar-social-section [section]="section" [selection]="selection()" />
    }
  }
```

In `frontend/src/app/reader/shell/reader-shell.component.html`, add `[socialSections]="subs.socialSections()"` after `[untagged]="subs.untagged()"`.

- [ ] **Step 8: Settings reloads the reader when private feeds change**

In `frontend/src/app/settings/social/social-accounts.service.ts`:

1. Import `SubscriptionsStore` from `'../../reader/state/subscriptions.store'` and add `private readonly subscriptions = inject(SubscriptionsStore);`.
2. Replace `makeDefault()`, `disconnect()`, `upserting()` and `replacingList()` with:

```ts
  makeDefault(id: number): void {
    this.replacingList(this.http.put<SocialAccountList>(`${this.endpoint}/${id}/default`, {})).subscribe(
      { error: () => undefined },
    );
  }

  /** Disconnecting removes the account's private feeds, so the reader reloads once the account is gone. */
  disconnect(id: number): void {
    this.replacingList(this.http.delete<SocialAccountList>(`${this.endpoint}/${id}`)).subscribe({
      next: () => this.subscriptions.load(),
      error: () => undefined,
    });
  }

  /** A connect creates the Home feed and a reconnect resumes the private feeds: the reader reloads either way. */
  private upserting(request: Observable<SocialAccount>): Observable<SocialAccount> {
    this.begin();
    return request.pipe(
      tap({
        next: (account) => {
          this.accounts.update((current) => this.withAccount(current ?? [], account));
          this.subscriptions.load();
        },
        error: (error: HttpErrorResponse) => this.fail(error),
      }),
      finalize(() => this.busy.set(false)),
    );
  }

  private replacingList(request: Observable<SocialAccountList>): Observable<SocialAccountList> {
    this.begin();
    return request.pipe(
      tap({
        next: (list) => this.accounts.set(list.accounts),
        error: (error: HttpErrorResponse) => this.fail(error),
      }),
      finalize(() => this.busy.set(false)),
    );
  }
```

- [ ] **Step 9: Translations**

In `frontend/public/i18n/en.json`, after line 1114 (`"toggleFeeds": "Toggle feeds",`), add:

```json
    "toggleSocialSection": "Toggle {{network}}",
    "socialHome": "Home",
```

In `frontend/public/i18n/de.json`, after line 1114 (`"toggleFeeds": "Feeds ein-/ausklappen",`), add:

```json
    "toggleSocialSection": "{{network}} ein-/ausklappen",
    "socialHome": "Startseite",
```

- [ ] **Step 10: Run the specs to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader src/app/settings`
Expected: PASS.

- [ ] **Step 11: Gate**

Run: `docker compose exec -T frontend npm run check`
Expected: clean. It type-checks the app, spec and e2e projects. A `SubscriptionDto` literal anywhere still lacking `social` fails `tsc`, naming the file; add `social: null` there. The e2e stubs flush untyped bodies and need nothing.

- [ ] **Step 12: Commit**

```bash
git add frontend/src/app/reader frontend/src/app/settings/social frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1599): Bluesky and Mastodon sections in the sidebar, private feeds out of the tag tree"
```

- [ ] **Step 13: Final verification — both database legs, mutation gate, dev log**

The PR description must say: an account whose private feeds were all removed shows no sidebar section, because the sections are derived from subscriptions (ruling 7).

Run from the repo root:

```bash
cd backend && composer test:parallel
docker compose exec php composer test
cd backend && git fetch origin develop && composer infection:diff
docker compose exec -T frontend npm run check
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name == "ERROR" or .level_name == "WARNING" or (.message | test("eprecat")))'
```

Expected:
- both suites green;
- `infection:diff` at or above `minMsi`; an escaped mutant gets a killing test, never a lowered threshold;
- `npm run check` clean;
- no new error or deprecation in the dev log.

Then connect a real Bluesky and a real Mastodon account against the Docker stack and run `docker compose exec php bin/console app:refresh-feeds` (the command `Command/RefreshFeedsCommand.php` registers; `bin/console list app` names it). Check the dev log for `Feed refresh failed: social:` lines. Then look at the sidebar on :4200: both sections, the Home row labelled, the counts. Fix anything found in a `fix(#1599): …` commit.

---

## Self-review

- **Spec coverage:**

  | Brief ruling | Task |
  |---|---|
  | 1 Model | 1, 2 |
  | 2 Creation/removal | 2, 3 |
  | 3 Fetching, sessions, errors, floor, AppView | 4, 5, 6, 8, 9 |
  | 4 Mapping | 7 |
  | Mastodon card ruling, both cases tested | 5 (queue), 7 (mapper) |
  | 5 Exclusions | 10 |
  | 6 API | 11, 12 |
  | 7 Frontend | 13, 14 |
  | 8 Docs | 1 (`backup.md`), 6 and 10 (`security.md`); `architecture.md` checked, no change |

  User deletion is pinned in Task 3. A reader-side unsubscribe takes the normal orphan path; Task 2's `removeAllOf` test exercises that path.
- **Placeholders:** none. Every code step carries the code. The one instruction found by error (Task 14 Step 11, "a literal `tsc` names") is bounded: add `social: null`.
- **Type consistency:**

  | Name | Defined | Used |
  |---|---|---|
  | `PrivateFeedModel::key()/keyOf()` | 2 | 12 |
  | `KnownSharesModel::blueskyPostFor/linkCardFor` | 5 | 7 |
  | `TimelinePageModel::$newestId` | 7 | 9 |
  | `SocialFeed::recordNewestSeen()` | 1 | 9 |
  | `FeedOutcomePersister::storeParsed(Feed, IncomingFeedModel, \DateTimeImmutable)` | 5 | 9 |
  | `RefreshRunners::readingTimelinesFrom()` | 9 | 9 |
  | `collectionKey()` | 13 | 13 |
  | `SocialSection` | 14 | 14 |
