# Retire `…Of` Names: Social Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No method in `Service/Social/Social`, `Social/Share`, `Social/Completion` or `Social/Notification` is named `of`, `of…` or `…Of`, except the one kept name under Decisions; the four interfaces these modules own are renamed with their implementations in the network modules.

**Architecture:** Renames only, the sixth PR for #2360. Each method gets the dictionary verb for what it does; the tables below are the decision. Callers anywhere in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2360 and the method-verb table in `CLAUDE.md`. The rules of the first plan (`2026-11-19-of-names-identity-sourcing-outbound.md`, Global Constraints and Decisions) apply unchanged and are repeated here in short.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables (`self::of(...)`), string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No PHPStan rule for the names, no `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class.
- Commits `refactor(#2360): …`. The PR says `Refs #2360`, not `Closes`. No attribution lines.
- Renames go through search and replace or the IDE's code-only rename: no tool may rewrite text in `docs/`. Before each commit `git status` shows no change under `docs/` other than this plan's own table updates.
- A method of these areas that matches the pattern but is not in a table, a new name that collides in its class, or a name that does not say what the body does: stop and report the row; do not pick a name.

## Decisions

- **A name says what the method really does** (Lars, 2026-11-19: "always look at what the method really does and chose the name based on that"). Every row below was named from the method's full body and its callers, not from its return type or its old name. A dictionary verb is used where its meaning matches; where no row of the dictionary fits, the plain verb for what happens (`clip`, `split`, `strip`, `collect`, `detect`, `pick`, `hash`).
- `of()` on a named constructor or a conversion becomes `from<Source>()` or `to<Target>()`.
- `parse…` interprets a decoded reply or a string into a value that may be absent; `read…` is a plain access with a default; `collect…` gathers from a list; `pick…` chooses one value; `select…` picks an adapter for a key; `compute…` works a number out.
- **Kept:** `PrivateFeeds::subscribeInPlaceOf()`. "In place of" is the phrase, and `subscribe…` says what it does.
- `SocialRepliesSource::audienceOf()` implements `CommentsSourceInterface` and was renamed `buildAudience()` with it in the Http and Content batch; it has no row here.
- An interface method is renamed with every implementation and double: `TimelineSourceInterface::pageOf()`, `PostCountsSourceInterface::countsOf()`, `ReplyThreadSourceInterface::selfReplyOf()`, `NotificationContextSourceInterface::contextsOf()`. Their implementations live in `Social/Bluesky`, `Social/Mastodon` and `Social/Lemmy` and are renamed here, not in the network batches.

## The renames

The last column is what the method does, which is what the reviewer checks the name against.

### Social/Social, under `backend/src/Service/Social/Social/`

| File | Old | New | What it does |
|---|---|---|---|
| `ActingAccountChooser.php` | `accountOf` (private) | `findAccount` | the account that acts on a feed: the private feed's own when it is the user's, else the acting one on the network; or null |
| `ComposerContexts.php` | `ofReply` | `buildReplyContext` | the composer context for a reply, mentions prefilled |
| `ComposerContexts.php` | `ofQuote` | `buildQuoteContext` | the composer context for quoting a post |
| `ComposerContexts.php` | `ofLink` | `buildLinkContext` | the composer context for sharing a link |
| `ComposerContexts.php` | `ofQuotedLink` | `buildQuotedLinkContext` | the composer context for a shared link that names a quotable post |
| `ComposerContexts.php` | `startingTextOf` (private) | `buildStartingText` | the text a link share starts with: the quote for a link post, else the share text |
| `ComposerContexts.php` | `quoteContextOf` (private) | `buildQuotingContext` | the quote context for a post, starting with a given quote |
| `ComposerContexts.php` | `contextOf` (private) | `buildContext` | the context model for a post, kind, defaults and text |
| `ComposerContexts.php` | `threadStarterOf` (private) | `buildThreadStarterLookup` | the closure that finds a reply target's thread starter when asked |
| `DeletedPostTombstones.php` | `keyOf` (private) | `buildKey` | the cache key of a feed's tombstones |
| `FollowAvatarCache.php` | `keyOf` (private) | `buildKey` | the cache key of an account's follow avatars |
| `ImportCandidates.php` | `singletonsOf` (private) | `buildSingletons` | a candidate for each timeline the network allows |
| `ImportCandidates.php` | `privateFeedSubscriptionsOf` (private) | `loadPrivateFeedSubscriptions` | the account's subscribed collections as subscription id by key |
| `ImportCandidates.php` | `networkCandidatesOf` (private) | `fetchNetworkCandidates` | asks the network, acting as the account, for what can be imported |
| `ImportCandidates.php` | `profileFeedsOf` (private) | `loadProfileFeeds` | the user's subscriptions that follow an identity on the network |
| `OwnPostDeletion.php` | `authorOf` (private) | `requireAuthor` | the connected account that wrote the post; throws when none did |
| `OwnPostDeletion.php` | `replyUrlsOf` (private) | `requireReplyUrls` | the thread's reply URLs, newest first; throws when one has none |
| `OwnPostDeletion.php` | `replyIdsOf` (private) | `requireReplyIds` | each reply's own id on the network; throws for a reply that is not the author's |
| `OwnPostDeletion.php` | `replyGuidsOf` (private) | `requireReplyGuids` | each reply's guids; throws for a reply that is not the author's |
| `PostLiking.php` | `likerOf` (private) | `selectLiker` | the liker of the account's network |
| `PrivateFeedSyncApplier.php` | `subscribedOf` (private) | `loadSubscribedCollections` | the account's subscribed collections of the remembered kinds, by key |
| `PrivateFeedSyncApplier.php` | `modelsOf` (private) | `buildPrivateFeeds` | a private feed for each collection of a kind |
| `PrivateFeedSyncApplier.php` | `titlesOf` (private) | `collectTitles` | the display title of each subscription by id |
| `PrivateFeeds.php` | `subscriptionOf` | `findSubscription` | the subscription to a private feed, or null |
| `PrivateFeeds.php` | `subscribeInPlaceOf` | kept | subscribes, the new subscription taking over the previous one's settings |
| `PrivateFeeds.php` | `removeAllOf` | `unsubscribeAllFeeds` | unsubscribes every feed of an account and returns how many |
| `ReplyThreadOrder.php` | `pageOf` | `buildPage` | the replies ordered by interest and walked into a comments page |
| `ReplyThreadOrder.php` | `interestOf` (private) | `computeInterest` | a reply's weighted likes, reposts, quotes and replies |
| `ResponseTargetFinder.php` | `addressOf` (private) | `requireAddress` | the address of the post or reply to respond to; throws when it cannot be responded to |
| `SearchFeedEditor.php` | `searchFeedOf` (private) | `requireSearchFeed` | the subscription's search feed; throws when it is none |
| `SearchFeedEditor.php` | `ruleOf` (private) | `selectRule` | the private feed rule of the feed's network |
| `SelfReplyChain.php` | `of` | `buildChain` | the author's own reply chain, oldest reply first at each level |
| `SelfThreadEnricher.php` | `foldThreadOf` (private) | `foldThread` | reads a row's thread and folds the author's replies into the entry; false when another worker took the row |
| `SelfThreadEnricher.php` | `rootCardUrlsOf` (private) | `loadRootCardUrls` | the URLs of the entry's stored link cards |
| `SelfThreadEnricher.php` | `chainOf` (private) | `fetchChain` | the self-reply chain of a thread read in public; empty when it is gone, throws when throttled |
| `SharedPageCards.php` | `cardOf` | `resolveCard` | the page's own card, else a card naming its host |
| `SharedPageCards.php` | `hostCardOf` (private) | `buildHostCard` | a card that names only the URL's host |
| `SocialAccountEditor.php` | `accountsOf` | `findAccounts` | the user's social accounts |
| `SocialAccountEditor.php` | `siblingsOf` (private) | `findSiblings` | the user's other accounts on the same network |
| `SocialCounts.php` | `of` | `fetchCounts` | a post's counts from the cache, else from the network; throws when the post is none or gone |
| `SocialCounts.php` | `actingAccountCountsOf` (private) | `fetchCountsAsActingAccount` | the counts read through the user's account after the public read failed |
| `SocialCountsCache.php` | `keyOf` (private) | `buildKey` | the cache key of a post's counts |
| `SocialImporter.php` | `assertCollectionsOf` (private) | `assertCollectionsAllowed` | throws when the network allows one of the collections not |
| `SocialImporter.php` | `profileFeedOf` (private) | `requireProfileFeedUrl` | the profile feed URL of an identity; throws when it names no account |
| `SocialRepliesSource.php` | `accountOrPublicThreadOf` (private) | `fetchThreadAsAccountOrPublic` | the thread read as the account, falling back to the public read |
| `SocialRepliesSource.php` | `publicThreadOf` (private) | `fetchPublicThread` | the thread read in public; throws throttled or unavailable |
| `SocialRepliesSource.php` | `postOf` (private) | `requirePost` | the entry's post address; throws when the reader cannot act on it |
| `TimelineSelfThreads.php` | `placementsOf` (private) | `loadPlacements` | loads the stored thread positions and works out where each root's replies go, keyed by root guid |
| `TimelineSelfThreads.php` | `rootsOf` (private) | `loadRoots` | the thread roots: created ones, else stored ones by guid hash |
| `TimelineSelfThreads.php` | `orphansOf` (private) | `collectOrphans` | the replies whose root has no placement |
| `TimelineSelfThreads.php` | `hashesOf` (private) | `hashGuids` | the distinct hashes of the guids |
| `TimelineSources.php` | `of` | `selectSource` | the timeline source of the account's network |
| `Model/CollectionCandidateModel.php` | `of` | `fromPrivateFeed` | a candidate for a private feed, subscribed when the account already has it |
| `Model/FollowAvatarsModel.php` | `of` | `fromFollows` | the avatars of the follows that have one, by identity |
| `Model/FollowAvatarsModel.php` | `avatarOf` | `findAvatar` | an identity's avatar URL, or null |
| `Model/PrivateFeedModel.php` | `of` | `fromKind` | the private feed of a kind, remote id and name |
| `Model/PrivateFeedModel.php` | `keyOf` | `buildKey` | `kind:remoteId` |
| `Model/PrivateFeedModel.php` | `timelineNameOf` (private) | `formatTimelineName` | a timeline kind's display name |
| `Model/TimelinePageModel.php` | `of` | `fromEntries` | a page from parsed entries, known shares and the newest id |
| `Pass/FollowMatching.php` | `keysOf` (private) | `collectKeys` | the identity, handle and feed identity a follow answers to |
| `Pass/SearchQueryParser.php` | `wordOf` (private) | `parseWord` | a word token as an author filter, an unchecked filter or a term |
| `Pass/SearchQueryParser.php` | `termOf` (private) | `parseTerm` | a text's words as a search term, or null without words |
| `Pass/SearchQueryParser.php` | `allOf` (private) | `combineAll` | several factors as one all-of expression, one as itself, none as null |
| `Pass/SearchQueryParser.php` | `anyOf` (private) | `combineAny` | several alternatives as one any-of expression, one as itself, none as null |
| `Pass/ThreadWalk.php` | `sizeOf` (private) | `countNodes` | a reply node and everything below it |
| `Pass/TimelineAccounts.php` | `heldOutcomeOf` | `findHeldOutcome` | why an account's feeds stay unasked this refresh, or null |
| `Pass/TimelineRound.php` | `ticketOf` (private) | `buildSettlement` | a feed's request ticket, or the outcome it settles at without one |
| `Pass/TimelineRound.php` | `requestOf` (private) | `buildRequestSettlement` | the settlement that sends a read's current request |
| `Pass/TimelineRound.php` | `socialFeedOf` (private) | `requireSocialFeed` | the social feed of a feed id; throws when the private feed lost it |
| `PostCountsSource/PostCountsSourceInterface.php` and `Bluesky/BlueskyAppViewCountsSource.php`, `Mastodon/MastodonOriginCountsSource.php` | `countsOf` | `fetchCounts` | asks the network for a post's public counts; throws when it cannot be counted (3 methods) |
| `ReplyThreadSource/ReplyThreadSourceInterface.php` and `Bluesky/BlueskyReplyThreadSource.php`, `Mastodon/MastodonReplyThreadSource.php` | `selfReplyOf` | `buildSelfReply` | the author's own reply from a timeline's post view, as a thread fold stores it (3 methods) |
| `Support/SearchQueryTokens.php` | `of` | `tokenizeQuery` | a query as its tokens, kind and text |
| `Support/SearchQueryTokens.php` | `wordsOf` | `extractWords` | the lower-cased letter and digit runs of a text |
| `Support/SocialFeedClassifier.php` | `privateAddressOf` | `findPrivateAddress` | the private address of a social feed, or null |
| `Support/SocialFeedUrl.php` | `of` | `buildUrl` | the synthetic URL of an account's private feed |
| `Support/SocialFeedUrl.php` | `addressOf` | `parseAddress` | network, account, kind and remote id of a private feed URL, or null |
| `Support/SocialFeedUrl.php` | `segmentOf` (private) | `readSegment` | the URL segment stored for a feed kind |
| `Support/SocialFeedUrl.php` | `kindOf` (private) | `parseKind` | the feed kind a URL segment names, or null |
| `TimelineSource/TimelineSourceInterface.php` and `Bluesky/BlueskyTimelineSource.php`, `Mastodon/MastodonTimelineSource.php`, `Lemmy/LemmyTimelineSource.php` | `pageOf` | `buildPage` | the timeline page from a feed's accepted answers (4 methods) |

### Share, Completion, Notification, under `backend/src/Service/Social/`

| File | Old | New | What it does |
|---|---|---|---|
| `Share/EntryShareClassifier.php` | `shareKindOf` (private) | `classifyShareKind` | whether an entry shares media, a link, a quote or nothing |
| `Share/Support/OwnProse.php` | `of` | `fromBody` | a post body's own prose, links and metadata lines dropped |
| `Share/Support/ShareTargets.php` | `planOf` | `buildPlan` | the entry's share targets without a card and the posted form of each |
| `Share/Support/SharedLinks.php` | `of` | `fromBodyHtml` | the links a post body shares, bare URLs linked |
| `Share/Support/TitledShareTarget.php` | `of` | `findTarget` | the URL a titled entry shares, or null |
| `Completion/EntryCompletion.php` | `ofKind` (private) | `filterByKind` | the queued rows of one kind |
| `Completion/ShareEnrichmentQueue.php` | `postedQuoteOf` (private) | `findPostedQuote` | the quoted post a link share was posted with, or null |
| `Completion/ShareEnrichmentQueue.php` | `postedCardOf` (private) | `findPostedCard` | the link card a link share was posted with, or null |
| `Completion/Model/KnownSharesModel.php` | `ofBlueskyPosts` | `fromBlueskyPosts` | the known shares of a Bluesky timeline's post views |
| `Completion/Model/KnownSharesModel.php` | `ofMastodonShares` | `fromMastodonShares` | the known shares of Mastodon cards and quotes |
| `Completion/Support/PostedLinkCard.php` | `of` | `resolveCard` | the posted card when it belongs to one of the entry's targets, or null |
| `Notification/NotificationStore.php` | `storedOf` (private) | `findStored` | the stored notifications of the retained remote ids |
| `Notification/NotificationStore.php` | `rowOf` (private) | `buildRow` | a notification row from a fetched notification |
| `Notification/NotificationContextSource/NotificationContextSourceInterface.php` and its three implementations in `Bluesky`, `Mastodon`, `Lemmy` | `contextsOf` | `fetchContexts` | asks the network for the context of fresh notifications; only a rejected credential throws (4 methods) |
| `Notification/Support/Excerpt.php` | `of` | `fromText` | a text trimmed and cut to the excerpt length, or null when empty |
| `Notification/Support/Excerpt.php` | `ofHtml` | `fromHtml` | the same from HTML |

## Review Focus

- **A name that does not say what the body does.** I read about the first eight lines of each body. Where the reviewer reads a body differently, the row comes back to the planner with what the method does; the reviewer picks no name.
- **Four interfaces with implementations in the network modules.** `pageOf`, `countsOf`, `selfReplyOf`, `contextsOf`: interface, every implementation and every double (`FakeReplyThreadSource`, `FakePostCountsSource`, `RecordingContextSource`, `StubNotificationSource` and others in `tests/Support`) together. Private namesakes in the network modules (`BlueskyTimelinePage::pageOf`, `LemmyPostEntries::countsOf`, `MastodonTimelinePage::selfReplyOf`, `BlueskyThreadReplies::selfReplyOf`, `MastodonContextReplies::selfReplyOf`) are not interface methods and wait for their own batch.
- **Wide fan-out:** `SocialFeedUrl::of` and `addressOf`, `PrivateFeedModel::of` and `keyOf`, `SocialFeedClassifier::privateAddressOf` (also in `Http/SubscriptionJson` and both social migrations), `TimelinePageModel::of`, `SearchQueryTokens::of`.
- **`TimelineSources::of()` is an instance call** (`$this->sources->of($account)`), as are `$this->chain->of()`, `$this->counts->of()` and the four `ComposerContexts` methods called from controllers.
- **Collisions.** `ComposerContexts` gets five `build…Context` names; `SocialRepliesSource` and `OwnPostDeletion` get `require…`; `SearchFeedEditor` and `PrivateFeedRules`/`ProfileFeeds` already use `selectRule`; `SocialCounts::fetchCounts` beside `PostCountsSourceInterface::fetchCounts`; three cache classes get `buildKey`.

---

### Task 1: The four interfaces

- [ ] **Step 1:** Rename `pageOf` → `buildPage`, `countsOf` → `fetchCounts`, `selfReplyOf` → `buildSelfReply` and `contextsOf` → `fetchContexts` on the interfaces, every implementation, every double and every caller.
- [ ] **Step 2:** Run `grep -rnE 'function (pageOf|countsOf|selfReplyOf|contextsOf)\(' backend/src backend/tests`. Expected: only the private namesakes named under Review Focus.
- [ ] **Step 3:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green, the same test count as develop.
- [ ] **Step 4:** Commit `refactor(#2360): the social source interfaces say what they do`.

### Task 2: Social/Social

- [ ] **Step 1:** Rename the remaining Social/Social rows and every caller in `backend/src`, `backend/tests` and `backend/migrations`.
- [ ] **Step 2:** Run `grep -rnE 'function\s+&?(of[A-Z]\w*|of|\w+Of)\s*\(' backend/src/Service/Social/Social`. Expected: one line, `PrivateFeeds::subscribeInPlaceOf`.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Social core methods say what they do`.

### Task 3: Share, Completion, Notification

- [ ] **Step 1:** Rename the rows of the second table and every caller.
- [ ] **Step 2:** Run the Task 2 grep over `backend/src/Service/Social/Share backend/src/Service/Social/Completion backend/src/Service/Social/Notification`. Expected: no output.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Share, Completion and Notification methods say what they do`.

### Task 4: Cross-checks and PR

- [ ] **Step 1:** Grep `backend/src` and `backend/tests` for each old name of the tables in quotes. Expected: no hit that refers to a renamed method.
- [ ] **Step 2:** Read the diff once for a renamed `of(` that belongs to a class outside the tables. Expected: none.
- [ ] **Step 3:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 4:** Run `/simplify` on the branch; a finding that changes more than a name goes into the PR text.
- [ ] **Step 5:** Push, open the PR into `develop` with `Refs #2360` and the tables. Merge when CI is green, then comment on #2360 with the areas done and the count that remains.
