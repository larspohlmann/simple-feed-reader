# Verbs for Bare `…For/In/At/Among/OrNull` Names: Ingestion, Library, Repository, Entity, Enum, Dto Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In `Service/Ingestion`, `Service/Library`, `Repository`, `Entity`, `Enum` and `Dto`, no method is a bare noun phrase ending in `For`, `In`, `At`, `Among`, `OrNull` or `OrRefuse`, or a bare `or…`, apart from the kept names.

**Architecture:** Renames only, the second of five PRs for #2400 (after Foundation to Operations; then Content, Curation, Social). The table below is the decision. Callers in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2400 and the method-name paragraph in `CLAUDE.md`.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock or comment changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables, string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Several classes share an old name (`publishedAt`, `placedIn`, `countFor`, `stringOrNull`, `planFor`). Rename per class through the type, never by a tree-wide text replace: the same name in a module outside this batch stays until its own batch.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class, no `CLAUDE.md` edit.
- Commits `refactor(#2400): …`. The PR says `Refs #2400` (the last batch closes it). No attribution lines.
- A living document under `docs/` (not `docs/superpowers/`) that names a renamed method is updated by hand in a separate `docs(#2400)` commit. A search over `docs/` found none for this batch.
- No tool may rewrite text in `docs/`. Before each commit `git status` shows no change under `docs/` other than this plan's own table updates.
- A method of these groups that is a bare name with one of the endings but is in no table and not under Kept names, a new name that collides in its class, or a name that does not say what the body does: stop and report the row to the planner; do not pick a name.

## Decisions

- Every row was named from the method's full body and its callers. A `CLAUDE.md` verb where its meaning matches; otherwise the plain verb the tree already uses for that act. The verbs #2360 settled apply: `build…` assembles, `parse…` interprets a string or decoded array into a value that may be absent, `find…` looks up (null when absent), `resolve…` settles between sources or falls back, `read…` is a plain access with a default, `collect…` gathers from a list, `keepIf…` passes a value through or drops it, `try…` swallows failure.
- In scope is a name that does not start with a verb. `findSubscriptionFor`, `getCreatedAt`, `isDueAt` and the like are not touched.
- **Kept name:** `AiSettingsJson::configurationFor` in `src/Http`. Http mappers name their methods for the shape they return (`one`, `list`, `from`, `configuration`), as ruled in #2360.
- A repository method that runs a query is `find…`; one that returns a `QueryBuilder` or an SQL/DQL fragment is `build…Query` / `build…Condition`. `load…` is for a service method that queries stored state and builds an object from it.
- `SyncStamp::checkedAt()` is a plain property read on an embedded entity value and becomes `getCheckedAt()`.

## The renames

The last column is what the reviewer checks the name against.

### Dto, Entity, Enum (paths under `backend/src/`)

| File | Old | New | What it does |
|---|---|---|---|
| `Dto/Entry/MarkReadRequest.php` | `requiredGroupFor` | `requireGroup` | The request's feed group; throws `ValidationException` when the scope needs one and it is missing or unknown |
| `Dto/Entry/MarkReadRequest.php` | `requiredIdFor` | `requireId` | The request's id; throws `ValidationException` when the scope needs one and it is missing |
| `Dto/Entry/MarkReadRequest.php` | `requiredNetworkFor` | `requireNetwork` | The request's network scope; throws `ValidationException` when the scope needs one and it is missing or unknown |
| `Entity/StoredList.php` | `orNull` | `keepIfNotEmpty` | Passes an encoded list through unless it is empty, then null |
| `Entity/InclusionChange.php` | `switchFor` | `getSwitch` | Plain read of one of four stored tri-state fields, chosen by surface; null means unchanged |
| `Entity/SyncStamp.php` | `checkedAt` | `getCheckedAt` | Returns the stored `checkedAt` property |
| `Enum/TrendingWindow.php` | `minimumOutletsFor` | `computeMinimumOutlets` | The larger of the window's floor and its per-mille share of the active feeds, rounded up |

### Repository (paths under `backend/src/Repository/`)

| File | Old | New | What it does |
|---|---|---|---|
| `DiscussionVoteRepository.php` | `scoresAmong` | `findScores` | Queries the user's vote score per entry id among the given entries |
| `EntryShareDql.php` | `existsFor` | `buildSharedCondition` | Returns the DQL `EXISTS` fragment "the user shared this entry" for an entry alias |
| `EntryShareRepository.php` | `sharedAtFor` | `findSharedAt` | Queries when the user shared an entry, null when not shared |
| `EntryTranscriptRepository.php` and `Service/Library/Search/TranscriptText/TranscriptTextInterface.php` | `searchTextsFor` | `findSearchTexts` | Queries the search text of each filled transcript among the given entries, by entry id |
| `LinkCardRepository.php` | `nextPositionFor` | `computeNextPosition` | Works the next free position out of the cards scheduled for insertion and the stored maximum: one above the highest; runs no query for an unsaved entry |
| `ProfileRunRepository.php` | `latestCompletedFingerprintFor` | `findLatestCompletedFingerprint` | Queries the fingerprint of the user's newest completed profile run, null when none |
| `ReaderAuditRepository.php` | `idOrNull` | `parseId` | Turns a `fetchOne()` result into an int id; null when `fetchOne()` answered `false`; a non-numeric value throws in `DatabaseValue::int` |
| `RecommendationRunHistoryRepository.php` | `historyRowsFor` | `buildHistoryRowsQuery` | Returns a `QueryBuilder` selecting a user's run history columns; runs nothing |
| `RetentionRepository.php` | `unprotectedAmong` | `findUnprotectedIds` | Queries which of the given entry ids are not protected from retention |
| `SavedPostRepository.php` | `copiesIn` | `findCopies` | Queries, per original entry id, the id of its copy in the saved feed |
| `SavedPostRepository.php` | `flaggedIn` | `findFlagged` | Queries the feed's entries the user favourited, kept or shared: URL hash by entry id, sorted by id |
| `SavedPostRepository.php` | `heldIn` | `findHeld` | Queries the saved feed's entries with the given guid hashes: id and URL hash by guid hash |
| `SavedSearchNetworkInclusionRepository.php` | `includedNetworksFor` | `findIncludedNetworks` | Queries the networks a user includes in saved searches |
| `ShareEnrichmentRepository.php` | `pendingEntryIdsAmong` | `findPendingEntryIds` | Queries which of the given entries still wait for share enrichment |
| `SharedLinkResolutionRepository.php` | `pendingAt` | `findDuePending` | Queries the queued link resolutions due at a time, oldest first, limited |
| `SocialLikeRepository.php` | `likedEntryIdsAmong` | `findLikedEntryIds` | Queries which of the given entries the user liked; a set keyed by entry id |
| `SubscribedEntryCountRepository.php` | `countFor` | `countSubscribedEntries` | Counts the entries of every feed the user subscribes to |
| `TrendingPlacementSql.php` | `placedIn` | `buildPlacedCondition` | Returns the SQL `EXISTS` fragment "this member row is placed in the window" |
| `TrendingStoryHistoryRepository.php` | `placedIn` | `findPlacedStories` | Queries the story id of each entry the reader's window places |

### Ingestion (paths under `backend/src/Service/Ingestion/`)

| File | Old | New | What it does |
|---|---|---|---|
| `Ingest/EntryIngestor.php` | `deduplicatorFor` | `loadDeduplicator` | Queries the feed's existing guid and URL hashes for the incoming entries and builds the deduplicator from them |
| `Language/FeedLanguageResolver.php` | `languageFor` | `resolveLanguage` | The feed's own language when set or not applicable, else the declared language, else a guess from the parsed text |
| `Language/LanguageGuess.php` | `bestFor` | `detectLanguage` | The detector's closest language for a text, null when the text has no letter or the language is not one of ours |
| `Language/LanguageGuess.php` | `confidentFor` | `detectConfidentLanguage` | `detectLanguage` on a text long enough to read reliably, null on a shorter one |
| `Parser/Factory/FeedParserFactory.php` | `parserFor` | `selectParser` | The first registered parser that supports the root element; throws `FeedParseException` when none does |
| `Parser/ItemImageExtractor.php` | `mediaCandidatesIn` | `collectMediaCandidates` | Gathers the Media RSS content and thumbnail children of an element that carry a URL and are images |
| `Parser/ItemMediaExtractor.php` | `mediaNodesIn` | `collectMediaNodes` | Gathers the Media RSS content and thumbnail children of a group that carry a URL |
| `Parser/ItemMediaExtractor.php` | `posterIn` | `findPoster` | The URL of the group's `media:thumbnail`, null when there is none |
| `Parser/Support/UndeclaredPrefixes.php` | `declaredIn` | `addMissingDeclarations` | Returns the XML with the missing namespace declarations added to its root start tag; unchanged when none is missing |
| `Parser/WordPressJsonParser.php` | `publishedAt` | `parsePublishedAt` | Reads `date_gmt` as a UTC time; null when missing or unreadable |
| `Parser/WordPressJsonParser.php` | `stringOrNull` | `parseString` | A trimmed non-empty string, or an int as a string; null for anything else |
| `Refresh/Pass/PassLane/PassLaneInterface.php` and every implementation | `ticketsFor` | `issueTickets` | Hands out the request tickets a feed needs in this pass; handing out may settle the feed without a ticket and record that (`TimelineLane` via `TimelineRound::ticketFor`, which stays until the Social batch) |
| `Refresh/RefreshRunStore.php` | `keyFor` | `buildKey` | Cache key from the user id and the target's run key; throws `LogicException` for a request without a user |
| `Refresh/UserRefreshScope.php` | `requestFor` | `resolveRequest` | Settles the refresh target among one feed, one of the user's tags and every feed, checking that the feed or tag is the user's (throws when not) |
| `Refresh/UserRefreshScope.php` | `requestForNetwork` | `buildNetworkRequest` | Assembles the refresh request for one social network's feeds |
| `Refresh/UserRefreshScope.php` | `requestForFeedGroup` | `buildFeedGroupRequest` | Assembles the refresh request for a feed group |
| `Refresh/UserRefreshScope.php` | `requestForNeverFetched` | `buildNeverFetchedRequest` | Assembles the refresh request for the feeds never fetched |
| `Refresh/UserRefreshScope.php` | `forFeed` (private) | `buildFeedRequest` | Assembles the feed request; throws `RecordNotFoundException` when the user does not subscribe to the feed |
| `Refresh/TrackedRefreshRunner.php` | `handledIn` | `computeHandled` | Adds up the report's fetched, not modified, failed and throttled feeds (arithmetic over the in-memory report) |
| `Scraper/Pass/CardFields.php` | `publishedAt` | `parsePublishedAt` | Parses the `datetime` of the container's `<time>` element; null when there is none or it does not parse |

### Library (paths under `backend/src/Service/Library/`)

| File | Old | New | What it does |
|---|---|---|---|
| `Reading/MarkReadService.php` | `subscriptionsIn` | `resolveSubscriptions` | Turns a read scope into the subscriptions it covers, by scope kind; throws `RecordNotFoundException` when the scope's feed or tag is not the user's |
| `SavedPosts/SavedPostRescue.php` | `planFor` | `loadPlan` | Queries the originals and the copies already held, and builds the copy plan from them |
| `SavedPosts/Support/SavedFeedUrl.php` | `networkIn` | `parseNetworkPart` | Parses the network out of the second of the URL's already split parts; null when the part is missing or no network. `parseNetwork` (whole URL) calls it after `parseParts` |
| `Search/Index/SearchIndexReader/MeilisearchIndex.php` | `highlightedWordsIn` | `extractHighlightedWords` | Pulls every text between the highlight markers out of a formatted field |
| `Search/Index/SearchIndexReader/MeilisearchIndex.php` | `queryStringFor` | `buildQueryString` | Assembles the Meilisearch `q` string from the terms: quoted as a phrase, quoted per word, or plain |
| `Search/Index/SearchIndexReader/MeilisearchIndex.php` | `filterFor` | `buildFilter` | Assembles the Meilisearch filter expression from feed ids, entry ids and the cursor, joined with `AND` |
| `SubscriptionHistory/Pass/DaySources.php` | `countFor` | `resolveDayCount` | The stored count of a subscription's day when one is stored, else the live one |
| `Subscription/FeedTagMove.php` | `ownedTagOrNull` | `requireOwnedTagIfSet` | Null for a null id (no tag, passes through); else the user's tag with that id, throwing `InvalidSelectionException` when it is not theirs |
| `Tag/TagEditor.php` | `parentFor` | `resolveParent` | Settles an edited tag's parent from an optional id: none for null, the unchanged current parent when the id is the current one, else the requested tag after the ownership, cycle and depth checks, which throw `InvalidTagParentException` |
| `Tag/TagEditor.php` | `parentForNewTag` (private) | `resolveNewTagParent` | Settles a new tag's parent from an optional id: none for null, else the requested tag after the ownership and depth checks, which throw |

## Review Focus

- `publishedAt` is also a property and array key all over the tree (103 files): only the two private methods in `WordPressJsonParser` and `CardFields` are renamed. Rename through the type, never by text.
- `SyncStamp::checkedAt()` shares its name with the DQL path `syncStamp.checkedAt` and the property: DQL strings and the property stay, only method calls follow.
- `findSearchTexts` is declared on the consumer-owned `TranscriptTextInterface`; the repository, the interface and every test double follow together. Check the other repository rows for an interface the same way.
- `PassLaneInterface::ticketsFor` has two implementations, `FeedLane` and `TimelineLane`; `TimelineLane` calls `ticketFor` on the round, which is a different method and stays until the Social batch.
- `planFor` also exists in `PrivateFeedSyncApplier` (Social) and `keyFor`, `candidateFor` in other groups: those wait for their own batch.
- String method names in mocks (`->method('flaggedIn')`, `->method('heldIn')`): search `backend/tests` for the bare names.

---

### Task 1: Dto, Entity, Enum and Repository

- [ ] **Step 1:** Rename the rows of the first two tables, interfaces and test doubles included, and every caller.
- [ ] **Step 2:** Run `composer cs:fix && composer check && composer md && php bin/phpunit` in `backend/`. Expected: all green.
- [ ] **Step 3:** Commit `refactor(#2400): repository and entity methods start with their verb`.

### Task 2: Ingestion

- [ ] **Step 1:** Rename the Ingestion rows, the `PassLaneInterface` implementations and doubles included.
- [ ] **Step 2:** Run the same four commands. Expected: all green.
- [ ] **Step 3:** Commit `refactor(#2400): Ingestion methods start with their verb`.

### Task 3: Library

- [ ] **Step 1:** Rename the Library rows and every caller.
- [ ] **Step 2:** Run `grep -rnoE 'function\s+&?\w+(OrNull|OrRefuse|For|In|At|Among)\s*\(' backend/src/Service/{Ingestion,Library} backend/src/{Repository,Entity,Enum,Dto}`. Expected: only names that start with a verb.
- [ ] **Step 3:** Run the same four commands. Expected: all green.
- [ ] **Step 4:** Commit `refactor(#2400): Library methods start with their verb`.

### Task 4: Gates and PR

- [ ] **Step 1:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green; escaped mutants only on lines whose logic this PR did not change, listed in the PR.
- [ ] **Step 2:** Open the PR into `develop` with the rename tables, the kept name and `Refs #2400`; merge when green.
