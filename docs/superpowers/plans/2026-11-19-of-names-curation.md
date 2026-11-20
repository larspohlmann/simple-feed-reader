# Retire `…Of` Names: Curation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No method in `Service/Curation` is named `of`, `of…` or `…Of`, and two `StoryTitleParser` helpers say what they do.

**Architecture:** Renames only, the fifth PR for #2360. Each method gets the dictionary verb for what it does; the tables below are the decision. Callers anywhere in `backend/src`, `backend/tests` and `backend/migrations` follow.

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
- **Two rows outside the pattern** (Lars, 2026-11-19, on PR #2385): `StoryTitleParser::parseCollapsed()` and `parseSentencesThatFit()` parse nothing.
- An interface method is renamed with every implementation: `GermanLexiconInterface::entryOf()` (`BundledGermanLexicon`, `GermanLexiconModel`, doubles).

## The renames

The last column is what the method does, which is what the reviewer checks the name against.

### Curation, under `backend/src/Service/Curation/`

| File | Old | New | What it does |
|---|---|---|---|
| `Ai/ModelCatalog/OpenAiCompatibleCatalog.php` | `scoringProtocolOf` (private) | `findScoringProtocol` | the scoring protocol a model's declared outputs name, or null |
| `Ai/Pass/ModelListing.php` | `answerOrRecordedFailureOf` (private) | `listModelsOrRecordFailure` | a catalog's models by id; on failure records it and returns none |
| `Ai/Support/AiReadiness.php` | `of` | `isReady` | whether a connection exists and has a model |
| `Ai/Support/ProviderHost.php` | `of` | `parseHost` | the host of a connection's base URL, or null |
| `Ai/Support/ReportedCost.php` | `nanoCreditsOf` | `parseNanoCredits` | a reported cost as integer nano-credits; null when unpriced or unusable |
| `Recommendation/Engine/RecommendationEngineResolver.php` | `engineOf` | `selectEngine` | the engine wired for a kind |
| `Recommendation/Engine/Model/RecommendationEngineCapabilitiesModel.php` | `of` | `fromKind` | the capabilities of an engine kind |
| `Recommendation/Feed/RecommendationFeedPager.php` | `cursorOf` (private) | `parseCursor` | the query's cursor decoded; null when missing or garbled |
| `Recommendation/Feed/Model/MonthWindowModel.php` | `of` | `fromMonth` | a calendar month as a UTC window in the viewer's zone; throws on a malformed month |
| `Recommendation/Llm/Completion/CompletionBodyDecoder.php` | `contentOf` (private) | `parseContent` | the answer text of a choice, or null |
| `Recommendation/Llm/Completion/CompletionBodyDecoder.php` | `reasoningOf` (private) | `parseReasoning` | the answer under either reasoning key, or null |
| `Recommendation/Llm/Completion/CompletionBodyDecoder.php` | `answerOf` (private) | `parseAnswer` | the `delta` or `message` object of a choice, or null |
| `Recommendation/Llm/Completion/CompletionBodyDecoder.php` | `finishReasonOf` (private) | `parseFinishReason` | a choice's finish reason, or null |
| `Recommendation/Llm/Completion/CompletionBodyDecoder.php` | `detailsOf` (private) | `readDetails` | a detail object of the usage report, empty when not sent |
| `Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClient.php` | `transportFailureOf` (private) | `buildTransportFailure` | the exception a transport cut stands for: the error body's, a runaway, or unreachable |
| `Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClient.php` | `contentOf` (private) | `requireContent` | the reply's content, else its reasoning; throws without either |
| `Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClient.php` | `quotableAnswerOf` (private) | `cutQuotableAnswer` | the start of a spoiled reply, cut to what a retry may quote |
| `Recommendation/Llm/Prompt/ModelReplyJsonDecoder.php` | `completeItemsOf` | `parseCompleteItems` | the complete objects of a key's array in a reply cut off mid-array |
| `Recommendation/Llm/Prompt/Model/OutputLimitModel.php` | `of` | `fromConnection` | a connection's reasoning preference and output token limit |
| `Recommendation/Llm/Run/Support/ConsolidationShortlist.php` | `of` | `fromRanked` | the best entries that fit the consolidation call |
| `Recommendation/Llm/Run/Support/RenderedCompletionRequest.php` | `of` | `fromRequest` | a request's model and messages as pretty JSON |
| `Recommendation/Pool/ArticleLines.php` | `of` | `buildLines` | the article line of each titled entry, with the story link of a post |
| `Recommendation/Pool/Model/ArticleLineModel.php` | `of` | `fromTitledEntry` | one entry's article line |
| `Recommendation/Profile/ProfileRunOpening.php` | `modelOf` (private) | `requireModel` | the connection's model; throws when it has none |
| `Recommendation/Profile/Support/ProfileInputFingerprint.php` | `of` | `hashInputs` | the SHA-256 over the profile inputs, caps and connection |
| `Recommendation/Run/Support/OneWinnerPerArticle.php` | `of` | `filterWinners` | the ranked winners with one kept per article |
| `Recommendation/Run/Support/ProviderFailedMessage.php` | `of` | `formatMessage` | the message for a provider failure |
| `Recommendation/Scoring/ScoringBatchWave.php` | `requestsOf` (private) | `collectRequests` | the request of each batch call |
| `Recommendation/Scoring/ScoringBatchWave.php` | `idsOf` (private) | `collectEntryIds` | the entry ids of a batch in snapshot order |
| `Recommendation/Scoring/ScoringHttpTransport.php` | `outcomeOf` (private) | `buildOutcome` | a response as a scoring outcome: refused key, retryable, failed or scored |
| `Recommendation/Scoring/ScoringProtocolResolver.php` | `protocolOf` | `selectProtocol` | the implementation wired for a scoring protocol |
| `Recommendation/Scoring/ScoringRecommendationEngine.php` | `waveOf` (private) | `buildWave` | a scoring wave for the tick's batches; throws without a profile |
| `Recommendation/Scoring/Pass/ResponseWave.php` | `positionOf` | `readPosition` | the position a response was sent at |
| `Recommendation/Scoring/Support/FittingPrefix.php` | `of` | `cutToFit` | the longest prefix of a text the check accepts |
| `Recommendation/Scoring/Support/QuestionId.php` | `of` | `fromEntryId` | the question id of an entry id |
| `Recommendation/Scoring/Support/QuestionId.php` | `entryIdOf` | `parseEntryId` | the entry id a question id names, or null |
| `Recommendation/Scoring/Support/RerankReplyDecoder.php` | `entryIdOf` (private) | `parseEntryId` | the entry id a result's index points at, or null |
| `Recommendation/Scoring/Support/RerankReplyDecoder.php` | `relevanceOf` (private) | `parseRelevance` | a result's relevance score, or null |
| `Recommendation/Scoring/Support/ScaledScore.php` | `of` | `fromValue` | a 0..1 value as an integer score on the scale, clamped |
| `Recommendation/Scoring/Support/ScoringArticle.php` | `of` | `fromLine` | an article line as the fields a scoring model reads |
| `Recommendation/Support/PrettyJson.php` | `of` | `encodeValue` | a value as pretty-printed JSON |
| `Recommendation/Support/ProviderErrorReason.php` | `rootOf` (private) | `parseRoot` | a body decoded to its root array, empty when it is none |
| `Recommendation/Support/RefusalMessage.php` | `of` | `formatMessage` | the message for a refused request |
| `Recommendation/Support/TokenEstimate.php` | `of` | `estimateTokens` | a text's estimated token count |
| `Recommendation/Support/TokenEstimate.php` | `ofLength` | `estimateTokensForLength` | the estimated token count of a byte length |
| `Trending/CardLanguage.php` | `of` | `resolveLanguage` | a confident guess from the text, else the post's language; a best guess without one |
| `Trending/FallbackChoice.php` | `bestOf` (private) | `pickBest` | the best candidate, marker-free ones first |
| `Trending/FallbackChoice.php` | `rankOf` (private) | `buildRank` | a candidate's score, time and id as a sort key |
| `Trending/FallbackTitles.php` | `titlesOf` (private) | `buildTitles` | the fallback title of each due story |
| `Trending/FallbackTitles.php` | `candidatesOf` (private) | `pickCandidates` | a group's outlet members in the majority language, else every member |
| `Trending/FallbackTitles.php` | `cardTitleOf` (private) | `findCardTitle` | a story's title from a shared card when it has no outlet member, or null |
| `Trending/FallbackTitles.php` | `cardLinkIdsOf` (private) | `collectCardLinkIds` | the shared-link ids of the due stories without outlet members |
| `Trending/FallbackTitles.php` | `cardLinkOf` (private) | `readCardLinkId` | a member's shared-link id when it shares an article, else null |
| `Trending/GermanCompoundSplitter.php` | `partsOf` | `splitParts` | the lexicon forms of a compound's parts, none for a non-compound |
| `Trending/GermanCompoundSplitter.php` | `bestSplitOf` (private) | `findBestSplit` | the best split of a word within a part limit, or null |
| `Trending/HeadlineEquivalents.php` | `wordsOf` | `extractWords` | a text's kept words and phrases, canonical, each with its bare form |
| `Trending/HeadlineEquivalents.php` | `wordStemsOf` | `stemWordAndParts` | the stem of a word and of a German compound's parts |
| `Trending/HeadlineEquivalents.php` | `stemOf` (private) | `stemWord` | a word's stem; a canonical is its own |
| `Trending/ReaderWindows.php` | `of` | `buildWindow` | the reader's cut of a trending window |
| `Trending/ReaderWindows.php` | `storyIdsLeftOutOf` (private) | `collectLeftOutStoryIds` | the ids of the stories shown in the shorter windows |
| `Trending/StoryTitleTask.php` | `cleanedHeadlinesOf` (private) | `loadCleanedHeadlines` | a story's member headlines with feed markers cleaned off |
| `Trending/StoryTitleTask.php` | `shownStoryIdsOf` (private) | `collectShownStoryIds` | the ids of the stories a user is shown in any window |
| `Trending/TrendingGrouping.php` | `storyOf` (private) | `buildStory` | a story from entry ids with its first and last time |
| `Trending/TrendingStories.php` | `articleKeyOf` (private) | `buildArticleKey` | a row's article key: its own URL hash for an outlet, else the link hash |
| Trending/GermanLexicon/GermanLexiconInterface.php, BundledGermanLexicon.php, `Trending/Model/GermanLexiconModel.php` | `entryOf` | `findEntry` | a form's lexicon entry, or null (3 methods) |
| `Trending/Model/EntryStemIdsModel.php` | `of` | `fromHeadlinesAndBodies` | the stem ids of an entry, split into headline and body-only |
| `Trending/Model/EntryStemIdsModel.php` | `unionOf` (private) | `mergeStems` | the stems of several texts, a stem held by a word in any of them marked so |
| `Trending/Model/GermanLexiconModel.php` | `contentsOf` (private) | `readFile` | the lexicon file's contents; throws when unreadable |
| `Trending/Model/RankedStoryModel.php` | `storyIdsOf` | `collectStoryIds` | the story id of each ranked story |
| `Trending/Model/ShownEntriesModel.php` | `linkHashOf` | `findLinkHash` | an entry's link hash, else its listed row's, or null |
| `Trending/Model/SortedLinesModel.php` | `valueOf` | `findValue` | the value stored under a key, by binary search, or null |
| `Trending/Model/TrendingStoryCardModel.php` | `articleOf` (private) | `buildArticle` | one article from its outlet rows and the posts sharing it |
| `Trending/Model/TrendingStoryCardModel.php` | `leadOf` (private) | `pickLead` | the article that leads the card |
| `Trending/Model/TrendingStoryCardModel.php` | `representativeOf` (private) | `pickRepresentative` | the post that stands for the shares: newest link share with a picture first |
| `Trending/Pass/CompoundParts.php` | `partLinksOf` | `buildPartLinks` | the compounds a part-only stem comes through in an entry and in each holder |
| `Trending/Pass/CompoundParts.php` | `unsharedOf` (private) | `filterUnshared` | the compounds the other entry does not hold; null when it holds all |
| `Trending/Pass/CompoundSplitSearch.php` | `linkingVariantsOf` (private) | `buildLinkingVariants` | a segment and its forms without a linking suffix |
| `Trending/Pass/CompoundSplitSearch.php` | `nounPartOf` (private) | `findNounPart` | a form as a single noun part, or null |
| `Trending/Pass/HeadlineWindow.php` | `countLanguageOf` (private) | `countLanguage` | counts an entry under its language |
| `Trending/Pass/HeadlineWindow.php` | `stemIdsOf` (private) | `collectStemIds` | the stem ids of a text's words, assigning ids to new stems |
| `Trending/Pass/HeadlineWindow.php` | `placedStemsOf` (private) | `collectPlacedStems` | an entry's stems with their place weight, filler left out |
| `Trending/Pass/HeadlineWindow.php` | `earlierHoldersOf` (private) | `collectEarlierHolders` | the earlier entries holding a stem, with its weight there |
| `Trending/Pass/HeadlineWindow.php` | `feedFillerOf` (private) | `loadFeedFiller` | a feed's filler stems, counted once and kept |
| `Trending/Pass/SharedArticles.php` | `of` | `findArticle` | the shared article of an entry's story card, read once per card, or null |
| `Trending/Pass/StoryCardSource.php` | `cardsOf` | `buildCards` | the card of each ranked story from its rows |
| `Trending/Pass/StoryGroups.php` | `sampleOf` (private) | `pickSample` | a group's newest members |
| `Trending/Pass/TrendingRun.php` | `memberOf` | `readMember` | the member stored for an entry id |
| `Trending/Pass/TrendingRun.php` | `effectiveTimeOf` | `readEffectiveTime` | the effective time stored for an entry id |
| `Trending/StoryLinkSource/HeadlineLinks.php` | `failuresOf` (private) | `collectFailures` | why an overlap links no story, one line per failed check |
| `Trending/Support/ArticleSite.php` | `of` | `fromUrl` | a URL's host key, or null |
| `Trending/Support/CentroidScores.php` | `of` | `computeScores` | each member's weight shared with every other member |
| `Trending/Support/PartWeightCaps.php` | `cappedWeightOf` (private) | `computeCappedWeight` | the parts' weight with each compound held to its cap |
| `Trending/StoryTitleParser.php` | `parseCollapsed` (private) | `collapseText` | collapses a string's whitespace; null for a non-string or an empty result |
| `Trending/StoryTitleParser.php` | `parseSentencesThatFit` (private) | `cutToLastSentence` | cuts a synopsis at the last sentence end within the limit; null when no sentence fits |

## Review Focus

- **A name that does not say what the body does.** This is the batch where I am least sure: the trending code is dense and I read about the first eight lines of each body. Check every Trending row against the full body. Where the reviewer reads a body differently, the row comes back to the planner with what the method does; the reviewer picks no name.
- **Callables.** `TrendingRun::memberOf(...)` and `effectiveTimeOf(...)` are passed as first-class callables; `PartWeightCaps` takes a closure parameter named `$rarityOf`, which is a variable and stays.
- **Names batch 3 already changed** that this code calls: `TrendingWindow::excludedStoryWindows()`, `TrendingStoryRepository::findMemberHeadlines()`, `TrendingEntryRepository::findLinkTitles()`.
- **Collisions.** `HeadlineWindow` already has `assignStemIds()` and `countFeedFiller()`; `OpenAiCompatibleChatClient` and `CompletionBodyDecoder` each get a method about content (`requireContent`, `parseContent`); `QuestionId` and `RerankReplyDecoder` both get `parseEntryId`; two message helpers both get `formatMessage`.
- **`AiReadiness::of()` becomes a question, `isReady()`**: callers read `AiReadiness::isReady($connection)`.

---

### Task 1: Curation/Ai and Curation/Recommendation

- [ ] **Step 1:** Rename the Ai and Recommendation rows and every caller in `backend/src`, `backend/tests` and `backend/migrations`.
- [ ] **Step 2:** Run `grep -rnE 'function\s+&?(of[A-Z]\w*|of|\w+Of)\s*\(' backend/src/Service/Curation/Ai backend/src/Service/Curation/Recommendation`. Expected: no output.
- [ ] **Step 3:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green, the same test count as develop.
- [ ] **Step 4:** Commit `refactor(#2360): Curation AI and recommendation methods say what they do`.

### Task 2: Curation/Trending and the rest of Curation

- [ ] **Step 1:** Rename the remaining rows, `GermanLexiconInterface::entryOf()` with its implementations and doubles, the two `StoryTitleParser` helpers, and every caller.
- [ ] **Step 2:** Run the Task 1 grep over `backend/src/Service/Curation`. Expected: no output.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Curation trending methods say what they do`.

### Task 3: Cross-checks and PR

- [ ] **Step 1:** Grep `backend/src` and `backend/tests` for each old name of the table in quotes. Expected: no hit that refers to a renamed method.
- [ ] **Step 2:** Read the diff once for a renamed `of(` that belongs to a class outside the table. Expected: none.
- [ ] **Step 3:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 4:** Run `/simplify` on the branch; a finding that changes more than a name goes into the PR text.
- [ ] **Step 5:** Push, open the PR into `develop` with `Refs #2360` and the table. Merge when CI is green, then comment on #2360 with the areas done and the count that remains.
