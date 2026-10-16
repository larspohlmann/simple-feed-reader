# Import Avatars Right Away Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A freshly imported Bluesky profile feed shows its owner's avatar as soon as the import returns, instead of waiting several `ProfileImageSweep` cycles (#1613).

**Architecture:** Option 2 of the issue, chosen by Lars. Loading the import candidates already reads each follow's avatar from Bluesky's public AppView (`getFollows`, #1625). `ImportCandidates` hands those follows to a new `FollowAvatarCache`, which keeps them server-side for an hour, one cache entry per account (identity → avatar URL). In the same transaction, after `BulkSubscriber` creates the profile feeds, `SocialImporter` asks a new `ImportedProfilePictures` to store the cached avatar on each of those feeds that has never had a picture check. The feed is then stamped as checked, so the weekly sweep picks it up again as usual. A cache miss changes nothing: the existing sweep still fills the picture later. The cache trusts only Bluesky accounts. A Mastodon follow list comes from an instance the user chose, which could report any picture for anyone, and a `Feed` row is shared between users. Mastodon profile feeds already get their picture from the RSS channel on their first fetch.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine, PSR-6 cache pool (filesystem adapter), PHPUnit 12.

**Spec:** GitHub issue #1613 (`gh issue view 1613`), option 2.

## Global Constraints

- No client-supplied image is ever written to a shared `Feed`; only data the server fetched from Bluesky's public AppView.
- No extra AppView calls, and no extra latency on the import beyond one cache read and one `IN` query.
- Datetimes stored as naive UTC: take "now" from `App\Service\Clock\NaiveUtcClock`, as `ProfileImageSweep` does.
- A picture written to `Feed` passes `AbsoluteHttpUrl::orNullWithin($url, Feed::IMAGE_URL_MAX)`.
- CLAUDE.md applies in full: Clean Code names, roles per folder (services in the module root, `Model/` for domain data), no comment unless it clears the bar, `final readonly`, PHPMD-clean touched files, PHPStan max, queries only in `src/Repository`.
- Commit format `type(#1613): …`; no Claude attribution.
- Tests run natively from `backend/` (`php bin/phpunit …`), not in Docker (the Docker stack serves another checkout).

---

### Task 1: Remember the candidates' Bluesky avatars

**Files:**
- Create: `backend/src/Service/Social/Model/FollowAvatarsModel.php`
- Create: `backend/src/Service/Social/FollowAvatarCache.php`
- Modify: `backend/config/packages/cache.yaml` (new pool next to `social.counts.cache`)
- Modify: `backend/src/Service/Social/ImportCandidates.php` (`forAccount()`, constructor)
- Test: `backend/tests/Service/Social/Model/FollowAvatarsModelTest.php` (create), `backend/tests/Service/Social/FollowAvatarCacheTest.php` (create), `backend/tests/Service/Social/ImportCandidatesTest.php`

**Interfaces:**
- Produces: `FollowAvatarsModel::of(list<FollowedAccountModel>): self`, `::none(): self`, `->avatarOf(string $identity): ?string`, `->isEmpty(): bool`, `->toCacheEntry(): array<string, string>`, `::fromCacheEntryOrNull(mixed): ?self`; `FollowAvatarCache::remember(SocialAccount $account, list<FollowedAccountModel> $follows): void`, `->find(SocialAccount $account): FollowAvatarsModel`; cache pool service id `social.follow_avatars.cache`.

- [ ] **Step 1: Write the failing tests**

`FollowAvatarsModelTest`:

```php
public function testKeepsOnlyTheFollowsWithAnAvatar(): void
{
    $avatars = FollowAvatarsModel::of([
        new FollowedAccountModel('did:plc:bob', '@bob.test', 'Bob', 'https://bsky.app/profile/did:plc:bob/rss', 'https://cdn.test/bob.jpg'),
        new FollowedAccountModel('did:plc:amy', '@amy.test', null, 'https://bsky.app/profile/did:plc:amy/rss', null),
    ]);

    self::assertSame('https://cdn.test/bob.jpg', $avatars->avatarOf('did:plc:bob'));
    self::assertNull($avatars->avatarOf('did:plc:amy'));
    self::assertSame(['did:plc:bob' => 'https://cdn.test/bob.jpg'], $avatars->toCacheEntry());
}

public function testNoneIsEmpty(): void
{
    self::assertTrue(FollowAvatarsModel::none()->isEmpty());
    self::assertFalse(FollowAvatarsModel::fromCacheEntryOrNull(['did:plc:bob' => 'https://cdn.test/bob.jpg'])?->isEmpty());
}

/** @return iterable<string, array{mixed}> */
public static function damagedEntries(): iterable
{
    yield 'not an array' => ['https://cdn.test/bob.jpg'];
    yield 'a list' => [['https://cdn.test/bob.jpg']];
    yield 'a non-string avatar' => [['did:plc:bob' => 42]];
}

#[DataProvider('damagedEntries')]
public function testADamagedCacheEntryReadsAsNothing(mixed $stored): void
{
    self::assertNull(FollowAvatarsModel::fromCacheEntryOrNull($stored));
}
```

`FollowAvatarCacheTest` (over `new ArrayAdapter()`, accounts from `SocialAccounts::bluesky()` / `::mastodon()` given ids with the `AssignsEntityIds` trait, as `BlueskyCandidateSourceTest` does):
- `testABlueskyAccountsAvatarsAreFoundAgain` — remember `[bob with avatar]` for Bluesky account 3; `find()` on it returns `'https://cdn.test/bob.jpg'` for `did:plc:bob`.
- `testAnUnaskedAccountHasNone` — `find()` on a fresh cache `isEmpty()`.
- `testEachAccountKeepsItsOwnAvatars` — remember for account 3; account 4 (also Bluesky) finds none.
- `testAMastodonAccountRemembersNothing` — remember `[bob@mastodon.example with avatar]` for a Mastodon account with id 5; `find()` returns none.
- `testALaterLoadReplacesTheEarlierOne` — remember `[bob]`, then `[carol]`; bob is gone and carol is found.

`ImportCandidatesTest` — the `importCandidates()` helper builds `ImportCandidates` with a `FollowAvatarCache` held in a new `$this->followAvatars` property over `new ArrayAdapter()`. New test:

```php
public function testTheCandidatesAvatarsAreRememberedForTheImport(): void
{
    $account = $this->account();
    $network = new StubSocialCandidates(new NetworkCandidatesModel(
        [self::follow('did:plc:bob', '@bob.bsky.social', 'https://cdn.test/bob.jpg')],
        [],
        [],
        false,
    ));

    $this->importCandidates($network)->forAccount($account);

    self::assertSame('https://cdn.test/bob.jpg', $this->followAvatars->find($account)->avatarOf('did:plc:bob'));
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/Model/FollowAvatarsModelTest.php tests/Service/Social/FollowAvatarCacheTest.php tests/Service/Social/ImportCandidatesTest.php`
Expected: FAIL, because the classes do not exist yet.

- [ ] **Step 3: Implement**

`FollowAvatarsModel` (`App\Service\Social\Model`):

```php
final readonly class FollowAvatarsModel
{
    /** @param array<string, string> $byIdentity */
    private function __construct(private array $byIdentity)
    {
    }

    /** @param list<FollowedAccountModel> $follows */
    public static function of(array $follows): self
    {
        $byIdentity = [];
        foreach ($follows as $follow) {
            if (null !== $follow->avatarUrl) {
                $byIdentity[$follow->identity] = $follow->avatarUrl;
            }
        }

        return new self($byIdentity);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function avatarOf(string $identity): ?string
    {
        return $this->byIdentity[$identity] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->byIdentity;
    }

    /** @return array<string, string> */
    public function toCacheEntry(): array
    {
        return $this->byIdentity;
    }

    public static function fromCacheEntryOrNull(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }
        $byIdentity = [];
        foreach ($stored as $identity => $avatarUrl) {
            if (!\is_string($identity) || !\is_string($avatarUrl)) {
                return null;
            }
            $byIdentity[$identity] = $avatarUrl;
        }

        return new self($byIdentity);
    }
}
```

`FollowAvatarCache` (`App\Service\Social`), shaped like `SocialCountsCache`:

```php
final readonly class FollowAvatarCache
{
    public function __construct(
        #[Autowire(service: 'social.follow_avatars.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** @param list<FollowedAccountModel> $follows */
    public function remember(SocialAccount $account, array $follows): void
    {
        if (!self::isTrusted($account)) {
            return;
        }
        $item = $this->cache->getItem(self::keyOf($account));
        $item->set(FollowAvatarsModel::of($follows)->toCacheEntry());
        $this->cache->save($item);
    }

    public function find(SocialAccount $account): FollowAvatarsModel
    {
        if (!self::isTrusted($account)) {
            return FollowAvatarsModel::none();
        }
        $item = $this->cache->getItem(self::keyOf($account));
        $avatars = $item->isHit() ? FollowAvatarsModel::fromCacheEntryOrNull($item->get()) : null;

        return $avatars ?? FollowAvatarsModel::none();
    }

    /** Bluesky's follows come from the public AppView; a Mastodon instance is the user's own pick and could name any picture. */
    private static function isTrusted(SocialAccount $account): bool
    {
        return SocialNetwork::Bluesky === $account->getNetwork();
    }

    private static function keyOf(SocialAccount $account): string
    {
        return 'follow_avatars.' . $account->requireId();
    }
}
```

`cache.yaml`: add after `social.counts.cache`:

```yaml
            # The Bluesky avatars an import picker was shown, per account, so the import pictures its new profile
            # feeds at once (#1613). A miss only leaves them to ProfileImageSweep.
            social.follow_avatars.cache:
                adapter: cache.adapter.filesystem
                default_lifetime: 3600
```

`ImportCandidates`: inject `private FollowAvatarCache $followAvatars` and, in `forAccount()` right after `$network = $this->networkCandidatesOf($account);`, call `$this->followAvatars->remember($account, $network->follows);`.

- [ ] **Step 4: Run the tests to verify they pass**

Run the Step 2 command again. Expected: PASS.

- [ ] **Step 5: Gates, then commit**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Controller/Api/SocialImportControllerTest.php && composer cs && composer stan && composer md && composer tramp`

```bash
git add backend
git commit -m "feat(#1613): the import picker remembers its follows' Bluesky avatars"
```

### Task 2: The import pictures the profile feeds it creates

**Files:**
- Create: `backend/src/Service/Social/ImportedProfilePictures.php`
- Modify: `backend/src/Service/Social/SocialImporter.php` (`import()`, constructor)
- Test: `backend/tests/Service/Social/SocialImporterTest.php`, `backend/tests/Controller/Api/SocialImportControllerTest.php`

**Interfaces:**
- Consumes: `FollowAvatarCache::remember()` / `::find()` and `FollowAvatarsModel::avatarOf()` / `::isEmpty()` (Task 1); `FeedRepository::findByUrlsIndexedByUrl(list<string>): array<string, Feed>`; `Feed::getImageCheckedAt()`, `Feed::recordImageCheck(?string, \DateTimeImmutable)`; `ProfileFeedUrl::identityOf(SocialNetwork, string): ?string`.
- Produces: `ImportedProfilePictures::picture(SocialAccount $account, list<string> $feedUrls): void`.

- [ ] **Step 1: Write the failing tests**

`SocialImporterTest`. In `setUp()`, clear the `social.follow_avatars.cache` pool, since it lives on disk across tests in one worker:

```php
$followAvatars = self::getContainer()->get('social.follow_avatars.cache');
self::assertInstanceOf(CacheItemPoolInterface::class, $followAvatars);
$followAvatars->clear();
```

Add a `followAvatars()` helper that returns the container's `FollowAvatarCache`. New tests:

```php
public function testAnImportedProfileFeedTakesTheAvatarThePickerShowed(): void
{
    $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
    $this->followAvatars()->remember($account, [
        new FollowedAccountModel('did:plc:bob', '@bob.test', 'Bob', 'https://bsky.app/profile/did:plc:bob/rss', 'https://cdn.test/bob.jpg'),
    ]);

    $this->importer()->import($account, new SocialImportModel(
        [new SocialImportFeedModel('did:plc:bob', 'Bob'), new SocialImportFeedModel('did:plc:carol', 'Carol')],
        [],
        [],
    ));

    $bob = $this->feedAt('https://bsky.app/profile/did:plc:bob/rss');
    self::assertSame('https://cdn.test/bob.jpg', $bob?->getImageUrl());
    self::assertNotNull($bob->getImageCheckedAt());
    $carol = $this->feedAt('https://bsky.app/profile/did:plc:carol/rss');
    self::assertNull($carol?->getImageUrl());
    self::assertNull($carol->getImageCheckedAt());
}

public function testAProfileFeedTheSweepAlreadyCheckedKeepsItsPicture(): void
{
    $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
    $other = (new UserFactory(...))->create('social-importer-other@example.test');   // as in setUp
    $this->subscriptions->subscribe($other, 'https://bsky.app/profile/did:plc:bob/rss');
    $checked = new \DateTimeImmutable('2026-10-01 00:00:00');
    $this->feedAt('https://bsky.app/profile/did:plc:bob/rss')?->recordImageCheck('https://cdn.test/old.jpg', $checked);
    $this->entityManager->flush();
    $this->followAvatars()->remember($account, [
        new FollowedAccountModel('did:plc:bob', '@bob.test', 'Bob', 'https://bsky.app/profile/did:plc:bob/rss', 'https://cdn.test/bob.jpg'),
    ]);

    $this->importer()->import($account, new SocialImportModel([new SocialImportFeedModel('did:plc:bob', 'Bob')], [], []));

    self::assertSame('https://cdn.test/old.jpg', $this->feedAt('https://bsky.app/profile/did:plc:bob/rss')?->getImageUrl());
}
```

Accounts are persisted with the file's existing `storedAccount()` helper: the cache is keyed by the account id. The existing tests that import with an unsaved `SocialAccounts::blueskyOf($this->user)` must switch to `$this->storedAccount(...)` too, or `requireId()` throws. Real accounts always have an id. Build the second user the same way `setUp()` builds the first.

`SocialImportControllerTest` (functional, through the real wiring). In `setUp()`, clear `social.follow_avatars.cache` next to the rate-limiter pool. Then add:

```php
public function testAnImportRightAfterTheCandidatesPicturesTheNewProfileFeeds(): void
{
    $client = $this->clientWith(new StubSocialCandidates(new NetworkCandidatesModel(
        [self::follow('did:plc:bob', '@bob.bsky.social', 'https://cdn.test/bob.jpg')],
        [],
        [],
        false,
    )));
    $user = $this->signIn($client, 'import-avatars@example.test');
    $account = $this->blueskyAccountOf($user);
    $this->requestCandidates($client, $account);

    $this->postImport($client, $account, ['subscribe' => [['identity' => 'did:plc:bob', 'title' => 'Bob']]]);

    self::assertResponseIsSuccessful();
    $this->entityManager()->clear();
    $feed = $this->entityManager()->getRepository(Feed::class)->findOneBy(['url' => 'https://bsky.app/profile/did:plc:bob/rss']);
    self::assertSame('https://cdn.test/bob.jpg', $feed?->getImageUrl());
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/SocialImporterTest.php tests/Controller/Api/SocialImportControllerTest.php`
Expected: FAIL, because the imported feed's `imageUrl` is still `null`.

- [ ] **Step 3: Implement**

`ImportedProfilePictures` (`App\Service\Social`):

```php
final readonly class ImportedProfilePictures
{
    public function __construct(
        private FollowAvatarCache $followAvatars,
        private FeedRepository $feeds,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @param list<string> $feedUrls */
    public function picture(SocialAccount $account, array $feedUrls): void
    {
        $avatars = $this->followAvatars->find($account);
        if ($avatars->isEmpty()) {
            return;
        }
        $now = $this->clock->now();
        foreach ($this->feeds->findByUrlsIndexedByUrl($feedUrls) as $feed) {
            $avatarUrl = self::avatarFor($feed, $avatars);
            if (null !== $avatarUrl && null === $feed->getImageCheckedAt()) {
                $feed->recordImageCheck($avatarUrl, $now);
            }
        }
    }

    private static function avatarFor(Feed $feed, FollowAvatarsModel $avatars): ?string
    {
        $identity = ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $feed->getUrl());

        return AbsoluteHttpUrl::orNullWithin(null === $identity ? null : $avatars->avatarOf($identity), Feed::IMAGE_URL_MAX);
    }
}
```

`SocialImporter`: inject `private ImportedProfilePictures $importedPictures`. Inside the transaction callback, right after `$subscribed = $this->bulkSubscriber->subscribeAll(...)`, add:

```php
$this->importedPictures->picture(
    $account,
    array_map(static fn (BulkSubscribeItemModel $item): string => $item->feedUrl, $items),
);
```

`wrapInTransaction` flushes when the callback returns, so the picture is written with the import.

- [ ] **Step 4: Run the tests to verify they pass**

Run the Step 2 command again, then the full suite once: `cd backend && composer test:parallel`. Expected: PASS.

- [ ] **Step 5: Gates, then commit**

Run: `cd backend && composer cs && composer stan && composer md && composer tramp && composer infection:diff`

```bash
git add backend
git commit -m "feat(#1613): an import pictures the profile feeds it creates"
```

### Task 3: Verify and open the PR

- [ ] Scan today's dev log of the worktree (`ls -t backend/var/log/*.log | head -1`) for deprecations from the new pool.
- [ ] Push `feature/1613-import-avatars-right-away` and open a PR into `develop` with `Closes #1613`. The PR says: option 2; Bluesky only, and why; a cache miss falls back to the sweep; the MySQL leg is left to CI.
