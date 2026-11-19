# Retire `…Of` Names: Library, Persistence, Commands Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No method in `Service/Library`, `src/Repository`, `src/Entity`, `src/Enum`, `src/Doctrine`, `src/Command` or `src/EventListener` is named `of`, `of…` or `…Of`, except the one kept name under Decisions.

**Architecture:** Renames only, 102 methods, the third PR for #2360. Each method gets the dictionary verb for what it does; the tables below are the decision. Callers anywhere in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2360 and the method-verb table in `CLAUDE.md`. The rules of the first plan (`2026-11-19-of-names-identity-sourcing-outbound.md`, Global Constraints and Decisions) apply unchanged and are repeated here in short.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables (`self::of(...)`), string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No PHPStan rule for the names, no `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class.
- Commits `refactor(#2360): …`. The PR says `Refs #2360`, not `Closes`. No attribution lines.
- A method of these areas that matches the pattern but is not in a table, a new name that collides in its class, or a name that does not say what the body does: stop and report the row; do not pick a name.

## Decisions

- **A name says what the method really does** (Lars, 2026-11-19: "always look at what the method really does and chose the name based on that"). Every row below was named from the method's full body and its callers, not from its return type or its old name. A dictionary verb is used where its meaning matches; where no row of the dictionary fits, the plain verb for what happens (`clip`, `split`, `strip`, `collect`, `detect`, `pick`, `hash`).
- `of()` on a named constructor or a conversion becomes `from<Source>()` or `to<Target>()`.
- A repository query is `find…` and a repository count is `count…`, as the repositories already name them; find-or-create is `ensure…`.
- **Kept:** `RecommendationRun::guardStatusOneOf()` ("one of" is the phrase, and `guard…` says what it does) and nothing else.
- **A repository method declared on a consumer-owned interface** is renamed together with that interface, wherever in `src/Service` it lives; a later batch finds it already done.

## The renames

The last column is what the method does, which is what the reviewer checks the name against.

### Library (41), under `backend/src/Service/Library/`

| File | Old | New | What it does |
|---|---|---|---|
| `EntryList/ConnectedAccountStates.php` | `accountsByNetworkOf` (private) | `loadAccountsByNetwork` | loads the reader's accounts grouped by network, only when a post can use them |
| `EntryList/ConnectedAccountStates.php` | `votesOf` (private) | `loadVotes` | the stored vote of each Lemmy post, none where nothing is stored |
| `EntryList/ConnectedAccountStates.php` | `lemmyPostIdsOf` (private) | `collectLemmyPostIds` | the ids of the entries that are Lemmy posts |
| `EntryList/ConnectedAccountStates.php` | `socialStatesOf` (private) | `buildSocialStates` | each post's row state: liked, likeable, own |
| `EntryList/ConnectedAccountStates.php` | `postAddressesOf` (private) | `collectPostAddresses` | the post address of each entry that has one |
| `EntryList/ConnectedAccountStates.php` | `lemmyOf` (private) | `buildLemmyRow` | the Lemmy part of a row; null without a post and a vote |
| `EntryList/EntryListRowEnricher.php` | `savedEntryIdsOf` (private) | `collectSavedEntryIds` | the ids of the entries in a saved feed |
| `EntryList/EntryListRowEnricher.php` | `discussionOf` (private) | `buildDiscussionRow` | the discussion part of a row for a platform thread, else null |
| `EntryList/EntryListRowEnricher.php` | `topLevelEntryIdsOf` (private) | `collectTopLevelEntryIds` | the entry id of each row |
| `Opml/OpmlImporter.php` | `sourceFormatOf` (private) | `resolveSourceFormat` | the outline's declared format when valid, else Mixcloud by URL, else XML |
| `Opml/Pass/OpmlTagTree.php` | `childrenOf` | `findChildren` | the tags whose parent is the given tag |
| `Opml/Pass/OpmlTagTree.php` | `feedsOf` | `findFeeds` | the exported subscriptions filed under a tag |
| `SavedPosts/SavedFeeds.php` | `of` | `ensureSubscription` | the reader's saved-feed subscription, created and stored when missing |
| `SavedPosts/SavedPostRescue.php` | `copiesOf` (private) | `copyEntries` | copies the planned entries, plain and renamed, and returns the id map |
| `SavedPosts/SavedStateMover.php` | `hiddenSinceOf` (private) | `readHiddenSince` | the hidden-at instant of a hidden state, else null |
| `SavedPosts/Pass/SavedCopyPlan.php` | `renamedGuidOf` (private) | `buildRenamedGuid` | the guid with the feed id appended |
| `SavedPosts/Support/SavedFeedUrl.php` | `of` | `buildUrl` | the saved-feed URL of a user and network |
| `SavedPosts/Support/SavedFeedUrl.php` | `networkOf` | `parseNetwork` | the network a saved-feed URL names, or null |
| `SavedPosts/Support/SavedFeedUrl.php` | `partsOf` (private) | `parseParts` | the pattern's parts of a saved-feed URL, or null |
| `SavedPosts/Support/SavedOrigin.php` | `posterOf` | `buildPoster` | the leaving feed as an author, for a networked public feed only |
| `SavedPosts/Support/SavedOrigin.php` | `originOf` | `buildOrigin` | the leaving feed as an origin, for a feed without a network only |
| `SavedPosts/Support/SavedOrigin.php` | `nameOf` (private) | `cutName` | the subscription's display title cut to the name length |
| `Search/Index/SearchIndexReader/MeilisearchIndex.php` | `entryIdsOf` (private) | `collectEntryIds` | the integer ids of the hits |
| `Search/Index/SearchIndexReader/MeilisearchIndex.php` | `matchedWordsOf` (private) | `collectMatchedWords` | the distinct highlighted words of the hits' titles and summaries |
| `Search/Index/SearchIndexReader/MeilisearchIndex.php` | `documentOf` (private) | `buildDocument` | the index document of an entry |
| `Search/Membership/SavedSearchMembershipSweep.php` | `idsOf` (private) | `collectIds` | the id of each saved search |
| `Search/Model/SavedSearchTermModel.php` | `idsOf` | `collectIds` | the id of each term model |
| `Search/Support/SavedSearchTerms.php` | `of` | `fromSavedSearch` | the search terms of a saved search |
| `Search/Support/SavedSearchTerms.php` | `termOf` | `buildTerm` | a saved search's id with its terms |
| `Subscription/SubscriptionService.php` | `feedIdsOf` (private) | `collectFeedIds` | the distinct feed ids of the subscriptions |
| `SubscriptionHistory/RetentionHorizon.php` | `asOf` | `buildHorizon` | the oldest day kept for entries and for social posts, counted back from a day |
| `SubscriptionHistory/SubscriptionHistoryRecorder.php` | `missingDaysOf` (private) | `collectMissingDays` | per subscription, its live days among the dates |
| `SubscriptionHistory/SubscriptionHistoryRecorder.php` | `chunksOf` (private) | `chunkDays` | yields the days in flush-sized chunks |
| `SubscriptionHistory/Model/DayCountsModel.php` | `of` | `readCount` | a subscription's count on a day, zero counts when none is held |
| `SubscriptionHistory/Model/DayWindowModel.php` | `averagedWeekOf` | `sliceAveragedWeek` | the seven days before today out of a series |
| `SubscriptionHistory/Model/TrackedSubscriptionModel.php` | `idsOf` | `collectIds` | the subscription id of each model |
| `SubscriptionHistory/Support/UtcDay.php` | `of` | `fromInstant` | an instant's day as `Y-m-d` |
| `Tag/TagEditor.php` | `treeOf` (private) | `loadTree` | the user's tag tree from the stored parent ids |
| `Tag/TagOrdering.php` | `siblingsOf` (private) | `requireSiblings` | the owned tags sharing the first listed tag's parent; throws when that tag is not owned |
| `Tag/Pass/TagTree.php` | `depthOf` | `computeDepth` | how many ancestors a tag has |
| `Tag/Pass/TagTree.php` | `heightOf` | `computeHeight` | how many levels lie below a tag |

### Repository (43), under `backend/src/Repository/`

| File | Old | New | What it does |
|---|---|---|---|
| `EntryListRowDiscussion.php` | `storedOf` | `fromDiscussion` | the row part from a stored discussion |
| `EntryListSort.php` | `instantOf` | `requireInstant` | the row's instant for this sort; throws when the row lacks it |
| `KnownSocialCollectionRepository.php` | `keysOf` | `findKeys` | the keys of an account's known collections |
| `PostRemovalRepository.php` | `copiesOf` | `findCopies` | the entry and each copy of its post outside a saved feed |
| `PrivateFeedSyncChangeRepository.php` | `recentOf` | `findRecentChanges` | the accounts' sync changes, newest first |
| `RecommendationRunLogRepository.php` | `listRowsOf` (private) | `findListRows` | the log rows of one run for the list |
| `RecommendationRunLogRepository.php` | `streamingTextOf` (private) | `findStreamingTexts` | the response text of a run's calls still streaming |
| `RecommendationRunRepository.php` | `statusOf` | `findStatus` | a run's stored status, read as a scalar; null without the run |
| `RetentionRepository.php` | `idsOf` (private) | `readIds` | runs a query and returns its single id column; none without a query |
| `SavedPostRepository.php` | `keptTwinsOf` | `findKeptTwins` | the entries sharing a URL hash in feeds the reader keeps |
| `SavedPostRepository.php` | `originalsOf` | `findOriginals` | the guid, hashes and feed of each entry id |
| `SearchedColumns.php` | `anyOf` | `buildAnyColumnMatch` | the DQL that matches title, summary or transcript |
| `ShareEnrichmentBacklogRepository.php` | `backlogOf` | `countBacklog` | how many enrichments of a kind wait, and since when |
| `ShareEnrichmentRepository.php` | `rowsOf` (private) | `readRows` | runs a query and returns its enrichments |
| `ShareEnrichmentRepository.php` | `idsOf` (private) | `collectIds` | the id of each enrichment |
| `SharedLinkRepository.php` | `rowOf` (private) | `buildRow` | a new link's values in insert order |
| `SocialFeedRepository.php` | `findAllOf` | `findAllForAccount` | an account's social feeds by id |
| `SocialFeedRepository.php` | `subscriptionsOf` | `findSubscriptions` | the account owner's subscriptions to its feeds |
| `SocialFeedRepository.php` | `subscribedCollectionsOf` | `findSubscribedCollections` | the account's subscribed collections of every kind |
| `SocialNotificationPollRepository.php` | `stampOf` | `ensureStamp` | the account's poll stamp, created and persisted when missing |
| `SubscriptionActivityRepository.php` | `plainSubscriptionsOf` | `findPlainSubscriptions` | a user's tracked subscriptions to feeds without a network |
| `SubscriptionActivityRepository.php` | `arrivalCountsOf` (private) | `countArrivals` | entries created per feed and day since an instant |
| `SubscriptionActivityRepository.php` | `readCountsOf` (private) | `countReads` | entries viewed per subscription and day since an instant |
| `SubscriptionDisplayTitle.php` | `ofSubscription` | `fromSubscription` | the display title of a subscription (beside `from()`) |
| `SubscriptionDisplayTitle.php` | `searchQueryOf` (private) | `parseSearchQuery` | the query a search feed's URL names, or null |
| `TagTreeRepository.php` | `lockTagsOf` | `lockTags` | locks the user's row so tag placements run one at a time |
| `TitledEntry.php` | `of` | `fromEntry` | an entry with its subscription's display title |
| `TrendingEntryRepository.php` | `headlinesOf` | `findHeadlines` | feed title and headline by entry id |
| `TrendingEntryRepository.php` | `linkTitlesOf` | `findLinkTitles` | the non-blank titles of shared links by id |
| `TrendingEntryRepository.php` | `modelOf` (private) | `buildEntry` | a window entry from a result row |
| `TrendingEntryRepository.php` | `cardOf` (private) | `buildCard` | a row's link card; null when a card column is missing |
| `TrendingStoryEntryListRepository.php` | `headingOf` | `findHeading` | a story's title and synopsis, or null |
| `TrendingStoryEntryListRepository.php` | `tallyOf` | `countMembers` | a story's member and unread counts for the reader |
| `TrendingStoryHistoryRepository.php` | `followsOf` (private) | `findFollows` | a story's follows by user id |
| `TrendingStoryHistoryRepository.php` | `fallbackColumnsOf` (private) | `buildFallbackColumns` | the two fallback-title column values |
| `TrendingStoryHistoryRepository.php` | `earliestJoinsOf` (private) | `findEarliestJoins` | the earliest stored join of each joining entry |
| `TrendingStoryHistoryRepository.php` | `joinedAtsOf` (private) | `buildJoinedAts` | each member's join time: its earliest join, else the build time |
| `TrendingStoryHistoryRepository.php` | `ofReader` (private) | `buildReaderCondition` | the SQL condition that keeps a read inside one reader's stories |
| `TrendingStoryRepository.php` | `headingsOf` | `findHeadings` | stored title and synopsis by story id |
| `TrendingStoryRepository.php` | `memberHeadlinesOf` | `findMemberHeadlines` | a story's outlet headlines, else its card headlines |
| `TrendingStoryRepository.php` | `titleDueOf` | `findStoriesDueForTitle` | the ids of a user's stories that need a title attempt |
| `TrendingStoryRepository.php` | `outletHeadlinesOf` (private) | `findOutletHeadlines` | the newest headline of each outlet in a story |
| `TrendingStoryRepository.php` | `cardHeadlinesOf` (private) | `findCardHeadlines` | a story's shared cards, newest share first |

### Entity, Enum, Doctrine, Command, EventListener (19, one kept), under `backend/src/`

| File | Old | New | What it does |
|---|---|---|---|
| `Entity/Discussion.php` | `of` | `fromPageAndFeed` | a discussion from its page and comments feed, the load dropped when nothing can load |
| `Entity/DiscussionCounts.php` | `of` | `fromScoreAndComments` | the counts from a score and a comment count |
| `Entity/EntryAttachment.php` | `extensionOf` | `extractExtension` | the lower-cased file extension of a URL's path |
| `Entity/EntryGuid.php` | `hashOf` | `hashGuid` | the SHA-256 of a guid |
| `Entity/EntryImage.php` | `hashOf` | `hashUrl` | the SHA-256 of an image URL |
| `Entity/ImageRendition.php` | `widestOf` | `pickWidestWidth` | the largest width among renditions |
| `Entity/PostCommunity.php` | `of` | `fromIdentityAndUrl` | a community with its identity lower-cased |
| `Entity/RecommendationRun.php` | `guardStatusOneOf` (private) | kept | throws unless the run's status is one of the allowed |
| `Enum/RecommendationEngineKind.php` | `of` | `fromProtocol` | scoring for a model with a scoring protocol, else LLM |
| `Enum/SocialNetwork.php` | `valuesOf` | `toValues` | the string value of each network |
| `Enum/TrendingWindow.php` | `leavesOutTheStoriesOf` | `excludedStoryWindows` | the windows whose stories this window leaves out |
| `Enum/VoteScore.php` | `ofNetworkValue` | `fromNetworkValue` | a network's vote number as a score, none when unknown |
| `Doctrine/SearchIndexQuery.php` | `allOf` | `buildConjunction` | quotes each piece and joins them with AND |
| `Command/ReaderAuditReportCommand.php` | `rowsOf` (private) | `toRows` | key and count pairs as table rows |
| `Command/TrendingExplainCommand.php` | `headlinesOf` (private) | `loadHeadlines` | loads the printed entries and formats a headline for each |
| `Command/TrendingExplainCommand.php` | `headlineOf` (private) | `formatHeadline` | `[feed] title` for an entry |
| `Command/TrendingExplainCommand.php` | `languagesOf` (private) | `formatLanguages` | the per-language entry counts as one line |
| `Command/TrendingExplainCommand.php` | `summaryOf` (private) | `formatSummary` | the explanation's totals as one line |
| `EventListener/DeferredMailFlushListener.php` | `recipientOf` (private) | `formatRecipients` | the recipients' addresses joined, `unknown` without any |

## Review Focus

- **A name that does not say what the body does.** The last column is my reading of each body; for the repository queries I read the first lines and the docblock, not every clause. Where the reviewer reads a body differently, the row comes back to the planner with what the method does; the reviewer picks no name.
- **Repository methods behind consumer-owned interfaces.** Several public repository methods are declared on an interface in a service module and doubled in tests by that interface. Declaration, implementation and every double are renamed together.
- **`EntryGuid::hashOf()` and `SocialNetwork::valuesOf()` are called across the whole tree,** also as first-class callables. Rename by the class-qualified name.
- **A mock that names the method as a string**, and DQL or SQL strings that happen to contain an old name: only method names change.
- **Name collisions.** Repositories inherit Doctrine's `find…` methods (`findAllForAccount`, `findStatus`, `findHeadings` must not hide one); `SavedFeedUrl` gets `buildUrl`, `parseNetwork`, `parseParts`; `TrendingEntryRepository` gets `buildEntry`, `buildCard`.

---

### Task 1: Library

- [ ] **Step 1:** Rename the 41 Library methods and every caller in `backend/src` and `backend/tests`.
- [ ] **Step 2:** Run `grep -rnE 'function\s+&?(of[A-Z]\w*|of|\w+Of)\s*\(' backend/src/Service/Library`. Expected: no output.
- [ ] **Step 3:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green, the same test count as develop.
- [ ] **Step 4:** Commit `refactor(#2360): Library methods say what they do`.

### Task 2: Repository

- [ ] **Step 1:** Rename the 43 Repository methods, the interface declarations they implement, every double and every caller.
- [ ] **Step 2:** Run the Task 1 grep over `backend/src/Repository`. Expected: no output.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Repository methods say what they do`.

### Task 3: Entity, Enum, Doctrine, Command, EventListener

- [ ] **Step 1:** Rename the 18 methods and every caller.
- [ ] **Step 2:** Run the Task 1 grep over `backend/src/Entity backend/src/Enum backend/src/Doctrine backend/src/Command backend/src/EventListener`. Expected: one line, `RecommendationRun::guardStatusOneOf`.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): entity, enum and command methods say what they do`.

### Task 4: Cross-checks and PR

- [ ] **Step 1:** Grep `backend/src` and `backend/tests` for each old name of the tables in quotes. Expected: no hit that refers to a renamed method.
- [ ] **Step 2:** Read the diff once for a renamed `of(` that belongs to a class outside the tables. Expected: none.
- [ ] **Step 3:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 4:** Run `/simplify` on the branch; a finding that changes more than a name goes into the PR text.
- [ ] **Step 5:** Push, open the PR into `develop` with `Refs #2360` and the three tables. Merge when CI is green, then comment on #2360 with the areas done and the count that remains.
