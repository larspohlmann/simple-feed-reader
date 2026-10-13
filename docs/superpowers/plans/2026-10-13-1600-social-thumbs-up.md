# Social thumbs up (#1600) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A thumbs-up toggle in the entry action bar likes a Bluesky or Mastodon post at its origin, through the user's own account, and remembers the like per user so it can be undone.

**Architecture:** The like is the first *post interaction*, so it is built on layers a later reply reuses unchanged:

1. `Support/SocialPostAddress` turns an `Entry` into a `SocialPostAddressModel` (network + at-uri or status URL).
2. `ActingAccountChooser` picks the account that acts on the entry.
3. `ActingAccountGuard` refuses an account that needs reconnecting and marks one whose credential the network refuses.
4. A per-network `PostResolver/PostResolverInterface` turns the address into a `SocialPostModel` on that account. Bluesky gives uri + cid, Mastodon a local status id.
5. `SocialPostFinder` composes steps 1–4.
6. A per-network `PostLiker/PostLikerInterface` acts on the `SocialPostModel`.
7. `SocialLikes` stores or removes the `SocialLike` row.

Every remote call runs through one authenticated account API per network. On Bluesky this is `BlueskySessions` (`askAs` and the new `postAs`). On Mastodon it is the new `MastodonAccountApi`, which `MastodonTimelineSource` now shares. All of these go through `GuardedApiClient`. The entry list's enricher adds `social: {network, isLiked}` per row in two batch queries. A thin `EntryLikeController` serves `PUT`/`DELETE /api/entries/{id}/like`. The frontend adds a `thumb_up` flag toggle to `app-entry-actions`. The toggle is optimistic and guarded against list reloads the way Favourite is. A failure reverts it and shows a toast.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM 3 / DBAL 4, symfony/http-client (`MockHttpClient` via `RoutedHttpClient` in tests), symfony/clock (`MockClock`); Angular 20 (standalone, signals), Transloco, Jest.

**Spec:** GitHub #1600 and the "Thumbs up" part of #1596, plus the binding design brief `/private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/77cc5a7c-2cd4-4b0d-af91-43df61f63320/scratchpad/1600-design-brief.md` (rulings 1–13). Global Constraints restates them, so this plan stands alone.

## Deviations from the brief

1. **`PostLikerInterface::like(SocialPostModel $post): SocialLikeResultModel`, not `like(SocialAccount, Entry): string`.** Rulings 12 and 13 replace ruling 7's signature. The liker acts on the resolved post, which carries its acting account. It returns a result model (today only `likeId`), so a fresh like count can join it later without changing the interface. `unlike(SocialAccount $account, string $likeId): void` is as briefed.
2. **A `SocialPostFinder` sits between the orchestrator and the resolvers.** It is the "address → account → resolve" half of ruling 12's composition. A reply calls it unchanged, so `SocialLikes` only stores rows and calls the liker.
3. **The problems of ruling 8 go to a new `SocialInteractionProblems` mapper, not to `SocialProblems`.** Its arms are not-a-post, no account, post gone, throttled, and unreachable (moved out of `SocialProblems`). `SocialProblems::resolve()` already sits one arm below PHPMD's cyclomatic limit. Throttled must be matched before its parent `SocialNetworkUnreachableException`, and tagged mappers run in no guaranteed order. So both arms live in one `match`, and `SocialProblems` loses its unreachable arm.
4. **A throttled network is a `503` with `Retry-After`, type `social_network_throttled`, on every social endpoint.** Before, connect and import showed it as the `502` "unreachable" problem. No test or client switches on that.

5. **Planner amendment — an existing Bluesky like is reused, never duplicated.** The authenticated `getPosts` answer carries `post.viewer.like` (the at-uri of the acting account's like) when the account already likes the post — liked in the Bluesky app, or through a second entry of the same post (Home item + profile-RSS copy). Task 6: `SocialPostModel` gains a fourth promoted property `public ?string $existingLikeId` (`onBluesky(..., ?string $existingLikeId)`; `onMastodon` passes null), and `BlueskyPostResolver` fills it from `post.viewer.like`. Task 7: `BlueskyPostLiker::like()` returns `new SocialLikeResultModel($post->existingLikeId)` without calling `createRecord` when it is set; add a test for it (no `createRecord` route registered). Mastodon needs nothing: `favourite` is idempotent on the server.

## Decisions the brief left to the plan

- **Problem statuses:**
  - `NotASocialPostException` → `409 social_post_unsupported`.
  - `NoSocialAccountForNetworkException` → `409 social_account_missing`.

  Both are 409, matching `social_account_needs_reconnect`: the request is well-formed, and the entry or the account set does not allow it. `SocialPostNotFoundException` → `404 social_post_not_found`, "Post no longer available".
- **`likeNetwork` also requires a post address.** `social` is non-null only when `SocialPostAddress::of()` finds one (ruling 2) *and* the user has an account on that network (ruling 1). A Bluesky entry whose guid is no at-uri never shows a button that can only fail.
- **A private feed whose `SocialFeed` belongs to another user acts as no account** (`NoSocialAccountForNetworkException`). This cannot happen by construction; the check costs nothing.
- **Mastodon status ids are checked against `^[A-Za-z0-9]{1,64}$`** before they reach a URL path. Mainline ids are digits, and forks speaking the Mastodon API (Akkoma, Pleroma) use base62 flake ids.
- **Rate limit `social_like`: 60 per 5 minutes per user, for like and unlike alike.** Each call spends two or three requests on the user's own network account, and Bluesky meters writes per account. The limit stops a script, not a reader.
- **The toggle has an `aria-label` and no `title`,** like its neighbours in the bar. The label names the network ("Like on Bluesky", "Unlike on Mastodon").
- **The like toggle sits right after Favourite.**
- **The duplicates popover flips its local clone,** as it does for Favourite. A like on a copy the list does not hold still reaches the server; only the optimistic row patch has nothing to patch.
- **Like counts stay out (ruling 13), with their seams named.** No count field or column exists now. Counts would arrive at these places:
  - the entry JSON's `social` object gains `likeCount` (via `EntryListRowSocial`);
  - `SocialPostModel` gains the count the resolver already receives (`likeCount` in `getPosts`, `favourites_count` in the search answer);
  - `SocialLikeResultModel` gains the fresh count after a like;
  - for counts on posts never acted on, the ingest point is the timeline mappers (`Support/BlueskyTimelinePage`, `Support/MastodonTimelinePage`), which read the same post views.
- **`EntryListRowSocial` lives in `App\Repository`,** beside `EntryListRowSubscription`, since `EntryListRowEnrichment` (a Repository value) carries it.
- **A reply reuses, unchanged:**
  - `Support/SocialPostAddress` and `Model/SocialPostAddressModel`;
  - `ActingAccountChooser`, `ActingAccountGuard`, `SocialPostFinder`;
  - `PostResolver/*` and `Model/SocialPostModel` (Bluesky reply refs need uri + cid; Mastodon's `in_reply_to_id` is the local status id);
  - `BlueskySessions::postAs()` and `MastodonAccountApi::post()`;
  - the exceptions `NotASocialPostException`, `NoSocialAccountForNetworkException` and `SocialPostNotFoundException`, with their `SocialInteractionProblems` arms.

  A reply adds its own `PostReplier/PostReplierInterface` beside `PostLiker/`, its own store, and its own controller action. Two things change for it:
  - `MastodonAccountApi::post()` gains a JSON body parameter;
  - the Mastodon token scope grows to `write:statuses`.

## Global Constraints

- CLAUDE.md "PHP code style" applies in full:
  - Services in a module root are `final readonly`.
  - Role folders: `Model/` (suffix `Model`), `Support/` (static-only, private constructor), `Exception/`, a folder per interface.
  - No abbreviations, no boolean flag parameters, guard clauses, at most three parameters per method (entity constructors excepted, as `SocialAccount` already is).
  - Comments only when a future reader would otherwise get the code wrong.
- **HTTP statuses are `Symfony\Component\HttpFoundation\Response::HTTP_*` constants in `src` and `tests`**, a `MockResponse` `http_code` included.
- Likeable (rulings 1, 2):
  - The feed classifies to a network via `SocialFeedClassifier::networkOf()`, and the user has ≥1 `SocialAccount` on it, any status.
  - The post identity comes from `SocialPostAddress::of()`. Bluesky: the guid, when it is `at://…/app.bsky.feed.post/…` (`AtPostUri::matches`). Mastodon: the entry URL when it is absolute http(s), else the guid when it is.
- Storage (ruling 3):
  - `App\Entity\SocialLike`, table `social_like`: `id`, `user_id` → `app_user` CASCADE, `entry_id` → `entry` CASCADE, `social_account_id` → `social_account` CASCADE, `remote_id VARCHAR(512)`, `liked_at DATETIME` (naive UTC).
  - Unique `(user_id, entry_id)`. A row exists ⇔ liked.
  - Left out of backups. Retention unchanged.
- Acting account (ruling 4):
  - A private feed acts through its `SocialFeed` account.
  - Otherwise the user's default account on the network: the oldest default by `connectedAt`, then by `id`. Without a default, the oldest account on the network.
  - Unlike uses the account on the row.
- API (ruling 5):
  - `PUT /api/entries/{id}/like` and `DELETE /api/entries/{id}/like`, both `200 {"isLiked": bool}`, both idempotent.
  - The entry loads through `EntryListRepository::getRowForUser()`, as `PATCH /state` does; another user's entry is a 404.
- Remote calls (ruling 7):
  - Bluesky like: `GET {pds}/xrpc/app.bsky.feed.getPosts?uris=<rawurlencoded uri>`. An empty `posts` means the post is gone. Then `POST {pds}/xrpc/com.atproto.repo.createRecord` `{repo: DID, collection: "app.bsky.feed.like", record: {"$type": "app.bsky.feed.like", subject: {uri, cid}, createdAt}}`. `createdAt` is ISO-8601 UTC with milliseconds; the answer's `uri` is the like id.
  - Bluesky unlike: `POST {pds}/xrpc/com.atproto.repo.deleteRecord` `{repo, collection: "app.bsky.feed.like", rkey: <last path segment>}`.
  - Mastodon like: `GET {instance}/api/v2/search?q=<rawurlencoded url>&resolve=true&type=statuses&limit=1`. No status means the post is gone. Then `POST {instance}/api/v1/statuses/:id/favourite`, where a 404 means gone.
  - Mastodon unlike: `POST …/unfavourite`, where a 404 counts as success.
- Errors (rulings 8, 12):
  - `NotASocialPostException`, `NoSocialAccountForNetworkException` and `SocialPostNotFoundException` are new in `Service/Social/Exception/`.
  - `SocialCredentialsRejectedException` marks the acting account `needs_reconnect`, flushes, and rethrows (`422 social_credentials_rejected`, existing).
  - A `needs_reconnect` account is refused before any call (`409 social_account_needs_reconnect`, existing).
- Entry JSON (ruling 12): every list row, detail and duplicate carries `social: {network: 'bluesky'|'mastodon', isLiked: bool} | null`. A page costs two queries more (accounts' networks, liked ids), never one per row.
- Frontend (ruling 9):
  - A `thumb_up` flag toggle in `app-entry-actions` after Favourite, shown only when `entry.social` is set.
  - It is optimistic, and survives a list reload while in flight (the `EntriesStore.inFlightPatches` mechanism).
  - A failure puts the flag back and calls `ToastService.show({message: problemMessage(problem)})`.
  - i18n keys in `en.json` and `de.json`.
- Module placement (ruling 10): everything in `Service/Social`. The new edges are `Social → Url` (`AbsoluteHttpUrl`) and `Social → Bluesky` (`AtPostUri`), which already existed. `grep -rn 'App\\Service\\Social' backend/src/Service/Url backend/src/Service/Bluesky` finds nothing, so there is no cycle. `App\Repository\EntryListRowEnricher` names `Service\Social\Support\SocialPostAddress`, a `\Support\` helper `PersistenceKnowsNoServiceRule` allows.
- Datetimes are naive UTC.
- Commit format `type(#1600): subject`, no attribution lines. Branch `feature/1600-social-thumbs-up` (checked out).
- Backend tests: `php bin/phpunit <path>` from `backend/` (SQLite). Frontend tests only in the container: `docker compose exec -T frontend npm test -- <path>`.
- Every backend task ends with:
  - `composer check` (warm the cache first: `bin/console cache:warmup`);
  - `composer md`, clean for every touched `src` file;
  - PhpStorm inspections (`mcp__phpstorm__lint_files`) on the changed PHP files, where ERROR and WARNING block.
- The plan's code fixes content, not final line wrapping: wrap where `composer cs` (PSR-12, 120 columns) or Prettier (100 columns) asks.

## Pre-flight notes for the executor

1. **Concurrent sessions share this checkout.** Run `git status` and `git branch --show-current` before every task. Do not `checkout`, `reset` or `stash` without checking.
2. `MockHttpClient` hands the callback the URL **verbatim**: an encoded `at%3A%2F%2F…` stays encoded (measured in #1598). Route keys are exact strings.
3. `RoutedHttpClient` records `$options['body']`. A `json` request option arrives there already encoded, as `MockHttpClient` prepares the request first. If a body assertion sees `''`, read `$options['json']` instead and adjust the assertion, not the code.
4. `SocialAccountRepository::networksOf()` relies on `getArrayResult()` hydrating an `enumType` column to the enum, as `SocialFeedRepository::subscribedCollectionsOf()` already does with `SocialFeedKind`. If it comes back as a string, map it with `SocialNetwork::from()`.
5. `infection:diff` ignores untracked files and diffs against `origin/develop`. Commit everything and run `git fetch origin develop` before the final run.
6. Where a step says "append to `<Test>`", add the shown methods inside the existing class and merge the shown `use` lines into its import block.

## File structure

Backend (`backend/`):

| File | Responsibility |
|---|---|
| `src/Entity/SocialLike.php`, `src/Repository/SocialLikeRepository.php`, `migrations/Version20261013120000.php` | the per-user like row |
| `src/Repository/SocialAccountRepository.php`, `src/Repository/SocialFeedRepository.php` (modify) | `networksOf`, `findActingOnNetwork`, `findOneByFeed` |
| `src/Service/Social/Model/SocialPostAddressModel.php`, `src/Service/Social/Support/SocialPostAddress.php` | entry → post address |
| `src/Service/Social/ActingAccountChooser.php`, `src/Service/Social/ActingAccountGuard.php` | which account acts; refusal handling |
| `src/Service/Fetch/Model/ApiRequestModel.php`, `src/Service/Social/Model/MastodonSessionModel.php`, `src/Service/Social/MastodonApiClient.php` (modify), `src/Service/Social/MastodonAccountApi.php`, `src/Service/Social/BlueskySessions.php` (modify) | authenticated GET/POST as an account |
| `src/Service/Social/TimelineSource/{Bluesky,Mastodon}TimelineSource.php` (modify) | use the shared account API |
| `src/Service/Social/Model/SocialPostModel.php`, `src/Service/Social/PostResolver/{PostResolverInterface,BlueskyPostResolver,MastodonPostResolver}.php`, `src/Service/Social/SocialPostFinder.php` | address → post on the acting account |
| `src/Service/Social/Model/SocialLikeResultModel.php`, `src/Service/Social/PostLiker/{PostLikerInterface,BlueskyPostLiker,MastodonPostLiker}.php` | like / unlike per network |
| `src/Service/Social/SocialLikes.php` | orchestration and persistence |
| `src/Service/Social/Exception/{NotASocialPost,NoSocialAccountForNetwork,SocialPostNotFound}Exception.php` | typed failures |
| `src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php`, `SocialProblems.php` (modify) | problem mapping |
| `src/Controller/Api/EntryLikeController.php`, `src/Http/SocialLikeJson.php`, `config/packages/rate_limiter.yaml` | the API |
| `src/Repository/{EntryListRowSocial,EntryListRowEnrichment,EntryListRowEnricher}.php`, `src/Http/EntryJson.php` | `social` on every entry |
| `tests/Support/FakeMastodonNetwork.php` | scripted resolver + liker |

Frontend (`frontend/`): `src/app/reader/models.ts`, `src/testing/entry-testing.ts`, `src/app/reader/reader-api.ts` (+ spec), `src/app/reader/state/entries.store.ts` (+ spec), `src/app/reader/entry/entry-actions/entry-action-handler.ts`, `entry-actions.component.{ts,html,spec.ts}`, `src/app/reader/shell/entry-state-actions.service.ts` (+ new spec), `src/app/reader/list/magazine/entry-duplicates.component.ts`, `public/i18n/{en,de}.json`.

Docs: `docs/backup.md` §6.2, `docs/security.md` "Social accounts".

---

### Task 1: The `SocialLike` row and its migration

**Files:**
- Create: `backend/src/Entity/SocialLike.php`
- Create: `backend/src/Repository/SocialLikeRepository.php`
- Create: `backend/migrations/Version20261013120000.php`
- Modify: `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php` (`ACCOUNT_SCOPED_WHOLLY_DROPPED`)
- Modify: `docs/backup.md` §6.2 table
- Test: `backend/tests/Repository/SocialLikeRepositoryTest.php`

**Interfaces:**
- Produces:
  - `new SocialLike(User $user, Entry $entry, SocialAccount $socialAccount, string $remoteId, \DateTimeImmutable $likedAt)`
  - `SocialLike::getId(): ?int`, `requireId(): int`, `getUser(): User`, `getEntry(): Entry`, `getSocialAccount(): SocialAccount`, `getRemoteId(): string`, `getLikedAt(): \DateTimeImmutable`
  - `SocialLikeRepository::findOneFor(User $user, Entry $entry): ?SocialLike`
  - `SocialLikeRepository::likedEntryIdsAmong(int $userId, list<int> $entryIds): list<int>` (no query for `[]`)

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Repository/SocialLikeRepositoryTest.php`:

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
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class SocialLikeRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private SocialAccount $account;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('social-likes@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $this->feed = new Feed('https://mastodon.social/@Gargron.rss');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testALikeIsFoundForItsOwnUserAndEntryOnly(): void
    {
        $liked = $this->entry('liked');
        $unliked = $this->entry('unliked');
        $like = $this->like($this->owner, $this->account, $liked);

        self::assertSame($like, $this->repository()->findOneFor($this->owner, $liked));
        self::assertNull($this->repository()->findOneFor($this->owner, $unliked));
        self::assertNull($this->repository()->findOneFor($this->user('likes-stranger@example.test'), $liked));
    }

    public function testLikedIdsAreTheUsersOwnAmongTheAskedEntries(): void
    {
        $first = $this->entry('first');
        $second = $this->entry('second');
        $unasked = $this->entry('unasked');
        $strangersOnly = $this->entry('strangers-only');
        $this->like($this->owner, $this->account, $first);
        $this->like($this->owner, $this->account, $second);
        $this->like($this->owner, $this->account, $unasked);
        $stranger = $this->user('likes-ids-stranger@example.test');
        $this->like(
            $stranger,
            SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($stranger)),
            $strangersOnly,
        );

        $likedIds = $this->repository()->likedEntryIdsAmong(
            $this->owner->requireId(),
            [$first->requireId(), $second->requireId(), $strangersOnly->requireId()],
        );
        sort($likedIds);

        self::assertSame([$first->requireId(), $second->requireId()], $likedIds);
        self::assertSame([], $this->repository()->likedEntryIdsAmong($this->owner->requireId(), []));
    }

    public function testASecondLikeOfTheSameEntryIsRefusedByTheDatabase(): void
    {
        $entry = $this->entry('twice');
        $this->like($this->owner, $this->account, $entry);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->like($this->owner, $this->account, $entry);
    }

    public function testRemovingTheAccountOrTheEntryDropsTheLike(): void
    {
        $byEntry = $this->entry('by-entry');
        $byAccount = $this->entry('by-account');
        $this->like($this->owner, $this->account, $byEntry);
        $this->like($this->owner, $this->account, $byAccount);

        $this->entityManager->remove($byEntry);
        $this->entityManager->flush();
        self::assertSame(1, $this->repository()->count([]));

        $this->entityManager->remove($this->account);
        $this->entityManager->flush();
        $this->entityManager->clear();
        self::assertSame(0, $this->repository()->count([]));
    }

    private function entry(string $guid): Entry
    {
        $now = new \DateTimeImmutable('2026-10-13 10:00:00');
        $entry = new Entry($this->feed, $guid, 'https://mastodon.social/@Gargron/' . $guid, $guid, $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function like(User $user, SocialAccount $account, Entry $entry): SocialLike
    {
        $like = new SocialLike($user, $entry, $account, '110', new \DateTimeImmutable('2026-10-13 10:05:00'));
        $this->entityManager->persist($like);
        $this->entityManager->flush();

        return $like;
    }

    private function repository(): SocialLikeRepository
    {
        /** @var SocialLikeRepository $repository */
        $repository = $this->entityManager->getRepository(SocialLike::class);

        return $repository;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Repository/SocialLikeRepositoryTest.php`
Expected: FAIL, `Class "App\Entity\SocialLike" not found`.

- [ ] **Step 3: Write the entity, the repository and the migration**

Create `backend/src/Entity/SocialLike.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SocialLikeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A user's like of a post at its origin (#1600), with the id the network gave it so it can be taken back. */
#[ORM\Entity(repositoryClass: SocialLikeRepository::class)]
#[ORM\Table(name: 'social_like')]
#[ORM\Index(name: 'idx_social_like_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_social_like_entry', columns: ['entry_id'])]
#[ORM\Index(name: 'idx_social_like_account', columns: ['social_account_id'])]
#[ORM\UniqueConstraint(name: 'uniq_social_like_user_entry', columns: ['user_id', 'entry_id'])]
final class SocialLike
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialAccount $socialAccount;

    #[ORM\Column(length: 512)]
    private string $remoteId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $likedAt;

    public function __construct(
        User $user,
        Entry $entry,
        SocialAccount $socialAccount,
        string $remoteId,
        \DateTimeImmutable $likedAt,
    ) {
        $this->user = $user;
        $this->entry = $entry;
        $this->socialAccount = $socialAccount;
        $this->remoteId = $remoteId;
        $this->likedAt = $likedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getSocialAccount(): SocialAccount
    {
        return $this->socialAccount;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getLikedAt(): \DateTimeImmutable
    {
        return $this->likedAt;
    }
}
```

Create `backend/src/Repository/SocialLikeRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\SocialLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialLike>
 */
final class SocialLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialLike::class);
    }

    public function findOneFor(User $user, Entry $entry): ?SocialLike
    {
        return $this->findOneBy(['user' => $user, 'entry' => $entry]);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    public function likedEntryIdsAmong(int $userId, array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<array{entryId: int|string}> $rows */
        $rows = $this->createQueryBuilder('sl')
            ->select('IDENTITY(sl.entry) AS entryId')
            ->andWhere('sl.user = :user')
            ->andWhere('sl.entry IN (:entryIds)')
            ->setParameter('user', $userId)
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['entryId'], $rows);
    }
}
```

Create `backend/migrations/Version20261013120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create social_like: a user\'s likes of Bluesky and Mastodon posts at their origin (#1600).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('social_like'), 'social_like already exists.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE social_like (id INT AUTO_INCREMENT NOT NULL, remote_id VARCHAR(512) NOT NULL,'
                . ' liked_at DATETIME NOT NULL, user_id INT NOT NULL, entry_id INT NOT NULL,'
                . ' social_account_id INT NOT NULL,'
                . ' INDEX idx_social_like_user (user_id), INDEX idx_social_like_entry (entry_id),'
                . ' INDEX idx_social_like_account (social_account_id),'
                . ' UNIQUE INDEX uniq_social_like_user_entry (user_id, entry_id), PRIMARY KEY (id))'
                . ' DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE social_like ADD CONSTRAINT fk_social_like_user'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE social_like ADD CONSTRAINT fk_social_like_entry'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE social_like ADD CONSTRAINT fk_social_like_account'
                . ' FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE');

            return;
        }

        $this->addSql('CREATE TABLE social_like (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' remote_id VARCHAR(512) NOT NULL, liked_at DATETIME NOT NULL, user_id INTEGER NOT NULL,'
            . ' entry_id INTEGER NOT NULL, social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_social_like_user FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_like_entry FOREIGN KEY (entry_id) REFERENCES entry (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_like_account FOREIGN KEY (social_account_id) REFERENCES social_account (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_social_like_user ON social_like (user_id)');
        $this->addSql('CREATE INDEX idx_social_like_entry ON social_like (entry_id)');
        $this->addSql('CREATE INDEX idx_social_like_account ON social_like (social_account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_like_user_entry ON social_like (user_id, entry_id)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('social_like'), 'social_like does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE social_like');
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

In `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, add `use App\Entity\SocialLike;` and this entry to `ACCOUNT_SCOPED_WHOLLY_DROPPED`, right after the `SocialFeed::class` entry:

```php
        SocialLike::class => 'Likes given through a connected account (#1600). They belong to an account a backup '
            . 'drops, and a restore cannot give them back without it.',
```

In `docs/backup.md` §6.2, add this row after the `SocialFeed` row:

```markdown
| `SocialLike` | Your likes of Bluesky and Mastodon posts, given through a connected account. They belong to that account, which the file leaves out. The likes themselves stay on the networks. |
```

- [ ] **Step 4: Run the tests and verify the migration on both dialects**

Run: `cd backend && php bin/phpunit tests/Repository/SocialLikeRepositoryTest.php tests/Service/Backup/Support/BackupSchemaCoverageTest.php`
Expected: PASS.

Migrate an empty SQLite database and validate the schema. Use a scratch database path, never the dev database:

```bash
cd backend && DATABASE_URL="sqlite:///$TMPDIR/sfr-1600-migrate.db" bin/console doctrine:migrations:migrate --no-interaction --env=dev \
  && DATABASE_URL="sqlite:///$TMPDIR/sfr-1600-migrate.db" bin/console doctrine:schema:validate --env=dev
```

Expected: `[OK] The database schema is in sync with the mapping files.` If an index name differs (Doctrine's implicit `IDX_…` for a join column), align the entity's `#[ORM\Index]` and the migration. Then apply the migration on the MySQL stack: `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate`.

- [ ] **Step 5: Gates and commit**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`
Expected: clean.

```bash
git add backend/src/Entity/SocialLike.php backend/src/Repository/SocialLikeRepository.php \
  backend/migrations/Version20261013120000.php backend/tests/Repository/SocialLikeRepositoryTest.php \
  backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php docs/backup.md
git commit -m "feat(#1600): store a user's like of a social post with the network's like id"
```

---

### Task 2: Repository reads for the acting account and the entry list

**Files:**
- Modify: `backend/src/Repository/SocialAccountRepository.php`
- Modify: `backend/src/Repository/SocialFeedRepository.php`
- Test: `backend/tests/Repository/SocialAccountRepositoryTest.php`, `backend/tests/Repository/SocialFeedRepositoryTest.php`

**Interfaces:**
- Produces:
  - `SocialAccountRepository::networksOf(int $userId): list<SocialNetwork>` (each network once)
  - `SocialAccountRepository::findActingOnNetwork(User $user, SocialNetwork $network): ?SocialAccount` (default first, then oldest `connectedAt`, then lowest id)
  - `SocialFeedRepository::findOneByFeed(Feed $feed): ?SocialFeed`

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Repository/SocialAccountRepositoryTest.php`:

```php
    public function testNetworksOfNamesEachNetworkOfTheUserOnce(): void
    {
        $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:one');
        $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:two');
        $this->persistAccount($this->stranger, SocialNetwork::Mastodon, 'bob@mastodon.example');
        $this->entityManager->flush();

        self::assertSame([SocialNetwork::Bluesky], $this->repository()->networksOf($this->owner->requireId()));
        self::assertSame([], $this->repository()->networksOf($this->stranger->requireId() + 1000));
    }

    public function testTheDefaultAccountActsBeforeAnOlderOne(): void
    {
        $older = $this->accountConnectedAt('did:plc:older', '2026-10-01 10:00:00');
        $default = $this->accountConnectedAt('did:plc:default', '2026-10-05 10:00:00');
        $default->becomeDefault();
        $this->entityManager->flush();

        self::assertSame($default, $this->repository()->findActingOnNetwork($this->owner, SocialNetwork::Bluesky));
        self::assertNotSame($older, $default);
    }

    public function testTheOldestOfSeveralDefaultsActs(): void
    {
        $newer = $this->accountConnectedAt('did:plc:newer', '2026-10-05 10:00:00');
        $oldest = $this->accountConnectedAt('did:plc:oldest', '2026-10-01 10:00:00');
        $newer->becomeDefault();
        $oldest->becomeDefault();
        $this->entityManager->flush();

        self::assertSame($oldest, $this->repository()->findActingOnNetwork($this->owner, SocialNetwork::Bluesky));
    }

    public function testWithoutADefaultTheOldestAccountActsAndAnotherNetworkNever(): void
    {
        $this->accountConnectedAt('did:plc:newer', '2026-10-05 10:00:00');
        $oldest = $this->accountConnectedAt('did:plc:oldest', '2026-10-01 10:00:00');

        self::assertSame($oldest, $this->repository()->findActingOnNetwork($this->owner, SocialNetwork::Bluesky));
        self::assertNull($this->repository()->findActingOnNetwork($this->owner, SocialNetwork::Mastodon));
        self::assertNull($this->repository()->findActingOnNetwork($this->stranger, SocialNetwork::Bluesky));
    }

    private function accountConnectedAt(string $identity, string $connectedAt): SocialAccount
    {
        $account = $this->persistAccount($this->owner, SocialNetwork::Bluesky, $identity);
        $account->replaceCredential(
            '@' . $identity,
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable($connectedAt),
        );
        $this->entityManager->flush();

        return $account;
    }
```

Append to `backend/tests/Repository/SocialFeedRepositoryTest.php`:

```php
    public function testAPrivateFeedIsFoundByItsFeedAndAnOrdinaryFeedIsNot(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, '');
        $ordinary = new Feed('https://example.com/ordinary.xml');
        $this->entityManager->persist($ordinary);
        $this->entityManager->flush();

        self::assertSame($home, SocialFeeds::repository($this->entityManager)->findOneByFeed($home->getFeed()));
        self::assertNull(SocialFeeds::repository($this->entityManager)->findOneByFeed($ordinary));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Repository/SocialAccountRepositoryTest.php tests/Repository/SocialFeedRepositoryTest.php`
Expected: FAIL, `Call to undefined method …::networksOf()` / `findOneByFeed()`.

- [ ] **Step 3: Implement**

In `backend/src/Repository/SocialAccountRepository.php`, add:

```php
    /** @return list<SocialNetwork> each network the user has an account on, once */
    public function networksOf(int $userId): array
    {
        /** @var list<array{network: SocialNetwork}> $rows */
        $rows = $this->createQueryBuilder('sa')
            ->select('DISTINCT sa.network AS network')
            ->andWhere('sa.user = :user')
            ->setParameter('user', $userId)
            ->orderBy('sa.network', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): SocialNetwork => $row['network'], $rows);
    }

    public function findActingOnNetwork(User $user, SocialNetwork $network): ?SocialAccount
    {
        return $this->findOneBy(
            ['user' => $user, 'network' => $network],
            ['isDefault' => 'DESC', 'connectedAt' => 'ASC', 'id' => 'ASC'],
        );
    }
```

In `backend/src/Repository/SocialFeedRepository.php`, add `use App\Entity\Feed;` and:

```php
    public function findOneByFeed(Feed $feed): ?SocialFeed
    {
        return $this->findOneBy(['feed' => $feed]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Repository/SocialAccountRepositoryTest.php tests/Repository/SocialFeedRepositoryTest.php`
Expected: PASS. If `networksOf` returns strings, see pre-flight note 4.

- [ ] **Step 5: Gates and commit**

Run: `cd backend && composer check && composer md`

```bash
git add backend/src/Repository/SocialAccountRepository.php backend/src/Repository/SocialFeedRepository.php \
  backend/tests/Repository/SocialAccountRepositoryTest.php backend/tests/Repository/SocialFeedRepositoryTest.php
git commit -m "feat(#1600): read a user's networks, the acting account and a private feed by its feed"
```

---

### Task 3: The post address of an entry

**Files:**
- Create: `backend/src/Service/Social/Model/SocialPostAddressModel.php`
- Create: `backend/src/Service/Social/Support/SocialPostAddress.php`
- Test: `backend/tests/Service/Social/Support/SocialPostAddressTest.php`

**Interfaces:**
- Produces:
  - `new SocialPostAddressModel(SocialNetwork $network, string $address)` with public readonly `network`, `address`
  - `SocialPostAddress::of(Entry $entry): ?SocialPostAddressModel`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Social/Support/SocialPostAddressTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Support\SocialPostAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialPostAddressTest extends TestCase
{
    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';

    #[DataProvider('posts')]
    public function testAnEntryIsAddressedAsItsNetworkKnowsIt(
        Entry $entry,
        ?SocialPostAddressModel $expected,
    ): void {
        self::assertEquals($expected, SocialPostAddress::of($entry));
    }

    /** @return iterable<string, array{Entry, SocialPostAddressModel|null}> */
    public static function posts(): iterable
    {
        $profile = new Feed('https://bsky.app/profile/did:plc:bob/rss');
        $home = new Feed('social:bluesky:3:home');
        $home->setSourceFormat(SourceFormat::BLUESKY_API);
        $mastodon = new Feed('https://mastodon.social/@Gargron.rss');
        $plain = new Feed('https://example.com/feed.xml');

        yield 'bluesky profile post by its at-uri' => [
            self::entry($profile, self::POST_URI, 'https://bsky.app/profile/did:plc:bob/post/3abc'),
            new SocialPostAddressModel(SocialNetwork::Bluesky, self::POST_URI),
        ];
        yield 'bluesky private feed post' => [
            self::entry($home, self::POST_URI, null),
            new SocialPostAddressModel(SocialNetwork::Bluesky, self::POST_URI),
        ];
        yield 'bluesky item without an at-uri guid' => [
            self::entry($profile, 'https://bsky.app/profile/did:plc:bob/post/3abc', null),
            null,
        ];
        yield 'bluesky item with a list at-uri' => [
            self::entry($profile, 'at://did:plc:bob/app.bsky.graph.list/3l1', null),
            null,
        ];
        yield 'mastodon post by its url' => [
            self::entry($mastodon, 'tag:mastodon.social,2026:110', self::STATUS_URL),
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
        ];
        yield 'mastodon post by its guid when it has no url' => [
            self::entry($mastodon, self::STATUS_URL, null),
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
        ];
        yield 'mastodon item with neither an http url nor an http guid' => [
            self::entry($mastodon, 'tag:mastodon.social,2026:110', 'mailto:gargron@example.test'),
            null,
        ];
        yield 'plain feed' => [self::entry($plain, self::POST_URI, self::STATUS_URL), null];
    }

    private static function entry(Feed $feed, string $guid, ?string $url): Entry
    {
        $now = new \DateTimeImmutable('2026-10-13 10:00:00');

        return new Entry($feed, $guid, $url, 'Post', $now, $now);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Social/Support/SocialPostAddressTest.php`
Expected: FAIL, class `SocialPostAddressModel` not found.

- [ ] **Step 3: Implement**

Create `backend/src/Service/Social/Model/SocialPostAddressModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Enum\SocialNetwork;

/** A post as its network names it everywhere: a Bluesky AT URI or a Mastodon status URL. */
final readonly class SocialPostAddressModel
{
    public function __construct(
        public SocialNetwork $network,
        public string $address,
    ) {
    }
}
```

Create `backend/src/Service/Social/Support/SocialPostAddress.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\Entry;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** Which network post an entry is: the one rule every interaction with a post (a like, later a reply) starts from. */
final class SocialPostAddress
{
    public static function of(Entry $entry): ?SocialPostAddressModel
    {
        return match (SocialFeedClassifier::networkOf($entry->getFeed())) {
            SocialNetwork::Bluesky => self::blueskyPost($entry->getGuid()),
            SocialNetwork::Mastodon => self::mastodonPost($entry),
            null => null,
        };
    }

    private static function blueskyPost(string $guid): ?SocialPostAddressModel
    {
        return AtPostUri::matches($guid) ? new SocialPostAddressModel(SocialNetwork::Bluesky, $guid) : null;
    }

    private static function mastodonPost(Entry $entry): ?SocialPostAddressModel
    {
        $statusUrl = AbsoluteHttpUrl::orNull($entry->getUrl()) ?? AbsoluteHttpUrl::orNull($entry->getGuid());

        return null === $statusUrl ? null : new SocialPostAddressModel(SocialNetwork::Mastodon, $statusUrl);
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Social/Support/SocialPostAddressTest.php`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `cd backend && composer check && composer md`

```bash
git add backend/src/Service/Social/Model/SocialPostAddressModel.php backend/src/Service/Social/Support/SocialPostAddress.php \
  backend/tests/Service/Social/Support/SocialPostAddressTest.php
git commit -m "feat(#1600): address an entry as the Bluesky or Mastodon post it is"
```

---

### Task 4: The acting account and its guard

**Files:**
- Create: `backend/src/Service/Social/ActingAccountChooser.php`
- Create: `backend/src/Service/Social/ActingAccountGuard.php`
- Create: `backend/src/Service/Social/Exception/NoSocialAccountForNetworkException.php`
- Test: `backend/tests/Service/Social/ActingAccountChooserTest.php`, `backend/tests/Service/Social/ActingAccountGuardTest.php`

**Interfaces:**
- Consumes: `SocialAccountRepository::findActingOnNetwork()`, `SocialFeedRepository::findOneByFeed()` (Task 2)
- Produces:
  - `ActingAccountChooser::accountFor(User $user, Entry $entry, SocialNetwork $network): SocialAccount` — throws `NoSocialAccountForNetworkException`
  - `ActingAccountGuard::actAs(SocialAccount $account, \Closure $call): mixed` (`@template T`, `\Closure(): T` → `T`) — throws `SocialAccountNeedsReconnectException` before calling, and on `SocialCredentialsRejectedException` marks the account, flushes and rethrows

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Social/ActingAccountChooserTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ActingAccountChooser;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialFeeds;

final class ActingAccountChooserTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('acting-account@example.test');
    }

    public function testAProfileFeedPostIsActedOnByTheDefaultAccount(): void
    {
        $this->account($this->owner, 'did:plc:older', '2026-10-01 10:00:00');
        $default = $this->account($this->owner, 'did:plc:default', '2026-10-05 10:00:00');
        $default->becomeDefault();
        $this->entityManager->flush();

        self::assertSame($default, $this->chooser()->accountFor(
            $this->owner,
            $this->entryOn(new Feed('https://bsky.app/profile/did:plc:bob/rss')),
            SocialNetwork::Bluesky,
        ));
    }

    public function testAPrivateFeedPostIsActedOnByItsOwnAccountEvenBesideADefault(): void
    {
        $default = $this->account($this->owner, 'did:plc:default', '2026-10-01 10:00:00');
        $default->becomeDefault();
        $own = $this->account($this->owner, 'did:plc:own', '2026-10-05 10:00:00');

        self::assertSame($own, $this->chooser()->accountFor(
            $this->owner,
            $this->entryOn($this->privateFeedOf($own)),
            SocialNetwork::Bluesky,
        ));
    }

    public function testAnotherUsersPrivateFeedHasNoAccountToActWith(): void
    {
        $this->account($this->owner, 'did:plc:mine', '2026-10-01 10:00:00');
        $stranger = $this->user('acting-stranger@example.test');
        $theirs = $this->account($stranger, 'did:plc:theirs', '2026-10-01 10:00:00');

        $this->expectException(NoSocialAccountForNetworkException::class);

        $this->chooser()->accountFor($this->owner, $this->entryOn($this->privateFeedOf($theirs)), SocialNetwork::Bluesky);
    }

    public function testNoAccountOnTheNetworkIsRefused(): void
    {
        $this->account($this->owner, 'did:plc:mine', '2026-10-01 10:00:00');

        $this->expectException(NoSocialAccountForNetworkException::class);
        $this->expectExceptionMessage('No Mastodon account');

        $this->chooser()->accountFor(
            $this->owner,
            $this->entryOn(new Feed('https://mastodon.social/@Gargron.rss')),
            SocialNetwork::Mastodon,
        );
    }

    private function account(User $user, string $identity, string $connectedAt): SocialAccount
    {
        $account = new SocialAccount(
            $user,
            SocialNetwork::Bluesky,
            $identity,
            '@' . $identity,
            'https://pds.example.test',
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable($connectedAt),
        );
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function privateFeedOf(SocialAccount $account): Feed
    {
        $feed = new Feed('social:bluesky:' . $account->requireId() . ':home');
        $feed->setSourceFormat(SourceFormat::BLUESKY_API);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new SocialFeed($account, $feed, SocialFeedKind::Home, ''));
        $this->entityManager->flush();

        return $feed;
    }

    private function entryOn(Feed $feed): Entry
    {
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-10-13 10:00:00');
        $entry = new Entry($feed, 'at://did:plc:bob/app.bsky.feed.post/3abc', null, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function chooser(): ActingAccountChooser
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);

        return new ActingAccountChooser(SocialFeeds::repository($this->entityManager), $accounts);
    }
}
```

Create `backend/tests/Service/Social/ActingAccountGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialAccountStatus;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;

final class ActingAccountGuardTest extends DbTestCase
{
    use SeedsUsers;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user('acting-guard@example.test')),
        );
    }

    public function testTheCallsAnswerComesBack(): void
    {
        self::assertSame('110', $this->guard()->actAs($this->account, static fn (): string => '110'));
    }

    public function testAnAccountThatNeedsReconnectingIsRefusedBeforeTheCall(): void
    {
        $this->account->markNeedsReconnect();
        $called = false;

        try {
            $this->guard()->actAs($this->account, static function () use (&$called): void {
                $called = true;
            });
            self::fail('An account that needs reconnecting must not act.');
        } catch (SocialAccountNeedsReconnectException) {
            self::assertFalse($called);
        }
    }

    public function testARefusedCredentialMarksTheAccountAndIsRethrown(): void
    {
        $refusal = new SocialCredentialsRejectedException('The Mastodon instance refused this access token.');

        try {
            $this->guard()->actAs($this->account, static fn (): string => throw $refusal);
            self::fail('A refused credential must reach the caller.');
        } catch (SocialCredentialsRejectedException $exception) {
            self::assertSame($refusal, $exception);
        }

        $accountId = $this->account->requireId();
        $this->entityManager->clear();
        $stored = $this->entityManager->find(SocialAccount::class, $accountId);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $stored?->getStatus());
    }

    private function guard(): ActingAccountGuard
    {
        return new ActingAccountGuard($this->entityManager);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/ActingAccountChooserTest.php tests/Service/Social/ActingAccountGuardTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

Create `backend/src/Service/Social/Exception/NoSocialAccountForNetworkException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

final class NoSocialAccountForNetworkException extends \RuntimeException
{
}
```

Create `backend/src/Service/Social/ActingAccountChooser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialFeedRepository;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Support\SocialFeedClassifier;

/** Which of the user's accounts acts on an entry's post: a private feed's own account, else the network's default. */
final readonly class ActingAccountChooser
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private SocialAccountRepository $socialAccounts,
    ) {
    }

    /** @throws NoSocialAccountForNetworkException */
    public function accountFor(User $user, Entry $entry, SocialNetwork $network): SocialAccount
    {
        return $this->accountOf($user, $entry->getFeed(), $network)
            ?? throw new NoSocialAccountForNetworkException(
                sprintf('No %s account is connected to act on this post.', $network->displayName()),
            );
    }

    private function accountOf(User $user, Feed $feed, SocialNetwork $network): ?SocialAccount
    {
        if (null === SocialFeedClassifier::privateAddressOf($feed)) {
            return $this->socialAccounts->findActingOnNetwork($user, $network);
        }
        $account = $this->socialFeeds->findOneByFeed($feed)?->getSocialAccount();

        return $account?->getUser()->requireId() === $user->requireId() ? $account : null;
    }
}
```

Create `backend/src/Service/Social/ActingAccountGuard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialAccountStatus;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ActingAccountGuard
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     *
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException once the account is marked as needing a reconnect
     */
    public function actAs(SocialAccount $account, \Closure $call): mixed
    {
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            throw new SocialAccountNeedsReconnectException(
                sprintf('%s needs reconnecting before it can act on a post.', $account->getHandle()),
            );
        }

        try {
            return $call();
        } catch (SocialCredentialsRejectedException $exception) {
            $account->markNeedsReconnect();
            $this->entityManager->flush();

            throw $exception;
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social/ActingAccountChooserTest.php tests/Service/Social/ActingAccountGuardTest.php`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `cd backend && composer check && composer md`

```bash
git add backend/src/Service/Social/ActingAccountChooser.php backend/src/Service/Social/ActingAccountGuard.php \
  backend/src/Service/Social/Exception/NoSocialAccountForNetworkException.php \
  backend/tests/Service/Social/ActingAccountChooserTest.php backend/tests/Service/Social/ActingAccountGuardTest.php
git commit -m "feat(#1600): choose the account that acts on a post and guard its credential"
```

---

### Task 5: One authenticated account API per network

**Files:**
- Modify: `backend/src/Service/Fetch/Model/ApiRequestModel.php` (`post()`)
- Modify: `backend/src/Service/Social/Model/MastodonSessionModel.php` (`post()`)
- Modify: `backend/src/Service/Social/MastodonApiClient.php` (`answer()` public, static `accepted()`)
- Create: `backend/src/Service/Social/MastodonAccountApi.php`
- Modify: `backend/src/Service/Social/TimelineSource/MastodonTimelineSource.php`
- Modify: `backend/src/Service/Social/BlueskySessions.php` (`postAs()`, static `accepted()`)
- Modify: `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php` (`itemsOf()` uses `BlueskySessions::accepted()`)
- Test: `backend/tests/Service/Social/MastodonAccountApiTest.php` (new), `backend/tests/Service/Social/BlueskySessionsTest.php`, `backend/tests/Service/Social/TimelineSource/MastodonTimelineSourceTest.php` (wiring)

**Interfaces:**
- Produces:
  - `ApiRequestModel::post(string $url, array<string,string> $headers = []): self` (no body)
  - `MastodonSessionModel::post(string $url): ApiRequestModel`
  - `MastodonApiClient::answer(ApiRequestModel $request): ApiResponseModel` (any status; throws only `SocialNetworkUnreachableException` when no answer arrived)
  - `MastodonApiClient::accepted(ApiResponseModel $answer): ApiResponseModel` (static: 429 → throttled, 401/403 → rejected, other non-2xx → unreachable)
  - `MastodonAccountApi::get(SocialAccount $account, string $path): ApiResponseModel`, `post(SocialAccount $account, string $path): ApiResponseModel` (any status comes back)
  - `BlueskySessions::postAs(SocialAccount $account, string $path, array<string,mixed> $json): ApiResponseModel` (stale-session retry, like `askAs`)
  - `BlueskySessions::accepted(ApiResponseModel $answer): ApiResponseModel` (static: 401 or stale → `SocialCredentialsRejectedException`, then `BlueskyApiClient::accepted`)

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Social/MastodonAccountApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonAccountApiTest extends TestCase
{
    private const string STATUS = 'https://mastodon.example/api/v1/statuses/110';

    public function testAGetCarriesTheAccountsTokenToItsOwnInstance(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::STATUS => new MockResponse('{"id":"110"}')]);

        $answer = $this->api($http)->get(SocialAccounts::mastodon(), '/api/v1/statuses/110');

        self::assertSame('{"id":"110"}', $answer->body);
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
    }

    public function testAPostCarriesTheTokenAndNoBody(): void
    {
        $http = new RoutedHttpClient(['POST ' . self::STATUS . '/favourite' => new MockResponse('{}')]);

        $this->api($http)->post(SocialAccounts::mastodon(), '/api/v1/statuses/110/favourite');

        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
        self::assertSame('', $http->requests[0]['body']);
    }

    public function testEveryStatusComesBackForTheCallerToRead(): void
    {
        $http = new RoutedHttpClient([
            'POST ' . self::STATUS . '/unfavourite' => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);

        $answer = $this->api($http)->post(SocialAccounts::mastodon(), '/api/v1/statuses/110/unfavourite');

        self::assertSame(Response::HTTP_NOT_FOUND, $answer->status);
    }

    public function testAnAcceptedAnswerRefusesARefusedToken(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::STATUS => new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        MastodonApiClient::accepted($this->api($http)->get(SocialAccounts::mastodon(), '/api/v1/statuses/110'));
    }

    public function testNoAnswerAtAllIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::STATUS => new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->api($http)->get(SocialAccounts::mastodon(), '/api/v1/statuses/110');
    }

    private function api(RoutedHttpClient $http): MastodonAccountApi
    {
        $guard = new UrlGuard(new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]), new IpValidator());

        return new MastodonAccountApi(
            new MastodonApiClient(FetchWiring::guardedApiClient($http->client(), $guard)),
            new StoredCredential(SocialAccounts::cipher()),
        );
    }
}
```

Append to `backend/tests/Service/Social/BlueskySessionsTest.php`. First split the helper so a sequenced `MockHttpClient` can drive it: rename the body of `sessions()` into `sessionsOver()`, and let `sessions()` delegate:

```php
    private function sessions(RoutedHttpClient $http, ArrayAdapter $cache): BlueskySessions
    {
        return $this->sessionsOver($http->client(), $cache);
    }

    private function sessionsOver(HttpClientInterface $http, ArrayAdapter $cache): BlueskySessions
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());

        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http, $guard));

        return new BlueskySessions(
            $api,
            new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
            self::cacheOver($cache),
        );
    }
```

Then add the tests and the `use` lines `App\Service\Fetch\Model\ApiResponseModel`, `App\Service\Social\Exception\SocialCredentialsRejectedException`, `PHPUnit\Framework\Attributes\DataProvider`, `Symfony\Component\HttpClient\MockHttpClient` and `Symfony\Contracts\HttpClient\HttpClientInterface`:

```php
    private const string CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';

    public function testAPostCarriesTheSessionsTokenAndItsJson(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => self::sessionAnswer('jwt-1'),
            self::CREATE_RECORD => new MockResponse('{"uri":"at://did:plc:alice/app.bsky.feed.like/3l"}'),
        ]);

        $answer = $this->sessions($http, new ArrayAdapter())->postAs(
            self::account(),
            '/xrpc/com.atproto.repo.createRecord',
            ['repo' => 'did:plc:alice'],
        );

        self::assertSame('{"uri":"at://did:plc:alice/app.bsky.feed.like/3l"}', $answer->body);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[1]['headers']);
        self::assertSame(['repo' => 'did:plc:alice'], json_decode($http->requests[1]['body'], true));
    }

    public function testAPostWithAnExpiredTokenIsSentOnceMoreWithAFreshSession(): void
    {
        $cache = new ArrayAdapter();
        $account = self::account();
        self::cacheOver($cache)->remember($account, new BlueskySessionModel('stale-jwt', 'alice.bsky.social'));
        $created = new MockResponse('{"uri":"at://did:plc:alice/app.bsky.feed.like/3l"}');
        $http = new MockHttpClient([
            new MockResponse('{"error":"ExpiredToken"}', ['http_code' => Response::HTTP_BAD_REQUEST]),
            self::sessionAnswer('jwt-2'),
            $created,
        ]);

        $answer = $this->sessionsOver($http, $cache)->postAs($account, '/xrpc/com.atproto.repo.createRecord', []);

        self::assertTrue($answer->isSuccessful());
        $headers = $created->getRequestOptions()['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertContains('Authorization: Bearer jwt-2', $headers);
        self::assertSame(3, $http->getRequestsCount());
    }

    #[DataProvider('refusedSessions')]
    public function testARefusedSessionIsARefusedCredential(ApiResponseModel $answer): void
    {
        $this->expectException(SocialCredentialsRejectedException::class);

        BlueskySessions::accepted($answer);
    }

    /** @return iterable<string, array{ApiResponseModel}> */
    public static function refusedSessions(): iterable
    {
        yield 'unauthorised' => [new ApiResponseModel(Response::HTTP_UNAUTHORIZED, '{"error":"AuthRequired"}')];
        yield 'expired token' => [new ApiResponseModel(Response::HTTP_BAD_REQUEST, '{"error":"ExpiredToken"}')];
    }

    public function testAnotherBadRequestIsAnUnreachableNetworkAndASuccessIsAccepted(): void
    {
        $success = new ApiResponseModel(Response::HTTP_OK, '{}');
        self::assertSame($success, BlueskySessions::accepted($success));

        $this->expectException(SocialNetworkUnreachableException::class);

        BlueskySessions::accepted(new ApiResponseModel(Response::HTTP_BAD_REQUEST, '{"error":"InvalidRequest"}'));
    }
```

In `backend/tests/Service/Social/TimelineSource/MastodonTimelineSourceTest.php`, replace the body of `source()` and add `use App\Service\Social\MastodonAccountApi;`:

```php
    private function source(RoutedHttpClient $http): MastodonTimelineSource
    {
        $guard = new UrlGuard(new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]), new IpValidator());

        return new MastodonTimelineSource(new MastodonAccountApi(
            new MastodonApiClient(FetchWiring::guardedApiClient($http->client(), $guard)),
            new StoredCredential(SocialAccounts::cipher()),
        ));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/MastodonAccountApiTest.php tests/Service/Social/BlueskySessionsTest.php tests/Service/Social/TimelineSource`
Expected: FAIL, `MastodonAccountApi` not found and `BlueskySessions::postAs()` undefined.

- [ ] **Step 3: Implement**

In `backend/src/Service/Fetch/Model/ApiRequestModel.php`, add after `get()`:

```php
    /** @param array<string, string> $headers */
    public static function post(string $url, array $headers = []): self
    {
        return new self('POST', $url, $headers, null);
    }
```

In `backend/src/Service/Social/Model/MastodonSessionModel.php`, add after `get()`:

```php
    public function post(string $url): ApiRequestModel
    {
        return ApiRequestModel::post($url, ['Authorization' => 'Bearer ' . $this->accessToken]);
    }
```

Replace the body of `backend/src/Service/Social/MastodonApiClient.php`'s class with:

```php
    private const array REFUSALS = [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN];

    public function __construct(private GuardedApiClient $api)
    {
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function send(ApiRequestModel $request): ApiResponseModel
    {
        return self::accepted($this->answer($request));
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException on any other answer but a 2xx
     */
    public static function accepted(ApiResponseModel $answer): ApiResponseModel
    {
        SocialNetworkThrottledException::throwIfThrottled($answer, 'The Mastodon instance asked for fewer requests.');
        if (\in_array($answer->status, self::REFUSALS, true)) {
            throw new SocialCredentialsRejectedException('The Mastodon instance refused this access token.');
        }
        if (!$answer->isSuccessful()) {
            throw new SocialNetworkUnreachableException(sprintf('The Mastodon instance answered %d.', $answer->status));
        }

        return $answer;
    }

    /** @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned */
    public function answer(ApiRequestModel $request): ApiResponseModel
    {
        try {
            return $this->api->send($request);
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException(
                'The Mastodon instance could not be reached.',
                previous: $exception,
            );
        }
    }
```

Create `backend/src/Service/Social/MastodonAccountApi.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\MastodonSessionModel;

/** Calls an account's own Mastodon instance with its token; the caller reads the status, as with BlueskySessions. */
final readonly class MastodonAccountApi
{
    public function __construct(
        private MastodonApiClient $mastodon,
        private StoredCredential $storedCredential,
    ) {
    }

    /**
     * @throws SocialCredentialsRejectedException when the stored token no longer opens
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public function get(SocialAccount $account, string $path): ApiResponseModel
    {
        $session = $this->sessionOf($account);

        return $this->mastodon->answer($session->get($session->url($path)));
    }

    /**
     * @throws SocialCredentialsRejectedException when the stored token no longer opens
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public function post(SocialAccount $account, string $path): ApiResponseModel
    {
        $session = $this->sessionOf($account);

        return $this->mastodon->answer($session->post($session->url($path)));
    }

    private function sessionOf(SocialAccount $account): MastodonSessionModel
    {
        return new MastodonSessionModel($account->getServiceUrl(), $this->storedCredential->open($account));
    }
}
```

In `backend/src/Service/Social/TimelineSource/MastodonTimelineSource.php`, replace the constructor and `page()`. Then drop the now unused imports `MastodonSessionModel` and `StoredCredential`, and add `use App\Service\Social\MastodonAccountApi;`:

```php
    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        $answer = MastodonApiClient::accepted(
            $this->mastodon->get($socialFeed->getSocialAccount(), self::pathOf($socialFeed)),
        );
        $statuses = JsonNodeModel::listOf(json_decode($answer->body, true))
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without a timeline.');

        return MastodonTimelinePage::of($statuses);
    }
```

In `backend/src/Service/Social/BlueskySessions.php`, replace `askAs()` and the private `ask()` with the following, and keep `isStale()` as it is:

```php
    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public function askAs(SocialAccount $account, string $path): ApiResponseModel
    {
        $url = $account->getServiceUrl() . $path;

        return $this->sendRetryingStale(
            $account,
            static fn (array $authorization): ApiRequestModel => ApiRequestModel::get($url, $authorization),
        );
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public function postAs(SocialAccount $account, string $path, array $json): ApiResponseModel
    {
        $url = $account->getServiceUrl() . $path;

        return $this->sendRetryingStale(
            $account,
            static fn (array $authorization): ApiRequestModel => ApiRequestModel::postJson($url, $json, $authorization),
        );
    }

    /**
     * @throws SocialCredentialsRejectedException when the session is refused even after the retry
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException on any other answer but a 2xx
     */
    public static function accepted(ApiResponseModel $answer): ApiResponseModel
    {
        if (Response::HTTP_UNAUTHORIZED === $answer->status || self::isStale($answer)) {
            throw new SocialCredentialsRejectedException('Bluesky refused the session of this account.');
        }

        return BlueskyApiClient::accepted($answer);
    }

    /** @param \Closure(array<string, string>): ApiRequestModel $request */
    private function sendRetryingStale(SocialAccount $account, \Closure $request): ApiResponseModel
    {
        $answer = $this->bluesky->answer($request(self::authorization($this->current($account))));

        return self::isStale($answer)
            ? $this->bluesky->answer($request(self::authorization($this->fresh($account))))
            : $answer;
    }

    /** @return array<string, string> */
    private static function authorization(BlueskySessionModel $session): array
    {
        return ['Authorization' => 'Bearer ' . $session->accessJwt];
    }
```

In `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`, replace `itemsOf()`'s body and drop the now unused imports `BlueskyApiClient`, `SocialCredentialsRejectedException` and `Response`:

```php
    private static function itemsOf(ApiResponseModel $answer): array
    {
        return JsonNodeModel::of(BlueskySessions::accepted($answer)->json())->listAt('feed')
            ?? throw new SocialNetworkUnreachableException('Bluesky answered without a feed.');
    }
```

Keep `itemsOf()`'s docblock (`@throws SocialCredentialsRejectedException` stays: the import goes only if PHPStan or cs reports it unused; the docblock still names it, so keep the import).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Service/Refresh`
Expected: PASS, the timeline sources' and verifiers' existing tests included.

- [ ] **Step 5: Gates and commit**

Run: `cd backend && composer check && composer md`

```bash
git add backend/src/Service/Fetch/Model/ApiRequestModel.php backend/src/Service/Social backend/tests/Service/Social
git commit -m "refactor(#1600): one authenticated GET/POST API per network, shared by timelines and post actions"
```

---

### Task 6: Resolving a post on the acting account

**Files:**
- Create: `backend/src/Service/Social/Model/SocialPostModel.php`
- Create: `backend/src/Service/Social/Exception/SocialPostNotFoundException.php`
- Create: `backend/src/Service/Social/PostResolver/PostResolverInterface.php`
- Create: `backend/src/Service/Social/PostResolver/BlueskyPostResolver.php`
- Create: `backend/src/Service/Social/PostResolver/MastodonPostResolver.php`
- Test: `backend/tests/Service/Social/PostResolver/BlueskyPostResolverTest.php`, `backend/tests/Service/Social/PostResolver/MastodonPostResolverTest.php`

**Interfaces:**
- Consumes: `BlueskySessions::askAs()`/`accepted()`, `MastodonAccountApi::get()`, `MastodonApiClient::accepted()` (Task 5)
- Produces:
  - `SocialPostModel::onBluesky(SocialAccount $account, string $uri, string $contentId): self`, `SocialPostModel::onMastodon(SocialAccount $account, string $statusId): self`; public readonly `account`, `postId` (Bluesky at-uri / Mastodon status id on the account's instance), `contentId` (Bluesky cid; null on Mastodon)
  - `#[AutoconfigureTag('app.social_post_resolver')] interface PostResolverInterface { static network(): string; resolve(SocialAccount $account, string $address): SocialPostModel; }`
  - `SocialPostNotFoundException`

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Social/PostResolver/BlueskyPostResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostResolver;

use App\Entity\SocialAccount;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\PostResolver\BlueskyPostResolver;
use App\Service\Social\StoredCredential;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyPostResolverTest extends TestCase
{
    use AssignsEntityIds;

    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string GET_POSTS = 'GET https://pds.example.test/xrpc/app.bsky.feed.getPosts?uris='
        . 'at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.feed.post%2F3abc';

    public function testAPostResolvesToItsUriAndCidOnTheAccount(): void
    {
        $account = self::account();
        $http = new RoutedHttpClient([
            self::SESSION => self::session(),
            self::GET_POSTS => self::json(['posts' => [['uri' => self::POST_URI, 'cid' => 'bafy-cid']]]),
        ]);

        $post = $this->resolver($http)->resolve($account, self::POST_URI);

        self::assertSame($account, $post->account);
        self::assertSame(self::POST_URI, $post->postId);
        self::assertSame('bafy-cid', $post->contentId);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[1]['headers']);
    }

    public function testAPostBlueskyNoLongerHasIsNotFound(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session(), self::GET_POSTS => self::json(['posts' => []])]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->resolver($http)->resolve(self::account(), self::POST_URI);
    }

    public function testARefusedSessionIsARefusedCredential(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => self::session(),
            self::GET_POSTS => self::json(['error' => 'AuthRequired'], Response::HTTP_UNAUTHORIZED),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->resolver($http)->resolve(self::account(), self::POST_URI);
    }

    private function resolver(RoutedHttpClient $http): BlueskyPostResolver
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());
        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));
        $cache = new BlueskySessionCache(
            new ArrayAdapter(),
            new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32))),
        );

        return new BlueskyPostResolver(new BlueskySessions(
            $api,
            new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
            $cache,
        ));
    }

    private static function account(): SocialAccount
    {
        $account = SocialAccounts::bluesky();
        self::assignId($account, 3);

        return $account;
    }

    private static function session(): MockResponse
    {
        return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => 'jwt-1']);
    }

    /** @param array<mixed> $body */
    private static function json(array $body, int $status = Response::HTTP_OK): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
```

Create `backend/tests/Service/Social/PostResolver/MastodonPostResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostResolver;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\PostResolver\MastodonPostResolver;
use App\Service\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonPostResolverTest extends TestCase
{
    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';

    public function testAPostResolvesToItsStatusIdOnTheAccountsInstance(): void
    {
        $account = SocialAccounts::mastodon();
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => '109876']])]);

        $post = $this->resolver($http)->resolve($account, self::STATUS_URL);

        self::assertSame($account, $post->account);
        self::assertSame('109876', $post->postId);
        self::assertNull($post->contentId);
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
    }

    public function testAPostTheInstanceCannotFindIsNotFound(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([])]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    #[DataProvider('foreignStatusIds')]
    public function testAStatusIdThatCouldLeaveItsPathIsAnUnreachableNetwork(string $statusId): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => $statusId]])]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignStatusIds(): iterable
    {
        yield 'parent path' => ['../1'];
        yield 'query' => ['1?x=2'];
        yield 'too long' => [str_repeat('1', 65)];
    }

    public function testAFlakeIdOfAnotherServerIsKept(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => 'AbC123xyz']])]);

        self::assertSame('AbC123xyz', $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL)->postId);
    }

    public function testARefusedTokenIsARefusedCredential(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED])]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    private function resolver(RoutedHttpClient $http): MastodonPostResolver
    {
        $guard = new UrlGuard(new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]), new IpValidator());

        return new MastodonPostResolver(new MastodonAccountApi(
            new MastodonApiClient(FetchWiring::guardedApiClient($http->client(), $guard)),
            new StoredCredential(SocialAccounts::cipher()),
        ));
    }

    /** @param list<array<string, string>> $statuses */
    private static function statuses(array $statuses): MockResponse
    {
        return new MockResponse(json_encode(
            ['accounts' => [], 'statuses' => $statuses, 'hashtags' => []],
            \JSON_THROW_ON_ERROR,
        ));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/PostResolver`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

Create `backend/src/Service/Social/Exception/SocialPostNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

final class SocialPostNotFoundException extends \RuntimeException
{
}
```

Create `backend/src/Service/Social/Model/SocialPostModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;

/** A post as the acting account's server knows it, ready to be acted on (liked now, replied to later). */
final readonly class SocialPostModel
{
    /**
     * @param string      $postId    the Bluesky AT URI, or the status id on the account's own Mastodon instance
     * @param string|null $contentId the Bluesky record's CID, which a like or a reply must name; null on Mastodon
     */
    private function __construct(
        public SocialAccount $account,
        public string $postId,
        public ?string $contentId,
    ) {
    }

    public static function onBluesky(SocialAccount $account, string $uri, string $contentId): self
    {
        return new self($account, $uri, $contentId);
    }

    public static function onMastodon(SocialAccount $account, string $statusId): self
    {
        return new self($account, $statusId, null);
    }
}
```

Create `backend/src/Service/Social/PostResolver/PostResolverInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\PostResolver;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's way from a post address to the post on an account, keyed by its SocialNetwork value. */
#[AutoconfigureTag('app.social_post_resolver')]
interface PostResolverInterface
{
    public static function network(): string;

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function resolve(SocialAccount $account, string $address): SocialPostModel;
}
```

Create `backend/src/Service/Social/PostResolver/BlueskyPostResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\PostResolver;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialPostModel;

final readonly class BlueskyPostResolver implements PostResolverInterface
{
    private const string GET_POSTS = '/xrpc/app.bsky.feed.getPosts?uris=';

    public function __construct(private BlueskySessions $sessions)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $answer = BlueskySessions::accepted($this->sessions->askAs($account, self::GET_POSTS . rawurlencode($address)));
        $contentId = JsonNodeModel::of($answer->json())->first('posts')->string('cid')
            ?? throw new SocialPostNotFoundException('The post is no longer on Bluesky.');

        return SocialPostModel::onBluesky($account, $address, $contentId);
    }
}
```

Create `backend/src/Service/Social/PostResolver/MastodonPostResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\PostResolver;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\SocialPostModel;

/** Asks the account's own instance for the post, so a post from any server gets the id that instance acts on. */
final readonly class MastodonPostResolver implements PostResolverInterface
{
    private const string SEARCH = '/api/v2/search?q=%s&resolve=true&type=statuses&limit=1';
    private const string STATUS_ID = '/^[A-Za-z0-9]{1,64}$/D';

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $answer = MastodonApiClient::accepted($this->mastodon->get($account, sprintf(self::SEARCH, rawurlencode($address))));
        $statusId = JsonNodeModel::of($answer->json())->first('statuses')->string('id')
            ?? throw new SocialPostNotFoundException('The post is no longer on Mastodon.');
        if (1 !== preg_match(self::STATUS_ID, $statusId)) {
            throw new SocialNetworkUnreachableException('The Mastodon instance named a status id it cannot mean.');
        }

        return SocialPostModel::onMastodon($account, $statusId);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social/PostResolver`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `cd backend && composer check && composer md`

```bash
git add backend/src/Service/Social/Model/SocialPostModel.php backend/src/Service/Social/Exception/SocialPostNotFoundException.php \
  backend/src/Service/Social/PostResolver backend/tests/Service/Social/PostResolver
git commit -m "feat(#1600): resolve a post address to the post on the acting account"
```

---

### Task 7: Liking and unliking per network

**Files:**
- Create: `backend/src/Service/Social/Model/SocialLikeResultModel.php`
- Create: `backend/src/Service/Social/PostLiker/PostLikerInterface.php`
- Create: `backend/src/Service/Social/PostLiker/BlueskyPostLiker.php`
- Create: `backend/src/Service/Social/PostLiker/MastodonPostLiker.php`
- Test: `backend/tests/Service/Social/PostLiker/BlueskyPostLikerTest.php`, `backend/tests/Service/Social/PostLiker/MastodonPostLikerTest.php`

**Interfaces:**
- Consumes: `SocialPostModel` (Task 6), `BlueskySessions::postAs()`/`accepted()`, `MastodonAccountApi::post()`, `MastodonApiClient::accepted()` (Task 5)
- Produces: `#[AutoconfigureTag('app.social_post_liker')] interface PostLikerInterface { static network(): string; like(SocialPostModel $post): SocialLikeResultModel; unlike(SocialAccount $account, string $likeId): void; }`
  - `new SocialLikeResultModel(string $likeId)`, public readonly `likeId`: the id the network gave the like, which is the like record's at-uri on Bluesky and the status id on Mastodon. It has no count field yet (ruling 13).

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Social/PostLiker/BlueskyPostLikerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostLiker\BlueskyPostLiker;
use App\Service\Social\StoredCredential;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyPostLikerTest extends TestCase
{
    use AssignsEntityIds;

    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string LIKE_URI = 'at://did:plc:alice/app.bsky.feed.like/3lk2';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.deleteRecord';

    public function testALikeIsALikeRecordInTheAccountsRepoNamingThePost(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => self::session(),
            self::CREATE_RECORD => self::json(['uri' => self::LIKE_URI, 'cid' => 'bafy-like']),
        ]);

        $result = $this->liker($http)->like(SocialPostModel::onBluesky(self::account(), self::POST_URI, 'bafy-post'));

        self::assertSame(self::LIKE_URI, $result->likeId);
        self::assertSame([
            'repo' => 'did:plc:alice',
            'collection' => 'app.bsky.feed.like',
            'record' => [
                '$type' => 'app.bsky.feed.like',
                'subject' => ['uri' => self::POST_URI, 'cid' => 'bafy-post'],
                'createdAt' => '2026-10-13T06:30:00.123Z',
            ],
        ], json_decode($http->requests[1]['body'], true));
    }

    public function testALikeBlueskyDoesNotNameIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session(), self::CREATE_RECORD => self::json([])]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->liker($http)->like(SocialPostModel::onBluesky(self::account(), self::POST_URI, 'bafy-post'));
    }

    public function testAThrottledLikeCarriesItsDelay(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => self::session(),
            self::CREATE_RECORD => new MockResponse('{}', [
                'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
                'response_headers' => ['Retry-After' => '60'],
            ]),
        ]);

        try {
            $this->liker($http)->like(SocialPostModel::onBluesky(self::account(), self::POST_URI, 'bafy-post'));
            self::fail('A 429 must not read as a like.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function testUnlikingDeletesTheLikeRecordByItsKey(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session(), self::DELETE_RECORD => self::json([])]);

        $this->liker($http)->unlike(self::account(), self::LIKE_URI);

        self::assertSame(
            ['repo' => 'did:plc:alice', 'collection' => 'app.bsky.feed.like', 'rkey' => '3lk2'],
            json_decode($http->requests[1]['body'], true),
        );
    }

    private function liker(RoutedHttpClient $http): BlueskyPostLiker
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());
        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));
        $sessions = new BlueskySessions(
            $api,
            new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
            new BlueskySessionCache(
                new ArrayAdapter(),
                new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32))),
            ),
        );

        return new BlueskyPostLiker(
            $sessions,
            new MockClock(new \DateTimeImmutable('2026-10-13T08:30:00.123+02:00')),
        );
    }

    private static function account(): SocialAccount
    {
        $account = SocialAccounts::bluesky();
        self::assignId($account, 3);

        return $account;
    }

    private static function session(): MockResponse
    {
        return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => 'jwt-1']);
    }

    /** @param array<mixed> $body */
    private static function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
```

Create `backend/tests/Service/Social/PostLiker/MastodonPostLikerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostLiker;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostLiker\MastodonPostLiker;
use App\Service\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonPostLikerTest extends TestCase
{
    private const string FAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/favourite';
    private const string UNFAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/unfavourite';

    public function testALikeFavouritesTheStatusAndKeepsItsIdToUndoIt(): void
    {
        $http = new RoutedHttpClient([self::FAVOURITE => new MockResponse('{"id":"110","favourited":true}')]);

        $result = $this->liker($http)->like(SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110'));

        self::assertSame('110', $result->likeId);
        self::assertSame([self::FAVOURITE], array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['url'],
            $http->requests,
        ));
    }

    public function testAStatusGoneBeforeTheLikeIsNotFound(): void
    {
        $http = new RoutedHttpClient([
            self::FAVOURITE => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->liker($http)->like(SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110'));
    }

    public function testUnlikingUnfavouritesTheStatus(): void
    {
        $http = new RoutedHttpClient([self::UNFAVOURITE => new MockResponse('{"id":"110","favourited":false}')]);

        $this->liker($http)->unlike(SocialAccounts::mastodon(), '110');

        self::assertCount(1, $http->requests);
    }

    public function testUnlikingAStatusThatIsGoneSucceeds(): void
    {
        $http = new RoutedHttpClient([
            self::UNFAVOURITE => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);

        $this->liker($http)->unlike(SocialAccounts::mastodon(), '110');

        self::assertCount(1, $http->requests);
    }

    public function testAnUnlikeTheInstanceFailsIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([
            self::UNFAVOURITE => new MockResponse('{}', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR]),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->liker($http)->unlike(SocialAccounts::mastodon(), '110');
    }

    private function liker(RoutedHttpClient $http): MastodonPostLiker
    {
        $guard = new UrlGuard(new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]), new IpValidator());

        return new MastodonPostLiker(new MastodonAccountApi(
            new MastodonApiClient(FetchWiring::guardedApiClient($http->client(), $guard)),
            new StoredCredential(SocialAccounts::cipher()),
        ));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/PostLiker`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

Create `backend/src/Service/Social/Model/SocialLikeResultModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What a network answered to a like; a fresh like count joins the id here when counts arrive (ruling 13). */
final readonly class SocialLikeResultModel
{
    /** @param string $likeId the id the network gave the like, which PostLikerInterface::unlike() takes back */
    public function __construct(public string $likeId)
    {
    }
}
```

Create `backend/src/Service/Social/PostLiker/PostLikerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's like, keyed by its SocialNetwork value. */
#[AutoconfigureTag('app.social_post_liker')]
interface PostLikerInterface
{
    public static function network(): string;

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function like(SocialPostModel $post): SocialLikeResultModel;

    /**
     * A like the network no longer has counts as taken back.
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function unlike(SocialAccount $account, string $likeId): void;
}
```

Create `backend/src/Service/Social/PostLiker/BlueskyPostLiker.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use Psr\Clock\ClockInterface;

final readonly class BlueskyPostLiker implements PostLikerInterface
{
    private const string CREATE_RECORD = '/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = '/xrpc/com.atproto.repo.deleteRecord';
    private const string LIKE_COLLECTION = 'app.bsky.feed.like';

    public function __construct(
        private BlueskySessions $sessions,
        private ClockInterface $clock,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        $answer = $this->sessions->postAs($post->account, self::CREATE_RECORD, [
            'repo' => $post->account->getIdentity(),
            'collection' => self::LIKE_COLLECTION,
            'record' => [
                '$type' => self::LIKE_COLLECTION,
                'subject' => ['uri' => $post->postId, 'cid' => $post->contentId],
                'createdAt' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            ],
        ]);

        $likeId = JsonNodeModel::of(BlueskySessions::accepted($answer)->json())->string('uri')
            ?? throw new SocialNetworkUnreachableException('Bluesky did not name the like it created.');

        return new SocialLikeResultModel($likeId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        BlueskySessions::accepted($this->sessions->postAs($account, self::DELETE_RECORD, [
            'repo' => $account->getIdentity(),
            'collection' => self::LIKE_COLLECTION,
            'rkey' => substr((string) strrchr($likeId, '/'), 1),
        ]));
    }
}
```

Create `backend/src/Service/Social/PostLiker/MastodonPostLiker.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use Symfony\Component\HttpFoundation\Response;

final readonly class MastodonPostLiker implements PostLikerInterface
{
    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        $answer = $this->mastodon->post($post->account, self::statusAction($post->postId, 'favourite'));
        if (Response::HTTP_NOT_FOUND === $answer->status) {
            throw new SocialPostNotFoundException('The post is no longer on Mastodon.');
        }
        MastodonApiClient::accepted($answer);

        return new SocialLikeResultModel($post->postId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        $answer = $this->mastodon->post($account, self::statusAction($likeId, 'unfavourite'));
        if (Response::HTTP_NOT_FOUND === $answer->status) {
            return;
        }
        MastodonApiClient::accepted($answer);
    }

    private static function statusAction(string $statusId, string $action): string
    {
        return '/api/v1/statuses/' . rawurlencode($statusId) . '/' . $action;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social/PostLiker`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `cd backend && composer check && composer md`

```bash
git add backend/src/Service/Social/Model/SocialLikeResultModel.php backend/src/Service/Social/PostLiker backend/tests/Service/Social/PostLiker
git commit -m "feat(#1600): like and unlike a resolved post on Bluesky and Mastodon"
```

---

### Task 8: Finding the post, storing the like, mapping the problems

**Files:**
- Create: `backend/src/Service/Social/Exception/NotASocialPostException.php`
- Create: `backend/src/Service/Social/SocialPostFinder.php`
- Create: `backend/src/Service/Social/SocialLikes.php`
- Create: `backend/src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php` (unreachable arm moves out)
- Create: `backend/tests/Support/FakeMastodonNetwork.php`
- Test: `backend/tests/Service/Social/SocialLikesTest.php`, `backend/tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php`

**Interfaces:**
- Consumes: Tasks 1–7
- Produces:
  - `SocialPostFinder::find(User $user, Entry $entry): SocialPostModel` — throws `NotASocialPostException`, `NoSocialAccountForNetworkException`, `SocialAccountNeedsReconnectException`, `SocialPostNotFoundException`, `SocialCredentialsRejectedException`, `SocialNetworkUnreachableException`
  - `SocialLikes::like(User $user, Entry $entry): void`, `SocialLikes::unlike(User $user, Entry $entry): void`
  - Problem types: `social_post_unsupported` (409), `social_account_missing` (409), `social_post_not_found` (404), `social_network_throttled` (503 + `Retry-After`), `social_network_unreachable` (502, unchanged)

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Support/FakeMastodonNetwork.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostLiker\PostLikerInterface;
use App\Service\Social\PostResolver\PostResolverInterface;

/** A Mastodon that resolves every address to status 110, records each call and can be told to fail. */
final class FakeMastodonNetwork implements PostResolverInterface, PostLikerInterface
{
    public const string STATUS_ID = '110';

    /** @var list<string> */
    public array $calls = [];

    private ?\Throwable $failure = null;

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function failWith(\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $this->record('resolve ' . $address);

        return SocialPostModel::onMastodon($account, self::STATUS_ID);
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        $this->record('like ' . $post->postId);

        return new SocialLikeResultModel($post->postId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        $this->record('unlike ' . $likeId);
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
```

Create `backend/tests/Service/Social/SocialLikesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialLikeRepository;
use App\Service\Social\ActingAccountChooser;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\SocialLikes;
use App\Service\Social\SocialPostFinder;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SocialLikesTest extends DbTestCase
{
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';

    private FakeMastodonNetwork $mastodon;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->owner = $this->user('social-likes-service@example.test');
    }

    public function testALikeResolvesThePostLikesItAndRemembersTheLikeId(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn('https://mastodon.social/@Gargron.rss', self::STATUS_URL);

        $this->likes()->like($this->owner, $entry);

        self::assertSame(['resolve ' . self::STATUS_URL, 'like 110'], $this->mastodon->calls);
        $like = $this->likeRepository()->findOneFor($this->owner, $entry);
        self::assertNotNull($like);
        self::assertSame($account, $like->getSocialAccount());
        self::assertSame('110', $like->getRemoteId());
        self::assertEquals(new \DateTimeImmutable('2026-10-13 06:30:00'), $like->getLikedAt());
    }

    public function testLikingALikedPostAgainCallsNoNetwork(): void
    {
        $this->mastodonAccount();
        $entry = $this->entryOn('https://mastodon.social/@Gargron.rss', self::STATUS_URL);

        $this->likes()->like($this->owner, $entry);
        $this->likes()->like($this->owner, $entry);

        self::assertCount(2, $this->mastodon->calls);
    }

    public function testUnlikingTakesTheLikeBackWithItsOwnAccountAndForgetsIt(): void
    {
        $this->mastodonAccount();
        $entry = $this->entryOn('https://mastodon.social/@Gargron.rss', self::STATUS_URL);
        $this->likes()->like($this->owner, $entry);

        $this->likes()->unlike($this->owner, $entry);

        self::assertSame('unlike 110', $this->mastodon->calls[2]);
        self::assertNull($this->likeRepository()->findOneFor($this->owner, $entry));
    }

    public function testUnlikingAPostNeverLikedCallsNoNetwork(): void
    {
        $this->mastodonAccount();

        $this->likes()->unlike($this->owner, $this->entryOn('https://mastodon.social/@Gargron.rss', self::STATUS_URL));

        self::assertSame([], $this->mastodon->calls);
    }

    public function testAnEntryThatIsNoPostIsRefusedBeforeAnyCall(): void
    {
        $this->mastodonAccount();

        try {
            $this->likes()->like($this->owner, $this->entryOn('https://example.com/feed.xml', self::STATUS_URL));
            self::fail('An ordinary article must not be liked.');
        } catch (NotASocialPostException) {
            self::assertSame([], $this->mastodon->calls);
        }
    }

    public function testAPostOnANetworkWithoutAnAccountIsRefused(): void
    {
        $this->expectException(NoSocialAccountForNetworkException::class);

        $this->likes()->like($this->owner, $this->entryOn('https://mastodon.social/@Gargron.rss', self::STATUS_URL));
    }

    public function testAnAccountThatNeedsReconnectingIsRefusedBeforeAnyCall(): void
    {
        $this->mastodonAccount()->markNeedsReconnect();
        $this->entityManager->flush();

        try {
            $this->likes()->like($this->owner, $this->entryOn('https://mastodon.social/@Gargron.rss', self::STATUS_URL));
            self::fail('An account that needs reconnecting must not act.');
        } catch (SocialAccountNeedsReconnectException) {
            self::assertSame([], $this->mastodon->calls);
        }
    }

    public function testARefusedCredentialMarksTheAccountAndStoresNoLike(): void
    {
        $accountId = $this->mastodonAccount()->requireId();
        $entry = $this->entryOn('https://mastodon.social/@Gargron.rss', self::STATUS_URL);
        $this->mastodon->failWith(new SocialCredentialsRejectedException('The Mastodon instance refused this access token.'));

        try {
            $this->likes()->like($this->owner, $entry);
            self::fail('A refused credential must reach the caller.');
        } catch (SocialCredentialsRejectedException) {
            $this->entityManager->clear();
            self::assertSame(
                SocialAccountStatus::NeedsReconnect,
                $this->entityManager->find(SocialAccount::class, $accountId)?->getStatus(),
            );
            self::assertSame(0, $this->likeRepository()->count([]));
        }
    }

    public function testAPostThatIsGoneStoresNoLike(): void
    {
        $this->mastodonAccount();
        $this->mastodon->failWith(new SocialPostNotFoundException('The post is no longer on Mastodon.'));

        try {
            $this->likes()->like($this->owner, $this->entryOn('https://mastodon.social/@Gargron.rss', self::STATUS_URL));
            self::fail('A gone post must not read as liked.');
        } catch (SocialPostNotFoundException) {
            self::assertSame(0, $this->likeRepository()->count([]));
        }
    }

    private function mastodonAccount(): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    private function entryOn(string $feedUrl, string $postUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-10-13 10:00:00');
        $entry = new Entry($feed, 'tag:mastodon.social,2026:110', $postUrl, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function likes(): SocialLikes
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $guard = new ActingAccountGuard($this->entityManager);
        $mastodon = $this->mastodon;

        return new SocialLikes(
            $this->likeRepository(),
            new SocialPostFinder(
                new ActingAccountChooser(SocialFeeds::repository($this->entityManager), $accounts),
                $guard,
                new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]),
            ),
            $guard,
            new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]),
            $this->entityManager,
            new MockClock(new \DateTimeImmutable('2026-10-13T08:30:00+02:00')),
        );
    }

    private function likeRepository(): SocialLikeRepository
    {
        /** @var SocialLikeRepository $repository */
        $repository = $this->entityManager->getRepository(SocialLike::class);

        return $repository;
    }
}
```

Create `backend/tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\SocialInteractionProblems;
use App\Http\Problem\ExceptionProblems\SocialProblems;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class SocialInteractionProblemsTest extends TestCase
{
    #[DataProvider('problems')]
    public function testEachInteractionFailureHasItsProblem(\Throwable $exception, string $type, int $status): void
    {
        $resolved = (new SocialInteractionProblems())->resolve($exception);

        self::assertSame($type, $resolved?->problem->type);
        self::assertSame($status, $resolved->problem->status);
    }

    /** @return iterable<string, array{\Throwable, string, int}> */
    public static function problems(): iterable
    {
        yield 'not a post' => [new NotASocialPostException('x'), 'social_post_unsupported', Response::HTTP_CONFLICT];
        yield 'no account' => [
            new NoSocialAccountForNetworkException('x'),
            'social_account_missing',
            Response::HTTP_CONFLICT,
        ];
        yield 'post gone' => [new SocialPostNotFoundException('x'), 'social_post_not_found', Response::HTTP_NOT_FOUND];
        yield 'throttled' => [
            new SocialNetworkThrottledException('x', 90),
            'social_network_throttled',
            Response::HTTP_SERVICE_UNAVAILABLE,
        ];
        yield 'unreachable' => [
            new SocialNetworkUnreachableException('x'),
            'social_network_unreachable',
            Response::HTTP_BAD_GATEWAY,
        ];
    }

    public function testAThrottleNamesItsDelayOnlyWhenTheNetworkGaveOne(): void
    {
        $problems = new SocialInteractionProblems();

        self::assertSame(
            ['Retry-After' => '90'],
            $problems->resolve(new SocialNetworkThrottledException('x', 90))?->headers,
        );
        self::assertSame([], $problems->resolve(new SocialNetworkThrottledException('x'))?->headers);
    }

    public function testTheAccountProblemsNoLongerClaimAnUnreachableNetworkAndOthersPassThrough(): void
    {
        self::assertNull((new SocialProblems())->resolve(new SocialNetworkUnreachableException('x')));
        self::assertNull((new SocialInteractionProblems())->resolve(new \RuntimeException('x')));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/SocialLikesTest.php tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

Create `backend/src/Service/Social/Exception/NotASocialPostException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

final class NotASocialPostException extends \RuntimeException
{
}
```

Create `backend/src/Service/Social/SocialPostFinder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostResolver\PostResolverInterface;
use App\Service\Social\Support\SocialPostAddress;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The post an entry is, found on the account that acts on it: where every post interaction starts. */
final readonly class SocialPostFinder
{
    public function __construct(
        private ActingAccountChooser $accounts,
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_resolver', defaultIndexMethod: 'network')]
        private ContainerInterface $resolvers,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function find(User $user, Entry $entry): SocialPostModel
    {
        $address = SocialPostAddress::of($entry)
            ?? throw new NotASocialPostException('This entry is not a Bluesky or Mastodon post.');
        $account = $this->accounts->accountFor($user, $entry, $address->network);
        $resolver = $this->resolverOn($address->network);

        return $this->guard->actAs(
            $account,
            static fn (): SocialPostModel => $resolver->resolve($account, $address->address),
        );
    }

    private function resolverOn(SocialNetwork $network): PostResolverInterface
    {
        $resolver = $this->resolvers->get($network->value);
        \assert($resolver instanceof PostResolverInterface);

        return $resolver;
    }
}
```

Create `backend/src/Service/Social/SocialLikes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\SocialLikeRepository;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\PostLiker\PostLikerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class SocialLikes
{
    public function __construct(
        private SocialLikeRepository $likes,
        private SocialPostFinder $posts,
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_liker', defaultIndexMethod: 'network')]
        private ContainerInterface $likers,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function like(User $user, Entry $entry): void
    {
        if (null !== $this->likes->findOneFor($user, $entry)) {
            return;
        }
        $post = $this->posts->find($user, $entry);
        $liker = $this->likerOf($post->account);
        $result = $this->guard->actAs($post->account, static fn (): SocialLikeResultModel => $liker->like($post));

        $this->entityManager->persist(new SocialLike(
            $user,
            $entry,
            $post->account,
            $result->likeId,
            $this->clock->now()->setTimezone(new \DateTimeZone('UTC')),
        ));
        $this->entityManager->flush();
    }

    public function unlike(User $user, Entry $entry): void
    {
        $like = $this->likes->findOneFor($user, $entry);
        if (null === $like) {
            return;
        }
        $account = $like->getSocialAccount();
        $liker = $this->likerOf($account);
        $this->guard->actAs($account, static function () use ($liker, $account, $like): void {
            $liker->unlike($account, $like->getRemoteId());
        });

        $this->entityManager->remove($like);
        $this->entityManager->flush();
    }

    private function likerOf(SocialAccount $account): PostLikerInterface
    {
        $liker = $this->likers->get($account->getNetwork()->value);
        \assert($liker instanceof PostLikerInterface);

        return $liker;
    }
}
```

Create `backend/src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use Symfony\Component\HttpFoundation\Response;

/** What acting on a network can end in. The throttled arm must stay above its parent, the unreachable one. */
final readonly class SocialInteractionProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof NotASocialPostException => new ResolvedProblem(new ApiProblem(
                'social_post_unsupported',
                'Not a Bluesky or Mastodon post',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof NoSocialAccountForNetworkException => new ResolvedProblem(new ApiProblem(
                'social_account_missing',
                'No account on this network',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof SocialPostNotFoundException => new ResolvedProblem(new ApiProblem(
                'social_post_not_found',
                'Post no longer available',
                Response::HTTP_NOT_FOUND,
                $exception->getMessage(),
            )),
            $exception instanceof SocialNetworkThrottledException => self::throttled($exception),
            $exception instanceof SocialNetworkUnreachableException => new ResolvedProblem(new ApiProblem(
                'social_network_unreachable',
                'The network could not be reached',
                Response::HTTP_BAD_GATEWAY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }

    private static function throttled(SocialNetworkThrottledException $exception): ResolvedProblem
    {
        $retryAfter = $exception->retryAfterSeconds;

        return new ResolvedProblem(
            new ApiProblem(
                'social_network_throttled',
                'The network asked for fewer requests',
                Response::HTTP_SERVICE_UNAVAILABLE,
                $exception->getMessage(),
            ),
            null === $retryAfter ? [] : ['Retry-After' => (string) $retryAfter],
        );
    }
}
```

In `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php`, delete the `$exception instanceof SocialNetworkUnreachableException => …` arm and its `use App\Service\Social\Exception\SocialNetworkUnreachableException;` line.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Http/Problem tests/Controller/Api/SocialAccountControllerTest.php tests/Controller/Api/SocialImportControllerTest.php`
Expected: PASS. The social controllers' existing `social_network_unreachable` expectations now come from the new mapper.

- [ ] **Step 5: Gates and commit**

Run: `cd backend && composer check && composer md && composer tramp`
Expected: clean. `ServiceModuleCycleRule` reports nothing (Global Constraints, module placement).

```bash
git add backend/src/Service/Social backend/src/Http/Problem/ExceptionProblems backend/tests/Support/FakeMastodonNetwork.php \
  backend/tests/Service/Social/SocialLikesTest.php backend/tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php
git commit -m "feat(#1600): like and unlike an entry's post through the acting account, with typed problems"
```

---

### Task 9: `PUT`/`DELETE /api/entries/{id}/like`

**Files:**
- Create: `backend/src/Controller/Api/EntryLikeController.php`
- Create: `backend/src/Http/SocialLikeJson.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Controller/Api/EntryLikeControllerTest.php`

**Interfaces:**
- Consumes: `SocialLikes` (Task 8), `EntryListRepository::getRowForUser()`, `RateLimitGuard::enforceForUser()`
- Produces: routes `api_entries_like` (`PUT`) and `api_entries_unlike` (`DELETE`) on `/api/entries/{id}/like`, answering `{"isLiked": true|false}`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Controller/Api/EntryLikeControllerTest.php`. It drives the real container down to the HTTP client, as `TimelineReaderWiringTest` does:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\DnsResolver\NativeDnsResolver;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntryLikeControllerTest extends ApiTestCase
{
    private const string POST_URL = 'https://mastodon.social/@Gargron/110';
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';
    private const string FAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/favourite';
    private const string UNFAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/unfavourite';

    private RoutedHttpClient $http;

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $client = static::createClient();

        $client->request('PUT', '/api/entries/1/like');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testALikeReachesTheOriginAndTheEntryReadsAsLiked(): void
    {
        $client = $this->clientRouting([self::SEARCH => self::found(), self::FAVOURITE => self::status()]);
        $user = $this->signIn($client, 'like-put@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['isLiked' => true], $this->payload($client));
        self::assertSame([self::SEARCH, self::FAVOURITE], $this->routesAsked());
        self::assertSame(['network' => 'mastodon', 'isLiked' => true], $this->socialOf($client, $entryId));
    }

    public function testASecondLikeCallsNoNetwork(): void
    {
        $client = $this->clientRouting([self::SEARCH => self::found(), self::FAVOURITE => self::status()]);
        $user = $this->signIn($client, 'like-twice@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));
        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        self::assertSame(['isLiked' => true], $this->payload($client));
        self::assertCount(2, $this->http->requests);
    }

    public function testAnUnlikeTakesTheLikeBack(): void
    {
        $client = $this->clientRouting([
            self::SEARCH => self::found(),
            self::FAVOURITE => self::status(),
            self::UNFAVOURITE => self::status(),
        ]);
        $user = $this->signIn($client, 'like-delete@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);
        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        $client->request('DELETE', sprintf('/api/entries/%d/like', $entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['isLiked' => false], $this->payload($client));
        self::assertSame([self::SEARCH, self::FAVOURITE, self::UNFAVOURITE], $this->routesAsked());
        self::assertSame(['network' => 'mastodon', 'isLiked' => false], $this->socialOf($client, $entryId));
    }

    public function testUnlikingAPostNeverLikedCallsNoNetwork(): void
    {
        $client = $this->clientRouting([]);
        $user = $this->signIn($client, 'like-delete-none@example.test');
        $this->mastodonAccountOf($user);

        $client->request('DELETE', sprintf('/api/entries/%d/like', $this->mastodonPostFor($user)));

        self::assertSame(['isLiked' => false], $this->payload($client));
        self::assertSame([], $this->http->requests);
    }

    public function testAnOrdinaryArticleIsNoPostToLike(): void
    {
        $client = $this->clientRouting([]);
        $user = $this->signIn($client, 'like-plain@example.test');
        $this->mastodonAccountOf($user);
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());
        $entryId = $fixtures->post(
            $fixtures->subscribe($user, NetworkFeedSubscriptions::PLAIN),
            self::POST_URL,
            '2026-10-12T10:00:00Z',
        )->requireId();

        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_post_unsupported', $this->payload($client)['type']);
        self::assertNull($this->socialOf($client, $entryId));
    }

    public function testAPostOnANetworkWithoutAnAccountIsRefusedAndShowsNoButton(): void
    {
        $client = $this->clientRouting([]);
        $user = $this->signIn($client, 'like-no-account@example.test');
        $entryId = $this->mastodonPostFor($user);

        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_account_missing', $this->payload($client)['type']);
        self::assertNull($this->socialOf($client, $entryId));
    }

    public function testAnotherUsersEntryReadsAsMissing(): void
    {
        $client = $this->clientRouting([]);
        $stranger = $this->factory()->create('like-stranger@example.test');
        $entryId = $this->mastodonPostFor($stranger);
        $user = $this->signIn($client, 'like-own@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    public function testARefusedTokenMarksTheAccountAsNeedingAReconnect(): void
    {
        $client = $this->clientRouting([
            self::SEARCH => new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]),
        ]);
        $user = $this->signIn($client, 'like-refused@example.test');
        $accountId = $this->mastodonAccountOf($user)->requireId();

        $client->request('PUT', sprintf('/api/entries/%d/like', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_credentials_rejected', $this->payload($client)['type']);
        $this->entityManager()->clear();
        self::assertSame(
            SocialAccountStatus::NeedsReconnect,
            $this->entityManager()->find(SocialAccount::class, $accountId)?->getStatus(),
        );
    }

    public function testAPostTheInstanceCannotFindIsNoLongerAvailable(): void
    {
        $client = $this->clientRouting([self::SEARCH => new MockResponse('{"statuses":[]}')]);
        $user = $this->signIn($client, 'like-gone@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', sprintf('/api/entries/%d/like', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame('social_post_not_found', $this->payload($client)['type']);
        self::assertSame('Post no longer available', $this->payload($client)['title']);
    }

    /** @param array<string, MockResponse> $routes */
    private function clientRouting(array $routes): KernelBrowser
    {
        $client = static::createClient();
        // KernelBrowser rebuilds the container after every request, which would discard the routed client.
        $client->disableReboot();
        $this->http = new RoutedHttpClient($routes);
        $container = self::getContainer();
        $container->set('http_client.uri_template', $this->http->client());
        $container->set(NativeDnsResolver::class, new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]));

        return $client;
    }

    private function signIn(KernelBrowser $client, string $email): User
    {
        $user = $this->factory()->create($email);
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokens->create($user));

        return $user;
    }

    private function mastodonAccountOf(User $user): SocialAccount
    {
        /** @var InstanceSecretCipher $instanceCipher */
        $instanceCipher = self::getContainer()->get(InstanceSecretCipher::class);
        $account = new SocialAccount(
            $user,
            SocialNetwork::Mastodon,
            'alice@mastodon.example',
            '@alice@mastodon.example',
            'https://mastodon.example',
            (new SocialCredentialCipher($instanceCipher))->seal($user->requireId(), SocialAccounts::ACCESS_TOKEN),
            '7890',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function mastodonPostFor(User $user): int
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());

        return $fixtures->post(
            $fixtures->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE),
            self::POST_URL,
            '2026-10-12T10:00:00Z',
        )->requireId();
    }

    /** @return list<string> */
    private function routesAsked(): array
    {
        return array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['url'],
            $this->http->requests,
        );
    }

    private function socialOf(KernelBrowser $client, int $entryId): mixed
    {
        $client->request('GET', sprintf('/api/entries/%d', $entryId));
        $entry = $this->payload($client)['entry'];
        self::assertIsArray($entry);

        return $entry['social'];
    }
}
```

The `socialOf()` assertions need Task 10. Until then they fail on a missing `social` key; that is expected at Step 4 of this task (see below).

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryLikeControllerTest.php`
Expected: FAIL, `405`/`404` for `PUT /api/entries/{id}/like` (no route).

- [ ] **Step 3: Implement**

Add to `backend/config/packages/rate_limiter.yaml`, after the `social_import` limiter:

```yaml
        # Per user: each like or unlike spends two or three calls on the user's own network account.
        social_like:
            policy: 'sliding_window'
            limit: 60
            interval: '5 minutes'
            cache_pool: cache.rate_limiter
```

Create `backend/src/Http/SocialLikeJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

final class SocialLikeJson
{
    /** @return array{isLiked: true} */
    public static function liked(): array
    {
        return ['isLiked' => true];
    }

    /** @return array{isLiked: false} */
    public static function unliked(): array
    {
        return ['isLiked' => false];
    }
}
```

Create `backend/src/Controller/Api/EntryLikeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\SocialLikeJson;
use App\Repository\EntryListRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\SocialLikes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryLikeController
{
    public function __construct(
        private EntryListRepository $entryList,
        private SocialLikes $socialLikes,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialLikeLimiter,
    ) {
    }

    #[Route('/{id}/like', name: 'api_entries_like', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function like(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getRowForUser($user->requireId(), $id)->entry;
        $this->rateLimitGuard->enforceForUser($this->socialLikeLimiter, $user);

        $this->socialLikes->like($user, $entry);

        return new JsonResponse(SocialLikeJson::liked());
    }

    #[Route('/{id}/like', name: 'api_entries_unlike', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function unlike(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getRowForUser($user->requireId(), $id)->entry;
        $this->rateLimitGuard->enforceForUser($this->socialLikeLimiter, $user);

        $this->socialLikes->unlike($user, $entry);

        return new JsonResponse(SocialLikeJson::unliked());
    }
}
```

- [ ] **Step 4: Run the test**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryLikeControllerTest.php`
Expected: the tests without `socialOf()` PASS; the five that read `social` fail on `Undefined array key "social"` until Task 10. Commit anyway: Task 10 lands in the same PR, and its Step 4 reruns this file.

- [ ] **Step 5: Gates and commit**

Run: `cd backend && composer check && composer md`
Expected: clean. `ThinControllerRule` and `ControllerMutatesNoEntityRule` stay silent: the controller reads `->entry` off a repository row and calls no entity method.

```bash
git add backend/src/Controller/Api/EntryLikeController.php backend/src/Http/SocialLikeJson.php \
  backend/config/packages/rate_limiter.yaml backend/tests/Controller/Api/EntryLikeControllerTest.php
git commit -m "feat(#1600): PUT and DELETE /api/entries/{id}/like"
```

---

### Task 10: `social` on every entry the reader shows

**Files:**
- Create: `backend/src/Repository/EntryListRowSocial.php`
- Modify: `backend/src/Repository/EntryListRowEnrichment.php`
- Modify: `backend/src/Repository/EntryListRowEnricher.php`
- Modify: `backend/src/Http/EntryJson.php`
- Test: `backend/tests/Repository/EntryListRowEnricherTest.php`, `backend/tests/Http/EntryJsonTest.php`, `backend/tests/Controller/Api/EntryLikeControllerTest.php` (rerun)

**Interfaces:**
- Consumes: `SocialAccountRepository::networksOf()` (Task 2), `SocialLikeRepository::likedEntryIdsAmong()` (Task 1), `SocialPostAddress::of()` (Task 3)
- Produces:
  - `new EntryListRowSocial(SocialNetwork $network, bool $isLiked)`
  - `EntryListRowEnrichment::$social: ?EntryListRowSocial` (last constructor parameter, default `null`)
  - JSON key `social: {network: 'bluesky'|'mastodon', isLiked: bool}|null` on `EntryJson::listRow()`, between `savedSearches` and `duplicates`; `detail()` and every duplicate inherit it

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Http/EntryJsonTest.php`, update the key list in `testListRowKeepsItsShapeAndCarriesTheEnrichment()` so its last line reads:

```php
                'shortPlayerUrl', 'titleDerived', 'savedSearches', 'social', 'duplicates',
```

Then append these tests, and add `use App\Enum\SocialNetwork;` and `use App\Repository\EntryListRowSocial;`:

```php
    public function testAnEntryWithoutASocialStateSaysSo(): void
    {
        self::assertNull(EntryJson::listRow($this->row($this->entryTitled('Article')))['social']);
    }

    public function testASocialPostCarriesItsNetworkAndWhetherTheUserLikedIt(): void
    {
        $row = $this->row(
            $this->entryTitled('Post'),
            new EntryListRowEnrichment(social: new EntryListRowSocial(SocialNetwork::Bluesky, true)),
        );

        self::assertSame(['network' => 'bluesky', 'isLiked' => true], EntryJson::detail($row)['social']);
    }
```

Append to `backend/tests/Repository/EntryListRowEnricherTest.php`. Change the query-count expectation in `testAPageCostsOneQueryPerLookup()` from 4 lookups to 5, with `social_account` in the table list:

```php
        self::assertCount(5, $recorder->queries(), $queries);
        foreach (['entry_category', 'saved_search_entry', 'link_card', 'share_enrichment', 'social_account'] as $table) {
```

Add the `use` lines `App\Entity\SocialAccount`, `App\Entity\SocialLike`, `App\Enum\SocialNetwork`, `App\Repository\EntryListRowSocial`, `App\Tests\Support\SocialAccounts` and `App\Tests\Support\SocialFeeds`, then add:

```php
    public function testAPostOnANetworkTheUserHasAnAccountOnCarriesItsSocialStateAndLike(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user));
        $mastodon = $this->feedAt('https://mastodon.social/@Gargron.rss');
        $liked = $this->postOn($mastodon, 'https://mastodon.social/@Gargron/1');
        $unliked = $this->postOn($mastodon, 'https://mastodon.social/@Gargron/2');
        $article = $this->entry('article');
        $this->likeOf($account, $liked);

        $rows = $this->enrich([$this->row($liked, [$this->row($unliked)]), $this->row($article)]);

        self::assertEquals(new EntryListRowSocial(SocialNetwork::Mastodon, true), $rows[0]->enrichment->social);
        self::assertEquals(
            new EntryListRowSocial(SocialNetwork::Mastodon, false),
            $rows[0]->duplicates[0]->enrichment->social,
        );
        self::assertNull($rows[1]->enrichment->social);
    }

    public function testWithoutAnAccountOnItsNetworkAPostHasNoSocialState(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
        $post = $this->postOn($this->feedAt('https://mastodon.social/@Gargron.rss'), 'https://mastodon.social/@Gargron/3');

        self::assertNull($this->enrich([$this->row($post)])[0]->enrichment->social);
    }

    public function testAPageWithSocialPostsCostsOneQueryMoreForTheLikes(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user));
        $post = $this->postOn($this->feedAt('https://mastodon.social/@Gargron.rss'), 'https://mastodon.social/@Gargron/4');
        $recorder = $this->recorder();

        $this->enrich([$this->row($post), $this->row($this->entry('plain'))]);

        self::assertCount(6, $recorder->queries(), implode("\n", $recorder->queries()));
        self::assertCount(1, $recorder->queriesMatching('social_like'));
    }

    private function feedAt(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function postOn(Feed $feed, string $url): Entry
    {
        $now = new \DateTimeImmutable('2026-10-13T00:00:00Z');
        $entry = new Entry($feed, $url, $url, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function likeOf(SocialAccount $account, Entry $entry): void
    {
        $this->entityManager->persist(
            new SocialLike($this->user, $entry, $account, '1', new \DateTimeImmutable('2026-10-13 10:00:00')),
        );
        $this->entityManager->flush();
    }
```

`testAPageCostsOneQueryPerLookup()` creates a recorder after the rows and before `enrich()`. A user with no account still costs the `social_account` lookup and no `social_like` query. That is the fifth query.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Http/EntryJsonTest.php tests/Repository/EntryListRowEnricherTest.php`
Expected: FAIL, `EntryListRowSocial` not found.

- [ ] **Step 3: Implement**

Create `backend/src/Repository/EntryListRowSocial.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\SocialNetwork;

/** A row's post on a network the reader has an account on, and whether they liked it there. */
final readonly class EntryListRowSocial
{
    public function __construct(
        public SocialNetwork $network,
        public bool $isLiked,
    ) {
    }
}
```

In `backend/src/Repository/EntryListRowEnrichment.php`, add as the last constructor parameter:

```php
        /** null for an article, or for a post on a network the reader has no account on */
        public ?EntryListRowSocial $social = null,
```

Replace `backend/src/Repository/EntryListRowEnricher.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Enum\SocialNetwork;
use App\Service\Social\Support\SocialPostAddress;

/**
 * Gives a page of rows their categories, saved searches, link cards, pending flag and social state: one query per
 * lookup.
 */
final readonly class EntryListRowEnricher
{
    public function __construct(
        private EntryCategoryRepository $categories,
        private SavedSearchEntryRepository $memberships,
        private LinkCardRepository $linkCards,
        private ShareEnrichmentRepository $shareEnrichments,
        private SocialAccountRepository $socialAccounts,
        private SocialLikeRepository $socialLikes,
    ) {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function enrich(array $rows, int $userId): array
    {
        $enrichments = $this->enrichmentsByEntryId($rows, $userId);

        return array_map(static fn (EntryListRow $row): EntryListRow => $row->enrichedFrom($enrichments), $rows);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return array<int, EntryListRowEnrichment>
     */
    private function enrichmentsByEntryId(array $rows, int $userId): array
    {
        $entries = self::entriesById($rows);
        $entryIds = array_keys($entries);
        $categories = $this->categories->labelsByEntryIds($entryIds);
        $savedSearches = $this->memberships->savedSearchesByEntry($userId, $entryIds);
        $linkCards = $this->linkCards->contentsByEntryIds($entryIds);
        $pendingIds = array_flip($this->shareEnrichments->pendingEntryIdsAmong(self::topLevelEntryIdsOf($rows)));
        $socialStates = $this->socialStatesOf($entries, $userId);

        $enrichments = [];
        foreach ($entryIds as $entryId) {
            $enrichments[$entryId] = new EntryListRowEnrichment(
                $categories[$entryId] ?? [],
                $savedSearches[$entryId] ?? [],
                $linkCards[$entryId] ?? [],
                isset($pendingIds[$entryId]),
                $socialStates[$entryId] ?? null,
            );
        }

        return $enrichments;
    }

    /**
     * @param array<int, Entry> $entries
     *
     * @return array<int, EntryListRowSocial> by entry id, only for posts on a network the user has an account on
     */
    private function socialStatesOf(array $entries, int $userId): array
    {
        if ([] === $entries) {
            return [];
        }
        $networks = self::postNetworksAmong($entries, $this->socialAccounts->networksOf($userId));
        $likedIds = array_flip($this->socialLikes->likedEntryIdsAmong($userId, array_keys($networks)));

        $states = [];
        foreach ($networks as $entryId => $network) {
            $states[$entryId] = new EntryListRowSocial($network, isset($likedIds[$entryId]));
        }

        return $states;
    }

    /**
     * @param array<int, Entry>   $entries
     * @param list<SocialNetwork> $accountNetworks
     *
     * @return array<int, SocialNetwork>
     */
    private static function postNetworksAmong(array $entries, array $accountNetworks): array
    {
        if ([] === $accountNetworks) {
            return [];
        }
        $networks = [];
        foreach ($entries as $entryId => $entry) {
            $network = SocialPostAddress::of($entry)?->network;
            if (null !== $network && \in_array($network, $accountNetworks, true)) {
                $networks[$entryId] = $network;
            }
        }

        return $networks;
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return array<int, Entry> the entries of these rows and of their duplicates, each once, by id
     */
    private static function entriesById(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            foreach ([$row, ...$row->duplicates] as $member) {
                $entries[$member->entry->requireId()] = $member->entry;
            }
        }

        return $entries;
    }

    /**
     * Only a top-level row can be pending, never one of its duplicates.
     *
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private static function topLevelEntryIdsOf(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $rows);
    }
}
```

In `backend/src/Http/EntryJson.php`:

1. In the `EntryRow` phpstan-type, change the line `savedSearches: list<array{id: int, slug: string, term: string}>, pending: bool,` to:

```php
 *   savedSearches: list<array{id: int, slug: string, term: string}>, pending: bool,
 *   social: array{network: 'bluesky'|'mastodon', isLiked: bool}|null,
```

2. In `listRow()`, between `'savedSearches' => …` and `'duplicates' => …`, add:

```php
            'social' => self::socialOf($row),
```

3. Add the private mapper below `posterOf()`:

```php
    /** @return array{network: 'bluesky'|'mastodon', isLiked: bool}|null */
    private static function socialOf(EntryListRow $row): ?array
    {
        $social = $row->enrichment->social;

        return null === $social ? null : ['network' => $social->network->value, 'isLiked' => $social->isLiked];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Http/EntryJsonTest.php tests/Repository/EntryListRowEnricherTest.php tests/Controller/Api/EntryLikeControllerTest.php tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/EntrySearchControllerTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php tests/Controller/Api/EntryCompletionControllerTest.php`
Expected: PASS, the whole `EntryLikeControllerTest` included.

- [ ] **Step 5: Gates and commit**

Run: `cd backend && composer check && composer md && composer tramp`

```bash
git add backend/src/Repository/EntryListRowSocial.php backend/src/Repository/EntryListRowEnrichment.php \
  backend/src/Repository/EntryListRowEnricher.php backend/src/Http/EntryJson.php \
  backend/tests/Repository/EntryListRowEnricherTest.php backend/tests/Http/EntryJsonTest.php
git commit -m "feat(#1600): every entry names its network and whether the reader liked it there"
```

---

### Task 11: The thumbs-up toggle

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/testing/entry-testing.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`, `frontend/src/app/reader/reader-api.spec.ts`
- Modify: `frontend/src/app/reader/state/entries.store.ts`, `frontend/src/app/reader/state/entries.store.spec.ts`
- Modify: `frontend/src/app/reader/entry/entry-actions/entry-action-handler.ts`
- Modify: `frontend/src/app/reader/entry/entry-actions/entry-actions.component.{ts,html,spec.ts}`
- Modify: `frontend/src/app/reader/shell/entry-state-actions.service.ts`
- Create: `frontend/src/app/reader/shell/entry-state-actions.service.spec.ts`
- Modify: `frontend/src/app/reader/list/magazine/entry-duplicates.component.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `PUT`/`DELETE /api/entries/{id}/like` (Task 9), `entry.social` (Task 10)
- Produces:
  - `interface EntrySocialDto { network: SocialNetworkName; isLiked: boolean }`, `EntryDto.social: EntrySocialDto | null`, `interface EntryLikeDto { isLiked: boolean }`
  - `ReaderApi.like(id): Observable<EntryLikeDto>`, `ReaderApi.unlike(id): Observable<EntryLikeDto>`
  - `EntriesStore.setLiked(entry: EntryDto, isLiked: boolean, onError: (problem: Problem) => void): void`
  - `EntryActionHandler.like(entry: EntryDto): void`

- [ ] **Step 1: Write the failing tests**

Append to `frontend/src/app/reader/reader-api.spec.ts`, inside `describe('ReaderApi', …)`:

```ts
  it('PUTs a like and DELETEs it again', () => {
    api.like(3).subscribe();
    const like = ctrl.expectOne('https://api.test/api/entries/3/like');
    expect(like.request.method).toBe('PUT');
    like.flush({ isLiked: true });

    api.unlike(3).subscribe();
    const unlike = ctrl.expectOne('https://api.test/api/entries/3/like');
    expect(unlike.request.method).toBe('DELETE');
    unlike.flush({ isLiked: false });
  });
```

Append to `frontend/src/app/reader/state/entries.store.spec.ts`, inside `describe('EntriesStore', …)`:

```ts
  describe('liking a post (#1600)', () => {
    const post = (isLiked = false): EntryDto =>
      entry(1, { social: { network: 'bluesky', isLiked } });

    const loadPost = (isLiked = false): void => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [post(isLiked)], nextCursor: null });
    };

    it('likes optimistically on its own route and keeps the like once the server agrees', () => {
      loadPost();

      store.setLiked(store.entries()[0], true, () => undefined);
      expect(store.entries()[0].social?.isLiked).toBe(true);
      const like = ctrl.expectOne('https://api.test/api/entries/1/like');
      expect(like.request.method).toBe('PUT');
      like.flush({ isLiked: true });

      expect(store.entries()[0].social).toEqual({ network: 'bluesky', isLiked: true });
    });

    it('unlikes on DELETE', () => {
      loadPost(true);

      store.setLiked(store.entries()[0], false, () => undefined);

      expect(store.entries()[0].social?.isLiked).toBe(false);
      expect(ctrl.expectOne('https://api.test/api/entries/1/like').request.method).toBe('DELETE');
    });

    it('keeps an in-flight like across a list reload that lands first', () => {
      loadPost();
      store.setLiked(store.entries()[0], true, () => undefined);
      const like = ctrl.expectOne('https://api.test/api/entries/1/like');

      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [post(false)], nextCursor: null });

      expect(store.entries()[0].social?.isLiked).toBe(true);
      like.flush({ isLiked: true });
    });

    it('puts the like back and hands the problem over when the network refuses', () => {
      loadPost();
      const problems: string[] = [];

      store.setLiked(store.entries()[0], true, (problem) => problems.push(problem.title));
      ctrl
        .expectOne('https://api.test/api/entries/1/like')
        .flush(
          { type: 'social_post_not_found', title: 'Post no longer available', status: 404 },
          { status: 404, statusText: 'Not Found' },
        );

      expect(store.entries()[0].social?.isLiked).toBe(false);
      expect(problems).toEqual(['Post no longer available']);
      expect(store.error()).toBeNull();
    });

    it('sends nothing for an entry that is no social post', () => {
      store.load({ view: 'all' });
      ctrl
        .expectOne((request) => request.url === 'https://api.test/api/entries')
        .flush({ entries: [entry(1)], nextCursor: null });

      store.setLiked(store.entries()[0], true, () => undefined);

      ctrl.expectNone('https://api.test/api/entries/1/like');
    });
  });
```

In `frontend/src/app/reader/entry/entry-actions/entry-actions.component.spec.ts`, add to `HostComponent`:

```ts
  liked: EntryDto | null = null;

  like(entry: EntryDto): void {
    this.liked = entry;
  }
```

and append inside `describe('EntryActionsComponent', …)`:

```ts
  describe('on a social post (#1600)', () => {
    const blueskyPost = (isLiked = false): EntryDto =>
      anEntry({ social: { network: 'bluesky', isLiked } });

    it('offers the like right after favorite, named for the network', () => {
      const labels = buttons(mount(blueskyPost())).map((button) => button.getAttribute('aria-label'));
      expect(labels).toEqual(['Favorite', 'Like on Bluesky', 'Keep', 'Toggle read']);
    });

    it('names the undo once liked, pressed and filled', () => {
      const fixture = mount(anEntry({ social: { network: 'mastodon', isLiked: true } }));
      const like = buttons(fixture)[1];
      expect(like.getAttribute('aria-label')).toBe('Unlike on Mastodon');
      expect(like.getAttribute('aria-pressed')).toBe('true');
      expect(like.textContent).toContain('thumb_up');
      expect(
        fixture.debugElement.queryAll(By.directive(IconComponent))[1].componentInstance.fill(),
      ).toBe(true);
    });

    it('likes without opening the card', () => {
      const fixture = mount(blueskyPost());

      buttons(fixture)[1].click();

      expect(fixture.componentInstance.liked).toBe(fixture.componentInstance.entry);
      expect(fixture.componentInstance.cardOpened).toBe(false);
    });

    it('offers no like on an entry that is no social post', () => {
      expect(mount().nativeElement.textContent).not.toContain('thumb_up');
    });
  });
```

Create `frontend/src/app/reader/shell/entry-state-actions.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { EntryStateActions } from './entry-state-actions.service';
import { EntriesStore } from '../state/entries.store';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { ReaderRouteState } from './reader-route-state.service';
import { ToastService } from '../../shared/toast/toast.service';
import { Problem } from '../../core/problem';
import { EntryDto } from '../models';
import { anEntry } from '../../../testing/entry-testing';

describe('EntryStateActions liking (#1600)', () => {
  const setLiked = jest.fn<void, [EntryDto, boolean, (problem: Problem) => void]>();
  const show = jest.fn();
  let actions: EntryStateActions;

  beforeEach(() => {
    setLiked.mockReset();
    show.mockReset();
    TestBed.configureTestingModule({
      providers: [
        EntryStateActions,
        provideRouter([]),
        { provide: EntriesStore, useValue: { setLiked } },
        { provide: SubscriptionsStore, useValue: {} },
        { provide: SavedSearchesStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId: signal(null), openEntry: signal(null) },
        },
        { provide: ToastService, useValue: { show } },
      ],
    });
    actions = TestBed.inject(EntryStateActions);
  });

  it('likes an unliked post and unlikes a liked one', () => {
    const unliked = anEntry({ social: { network: 'bluesky', isLiked: false } });
    const liked = anEntry({ social: { network: 'bluesky', isLiked: true } });

    actions.like(unliked);
    actions.like(liked);

    expect(setLiked.mock.calls.map(([entry, isLiked]) => [entry, isLiked])).toEqual([
      [unliked, true],
      [liked, false],
    ]);
  });

  it("toasts the problem's own message when the like fails", () => {
    actions.like(anEntry({ social: { network: 'mastodon', isLiked: false } }));
    const onError = setLiked.mock.calls[0][2];

    onError({
      type: 'social_post_not_found',
      title: 'Post no longer available',
      status: 404,
      detail: 'The post is no longer on Mastodon.',
    });

    expect(show).toHaveBeenCalledWith({ message: 'The post is no longer on Mastodon.' });
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/reader-api.spec.ts src/app/reader/state/entries.store.spec.ts src/app/reader/entry/entry-actions src/app/reader/shell/entry-state-actions.service.spec.ts`
Expected: FAIL. Typescript reports `social` missing from `EntryDto`, and `like`/`setLiked` are missing.

- [ ] **Step 3: Implement**

In `frontend/src/app/reader/models.ts`, add to `EntryDto` after `pending`:

```ts
  /** The entry's post on a network the reader has an account on, and whether they liked it there; null for an
   *  article or a post on a network without an account (#1600). */
  social: EntrySocialDto | null;
```

and after the `EntryDto` interface:

```ts
export interface EntrySocialDto {
  network: SocialNetworkName;
  isLiked: boolean;
}

/** The answer of `PUT`/`DELETE /api/entries/{id}/like`. */
export interface EntryLikeDto {
  isLiked: boolean;
}
```

In `frontend/src/testing/entry-testing.ts`, add `social: null,` after `pending: false,`.

In `frontend/src/app/reader/reader-api.ts`, add `EntryLikeDto` to the models import and, after `updateState()`:

```ts
  like(id: number): Observable<EntryLikeDto> {
    return this.http.put<EntryLikeDto>(`${this.base}/api/entries/${id}/like`, null);
  }

  unlike(id: number): Observable<EntryLikeDto> {
    return this.http.delete<EntryLikeDto>(`${this.base}/api/entries/${id}/like`);
  }
```

In `frontend/src/app/reader/state/entries.store.ts`:

1. Widen the in-flight patch, replacing the `InFlightPatch` interface:

```ts
/** A row flag change still on the wire — a state PATCH or a like — kept so a list reload that lands meanwhile
 *  can lay the row's optimistic state back over the server's stale copy. */
interface InFlightPatch {
  entryId: number;
  patch: EntryStatePatch & Pick<Partial<EntryDto>, 'social'>;
}
```

2. Add after `setState()`:

```ts
  /** Optimistic like on its own route, apart from `setState`'s PATCH; a failure puts back only the like and hands
   *  `onError` the problem instead of raising the list's error banner. */
  setLiked(entry: EntryDto, isLiked: boolean, onError: (problem: Problem) => void): void {
    const before = entry.social;
    if (!before) return;
    const inFlight: InFlightPatch = { entryId: entry.id, patch: { social: { ...before, isLiked } } };
    this.inFlightPatches.add(inFlight);
    this.patchEntries([entry.id], (row) => ({ ...row, ...inFlight.patch }));
    const request$ = isLiked ? this.api.like(entry.id) : this.api.unlike(entry.id);
    request$.pipe(finalize(() => this.inFlightPatches.delete(inFlight))).subscribe({
      error: (error: HttpErrorResponse) => {
        this.patchEntries([entry.id], (row) => ({ ...row, social: before }));
        onError(parseProblem(error));
      },
    });
  }
```

In `frontend/src/app/reader/entry/entry-actions/entry-action-handler.ts`, add `abstract like(entry: EntryDto): void;` after `favorite`, and `like: () => undefined,` to `IGNORED_ENTRY_ACTIONS`.

In `frontend/src/app/reader/entry/entry-actions/entry-actions.component.ts`, import `computed` from `@angular/core` and add to the class:

```ts
  protected readonly likeLabel = computed(() => {
    const social = this.entry().social;
    return social ? `reader.${social.isLiked ? 'unlike' : 'like'}.${social.network}` : '';
  });
```

Update its doc comment's first line to name the like: `The per-entry actions — favorite, like on the post's network (#1600), keep, mark read, …`.

In `frontend/src/app/reader/entry/entry-actions/entry-actions.component.html`, insert after the favorite `<button>`:

```html
@if (entry().social; as social) {
  <button
    type="button"
    [attr.aria-label]="likeLabel() | transloco"
    [appFlagToggle]="social.isLiked"
    (click)="$event.stopPropagation(); actions.like(entry())"
    (keydown.enter)="$event.stopPropagation()"
    (keydown.space)="$event.stopPropagation()"
  >
    <app-icon name="thumb_up" [size]="size()" [fill]="social.isLiked" />
  </button>
}
```

In `frontend/src/app/reader/shell/entry-state-actions.service.ts`, add the imports `ToastService` (`'../../shared/toast/toast.service'`) and `problemMessage` (`'../../core/problem'`), the field `private readonly toast = inject(ToastService);`, and after `favorite()`:

```ts
  like(entry: EntryDto): void {
    this.entries.setLiked(entry, !entry.social?.isLiked, (problem) =>
      this.toast.show({ message: problemMessage(problem) }),
    );
  }
```

In `frontend/src/app/reader/list/magazine/entry-duplicates.component.ts`, add after `favorite()`:

```ts
  like(copy: EntryDto): void {
    this.shellActions.like(copy);
    this.displayed.update((current) =>
      current?.social
        ? { ...current, social: { ...current.social, isLiked: !current.social.isLiked } }
        : current,
    );
  }
```

In `frontend/public/i18n/en.json`, inside `"reader"`, after `"favorite": "Favorite",`:

```json
    "like": {
      "bluesky": "Like on Bluesky",
      "mastodon": "Like on Mastodon"
    },
    "unlike": {
      "bluesky": "Unlike on Bluesky",
      "mastodon": "Unlike on Mastodon"
    },
```

In `frontend/public/i18n/de.json`, inside `"reader"`, after `"favorite": "Favorit",`:

```json
    "like": {
      "bluesky": "Auf Bluesky liken",
      "mastodon": "Auf Mastodon liken"
    },
    "unlike": {
      "bluesky": "Like auf Bluesky zurücknehmen",
      "mastodon": "Like auf Mastodon zurücknehmen"
    },
```

- [ ] **Step 4: Run the tests and the gate**

Run: `docker compose exec -T frontend npm test -- src/app/reader/reader-api.spec.ts src/app/reader/state/entries.store.spec.ts src/app/reader/entry/entry-actions src/app/reader/shell/entry-state-actions.service.spec.ts src/app/reader/list/magazine`
Expected: PASS.

Run: `docker compose exec -T frontend npm run check`
Expected: clean. If `tsc` (spec or e2e) reports an `EntryDto` literal without `social`, add `social: null` to it; `anEntry()` covers every spec found by `grep -rn "titleDerived: " src`.

- [ ] **Step 5: Commit**

```bash
git add frontend/src frontend/public/i18n
git commit -m "feat(#1600): a thumbs-up toggle beside Favorite likes the post at its origin"
```

---

### Task 12: Security notes and final verification

**Files:**
- Modify: `docs/security.md` ("Social accounts")

- [ ] **Step 1: Document the write**

In `docs/security.md`, section "Social accounts", add after the bullet beginning "The refresh worker reads the stored credential":

```markdown
- A like (#1600) is the one write. It acts through the account that owns the private feed, else the network's default
  account. It first resolves the post on that account's own server (`getPosts` on the PDS, `/api/v2/search?resolve=true`
  on the Mastodon instance), and a status id from that answer reaches a URL path only when it is 1–64 letters and digits.
  An account that needs reconnecting is refused before any call; a refused credential marks it. Like and unlike share a
  per-user budget (`social_like`, 60 per 5 minutes).
```

Commit:

```bash
git add docs/security.md
git commit -m "docs(#1600): the like is the one write a social account makes"
```

- [ ] **Step 2: Run every gate**

From `backend/`:

```bash
bin/console cache:warmup
composer cs
composer stan
composer md
composer tramp
php bin/phpunit
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php composer test
git fetch origin develop && composer infection:diff
```

From the repo root:

```bash
docker compose exec -T frontend npm run check
```

Expected:
- every command is clean, and `composer md` lists no finding in a touched `src` file;
- `infection:diff` meets `minMsi`, where escaped mutants on touched lines get a test, not a threshold change;
- PhpStorm inspections (`mcp__phpstorm__lint_files`) on every changed PHP file show no ERROR or WARNING;
- `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq 'select(.level_name != "DEBUG" and .level_name != "INFO")'` shows no new deprecation or error from the like path.

- [ ] **Step 3: Manual check in the browser**

Restart the worker and PHP containers (`docker compose restart php worker`), open the reader on `http://localhost:4200`, and check that:

- a Bluesky or Mastodon post from a connected network shows the thumbs up after the star;
- liking turns it accent-filled, and unliking clears it;
- an ordinary article shows no thumbs up.

Use an account the user approves for a real like; never like on a production account unasked.

## Self-review

- Spec coverage:
  - Ruling 1: Tasks 3 and 10.
  - Ruling 2: Tasks 3 and 6.
  - Ruling 3: Task 1.
  - Ruling 4: Tasks 2 and 4.
  - Ruling 5: Task 9.
  - Ruling 6, replaced by 12's `social`: Task 10.
  - Ruling 7: Tasks 5–7.
  - Ruling 8: Tasks 4 and 8.
  - Ruling 9: Task 11.
  - Ruling 10: Global Constraints.
  - Ruling 12's layers: Tasks 3–8, with the reply reuse list under "Decisions".
  - Ruling 11 (out of scope): nothing added.
  - Ruling 13 (like counts later): seams named under "Decisions"; `SocialLikeResultModel` in Task 7.
- Types:
  - `SocialPostModel::{account, postId, contentId}` are used alike in Tasks 6–8 and in `FakeMastodonNetwork`.
  - `PostLikerInterface::like()` returns `SocialLikeResultModel` in Task 7, `FakeMastodonNetwork` and `SocialLikes`.
  - `PostLikerInterface::unlike(SocialAccount, string $likeId)` is the same in Tasks 7 and 8.
  - `EntriesStore.setLiked(entry, isLiked, onError)` is the same in the store, `EntryStateActions` and the specs.
