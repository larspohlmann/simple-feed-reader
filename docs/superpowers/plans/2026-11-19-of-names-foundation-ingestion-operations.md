# Retire `…Of` Names: Foundation, Ingestion, Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No method in `Service/Foundation`, `Service/Ingestion` or `Service/Operations` is named `of`, `of…` or `…Of`.

**Architecture:** Renames only, 78 methods, the second PR for #2360. Each method gets the dictionary verb for what it does; the tables below are the decision. Callers anywhere in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2360 and the method-verb table in `CLAUDE.md`. The rules of the first plan (`2026-11-19-of-names-identity-sourcing-outbound.md`, Global Constraints and Decisions) apply unchanged and are repeated here in short.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock changes only where it names the old method (`ClippedText`'s "As of()" lines name the new one).
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables (`self::of(...)`), string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No PHPStan rule for the names, no `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class.
- Commits `refactor(#2360): …`. The PR says `Refs #2360`, not `Closes`. No attribution lines.
- A method of these three groups that matches the pattern but is not in a table, a new name that collides in its class, or a name that does not say what the body does: stop and report the row; do not pick a name.

## Decisions

- **A name says what the method really does** (Lars, 2026-11-19: "always look at what the method really does and chose the name based on that"). Every row below was named from the method's full body and its callers, not from its return type or its old name. A dictionary verb is used where its meaning matches; where no row of the dictionary fits, the plain verb for what happens (`clip`, `split`, `strip`, `collect`, `detect`, `pick`, `hash`).
- `of()` on a named constructor or a conversion becomes `from<Source>()` or `to<Target>()`.
- A check that only throws follows the tree's existing `throwIf…` (`SocialNetworkThrottledException::throwIfThrottled`).

## The renames

Paths are under `backend/src/Service/`. The third column is what the method does, which is what the reviewer checks the name against.

### Foundation (23)

| File | Old | New | What it does |
|---|---|---|---|
| `Foundation/Clock/Model/ViewerTimeZoneModel.php` | `of` | `fromIdentifier` | builds the zone from an IANA identifier, UTC when it is missing or unknown |
| `Foundation/Html/PictureSources.php` | `renditionOf` (private) | `findWidestRendition` | the widest usable candidate of a `<source>` the viewport admits, or null |
| `Foundation/Html/PictureSources.php` | `srcsetOf` (private) | `findSrcset` | the first non-empty srcset attribute, or null |
| `Foundation/Json/Model/JsonNodeModel.php` | `of` | `fromValue` | wraps a decoded value; a non-array reads as an empty object |
| `Foundation/Json/Model/JsonNodeModel.php` | `listOf` | `parseNodes` | each item of a decoded list as a node; null when the value is no list |
| `Foundation/Text/MarkdownHtml.php` | `of` | `toHtml` | converts Markdown to HTML |
| `Foundation/Text/StopWords/StopWordsInterface.php`, `BundledStopWords.php`, every double | `wordsOf` | `requireWords` | a language's stop words; throws when the bundle holds no list for it |
| `Foundation/Text/Support/CasefoldedWords.php` | `of` | `splitWords` | casefolds a text and splits it into its words |
| `Foundation/Text/Support/ClippedText.php` | `of` | `clipText` | cuts a text to N characters and appends the marker |
| `Foundation/Text/Support/ClippedText.php` | `ofScrubbed` | `clipScrubbedText` | the same after replacing invalid UTF-8 |
| `Foundation/Text/Support/ClippedText.php` | `trimmedOf` | `clipTrimmedText` | the same with trailing whitespace dropped before the ellipsis |
| `Foundation/Text/Support/EntryExcerpt.php` | `of` | `fromSummaryOrContent` | the plain text of the summary, else of the content, else `''` |
| `Foundation/Text/Support/EntryPlainText.php` | `of` | `fromHtml` | HTML without images as plain text; null when empty or junk |
| `Foundation/Text/Support/LinkFreeText.php` | `of` | `stripLinks` | removes web links from a text |
| `Foundation/Url/EntryUrlHash.php` | `of` | `hashEntryUrl` | hashes the identity URL a guid names, else the entry's URL; null without either |
| `Foundation/Url/Pass/TrackedUrl.php` | `of` | `fromUrl` | splits a URL into address, query and fragment with its host's tracking keys |
| `Foundation/Url/Pass/TrackedUrl.php` | `absoluteHostOf` (private) | `parseAbsoluteHost` | the lower-cased host of an absolute URL, or null |
| `Foundation/Url/Pass/TrackedUrl.php` | `pairsOf` (private) | `splitPairs` | splits a query or fragment into its pairs, each with its key |
| `Foundation/Url/Pass/TrackedUrl.php` | `keyOf` (private) | `extractKey` | a pair's key, decoded, lower-cased, without the double-escaped separator |
| `Foundation/Url/Support/FeedWebsite.php` | `of` | `resolveWebsite` | the feed's published link when usable, else the feed URL's origin |
| `Foundation/Url/Support/TrackingFreeUrl.php` | `of` | `stripTracking` | removes tracking parameters from a URL |
| `Foundation/Url/Support/UrlOrigin.php` | `of` | `parseOrigin` | scheme, host and port of an absolute URL; null for any other |

`StopWordsInterface` and `BundledStopWords` count as two methods.

### Ingestion (25)

| File | Old | New | What it does |
|---|---|---|---|
| `Ingestion/Feed/FeedScheduler.php` | `floorOf` (private) | `pickFloorMinutes` | the shortest poll interval: 15 minutes for a social feed, else the default |
| `Ingestion/Ingest/EntryIngestor.php` | `urlHashesOf` (private) | `collectUrlHashes` | the URL hashes of the candidates that have one |
| `Ingestion/Ingest/StoredEntryRefresher.php` | `guidHashesOf` (private) | `hashGuids` | hashes each entry's guid |
| `Ingestion/Ingest/Factory/IngestedEntryFactory.php` | `textOf` (private) | `buildText` | the entry text with snippet, sanitised content and, on a social feed, language |
| `Ingestion/Ingest/FeedTitle/FeedTitleInterface.php`, `Social/Social/ProfileFeedTitle.php`, every double | `of` | `formatTitle` | the title a feed is stored under, given its channel title |
| `Ingestion/Ingest/Support/EntryMediaAssembler.php` | `mediaOf` | `assembleVisualMedia` | the visual media of a list without a lead image (beside `assemble()`) |
| `Ingestion/Ingest/Support/LemmyCommunityLink.php` | `of` | `parseCommunity` | the community an https community link names, or null |
| `Ingestion/Language/PreviewLanguage.php` | `of` | `detectLanguage` | the stated language, else a confident guess from title and description |
| `Ingestion/Language/SocialEntryLanguage.php` | `of` | `detectLanguage` | reconciles a post's stated language with a guess from its body |
| `Ingestion/Language/StopWordVote.php` | `languageOf` | `detectLanguage` | the language whose stop words a text holds most of; null on a tie |
| `Ingestion/Language/Support/PreviewText.php` | `of` | `fromTitleAndDescription` | joins the title and the cut description |
| `Ingestion/Language/Support/PreviewText.php` | `descriptionOf` | `cutDescription` | the description as plain text, cut to the card's maximum |
| `Ingestion/Parser/FeedFormatParser/Rss2Parser.php` | `channelOf` (private) | `findChannel` | the root's `<channel>` child, or null |
| `Ingestion/Parser/Support/DeclaredChapters.php` | `of` | `parseChapters` | an item's inline chapters, else its linked chapters file |
| `Ingestion/Parser/Support/DeclaredRenditions.php` | `ofWidth` | `fromWidth` | one rendition when the width is known, none otherwise (beside `fromSrcset()`) |
| `Ingestion/Parser/Support/DeclaredTranscripts.php` | `of` | `findBestTranscript` | the best of an item's declared transcripts, or null |
| `Ingestion/Parser/Support/EntryTitle.php` | `of` | `buildHeadline` | a headline from the feed title, else derived from the body, else untitled |
| `Ingestion/Parser/Support/FeedBodyHtml.php` | `of` | `fromBody` | a plain-text body as HTML with pasted line breaks kept; null without a body |
| `Ingestion/Parser/Support/MediaDescription.php` | `descriptionOf` | `findDescription` | the Media RSS `description` child, or null |
| `Ingestion/Parser/Support/PodcastArtwork.php` | `of` | `findArtwork` | the first of the podcast, iTunes and Google Play images, or null |
| `Ingestion/Parser/Support/VisualMedium.php` | `of` (private) | `buildMedium` | an https image or video medium; null for an insecure URL or another kind |
| `Ingestion/Refresh/Model/FeedRefreshResultModel.php` | `of` | `fromOutcome` | a result with that outcome and no entries created |
| `Ingestion/Refresh/Pass/PassTickets.php` | `privateFeedIdsOf` | `collectPrivateFeedIds` | the ids of a pass's social feeds |
| `Ingestion/Refresh/Pass/PassTickets.php` | `laneOf` (private) | `selectLane` | the timeline lane for a private feed, else the feed lane |
| `Ingestion/Scraper/CardTitle.php` | `of` | `findTitle` | a card's heading, else a class-hinted title, else the anchor text; null without any |

### Operations (30)

| File | Old | New | What it does |
|---|---|---|---|
| `Operations/Backup/AccountBackupExporter.php` | `provenanceOf` (private) | `buildProvenance` | a new backup's id, time, source and account |
| `Operations/Backup/AccountBackupExporter.php` | `networkSwitchesOf` (private) | `buildNetworkSwitches` | the included and excluded networks of a user as one model |
| `Operations/Backup/Dto/AccountLine.php` | `of` | `fromAccount` | the line from a user, their settings and network switches |
| `Operations/Backup/Dto/EntryLine.php` | `of` | `fromEntry` | the line from an entry and its companions |
| `Operations/Backup/Dto/EntryLine.php` | `shareKindOf` (private) | `normalizeShareKind` | turns a quote share without a quote into a link share |
| `Operations/Backup/Dto/EntryLine.php` | `quoteOf` (private) | `parseQuote` | the quoted post a line carries, or null |
| `Operations/Backup/Dto/EntryLine.php` | `mediaOf` (private) | `parseMedia` | the complete media a line's fields carry |
| `Operations/Backup/Dto/EntryLine.php` | `authorUrlOf` (private) | `parseAuthorUrl` | an absolute author URL within the length limit, or null |
| `Operations/Backup/Dto/EntryLine.php` | `chaptersUrlOf` (private) | `parseChaptersUrl` | an absolute chapters URL within the length limit, or null |
| `Operations/Backup/Dto/EntryLine.php` | `originOf` (private) | `parseOrigin` | the entry origin a line carries, or null |
| `Operations/Backup/Dto/EntryLine.php` | `linkCardsOf` (private) | `parseLinkCards` | the storable link cards of a line |
| `Operations/Backup/Dto/EntryLine.php` | `linkCardOf` (private) | `parseLinkCard` | one storable link card, or null |
| `Operations/Backup/Dto/EntryShareLine.php` | `of` | `fromEntry` | the line from a shared entry |
| `Operations/Backup/Dto/EntryStateLine.php` | `of` | `fromState` | the line from an entry state |
| `Operations/Backup/Dto/FeedLine.php` | `of` | `fromSubscription` | the line from an exported subscription's feed |
| `Operations/Backup/Dto/FeedLine.php` | `languageOf` (private) | `parseLanguage` | a line's feed language where the format carries one, or null |
| `Operations/Backup/Dto/FooterLine.php` | `of` | `fromCounts` | the footer from the counts, missing ones zero |
| `Operations/Backup/Dto/SavedSearchLine.php` | `of` | `fromSavedSearch` | the line from a saved search |
| `Operations/Backup/Dto/SubscriptionLine.php` | `of` | `fromSubscription` | the line from an exported subscription |
| `Operations/Backup/Dto/SubscriptionTagReference.php` | `of` | `fromSubscriptionTag` | the reference from a subscription's tag |
| `Operations/Backup/Dto/TagLine.php` | `of` | `fromTag` | the line from a tag |
| `Operations/Backup/Model/RestoreResultModel.php` | `ofFoundation` | `fromFoundationCounts` | a result holding only tag, search, feed and subscription counts |
| `Operations/Backup/Model/RestoreResultModel.php` | `ofEntryPart` | `fromEntryCounts` | a result holding only entry and entry-state counts |
| `Operations/Backup/Pass/BackupPartWalk.php` | `lastIdOf` (private) | `pickLastId` | the last entry's id of a batch, else the fallback |
| `Operations/Backup/Pass/RestoreEntryLoader.php` | `cardsOf` (private) | `collectCards` | every link card of the lines, a quote's included |
| `Operations/Backup/Pass/RestoreEntryLoader.php` | `unknownOf` (private) | `filterUnknownLines` | the lines the feed does not hold yet, each guid hash once |
| `Operations/Backup/Support/PrivateFeedRefusal.php` | `ofUrl` | `throwIfPrivateUrl` | throws when the URL is a private feed's |
| `Operations/Backup/Support/PrivateFeedRefusal.php` | `ofFeed` | `throwIfPrivateFeed` | throws when the URL or the source format is a private feed's |
| `Operations/Backup/Support/SavedFeedRefusal.php` | `ofFeed` | `throwIfMixedFeed` | throws when a saved-feed address and a saved-feed format do not go together |
| `Operations/Queue/QueueDepths.php` | `backlogOf` (private) | `countBacklog` | the backlog counts of one queue |

## Review Focus

- **`JsonNodeModel::of()` has callers in almost every social file**, as a call and as `JsonNodeModel::of(...)` / `self::of(...)` callables. Rename by the class-qualified name; a bare search for `::of(` hits about 200 other methods.
- **Interface renames reach other groups.** `FeedTitleInterface::of()` is implemented by `Social/Social/ProfileFeedTitle`; `StopWordsInterface::wordsOf()` has test doubles. A double left with the old name is a fatal error only when that test runs.
- **A name that does not say what the body does.** The fourth column is my reading of each body. Where the reviewer reads the body differently, the row comes back to the planner with what the method does; the reviewer picks no name.
- **A mock that names the method as a string** (`->method('of')`, `->method('wordsOf')`, `->method('languageOf')`).
- **Name collisions.** `TrackedUrl` gets `fromUrl`, `splitPairs`, `extractKey`; `EntryLine` gets `parseOrigin`, `parseMedia`, `fromEntry`; `TrackingFreeUrl` gets `stripTracking` beside `read()` and `query()`; `EntryPlainText` gets `fromHtml`; `EntryMediaAssembler` gets `assembleVisualMedia` beside `assemble()`. Check each target class and what it extends or implements before renaming.
- **Same new name on purpose.** `detectLanguage` on three language classes, `fromEntry` and `fromSubscription` on two DTOs each: these are different classes and the repetition is intended.

---

### Task 1: Foundation

- [ ] **Step 1:** Rename the 23 Foundation methods and every caller in `backend/src` and `backend/tests`.
- [ ] **Step 2:** Run `grep -rnE 'function\s+&?(of[A-Z]\w*|of|\w+Of)\s*\(' backend/src/Service/Foundation`. Expected: no output.
- [ ] **Step 3:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green, the same test count as develop.
- [ ] **Step 4:** Commit `refactor(#2360): Foundation methods say what they do`.

### Task 2: Ingestion

- [ ] **Step 1:** Rename the 25 Ingestion methods, `ProfileFeedTitle::of()` with its interface, and every caller.
- [ ] **Step 2:** Run the Task 1 grep over `backend/src/Service/Ingestion`. Expected: no output.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Ingestion methods say what they do`.

### Task 3: Operations

- [ ] **Step 1:** Rename the 30 Operations methods and every caller.
- [ ] **Step 2:** Run the Task 1 grep over `backend/src/Service/Operations`. Expected: no output.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Operations methods say what they do`.

### Task 4: Cross-checks and PR

- [ ] **Step 1:** Grep `backend/src` and `backend/tests` for each old name of the tables in quotes (`'wordsOf'`, `"languageOf"`, …). Expected: no hit that refers to a renamed method.
- [ ] **Step 2:** Read the diff once for a renamed `of(` that belongs to a class outside the tables. Expected: none.
- [ ] **Step 3:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 4:** Run `/simplify` on the branch; a finding that changes more than a name goes into the PR text.
- [ ] **Step 5:** Push, open the PR into `develop` with `Refs #2360` and the three tables. Merge when CI is green, then comment on #2360 with the groups done and the count that remains.
