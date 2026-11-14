# Lemmy as a feed network — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lemmy becomes a network like Bluesky and Mastodon: community RSS feeds and account timelines group under one Lemmy section, the Lemmy account gets "Manage feeds…" (Communities, Timelines, Search), and every list of networks lists Lemmy.

**Architecture:** The part-1 split (`carriesFeeds()`, `feedNetworks()`, `FEED_NETWORK_REQUIREMENT`, `tryFromFeed()`, `NetworkWithoutFeedsException`; frontend `ACCOUNT_NETWORKS` / `AccountNetworkName` / `FeedAccountRefDto`) is dismantled site by site. Each guard disappears in the commit that gives Lemmy the behaviour the guard was keeping out, so every commit is green and shippable; the first commit changes no behaviour. Lemmy implements the per-network strategies the others implement: `ProfileFeedUrl` (community RSS), `PrivateFeedRules`, `PrivateFeedSite`, `LemmyTimelineSource`, `LemmyCandidateSource`, and a Lemmy arm in `NetworkSocialSearch`. One root service, `LemmyPostEntries`, turns PostViews into entries for timelines, search feeds and the search preview, shaped like a Lemmy RSS entry so `LemmyPostAddress::of` (votes, replies, thread) matches unchanged.

**Tech Stack:** Symfony 8.1 / PHP 8.5 / Doctrine (SQLite + MySQL), Lemmy API v3 (0.19), Angular 20 standalone + signals, Transloco, Jest.

**Spec:** `docs/superpowers/specs/2026-11-14-2218-lemmy-feed-network-design.md` (binding, incl. "Builder questions, settled" and "Search subscriptions"). Background: `docs/superpowers/specs/2026-11-14-2218-lemmy-account-design.md`, `docs/superpowers/plans/2026-11-14-2218-lemmy-account.md`.

## Plan notes vs design

1. **The link of a timeline post.** The spec says "the link is the post's external `url` when it has one, otherwise `ap_id`", and also that a timeline post is "shaped so that `LemmyPostAddress::of` matches" "in the same shape as a Lemmy RSS entry". `LemmyPostAddress::of` requires `Entry::isDiscussionThread()`, i.e. `url === null` (`ParsedEntryModel::asDiscussionThread` nulls it, and the RSS path does exactly that). Ruling: the entry's `url` stays `null`, the discussion is the post page, and the external link leads the body as `<p><a href="{url}">{url}</a></p>` — the shape the Lemmy RSS body already has (`politics.xml`). *If wrong:* votes and the thread would stop working for link posts, which is why the settled rule wins.
2. **A post whose `ap_id` is no Lemmy post page** (PieFed/Mbin origins such as `https://mbin.example/m/rss/t/9`) would give no `LemmyPostAddress`. Ruling: the discussion thread is then the home-instance page `{serviceUrl}/post/{post.id}`, which `LemmyPostUrl::parse` accepts; the guid stays the `ap_id`.
3. **`carriesFeeds()` lives until Task 5.** Removing all guards in Task 1 would let Lemmy reach code that throws (`PrivateFeeds::sourceFormatOf`) before Lemmy has a timeline source. Task 1 removes only what changes nothing; Tasks 2–5 each remove the guards their behaviour makes safe, and Task 5 deletes the enum members, `tryFromFeed()` and `NetworkWithoutFeedsException`.
4. **The route requirement stays a constant.** A `#[Route]` requirement must be a constant expression, so `FEED_NETWORK_REQUIREMENT = 'bluesky|mastodon'` becomes `SocialNetwork::ROUTE_REQUIREMENT = 'bluesky|mastodon|lemmy'`, pinned by a test against `cases()`. Unknown networks keep answering 404.
5. **Lemmy search is judged by the instance alone.** Lemmy's query is plain text, so the reader-side matcher (`SocialSearchMatch`, written for Bluesky/Mastodon operators) is not applied to Lemmy results, and a requery of a Lemmy search removes no stored posts (the stored-post check reads only the body, and Lemmy matches titles too). Expressed as the capability `SocialNetwork::checksSearchResults(): bool` (Lemmy false), the second explicit capability beside `takesLinkPosts()`.
6. **Picker labels (left to the builder).** The tab keys stay `accounts` / `lists` / `search`; for Lemmy the segmented choice reads `settings.social.importTabLemmy.{accounts,lists,search}` = "Communities" / "Timelines" / "Search". Singleton rows: Home is labelled "Subscribed", Local "Local", All "All" (`settings.social.importLemmy{Subscribed,Local,All}`). A community already subscribed is matched **by URL** (`ProfileFeedUrl::identityOf`), the preferred option.
7. **Lemmy 0.19 API, checked during planning** against lemmy-js-client **0.19.6** (0.19.5 exists only as `-alpha.1` on npm): `GET /api/v3/community/list` takes `{type_?, sort?, show_nsfw?, page?, limit?}` → `{communities: CommunityView[]}`, `CommunityView.community {id, name, title, actor_id, icon?, …}`; `GET /api/v3/post/list` takes `{type_?: "All"|"Local"|"Subscribed"|"ModeratorView", sort?, page?, limit?, page_cursor?, …}` → `{posts: PostView[], next_page?}`; `GET /api/v3/search` takes `{q, type_?: "All"|"Comments"|"Posts"|"Communities"|"Users"|"Url", sort?, listing_type?, page?, limit?, …}` → `{type_, comments, posts: PostView[], communities, users}`. `PostView {post, creator, community, counts, my_vote?, …}`; `Post {id, name, url?, body?, thumbnail_url?, ap_id, published, deleted, removed, nsfw, …}`; `PostAggregates {score, comments, …}`. Community list has no cursor: paging is by `page` until a short page.

## Global Constraints

- Lemmy 0.19, API v3; every call through `LemmyApiClient` → `GuardedApiClient`; the JWT only to the account's own `serviceUrl` (`LemmyAccountApi`).
- Requests: communities `/api/v3/community/list?type_=Subscribed&sort=Old&limit=50&page=N` (N from 1; stop at a page of fewer than 50 or past `CollectedItemsModel::MAX_ITEMS`); timelines `/api/v3/post/list?type_=Subscribed|Local|All&sort=New&limit=50`; search `/api/v3/search?q=<rawurlencoded>&type_=Posts&sort=New&listing_type=All&limit=50`.
- Community feed URL `https://<community host>/feeds/c/<name>.xml`, identity `<name>@<host>` lower-cased, handle `!<name>@<host>`; `/feeds/u/…` never classifies.
- `SocialFeedKind::Local = 'local'`, `SocialFeedKind::All = 'all'`; URL segments `local`, `all`; Subscribed is `Home`. `PrivateFeedModel::local()` name `Local`, `::all()` name `All`.
- `SourceFormat::LEMMY_API = 'lemmy-api'`, in `SOCIAL` and `NOT_PROFILES`. Community RSS feeds keep `SourceFormat::XML`; only `Feed.network` becomes `lemmy`.
- Timeline entry: guid `post.ap_id`; `url` null; discussion `Discussion::platformThread(<post page>)`; headline `post.name`; author `creator.display_name ?? creator.name`, authorUrl `creator.actor_id`, avatar `creator.avatar`; body = optional link paragraph + `MarkdownHtml::of(post.body)`; published `DateParser::parse(post.published)` (UTC); image `post.thumbnail_url`; counts `DiscussionCounts::of(counts.score, counts.comments)`; `newestId` = highest `post.id` as a string (recorded, never sent). Deleted or removed posts are skipped. `entry.social` stays null.
- Capabilities on `SocialNetwork`: `takesLinkPosts()` (Lemmy false), `checksSearchResults()` (Lemmy false). No other per-network filter; every enumeration is `cases()` / `SOCIAL_NETWORKS`.
- No new endpoint, no new problem type, no new limiter. A Lemmy failure inside a timeline round is `SocialCredentialsRejectedException` or `SocialNetworkUnreachableException` only.
- Frontend: `SOCIAL_NETWORKS = ['bluesky', 'mastodon', 'lemmy']`; `ACCOUNT_NETWORKS`, `AccountNetworkName`, `FeedAccountRefDto` deleted; `LINK_POST_NETWORKS = ['bluesky', 'mastodon']` drives the share menu.
- Transloco keys in `frontend/public/i18n/{en,de,fr,es}.json` (fr *vous*, es *tú*, de as the dictionary already addresses the reader).
- Fixtures are synthetic (`lemmy.example`, `feddit.example`, `news.example`, `mbin.example`); never copied third-party data; JWT `test-jwt-not-a-real-token`.
- CLAUDE.md rules: Clean Code, `composer check && composer md` clean on every touched `src` file (`bin/console cache:warmup` before `composer stan`), no PHPStan docblocks/ignores/rule edits, no module cycle (Content/Comments imports nothing from Social/Social), PHPMD fixed by design. Commits `type(#2218): …`, no attribution lines. Never run the SQLite and MySQL full suites at the same time.

## Review Focus

1. **A timeline post must stay votable and threaded.** A link post, a text post and a post from a non-Lemmy origin all give an entry `LemmyPostAddress::of` accepts. → Task 3 `testEveryPostIsALemmyDiscussionThread`, `testAnOriginThatIsNoLemmyPostPageUsesTheHomeInstancePage`.
2. **Community URLs that are not community feeds.** `/feeds/u/ada.xml`, `/feeds/c/rss` (no `.xml`), `/feeds/c/a/b.xml`, a `ftp:` URL must not classify; `?sort=New` on a real one must. → Task 2 `ProfileFeedUrlTest` data provider.
3. **A Lemmy error inside a timeline round** (`couldnt_find_post`, `banned_from_community`) must fail that feed, not the round. → Task 3 `testARefusalInARoundIsUnreachable`.
4. **Already-subscribed communities** under a URL with a query string or upper-case name show as subscribed, not as new. → Task 4 `testACommunityAlreadySubscribedByUrlIsSubscribed`.
5. **Times with an offset or fractional seconds** (`2026-11-14T05:05:09.123456Z`, `…+02:00`) are stored as naive UTC. → Task 3 `testPublishedTimesAreUtc`.

---

### Task 1: Generalise the network code without changing behaviour

**Files:**
- Modify: `backend/src/Enum/SocialNetwork.php`, `backend/src/Dto/Social/LinkTargetQuery.php`, `backend/src/Service/Social/Social/Support/{ProfileFeedUrl,OwnReplyGuids,OwnPostIds,PrivateFeedSyncReasons}.php`
- Test: `backend/tests/Enum/SocialNetworkTest.php`, `backend/tests/Service/Social/Social/Support/{OwnReplyGuidsTest,OwnPostIdsTest,PrivateFeedSyncReasonsTest}.php`, `backend/tests/Controller/Api/SocialLinkPostControllerTest.php`

**Interfaces:**
- Produces: `SocialNetwork::takesLinkPosts(): bool` (Bluesky, Mastodon true; Lemmy false). `LinkTargetQuery::isLinkPostNetwork(): bool` (renamed from `isFeedNetwork`, same `#[Assert\IsTrue(message: 'This network takes no link posts.')]`).

- [ ] **Step 1: Re-check the API.** Fetch `https://cdn.jsdelivr.net/npm/lemmy-js-client@0.19.6/dist/types/{GetPosts,GetPostsResponse,ListCommunities,ListCommunitiesResponse,Search,SearchResponse,PostView,Post,PostAggregates,CommunityView,Community,Person,ListingType,SearchType}.d.ts` and `dist/http.js` into the scratchpad; confirm Plan note 7 (paths `/community/list`, `/post/list`, `/search`, all GET). Stop and report any mismatch. Nothing is committed from this step.
- [ ] **Step 2: Failing tests**
  - `SocialNetworkTest::testOnlyLemmyTakesNoLinkPosts`: `takesLinkPosts()` true, true, false.
  - `OwnReplyGuidsTest::testALemmyReplyIsRefused` and `OwnPostIdsTest::testALemmyPostIsRefused` (both methods): `SocialPostRefusedException` `Lemmy posts are not deleted from this reader.`
  - `PrivateFeedSyncReasonsTest::testALemmyListIsNeverOwned`: `added(<lemmy account>, PrivateFeedModel::userList('1', 'x'))` → `PrivateFeedSyncReason::Saved`.
  - `SocialLinkPostControllerTest::testTheLinkComposerTakesNoLemmyNetwork` stays green unchanged.
- [ ] **Step 3: Run** `cd backend && php bin/phpunit tests/Enum/SocialNetworkTest.php tests/Service/Social/Social/Support tests/Controller/Api/SocialLinkPostControllerTest.php` — FAIL.
- [ ] **Step 4: Implement.** `LinkTargetQuery` calls `takesLinkPosts()`. `ProfileFeedUrl::networkOf` loops `SocialNetwork::cases()` (Lemmy's `identityIn` is still `null`). The three Support matches get the Lemmy arms above in place of `NetworkWithoutFeedsException` (`isOwnedList` Lemmy arm: `false`). Remove the now-unused imports.
- [ ] **Step 5: Run** the same tests — PASS; `bin/console cache:warmup && composer check && composer md`.
- [ ] **Step 6: Commit** — `refactor(#2218): name the link-post capability and give the post-keyed matches a Lemmy arm`

### Task 2: Community RSS feeds are Lemmy network feeds

**Files:**
- Modify: `backend/src/Service/Social/Social/Support/ProfileFeedUrl.php`, `backend/src/Enum/SocialNetwork.php` (`ROUTE_REQUIREMENT`), `backend/src/Controller/Api/{NetworkExclusionsController,SavedSearchController}.php`, `backend/src/Service/Library/SavedPosts/Support/SavedFeedUrl.php`, `backend/src/Service/Operations/Backup/Dto/AccountLine.php`, `backend/src/Http/EntryJson.php` (`posterOf`)
- Create: `backend/migrations/Version20261114160000.php`, `backend/tests/Migrations/Version20261114160000Test.php`
- Test: `backend/tests/Service/Social/Social/Support/{ProfileFeedUrlTest,SocialFeedClassifierTest}.php`, `backend/tests/Enum/SocialNetworkTest.php`, `backend/tests/Controller/Api/{NetworkExclusionsControllerTest,SavedSearchNetworksControllerTest}.php`, `backend/tests/Service/Library/SavedPosts/Support/SavedFeedUrlTest.php`, `backend/tests/Service/Operations/Backup/Dto/AccountLineTest.php`, `backend/tests/Http/EntryJsonTest.php`

**Interfaces:**
- Produces: `ProfileFeedUrl::lemmy(string $community, string $host): string` → `https://{host}/feeds/c/{community}.xml`; `ProfileFeedUrl::of(SocialNetwork::Lemmy, 'rss@feddit.example')` → that URL; `identityOf(Lemmy, $url)` → `name@host` (lower-cased) for path `#^/feeds/c/([^/]+)\.xml$#`. `SocialNetwork::ROUTE_REQUIREMENT = 'bluesky|mastodon|lemmy'` replaces `FEED_NETWORK_REQUIREMENT`.

- [ ] **Step 1: Failing tests**
  - `ProfileFeedUrlTest::testACommunityFeedIsALemmyProfileFeed` (provider): `https://feddit.example/feeds/c/rss.xml` → `Lemmy`, identity `rss@feddit.example`; `https://Feddit.Example/feeds/c/RSS.xml?sort=New` → identity `rss@feddit.example`; `http://…/feeds/c/rss.xml` → Lemmy (web schemes, as Mastodon). Not Lemmy (`networkOf` null): `https://feddit.example/feeds/u/ada.xml`, `https://feddit.example/feeds/c/rss`, `https://feddit.example/feeds/c/a/b.xml`, `ftp://feddit.example/feeds/c/rss.xml` (Review Focus 2). `testOfBuildsTheCommunityFeed`: `of(Lemmy, 'rss@feddit.example')` → `https://feddit.example/feeds/c/rss.xml`; `of(Lemmy, 'rss')` → null.
  - `SocialFeedClassifierTest::testACommunityRssFeedIsOnLemmy`: `networkFor('https://feddit.example/feeds/c/rss.xml', 'xml')` → `Lemmy`.
  - `SocialNetworkTest::testTheRouteRequirementNamesEveryNetwork`: `ROUTE_REQUIREMENT === implode('|', SocialNetwork::valuesOf(SocialNetwork::cases()))`.
  - `NetworkExclusionsControllerTest::testLemmyCanBeSwitched` (provider `surfaces`, replaces `testANetworkWithoutFeedsCannotBeSwitched`): `PUT /api/{surface}/excluded-networks/lemmy` → 2xx and the GET lists `lemmy`. `SavedSearchNetworksControllerTest::testLemmyCanBeIncluded` (replaces `testANetworkWithoutFeedsCannotBeIncluded`). `myspace` stays 404.
  - `SavedFeedUrlTest::testASavedLemmyFeedNamesLemmy`: the saved URL with segment `lemmy` → `Lemmy`. `AccountLineTest::testARestoreKeepsLemmy` (replaces `testARestoreDropsNetworksWithoutFeeds`): `["bluesky","lemmy","myspace"]` → `[Bluesky, Lemmy]`.
  - `EntryJsonTest::testACommunityFeedShowsThePostsOwnAuthor`: an RSS feed with `network = lemmy`, entry author `ada`, authorUrl `https://feddit.example/u/ada`, subscription title `RSS` → `poster` `{name: 'ada', url: 'https://feddit.example/u/ada', imageUrl: null}`; a Mastodon profile feed still shows the subscription title.
  - `Version20261114160000Test::testCommunityFeedsMoveToLemmy`: seed feeds `https://feddit.example/feeds/c/rss.xml` and `https://lemmy.example/feeds/u/ada.xml` and `https://blog.example/feed.xml`, set `network = NULL` by SQL, run the migration (`require_once` + `up()` + execute `getSql()`, as `Version20261005140100Test`) → `lemmy`, `null`, `null`; `testDownClearsIt`.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Social/Social/Support tests/Enum tests/Controller/Api/NetworkExclusionsControllerTest.php tests/Controller/Api/SavedSearchNetworksControllerTest.php tests/Service/Library/SavedPosts tests/Service/Operations/Backup/Dto/AccountLineTest.php tests/Http/EntryJsonTest.php tests/Migrations/Version20261114160000Test.php` — FAIL.
- [ ] **Step 3: Implement.**
  - `ProfileFeedUrl` Lemmy arms in `of` (`name@host` split like `mastodonOf`, then `lemmy()`) and `identityIn` (`LEMMY_COMMUNITY_PATH`).
  - Both controllers use `ROUTE_REQUIREMENT`; `SavedFeedUrl::networkIn` and `AccountLine::networks` use `SocialNetwork::tryFrom(...)`.
  - `EntryJson::posterOf`: before the network-feed branch, `SocialNetwork::Lemmy === $feed->getNetwork()` → `self::authorPosterOf($entry)` (an explicit arm, the spec's one-line switch).
  - Migration (no DDL; same SQL on both dialects; `abortIfUnsupportedPlatform` as `Version20261013150000`): `up()` reads `SELECT id, url, source_format FROM feed WHERE network IS NULL AND url LIKE '%/feeds/c/%'`, keeps the rows `SocialFeedClassifier::networkFor` puts on Lemmy, and queues one `UPDATE feed SET network = 'lemmy' WHERE id IN (:ids)` (skip when none). `down()`: `UPDATE feed SET network = NULL WHERE network = 'lemmy' AND source_format = 'xml'`. Description: `Classify Lemmy community RSS feeds as network feeds (#2218).`
  - Confirm, without changing them, that the `network IS NULL` readers (`SubscriptionActivity`, `FeedGroupDql`, `EntryState`, `EntryScopePredicates`, `Retention`, `TrendingStory`, `UnreadCount`) treat a Lemmy RSS feed as a network feed; note it in the commit body.
- [ ] **Step 4: Run** the tests — PASS. Migration leg: `cd backend && APP_ENV=test DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:migrations:migrate -n && APP_ENV=test DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:schema:validate; rm var/migrate-check.db`, then `docker compose exec php bin/console doctrine:migrations:migrate -n && docker compose exec php bin/console doctrine:schema:validate` — `[OK] The database schema is in sync with the mapping files.` `composer check && composer md`.
- [ ] **Step 5: Commit** — `feat(#2218): classify Lemmy community RSS feeds as Lemmy network feeds`

### Task 3: Lemmy timelines as private feeds

**Files:**
- Modify: `backend/src/Enum/{SocialFeedKind,SourceFormat}.php`, `backend/src/Service/Social/Social/Model/PrivateFeedModel.php`, `Support/{SocialFeedUrl,PrivateFeedRules,PrivateFeedSite}.php`, `PrivateFeeds.php`, `SocialImporter.php` (`kindLabel`), `SocialAccountConnector.php` (drop the `carriesFeeds()` guard), `LemmyApiClient.php`, `LemmyAccountApi.php`, `backend/src/Service/Library/Subscription/SubscriptionCreator.php` (format docblock)
- Create: `backend/src/Service/Social/Social/LemmyPostEntries.php`, `backend/src/Service/Social/Social/TimelineSource/LemmyTimelineSource.php`, fixture `backend/tests/Fixtures/lemmy/post-list.json`
- Test: `backend/tests/Service/Social/Social/LemmyPostEntriesTest.php`, `TimelineSource/LemmyTimelineSourceTest.php`, `LemmyApiClientTest.php`, `Support/{SocialFeedUrlTest,PrivateFeedRulesTest,PrivateFeedSiteTest}.php`, `SocialAccountConnectorTest.php`, `backend/tests/Service/Social/Social/TimelineRoundsTest.php` (or the existing round test that drives sources), `backend/tests/Support/{PrivateFeeds,TrendingFixtures}.php`

**Interfaces:**
- Produces:
  - `SocialFeedKind::Local`, `SocialFeedKind::All`; `PrivateFeedModel::local(): self`, `::all(): self` (remoteId `''`), with arms in `PrivateFeedModel::of`.
  - `SourceFormat::LEMMY_API`.
  - `LemmyApiClient::accepted(ApiResponseModel $answer): ApiResponseModel` (public static; `send()` becomes `self::accepted($this->answer($request))`), `LemmyApiClient::acceptedInRound(ApiResponseModel $answer): ApiResponseModel` (refused / not found → `SocialNetworkUnreachableException` with `previous`), `LemmyApiClient::answerOf(FetchOutcomeModel $outcome): ApiResponseModel`.
  - `LemmyAccountApi::authorizedGet(SocialAccount $account, string $pathAndQuery): ApiRequestModel`.
  - `final readonly class LemmyPostEntries { __construct(MarkdownHtml $markdown); page(SocialAccount $account, ApiResponseModel $answer): TimelinePageModel }` — throws `SocialNetworkUnreachableException('The Lemmy instance answered without posts.')` when `posts` is no list. `KnownSharesModel::none()`.
  - `LemmyTimelineSource` (`#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]`, ctor `LemmyAccountApi $lemmy, LemmyPostEntries $entries`).

- [ ] **Step 1: Fixture.** `post-list.json` `{"posts": [...]}` with five PostViews, shaped by the 0.19 types (all required keys present, fake values):
  - 101: `ap_id` `https://feddit.example/post/7`, `name` `A link post`, `url` `https://news.example/story`, no `body`, `thumbnail_url` `https://feddit.example/pictrs/image/thumb.jpeg`, `published` `2026-11-14T05:05:09.123456Z`, creator `{name: "ada", display_name: "Ada", actor_id: "https://feddit.example/u/ada", avatar: "https://feddit.example/pictrs/image/ada.png"}`, `counts {score: 12, comments: 3}`.
  - 102: `ap_id` `https://lemmy.example/post/102`, `name` `A text post`, `body` `Some **bold** text`, `published` `2026-11-14T08:00:00+02:00`, creator `{name: "lars", display_name: null, actor_id: "https://lemmy.example/u/lars"}`, `counts {score: 1, comments: 0}`.
  - 103: `deleted: true` (and a sibling 105 `removed: true`).
  - 104: `ap_id` `https://mbin.example/m/rss/t/9`, `name` `From elsewhere`.
- [ ] **Step 2: Failing tests**
  - `LemmyPostEntriesTest` (account `lemmy.example`): `testEveryPostIsALemmyDiscussionThread` (Review Focus 1): for each entry `url` null, `discussion->commentsLoad` `Auto`, and `LemmyPostUrl::parse($discussion->url)` not null; guids `https://feddit.example/post/7`, `https://lemmy.example/post/102`, `https://mbin.example/m/rss/t/9`. `testALinkPostLeadsWithItsLink`: 101's `contentHtml` starts `<p><a href="https://news.example/story">https://news.example/story</a></p>`, image `https://feddit.example/pictrs/image/thumb.jpeg`. `testTheBodyIsRenderedMarkdown`: 102 contains `<strong>bold</strong>`. `testTheAuthorAndCountsAreThePosts`: 101 author `Ada`, authorUrl `https://feddit.example/u/ada`, avatar `…/ada.png`, counts `DiscussionCounts::of(12, 3)`; 102 author `lars`. `testPublishedTimesAreUtc` (Review Focus 5): 101 `2026-11-14 05:05:09`, 102 `2026-11-14 06:00:00`, both timezone `UTC`. `testDeletedAndRemovedPostsAreSkipped`: three entries (101, 102, 104); 103 and 105 are absent. `testAnOriginThatIsNoLemmyPostPageUsesTheHomeInstancePage`: 104 discussion `https://lemmy.example/post/104`. `testTheNewestIdIsTheHighestPostId`: `'105'`; an empty `posts` → `null`. `testAnAnswerWithoutPostsIsUnreachable`.
  - `LemmyTimelineSourceTest`: `testEachTimelineAsksItsListing` (provider): Home → `GET https://lemmy.example/api/v3/post/list?type_=Subscribed&sort=New&limit=50`, Local → `type_=Local`, All → `type_=All`; each with `Authorization: Bearer test-jwt-not-a-real-token`; `UserList`/`CustomFeed`/`Author`/`Tag`/`Trending` → `SocialNetworkUnreachableException` `Lemmy has no such timeline.` (Search arm comes in Task 5: until then it throws the same). `testARefusalInARoundIsUnreachable` (Review Focus 3): `accepted()` of a 400 `{"error":"couldnt_find_post"}` and of `{"error":"banned_from_community"}` → `SocialNetworkUnreachableException`; `{"error":"not_logged_in"}` → `SocialCredentialsRejectedException`; 429 → `SocialNetworkThrottledException`. `testThePageIsBuiltFromThePosts`: `pageOf` with `post-list.json` → 3 entries.
  - `LemmyApiClientTest` stays green (behaviour of `send` unchanged).
  - `SocialFeedUrlTest::testALemmyTimelineAddressRoundTrips`: `social:lemmy:7:local` and `social:lemmy:7:all` parse to `(Lemmy, 7, Local|All, '')`.
  - `PrivateFeedRulesTest::testLemmyAllowsItsTimelinesOnly` (provider): Lemmy allows Home, Local, All; refuses UserList `'1'` (no longer the Mastodon branch), CustomFeed, Author, Tag, Trending, Search (until Task 5). Bluesky and Mastodon refuse Local and All.
  - `PrivateFeedSiteTest::testALemmyTimelineIsItsListingPage`: Home → `https://lemmy.example/?listingType=Subscribed`, Local → `…/?listingType=Local`, All → `…/?listingType=All`.
  - `SocialAccountConnectorTest::testConnectingLemmyCreatesItsHomeFeed` (replaces `testConnectingLemmyCreatesNoFeed`): one subscription, feed URL `social:lemmy:{id}:home`, source format `lemmy-api`, network `lemmy`.
  - A round test (in the existing `TimelineRounds` / `PrivateFeedSync`-driven test that routes Mastodon): `testALemmyHomeFeedIngestsItsPosts` — after one round, the feed holds the three entries and `newestSeenId` `'105'`; then `testALemmyEntryCarriesItsVote` through `GET /api/entries` on that feed shows `myVote` (not null) and `social` null.
- [ ] **Step 3: Run** `php bin/phpunit tests/Service/Social/Social tests/Enum` — FAIL.
- [ ] **Step 4: Implement.**
  - `SocialFeedUrl::SEGMENTS` gains `'local' => 'local', 'all' => 'all'`; `addressOf` uses `SocialNetwork::tryFrom`. `SocialImporter::kindLabel`: `Local` → `local timeline`, `All` → `All timeline`.
  - `PrivateFeedRules::allows` becomes `match ($network)` over three private methods (`bluesky`, `mastodon`, `lemmy`), each matching every kind explicitly; Bluesky/Mastodon answer exactly as today and `false` for Local/All. Keep `allows` and the helpers PHPMD-clean.
  - `PrivateFeeds::sourceFormatOf` Lemmy → `LEMMY_API` (docblock `'bluesky-api'|'mastodon-api'|'lemmy-api'`); `PrivateFeedSite` Lemmy arm (other kinds → the instance root); `SourceFormat::SOCIAL` and `NOT_PROFILES` gain `LEMMY_API`.
  - `LemmyPostEntries::page`: `JsonNodeModel::of($answer->json())->listAt('posts')`; per post skip `deleted`/`removed`; build `ParsedEntryModel` then `->asDiscussionThread(Discussion::platformThread($page))->withDiscussionCounts(...)`; `$page` = `ap_id` when `LemmyPostUrl::parse` accepts it, else `rtrim(serviceUrl, '/') . '/post/' . id`. Author identity is not needed (no `LemmyAuthor` import required). Split into private methods until each reads as one sentence.
  - `LemmyTimelineSource::accepted` = `LemmyApiClient::acceptedInRound(LemmyApiClient::answerOf($outcome))`; `pageOf` reads `$accepted[0]` (`\LogicException('A Lemmy timeline is read with one request.')` otherwise); `dropSession` no-op.
  - `SocialAccountConnector::createHome` loses its `carriesFeeds()` guard.
  - `tests/Support/PrivateFeeds::of` and `TrendingFixtures` get a real Lemmy arm (`new SocialFeed($account, <feed with lemmy-api>, $kind, $remoteId)`).
- [ ] **Step 5: Run** — PASS; `composer check && composer md`; `composer tramp` (no `$account` chain of 4: if `page()`'s account reaches a private helper three hops down, hold the home-instance URL in a per-call `Pass/` object instead).
- [ ] **Step 6: Commit** — `feat(#2218): read Lemmy Subscribed, Local and All timelines as private feeds`

### Task 4: Manage feeds for Lemmy — communities and timelines

**Files:**
- Create: `backend/src/Service/Social/Social/CandidateSource/LemmyCandidateSource.php`, `backend/src/Service/Social/Social/Support/LemmyCommunity.php`, fixtures `backend/tests/Fixtures/lemmy/community-list.json`
- Modify: `backend/src/Service/Social/Social/ImportCandidates.php` (`singletonsOf`), `backend/src/Controller/Api/SocialImportController.php` (`requireConnected`), `backend/src/Repository/SocialAccountSyncRepository.php` (drop the network filter)
- Test: `backend/tests/Service/Social/Social/CandidateSource/LemmyCandidateSourceTest.php`, `Support/LemmyCommunityTest.php`, `ImportCandidatesTest.php`, `SocialImporterTest.php`, `backend/tests/Controller/Api/SocialImportControllerTest.php`, `backend/tests/Repository/SocialAccountSyncRepositoryTest.php`

**Interfaces:**
- Consumes: `ProfileFeedUrl::lemmy` (Task 2), `PrivateFeedModel::local()/all()`, `PrivateFeedRules` (Task 3), `LemmyAccountApi::get`.
- Produces: `LemmyCommunity::follow(JsonNodeModel $communityView): ?FollowedAccountModel` (identity `name@host` of `community.actor_id`, lower-cased; handle `!name@host`; displayName `community.title`; feedUrl `ProfileFeedUrl::lemmy(name, host)`; avatar `community.icon`; null when `name` is missing or `actor_id` is no `https` URL). `LemmyCandidateSource { candidates(SocialAccount): NetworkCandidatesModel; collections(SocialAccount): NetworkCollectionsModel }` — `collections` returns `new NetworkCollectionsModel([], [])` without a call.

- [ ] **Step 1: Fixture.** `community-list.json`: three CommunityViews — `rss` on `feddit.example` (title `RSS`, icon `https://feddit.example/pictrs/image/rss.png`), `Linux` on `lemmy.example` (title `Linux`, no icon), one with `actor_id` `http://plain.example/c/x`.
- [ ] **Step 2: Failing tests**
  - `LemmyCommunityTest`: `rss` → identity `rss@feddit.example`, handle `!rss@feddit.example`, feedUrl `https://feddit.example/feeds/c/rss.xml`; `Linux` → identity `linux@lemmy.example`, feedUrl `https://lemmy.example/feeds/c/linux.xml`; the `http` one → null.
  - `LemmyCandidateSourceTest`: `testTheSubscribedCommunitiesAreTheFollows`: one call `GET https://lemmy.example/api/v3/community/list?type_=Subscribed&sort=Old&limit=50&page=1` (bearer) → two follows, `lists`/`customFeeds`/`tags` empty, `truncated` false. `testCommunitiesArePagedUntilAShortPage`: page 1 is 50 generated communities, page 2 the fixture → 52 follows and exactly two requests. `testCollectionsAskNothing`: no request.
  - `ImportCandidatesTest::testALemmyAccountOffersItsThreeTimelines`: the second list holds Home, Local, All in that order, no Trending; a Mastodon account still gets Home and Trending only. `testACommunityAlreadySubscribedByUrlIsSubscribed` (Review Focus 4): a plain subscription to `https://Feddit.Example/feeds/c/rss.xml?sort=New` makes `rss@feddit.example` `subscribed`; `linux@lemmy.example` is `new`.
  - `SocialImporterTest::testImportingACommunitySubscribesItsRssFeed`: `subscribe: [{identity: 'rss@feddit.example', title: 'RSS'}]`, `collections: [local]` → a plain subscription to `https://feddit.example/feeds/c/rss.xml` (network `lemmy`, format `xml`) and a private feed `social:lemmy:{id}:local`.
  - `SocialImportControllerTest::testALemmyAccountListsItsCommunities` and `testALemmyAccountImports` (replace `testALemmyAccountHasNoImportCandidates` / `testALemmyAccountTakesNoImport`): 200.
  - `SocialAccountSyncRepositoryTest::testALemmyAccountIsDueForSync` (replaces `testALemmyAccountIsNeverDueForSync`): both never-checked accounts are due.
- [ ] **Step 3: Run** `php bin/phpunit tests/Service/Social/Social tests/Controller/Api/SocialImportControllerTest.php tests/Repository/SocialAccountSyncRepositoryTest.php` — FAIL.
- [ ] **Step 4: Implement.** `LemmyCandidateSource` (`#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]`, ctor `LemmyAccountApi $lemmy`): page with `page=N` while a page is full and the total is ≤ `CollectedItemsModel::MAX_ITEMS`; `capped()` the result; `communities` not a list → `SocialNetworkUnreachableException('The Lemmy instance answered without communities.')`. `ImportCandidates::singletonsOf` iterates `[home(), trending(), local(), all()]`, filtered by `PrivateFeedRules::allows`. `SocialImportController` uses `requireConnected`. `findIdsDueForSync` loses `sa.network IN (:feedNetworks)`.
- [ ] **Step 5: Run** — PASS; `composer check && composer md`.
- [ ] **Step 6: Commit** — `feat(#2218): manage a Lemmy account's communities and timelines`

### Task 5: Lemmy search subscriptions, and the split goes

**Files:**
- Modify: `backend/src/Service/Social/Social/SocialSearch/NetworkSocialSearch.php`, `TimelineSource/LemmyTimelineSource.php`, `Support/{SocialSearchPaths,PrivateFeedRules,PrivateFeedSite,StoredSearchedPost}.php`, `SearchFeedEditor.php`, `backend/src/Controller/Api/SocialSearchController.php` (`requireConnected`), `backend/src/Service/Social/Social/SocialAccountForUser.php` (delete `requireFeedAccount`), `backend/src/Enum/SocialNetwork.php` (delete `carriesFeeds`, `feedNetworks`, `tryFromFeed`, `requestCases`; add `checksSearchResults`)
- Delete: `backend/src/Service/Social/Social/Exception/NetworkWithoutFeedsException.php`
- Create: fixture `backend/tests/Fixtures/lemmy/search.json`
- Test: `backend/tests/Service/Social/Social/SocialSearch/NetworkSocialSearchTest.php`, `TimelineSource/LemmyTimelineSourceTest.php`, `Support/{PrivateFeedRulesTest,PrivateFeedSiteTest,SocialSearchPathsTest}.php`, `SearchFeedEditorTest.php`, `SocialAccountForUserTest.php`, `backend/tests/Controller/Api/SocialSearchControllerTest.php`, `backend/tests/Enum/SocialNetworkTest.php`

**Interfaces:**
- Consumes: `LemmyPostEntries::page` (Task 3).
- Produces: `SocialSearchPaths::lemmy(string $query): string` → `/api/v3/search?q={rawurlencode}&type_=Posts&sort=New&listing_type=All&limit=50`; `SocialNetwork::checksSearchResults(): bool`.

- [ ] **Step 1: Fixture.** `search.json`: a SearchResponse `{"type_": "Posts", "comments": [], "posts": [<two PostViews like post-list 101 and 102>], "communities": [], "users": []}`.
- [ ] **Step 2: Failing tests**
  - `SocialSearchPathsTest::testALemmySearchAsksForNewPostsEverywhere`: `lemmy('rss reader || atom')` → `/api/v3/search?q=rss%20reader%20%7C%7C%20atom&type_=Posts&sort=New&listing_type=All&limit=50` (no split).
  - `NetworkSocialSearchTest::testALemmySearchPreviewsThePostsFound`: one request to that path on `https://lemmy.example` (bearer) → a page of two entries, guids as the fixture, no reader-side filtering (a post not containing the words is kept).
  - `LemmyTimelineSourceTest::testASearchFeedAsksTheSearch`: Search kind, remoteId `rss reader` → one request, the path above; `pageOf(search.json)` → two entries.
  - `PrivateFeedRulesTest::testLemmyTakesAPlainSearch`: Lemmy allows Search `a || b || c || d || e || f || g || h || i` (nine alternatives: Mastodon's limit of 8 does not apply), refuses `''` and a query over `SocialSearchQuery::MAX_LENGTH`.
  - `PrivateFeedSiteTest::testALemmySearchIsItsSearchPage`: `https://lemmy.example/search?q=rss%20reader&type=Posts`.
  - `SearchFeedEditorTest::testRequeryingALemmySearchKeepsItsPosts`: a Lemmy search feed with two stored posts; requery to `atom` → feed URL `social:lemmy:{id}:search:atom`, both entries kept.
  - `SocialSearchControllerTest::testALemmyAccountPreviewsASearch` (replaces `testTheSearchPreviewOfALemmyAccountAnswers404`): 200 with two posts.
  - `SocialNetworkTest`: `testOnlyLemmyLeavesSearchResultsToTheInstance` (`checksSearchResults()` true, true, false); `testEveryNetworkParsesFromARequest`: `fromRequestValue('lemmy')` → Lemmy. Delete the `feedNetworks`/`carriesFeeds`/`tryFromFeed` tests and `SocialAccountForUserTest::testAFeedAccountRequirementReadsALemmyAccountAsMissing`.
- [ ] **Step 3: Run** `php bin/phpunit tests/Service/Social/Social tests/Controller/Api/SocialSearchControllerTest.php tests/Enum` — FAIL.
- [ ] **Step 4: Implement.**
  - `NetworkSocialSearch` gains `LemmyAccountApi $lemmy, LemmyPostEntries $lemmyEntries`; Lemmy arm `$this->lemmyEntries->page($account, $this->lemmy->get($account, SocialSearchPaths::lemmy($query)))`.
  - `LemmyTimelineSource::pathOf` Search arm → `SocialSearchPaths::lemmy($socialFeed->getRemoteId())`.
  - `PrivateFeedRules` Lemmy Search arm: non-empty and `mb_strlen ≤ SocialSearchQuery::MAX_LENGTH`. `PrivateFeedSite` Lemmy Search arm.
  - `SearchFeedEditor::nonMatchingIds` returns `[]` when `!$account->getNetwork()->checksSearchResults()`; `StoredSearchedPost` Lemmy arm `throw new \LogicException('A Lemmy search is never matched by the reader.')`.
  - `SocialSearchController` uses `requireConnected`; delete `requireFeedAccount`, the enum's `carriesFeeds`, `feedNetworks`, `tryFromFeed` and its `requestCases` override, and `NetworkWithoutFeedsException` with its last imports (`grep -rn "NetworkWithoutFeeds\|carriesFeeds\|feedNetworks\|tryFromFeed\|FEED_NETWORK" backend/src backend/tests` → nothing).
- [ ] **Step 5: Run** the touched tests — PASS. Then the backend in full, one leg after the other: `composer check && composer md && composer tramp && php bin/phpunit`, then `docker compose exec -T php composer test:parallel`. `composer infection:diff` — at or above `minMsi`. Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq .`).
- [ ] **Step 6: Commit** — `feat(#2218): subscribe to a Lemmy search and drop the feed-network split`

### Task 6: Frontend — every network list includes Lemmy

**Files:**
- Modify: `frontend/src/app/core/social-network.ts`, `reader/social-feed.ts`, `reader/entry-social.ts`, `reader/state/social-sections.ts`, `reader/shell/sidebar/sidebar-row-actions.service.ts`, `reader/feeds/social-picker/{social-feeds-picker.service,social-import-list}.ts`, `reader/article/share/post-networks.service.ts`, `reader/article/composer/{post-actions.service,post-length,composed-post}.ts`, `settings/social/{social-section.component,social-accounts.service}.ts`, `reader/feeds/social-picker/social-search-form.component.ts` (`SEARCH_SYNTAX.lemmy`), `shared/network-logo/network-logo.component.{html,scss}`, every other file `npx tsc` names; `frontend/public/i18n/{en,de,fr,es}.json`
- Test: `frontend/src/app/core/social-network.spec.ts` (create), `reader/state/social-sections.spec.ts`, `reader/article/share/post-networks.service.spec.ts` (create if missing), `shared/network-logo/network-logo.component.spec.ts`, `settings/social/social-section.component.spec.ts`, `reader/feeds/social-picker/social-import-list.spec.ts`

**Interfaces:**
- Produces: `SOCIAL_NETWORKS = ['bluesky', 'mastodon', 'lemmy'] as const`; `SOCIAL_NETWORK_NAMES.lemmy = 'Lemmy'`; `LINK_POST_NETWORKS: readonly SocialNetworkName[] = ['bluesky', 'mastodon']`; `SocialFeedRefDto` private kinds gain `'local' | 'all'`; `SocialAccountRefDto.network: SocialNetworkName`. `IMPORT_TABS: Readonly<Record<SocialNetworkName, readonly ImportTab[]>>` = bluesky `['accounts','lists','customFeeds']`, mastodon `['accounts','lists','tags']`, lemmy `['accounts','lists']` (Search appended for all).

- [ ] **Step 1: Failing tests**
  - `social-network.spec.ts`: `isSocialNetworkName('lemmy')` true; `LINK_POST_NETWORKS` excludes `lemmy`.
  - `social-sections.spec.ts`: `buildsALemmySectionFromItsAccountAndCommunityFeeds` — a Lemmy account plus a subscription `{social: {network: 'lemmy', kind: 'profile', accountId: null}}` → a `lemmy` section with the account in `networkAccounts` and the feed in `groups.profile`; private `local`/`all` feeds sort after Home (`KIND_ORDER` `local: 1`, `all: 2`, the rest shifted).
  - `post-networks.service.spec.ts`: a connected Lemmy account adds no post network.
  - `network-logo.component.spec.ts`: `network = 'lemmy'` renders `svg.lemmy`.
  - `social-section.component.spec.ts`: the Lemmy account shows the `social-import-{id}` "Manage feeds…" button.
  - `social-import-list.spec.ts`: for `network: 'lemmy'` the tabs are `['accounts', 'lists', 'search']`.
  - `saved-searches.store.spec.ts` and `settings/visibility/visibility-tree.spec.ts`: Lemmy is offered in the saved-search network filter and in the For you / All items / Trending network switches.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/core src/app/reader/state/social-sections src/app/reader/article/share src/app/shared/network-logo src/app/settings/social src/app/reader/feeds/social-picker` — FAIL.
- [ ] **Step 3: Implement.** Delete `ACCOUNT_NETWORKS`, `AccountNetworkName`, `FeedAccountRefDto` and `FeedAccount`; replace each use with `SocialNetworkName` / `SocialAccountRefDto`, then let `docker compose exec -T frontend npx tsc -p tsconfig.app.json --noEmit` name every `Record<SocialNetworkName, …>` missing a `lemmy` key and fill it (Lemmy has no search operators: `SEARCH_SYNTAX.lemmy = []` until Task 7). `PostNetworks` iterates `LINK_POST_NETWORKS`. Logo: a monochrome Lemmy mark (24×24 `viewBox`, a rounded lemming head with two ears and two eyes, one `path`, `fill: currentColor` in `.lemmy`). i18n in all four files: `reader.socialNetworkShort.lemmy` `lemmy`, `reader.savedFrom.lemmy` `Saved from Lemmy` (de `Gespeichert aus Lemmy`, fr `Enregistré depuis Lemmy`, es `Guardado de Lemmy`), and every other `…bluesky` / `…mastodon` key family the dictionaries hold (grep `"mastodon":` in `en.json`) gains its Lemmy sibling.
- [ ] **Step 4: Run** the same jest paths — PASS; `docker compose exec -T frontend npm run check`.
- [ ] **Step 5: Commit** — `feat(#2218): list Lemmy wherever the reader lists networks`

### Task 7: Frontend — Lemmy's picker, section labels and search hint

**Files:**
- Modify: `frontend/src/app/reader/feeds/social-picker/{social-import-dialog.component.html,social-import-list.ts,social-import-rows.ts,social-search-form.component.ts,social-search-form.component.html}`, `reader/state/social-sections.ts` (label lookup), `reader/shell/sidebar/sidebar-social-section.component.ts`, `reader/shell/list-heading.service.ts`, `frontend/public/i18n/{en,de,fr,es}.json`
- Test: `reader/feeds/social-picker/{social-import-dialog.component,social-import-rows,social-search-form.component}.spec.ts`, `reader/state/social-sections.spec.ts`, `reader/shell/list-heading.service.spec.ts`

**Interfaces:**
- Produces: `networkFeedKindLabels(kind: NetworkFeedKind, network: SocialNetworkName): { labelKey: string; toggleKey: string }` in `social-sections.ts` — `(profile, lemmy)` → `reader.lemmyCommunities` / `reader.toggleLemmyCommunities`, else the descriptor's keys. `SocialImportList.tabLabelPrefix: string` — `settings.social.importTabLemmy.` for Lemmy, else `settings.social.importTab.`.

- [ ] **Step 1: Failing tests**
  - `social-import-dialog.component.spec.ts`: for a Lemmy account the tabs read `Communities`, `Timelines`, `Search` (Transloco test loader with the en keys).
  - `social-import-rows.spec.ts`: Lemmy singleton rows `home`, `local`, `all` are labelled `Subscribed`, `Local`, `All`, icons `home`, `location_city`, `public`; Mastodon's Home stays `Home timeline`.
  - `social-sections.spec.ts`: `networkFeedKindLabels('profile', 'lemmy')` → the Lemmy keys; `('profile', 'mastodon')` → `reader.socialFeeds`.
  - `list-heading.service.spec.ts`: a Lemmy profile-group selection is headed `Lemmy · Communities`.
  - `social-search-form.component.spec.ts`: for `lemmy` the syntax lists one rule (`rss reader` → `plain words; matches post titles and bodies`), shows no combined example and no Mastodon notes; the example query is `rss reader`.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/feeds/social-picker src/app/reader/state/social-sections src/app/reader/shell` — FAIL.
- [ ] **Step 3: Implement.** Dialog binds `[labelPrefix]="list.tabLabelPrefix"`. `COLLECTION_ICONS` and the singleton name keys gain `local`/`all`; the name key for a singleton is looked up per network (`SINGLETON_NAME_KEYS` becomes `(network, kind) → key`, Lemmy Home → `settings.social.importLemmySubscribed`). The sidebar section and list heading call `networkFeedKindLabels`. Search form: `SEARCH_SYNTAX.lemmy = [{operator: 'rss reader', key: 'settings.social.search.syntaxLemmyPlain'}]`; example and combined example per network (`EXAMPLE_QUERIES`, combined shown only when the network has operators). Keys, all four dictionaries (en given; translate de/fr/es in the dictionaries' voice):
  - `settings.social.importTabLemmy`: `accounts` `Communities`, `lists` `Timelines`, `search` `Search`
  - `settings.social.importLemmySubscribed` `Subscribed`, `importLemmyLocal` `Local`, `importLemmyAll` `All`
  - `settings.social.search.syntaxLemmyPlain` `plain words; matches post titles and bodies`
  - `reader.lemmyCommunities` `Communities`, `reader.toggleLemmyCommunities` `Toggle {{network}} communities`
  - `settings.social.importSectionCaption` names Lemmy communities and timelines beside the Bluesky and Mastodon follows, lists and feeds.
- [ ] **Step 4: Run** the jest paths — PASS. Then the gate: `docker compose exec -T frontend npm run check` and `docker compose exec -T frontend npx ng build` (the check misses template errors) — both green.
- [ ] **Step 5: Live check** (controller, not the implementer): stack up, migrations applied (`docker compose exec php bin/console doctrine:migrations:migrate -n`), frontend cache cleared; on http://localhost:4200 with the trial Lemmy account: the Lemmy section groups the existing community RSS feeds under Communities, "Manage feeds…" opens on Communities with them ticked, Local subscribes and fills on the next refresh, a search previews and subscribes, a timeline post's vote pill votes. Scan today's dev log.
- [ ] **Step 6: Commit** — `feat(#2218): Lemmy's Manage feeds tabs, community labels and search hint`
