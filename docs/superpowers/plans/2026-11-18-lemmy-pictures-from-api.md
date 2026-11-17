# Lemmy Pictures From the API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lemmy community and user feeds get their picture from the public Lemmy API into `Feed.imageUrl` through the profile-image sweep, and `FaviconResolver` loses the Lemmy actor scraping #2316 added (#2320).

**Architecture:** A network feed's own picture lives in `Feed.imageUrl` (#1599), shown by the frontend as the feed's avatar (`feedAvatarUrl`, for `social.kind = 'profile'`) in front of the site favicon. `ProfileImageSweep` fills it weekly, Bluesky only today. It becomes a coordinator over per-network `ProfilePictureSourceInterface` sources (tag + keyed locator, the `FeedBodyParser` pattern): the existing Bluesky logic moves into `BlueskyProfilePictures`, and a new `LemmyProfilePictures` asks `GET /api/v3/community?name=` or `GET /api/v3/user?username=` on the feed's instance. Lemmy user feeds (`/feeds/u/<name>.xml`) become Lemmy network feeds with the identity `@name@host`, so they never collide with a community's `name@host`. `faviconUrl` goes back to the instance icon (#2298).

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM/Migrations, PHPUnit 12.

**Spec:** GitHub issue #2320; decisions from Lars in session: avatar-less users get the mouse `https://join-lemmy.org/static/assets/icons/apple-touch-icon.png`; Lemmy user feeds are classified as Lemmy feeds.

## Global Constraints

- CLAUDE.md binds: Clean Code names, default to no comment, `final readonly` services, one role per folder, queries only in `src/Repository`, every touched `src` file PHPMD-clean, `composer check` green, no tramp data (4+ hops across 2+ classes).
- Commit messages `type(#2320): lower-case summary`; no Claude attribution.
- A community without an icon gets no picture (`imageUrl` stays null, the instance favicon shows). Only a user without an avatar gets the mouse.
- Mouse URL, verbatim: `https://join-lemmy.org/static/assets/icons/apple-touch-icon.png`.
- Picture URLs pass `AbsoluteHttpUrl::orNullWithin($url, Feed::IMAGE_URL_MAX)`, as Bluesky's do.
- The Bluesky sweep's behaviour (25 actors per call, throttle, set-aside, visibility labels, 7-day refresh) is unchanged; its existing tests stay green unchanged except for constructor wiring.
- Lemmy refresh interval: the same `-7 days` as Bluesky.

## Review Focus

- A community or user the instance answers 404 / `couldnt_find_community` / `couldnt_find_person` for → stamped as checked, existing `imageUrl` kept, the sweep moves on.
- An unreachable or throttling Lemmy instance → that feed stamped as checked and logged at warning; other feeds (other instances) still swept.
- A Lemmy feed URL with a query (`/feeds/c/technology.xml?sort=New`) or an upper-case name → same identity and API call as without.
- A user feed and a community feed with the same name on one instance → two identities (`@ada@host`, `ada@host`), two different API calls.
- Budget: with a budget of N calls, Bluesky and Lemmy together make at most N calls; Lemmy spends one call per feed.

---

### Task 1: Lemmy user feeds are Lemmy network feeds

**Files:**
- Modify: `backend/src/Service/Social/Social/Support/ProfileFeedUrl.php` (`LEMMY_COMMUNITY_PATH` l.16, `lemmyIdentity` l.148-151)
- Modify: `backend/src/Service/Social/Social/ProfileFeedTitle.php` (`lemmyTitle` l.38+)
- Test: `backend/tests/Service/Social/Social/Support/ProfileFeedUrlTest.php` (provider `communityFeeds` l.86-98), the existing `ProfileFeedTitle` test

**Interfaces:**
- Produces: `ProfileFeedUrl::identityOf(SocialNetwork::Lemmy, $url)` returns `name@host[:port]` for `/feeds/c/<name>.xml` (unchanged) and `@name@host[:port]` for `/feeds/u/<name>.xml` (new), lower-cased, query ignored. `ProfileFeedUrl::networkOf()` therefore returns Lemmy for user feeds, and `FeedNetworkListener` classifies them on their next flush.

- [ ] **Step 1: Write the failing tests.** In `ProfileFeedUrlTest`, change `'a user feed' => [.../feeds/u/ada.xml, null]` to expect `'@ada@<host>'`; add `'a user feed with a query and capitals'` (`https://Lemmy.World/feeds/u/Ada.xml?sort=New` → `'@ada@lemmy.world'`); add a `networkOf` case for a user feed → `SocialNetwork::Lemmy`; keep the community cases. Assert `ProfileFeedUrl::of(SocialNetwork::Lemmy, '@ada@lemmy.world')` is null (the builder only makes community feeds). In the `ProfileFeedTitle` test add: a user feed `https://lemmy.world/feeds/u/lars_pohlmann.xml` with channel title `Lemmy.World - lars_pohlmann` gets the same "name first, then site" title a community gets (`lars_pohlmann - Lemmy.World` — copy the community case's exact format from the existing test).
- [ ] **Step 2: Run** `php bin/phpunit --filter 'ProfileFeedUrlTest|ProfileFeedTitleTest'` — FAIL.
- [ ] **Step 3: Implement.** Add `LEMMY_USER_PATH = '#^/feeds/u/([^/]+)\.xml$#'`; `lemmyIdentity` returns `'@' . name . '@' . hostWithPort` for it. In `ProfileFeedTitle::lemmyTitle` take the name from the identity with a leading `@` stripped (`ltrim(…, '@')` before `strstr`).
- [ ] **Step 4: Run** the filter, then grep callers of `identityOf(SocialNetwork::Lemmy` / `ProfileIdentity::of` / `FollowMatching` and run their tests (`php bin/phpunit --filter 'FollowMatch|ProfileIdentity|ImportedProfilePictures|SocialFeedClassifier|FeedNetworkListener'`) — PASS.
- [ ] **Step 5: Commit** — `feat(#2320): lemmy user feeds are lemmy network feeds`

### Task 2: The profile-image sweep runs per-network picture sources

**Files:**
- Create: `backend/src/Service/Social/Social/ProfilePictureSource/ProfilePictureSourceInterface.php`
- Create: `backend/src/Service/Social/Social/ProfilePictureSource/BlueskyProfilePictures.php` (the Bluesky body of today's `ProfileImageSweep`, moved)
- Modify: `backend/src/Service/Social/Social/ProfileImageSweep.php` (coordinator)
- Modify: `backend/src/Service/Social/Social/Model/ProfileCallBudgetModel.php`, `.../Model/ProfileImageSweepReportModel.php`
- Modify: `backend/src/Repository/FeedRepository.php:203` (`findBlueskyProfileFeedsWithImageDue` → `findProfileFeedsWithImageDue`)
- Test: `ProfileImageSweepTest`, `ProfileImagesTickSweepTest`, `RefreshProfileImagesHandlerTest`, `ProfileCallBudgetModelTest`, `FeedRepositoryProfileImageDueTest`, `MaintenanceTickJsonTest` (only if the report shape it prints changes — it must not)

**Interfaces:**
- Produces:
  - `interface ProfilePictureSourceInterface { public function refresh(ProfileCallBudgetModel $budget, \DateTimeImmutable $now): ProfileImageSweepReportModel; }` — tagged (`#[AutoconfigureTag('app.profile_picture_source')]`), each implementation `#[AsTaggedItem(index: '<network value>', priority: …)]`, Bluesky priority higher than Lemmy so it runs first. A source records pictures on the feeds; it does not flush.
  - `ProfileImageSweepReportModel(int $checked, int $changed, int $calls = 0)` — `calls` = API calls spent; `adding()` sums it; `toLogContext()` and `ProfileImageSweepJson` keep reporting only `checked`/`changed`.
  - `ProfileCallBudgetModel::spending(int $calls): self` (`#[\NoDiscard]`, floors at 0).
  - `FeedRepository::findProfileFeedsWithImageDue(SocialNetwork $network, \DateTimeImmutable $checkedBefore, int $limit): array` (list<Feed>) — today's query with `NetworkScope::profiles($network)`.
  - `ProfileImageSweep::sweep(ProfileCallBudgetModel $budget): ProfileImageSweepReportModel` keeps its signature: for each source in tag order, `refresh($remaining, $now)`, add its report, `$remaining = $remaining->spending($report->calls)`, stop when empty; one `flush()` at the end.

- [ ] **Step 1: Write the failing tests.** `ProfileCallBudgetModelTest`: `calls(2)->spending(1)->calls === 1`, `calls(1)->spending(3)->isEmpty()`. `FeedRepositoryProfileImageDueTest`: switch to `findProfileFeedsWithImageDue(SocialNetwork::Bluesky, …)`; add a Lemmy case — a subscribed `https://lemmy.world/feeds/c/technology.xml?sort=New` feed is due for `SocialNetwork::Lemmy` and not for Bluesky. `ProfileImageSweepTest`: build the sweep over a sources list; add `testASecondSourceGetsOnlyTheCallsTheFirstLeft` with two test sources (anonymous classes implementing the interface, recording the budget they got; the first reports `calls: 1` from a budget of 2 → the second receives 1) and `testAnExhaustedBudgetSkipsTheRemainingSources`. Existing Bluesky tests keep their assertions, now via `BlueskyProfilePictures`, and additionally assert the Bluesky report's `calls` equals the number of AppView calls made.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'ProfileImage|ProfileCallBudget|FeedRepositoryProfileImageDue|RefreshProfileImages|MaintenanceTick'` — FAIL.
- [ ] **Step 3: Implement** per Interfaces. `ProfileImageSweep` takes `#[AutowireIterator('app.profile_picture_source')] iterable $sources`, `EntityManagerInterface`, `NaiveUtcClock`. `BlueskyProfilePictures` takes over every Bluesky collaborator (AppViewClient, ProfileVisibilityCache, logger, FeedRepository) and the `isThrottled` guard; it counts one call per AppView `profiles()` request it makes.
- [ ] **Step 4: Run** the filter and `bin/console debug:container App\\Service\\Social\\Social\\ProfileImageSweep --show-arguments` (one source listed) — PASS.
- [ ] **Step 5: Commit** — `refactor(#2320): the profile-image sweep runs per-network picture sources`

### Task 3: Lemmy community and user pictures from the Lemmy API

**Files:**
- Create: `backend/src/Service/Social/Social/ProfilePictureSource/LemmyProfilePictures.php`
- Test: `backend/tests/Service/Social/Social/ProfilePictureSource/LemmyProfilePicturesTest.php` (DbTestCase, like `ProfileImageSweepTest`; Lemmy answers via `LemmyApiClient` over `FetchWiring::guardedApiClient(new MockHttpClient(...), …)` as `LemmyApiClientTest` does)

**Interfaces:**
- Consumes: `ProfilePictureSourceInterface`, `ProfileCallBudgetModel`, `ProfileImageSweepReportModel(checked, changed, calls)`, `FeedRepository::findProfileFeedsWithImageDue(SocialNetwork::Lemmy, …)` (Task 2); `ProfileFeedUrl::identityOf(SocialNetwork::Lemmy, $url)` → `name@host` / `@name@host` (Task 1); `LemmyApiClient::send(ApiRequestModel::get($url))` (anonymous GET, as `LemmyPosts.php:45`).
- Produces: `final readonly class LemmyProfilePictures implements ProfilePictureSourceInterface`, `#[AsTaggedItem(index: 'lemmy')]`, `public const string MOUSE = 'https://join-lemmy.org/static/assets/icons/apple-touch-icon.png'`.

- [ ] **Step 1: Write the failing tests.** Each test subscribes a feed and runs `refresh(ProfileCallBudgetModel::calls(5), $now)`:
  - `testACommunityFeedTakesItsCommunityIcon` — `https://lemmy.world/feeds/c/technology.xml?sort=New`, answer to `GET https://lemmy.world/api/v3/community?name=technology` = `{"community_view":{"community":{"icon":"https://lemmy.world/pictrs/image/c.png"}}}` → `imageUrl` = that icon, `imageCheckedAt` = now, report `checked 1, changed 1, calls 1`.
  - `testACommunityWithoutAnIconHasNoPicture` — community record without `icon` → `imageUrl` null, checked stamped.
  - `testAUserFeedTakesTheUsersAvatar` — `https://lemmy.world/feeds/u/lars_pohlmann.xml`, `GET https://lemmy.world/api/v3/user?username=lars_pohlmann&limit=1` = `{"person_view":{"person":{"avatar":"https://lemmy.world/pictrs/image/a.png"}}}` → that avatar.
  - `testAUserWithoutAnAvatarGetsTheLemmyMouse` — `"avatar":null` → `LemmyProfilePictures::MOUSE`.
  - `testAnUnknownActorKeepsItsPicture` — 404 `{"error":"couldnt_find_community"}`, feed pre-set with `imageUrl` `https://lemmy.world/old.png` → kept, checked stamped, `changed 0`.
  - `testAnUnreachableInstanceIsSetAsideAndTheNextFeedStillSwept` — two feeds on different hosts, first host answers 503, second answers an icon → first stamped and unchanged, warning logged (RecordingLogger), second updated; report `checked 2, calls 2`.
  - `testTheBudgetCapsTheFeedsAsked` — three due feeds, `calls(2)` → exactly two requests.
  - `testAnInsecureIconIsNoPicture` — `http://` icon → `imageUrl` null (AbsoluteHttpUrl rules).
  - `testAUserAndACommunityOfOneNameAskDifferentEndpoints` — `/feeds/u/ada.xml` and `/feeds/c/ada.xml` on one host → one `user?username=ada` and one `community?name=ada` request.
- [ ] **Step 2: Run** `php bin/phpunit --filter LemmyProfilePicturesTest` — FAIL (class missing).
- [ ] **Step 3: Implement.** Due feeds = `findProfileFeedsWithImageDue(SocialNetwork::Lemmy, $now->modify('-7 days'), $budget->calls)`. Per feed: split the identity into name and `hostWithPort`; a leading `@` means user. Base `https://<hostWithPort>/api/v3/`; names go through `rawurlencode`. Catch `SocialPostNotFoundException` → keep picture, stamp; catch `SocialNetworkThrottledException`/`SocialNetworkUnreachableException` → keep picture, stamp, log warning `Lemmy profile picture check set aside a feed its instance refused.` with the exception and feed url; one call counted per request made. A feed whose identity is null (not a Lemmy profile URL) is stamped without a call.
- [ ] **Step 4: Run** the test, `ProfileImageSweepTest`, and `bin/console debug:container App\\Service\\Social\\Social\\ProfileImageSweep --show-arguments` (two sources, Bluesky first) — PASS.
- [ ] **Step 5: Commit** — `feat(#2320): lemmy community and user feeds take their picture from the lemmy api`

### Task 4: FaviconResolver forgets Lemmy actors

**Files:**
- Delete: `backend/src/Service/Outbound/Fetch/Support/LemmyActorPage.php`, `backend/tests/Service/Outbound/Fetch/Support/LemmyActorPageTest.php`
- Modify: `backend/src/Service/Outbound/Fetch/Support/LemmyPageIcon.php` → back to `LemmySiteIcon.php` (`git mv`), keeping only the site record (`SITE_RECORD`, `RECORD_BODY` with its possessive-match comment) and `in(string $html): ?string` returning the site icon
- Modify: `backend/src/Service/Outbound/Fetch/FaviconResolver/FaviconResolver.php` — origin-keyed tickets again (no `$pages`), `iconFrom` = `$this->pickIcon($body, new PageUrls($response->finalUrl)) ?? LemmySiteIcon::in($body)`, docblocks and log keys (`origin`) as on `9e938963d`
- Modify: `backend/tests/Service/Outbound/Fetch/FaviconResolver/FaviconResolverTest.php` — remove `lemmyActorRecords`, `testALemmyActorIconOutranksItsInstanceIcon`, `lemmyActorFeeds`, `testALemmyActorFeedFetchesItsActorPageOnce`, `testAnUnreachableActorPageFallsBackToTheOriginFaviconIco`, the `lemmyPage()` actor parameters; Lemmy site-record tests stub the origin `https://lemmy.example.com` again; `testFeedsOnOneSiteShareASingleHomepageFetch` may keep `/blog/one`, `/blog/two`.
- Create: `backend/migrations/Version20261118090000.php` + `backend/tests/Migrations/Version20261118090000Test.php`

**Interfaces:**
- Consumes: nothing from Tasks 1-3.
- Produces: `faviconUrl` of a Lemmy community or user feed is the instance icon again.

- [ ] **Step 1: Write the failing tests.** Resolver: `testALemmyCommunityFeedTakesItsInstanceIcon` — base `https://lemmy.example.com/feeds/c/technology.xml`, stub only `https://lemmy.example.com` with a page holding the real `<link id="favicon" rel="shortcut icon" type="image/x-icon" href=https://lemmy.example.com/pictrs/image/site.png />` and a `community_view` record with another icon → `fetchedUrls === ['https://lemmy.example.com']`, icon `…/site.png`. Migration test (style of `Version20261117230000Test`): `up()` sets `favicon_url = NULL` for `https://lemmy.world/feeds/c/a.xml`, `…/feeds/c/technology.xml?sort=New`, `…/feeds/u/lars.xml`, keeps `https://lemmy.world/feeds/local.xml` and `https://blog.example.com/feed.xml`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'FaviconResolverTest|Version20261118090000Test'` — FAIL.
- [ ] **Step 3: Implement** the removals and the migration (`UPDATE feed SET favicon_url = NULL WHERE url LIKE 'https://%/feeds/c/%' OR url LIKE 'https://%/feeds/u/%'`, description `Lemmy community and user feeds resolve their favicon again, as their instance icon (#2320).`, irreversible `down()`). Leave `Version20261117230000` as it is (already run on Strato).
- [ ] **Step 4: Run** the filter; migrate SQLite natively and MySQL in Docker (`doctrine:migrations:migrate --no-interaction`, then `doctrine:schema:validate`) — PASS.
- [ ] **Step 5: Commit** — `refactor(#2320): the favicon resolver no longer scrapes lemmy actors`

### Task 5: Gates and live check

- [ ] `composer check`, `composer md`, PhpStorm lint on changed PHP, `php bin/phpunit` and `docker compose exec php composer test:parallel`, `composer infection:diff`.
- [ ] Live (Docker; restart `worker` first): run the sweep (`bin/console` message or `RefreshProfileImagesHandler` via `messenger:consume` / the maintenance tick) until all eight lemmy feeds are checked. Expect: 892 `image_url` = `https://lemmy.world/pictrs/image/da958314-efea-4b90-b3a5-38c4b47a1b30.png`, its `favicon_url` = the lemmy.world instance icon `…/0fd47927….png` after a refresh; `http://localhost:4200/?subscription=2750` shows the community logo as the feed avatar in the sidebar and heading. Subscribe the dev account to `https://lemmy.world/feeds/u/lars_pohlmann.xml` and an avatar-less user feed; check avatar and mouse; unsubscribe both afterwards. Scan today's dev log.
