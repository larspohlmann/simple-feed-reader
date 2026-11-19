# Per-Network Facts Behind Keyed Adapters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A fact about one social network (address formats, profile feed URLs, private feed rules, search syntax, credential shape) lives in that network's adapter, not in a `match` inside a shared helper.

**Architecture:** The behaviour of a network already sits behind tagged interfaces picked from a locator keyed by `SocialNetwork->value`. This plan gives the facts the same shape: four new keyed interfaces in `Service/Social/Social`, one adapter per network each, and one small root service per interface that picks the adapter. The static `match` helpers are deleted; functions only one network needs stay static in a helper named for that network. `EntryListRowEnricher` leaves `src/Repository` so it can inject those services.

**Tech Stack:** PHP 8.5, Symfony 8.1 (`#[AutoconfigureTag]`, `#[AsTaggedItem]`, `#[AutowireLocator]`, `#[AutowireIterator]`), PHPUnit 12, PHPStan max.

**Spec:** GitHub issue #2358. Follow-up, not part of this plan: #2359 (a module per network).

## Global Constraints

- No behaviour change and no API change. Every user-facing message string moves verbatim.
- Every existing test case of a deleted helper survives, re-pointed at the service or adapter that now answers it. Do not drop or weaken an assertion.
- `composer check`, `composer md` on every touched `src` file, `php bin/phpunit` and `docker compose exec php composer test:parallel` are green at the end of every task.
- A keyed adapter carries `#[AsTaggedItem(index: SocialNetwork::X->value)]`; its interface carries `#[AutoconfigureTag('…')]`. Follow `TimelineSource/` and `TimelineSources.php` exactly.
- An interface and its same-module adapters share a folder named after the interface without the `Interface` suffix (`ServiceRoleRule`).
- No `tests/PhpStan` rule or allow-list is edited, no `@phpstan-ignore`, no PHPMD suppression.
- Commit format `refactor(#2358): …`. No attribution lines.
- Out of scope, leave untouched: the Lemmy fields in `EntryListRowEnricher`, `EntryListRowLemmy` and `Http/EntryJson`; `DiscussionVotes`; the `HiddenProfile*` family; `FollowAvatarCache`; the Bluesky guard in `FollowMatching::unmatchedHandles()`; the three connect routes; the frontend. They are capability questions or belong to #2359.

## Decisions taken while reading (review these first)

1. **Four interfaces, not three.** "Addresses" has 12 facts; one adapter would exceed PHPMD's 10 public methods. It splits into post addresses and profile feeds.
2. **Credentials need no new interface.** The connect DTOs are network-named and call their own network's static builder. Only the reconnect path dispatches, and it joins the existing `NetworkVerifierInterface`, which already is the per-network credential adapter.
3. **`fits()`/`requireFits()` disappear.** `searchQueries()` throwing `SearchQueryTooBroadException` is the one fact; callers that only validate discard the result.
4. **The message "This entry is not a Bluesky, Mastodon or Lemmy post." stays verbatim** in the generic service. It is copy, not a branch.

## File Structure

New, all under `backend/src/Service/Social/Social/` unless a path says otherwise:

| File | Responsibility |
|---|---|
| `backend/src/Service/Library/EntryList/EntryListRowEnricher.php` | Moved from `src/Repository`; unchanged behaviour |
| `PostAddressRule/PostAddressRuleInterface.php` + `Bluesky…`, `Mastodon…`, `Lemmy…PostAddressRule.php` | Which post an entry, a reply address or a URL names; own-post ids; the post's author |
| `SocialPostAddresses.php` | Picks the rule; the refusal messages |
| `ProfileFeedRule/ProfileFeedRuleInterface.php` + three adapters | Profile feed URL ↔ identity, handle, feed title |
| `ProfileFeeds.php` | Picks the rule by network or by URL |
| `PrivateFeedRule/PrivateFeedRuleInterface.php` + three adapters | Allowed feed kinds, site URL, list ownership, source format, search syntax, searched-post shape |
| `PrivateFeedRules.php` | Picks the rule (service; replaces the static helper of the same name) |
| `SearchSource/SearchSourceInterface.php` + three adapters | One network's newest search results |
| `SearchAnswers.php` | Sends a list of search requests concurrently and returns the accepted answers in order |
| `Support/BlueskyProfileFeed.php`, `MastodonProfileFeed.php`, `LemmyProfileFeed.php` | The static, single-network functions of today's `ProfileFeedUrl` |
| `Support/BlueskyCredentials.php`, `MastodonCredentials.php`, `LemmyCredentials.php`, `InstanceOrigin.php` | The static builders of today's `SocialCredentialsModel` |
| `Support/MastodonFeedIds.php` | `isListId()`, `isTagName()` from today's `PrivateFeedRules` |
| `Model/CredentialReplacementModel.php` | The secret and second factor a reconnect sends |

Deleted: `Support/SocialPostAddress.php`, `OwnPostIds.php`, `OwnReplyGuids.php`, `PostAuthor.php`, `ProfileFeedUrl.php`, `PrivateFeedRules.php` (static), `PrivateFeedSite.php`, `NetworkSearchQueries.php`, `StoredSearchedPost.php`.

Tests mirror `src`. One shared helper, `backend/tests/Support/SocialRuleWiring.php`, builds each picker service over real adapters with a `ServiceLocator`, the way `tests/Support/NotificationPollWiring.php` does.

## Review Focus

1. **A network without an adapter.** The deleted `match` expressions were exhaustive and PHPStan failed on a missing arm; a locator fails at runtime. Expected: a test fails the build. Pinned in Task 2, extended in Tasks 3–5.
2. **A URL two networks claim.** `ofUrl()` and `networkOf()` tried Bluesky first by code order; an iterator has no promised order. Expected: no URL is claimed twice, so order cannot matter. Pinned in Tasks 2 and 3.
3. **An entry in a feed with no network that links a Lemmy post.** `respondableOrRefuse()` answers it today although the feed's network is `null`. Expected: still answered. Pinned in Task 2.
4. **A malformed app password on reconnect.** Today it is refused before the rate limiter counts the attempt. Expected: same order. Pinned in Task 6.
5. **`EntryCompletionController` rows.** The response must stay enriched after `EntryCompletion` stops enriching. Pinned in Task 1.

---

### Task 1: The row enricher becomes a service

**Files:**
- Move: `backend/src/Repository/EntryListRowEnricher.php` → `backend/src/Service/Library/EntryList/EntryListRowEnricher.php`
- Move: `backend/tests/Repository/EntryListRowEnricherTest.php` → `backend/tests/Service/Library/EntryList/EntryListRowEnricherTest.php`
- Modify: `backend/src/Service/Social/Completion/EntryCompletion.php`, `backend/src/Controller/Api/EntryCompletionController.php`
- Modify (import only): `SavedSearchEntriesController`, `TrendingStoryEntriesController`, `EntryDetailController`, `EntrySearchController`, `EntryController`, `Curation/Recommendation/Feed/ForYouFeed.php`, `Curation/Trending/TrendingStories.php`, and every test that names the class
- Modify: `docs/architecture.md` §9 table, `Library` row: add `EntryList`

**Interfaces:**
- Produces: `App\Service\Library\EntryList\EntryListRowEnricher::enrich(array $rows, int $userId): array` — same signature, new namespace. The `EntryListRow*` value classes stay in `App\Repository`.
- Produces: `EntryCompletion::complete(int $userId, array $ids): array` returns the completed rows **without** enrichment.

- [ ] **Step 1: Write the failing test** in `tests/Controller/Api/EntryCompletionControllerTest.php`: complete an entry that has a category and assert the response entry carries that category. If an existing case already asserts an enriched field, name it in the commit message and add nothing.
- [ ] **Step 2: Move the class and its test**, fix the namespaces and imports. Run `php bin/phpunit tests/Service/Library/EntryList` — PASS.
- [ ] **Step 3: Remove the enricher from `EntryCompletion`**; `EntryCompletionController::complete()` calls `$this->enricher->enrich($rows, $user->requireId())` between the completion and the JSON mapping.
- [ ] **Step 4: Verify.** `composer stan` reports no `ServiceModuleCycleRule`, `ServiceRoleRule` or `ThinControllerRule` error; `php bin/phpunit` is green.
- [ ] **Step 5: Commit** `refactor(#2358): the row enricher is a service in Library/EntryList`.

### Task 2: Post addresses

**Files:**
- Create: `PostAddressRule/PostAddressRuleInterface.php`, `BlueskyPostAddressRule.php`, `MastodonPostAddressRule.php`, `LemmyPostAddressRule.php`, `SocialPostAddresses.php`, `backend/tests/Support/SocialRuleWiring.php`
- Delete: `Support/SocialPostAddress.php`, `Support/OwnPostIds.php`, `Support/OwnReplyGuids.php`, `Support/PostAuthor.php` and their four tests, after moving the cases
- Modify: the callers listed below
- Test: `tests/Service/Social/Social/SocialPostAddressesTest.php`, `tests/Service/Social/Social/PostAddressRule/*Test.php`, `tests/Service/Social/Social/NetworkRulesCompleteTest.php`

**Interfaces — produces:**

```php
#[AutoconfigureTag('app.social_post_address_rule')]
interface PostAddressRuleInterface
{
    /** The post an entry of this network's feed is; null when the entry is none. */
    public function postOf(Entry $entry): ?SocialPostAddressModel;

    /** The post an entry's discussion is, whatever feed carries the entry; null on a network whose entries are posts. */
    public function discussionPostOf(Entry $entry): ?SocialPostAddressModel;

    public function replyAt(string $address): ?SocialPostAddressModel;

    /** $url arrives without its fragment. */
    public function postAtUrl(string $url): ?SocialPostAddressModel;

    public function authorIdentityOf(Entry $entry, SocialPostAddressModel $post): ?string;

    /** @throws SocialPostRefusedException */
    public function ownPostId(SocialPostAddressModel $post, SocialAccount $account): string;

    /** @throws SocialPostRefusedException */
    public function ownReplyId(string $url, SocialAccount $account): string;

    /** @return list<string> @throws SocialPostRefusedException */
    public function ownReplyGuids(string $url, SocialAccount $account): array;
}
```

```php
final readonly class SocialPostAddresses
{
    public function __construct(
        #[AutowireLocator('app.social_post_address_rule')] private ContainerInterface $rulesByNetwork,
        /** @var iterable<PostAddressRuleInterface> */
        #[AutowireIterator('app.social_post_address_rule')] private iterable $rules,
    ) {}

    public function ruleOf(SocialNetwork $network): PostAddressRuleInterface;
    public function of(Entry $entry): ?SocialPostAddressModel;                 // by the feed's network; null feed network → null
    public function ofOrRefuse(Entry $entry): SocialPostAddressModel;
    public function replyOrRefuse(Entry $entry, string $address): SocialPostAddressModel;
    public function respondableOrRefuse(Entry $entry): SocialPostAddressModel; // of() ?? first discussionPostOf() over all rules
    public function respondableReplyOrRefuse(Entry $entry, string $address): SocialPostAddressModel;
    public function ofUrl(string $url): ?SocialPostAddressModel;               // strips the fragment, asks every rule
    /** @param list<SocialAccount> $accounts */
    public function authorAmong(Entry $entry, array $accounts): ?SocialAccount;
}
```

What each adapter answers, taken from the deleted code:

| Method | Bluesky | Mastodon | Lemmy |
|---|---|---|---|
| `postOf` | guid when `AtPostUri::matches` | `AbsoluteHttpUrl::orNull(guid) ?? orNull(url)` | `null` |
| `discussionPostOf` | `null` | `null` | `LemmyPostAddress::of($entry)?->pageUrl()` as a Lemmy address |
| `replyAt` | address when `AtPostUri::matches` | `AbsoluteHttpUrl::orNull` | `AbsoluteHttpUrl::httpsOrNull` |
| `postAtUrl` | `AtPostUri` match or `AtPostUri::ofWebUrl` | when `MastodonStatusAddress::ofOrNull` is not null | `null` |
| `authorIdentityOf` | `AtPostUri::authorityOf` | today's `MastodonAuthor::identityOfProfileUrl(...)` expression | `null` |
| `ownPostId` | the address | status id on the account's own host, else refuse | refuse with `OwnPostIds::LEMMY_NOT_DELETED`'s text |
| `ownReplyId` | `AtPostUri::ofWebUrl` with the account's authority | as `ownPostId` | refuse, same text |
| `ownReplyGuids` | `[ownReplyId]` | `[status uri, url]` | refuse, same text |

`replyOrRefuse()` still calls `ofOrRefuse()` first, so a Lemmy entry is refused there as today; only `respondableReplyOrRefuse()` reaches Lemmy's `replyAt`.

Callers to convert (inject `SocialPostAddresses`): `ConfirmedDeletion`, `OwnPostDeletion`, `SocialCounts`, `SocialCountsCache`, `SocialPostFinder`, `SocialResponses`, `SocialRepliesSource`, `SelfThreadEnricher`, `SharedPostQuoteFinder`, `ComposerContexts`, `ResponseTargetFinder`, `MastodonPendingThread`, `Library/EntryList/EntryListRowEnricher` (its two static methods that call the helper become instance methods).

- [ ] **Step 1: Write `SocialRuleWiring::postAddresses(): SocialPostAddresses`** over the three real adapters.
- [ ] **Step 2: Move the test cases.** `SocialPostAddressTest`, `OwnPostIdsTest`, `OwnReplyGuidsTest` and `PostAuthorTest` become `SocialPostAddressesTest` and the three `…PostAddressRuleTest`s; each case keeps its inputs and expected values and calls the new method. Run — FAIL, classes missing.
- [ ] **Step 3: Add the Review Focus tests** to `SocialPostAddressesTest`:

```php
public function testALemmyPostInAFeedWithoutNetworkIsRespondable(): void
// entry in a feed whose getNetwork() is null, url a Lemmy post page
// → respondableOrRefuse() returns network Lemmy, address the page URL

#[DataProvider('postUrls')]
public function testExactlyOneRuleClaimsAPostUrl(string $url, SocialNetwork $owner): void
// for a bsky.app post URL, an at:// post URI and a Mastodon status URL (both /@user/id and /users/…/statuses/id):
// ruleOf($owner)->postAtUrl($url) is not null, and every other rule returns null
```

- [ ] **Step 4: Write `NetworkRulesCompleteTest`** (a `KernelTestCase`):

```php
#[DataProvider('ruleTags')]
public function testEveryNetworkHasARule(string $pickerServiceId): void
// for each SocialNetwork::cases(): the picker's ruleOf($network) returns without throwing
```

with `SocialPostAddresses::class` as the first provider row.
- [ ] **Step 5: Implement** the interface, the three adapters and `SocialPostAddresses`; convert the callers; delete the four helpers. Run the moved tests and `php bin/phpunit` — PASS.
- [ ] **Step 6: Verify** `grep -rn "SocialPostAddress::\|OwnPostIds::\|OwnReplyGuids::\|PostAuthor::" src tests` prints nothing; `composer check` and `composer md` are clean.
- [ ] **Step 7: Commit** `refactor(#2358): a post's address is its network's rule`.

### Task 3: Profile feeds

**Files:**
- Create: `ProfileFeedRule/ProfileFeedRuleInterface.php` + three adapters, `ProfileFeeds.php`, `Support/BlueskyProfileFeed.php`, `Support/MastodonProfileFeed.php`, `Support/LemmyProfileFeed.php`
- Delete: `Support/ProfileFeedUrl.php` and its test, after moving the cases
- Modify: `ProfileFeedTitle.php`, `FeedNetworkListener.php`, `Support/SocialFeedClassifier.php`, `Support/ProfileIdentity.php`, `Pass/FollowMatching.php`, `ImportCandidates.php`, `ImportedProfilePictures.php`, `SocialImporter.php`, `Model/ProfileFeedSubscriptionModel.php`, `Model/ImportCandidateModel.php`, `Library/EntryList/EntryListRowEnricher.php`, and the network-named callers below

**Interfaces — produces:**

```php
#[AutoconfigureTag('app.social_profile_feed_rule')]
interface ProfileFeedRuleInterface
{
    /** null when $identity names no account of this network or does not round-trip through identityOf() */
    public function feedUrlOf(string $identity): ?string;
    public function identityOf(string $feedUrl): ?string;
    public function handleOf(string $identity): string;
    public function titleOf(string $feedUrl, string $channelTitle): string;
}

final readonly class ProfileFeeds   // locator + iterator, like SocialPostAddresses
{
    public function ruleOf(SocialNetwork $network): ProfileFeedRuleInterface;
    public function networkOf(string $feedUrl): ?SocialNetwork;   // the one network whose identityOf() is not null
    public function ruleOfUrl(string $feedUrl): ?ProfileFeedRuleInterface;
}
```

Static, single-network helpers (called by the adapters and by network-named code):

```php
BlueskyProfileFeed::urlOf(string $did): string                    // was ProfileFeedUrl::bluesky
BlueskyProfileFeed::identityOf(string $url): ?string
BlueskyProfileFeed::profileActorOf(string $url): ?string          // was blueskyProfileActorOf
MastodonProfileFeed::urlOf(string $user, string $domain): string  // was ::mastodon
MastodonProfileFeed::identityOf(string $url): ?string
LemmyProfileFeed::urlOf(string $community, string $host): string  // was ::lemmy
LemmyProfileFeed::identityOf(string $url): ?string
LemmyProfileFeed::userOf(string $identity): ?string               // was lemmyUserOf
```

Conversions:
- Network-named callers call their helper directly: `BlueskyCandidateSource`, `Support/ExportedFeed`, `TolerantHiddenProfiles`, `Support/StoredSearchedPost` (until Task 4 moves it), `Support/LemmyCommunity`, `Support/MastodonAccount`, `ProfilePictureSource/LemmyProfilePictures`.
- `ProfileFeedTitle::of()` returns `$this->profileFeeds->ruleOfUrl($feedUrl)?->titleOf($feedUrl, $channelTitle) ?? $channelTitle`; its two private title methods move into the Bluesky and Lemmy adapters, Mastodon's returns `$channelTitle`.
- `ProfileFeedSubscriptionModel::handleOn()` is deleted; `ImportCandidateModel`'s factory takes the handle as a `string`, and its caller asks `ProfileFeeds::ruleOf($network)->handleOf(...)`.
- `SocialFeedClassifier::networkOf()` and `networkFor()` are deleted; `privateAddressOf()` stays. `FeedNetworkListener` injects `ProfileFeeds` and holds the three-way decision (saved feed → `SavedFeedUrl::networkOf`, private address → its network, else `ProfileFeeds::networkOf`).
- `ProfileIdentity::of()` is deleted; its one caller, `ImportCandidates`, composes `ruleOf($network)->identityOf($url) ?? SocialFeedUrl::authorOf($network, $url)`. The two `validBlueskyActor…` functions stay and call `BlueskyProfileFeed::identityOf`.
- `FollowMatching` takes `ProfileFeedRuleInterface` as a constructor argument next to the network; `ImportCandidates` passes it.

- [ ] **Step 1: Move the test cases** of `ProfileFeedUrlTest`, `ProfileFeedTitleTest`, `SocialFeedClassifierTest` (its network cases go to `FeedNetworkListenerTest`), `ProfileIdentityTest` and `ProfileFeedSubscriptionModelTest` (its `handleOn` cases go to the three adapter tests). Add `SocialRuleWiring::profileFeeds()`. Run — FAIL.
- [ ] **Step 2: Add the Review Focus test** to `ProfileFeedsTest`:

```php
#[DataProvider('profileFeedUrls')]
public function testExactlyOneRuleClaimsAProfileFeed(string $url, SocialNetwork $owner): void
// https://bsky.app/profile/did:plc:abc/rss → Bluesky; https://mastodon.social/@user.rss → Mastodon;
// https://lemmy.world/feeds/c/tech.xml and https://lemmy.world/feeds/u/sam.xml → Lemmy;
// each other rule's identityOf() returns null
```

and add `ProfileFeeds::class` to `NetworkRulesCompleteTest`'s provider.
- [ ] **Step 3: Implement**, convert, delete `ProfileFeedUrl`. Run `php bin/phpunit` — PASS.
- [ ] **Step 4: Functional check for the listener** (a direct-invocation test misleads): one `KernelTestCase` case persists a `Feed` with a Mastodon profile feed URL and asserts `getNetwork()` is `SocialNetwork::Mastodon` after flush. Skip if `FeedNetworkListenerTest` already goes through the entity manager.
- [ ] **Step 5: Verify** `grep -rn "ProfileFeedUrl::\|handleOn(\|SocialFeedClassifier::network" src tests` prints nothing; `composer check`, `composer md` clean.
- [ ] **Step 6: Commit** `refactor(#2358): a profile feed is read by its network's rule`.

### Task 4: Private feed rules

**Files:**
- Create: `PrivateFeedRule/PrivateFeedRuleInterface.php` + three adapters, `PrivateFeedRules.php` (service), `Support/MastodonFeedIds.php`
- Delete: `Support/PrivateFeedRules.php` (static), `Support/PrivateFeedSite.php`, `Support/NetworkSearchQueries.php`, `Support/StoredSearchedPost.php` and their tests, after moving the cases
- Modify: `PrivateFeeds.php`, `SearchFeedEditor.php`, `ImportCandidates.php`, `SocialImporter.php`, `PrivateFeedSyncApplier.php`, `Support/PrivateFeedSyncReasons.php`, `TimelineSource/MastodonTimelineSource.php`, `TimelineSource/LemmyTimelineSource.php`, `Support/MastodonFollowedTag.php`, `SocialSearch/NetworkSocialSearch.php`

**Interfaces — produces:**

```php
#[AutoconfigureTag('app.social_private_feed_rule')]
interface PrivateFeedRuleInterface
{
    public function allows(PrivateFeedModel $privateFeed): bool;
    public function siteOf(SocialAccount $account, PrivateFeedModel $privateFeed): string;
    /** Whether a list the account holds is its own rather than one it saved. Only asked for a UserList. */
    public function ownsList(SocialAccount $account, PrivateFeedModel $list): bool;
    public function sourceFormat(): string;
    /** @return non-empty-list<string> @throws SearchQueryTooBroadException */
    public function searchQueries(string $query): array;
    public function searchedPostOf(SocialAccount $account, StoredPostModel $post): SearchedPostModel;
}

final readonly class PrivateFeedRules   // locator only
{
    public function ruleOf(SocialNetwork $network): PrivateFeedRuleInterface;
}
```

`MastodonFeedIds::isListId(string): bool`, `MastodonFeedIds::isTagName(string): bool` carry the two patterns of the deleted static helper.

Conversions:
- `PrivateFeedSyncReasons` becomes a `final readonly` service in the module root (it now needs the rule); `added()` and `removed()` keep their signatures; `isOwnedList()` returns `false` unless the kind is `UserList`, then asks `ownsList()`. `PrivateFeedSyncApplier` injects it.
- `PrivateFeeds::sourceFormatOf()` is deleted in favour of `sourceFormat()`.
- `SearchFeedEditor` calls `searchQueries()` and discards the result where it called `requireFits()`.
- Inside the Mastodon and Lemmy adapters, `allows()` for a `Search` feed catches `SearchQueryTooBroadException` from its own `searchQueries()` and answers `false`.
- `NetworkSocialSearch` and the two timeline sources call their rule's `searchQueries()` (the timeline sources inject their own network's adapter class, not the picker).

- [ ] **Step 1: Move the test cases** of `PrivateFeedRulesTest`, `PrivateFeedSiteTest`, `PrivateFeedSyncReasonsTest`, `NetworkSearchQueriesTest` and `StoredSearchedPostTest` into three `…PrivateFeedRuleTest`s and `PrivateFeedSyncReasonsTest`. Add `SocialRuleWiring::privateFeedRules()` and the `NetworkRulesCompleteTest` provider row. Run — FAIL.
- [ ] **Step 2: Add** to each adapter test `testSourceFormat()` asserting `SourceFormat::BLUESKY_API`, `MASTODON_API`, `LEMMY_API` respectively.
- [ ] **Step 3: Implement**, convert, delete. Run `php bin/phpunit` — PASS.
- [ ] **Step 4: Verify** `grep -rn "PrivateFeedSite::\|NetworkSearchQueries::\|StoredSearchedPost::\|Support\\\\PrivateFeedRules" src tests` prints nothing; `composer check`, `composer md` clean.
- [ ] **Step 5: Commit** `refactor(#2358): a private feed's rules are its network's`.

### Task 5: One search source per network

**Files:**
- Create: `SearchSource/SearchSourceInterface.php`, `BlueskySearchSource.php`, `MastodonSearchSource.php`, `LemmySearchSource.php`, `SearchAnswers.php`
- Modify: `SocialSearch/NetworkSocialSearch.php`
- Test: `tests/Service/Social/Social/SearchSource/*Test.php`, `tests/Service/Social/Social/SocialSearch/NetworkSocialSearchTest.php`

**Interfaces — produces:**

```php
#[AutoconfigureTag('app.social_search_source')]
interface SearchSourceInterface
{
    /** The caller already acts as $account. Throws what TimelineSourceInterface::accepted() throws. */
    public function newest(SocialAccount $account, string $query): TimelinePageModel;
}

final readonly class SearchAnswers
{
    /**
     * @param list<ApiRequestModel> $requests
     * @param \Closure(FetchOutcomeModel): ApiResponseModel $accept
     * @return list<ApiResponseModel> one accepted answer per request, in the requests' order
     */
    public function of(SocialAccount $account, array $requests, \Closure $accept): array;
}
```

`NetworkSocialSearch` keeps `ActingAccountGuard` and a locator on `app.social_search_source`; it injects no network client. `SearchAnswers` is the body of today's private `answers()`, used by the Mastodon and Lemmy sources.

- [ ] **Step 1: Split `NetworkSocialSearchTest`**: each network's cases move to its source's test unchanged; `NetworkSocialSearchTest` keeps one case per network proving the guard wraps the right source. Add `SearchSourceInterface`'s locator to `NetworkRulesCompleteTest` (assert `has($network->value)` for every case). Run — FAIL.
- [ ] **Step 2: Implement.** Run `php bin/phpunit tests/Service/Social/Social tests/Controller/Api/SocialSearchControllerTest.php` — PASS.
- [ ] **Step 3: Verify** `grep -c "Bluesky\|Mastodon\|Lemmy" src/Service/Social/Social/SocialSearch/NetworkSocialSearch.php` prints `0`; `composer check`, `composer md` clean.
- [ ] **Step 4: Commit** `refactor(#2358): each network searches through its own source`.

### Task 6: Credentials

**Files:**
- Create: `Support/BlueskyCredentials.php`, `Support/MastodonCredentials.php`, `Support/LemmyCredentials.php`, `Support/InstanceOrigin.php`, `Model/CredentialReplacementModel.php`
- Modify: `Model/SocialCredentialsModel.php`, `NetworkVerifier/NetworkVerifierInterface.php` and its three adapters, `AccountVerifier/AccountVerifierInterface.php`, `AccountVerifier/NetworkAccountVerifier.php`, `SocialAccountConnector.php`, `Factory/SocialAccountFactory.php`, `src/Dto/Social/Connect{Bluesky,Mastodon,Lemmy}Request.php`, `src/Dto/Social/ReplaceCredentialRequest.php`, `src/Controller/Api/SocialAccountController.php`, and every test double of `AccountVerifierInterface`

**Interfaces — produces:**

```php
final readonly class SocialCredentialsModel   // public constructor, no static method, no match
{
    public function __construct(
        public SocialNetwork $network,
        public string $address,
        #[\SensitiveParameter] public string $secret,
        public string $hint,
        public string $login = '',
        public ?string $secondFactor = null,
    ) {}
}

final readonly class CredentialReplacementModel
{
    public function __construct(#[\SensitiveParameter] public string $secret, public ?string $secondFactor) {}
}

BlueskyCredentials::of(string $handle, string $appPassword): SocialCredentialsModel            // @throws NotAnAppPasswordException
BlueskyCredentials::forReconnect(SocialAccount $account, CredentialReplacementModel $replacement): SocialCredentialsModel
MastodonCredentials::of(string $instanceUrl, string $accessToken): SocialCredentialsModel      // @throws InvalidInstanceUrlException
MastodonCredentials::forReconnect(…): SocialCredentialsModel
LemmyCredentials::of(string $instanceUrl, string $usernameOrEmail, string $password, ?string $secondFactor): SocialCredentialsModel
LemmyCredentials::forReconnect(…): SocialCredentialsModel
InstanceOrigin::of(string $instanceUrl): string                                                // @throws InvalidInstanceUrlException

// added to NetworkVerifierInterface and AccountVerifierInterface:
/** @throws NotAnAppPasswordException */
public function reconnectCredentials(SocialAccount $account, CredentialReplacementModel $replacement): SocialCredentialsModel;

// added to SocialAccountConnector, delegating to the account verifier:
public function reconnectCredentials(SocialAccount $account, CredentialReplacementModel $replacement): SocialCredentialsModel;
```

The hint is the last 4 characters of the secret for Bluesky and Mastodon and `''` for Lemmy, set by each builder; `hint()` becomes the `$hint` property. `ReplaceCredentialRequest::toCredentials()` becomes `toReplacement(): CredentialReplacementModel`. In `SocialAccountController::replaceCredential()` the order stays: require the account, build the credentials (`$this->connector->reconnectCredentials(...)`), enforce the rate limit, reconnect.

- [ ] **Step 1: Move the cases** of `SocialCredentialsModelTest` into `BlueskyCredentialsTest`, `MastodonCredentialsTest`, `LemmyCredentialsTest` and `InstanceOriginTest`. Run — FAIL.
- [ ] **Step 2: Write the Review Focus test** in `tests/Controller/Api/SocialAccountControllerTest.php`:

```php
public function testAMalformedAppPasswordOnReconnectIsRefusedWithoutCountingAgainstTheLimit(): void
// PUT /api/me/social-accounts/{id}/credential for a Bluesky account with secret "not-an-app-password",
// repeated once more than the limiter allows: every answer is the 422 the first one gave, never 429
```

If a case with that meaning exists, keep it and add nothing.
- [ ] **Step 3: Implement.** Run `php bin/phpunit` — PASS.
- [ ] **Step 4: Verify** `grep -n "match\|SocialNetwork::" src/Service/Social/Social/Model/SocialCredentialsModel.php` prints nothing; `composer check`, `composer md` clean.
- [ ] **Step 5: Commit** `refactor(#2358): each network shapes its own credentials`.

### Task 7: Close out

**Files:**
- Modify: `docs/architecture.md` — in §9 or §10, one paragraph: a fact about a social network lives in its `…Rule` adapter; a function one network alone needs is a static helper named for it; no shared file in `Service/Social/Social` matches on a `SocialNetwork` case to state a fact.

- [ ] **Step 1: The acceptance grep.** Run:

```bash
grep -rnE 'SocialNetwork::(Bluesky|Mastodon|Lemmy)' src/Service/Social/Social | grep -viE '/[^/]*(bluesky|mastodon|lemmy)[^/]*\.php:'
```

Expected: only the out-of-scope files named in Global Constraints (`DiscussionVotes`, `HiddenProfile*`, `HiddenImportFollows`, `FollowAvatarCache`, `Pass/FollowMatching`, `Support/ExportedFeed`, `Support/ProfileIdentity`) and `AsTaggedItem` lines. Any other hit is unfinished work.
- [ ] **Step 2: Full gates.** `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test:parallel`, `composer infection:diff`. All green; paste the tails into the PR.
- [ ] **Step 3: Scan today's dev log** for new deprecations or container errors.
- [ ] **Step 4: Commit** `docs(#2358): where a network's facts live`, run `/simplify`, open the PR into `develop` with `Closes #2358`.
