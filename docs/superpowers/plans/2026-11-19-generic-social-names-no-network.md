# Generic Social Code Names No Network Class Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No generically named class in `Service/Social/{Social,Notification,Share,Completion}` imports or calls a class named after a network, so #2359 can move each network's files into its own module as a pure rename.

**Architecture:** Static helpers with one method per network split into one helper per network. Three behaviours get a seam owned by `Social/Social`: a keyed import rule, a keyed post voter, and a disconnect event. `ProfileFeedRuleInterface` gains the export URL. Two composer models lose the network from their names.

**Tech Stack:** PHP 8.5, Symfony 8.1 (`#[AutoconfigureTag]`, `#[AsTaggedItem]`, `#[AutowireLocator]`, `#[AutowireIterator]`, `#[AsEventListener]`), PHPUnit 12, PHPStan max, PHPMD.

**Spec:** GitHub issue #2367; follows `docs/superpowers/plans/2026-11-19-per-network-facts.md` (#2358) and prepares #2359.

**Branch:** `refactor/2367-generic-social-names-no-network`, cut from freshly pulled `develop` after #2358 is merged.

## Global Constraints

- No behaviour change and no API change: every JSON key, every problem `detail` string and every log line stays byte-identical.
- Every existing test case survives; a test moves or is renamed with its subject, it is never deleted to make a task pass.
- Per task, green before commit: `composer check`, `composer md`, `php bin/phpunit`. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No edit under `tests/PhpStan`, no `@phpstan-ignore`, no PHPMD suppression, no new baseline.
- No class is created to get under a PHPMD count (#2025). New `src` classes in this plan are listed per task; anything beyond that list needs Lars's "approve classes" in the committing session.
- Commit format `refactor(#2367): …`. No attribution lines.
- Method names follow the verb dictionary in `CLAUDE.md` ("Names reveal intent"): `build…`, `parse…`, `find…`, `require…`, `fetch…`, `count…`, `format…`, `select…`, `is…/has…/owns…/allows…`; every verb is followed by its object.
- Keyed adapters follow `TimelineSource/` + `TimelineSources.php`: interface with `#[AutoconfigureTag('app.social_…')]`, adapters with `#[AsTaggedItem(index: SocialNetwork::X->value)]`, picker with `#[AutowireLocator]`.
- New adapters stay in `Service/Social/Social` under a folder named after their interface. Nothing moves to another module in this PR; that is #2359.
- The locator body (get, assert, return) is copied again for the two new pickers. Do not extract a helper here; #2360 owns that.
- Out of scope: moving any file between modules; `Social/Bluesky` (the lower module) and everything that imports it; `Content/Comments` Lemmy classes; the named constructors `SocialPostModel::onBluesky/onMastodon/onLemmy`; `RefusedProfileSubscriptionModel`, `RefusedSubscriptionSelection`, `HiddenProfile*`, `AppViewAnswer` (Bluesky-only by content, they move whole in #2359); frontend.
- Also out of scope, ruled on explicitly: the named constructors and fields of `Completion/Model/KnownSharesModel` (`ofBlueskyPosts()`, `ofMastodonShares()`, `blueskyPostFor()`) and the imports of `PostEnricher`, `EntryEmbedWriterInterface`, `FillOutcome` and `BlueskyPostView` in `Social/Completion`: they point at the lower Bluesky module, which stays below `Completion` after #2359. The feed kinds only one network has (`SocialFeedKind::Tag`, `CustomFeed`, and `Author` carrying a DID) with the `$tags`/`$customFeeds` fields of `NetworkCollectionsModel` and `NetworkCandidatesModel`: they are values of a shared enum, and each network's `PrivateFeedRuleInterface::allows()` already decides which it takes. Comments that mention a network in files this plan does not otherwise touch.

## Decisions (Lars, 2026-11-19)

- Import: one keyed import rule per network, not an extension of `CandidateSourceInterface` and not one seam per branch.
- Disconnect: a domain event, not a verifier method.
- Composer: `LemmyLinkModel` and `LemmyCommunityModel` stay in `Social/Social/Model` under network-free names.
- Export: a method on `ProfileFeedRuleInterface`.
- Voting: a full keyed adapter family like liking and reposting.

## Review Focus

- A Mastodon or Lemmy import: no follow is treated as hidden, no handle resolution call is made, no avatar is cached. Test on `ImportCandidates` and `SocialImporter` with each network's rule.
- A Bluesky import where the AppView cannot answer: all follows still come in by RSS and the log line is unchanged.
- Disconnecting through the real dispatcher forgets the Bluesky session; a listener test that calls the listener directly does not prove this.
- An entry that is no votable post: `detail` is still "This entry is not a Lemmy post."; a comment address that is not https: still "The address names no Lemmy comment."
- Export of a Bluesky author feed whose `Feed::getNetwork()` is null still leaves as the profile's RSS: the export asks the feed URL, not the stored network.
- A Mastodon post reached through an RSS profile feed and confirmed deleted is still removed without replies; only an entry of a Mastodon account feed keeps its other copies.
- `GET` composer context JSON for a Lemmy account is byte-identical, key `lemmy` included.

---

### Task 1: One static helper per network

**Files:**
- Create: `Support/BlueskyCommentPost.php`, `Support/MastodonCommentPost.php`, `Support/BlueskyPostCounts.php`, `Support/MastodonPostCounts.php`, `Support/BlueskySearchPath.php`, `Support/MastodonSearchPath.php`, `Support/LemmySearchPath.php`, `Support/BlueskyProfileActor.php` (all under `backend/src/Service/Social/Social/`)
- Modify: `Support/PostCounts.php`, `Support/ProfileIdentity.php`, `Support/SocialFeedUrl.php`, and the callers: `BlueskyThreadReplies`, `MastodonContextReplies`, `PostCountsSource/*`, `PostResolver/{Bluesky,Mastodon}PostResolver`, `TimelineSource/{Bluesky,Mastodon}TimelineSource`, `SearchSource/{Bluesky,Mastodon}SearchSource`, `LemmyAccountApi`, `HiddenProfileSwitchSweep`, `ProfilePictureSource/BlueskyProfilePictures`
- Delete: `Support/CommentPost.php`, `Support/SocialSearchPaths.php`
- Test: `CommentPostTest`, `PostCountsTest` and `SocialSearchPathsTest` move to one test class per new helper, cases unchanged; `ProfileIdentityTest` keeps the two generic methods' cases and its Bluesky cases move to `BlueskyProfileActorTest`; `SocialFeedUrlTest` (the `authorOf` cases near line 149) follows the rename.

**Interfaces — Produces:**

```php
BlueskyCommentPost::parsePost(JsonNodeModel $post): ?CommentPostModel
MastodonCommentPost::parseStatus(JsonNodeModel $status): ?CommentPostModel
BlueskyPostCounts::parsePost(JsonNodeModel $post): ?SocialCountsModel
MastodonPostCounts::parseStatus(JsonNodeModel $status): ?SocialCountsModel
PostCounts::parseKeys(...)            // the former private ofKeys(), signature unchanged, now public
BlueskySearchPath::buildPath(string $query): string
MastodonSearchPath::buildPath(string $query, ?string $newestSeenId): string
LemmySearchPath::buildPath(string $query): string
BlueskyProfileActor::parseFollowedActor(string $feedUrl): ?string   // was ProfileIdentity::validBlueskyActorOf
BlueskyProfileActor::parseRssActor(string $feedUrl): ?string        // was ProfileIdentity::validBlueskyActorOfRssFeed
SocialFeedUrl::parseAuthor(SocialNetwork $network, string $url): ?string   // was authorOf(), without the DID check
```

`SocialFeedUrl::parseAuthor()` returns the author address's remote id for that network; the `BlueskyActor::isDid()` check it carried moves into `BlueskyProfileActor` and, in Task 2 and Task 5, into the Bluesky adapters. `ProfileIdentity` keeps `parseNameAndHost()` and `parseRoundTripUrl()` only.

- [ ] **Step 1:** Move each test case to the new helper's test class and rename the call; add `testParseAuthorReturnsAHandleShapedRemoteId` for `SocialFeedUrl::parseAuthor()` (an author address whose remote id is no DID now returns it) and `testParseFollowedActorRefusesANonDidAuthorFeed` for `BlueskyProfileActor` (returns null, as before).
- [ ] **Step 2:** Run the moved tests. Expected: FAIL, classes not found.
- [ ] **Step 3:** Create the helpers by moving the method bodies; update the callers.
- [ ] **Step 4:** `php bin/phpunit --filter 'CommentPost|PostCounts|SearchPath|ProfileActor|SocialFeedUrl'`, then `composer check && composer md`. Expected: PASS.
- [ ] **Step 5:** `grep -rn 'CommentPost::of\|SocialSearchPaths\|validBlueskyActor\|SocialFeedUrl::authorOf' backend/src backend/tests backend/migrations` — expected: none. Grep for `SocialFeedUrl::authorOf`, not bare `authorOf`: `MastodonContextReplies` and `BlueskyThreadReplies` have unrelated private methods of that name. Of the three former callers, `ProfileIdentity:15` went to `BlueskyProfileActor`; `ImportCandidates:153` and `ExportedFeed:21` call `parseAuthor` plus an inline DID check kept verbatim until Task 2 and Task 5 remove them.
- [ ] **Step 6:** Commit `refactor(#2367): each network parses its own comment posts, counts and search paths`.

### Task 2: Import rule

**Files:**
- Create: `ImportRule/ImportRuleInterface.php`, `ImportRule/BlueskyImportRule.php`, `ImportRule/MastodonImportRule.php`, `ImportRule/LemmyImportRule.php`, `ImportRules.php`
- Modify: `SocialImporter.php`, `ImportCandidates.php`, `FollowMatcher.php`, `Pass/FollowMatching.php`, `FollowAvatarCache.php`, `HiddenImportFollows.php`
- Test: `tests/Service/Social/Social/ImportRule/*Test.php`, existing `SocialImporterTest`, `ImportCandidatesTest`, `FollowMatcherTest`, `FollowMatchingTest`, `FollowAvatarCacheTest`, `NetworkRulesCompleteTest`

**Interfaces — Consumes:** `SocialFeedUrl::parseAuthor()`, `BlueskyProfileActor` (Task 1); `ProfileFeeds`, `ProfileFeedRuleInterface` (#2358).

**Produces:**

```php
#[AutoconfigureTag('app.social_import_rule')]
interface ImportRuleInterface
{
    /**
     * @param list<SocialImportFeedModel> $feeds
     * @return array<string, PrivateFeedModel> by the chosen identity: the follows that come in through the account
     */
    public function findAccountOnlyFeeds(SocialAccount $account, array $feeds): array;

    /** the identity a subscribed feed follows on this network; null for any other feed */
    public function parseFollowedIdentity(string $feedUrl): ?string;

    public function isResolvedIdentity(string $identity): bool;

    /**
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function fetchResolvedIdentity(string $identity): string;

    public function allowsFollowAvatarCache(): bool;
}

final readonly class ImportRules { public function selectRule(SocialNetwork $network): ImportRuleInterface; }

FollowMatching::__construct(ProfileFeedRuleInterface $profileFeedRule, ImportRuleInterface $importRule, array $follows)
FollowMatching::unresolvedProfileFeeds(): array      // was unmatchedHandles()
FollowMatcher::match(FollowMatching $matching, ImportRuleInterface $importRule, array $profileFeeds): array
```

Adapters: `BlueskyImportRule` injects `HiddenImportFollows` and `BlueskyIdentityResolver`; `findAccountOnlyFeeds()` maps each hidden profile through `toAuthorFeed()`; `parseFollowedIdentity()` is `BlueskyProfileFeed::parseIdentity()` or the DID of an author feed; `isResolvedIdentity()` is `BlueskyActorKey::isDid()`; `allowsFollowAvatarCache()` is true. Mastodon and Lemmy: no account-only feeds, `parseFollowedIdentity()` is their profile feed rule's `parseIdentity()`, every identity is resolved, `fetchResolvedIdentity()` returns its argument, no avatar cache. The network guards in `HiddenImportFollows::among()`, `FollowMatching` and `FollowAvatarCache::remember()` are deleted; `ImportCandidates` asks `allowsFollowAvatarCache()` before `remember()`. The reason Mastodon avatars are not cached (today a comment on `FollowAvatarCache::remember()`) goes with the answer to `MastodonImportRule`.

- [ ] **Step 1:** Write `ImportRule` tests: Bluesky returns an author feed per hidden follow keyed by the chosen identity and none when the AppView cannot say; `isResolvedIdentity('did:plc:abc')` true, `('alice.bsky.social')` false; Mastodon and Lemmy return `[]`, true, and `allowsFollowAvatarCache()` false. Add `ImportRules::class` to `NetworkRulesCompleteTest::ruleTags()`.
- [ ] **Step 2:** In the existing tests, add the Review Focus cases: `ImportCandidatesTest::testAMastodonImportResolvesNoHandleAndCachesNoAvatar`, `SocialImporterTest::testALemmyImportTreatsNoFollowAsHidden`.
- [ ] **Step 3:** Run them. Expected: FAIL, interface not found.
- [ ] **Step 4:** Implement the interface, the three adapters and the picker; rewire `SocialImporter`, `ImportCandidates`, `FollowMatcher`, `FollowMatching`, `FollowAvatarCache`.
- [ ] **Step 5:** `php bin/phpunit --filter 'Import|FollowMatch|FollowAvatar|NetworkRulesComplete'`, `composer check && composer md`. Expected: PASS; `SocialImporter` and `ImportCandidates` name no `Hidden*` or `Bluesky*` class and no `SocialNetwork::Bluesky`.
- [ ] **Step 6:** Commit `refactor(#2367): each network answers its own import questions`.

### Task 3: Disconnect event

**Files:**
- Create: `backend/src/Event/SocialAccountDisconnected.php`, `backend/src/Service/Social/Social/BlueskySessionForgetListener.php`
- Modify: `SocialAccountEditor.php`
- Test: `tests/Service/Social/Social/SocialAccountEditorTest.php` (functional case), `tests/Service/Social/Social/BlueskySessionForgetListenerTest.php`

**Produces:**

```php
final readonly class SocialAccountDisconnected { public function __construct(public SocialAccount $account) {} }

#[AsEventListener]
final readonly class BlueskySessionForgetListener { public function __invoke(SocialAccountDisconnected $event): void; }
```

`SocialAccountEditor::disconnect()` dispatches the event where it calls `forget()` today, before the transaction, through an injected `Psr\EventDispatcher\EventDispatcherInterface`, and no longer injects `BlueskySessionCache`. The listener forgets for every account, as today, with no network guard: `forget()` only deletes a `bluesky_session.…` key, which no other network writes.

- [ ] **Step 1:** Write `SocialAccountEditorTest::testDisconnectingABlueskyAccountForgetsItsSession` as a kernel test: editor from the container, a cached session for the account, `disconnect()`, assert the cache no longer holds it.
- [ ] **Step 2:** Run it against the unchanged code. Expected: PASS (it pins today's behaviour). Then remove the `forget()` call. Expected: FAIL.
- [ ] **Step 3:** Add the event and the listener, dispatch from the editor.
- [ ] **Step 4:** Run the test, `composer check && composer md`. Expected: PASS.
- [ ] **Step 5:** Commit `refactor(#2367): a disconnected account is announced, Bluesky forgets its session`.

### Task 4: Post voter family

**Files:**
- Create: `PostVoter/PostVoterInterface.php`
- Rename: `LemmyVoting.php` → `PostVoter/LemmyPostVoter.php` (it becomes the adapter; no wrapper class)
- Modify: `DiscussionVotes.php`
- Test: new `tests/Service/Social/Social/DiscussionVotesTest.php` and `tests/Service/Social/Social/PostVoter/LemmyPostVoterTest.php`. Neither class has a test today, so the first step pins the current behaviour before anything moves. `NetworkRulesCompleteTest` is not extended: not every network votes.

**Produces:**

```php
#[AutoconfigureTag('app.social_post_voter')]
interface PostVoterInterface
{
    public function isVotablePost(Entry $entry): bool;

    /** @throws the six exceptions LemmyVoting::castOnPost() declares today */
    public function castVoteOnPost(SocialAccount $account, Entry $entry, VoteScore $score): DiscussionVoteResultModel;

    /** @throws the same six */
    public function castVoteOnComment(SocialAccount $account, string $address, VoteScore $score): DiscussionVoteResultModel;

    /** @throws the same six */
    public function fetchPostVote(SocialAccount $account, Entry $entry): VoteScore;
}
```

`LemmyPostVoter` carries `#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]`, keeps `ActingAccountGuard`, and reads the post through `LemmyPostAddress::of($entry)`. `DiscussionVotes` takes the voters through `#[AutowireLocator('app.social_post_voter', indexAttribute: 'index')]`, finds the network whose voter says `isVotablePost($entry)`, and asks `ActingAccountChooser::accountFor()` for that network. Its three public signatures do not change. The two refusal texts are built from the display names of the networks that have a voter, so with one voter they read exactly "This entry is not a Lemmy post." and "The address names no Lemmy comment."; with several the names join with ", " and a final " or ". (`SocialPostAddresses` keeps its own literal from #2358; do not share code with it.)

- [ ] **Step 1:** Write `DiscussionVotesTest` against the unchanged `DiscussionVotes` (kernel test, HTTP client mocked as the other Lemmy account tests do): a vote is cast and mirrored into `discussion_vote`; a vote of `VoteScore::None` drops the stored row; a recast by another account updates it; `voteOnComment()` forgets the cached comments; `syncFromNetwork()` mirrors what the network reports; `testAnEntryNoVoterTakesIsRefusedWithTheLemmyText` and `testACommentAddressThatIsNotHttpsIsRefusedWithTheLemmyText` assert both messages verbatim. Run. Expected: PASS. Commit as `test(#2367): pin discussion votes before the voter family`.
- [ ] **Step 2:** Write `LemmyPostVoterTest` for the three adapter methods (request path and body per call, the result's `myVote` and `score`, `isVotablePost()` true for a Lemmy post entry and false for an RSS entry). Run. Expected: FAIL, class not found.
- [ ] **Step 3:** Add the interface, rename and adapt `LemmyVoting`, rewrite `DiscussionVotes` to select the voter.
- [ ] **Step 4:** `php bin/phpunit --filter 'DiscussionVotes|LemmyPostVoter|EntryVote'`, `composer check && composer md`. Expected: PASS; `DiscussionVotes` names no `Lemmy*` class and no `SocialNetwork::Lemmy`.
- [ ] **Step 5:** Commit `refactor(#2367): voting goes through a keyed post voter`.

### Task 5: Export URL on the profile feed rule

**Files:**
- Create: `ExportedFeeds.php`
- Modify: `ProfileFeedRule/ProfileFeedRuleInterface.php` and its three adapters, `ProfileFeeds.php`, `backend/src/Service/Library/Opml/OpmlExporter.php`, `backend/src/Service/Operations/Backup/AccountBackupExporter.php`
- Delete: `Support/ExportedFeed.php`
- Test: `tests/Service/Social/Social/ExportedFeedsTest.php` (the former `ExportedFeedTest`), the three `ProfileFeedRule` tests, `OpmlExporterTest`, `AccountBackupExporterTest`

**Produces:**

```php
// ProfileFeedRuleInterface
/** the public feed URL this feed leaves as on export; null when it leaves as it is */
public function buildExportUrl(string $feedUrl): ?string;

// ProfileFeeds
public function findExportUrl(string $feedUrl): ?string;   // first rule that answers, over the tagged iterator

final readonly class ExportedFeeds
{
    public function buildExport(Subscription $subscription): ExportedSubscriptionModel;
    /** @param list<Subscription> $subscriptions @return list<ExportedSubscriptionModel> */
    public function buildExportsWithoutRepeats(array $subscriptions): array;
}
```

Bluesky answers `BlueskyProfileFeed::buildUrl($did)` for an author feed whose remote id is a DID, with `SourceFormat::XML` set by `ExportedFeeds` as today; Mastodon and Lemmy answer null. The class comment of `ExportedFeed` (why an author feed leaves as RSS) goes to `BlueskyProfileFeedRule::buildExportUrl()`.

- [ ] **Step 1:** Move `ExportedFeedTest` to `ExportedFeedsTest` with a real `ProfileFeeds`; add `testAnAuthorFeedWithNoStoredNetworkStillExportsAsRss`; add `buildExportUrl` cases to the three rule tests (Bluesky author feed → RSS URL, Bluesky RSS feed → null, Mastodon and Lemmy → null).
- [ ] **Step 2:** Run. Expected: FAIL, method not found.
- [ ] **Step 3:** Implement; inject `ExportedFeeds` into the two exporters.
- [ ] **Step 4:** `php bin/phpunit --filter 'Export|ProfileFeedRule|Opml|Backup'`, `composer check && composer md`. Expected: PASS. If `ProfileFeedRuleInterface` adapters now trip a PHPMD limit, stop and report; do not split.
- [ ] **Step 5:** Commit `refactor(#2367): the profile feed rule names the export URL`.

### Task 6: Composer models without a network in the name

**Files:**
- Rename: `Model/LemmyLinkModel.php` → `Model/LinkPostChoicesModel.php`, `Model/LemmyCommunityModel.php` → `Model/CommunityModel.php`
- Modify: `Model/ComposerDefaultsModel.php` (`$lemmyLink` → `$linkPost`), `ComposerDefaults/LemmyComposerDefaults.php`, `PostWriter/LemmyPostWriter.php`, `LemmyCommunities.php`, `backend/src/Http/ComposerContextJson.php` (the phpstan-type `LemmyRow` → `LinkPostRow`; the JSON key `'lemmy'` stays)
- Test: renamed alongside; `ComposerContextJsonTest` unchanged in its expected arrays.

- [ ] **Step 1:** Rename with `mcp__phpstorm__rename_refactoring` or by hand; no logic changes.
- [ ] **Step 2:** `php bin/phpunit --filter 'Composer|LemmyPostWriter|LemmyCommunities'`, `composer check && composer md`. Expected: PASS with no expected-array edits in `ComposerContextJsonTest`.
- [ ] **Step 3:** Commit `refactor(#2367): the link post choices carry no network in their name`.

### Task 7: Which deletion proves every copy gone

**Files:**
- Modify: `PrivateFeedRule/PrivateFeedRuleInterface.php` and its three adapters, `PrivateFeedRules.php`, `ConfirmedDeletion.php`
- Test: `ConfirmedDeletionTest`, the three `PrivateFeedRule` tests

**Produces:**

```php
// PrivateFeedRuleInterface
/** true when a post in an account feed may be visible to followers only, so a stranger's 404 proves nothing */
public function hasFollowersOnlyPosts(): bool;

// PrivateFeedRules
public function findRuleReading(string $sourceFormat): ?PrivateFeedRuleInterface;   // loops SocialNetwork::cases() through selectRule(); the three formats are distinct
```

Mastodon answers true, Bluesky and Lemmy false. `ConfirmedDeletion::remove()` asks the rule that reads the entry's feed: with a rule that has followers-only posts it removes only this copy, in every other case (no rule, or a rule without them) it removes without replies. The comment on the branch goes to `MastodonPrivateFeedRule::hasFollowersOnlyPosts()`. `ConfirmedDeletion` names no `SourceFormat::MASTODON_API`.

- [ ] **Step 1:** In `ConfirmedDeletionTest` keep the existing cases and add `testAMastodonPostInAnRssFeedIsRemovedWithoutReplies` and `testABlueskyAccountFeedPostIsRemovedWithoutReplies`; add `hasFollowersOnlyPosts` to the three rule tests.
- [ ] **Step 2:** Run. Expected: the new `ConfirmedDeletion` cases PASS on the unchanged code (they pin it), the rule cases FAIL.
- [ ] **Step 3:** Implement the method, the lookup and the rewrite of `remove()`.
- [ ] **Step 4:** `php bin/phpunit --filter 'ConfirmedDeletion|PrivateFeedRule'`, `composer check && composer md`. Expected: PASS.
- [ ] **Step 5:** Commit `refactor(#2367): the private feed rule says whether a 404 proves a post gone`.

### Task 8: Acceptance, docs, PR

- [ ] **Step 1:** Acceptance, two checks. `grep -rnE 'SourceFormat::(MASTODON|BLUESKY|LEMMY)' ` over the same generically named files: expected none. Then, from `backend/src/Service/Social`, list every file in `Social`, `Notification`, `Share`, `Completion` whose basename contains none of `Bluesky|Mastodon|Lemmy|Hidden|ProfileVisibility|AppView|Refused` and print its lines matching `\b\w*(Bluesky|Mastodon|Lemmy)\w*(::|\s+\$\w|;)`. Expected leftovers, and only these: imports from `App\Service\Social\Bluesky\…` (the lower module) and `App\Service\Content\Comments\…`; `SocialPostModel::onBluesky/onMastodon/onLemmy` call sites; `SocialNetwork::X` enum cases inside `#[AsTaggedItem]`. Any other line is a miss: fix it or report it.
- [ ] **Step 2:** `docs/architecture.md`: extend the #2358 paragraph on where a network's facts live with the import rule, the post voter and the disconnect event (three sentences at most).
- [ ] **Step 3:** Full gates: `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test:parallel`, `composer infection:diff`; scan today's `backend/var/log/dev-*.log`.
- [ ] **Step 4:** `/simplify`, then push and open the PR into `develop` with `Closes #2367`, naming what #2359 still moves.
