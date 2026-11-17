# Lemmy Pictures From the API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lemmy community and user feeds get their picture from the public Lemmy API into `Feed.imageUrl` through the profile-image sweep, and `FaviconResolver` loses the Lemmy actor scraping #2316 added (#2320).

**Architecture:** A network feed's own picture lives in `Feed.imageUrl` (#1599). The frontend shows it as the feed's avatar (`feedAvatarUrl`, for `social.kind = 'profile'`) in front of the site favicon. `ProfileImageSweep` fills it weekly, Bluesky only today; it becomes a coordinator over per-network `ProfilePictureSourceInterface` sources (tagged iterator). The Bluesky logic moves unchanged into `BlueskyProfilePictures`; a new `LemmyProfilePictures` asks `GET /api/v3/community?name=` or `GET /api/v3/user?username=` on the feed's instance. Lemmy user feeds (`/feeds/u/<name>.xml`) become Lemmy network feeds with the identity `@name@host`, so they never collide with a community's `name@host`. `faviconUrl` goes back to the instance icon (#2298).

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM/Migrations, PHPUnit 12.

**Spec:** GitHub issue #2320; Lars's decisions in session: an avatar-less user gets the mouse `https://join-lemmy.org/static/assets/icons/apple-touch-icon.png`; Lemmy user feeds are classified as Lemmy feeds.

## Global Constraints

- CLAUDE.md binds: Clean Code names, default to no comment, `final readonly` services, one role per folder (interface + implementations in a folder named after the interface), queries only in `src/Repository`, every touched `src` file PHPMD-clean, `composer check` green, no tramp data.
- Commit messages `type(#2320): lower-case summary`; no Claude attribution.
- A community without an icon gets no picture (`imageUrl` null; the instance favicon shows). Only a user without an avatar gets the mouse.
- Mouse URL, verbatim: `https://join-lemmy.org/static/assets/icons/apple-touch-icon.png`.
- Picture URLs pass `AbsoluteHttpUrl::orNullWithin($url, Feed::IMAGE_URL_MAX)`, as Bluesky's do.
- Bluesky sweep behaviour (25 actors per call, throttle, set-aside, visibility labels, `-7 days`) is unchanged; its tests keep their assertions.
- Lemmy refresh interval: `-7 days`, as Bluesky.

## Review Focus

- An instance that answers 404 / `couldnt_find_community` / `couldnt_find_person` → stamped checked, existing `imageUrl` kept (Task 3 `testAnUnknownActorKeepsItsPicture`).
- An unreachable or throttling instance → that feed stamped and logged, feeds on other instances still swept (Task 3 `testAnUnreachableInstanceIsSetAsideAndTheNextFeedStillSwept`).
- A feed URL with a query or capitals → same identity and request (Task 1 provider rows, Task 3 community test uses `?sort=New`).
- A user and a community of one name on one instance → two identities, two endpoints (Task 1 `testAUserAndACommunityOfOneNameAreTwoIdentities`, Task 3 `testAUserAndACommunityOfOneNameAskDifferentEndpoints`).
- Budget: Bluesky and Lemmy together spend at most the budget (Task 2 `testASecondSourceGetsOnlyTheCallsTheFirstLeft`, Task 3 `testTheBudgetCapsTheFeedsAsked`).

---

### Task 1: Lemmy user feeds are Lemmy network feeds

**Files:**
- Modify: `backend/src/Service/Social/Social/Support/ProfileFeedUrl.php` (`LEMMY_COMMUNITY_PATH` l.16, `lemmyIdentity` l.148-151)
- Modify: `backend/src/Service/Social/Social/ProfileFeedTitle.php` (`lemmyTitle`, l.38-40)
- Test: `backend/tests/Service/Social/Social/Support/ProfileFeedUrlTest.php`, `backend/tests/Service/Social/Social/ProfileFeedTitleTest.php`

**Interfaces:**
- Produces: `ProfileFeedUrl::identityOf(SocialNetwork::Lemmy, $url): ?string` → `name@host[:port]` for `/feeds/c/<name>.xml` (unchanged), `@name@host[:port]` for `/feeds/u/<name>.xml` (new); lower-cased, query ignored. `networkOf()` returns `SocialNetwork::Lemmy` for both, so `FeedNetworkListener` classifies user feeds on their next flush. `ProfileFeedUrl::of(SocialNetwork::Lemmy, '@…')` stays null.

- [ ] **Step 1: Write the failing tests.** In `ProfileFeedUrlTest::communityFeeds()` replace the `'a user feed' => […, null]` row and add:

```php
yield 'a user feed' => ['https://feddit.example/feeds/u/ada.xml', '@ada@feddit.example'];
yield 'a user feed with capitals and a query' => ['https://Feddit.Example/feeds/u/Ada.xml?sort=New', '@ada@feddit.example'];
yield 'a user feed on a port' => ['https://feddit.example:8443/feeds/u/ada.xml', '@ada@feddit.example:8443'];
yield 'a nested user path' => ['https://feddit.example/feeds/u/a/b.xml', null];
```

and new tests:

```php
public function testAUserAndACommunityOfOneNameAreTwoIdentities(): void
{
    self::assertSame('ada@feddit.example', ProfileFeedUrl::identityOf(SocialNetwork::Lemmy, 'https://feddit.example/feeds/c/ada.xml'));
    self::assertSame('@ada@feddit.example', ProfileFeedUrl::identityOf(SocialNetwork::Lemmy, 'https://feddit.example/feeds/u/ada.xml'));
}

public function testOfBuildsNoUserFeed(): void
{
    self::assertNull(ProfileFeedUrl::of(SocialNetwork::Lemmy, '@ada@feddit.example'));
}
```

In `ProfileFeedTitleTest`:

```php
public function testALemmyUserFeedNamesTheUserBeforeTheSite(): void
{
    $title = (new ProfileFeedTitle())->of('https://lemmy.world/feeds/u/lars_pohlmann.xml', 'Lemmy.World - lars_pohlmann');

    self::assertSame('lars_pohlmann - Lemmy.World', $title);
}
```

- [ ] **Step 2: Run** `php bin/phpunit --filter 'ProfileFeedUrlTest|ProfileFeedTitleTest'` — Expected: FAIL (user rows null; title unchanged).
- [ ] **Step 3: Implement.** `private const string LEMMY_USER_PATH = '#^/feeds/u/([^/]+)\.xml$#';` `lemmyIdentity()` returns `'@' . strtolower($name) . '@' . $hostWithPort` on a user-path match. `ProfileFeedTitle::lemmyTitle()` strips the leading `@` before `strstr($identity, '@', true)`.
- [ ] **Step 4: Run** `php bin/phpunit --filter 'ProfileFeedUrlTest|ProfileFeedTitleTest|FollowMatch|ProfileIdentity|ImportedProfilePictures|SocialFeedClassifier|FeedNetworkListener'` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(#2320): lemmy user feeds are lemmy network feeds`

### Task 2: The profile-image sweep runs per-network picture sources

**Files:**
- Create: `backend/src/Service/Social/Social/ProfilePictureSource/ProfilePictureSourceInterface.php`
- Create: `backend/src/Service/Social/Social/ProfilePictureSource/BlueskyProfilePictures.php`
- Modify: `backend/src/Service/Social/Social/ProfileImageSweep.php`
- Modify: `backend/src/Service/Social/Social/Model/ProfileCallBudgetModel.php`, `backend/src/Service/Social/Social/Model/ProfileImageSweepReportModel.php`
- Modify: `backend/src/Repository/FeedRepository.php:203`
- Test: `backend/tests/Service/Social/Social/ProfileImageSweepTest.php` (Bluesky cases move to `backend/tests/Service/Social/Social/ProfilePictureSource/BlueskyProfilePicturesTest.php`), `ProfileCallBudgetModelTest`, `FeedRepositoryProfileImageDueTest`, `ProfileImagesTickSweepTest`, `RefreshProfileImagesHandlerTest` (wiring only)

**Interfaces:**
- Produces:

```php
#[AutoconfigureTag('app.profile_picture_source')]
interface ProfilePictureSourceInterface
{
    /** Records pictures on the due feeds of its network; never flushes. */
    public function refresh(ProfileCallBudgetModel $budget, \DateTimeImmutable $now): ProfileImageSweepReportModel;
}

// ProfileImageSweepReportModel
public function __construct(public int $checked, public int $changed, public int $calls = 0) {}
// adding() sums all three; toLogContext() and ProfileImageSweepJson keep only checked/changed.

// ProfileCallBudgetModel
#[\NoDiscard] public function spending(int $calls): self   // floors at 0

// FeedRepository (replaces findBlueskyProfileFeedsWithImageDue)
/** @return list<Feed> */
public function findProfileFeedsWithImageDue(SocialNetwork $network, \DateTimeImmutable $checkedBefore, int $limit): array

// ProfileImageSweep — signature unchanged
public function __construct(
    #[AutowireIterator('app.profile_picture_source')] private iterable $sources,
    private EntityManagerInterface $entityManager,
    private NaiveUtcClock $clock,
) {}
public function sweep(ProfileCallBudgetModel $budget): ProfileImageSweepReportModel
```

  `BlueskyProfilePictures` carries `#[AsTaggedItem(index: 'bluesky', priority: 20)]` (runs first) and takes today's sweep collaborators (FeedRepository, AppViewClient, LoggerInterface, ProfileVisibilityCache); it reports `calls` = AppView `profiles()` requests made.

- [ ] **Step 1: Write the failing tests.**

```php
// ProfileCallBudgetModelTest
public function testSpendingLeavesTheRest(): void
{
    self::assertSame(1, ProfileCallBudgetModel::calls(2)->spending(1)->calls);
}

public function testSpendingMoreThanLeftLeavesNothing(): void
{
    self::assertTrue(ProfileCallBudgetModel::calls(1)->spending(3)->isEmpty());
}

// FeedRepositoryProfileImageDueTest — existing tests call findProfileFeedsWithImageDue(SocialNetwork::Bluesky, …); new:
public function testLemmyCommunityAndUserFeedsAreDueForLemmyOnly(): void
{
    $community = $this->feed('https://lemmy.world/feeds/c/technology.xml?sort=New', null);
    $user = $this->feed('https://lemmy.world/feeds/u/lars_pohlmann.xml', null);
    $this->feed('https://bsky.app/profile/a.bsky.social/rss', null);

    $lemmy = $this->feeds()->findProfileFeedsWithImageDue(SocialNetwork::Lemmy, new \DateTimeImmutable(self::CHECKED_BEFORE), 10);
    $bluesky = $this->feeds()->findProfileFeedsWithImageDue(SocialNetwork::Bluesky, new \DateTimeImmutable(self::CHECKED_BEFORE), 10);

    self::assertEqualsCanonicalizing(
        [$community->requireId(), $user->requireId()],
        array_map(static fn (Feed $feed): int => $feed->requireId(), $lemmy),
    );
    self::assertNotContains($community, $bluesky);
}

// ProfileImageSweepTest — now about the coordinator; a recording source:
private function source(int $spends, array &$budgets): ProfilePictureSourceInterface
{
    return new class ($spends, $budgets) implements ProfilePictureSourceInterface {
        /** @param list<int> $budgets */
        public function __construct(private readonly int $spends, private array &$budgets) {}
        public function refresh(ProfileCallBudgetModel $budget, \DateTimeImmutable $now): ProfileImageSweepReportModel
        {
            $this->budgets[] = $budget->calls;
            return new ProfileImageSweepReportModel(1, 1, $this->spends);
        }
    };
}

public function testASecondSourceGetsOnlyTheCallsTheFirstLeft(): void
{
    $budgets = [];
    $report = $this->sweepOver([$this->source(1, $budgets), $this->source(1, $budgets)], 2);

    self::assertSame([2, 1], $budgets);
    self::assertSame(2, $report->checked);
    self::assertSame(2, $report->calls);
}

public function testAnExhaustedBudgetSkipsTheRemainingSources(): void
{
    $budgets = [];
    $this->sweepOver([$this->source(2, $budgets), $this->source(0, $budgets)], 2);

    self::assertSame([2], $budgets);
}

public function testAnEmptyBudgetAsksNoSource(): void
{
    $budgets = [];
    $this->sweepOver([$this->source(0, $budgets)], 0);

    self::assertSame([], $budgets);
}
```

  `BlueskyProfilePicturesTest` receives today's `ProfileImageSweepTest` Bluesky cases unchanged in their assertions, calling `refresh(ProfileCallBudgetModel::calls($calls), $this->clock->now())` then `flush()`; add to `testItAsksForAtMostAPageOfActorsPerCall` `self::assertSame(2, $report->calls);`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'ProfileImage|ProfileCallBudget|FeedRepositoryProfileImageDue|RefreshProfileImages|BlueskyProfilePictures|MaintenanceTick'` — Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces. The coordinator body:

```php
$report = ProfileImageSweepReportModel::none();
$now = $this->clock->now();
foreach ($this->sources as $source) {
    if ($budget->isEmpty()) {
        break;
    }
    $spent = $source->refresh($budget, $now);
    $report = $report->adding($spent);
    $budget = $budget->spending($spent->calls);
}
$this->entityManager->flush();

return $report;
```

- [ ] **Step 4: Run** the Step 2 filter and `bin/console debug:container 'App\Service\Social\Social\ProfileImageSweep' --show-arguments` (one tagged source) — Expected: PASS.
- [ ] **Step 5: Commit** — `refactor(#2320): the profile-image sweep runs per-network picture sources`

### Task 3: Lemmy community and user pictures from the Lemmy API

**Files:**
- Create: `backend/src/Service/Social/Social/ProfilePictureSource/LemmyProfilePictures.php`
- Test: `backend/tests/Service/Social/Social/ProfilePictureSource/LemmyProfilePicturesTest.php`

**Interfaces:**
- Consumes: `ProfilePictureSourceInterface`, `ProfileCallBudgetModel`, `ProfileImageSweepReportModel(checked, changed, calls)`, `FeedRepository::findProfileFeedsWithImageDue()` (Task 2); `ProfileFeedUrl::identityOf(SocialNetwork::Lemmy, $url)` (Task 1); `LemmyApiClient::send(ApiRequestModel::get($url)): ApiResponseModel` (anonymous GET, as `LemmyPosts.php:45`), `JsonNodeModel::of($answer->json())`.
- Produces:

```php
#[AsTaggedItem(index: 'lemmy', priority: 10)]
final readonly class LemmyProfilePictures implements ProfilePictureSourceInterface
{
    public const string MOUSE = 'https://join-lemmy.org/static/assets/icons/apple-touch-icon.png';

    public function __construct(
        private FeedRepository $feeds,
        private LemmyApiClient $lemmy,
        private LoggerInterface $logger,
    ) {}

    public function refresh(ProfileCallBudgetModel $budget, \DateTimeImmutable $now): ProfileImageSweepReportModel;
}
```

- [ ] **Step 1: Write the failing tests** (`DbTestCase`; Lemmy answers routed like `LemmyApiClientTest::clientAnswering()` — `RoutedHttpClient` keyed `'GET <url>'`, `UrlGuard(new StaticDnsResolver(['lemmy.world' => ['93.184.216.34'], 'feddit.org' => ['93.184.216.35']]), new IpValidator())`, `new LemmyApiClient(FetchWiring::guardedApiClient($http->client(), $guard))`; subscribed feeds persisted as in `ProfileImageSweepTest::persistSubscribed()`; `refresh()` then `$this->entityManager->flush()`):

```php
private const string NOW = '2026-11-18 12:00:00';
private const string COMMUNITY_API = 'https://lemmy.world/api/v3/community?name=technology';
private const string USER_API = 'https://lemmy.world/api/v3/user?username=lars_pohlmann&limit=1';

public function testACommunityFeedTakesItsCommunityIcon(): void
{
    $feed = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml?sort=New');
    $this->answer(self::COMMUNITY_API, ['community_view' => ['community' => ['icon' => 'https://lemmy.world/pictrs/image/c.png']]]);

    $report = $this->refresh(5);

    self::assertSame('https://lemmy.world/pictrs/image/c.png', $feed->getImageUrl());
    self::assertSame(self::NOW, $feed->getImageCheckedAt()?->format('Y-m-d H:i:s'));
    self::assertSame([1, 1, 1], [$report->checked, $report->changed, $report->calls]);
}

public function testACommunityWithoutAnIconHasNoPicture(): void
{
    $feed = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml');
    $this->answer(self::COMMUNITY_API, ['community_view' => ['community' => ['name' => 'technology']]]);

    $this->refresh(5);

    self::assertNull($feed->getImageUrl());
    self::assertNotNull($feed->getImageCheckedAt());
}

public function testAUserFeedTakesTheUsersAvatar(): void
{
    $feed = $this->lemmyFeed('https://lemmy.world/feeds/u/lars_pohlmann.xml');
    $this->answer(self::USER_API, ['person_view' => ['person' => ['avatar' => 'https://lemmy.world/pictrs/image/a.png']]]);

    $this->refresh(5);

    self::assertSame('https://lemmy.world/pictrs/image/a.png', $feed->getImageUrl());
}

public function testAUserWithoutAnAvatarGetsTheLemmyMouse(): void
{
    $feed = $this->lemmyFeed('https://lemmy.world/feeds/u/lars_pohlmann.xml');
    $this->answer(self::USER_API, ['person_view' => ['person' => ['avatar' => null]]]);

    $this->refresh(5);

    self::assertSame(LemmyProfilePictures::MOUSE, $feed->getImageUrl());
}

public function testAnUnknownActorKeepsItsPicture(): void
{
    $feed = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml', imageUrl: 'https://lemmy.world/old.png');
    $this->answerStatus(self::COMMUNITY_API, Response::HTTP_NOT_FOUND, 'couldnt_find_community');

    $report = $this->refresh(5);

    self::assertSame('https://lemmy.world/old.png', $feed->getImageUrl());
    self::assertNotNull($feed->getImageCheckedAt());
    self::assertSame(0, $report->changed);
}

public function testAnUnreachableInstanceIsSetAsideAndTheNextFeedStillSwept(): void
{
    $down = $this->lemmyFeed('https://feddit.org/feeds/c/linux.xml', imageUrl: 'https://feddit.org/old.png');
    $up = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml');
    $this->answerStatus('https://feddit.org/api/v3/community?name=linux', Response::HTTP_SERVICE_UNAVAILABLE, '');
    $this->answer(self::COMMUNITY_API, ['community_view' => ['community' => ['icon' => 'https://lemmy.world/pictrs/image/c.png']]]);
    $logger = new RecordingLogger();

    $report = $this->refresh(5, $logger);

    self::assertSame('https://feddit.org/old.png', $down->getImageUrl());
    self::assertNotNull($down->getImageCheckedAt());
    self::assertSame('https://lemmy.world/pictrs/image/c.png', $up->getImageUrl());
    self::assertSame([2, 2], [$report->checked, $report->calls]);
    self::assertSame(['warning'], array_column($logger->records, 'level'));
}

public function testTheBudgetCapsTheFeedsAsked(): void
{
    $this->lemmyFeed('https://lemmy.world/feeds/c/a.xml');
    $this->lemmyFeed('https://lemmy.world/feeds/c/b.xml');
    $this->lemmyFeed('https://lemmy.world/feeds/c/c.xml');
    // answer all three; assert exactly two were requested
    $report = $this->refresh(2);

    self::assertSame(2, $report->calls);
    self::assertCount(2, $this->requestedUrls());
}

public function testAnInsecureIconIsNoPicture(): void
{
    $feed = $this->lemmyFeed('https://lemmy.world/feeds/c/technology.xml');
    $this->answer(self::COMMUNITY_API, ['community_view' => ['community' => ['icon' => 'http://lemmy.world/c.png']]]);

    $this->refresh(5);

    self::assertNull($feed->getImageUrl());
}

public function testAUserAndACommunityOfOneNameAskDifferentEndpoints(): void
{
    $this->lemmyFeed('https://lemmy.world/feeds/u/ada.xml');
    $this->lemmyFeed('https://lemmy.world/feeds/c/ada.xml');
    // answer both
    $this->refresh(5);

    self::assertEqualsCanonicalizing(
        ['https://lemmy.world/api/v3/user?username=ada&limit=1', 'https://lemmy.world/api/v3/community?name=ada'],
        $this->requestedUrls(),
    );
}
```

  `answer(string $url, array $body)`, `answerStatus(string $url, int $status, string $errorCode)` (body `{"error": code}` or empty, as `LemmyApiClientTest::errorAnswer`), `requestedUrls(): list<string>` and `lemmyFeed(string $url, ?string $imageUrl = null): Feed` are the test's own helpers; `requestedUrls()` returns the `RoutedHttpClient`'s own `requestedUrls()`, and the routes are registered before the client is built (one `RoutedHttpClient` per test, built in `refresh()`).
- [ ] **Step 2: Run** `php bin/phpunit --filter LemmyProfilePicturesTest` — Expected: FAIL (class missing).
- [ ] **Step 3: Implement.** Due feeds = `findProfileFeedsWithImageDue(SocialNetwork::Lemmy, $now->modify('-7 days'), $budget->calls)`. Identity `@name@host` → `https://host/api/v3/user?username=<rawurlencode(name)>&limit=1`, picture `person_view.person.avatar`, null → `self::MOUSE`; identity `name@host` → `https://host/api/v3/community?name=<rawurlencode(name)>`, picture `community_view.community.icon`. `SocialPostNotFoundException` → keep picture, stamp. `SocialNetworkThrottledException` / `SocialNetworkUnreachableException` → keep picture, stamp, `logger->warning('Lemmy profile picture check set aside a feed its instance refused.', ['feed' => $feed->getUrl(), 'exception' => $exception])`. One call counted per request made; a feed with a null identity is stamped without a call.
- [ ] **Step 4: Run** the test, `php bin/phpunit --filter 'ProfileImageSweep|BlueskyProfilePictures'`, and `bin/console debug:container 'App\Service\Social\Social\ProfileImageSweep' --show-arguments` (two sources, bluesky before lemmy) — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(#2320): lemmy community and user feeds take their picture from the lemmy api`

### Task 4: FaviconResolver forgets Lemmy actors

**Files:**
- Delete: `backend/src/Service/Outbound/Fetch/Support/LemmyActorPage.php`, `backend/tests/Service/Outbound/Fetch/Support/LemmyActorPageTest.php`
- Rename: `backend/src/Service/Outbound/Fetch/Support/LemmyPageIcon.php` → `LemmySiteIcon.php` (`git mv`), keeping `RECORD_BODY` (with its possessive-match comment), `SITE_RECORD` and the decode helpers
- Modify: `backend/src/Service/Outbound/Fetch/FaviconResolver/FaviconResolver.php`
- Modify: `backend/tests/Service/Outbound/Fetch/FaviconResolver/FaviconResolverTest.php`
- Create: `backend/migrations/Version20261118090000.php`, `backend/tests/Migrations/Version20261118090000Test.php`

**Interfaces:**
- Produces: `LemmySiteIcon::in(string $html): ?string` (the site record's https icon). `FaviconResolver` keys tickets by origin again; `iconFrom()` returns `$this->pickIcon($body, new PageUrls($response->finalUrl)) ?? LemmySiteIcon::in($body)`; class docblock and log context (`origin`) as on `9e938963d`.

- [ ] **Step 1: Write the failing tests.** Remove from `FaviconResolverTest`: `lemmyActorRecords`, `testALemmyActorIconOutranksItsInstanceIcon`, `lemmyActorFeeds`, `testALemmyActorFeedFetchesItsActorPageOnce`, `testAnUnreachableActorPageFallsBackToTheOriginFaviconIco`, and the `$actorFragment`/`$body` parameters of `lemmyPage()`; the `lemmySiteRecords` test stubs `https://lemmy.example.com` again. Add:

```php
public function testALemmyCommunityFeedTakesItsInstanceIcon(): void
{
    $fetcher = new StubFeedFetcher();
    $fetcher->willReturn('https://lemmy.example.com', $this->page(
        '<link id="favicon" rel="shortcut icon" type="image/x-icon" href=https://lemmy.example.com/pictrs/image/site.png />',
        'https://lemmy.example.com/',
    ));

    $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://lemmy.example.com/feeds/c/technology.xml']);

    self::assertSame(['https://lemmy.example.com'], $fetcher->fetchedUrls);
    self::assertSame('https://lemmy.example.com/pictrs/image/site.png', $icons[1]);
}
```

  `Version20261118090000Test` copies `Version20261117230000Test` with the class name changed (same five URLs: three cleared, two kept).
- [ ] **Step 2: Run** `php bin/phpunit --filter 'FaviconResolverTest|Version20261118090000Test'` — Expected: FAIL (community page fetched; migration missing).
- [ ] **Step 3: Implement** the removals and the migration: `UPDATE feed SET favicon_url = NULL WHERE url LIKE 'https://%/feeds/c/%' OR url LIKE 'https://%/feeds/u/%'`, description `Lemmy community and user feeds resolve their favicon again, as their instance icon (#2320).`, `down()` → `throwIrreversibleMigrationException('The cleared icons are not kept; a refresh sets them again.')`. `Version20261117230000` stays (already run on Strato).
- [ ] **Step 4: Run** the filter; `bin/console doctrine:migrations:migrate --no-interaction && bin/console doctrine:schema:validate` natively and the same through `docker compose exec -T php` — Expected: PASS / migrated / MySQL schema in sync.
- [ ] **Step 5: Commit** — `refactor(#2320): the favicon resolver no longer scrapes lemmy actors`

### Task 5: Gates and live check

- [ ] **Step 1:** `composer check`, `composer md`, PhpStorm lint on changed PHP, `php bin/phpunit` and `docker compose exec -T php composer test:parallel`, `composer infection:diff` — Expected: all green.
- [ ] **Step 2: Live (Docker).** `docker compose restart worker`; `docker compose exec -T php bin/console cache:clear`; trigger the sweep until the eight `network = 'lemmy'` feeds are checked (`docker compose exec -T php bin/console messenger:consume scheduler_default --limit=…` or the maintenance tick endpoint). Expected: feed 892 `image_url` = `https://lemmy.world/pictrs/image/da958314-efea-4b90-b3a5-38c4b47a1b30.png`; after `app:feeds:refresh --feed=892 --force`, `favicon_url` = `https://lemmy.world/pictrs/image/0fd47927-ca3a-4d2c-b2e4-a25353786671.png`; `http://localhost:4200/?subscription=2750` shows the community logo as the feed avatar in the sidebar and heading. Subscribe the dev account to `https://lemmy.world/feeds/u/lars_pohlmann.xml` and `https://lemmy.world/feeds/u/inclementimmigrant.xml`, sweep, expect the avatar and `LemmyProfilePictures::MOUSE`; unsubscribe both. Scan today's dev log.
