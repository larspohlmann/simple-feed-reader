# Verbs for Bare `…For/In/At/Among/OrNull` Names: Curation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In `Service/Curation`, no method is a bare noun phrase ending in `For`, `In`, `At`, `Among`, `OrNull` or `OrRefuse`, or a bare `or…`.

**Architecture:** Renames only, the fourth of five PRs for #2400 (then Social). The table below is the decision. Callers in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2400 and the method-name paragraph in `CLAUDE.md`.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock or comment changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables, string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Several classes share an old name (`candidatesFor` four times, `connectionFor` four times, `usageIn` three times, `scoresIn`, `usableFor`, `secondsIn`). Rename per class through the type, never by a tree-wide text replace: the same name in a module outside this batch stays until its own batch.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class, no `CLAUDE.md` edit.
- Commits `refactor(#2400): …`. The PR says `Refs #2400` (the last batch closes it). No attribution lines.
- A living document under `docs/` (not `docs/superpowers/`) that names a renamed method is updated by hand in a separate `docs(#2400)` commit. Known: `docs/recommendations-runs.md:179` names `UserTickLock::nameFor()`. Search `docs/` for the other old names before the PR.
- A bare noun-led method the endings pattern misses because a word follows the preposition (`requestForNetwork`) or the name starts with `for`: report it when it sits in a class this batch already touches; do not sweep the group for more. The rest goes to the follow-up named in the PR.
- No tool may rewrite text in `docs/`. Before each commit `git status` shows no change under `docs/` other than this plan's own table updates.
- A method of these groups that is a bare name with one of the endings but is in no table and not under Kept names, a new name that collides in its class, or a name that does not say what the body does: stop and report the row to the planner; do not pick a name.

## Decisions

- Every row was named from the method's full body and its callers. A `CLAUDE.md` verb where its meaning matches; otherwise the plain verb the tree already uses for that act. The verbs #2360 settled apply: `build…` assembles, `parse…` interprets a string or decoded array into a value that may be absent, `find…` looks up (null when absent), `resolve…` settles between sources or falls back, `read…` is a plain access with a default, `collect…` gathers from a list, `keepIf…` passes a value through or drops it, `try…` swallows failure.
- In scope is a name that does not start with a verb. `findSubscriptionFor`, `getCreatedAt`, `isDueAt` and the like are not touched.
- `…IfSet` marks a method that passes a null argument through and otherwise does its act, as `requireOwnedTagIfSet`. `pick…` chooses one value among given ones; `select…` picks a strategy object for a key; `load…` queries stored state and builds from it; `compute…` works a number or a time out.
- A yes/no question starts with `is…` or `has…`: `titlesEnabledIn` becomes `hasTitlesEnabled`.

## The renames

The last column is what the reviewer checks the name against.

### Ai and StoryAi (paths under `backend/src/Service/Curation/`)

| File | Old | New | What it does |
|---|---|---|---|
| `Ai/ConnectionPicks.php` | `candidatesFor` | `findCandidates` | The user's AI configurations that pass the given capability check |
| `Ai/Factory/ProviderConnectionFactory.php` | `timeoutsFor` | `pickTimeouts` | The slow-model timeouts for a slow model, else the standard ones; opens no key |
| `Ai/Model/RetryPlanModel.php` | `waitSecondsFor` | `computeWaitSeconds` | The provider's retry-after when given, else the backoff step for the retry index, the last step beyond the table |
| `Ai/Support/RetryAfter.php` | `secondsIn` | `parseSeconds` | Reads integer seconds out of a response's `Retry-After` header; null when absent or a date |
| `StoryAi/StoryAiTask/StoryAiTaskInterface.php` and every implementation | `connectionFor` | `findConnection` | The connection a task calls for a user; null skips the user for this task |
| `StoryAi/StorySettingsEditor.php` | `connectionFor` | `requireAllowedConnectionIfSet` | Null for a null id; else the user's configuration with that id, throwing when it does not exist or may not write text |
| `StoryAi/StorySettingsProvider.php` | `titleConnectionFor` | `findTitleConnection` | The connection that writes the user's story titles; null while titles are off or none can write |
| `StoryAi/StorySettingsProvider.php` | `titlesEnabledIn` | `hasTitlesEnabled` | Whether stored settings enable titles, the default when there are none |
| `StoryAi/StoryWritingConnections.php` | `candidatesFor` | `findCandidates` | The user's AI configurations that can write text |
| `StoryAi/StoryWritingConnections.php` | `usableFor` | `findUsableConnection` | The writing connection usable for the user under their stored settings, null when none |

### Trending (paths under `backend/src/Service/Curation/Trending/`)

| File | Old | New | What it does |
|---|---|---|---|
| `FallbackTitles.php` | `candidatesIn` | `buildCandidates` | Cleans the headlines of a pool's members and builds a title candidate per usable one, scored against the centroid |
| `HeadlineEquivalents.php` | `phraseAt` | `matchPhrase` | Matches a known phrase starting at a word index: its length and canonical form, or length 1 and null |
| `HeadlineEquivalents.php` | `stemsFor` | `pickStems` | The stem table of a language, the cut stems for no language |
| `Model/LanguageCoverageModel.php` | `entriesIn` | `readEntryCount` | The stored entry count of a language, 0 when none |
| `Model/TrendingLastBuildsModel.php` | `builtAt` | `findBuiltAt` | When a window was last built, null when never |
| `Model/TrendingLastBuildsModel.php` | `dueAt` | `computeDueAt` | The window's last build plus its rebuild interval; null while never built |
| `Model/TrendingLastBuildsModel.php` | `nextBuildDueAt` | `computeNextBuildDueAt` | The earliest due time over all windows; null when one was never built |
| `Pass/CompoundSplitSearch.php` | `compoundSplitAt` | `findCompoundSplit` | The split that puts a modifier between two offsets before the best tail; null when there is no tail, too many parts or no modifier |
| `SnapshotBuilder/TrendingSnapshotBuilder.php` | `policyFor` | `selectPolicy` | Picks the story build policy registered for a window; throws `LogicException` when none is wired |
| `StoryLinkSource/SharedUrlLinks.php` | `linksAmong` | `buildLinks` | Builds a same-URL link for every pair of the entries that may link |
| `StoryTitleTask.php` | `connectionFor` | `findConnection` | Implements `StoryAiTaskInterface`: the user's title connection |
| `TrendingStories.php` | `sourceFor` | `loadCardSource` | Queries the shown entries, rows, viewed and followed stories and headings of the ranked stories and builds the card source from them |
| `TrendingStories.php` | `unopenedFor` | `countUnopened` | Counts the ranked stories of a window the user has not viewed; 0 without a query when Trending is off |

### Recommendation (paths under `backend/src/Service/Curation/Recommendation/`)

| File | Old | New | What it does |
|---|---|---|---|
| `Engine/RecommendationEngineResolver.php` | `kindFor` | `resolveKind` | The engine kind a connection runs: its model kind |
| `Engine/RecommendationEngineResolver.php` | `capabilitiesFor` | `resolveCapabilities` | The capabilities of the engine kind a connection runs |
| `Feed/RecommendationFeedPager.php` | `nextCursorFor` | `buildNextCursor` | Encodes a cursor from the last row of a full page; null for an empty or short page |
| `Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClient.php` | `responsesIn` | `collectResponses` | The responses held in the context storage, as a list |
| `Llm/Completion/CompletionBodyDecoder.php` | `firstChoiceIn` | `readFirstChoice` | The first element of the decoded body's `choices`, null when missing or mistyped |
| `Llm/Completion/CompletionBodyDecoder.php` | `usageIn` | `parseUsage` | Interprets the decoded body's `usage` into a usage model; null when there is none |
| `Llm/LlmRecommendationEngine.php` | `providerPhaseFor` | `selectProviderPhase` | Picks the consolidation phase or the batch phase by the run's progress |
| `Llm/Prompt/PlausibleDuplicateShare.php` | `maximumFor` | `computeMaximum` | The allowed number of duplicates for a shown count: its fixed percentage, rounded down |
| `Llm/Run/OutputCapFallback.php` | `capNamedIn` | `parseCap` | Reads the output cap a "too large" rejection names in its message; null when it is another rejection or names none |
| `Profile/ProfileConnections.php` | `candidatesFor` | `findCandidates` | The user's AI configurations that can build profiles |
| `Profile/ProfileConnections.php` | `usableFor` | `findUsableConnection` | The profile connection usable for the user under their stored settings, null when none |
| `Profile/ProfileForRun/ProfileForRunInterface.php` and every implementation | `profileFor` | `ensureProfile` | The profile state for a run: the stored profile, or the state of the latest profile run; starts a profile run when there is no profile and none has run since the run was created |
| `Profile/ProfileRunTick.php` | `tickFor` | `loadTick` | Resolves the settings for the connection, loads the profile inputs and builds the tick from them |
| `Profile/ProfileSettingsEditor.php` | `connectionFor` | `requireAllowedConnectionIfSet` | Null for a null id; else the user's configuration with that id, throwing when it does not exist or may not build profiles |
| `Run/BatchWaveRounds.php` | `abortDetailFor` | `pickAbortDetail` | The outcome's own cause message when it has one, else the wave failure's |
| `Run/BatchWaveRounds.php` | `firstFailureIn` | `pickFailure` | The cause to report for a wave: a provider refusal when any outcome has one, else the first failure; null when none failed |
| `Run/Model/RunStartModel.php` | `elapsedSecondsAt` | `computeElapsedSeconds` | Seconds from the run's start to a time, never negative; null before the run has a start |
| `Run/RecommendationRunAdvancer.php` | `failureMessageFor` | `pickFailureMessage` | The "key unreadable" message for that exception, else the "no longer configured" one |
| `Run/SnapshotPhase.php` | `candidatesFor` | `loadCandidates` | Loads the candidate pool for a tick from its lookback window and pool size |
| `Run/TickLockTtl.php` | `secondsFor` | `computeSeconds` | The lock TTL for a user: `computeSecondsForConnection` of their active connection |
| `Run/TickLockTtl.php` | `secondsForConnection` | `computeSecondsForConnection` | The lock TTL sized against a connection's call timeouts |
| `Run/UserTickLock.php` | `nameFor` | `buildName` | The lock name: prefix plus the user id |
| `Scoring/Support/RerankReplyDecoder.php` | `scoresIn` | `parseScores` | Interprets a rerank reply's results into a relevance per entry id; `[]` when malformed or an entry repeats |
| `Scoring/Support/RerankReplyDecoder.php` | `usageIn` | `parseUsage` | Interprets the reply's `usage` into a usage model; null when there is none |
| `Scoring/Support/SystemOneReplyDecoder.php` | `scoresIn` | `parseScores` | Interprets the reply's answers into a score per entry id, skipping unusable answers |
| `Scoring/Support/SystemOneReplyDecoder.php` | `usageIn` | `parseUsage` | Interprets the reply's `usage` into a usage model; null when there is none |
| `Scoring/Support/SystemOneReplyDecoder.php` | `countIn` | `readCount` | The token count under the first of two keys that carries one |
| `Settings/Pass/AccountRecommendationSettings.php` | `batchCeilingFor` | `resolveBatchCeiling` | The connection's configured maximum batch size, the default when unset or without a connection |
| `Support/ProviderErrorReason.php` | `identifiersIn` | `parseIdentifiers` | Reads the error's `code` and `param` out of a provider error body |
| `Support/ProviderErrorReason.php` | `reasonIn` | `parseReason` | Reads the error reason out of a provider error body: `detail` or `error.message`; null when neither is usable |

## Review Focus

- `StoryAiTaskInterface::connectionFor` and `ProfileForRunInterface::profileFor` are renamed with every implementation and test double. The two private `connectionFor` methods in the settings editors are a different act and get a different name.
- `candidatesFor` is four methods with two new names (`findCandidates` three times, `loadCandidates` in `SnapshotPhase`); `secondsIn` in `Content/Chapter` was renamed in the Content batch and is not this row.
- `nextBuildDueAt`, `builtAt` and `dueAt` are also properties, array keys and JSON fields (`FollowedStoryJson`, `TrendingSnapshotRepository`): only the three methods of `TrendingLastBuildsModel` are renamed.
- `usableGiven` beside `usableFor` in both connection classes, `bestFrom` and `modifierBetween` in `CompoundSplitSearch`: bare names in touched classes that the endings pattern misses; they have no row. Report what their bodies do with your review.
- `docs/recommendations-runs.md:179` names `UserTickLock::nameFor()` and is updated by hand in a `docs(#2400)` commit.
- String method names in mocks: search `backend/tests` for each bare old name.

---

### Task 1: Ai and StoryAi

- [ ] **Step 1:** Rename the rows of the first table, the interface implementations and test doubles included, and every caller.
- [ ] **Step 2:** Run `composer cs:fix && composer check && composer md && php bin/phpunit` in `backend/`. Expected: all green.
- [ ] **Step 3:** Commit `refactor(#2400): AI connection methods start with their verb`.

### Task 2: Trending

- [ ] **Step 1:** Rename the Trending rows and every caller.
- [ ] **Step 2:** Run the same four commands. Expected: all green.
- [ ] **Step 3:** Commit `refactor(#2400): Trending methods start with their verb`.

### Task 3: Recommendation

- [ ] **Step 1:** Rename the Recommendation rows, the `ProfileForRunInterface` implementations and doubles included; update `docs/recommendations-runs.md` in its own `docs(#2400)` commit.
- [ ] **Step 2:** Run `grep -rnoE 'function\s+&?(\w+(OrNull|OrRefuse|For|In|At|Among)|or[A-Z]\w*)\s*\(' backend/src/Service/Curation`. Expected: only names that start with a verb.
- [ ] **Step 3:** Run the same four commands. Expected: all green.
- [ ] **Step 4:** Commit `refactor(#2400): Recommendation methods start with their verb`.

### Task 4: Gates and PR

- [ ] **Step 1:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green; escaped mutants only on lines whose logic this PR did not change, listed in the PR.
- [ ] **Step 2:** Open the PR into `develop` with the rename tables and `Refs #2400`; merge when green.
